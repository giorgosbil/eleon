<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/compose.php';
require_once dirname(__DIR__) . '/lib/body.php';
$p = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['ecw_test_pdo']=$p;
$p->exec('CREATE TABLE ecw_email_messages (id INT, mailbox_id INT, provider_message_id TEXT)');
$p->exec('CREATE TABLE ecw_email_attachments (id INT, email_message_id INT,content_id TEXT,local_path TEXT,provider_attachment_id TEXT)');
$p->exec('CREATE TABLE ecw_mailboxes (id INT, organization_id INT)');
$p->exec('INSERT INTO ecw_mailboxes VALUES (2,1),(3,9),(4,1)');
$file=tempnam(sys_get_temp_dir(),'ecw-quote-');
try {
 file_put_contents($file,base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
 $p->exec("INSERT INTO ecw_email_messages VALUES (1,2,'message')");
 $p->prepare("INSERT INTO ecw_email_attachments VALUES (7,1,'logo',?,'attachment')")->execute([$file]);
 $quote=ecw_quote_block(['id'=>1,'from_email'=>'a@example.test','body_html'=>'<html><body><p>Hello</p><table border="0" style="border:0"><tr><td style="border:0"><img src="cid:logo"><p>Signature</p></td></tr></table></body></html>','quoted_html'=>'<p>Older history</p>']);
 if (!str_contains($quote,'action=attachment') || !str_contains($quote,'Older history') || !str_contains($quote,'Signature')) throw new Exception('Missing original content');
 $sent=ecw_sanitize_compose_html('<p>My answer</p>'.$quote);
 $embedded=ecw_embed_quoted_images(2,$sent);
 [$html,$attachments]=ecw_extract_inline_images($embedded);
 if (!str_contains($html,'My answer') || !str_contains($html,'cid:') || count($attachments)!==1 || preg_match('~src="[^"]*action=attachment~',$html)) throw new Exception('Quoted image not embedded for recipient');
 try { ecw_embed_quoted_images(3,$sent); throw new Exception('Workspace isolation failed'); } catch (RuntimeException $expected) {}
 // A picture copied from an open email into the reply carries the full link the browser used.
 foreach (['http://192.168.2.2:8080/other_things/web/api/index.php?action=attachment&amp;id=7', 'http://100.106.119.62:8080/other_things/web/api/index.php?action=attachment&amp;id=7', 'http://localhost:8080/other_things/web/api/?action=attachment&amp;id=7'] as $src) {
   $pasted = ecw_embed_quoted_images(2, '<p>See:</p><img src="' . $src . '">');
   [$html2, $att2] = ecw_extract_inline_images($pasted);
   if (!str_contains($html2, 'cid:') || count($att2) !== 1 || str_contains($html2, 'action=attachment')) throw new Exception('Pasted picture not embedded: ' . $src);
 }
 if (!str_contains(ecw_embed_quoted_images(4, '<img src="api/index.php?action=attachment&amp;id=7">'), 'data:image')) throw new Exception('A picture from another mailbox of the same workspace was not embedded');
 $foreign = '<img src="https://example.com/api/index.php?action=attachment&amp;id=7">';
 if (ecw_embed_quoted_images(2, $foreign) !== $foreign) throw new Exception('An image from another website was touched');
 // A reply separates the answer from the history the way Outlook does: a line across, then From / Sent / To / Subject.
 $reply = ecw_outgoing_paragraphs(ecw_sanitize_compose_html('<p>My answer</p>' . ecw_quote_block(['subject'=>'Pump','from_name'=>'Maria','from_email'=>'maria@client.test','to_json'=>'[{"name":"Us","email":"us@ours.test"}]','occurred_at'=>'2026-10-08 18:53:45','body_html'=>'<p>Old text</p>'], 'Europe/Athens')));
 if (!str_contains($reply, 'border-top:1px solid #E1E1E1') || !str_contains($reply, '<b>Sent:</b> Thursday, 8 October 2026 21:53') || !str_contains($reply, 'Maria &lt;maria@client.test&gt;') || strpos($reply, 'My answer') > strpos($reply, 'Old text')) throw new Exception('Reply separator line or header lost on the way out: ' . $reply);
 $split = ecw_message_display_parts(['body_html' => $reply, 'body_text' => '']);
 if (!$split['has_quoted'] || str_contains($split['body_html'], 'Old text')) throw new Exception('The app no longer recognises the history in its own reply');
 $sig=ecw_sanitize_signature_html('<p>One</p><p><br></p><p>Two</p>');
 if (str_contains($sig,'<p>') || !str_contains($sig,'line-height:1.25')) throw new Exception('Signature spacing not compact');
 echo "PASS quote images, signature tables, history, send embedding (also pasted pictures with the full LAN/Tailscale link), workspace isolation and compact signatures\n";
} finally {unlink($file);}
