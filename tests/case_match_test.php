<?php declare(strict_types=1);
// "Add to case" suggestion: only the AI's confident pick among the candidates is shown (lib/case_match.php).
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/lib/case_match.php';
$cands = [['id' => 12, 'case_number' => 'CASE-00012', 'name' => 'LYSSY L80 service', 'status' => 'open', 'shared' => 1],
          ['id' => 40, 'case_number' => 'CASE-00040', 'name' => 'Other', 'status' => 'open', 'shared' => 0]];
$check = static function (bool $ok, string $what): void { if (!$ok) throw new RuntimeException('FAIL ' . $what); };
$r = ecw_case_match_pick($cands, 'Sure: {"case": "C12", "confidence": 88, "why": "Same LYSSY L80-5000 service request"}');
$check(count($r) === 1 && $r[0]['id'] === 12 && $r[0]['why'] === 'Same LYSSY L80-5000 service request' && $r[0]['confidence'] === 88, 'a confident pick is shown');
$check(ecw_case_match_pick($cands, '{"case": "C12", "confidence": 60, "why": "maybe"}') === [], 'unsure: nothing');
$check(ecw_case_match_pick($cands, '{"case": null, "confidence": 0}') === [], 'none: nothing');
$check(ecw_case_match_pick($cands, '{"case": "C99", "confidence": 95, "why": "x"}') === [], 'a case not among the candidates is never trusted');
$check(ecw_case_match_pick($cands, 'I think it is case 12') === [], 'no JSON: nothing');
echo "PASS case match: only a confident pick among the candidates\n";
