<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__).'/lib/sync/queue.php';
$pdo=ecw_db();$pdo->beginTransaction();
try {
 $pdo->exec("INSERT INTO ecw_organizations(name) VALUES ('Queue recovery test')");$org=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO ecw_mailboxes(organization_id,provider,provider_account_id,email_address,sync_status) VALUES (?,'microsoft',?,'queue@example.test','connected')")->execute([$org,'queue-'.$org]);$mailbox=(int)$pdo->lastInsertId();
 $insert=$pdo->prepare("INSERT INTO ecw_sync_queue(mailbox_id,job_type,reason,status,available_at,locked_by,locked_at,attempts) VALUES (?,'delta','manual','running',?,?,?,?)");
 $old=gmdate('Y-m-d H:i:s',time()-3600);$host=gethostname();$ids=[];
 $recentRemote=gmdate('Y-m-d H:i:s',time()-1800);$deadRemote=gmdate('Y-m-d H:i:s',time()-7200);
 foreach ([[$host.':2147483647',$old,1],[$host.':'.getmypid(),$old,1],['another-host:2147483647',$recentRemote,1],[$host.':2147483647',ecw_now(),1],[$host.':2147483647',$old,6],['old-container:1',$deadRemote,1]] as [$worker,$time,$attempts]){
  $insert->execute([$mailbox,$old,$worker,$time,$attempts]);$ids[]=(int)$pdo->lastInsertId();
 }
 ecw_queue_recover_abandoned();
 $st=$pdo->prepare('SELECT status FROM ecw_sync_queue WHERE id IN ('.implode(',',array_fill(0,6,'?')).') ORDER BY id');$st->execute($ids);
 if($st->fetchAll(PDO::FETCH_COLUMN)!==['queued','running','running','running','failed','queued'])throw new RuntimeException('Incorrect abandoned-job recovery');
 echo "PASS dead worker requeued; live, remote and fresh workers untouched; exhausted job fails visibly\n";
}finally{$pdo->rollBack();}
