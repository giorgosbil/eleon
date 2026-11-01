<?php declare(strict_types=1);
/**
 * Email Case Workspace — Greek and English spelling, and word suggestions,
 * for the composer (assets/spelling.js).
 *
 *   ecw_spell_check(words)  which words are misspelled, with corrections. Hunspell
 *                           with the Greek (el_GR) and US English dictionaries
 *                           (Debian hunspell-el, hunspell-en-us, in the image).
 *   ecw_spell_vocab()       the words the person actually writes: counted from
 *                           the emails they sent from the account, most used
 *                           first. The composer offers them to finish a word as it
 *                           is typed (accents optional: "καλημερα" → "καλημέρα").
 *
 * The person's own words (names, products, part numbers in their sent mail, and
 * the ones they add with "Add to dictionary") are never marked wrong.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

const ECW_SPELL_DICTS = 'el_GR,en_US';
const ECW_SPELL_MAX_WORDS = 400;
const ECW_VOCAB_SIZE = 12000;

/** A word worth checking: letters only (Greek or Latin), 2+ letters, not an acronym. */
function ecw_spell_checkable(string $w): bool
{
    if (mb_strlen($w) < 2 || mb_strlen($w) > 40) return false;
    if (!preg_match('/^[\p{L}][\p{L}\'’-]*$/u', $w)) return false;
    if (preg_match('/^[\p{Lu}\d]{2,}$/u', $w)) return false;   // ISO, PDF, ΑΕ
    return true;
}

/**
 * @param list<string> $words
 * @return array<string, list<string>> misspelled word => up to 5 corrections
 */
function ecw_spell_check(array $words, array $known = [], int $max = ECW_SPELL_MAX_WORDS): array
{
    $knownLc = array_flip(array_map('mb_strtolower', $known));
    $todo = [];
    foreach (array_slice(array_values(array_unique($words)), 0, $max) as $w) {
        $w = trim((string)$w, "'’-");
        if (!ecw_spell_checkable($w) || isset($knownLc[mb_strtolower($w)])) continue;
        $todo[] = $w;
    }
    if ($todo === []) return [];
    $bin = trim((string)shell_exec('command -v hunspell 2>/dev/null'));
    if ($bin === '') throw new RuntimeException('The spelling dictionaries are not installed (rebuild the app).');
    $proc = proc_open([$bin, '-d', ECW_SPELL_DICTS, '-a', '-i', 'UTF-8'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('The spell checker could not start.');
    // "^" makes hunspell read each line as text, never as a command.
    fwrite($pipes[0], implode("\n", array_map(static fn($w) => '^' . $w, $todo)) . "\n");
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $wrong = [];
    foreach (preg_split('/\R/u', $out) ?: [] as $line) {
        // "& word 7 0: a, b, c" (with corrections) or "# word 0" (none).
        if (preg_match('/^&\s+(\S+)\s+\d+\s+\d+:\s*(.*)$/u', $line, $m)) {
            $wrong[$m[1]] = array_slice(array_values(array_filter(array_map('trim', explode(',', $m[2])))), 0, 5);
        } elseif (preg_match('/^#\s+(\S+)\s+\d+/u', $line, $m)) {
            $wrong[$m[1]] = [];
        }
    }
    return $wrong;
}

/** Without accents and in lower case: "Καλημέρα" → "καλημερα" (how suggestions match what is typed). */
function ecw_spell_fold(string $w): string
{
    $w = mb_strtolower($w);
    // Greek accents and diaeresis by table (the image has no intl extension), then any other mark.
    $w = strtr($w, ['ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω', 'ς' => 'σ']);
    if (class_exists('Normalizer')) $w = (string)preg_replace('/\p{Mn}/u', '', (string)Normalizer::normalize($w, Normalizer::FORM_D));
    return $w;
}

/**
 * The words this person writes, from the emails they sent from the account (the
 * newest 1500), most used first. Kept a day in storage/cache.
 * @return list<string>
 */
function ecw_spell_vocab(int $userId, int $mailboxId): array
{
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $file = $dir . '/vocab-' . $mailboxId . '.json';
    if (is_file($file) && time() - (int)filemtime($file) < 86400) {
        $cached = json_decode((string)file_get_contents($file), true);
        if (is_array($cached)) return array_merge(ecw_spell_own_words($userId), $cached);
    }
    $st = ecw_db()->prepare("SELECT body_text FROM `" . ecw_table('email_messages') . "` WHERE mailbox_id = ? AND direction = 'outgoing' AND provider_removed_at IS NULL
                              ORDER BY occurred_at DESC LIMIT 1500");
    $st->execute([$mailboxId]);
    $count = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $text) {
        // What the person wrote: the reply above the quoted history and the signature dashes.
        $text = (string)preg_split('/\R(?:-{2,}|_{5,}|From:|Από:|Sent:|Στάλθηκε:|On .{5,80} wrote:)/u', (string)$text)[0];
        if (!preg_match_all('/[\p{L}][\p{L}\'’]{2,}/u', mb_substr($text, 0, 6000), $m)) continue;
        foreach ($m[0] as $w) {
            if (preg_match('/^[\p{Lu}]{2,}$/u', $w)) continue;   // shouting and acronyms
            $count[$w] = ($count[$w] ?? 0) + 1;
        }
    }
    // One spelling per word: the one written most ("Καλημέρα" at the start of a line and
    // "καλημέρα" in a sentence count together, kept as written most often).
    $best = [];
    foreach ($count as $w => $n) {
        $k = mb_strtolower($w);
        if (!isset($best[$k]) || $n > $best[$k][1]) $best[$k] = [$w, $n + ($best[$k][1] ?? 0)];
        else $best[$k][1] += $n;
    }
    uasort($best, static fn($a, $b) => $b[1] <=> $a[1]);
    $words = array_values(array_map(static fn($x) => $x[0], array_filter($best, static fn($x) => $x[1] >= 2)));
    $words = array_slice($words, 0, ECW_VOCAB_SIZE);
    // Only words spelled right, so a typo the person made often is still marked and never
    // offered: what the dictionaries accept, and names and brands (a capital letter).
    try {
        $plain = array_values(array_filter($words, static fn($w) => mb_strtolower($w) === $w));
        $wrong = [];
        foreach (array_chunk($plain, 3000) as $chunk) $wrong += ecw_spell_check($chunk, [], 3000);
        if ($wrong !== []) $words = array_values(array_filter($words, static fn($w) => !isset($wrong[$w])));
    } catch (Throwable $e) { ecw_log('spelling: vocabulary not checked: ' . $e->getMessage()); }
    @file_put_contents($file, json_encode($words, JSON_UNESCAPED_UNICODE));
    return array_merge(ecw_spell_own_words($userId), $words);
}

/** Words the person added with "Add to dictionary". @return list<string> */
function ecw_spell_own_words(int $userId): array
{
    $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $p = json_decode((string)$st->fetchColumn(), true);
    return array_values(array_filter((array)($p['spell_words'] ?? []), 'is_string'));
}

/** "Add to dictionary": never marked wrong again for this person (at most 3000 words). */
function ecw_spell_learn(int $userId, string $word): void
{
    $word = trim($word);
    if (!ecw_spell_checkable($word)) throw new InvalidArgumentException('Only words can be added.');
    $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $p = json_decode((string)$st->fetchColumn(), true) ?: [];
    $words = array_values(array_filter((array)($p['spell_words'] ?? []), 'is_string'));
    if (!in_array($word, $words, true)) $words[] = $word;
    $p['spell_words'] = array_slice($words, -3000);
    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET preferences_json = ? WHERE id = ?')->execute([json_encode($p, JSON_UNESCAPED_UNICODE), $userId]);
}
