<?php declare(strict_types=1);
/**
 * Email Case Workspace — separating a reply from the history it quotes.
 *
 * The product spec collapses quoted history behind "..." in every bubble, so
 * the split has to happen once, at ingest, rather than in each renderer.
 *
 * CONSERVATIVE ON PURPOSE
 * -----------------------
 * A wrong split is worse than no split: cutting at a guessed boundary hides
 * real content the user wrote, and in a case timeline that content may be the
 * decision someone is later audited on. So this only cuts at markers that mail
 * clients emit deliberately — Outlook's appendonsend divider, a cited
 * blockquote, Gmail's quote container, or a literal "Original Message"
 * separator. Anything else returns the body whole with quoted_* null, and the
 * UI simply has nothing to collapse.
 */

/**
 * @return array{body_html:?string, body_text:?string, quoted_html:?string, quoted_text:?string}
 */
function ecw_split_quoted(string $html, string $text): array
{
    $out = [
        'body_html'   => $html !== '' ? $html : null,
        'body_text'   => $text !== '' ? $text : null,
        'quoted_html' => null,
        'quoted_text' => null,
    ];

    if ($html !== '') {
        $cut = ecw_find_html_quote_boundary($html);
        if ($cut !== null && $cut >= 0) {
            $out['body_html']   = substr($html, 0, $cut);
            $out['quoted_html'] = substr($html, $cut);
        }
    }

    if ($text !== '') {
        $cut = ecw_find_text_quote_boundary($text);
        if ($cut !== null && $cut >= 0) {
            $out['body_text']   = rtrim(substr($text, 0, $cut));
            $out['quoted_text'] = substr($text, $cut);
        }
    }

    // Outlook and most mail send HTML only. Search (the full-text index on
    // body_text) and the AI need the words too, so derive the text from the HTML.
    if ($out['body_text'] === null && $out['body_html'] !== null) $out['body_text'] = ecw_html_plain($out['body_html']) ?: null;
    if ($out['quoted_text'] === null && $out['quoted_html'] !== null) $out['quoted_text'] = ecw_html_plain($out['quoted_html']) ?: null;

    return $out;
}

/** The visible text of an HTML email: no styles, scripts or tags; lines and table cells kept apart. */
function ecw_html_plain(string $html): string
{
    if ($html === '') return '';
    $html = (string)preg_replace('#<(style|script|head|title)\b[^>]*>.*?</\1>#is', ' ', $html);
    $html = (string)preg_replace('#<!--.*?-->#s', ' ', $html);
    $html = (string)preg_replace('#<(br|/p|/div|/tr|/li|/h\d|/table|/blockquote)\b[^>]*>#i', "\n", $html);
    $html = (string)preg_replace('#</t[dh]>#i', ' ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string)preg_replace('/[ \t\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]+/u', ' ', $text);
    $text = (string)preg_replace('/ *\n */', "\n", $text);
    return trim((string)preg_replace('/\n{3,}/', "\n\n", $text));
}

/** Earliest high-confidence boundary, or null. */
function ecw_find_html_quote_boundary(string $html): ?int
{
    $markers = [
        '<div id="appendonsend"',        // Outlook: new content ends here
        '<div id=3D"appendonsend"',      // quoted-printable variant
        '<hr id="stopSpelling"',         // older Outlook divider
        '<div class="gmail_quote"',
        '<blockquote type="cite"',
        '-----Original Message-----',
        '-----Ursprüngliche Nachricht-----',
    ];

    $best = null;
    foreach ($markers as $marker) {
        $pos = stripos($html, $marker);
        if ($pos !== false && ($best === null || $pos < $best)) {
            $best = $pos;
        }
    }
    // Match attributes regardless of order, quote style or extra class names.
    $patterns = [
        '~<(?:div|hr)\b[^>]*\bid\s*=\s*([\'"])(?:divRplyFwdMsg|appendonsend|stopSpelling)\1[^>]*>~i',
        '~<div\b[^>]*\bclass\s*=\s*([\'"])[^\'"]*\b(?:gmail_quote|yahoo_quoted|moz-cite-prefix|hs_reply|protonmail_quote|zmail_extra)\b[^\'"]*\1[^>]*>~i',
        '~<blockquote\b[^>]*\btype\s*=\s*([\'"])cite\1[^>]*>~i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE)) {
            $pos = $match[0][1];
            $best = $best === null ? $pos : min($best, $pos);
        }
    }
    // Outlook desktop often uses only a styled divider plus a full mail-header block.
    // Require all four ordered labels; a decorative border alone is never history.
    if (preg_match_all('~<(?:div|p|hr)\b[^>]*\bstyle\s*=\s*([\'"])[^\'"]*border-top[^\'"]*\1[^>]*>~i', $html, $dividers, PREG_OFFSET_CAPTURE)) {
        foreach ($dividers[0] as [$tag, $pos]) {
            $header = html_entity_decode(strip_tags(substr($html, $pos + strlen($tag), 5000)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/^\s*(?:From|Von|Από):.{1,800}?(?:Sent|Gesendet|Date|Ημερομηνία|Στάλθηκε):.{1,400}?(?:To|An|Προς):.{1,1200}?(?:Subject|Betreff|Θέμα):/su', $header)) {
                $best = $best === null ? $pos : min($best, $pos);
            }
        }
    }
    // "On Mon, 2 Oct 2026 … wrote:" (Greek "Στις … έγραψε:") right before a
    // <blockquote>: how this app, Apple Mail and many others quote once the
    // cleaner has removed their ids and classes. Both parts are required.
    if (preg_match_all('~<blockquote\b~i', $html, $quotes, PREG_OFFSET_CAPTURE)) {
        foreach ($quotes[0] as [, $qpos]) {
            if ($best !== null && $qpos >= $best) break;
            $cut = ecw_attribution_start($html, $qpos);
            if ($cut !== null) { $best = $best === null ? $cut : min($best, $cut); break; }
        }
    }
    // Outlook's header block ("From: … Sent: … To: … Subject:") with no divider
    // line. All four labels in order, and something written above it, so a
    // forward without a comment is never folded away entirely.
    if (preg_match_all('~(?:<(?:b|strong|span)\b[^>]*>\s*)*\b(?:From|Από|Von|De)\s*(?:</(?:b|strong|span)>\s*)*:~u', $html, $labels, PREG_OFFSET_CAPTURE)) {
        foreach ($labels[0] as [, $lpos]) {
            if ($best !== null && $lpos >= $best) break;
            $after = ecw_quote_plain(substr($html, $lpos, 6000));
            if (!preg_match('/^\s*(?:From|Από|Von|De)\s*:.{1,800}?(?:Sent|Gesendet|Date|Ημερομηνία|Στάλθηκε|Envoyé)\s*:.{1,400}?(?:To|An|Προς|À)\s*:.{1,1500}?(?:Subject|Betreff|Θέμα|Objet)\s*:/su', $after)) continue;
            if (trim(ecw_quote_plain(substr($html, 0, $lpos))) === '') continue;
            $open = ecw_last_block_open(substr($html, max(0, $lpos - 400), min(400, $lpos)));
            $cut = $open === null ? $lpos : max(0, $lpos - 400) + $open;
            $best = $best === null ? $cut : min($best, $cut);
            break;
        }
    }
    return $best;
}

/** Visible text of an HTML fragment, one line per block. */
function ecw_quote_plain(string $html): string
{
    $html = (string)preg_replace('#<(br|/p|/div|/tr|/li)\b[^>]*>#i', "\n", $html);
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Offset of the last <p>/<div> opening tag in $html, or null. */
function ecw_last_block_open(string $html): ?int
{
    if (!preg_match_all('~<(?:p|div)\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) return null;
    return (int)end($m[0])[1];
}

/**
 * If the text just before the <blockquote> at $qpos is an attribution line
 * ("On … wrote:"), the offset where that line's block starts; else null.
 */
function ecw_attribution_start(string $html, int $qpos): ?int
{
    $from = max(0, $qpos - 1200);
    $before = substr($html, $from, $qpos - $from);
    $attribution = '/^\s*(?:On|Στις|Την|Am|Le|El|Il|Op)\b.{4,500}?(?:wrote|έγραψε|schrieb|a écrit|escribió|ha scritto|schreef)\s*:?\s*$/su';
    if (!preg_match_all('~<(?:p|div)\b[^>]*>~i', $before, $opens, PREG_OFFSET_CAPTURE)) return null;
    // From the nearest block outwards: the first one whose text is exactly the attribution.
    foreach (array_reverse($opens[0]) as [, $o]) {
        $text = trim((string)preg_replace('/\s+/u', ' ', ecw_quote_plain(substr($before, $o))));
        if ($text === '') continue;
        if (!preg_match($attribution, $text)) return null;
        $cut = $from + $o;
        // A divider right above it (<hr>) belongs to the history too.
        $above = substr($html, max(0, $cut - 80), min(80, $cut));
        if (preg_match('~<hr\b[^>]*>\s*$~i', $above, $hm, PREG_OFFSET_CAPTURE)) $cut = max(0, $cut - 80) + $hm[0][1];
        return $cut;
    }
    return null;
}

function ecw_find_text_quote_boundary(string $text): ?int
{
    $markers = [
        '-----Original Message-----',
        '-----Ursprüngliche Nachricht-----',
        '________________________________',
    ];

    $best = null;
    foreach ($markers as $marker) {
        $pos = stripos($text, $marker);
        if ($pos !== false && ($best === null || $pos < $best)) {
            $best = $pos;
        }
    }

    // A run of quoted lines at the end of the message. Requires the attribution
    // line ("On ... wrote:" / "Am ... schrieb:") immediately above it, so a
    // single stray "> " in the middle of a sentence is not treated as history.
    if (preg_match('/\R(On .{4,120}wrote:|Am .{4,120}schrieb.{0,40}:)\s*\R(>.*)$/su', $text, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[1][1];
        if ($best === null || $pos < $best) {
            $best = $pos;
        }
    }

    // A nested reply chain ("> ..." followed later by ">> ...") at the very end
    // of the message, set off by a blank line. No attribution line is required
    // here: ">>" only exists by quoting an already-quoted message, so 2+
    // trailing quoted lines that include actual nesting are unambiguous history
    // even without "On ... wrote:" — unlike a single "> ", which stays gated by
    // the rule above so a stray one mid-sentence is still never cut.
    if (preg_match('/\R\R(>[^\r\n]*(?:\R>[^\r\n]*)*\R>{2,}[^\r\n]*)$/su', $text, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[1][1];
        if ($best === null || $pos < $best) {
            $best = $pos;
        }
    }

    return $best;
}

/** Separate explicitly marked signatures for display; never change stored mail. */
function ecw_split_signature_html(?string $html): array
{
    $html = (string)$html;
    if ($html === '') { return ['body_html' => '', 'signature_html' => '']; }
    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($doc);
    $signatures = [];
    $words = static fn(string $t): int => mb_strlen(trim((string)preg_replace('/\s+/u', ' ', $t)));
    foreach ($xpath->query('//*[@id or @class or @data-smartmail]') as $node) {
        $id = strtolower($node->getAttribute('id'));
        $classes = preg_split('/\s+/', strtolower($node->getAttribute('class')));
        if (in_array($id, ['signature', 'signatureblock'], true)
            || array_intersect($classes, ['gmail_signature', 'moz-signature'])
            || strtolower($node->getAttribute('data-smartmail')) === 'gmail_signature') {
            // A signature ends the message: a marked block with a whole message after it is not one
            // (some mail programs put their signature box above what was written). A short P.S.
            // below a signature does not count.
            $after = 0;
            foreach ($xpath->query('following::text()[not(ancestor::style) and not(ancestor::script)]', $node) as $t) { $after += $words($t->nodeValue); if ($after > 200) break; }
            if ($after > 200) continue;
            $signatures[] = $node;
        }
    }
    if ($signatures === []) { return ['body_html' => $html, 'signature_html' => '']; }
    // Some people write their whole reply inside their signature box (Outlook's id="Signature",
    // Gmail's gmail_signature): folding it would leave an empty message with everything hidden
    // under "Signature". Then nothing is folded: the email shows whole.
    // Visible text only (not style rules, scripts or titles, which nobody reads).
    $visible = static function (?DOMNode $n) use ($xpath, $words): int {
        if ($n === null) return 0;
        $c = 0;
        foreach ($xpath->query('.//text()[not(ancestor::style) and not(ancestor::script) and not(ancestor::title)]', $n) as $t) $c += $words($t->nodeValue);
        return $c;
    };
    $folded = 0;
    foreach ($signatures as $node) {
        // A signature inside another one is counted with its outer one, not twice.
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) if (in_array($p, $signatures, true)) continue 2;
        $folded += $visible($node);
    }
    if ($visible($doc->getElementsByTagName('body')->item(0)) - $folded < 3) { return ['body_html' => $html, 'signature_html' => '']; }
    $signatureHtml = '';
    foreach ($signatures as $node) {
        // A nested signature belongs to its outer container; do not duplicate it.
        $parent = $node->parentNode;
        $nested = false;
        while ($parent !== null) {
            if (in_array($parent, $signatures, true)) { $nested = true; break; }
            $parent = $parent->parentNode;
        }
        if (!$nested) { $signatureHtml .= $doc->saveHTML($node); }
    }
    foreach (array_reverse($signatures) as $node) { $node->parentNode?->removeChild($node); }
    $body = $doc->getElementsByTagName('body')->item(0);
    $bodyHtml = '';
    foreach ($body->childNodes as $node) { $bodyHtml .= $doc->saveHTML($node); }
    return ['body_html' => $bodyHtml, 'signature_html' => $signatureHtml];
}

/** Re-split at display time so previously imported messages benefit without a reimport. */
function ecw_message_display_parts(array $message): array
{
    $split = ecw_split_quoted((string)($message['body_html'] ?? ''), (string)($message['body_text'] ?? ''));
    $asHtml = static fn($html, $text): string => (string)($html ?: nl2br(htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
    $hasHtml = (string)($message['body_html'] ?? '') !== '';
    $current = ecw_split_signature_html($hasHtml ? (string)$split['body_html'] : $asHtml('', $split['body_text']));
    $quoted = $asHtml($split['quoted_html'], $split['quoted_text'])
        . $asHtml($message['quoted_html'] ?? '', $message['quoted_text'] ?? '');
    return $current + ['quoted_html' => $quoted, 'has_quoted' => trim($quoted) !== ''];
}
