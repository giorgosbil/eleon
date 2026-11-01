<?php declare(strict_types=1);
/**
 * Interface language: English or Greek.
 *
 * The app is written in English; a Greek page is translated in the browser by
 * assets/i18n.js with the dictionary assets/lang/el.js (English text → Greek),
 * which never touches mail content, notes or anything people typed. Each
 * person picks a language in Settings → Account; until they do, the browser's
 * language decides.
 */

require_once __DIR__ . '/db.php';

const ECW_LANGUAGES = ['en' => 'English', 'el' => 'Ελληνικά'];

/** The language from the browser (Accept-Language), en when it is not one we have. */
function ecw_browser_language(): string
{
    foreach (explode(',', strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $part) {
        $code = substr(trim(explode(';', $part)[0]), 0, 2);
        if (isset(ECW_LANGUAGES[$code])) return $code;
    }
    return 'en';
}

/** The language for this person (their choice, else the browser's). */
function ecw_user_language(?int $userId): string
{
    if ($userId) {
        try {
            $st = ecw_db()->prepare('SELECT language FROM `' . ecw_table('users') . '` WHERE id = ?');
            $st->execute([$userId]);
            $lang = (string)$st->fetchColumn();
            if (isset(ECW_LANGUAGES[$lang])) return $lang;
        } catch (Throwable $e) { /* before migration 032 */ }
    }
    $cookie = (string)($_COOKIE['ecw_lang'] ?? '');
    return isset(ECW_LANGUAGES[$cookie]) ? $cookie : ecw_browser_language();
}

function ecw_user_language_save(int $userId, ?string $lang): void
{
    if ($lang !== null && !isset(ECW_LANGUAGES[$lang])) throw new InvalidArgumentException('Unknown language.');
    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET language = ? WHERE id = ?')->execute([$lang, $userId]);
}

/**
 * The <script> tags that translate the page, or '' for English. $base: web base path.
 * Loaded early in <body> so text is translated as it is parsed.
 */
function ecw_i18n_scripts(string $lang, string $base): string
{
    if ($lang === 'en') return '';
    $dir = dirname(__DIR__) . '/web/assets';
    $dict = $dir . '/lang/' . $lang . '.js';
    if (!is_file($dict)) return '';
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    return '<script>document.documentElement.lang = ' . json_encode($lang) . ';</script>'
        . '<script src="' . $h($base) . 'assets/lang/' . $h($lang) . '.js?v=' . filemtime($dict) . '"></script>'
        . '<script src="' . $h($base) . 'assets/i18n.js?v=' . filemtime($dir . '/i18n.js') . '"></script>';
}
