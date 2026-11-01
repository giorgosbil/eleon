<?php declare(strict_types=1);
require_once dirname(__DIR__).'/lib/auth.php';
if(!ecw_local_features() || !getenv('ECW_SETUP_TOKEN')){http_response_code(404);exit;}
header('Cache-Control: no-store');
$pdo=ecw_db();
// The database has no tables yet (setup did not run, or the package had no database/migrations): say so.
try { $exists=(int)$pdo->query('SELECT COUNT(*) FROM `'.ecw_table('users').'`')->fetchColumn()>0; }
catch (PDOException $e) {
 http_response_code(503);
 echo '<!doctype html><meta charset="utf-8"><title>Database not ready</title><body style="font-family:system-ui;max-width:640px;margin:60px auto;padding:0 16px;line-height:1.5">'
  .'<h1>The database is not set up yet</h1><p>The tables have not been created. Usually the installation package is missing the <code>database/migrations</code> folder.</p>'
  .'<ol><li>Check that <code>database\migrations</code> exists in the app folder and contains <code>.sql</code> files.</li>'
  .'<li>Run: <code>docker compose run --rm init</code></li><li>Reload this page.</li></ol></body>';
 exit;
}
$error='';$done=false;
if(!$exists && ($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 $locked=false;
 try {
  if(!ecw_csrf_valid((string)($_POST['csrf']??''))||!hash_equals((string)getenv('ECW_SETUP_TOKEN'),(string)($_POST['setup_token']??'')))throw new RuntimeException('That setup code is not right. Copy it again (step 8 of the setup guide does it for you) and paste it here.');
  $name=trim((string)($_POST['name']??''));$email=strtolower(trim((string)($_POST['email']??'')));$org=trim((string)($_POST['organization']??''));$password=(string)($_POST['password']??'');
  if(!$name||!$org||mb_strlen($name)>120||mb_strlen($org)>120||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>320)throw new RuntimeException('Enter a company name, your name, and a valid email.');
  if($problem=ecw_password_problem($password))throw new RuntimeException($problem);
  $locked=(int)$pdo->query("SELECT GET_LOCK(CONCAT(DATABASE(),':setup'),5)")->fetchColumn()===1;
  if(!$locked || (int)$pdo->query('SELECT COUNT(*) FROM `'.ecw_table('users').'`')->fetchColumn()>0)throw new RuntimeException('Setup has already completed or another setup is running.');
  $pdo->beginTransaction();
  $pdo->prepare('INSERT INTO `'.ecw_table('organizations').'` (name,timezone) VALUES (?,\'UTC\')')->execute([$org]);$orgId=(int)$pdo->lastInsertId();
  $pdo->prepare('INSERT INTO `'.ecw_table('users').'` (name,email,password_hash) VALUES (?,?,?)')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);$id=(int)$pdo->lastInsertId();
  $pdo->prepare('INSERT INTO `'.ecw_table('organization_users').'` (organization_id,user_id,role) VALUES (?,?,\'admin\')')->execute([$orgId,$id]);$pdo->commit();$done=true;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof PDOException?'Setup could not be completed. Check container logs.':$e->getMessage();}
 finally {if($locked)$pdo->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(),':setup'))");}
}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$csrf=ecw_csrf_token();
// After a mistake the form comes back with what was typed (never the code or the password).
$old=static fn(string $k):string=>$error!==''?trim((string)($_POST[$k]??'')):'';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex, nofollow"><title><?= $exists||$done?'Workspace ready':'Create your workspace' ?></title><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/auth.css"><link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css"></head>
<body class="auth-body setup-body"><main class="auth-card setup-card">
<?php if($exists||$done): ?>
  <div class="setup-mark ok" aria-hidden="true"><i class="fa fa-check"></i></div>
  <h1>Your workspace is ready</h1>
  <p class="auth-sub">The administrator account exists. Sign in with its email and password.</p>
  <a class="setup-btn primary" href="login.php">Sign in <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
  <ol class="setup-next">
    <li><i class="fa fa-envelope" aria-hidden="true"></i><span><strong>Connect your mailbox</strong> in Settings → Mailbox.</span></li>
    <li><i class="fa fa-user-plus" aria-hidden="true"></i><span><strong>Invite your colleagues</strong> in Settings → Users.</span></li>
    <li><i class="fa fa-heart-pulse" aria-hidden="true"></i><span><strong>Check System health</strong>: everything should be green.</span></li>
  </ol>
<?php else: ?>
  <div class="setup-mark" aria-hidden="true"><i class="fa fa-building"></i></div>
  <h1>Create your workspace</h1>
  <p class="auth-sub">One time only: your company and its first administrator. Colleagues are invited later and choose their own passwords.</p>
  <?php if($error): ?><div class="auth-error" role="alert"><i class="fa fa-circle-exclamation" aria-hidden="true"></i> <?= $h($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="on" class="setup-form">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <fieldset class="setup-group setup-code">
      <legend><span class="setup-num">1</span> Setup code</legend>
      <div class="setup-secret"><input id="setup_token" name="setup_token" type="password" autocomplete="off" spellcheck="false" required autofocus aria-describedby="codeHint"><button type="button" class="setup-eye" data-show="setup_token" aria-label="Show the code"><i class="fa fa-eye"></i></button></div>
      <p class="setup-hint" id="codeHint">Step 8 of the setup guide copies it for you. It is also on the last lines of the start command.</p>
    </fieldset>
    <fieldset class="setup-group">
      <legend><span class="setup-num">2</span> Your company</legend>
      <label for="organization">Company name</label>
      <input id="organization" name="organization" type="text" maxlength="120" autocomplete="organization" required value="<?= $h($old('organization')) ?>">
    </fieldset>
    <fieldset class="setup-group">
      <legend><span class="setup-num">3</span> You, the administrator</legend>
      <label for="name">Your name</label>
      <input id="name" name="name" type="text" maxlength="120" autocomplete="name" required value="<?= $h($old('name')) ?>">
      <label for="email">Your email</label>
      <input id="email" name="email" type="email" maxlength="320" autocomplete="username" required value="<?= $h($old('email')) ?>">
      <label for="password">Choose a password</label>
      <div class="setup-secret"><input id="password" name="password" type="password" minlength="<?= ECW_PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required aria-describedby="pwHint"><button type="button" class="setup-eye" data-show="password" aria-label="Show the password"><i class="fa fa-eye"></i></button></div>
      <p class="setup-hint" id="pwHint">At least <?= ECW_PASSWORD_MIN_LENGTH ?> characters.</p>
    </fieldset>
    <button type="submit" class="primary">Create workspace</button>
  </form>
  <p class="auth-foot"><i class="fa fa-lock" aria-hidden="true"></i> Runs on your own server. Nothing you type here is sent to us.</p>
  <script>
  // Show / hide the code and the password while typing.
  document.querySelectorAll('.setup-eye').forEach(function (b) {
    b.addEventListener('click', function () {
      var input = document.getElementById(b.dataset.show), show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      b.querySelector('i').className = show ? 'fa fa-eye-slash' : 'fa fa-eye';
      b.setAttribute('aria-label', (show ? 'Hide' : 'Show') + (b.dataset.show === 'password' ? ' the password' : ' the code'));
    });
  });
  </script>
<?php endif; ?>
</main></body></html>
