<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/compose.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['ecw_test_pdo'] = $pdo;
$pdo->exec('CREATE TABLE ecw_drafts (id INTEGER PRIMARY KEY, organization_id INT, attachments_json TEXT, provider_push_pending INT DEFAULT 0, mailbox_id INT)');
$entries = [['id'=>'one','filename'=>'a.pdf'],['id'=>'two','filename'=>'b.pdf']];
$pdo->prepare('INSERT INTO ecw_drafts (id, organization_id, attachments_json) VALUES (1,1,?)')->execute([json_encode($entries)]);
try { ecw_draft_detach(2,1,'one'); throw new Exception('Isolation failed'); } catch (RuntimeException $e) {}
if (count(json_decode(ecw_draft_load(1,1)['attachments_json'],true)) !== 2) { throw new Exception('Wrong organization changed draft'); }
ecw_draft_detach(1,1,'one');
$remaining=json_decode(ecw_draft_load(1,1)['attachments_json'],true);
if (count($remaining)!==1 || $remaining[0]['id']!=='two') { throw new Exception('Wrong attachment removed'); }
try { ecw_draft_detach(1,1,'missing'); throw new Exception('Missing ID accepted'); } catch (RuntimeException $e) {}
echo "PASS attachment removal, organization isolation and missing attachment rejection\n";
