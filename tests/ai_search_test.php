<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ECW_TESTING', true);
require_once dirname(__DIR__).'/lib/ai.php';
$pdo=ecw_db();$pdo->beginTransaction();
function verify(bool $value,string $label):void {if(!$value)throw new RuntimeException($label);echo "PASS $label\n";}
try {
 $insert=static function(string $table,array $data) use($pdo):int {$keys=array_keys($data);$pdo->prepare('INSERT INTO `'.ecw_table($table).'` (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($data));return (int)$pdo->lastInsertId();};
 $org=$insert('organizations',['name'=>'AI regression']);
 $user=$insert('users',['name'=>'AI Tester','email'=>'ai-'.$org.'@example.test','password_hash'=>'x']);
 $mailbox=$insert('mailboxes',['organization_id'=>$org,'provider'=>'microsoft','provider_account_id'=>'ai-'.$org,'email_address'=>'ai@example.test','sync_status'=>'connected']);
 $folder=$insert('mail_folders',['mailbox_id'=>$mailbox,'provider_folder_id'=>'inbox','name'=>'Inbox','folder_type'=>'inbox']);
 $thread=$insert('email_threads',['mailbox_id'=>$mailbox,'provider_conversation_id'=>'ai-thread','normalized_subject'=>'Context']);
 $ids=[];
 for($i=0;$i<8;$i++){
  $ids[]=$insert('email_messages',['mailbox_id'=>$mailbox,'email_thread_id'=>$thread,'mail_folder_id'=>$folder,'provider_message_id'=>'msg-'.$i,'subject'=>'Context','from_email'=>'sender@example.test','from_name'=>'George Brown','direction'=>'incoming','received_at'=>'2026-09-20 12:0'.$i.':00','body_text'=>'Message '.$i.' about calibration.','body_html'=>'<p>Message '.$i.'</p>','body_fetch_state'=>'fetched','has_attachments'=>$i===0?1:0]);
  $insert('message_participants',['email_message_id'=>end($ids),'role'=>'from','email'=>'sender@example.test','name'=>'George Brown']);
 }
 $insert('email_attachments',['email_message_id'=>$ids[0],'provider_attachment_id'=>'file','filename'=>'GLO01234_rev_A.step','mime_type'=>'application/octet-stream','size_bytes'=>123]);
 $search=static function(string $query)use($pdo,$mailbox):array { $c=ecw_search_conditions(ecw_search_parse($query));$st=$pdo->prepare('SELECT m.id FROM `'.ecw_table('email_messages').'` m JOIN `'.ecw_table('email_threads').'` t ON t.id=m.email_thread_id WHERE m.mailbox_id=? AND '.implode(' AND ',$c['where']));$st->execute(array_merge([$mailbox],$c['params']));return $st->fetchAll(PDO::FETCH_COLUMN);};
 verify(count($search('filename:GLO01234'))===1,'filename filter matches attachment metadata');
 verify(count($search('attachment:"GLO01234_rev_A.step"'))===1,'attachment alias and exact punctuation');
 verify(count($search('GLO01234_rev_A.step'))===1,'bare filename search');
 verify(count($search('filename:"GLO01234%"'))===0,'filename SQL wildcards are literal');
 verify(count($search('from:"George Brown"'))===8,'display-name sender search');
 verify(!ecw_search_is_empty(ecw_search_parse('filename:step')),'filename filter is not treated as empty');
 $rows=ecw_ai_reply_messages($mailbox,$ids[6]);
 verify(array_column($rows,'id')===array_slice($ids,1,6),'reply includes target and five previous messages, excludes later email');
 try {ecw_ai_reply_messages($mailbox+100000,$ids[6]);throw new LogicException('Mailbox leak');}catch(InvalidArgumentException $expected){echo "PASS reply mailbox isolation\n";}
 $GLOBALS['ecw_ai_test_config']=['key'=>'fake','daily_requests'=>2];$calls=0;$payloads=[];
 $GLOBALS['ecw_ai_transport']=static function($payload)use(&$calls,&$payloads){$calls++;$payloads[]=$payload;return ['text'=>'Suggested reply','usage'=>['input_tokens'=>100,'output_tokens'=>20],'truncated'=>false];};
 $reply=ecw_ai_run($org,$user,$mailbox,['mode'=>'reply','message_id'=>$ids[6],'question'=>'Reply politely']);
 verify($reply['context_count']===6 && $calls===1,'one bounded provider call for six-message reply');
 verify(strlen(json_encode($payloads[0],JSON_UNESCAPED_UNICODE))<=ECW_AI_MAX_PAYLOAD_BYTES && $payloads[0]['max_tokens']===1000,'payload and output limits');
 verify(!isset($payloads[0]['tools']) && str_contains($payloads[0]['system'],'untrusted'),'email is evidence, with no tool or send capability');
 $files=ecw_ai_run($org,$user,$mailbox,['mode'=>'ask','question'=>'Which file was sent?','query'=>'filename:GLO01234']);
 verify($files['kind']==='files' && !$files['charged'] && $calls===1 && count($files['files'])===1,'a question about files is answered from the database, with no AI request');
 $answer=ecw_ai_run($org,$user,$mailbox,['mode'=>'ask','question'=>'What did George say?','query'=>'from:"George Brown"']);
 verify($answer['context_count']>0 && $calls===2 && !array_diff(array_column($answer['sources'],'id'),$ids),'general assistant returns scoped source links');
 try {ecw_ai_run($org+100000,$user,$mailbox,['question'=>'Find something']);throw new LogicException('Organization leak');}catch(InvalidArgumentException $expected){echo "PASS organization isolation\n";}
 try {ecw_ai_run($org,$user,$mailbox,['mode'=>'reply','message_id'=>$ids[6],'question'=>'Again']);throw new LogicException('Daily limit failed');}catch(EcwRateLimitExceeded $expected){verify($calls===2,'daily limit blocks paid call before provider access');}
} finally {$pdo->rollBack();}
