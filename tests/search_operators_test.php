<?php declare(strict_types=1);
// Search operators: file types (*.step, ext:), wildcards, relative dates, sizes, direction.
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/lib/search.php';
$check = static function (bool $ok, string $what): void { if (!$ok) throw new RuntimeException('FAIL ' . $what); };

$f = ecw_search_parse('*.step');
$check($f['filename'] === ['*.step'] && $f['keywords'] === [], '*.step is a file type, not text');
$f = ecw_search_parse('.STP offer');
$check($f['filename'] === ['*.STP'] && $f['keywords'] === ['offer'], '.stp alone is a file type');
$f = ecw_search_parse('ext:step filetype:.pdf');
$check($f['filename'] === ['*.step', '*.pdf'], 'ext: and filetype:');
$f = ecw_search_parse('drawing*.pdf');
$check($f['filename'] === ['drawing*.pdf'], 'a wildcard name with an extension');
$f = ecw_search_parse('v2.0 1.5');
$check($f['filename'] === [] && $f['keywords'] === ['v2.0', '1.5'], 'version numbers stay text');

$check(ecw_search_filename_pattern('*.step') === '%.step', '*.step ends in .step');
$check(ecw_search_filename_pattern('drawing') === '%drawing%', 'no wildcard: anywhere in the name');
$check(ecw_search_filename_pattern('a_b?.pdf') === 'a=_b_.pdf', '_ escaped, ? one character');
$check(ecw_search_filename_pattern('100%*') === '100=%%', '% escaped');

$f = ecw_search_parse('newer_than:7d older_than:1y larger:5mb smaller:200kb is:sent in:Support');
$check($f['after'] !== null && strtotime($f['after'] . ' UTC') > time() - 8 * 86400, 'newer_than:7d');
$check($f['before'] !== null && strtotime($f['before'] . ' UTC') < time() - 360 * 86400, 'older_than:1y');
$check($f['larger'] === 5 * 1048576 && $f['smaller'] === 200 * 1024, 'sizes');
$check($f['direction'] === 'outgoing' && $f['folder'] === ['Support'], 'is:sent and in:');
$check(ecw_search_size('1,5m') === 1572864 && ecw_search_size('big') === null, 'size parsing');
$check(!ecw_search_is_empty(ecw_search_parse('larger:5mb')) && !ecw_search_is_empty(ecw_search_parse('is:sent')), 'a size or direction alone is a search');

echo "PASS search: *.step / ext: / wildcards, relative dates, sizes, sent / received, in:\n";
