<?php declare(strict_types=1);
/**
 * Email Case Workspace — HTML sanitization.
 *
 * Two different jobs, deliberately two different functions:
 *
 *   ecw_sanitize_email_html()   — HTML that ARRIVED from the internet, shown in
 *                                 the reading pane. Maximally hostile input.
 *   ecw_sanitize_compose_html() — HTML the user just typed, on its way to storage
 *                                 or to the provider. Hostile too: the step says
 *                                 sanitize the editor's output server-side, and
 *                                 an editor is a client-side control that a
 *                                 crafted POST simply skips.
 *
 * HTMLPurifier does the parsing. It is already vendored (ezyang/htmlpurifier,
 * a transitive dependency) and it whitelists rather than blacklists, which is
 * the only approach that survives contact with real mail. The repo's older
 * hand-rolled DOM sanitizer in includes_old/email_content.php is deliberately
 * not reused — one sanitizer per codebase is already one too many.
 *
 * REMOTE IMAGES ARE BLOCKED BY DEFAULT
 * ------------------------------------
 * A remote <img> in an email is a read receipt: it tells the sender when the
 * message was opened, from which IP, how often. Every serious mail client
 * blocks them until asked. The src is moved to data-ecw-src so the UI can offer
 * "show images" without re-fetching or re-sanitizing the message.
 *
 * INLINE IMAGES
 * -------------
 * cid: references are rewritten to our own attachment endpoint BEFORE the
 * purifier runs. HTMLPurifier has no URI scheme handler for cid, so it drops
 * the attribute, and an <img> with no src is then deleted as invalid — the
 * inline image disappears entirely rather than merely failing to load.
 */

require_once __DIR__ . '/log.php';

/**
 * Tags and attributes allowed in received mail. Formatting and tables only, with the styles a
 * designed email (a newsletter) is built from: styled links and paragraphs (its buttons and
 * headings), table colours (bgcolor) and alignment. Still no scripts, forms, frames or ids.
 */
const ECW_EMAIL_ALLOWED_HTML =
    'p[style|class|align],br,b[style],strong[style],i[style],em[style],u[style],s,strike,sub,sup,span[style|class],div[style|class|align],center,'
    . 'a[href|title|target|rel|style],img[src|alt|title|width|height|style|class|align|border],'
    . 'ul[style],ol[style],li[style],blockquote[style|class],pre[style],code,hr[style],'
    . 'table[style|class|width|border|cellpadding|cellspacing|align|bgcolor],thead,tbody,tfoot,'
    . 'tr[style|align|valign|bgcolor],th[style|colspan|rowspan|align|valign|width|height|bgcolor],td[style|colspan|rowspan|align|valign|width|height|bgcolor],'
    . 'h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],font[color|face|size|style]';

/** Composer content, including preserved borderless email signature tables. */
const ECW_COMPOSE_ALLOWED_HTML =
    'p[style],br,b,strong,i,em,u,s,span[style],div[style],a[href|title|target|rel],'
    . 'img[src|alt|width|height|style],ul,ol,li[style],blockquote,pre,code,h1,h2,h3,h4,'
    . 'table[style|width|border|cellpadding|cellspacing],thead,tbody,tr[style],th[style|colspan|rowspan|width|align|valign],td[style|colspan|rowspan|width|align|valign]';

/**
 * Styles kept in an email. No background images (a remote picture: a read receipt, like an
 * <img>; blocked the same way), and no position (nothing can be laid over the page).
 * display / visibility / overflow (CSS.AllowTricky below): a newsletter's hidden preview line
 * and its mobile-only copies stay hidden, instead of showing as stray text.
 */
const ECW_ALLOWED_CSS =
    'color,background-color,font,font-weight,font-style,font-variant,text-decoration,text-align,text-transform,text-indent,'
    . 'letter-spacing,word-spacing,font-size,font-family,margin,margin-left,margin-right,margin-top,margin-bottom,'
    . 'padding,padding-left,padding-right,padding-top,padding-bottom,'
    . 'border,border-left,border-right,border-top,border-bottom,border-collapse,border-spacing,'
    . 'border-color,border-style,border-width,border-radius,'
    . 'width,min-width,max-width,height,min-height,max-height,vertical-align,list-style-type,line-height,white-space,'
    . 'table-layout,float,clear,direction,display,visibility,overflow';

function ecw_purifier(string $allowedHtml): HTMLPurifier
{
    static $instances = [];
    if (isset($instances[$allowedHtml])) {
        return $instances[$allowedHtml];
    }

    // Written out in full (no variable) so tests/wiring_test.php can load it on its own.
    require_once is_file(dirname(__DIR__) . '/vendor/autoload.php') ? dirname(__DIR__) . '/vendor/autoload.php' : dirname(__DIR__, 2) . '/vendor/autoload.php';

    $config = HTMLPurifier_Config::createDefault();
    $config->set('HTML.Allowed', $allowedHtml);
    $config->set('CSS.AllowedProperties', ECW_ALLOWED_CSS);
    $config->set('CSS.AllowTricky', true);        // display, visibility, overflow (not position: CSS.Trusted stays off)
    $config->set('CSS.Proprietary', true);        // border-radius: rounded buttons
    $config->set('AutoFormat.RemoveEmpty', false);
    // A picture without alt text gets an empty one, not its own file name or data: URI as text.
    $config->set('Attr.DefaultImageAlt', '');
    $config->set('Core.Encoding', 'UTF-8');
    // No 'cid' here: it is resolved to a normal URL before purification, and
    // the purifier has no scheme handler for it anyway. 'data' is allowed for
    // the unresolved-inline-image placeholder pixel.
    $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true, 'data' => true]);   // tel: a phone number in a signature
    $config->set('Attr.AllowedFrameTargets', ['_blank']);
    $config->set('HTML.TargetBlank', true);
    $config->set('Attr.EnableID', false);

    // Purifier caches its parsed definitions. Without a writable directory it
    // re-parses them on every call, which is slow but correct — so fall back
    // rather than fail, and say so once. (This host has a history of
    // not-group-writable app directories.)
    $cacheDir = dirname(__DIR__) . '/cache/htmlpurifier';
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        $config->set('Cache.SerializerPath', $cacheDir);
    } else {
        static $warned = false;
        if (!$warned) {
            ecw_log("HTMLPurifier cache directory is not writable ({$cacheDir}); running uncached");
            $warned = true;
        }
        $config->set('Cache.DefinitionImpl', null);
    }

    $instances[$allowedHtml] = new HTMLPurifier($config);
    return $instances[$allowedHtml];
}

/**
 * Sanitize received mail for display.
 *
 * @param callable|null $cidResolver fn(string $cid): ?string — URL for an inline attachment
 */
function ecw_sanitize_email_html(?string $html, bool $allowRemoteImages = false, ?callable $cidResolver = null): string
{
    if ($html === null || trim($html) === '') {
        return '';
    }

    // ORDER MATTERS, and it is not symmetric.
    //
    // cid: must be resolved BEFORE purification: HTMLPurifier has no URI scheme
    // handler for cid, so it drops the attribute — and an <img> left without a
    // src is then removed as invalid, taking the inline image with it. By the
    // time a post-pass runs there is nothing left to rewrite.
    //
    // Remote images must be defused AFTER purification, for the mirror-image
    // reason: defusing means removing src, and doing that first would again
    // leave an <img> with no src for the purifier to delete.
    //
    // Whitespace collapsing runs last, on already-safe markup: it only ever
    // removes <br> elements and provably-empty <p>/<div> elements, so it has
    // nothing to defend against and nothing to get wrong about a payload.
    $prepared = ecw_resolve_cid_images($html, $cidResolver);
    $clean    = ecw_purifier(ECW_EMAIL_ALLOWED_HTML)->purify($prepared);
    $clean    = ecw_rewrite_images($clean, $allowRemoteImages);

    return ecw_collapse_display_whitespace($clean);
}

/** A 1×1 transparent GIF: an inline image we cannot resolve renders as nothing. */
const ECW_PLACEHOLDER_PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

/**
 * Point cid: references at our own attachment endpoint, before sanitizing.
 *
 * A plain string substitution rather than a DOM pass: this runs on raw,
 * untrusted mail, and everything it produces is re-validated by the purifier
 * immediately afterwards. Nothing here is a security boundary.
 */
function ecw_resolve_cid_images(string $html, ?callable $cidResolver): string
{
    if (stripos($html, 'cid:') === false) {
        return $html;
    }

    return (string)preg_replace_callback(
        '#src\s*=\s*(["\'])cid:([^"\']+)\1#i',
        static function (array $m) use ($cidResolver): string {
            $cid = trim($m[2]);
            $url = $cidResolver !== null ? $cidResolver($cid) : null;
            if ($url === null) {
                // Not downloaded yet. A placeholder keeps the layout intact and
                // leaks nothing, where a dangling cid: renders as a broken image.
                return 'src="' . ECW_PLACEHOLDER_PIXEL . '" data-ecw-unresolved="1"';
            }
            return 'src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
        },
        $html
    );
}

/** Sanitize composer output, for storage and for send. */
function ecw_sanitize_compose_html(?string $html): string
{
    if ($html === null || trim($html) === '') {
        return '';
    }
    return ecw_purifier(ECW_COMPOSE_ALLOWED_HTML)->purify($html);
}

/** Signatures are composer content with the same rules. Stored sanitized. */
function ecw_sanitize_signature_html(?string $html): string
{
    $clean = ecw_sanitize_compose_html($html);
    $clean = (string)preg_replace('~<p>(?:\s|&nbsp;|<br\s*/?>)*</p>~i', '', $clean);
    $clean = str_replace(['<p>', '</p>'], ['<div style="margin:0;line-height:1.25">', '</div>'], $clean);
    return ecw_collapse_display_whitespace($clean);
}

/**
 * Post-sanitize image pass: defuse remote src.
 *
 * Runs on already-purified markup, so the DOM here is known-safe and this only
 * has to move attributes around. data-ecw-src is added AFTER purification
 * precisely because the purifier does not know that attribute and would strip it.
 */
function ecw_rewrite_images(string $html, bool $allowRemoteImages): string
{
    if (stripos($html, '<img') === false) {
        return $html;
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    // The wrapper keeps DOMDocument from inventing <html><body>, and the meta
    // charset keeps it from mangling UTF-8 into entities.
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><div id="ecw-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $doc->getElementById('ecw-root');
    if ($root === null) {
        return $html;
    }

    /** @var DOMElement $img */
    foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
        $src = (string)$img->getAttribute('src');
        if ($src === '') {
            continue;
        }

        if (!$allowRemoteImages && preg_match('#^https?://#i', $src)) {
            $img->removeAttribute('src');
            $img->setAttribute('data-ecw-src', $src);
            $img->setAttribute('class', trim((string)$img->getAttribute('class') . ' ecw-blocked-image'));
        }
    }

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

/**
 * Collapse redundant visual whitespace in a display copy of a message body.
 *
 * DISPLAY-ONLY: this runs on the sanitized copy returned to the reading pane
 * and the case timeline. The stored `body_html` column is never touched, and
 * plain-text messages reach here too — the display-parts split in body.php
 * already turns them into <br>-joined HTML before sanitization, so a run of
 * blank lines in a plain-text mail is a run of <br> elements by the time this
 * runs.
 *
 * Mail clients (Outlook especially) pad messages with runs of <br> and with
 * stacks of empty <p>/<div> — an editor's "extra paragraph" left over from
 * composing. Both read as dead space stacking without limit. This caps any
 * consecutive run of "blank" nodes — <br>, or a <p>/<div> containing nothing
 * but whitespace, &nbsp; or more <br> — to two, at every level of the tree,
 * without touching anything that is not itself blank: a signature, a table,
 * a real paragraph are never candidates for removal in the first place.
 */
function ecw_collapse_display_whitespace(string $html): string
{
    if ($html === '') {
        return $html;
    }
    // Cheap reject: nothing that could form a blank run is present at all.
    if (stripos($html, '<br') === false && stripos($html, '<p') === false && stripos($html, '<div') === false) {
        return $html;
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><div id="ecw-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $doc->getElementById('ecw-root');
    if ($root === null) {
        return $html;
    }

    ecw_collapse_blank_runs($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

/**
 * True for a <br>, or a <p>/<div> whose only content is whitespace/&nbsp;/<br>,
 * also when wrapped in empty inline tags: Outlook writes an empty line as
 * <p class="MsoNormal"><span style="…">&nbsp;</span></p>.
 */
function ecw_is_blank_display_node(DOMNode $node): bool
{
    if (!$node instanceof DOMElement) {
        return false;
    }
    $tag = strtolower($node->tagName);
    if ($tag === 'br') {
        return true;
    }
    if ($tag !== 'p' && $tag !== 'div') {
        return false;
    }
    return ecw_is_blank_inline_content($node);
}

/** Only whitespace, &nbsp;, <br> and empty inline wrappers (span, font, b, i, o:p…) inside. */
function ecw_is_blank_inline_content(DOMNode $node): bool
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $t = strtolower($child->tagName);
            if ($t === 'br') continue;
            if (!in_array($t, ['span', 'font', 'b', 'i', 'u', 'strong', 'em', 'o:p', 'p', 'small', 'big'], true) || !ecw_is_blank_inline_content($child)) {
                return false;
            }
        } elseif ($child instanceof DOMText) {
            if (trim(str_replace("\u{00A0}", ' ', $child->wholeText)) !== '') {
                return false;
            }
        } elseif (!$child instanceof DOMComment) {
            return false;
        }
    }
    return true;
}

/**
 * Walk $container's children, capping every maximal run of consecutive blank
 * nodes (mixing <br> and empty <p>/<div> freely) to two, then recurse into
 * whatever survives. Whitespace-only text nodes between blank siblings are
 * skipped rather than treated as a break — they carry no visual width of
 * their own and Outlook's export routinely inserts them between <br> tags.
 */
function ecw_collapse_blank_runs(DOMNode $container): void
{
    $run = [];
    $flushRun = static function () use (&$run): void {
        // Two <br> make one empty line after text; an empty paragraph is one already.
        // So a run keeps "<br><br>" or a single empty paragraph: one gap, never a stack.
        $keep = isset($run[1]) && $run[0]->nodeName === 'br' && $run[1]->nodeName === 'br' ? 2 : 1;
        foreach (array_slice($run, $keep) as $extra) {
            $extra->parentNode?->removeChild($extra);
        }
        // An empty paragraph is drawn as a small gap (app.css .ecw-gap), not a full line.
        foreach (array_slice($run, 0, $keep) as $kept) {
            if ($kept instanceof DOMElement && $kept->nodeName !== 'br') {
                $kept->setAttribute('class', trim($kept->getAttribute('class') . ' ecw-gap'));
            }
        }
        $run = [];
    };

    foreach (iterator_to_array($container->childNodes) as $child) {
        if ($child instanceof DOMText && trim(str_replace("\u{00A0}", ' ', $child->wholeText)) === '') {
            continue;
        }
        if (ecw_is_blank_display_node($child)) {
            $run[] = $child;
            continue;
        }
        $flushRun();
        if ($child instanceof DOMElement) {
            ecw_collapse_blank_runs($child);
        }
    }
    $flushRun();
}

/** Plain text from HTML, for previews and for the text part of an outgoing mail. */
function ecw_html_to_text(?string $html): string
{
    if ($html === null || trim($html) === '') {
        return '';
    }
    $text = (string)preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])[^>]*>#i', "\n", $html);
    $text = (string)preg_replace('#<li[^>]*>#i', '- ', $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string)preg_replace("/[ \t]+/", ' ', $text);
    $text = (string)preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}
