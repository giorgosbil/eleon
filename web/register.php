<?php declare(strict_types=1);
require_once dirname(__DIR__).'/lib/invitations.php';
if(!ecw_local_features()){http_response_code(404);exit;}
header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');
$token=(string)($_POST['token']??$_GET['token']??'');$error='';$done=false;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 try{
  if(!ecw_csrf_valid((string)($_POST['csrf']??'')))throw new RuntimeException('Reload the page and try again.');
  ecw_rate_limit_hit('register_ip','ip:'.($_SERVER['REMOTE_ADDR']??'unknown'),20,3600);
  ecw_accept_invitation($token,(string)($_POST['name']??''),(string)($_POST['password']??''));$done=true;
 }catch(PDOException $e){$error='An account already exists for this invitation, or registration is unavailable.';}
 catch(Throwable $e){$error=$e->getMessage();}
}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$csrf=ecw_csrf_token();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create your account</title><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/auth.css"></head><body class="page-body"><main class="page"><h1>Create your account</h1>
<?php if($done): ?><p>Your account is ready. <a href="login.php">Sign in</a>, then connect your mailbox under Settings → Mailbox.</p>
<?php else: ?><p>Use the invitation from your company administrator. Your mailbox stays private; conversations you link to a company case are shared with the workspace.</p>
<?php if($error): ?><p class="flash err"><?= $h($error) ?></p><?php endif; ?>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="token" value="<?= $h($token) ?>"><label>Your name<input name="name" required maxlength="120" autocomplete="name"></label><label>Choose your password<input type="password" name="password" required minlength="10" autocomplete="new-password"></label><button class="primary">Create account</button></form><?php endif; ?></main></body></html>
