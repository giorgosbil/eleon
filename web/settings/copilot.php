<?php declare(strict_types=1);
/**
 * Settings → Copilot & AI apps.
 *   Admins: connect Microsoft 365 Copilot (Entra SSO) and download the agent package.
 *   Everyone: personal access tokens for other MCP apps (Claude, VS Code…).
 * Both use web/mcp.php: the built-in assistant's tools that only read (lib/mcp.php ECW_MCP_TOOLS).
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/copilot_package.php';
require_once dirname(__DIR__, 2) . '/lib/account_security.php';
$context = ecw_require_login(); ecw_require_feature('ai');
$orgId = (int)$context['organization_id']; $userId = (int)$context['user']['id']; $isAdmin = $context['role'] === 'admin';
$navActive = 'settings'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$mcpUrl = ecw_link_base_url() . '/other_things/web/mcp.php';
$public = (bool)preg_match('#^https://(?!localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)[^/]+#i', $mcpUrl);
$notice = null; $error = null; $newToken = null;

if ($isAdmin && ($_GET['download'] ?? '') === 'package') {
    $zip = ecw_copilot_package_zip($orgId, $mcpUrl);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="case-workspace-copilot-agent.zip"');
    header('Content-Length: ' . filesize($zip));
    readfile($zip);
    @unlink($zip);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) { $error = 'Your session expired. Please try again.'; }
    else {
        try {
            $do = (string)($_POST['do'] ?? '');
            if ($do === 'copilot' && $isAdmin) { ecw_copilot_settings_save($orgId, $_POST); $notice = 'Saved.'; }
            elseif ($do === 'token_new') { $newToken = ecw_mcp_token_create($orgId, $userId, (string)($_POST['name'] ?? '')); }
            elseif ($do === 'token_revoke') { ecw_mcp_token_revoke($userId, (int)($_POST['id'] ?? 0)); $notice = 'Token removed. Apps using it are disconnected.'; }
        } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    }
}
$cs = ecw_copilot_settings($orgId);
$tokens = ecw_mcp_tokens($userId);
$graphClient = (string)(getenv('ECW_GRAPH_CLIENT_ID') ?: '');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Copilot &amp; AI apps</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope">
  <h1>Connected apps <span class="muted small" style="font-weight:400">· Copilot &amp; AI apps</span></h1>
  <p class="muted">Let other AI assistants read this workspace with the Ask AI tools that look things up: search, count and read emails, read and list files, find amounts, look at cases, list unanswered emails.</p>
  <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">What they can and cannot do</span></summary>
    <p class="muted small">They see only what the signed-in person may see, and they can only read — never send, move, change or propose anything. Proposing a case, a note or a reply stays in Ask AI, where you confirm it.</p>
  </details>
  <?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>

  <section class="panel cop-section">
    <h2><span class="cop-ms" aria-hidden="true"></span> Microsoft 365 Copilot</h2>
    <p>People ask Copilot in Outlook, Teams or <a href="https://m365.cloud.microsoft/chat" target="_blank" rel="noopener">m365.cloud.microsoft</a> things like “which files did we exchange in CASE-00002?” and Copilot asks this workspace. They are signed in with their Microsoft account — no extra password.</p>
    <p class="muted small">Needs a Microsoft 365 Copilot licence for each person who uses it, and this server reachable from the internet over HTTPS.</p>
    <div class="cop-url"><span class="muted small">Server address (MCP)</span><code><?= $h($mcpUrl) ?></code></div>
    <?php if (!$public): ?><div class="cop-warn">Copilot runs in Microsoft’s cloud, so it cannot reach <code><?= $h(parse_url($mcpUrl, PHP_URL_HOST)) ?></code>. Put the app on a public HTTPS address first (set <code>ECW_BASE_URL</code>), for example through your firewall or a reverse proxy.</div><?php endif; ?>
    <?php if ($isAdmin): ?>
    <details class="st-fold cop-steps" <?= $cs['enabled'] ? '' : 'open' ?>>
      <summary><span class="st-fold-title"><i class="fa fa-list-ol" aria-hidden="true"></i> How to connect it</span><span class="st-fold-hint">About 15 minutes, once</span></summary>
      <ol>
        <li><strong>App registration.</strong> In <a href="https://entra.microsoft.com/" target="_blank" rel="noopener">Microsoft Entra admin center</a> → App registrations, use the app this workspace already signs in with<?= $graphClient !== '' ? ' (client ID <code>' . $h($graphClient) . '</code>)' : '' ?> or a new one. Copy its <em>Directory (tenant) ID</em> and <em>Application (client) ID</em> below.</li>
        <li><strong>Sign-in registration.</strong> In the <a href="https://dev.teams.microsoft.com/tools" target="_blank" rel="noopener">Teams developer portal</a> → Tools → <em>Microsoft Entra SSO client ID registration</em> → New: Base URL = the server address above, Client ID = the application ID, Scope = <code>api://&lt;client ID&gt;/access_as_user</code>. Save, then copy the <em>registration ID</em> and the <em>Application ID URI</em> below.</li>
        <li><strong>Back in the app registration:</strong> under <em>Expose an API</em> add a scope <code>access_as_user</code> and the client application <code><?= $h(ECW_MCP_TOKEN_STORE_CLIENT) ?></code>; under <em>Authentication</em> add the Web redirect URI <code>https://teams.microsoft.com/api/platform/v1.0/oAuthConsentRedirect</code>; in the <em>Manifest</em> add the Application ID URI from step 2 to <code>identifierUris</code>.</li>
        <li><strong>Switch it on</strong> here and <strong>download the agent package</strong>.</li>
        <li><strong>Upload it:</strong> <a href="https://admin.microsoft.com/" target="_blank" rel="noopener">Microsoft 365 admin center</a> → Settings → Integrated apps → Upload custom apps (or Teams → Apps → Manage your apps → Upload an app). “Case Workspace” then appears among the agents in Copilot.</li>
      </ol>
    </details>
    <form method="post" class="cop-form">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="do" value="copilot">
      <label class="checkbox-label"><input type="checkbox" name="enabled" value="1" <?= $cs['enabled'] ? 'checked' : '' ?>> Let Microsoft 365 Copilot read this workspace</label>
      <details class="st-fold st-fold-inline"<?= !$cs['enabled'] || ($error !== null && ($_POST['do'] ?? '') === 'copilot') ? ' open' : '' ?>><summary><span class="st-fold-title">Microsoft registration details</span><span class="st-fold-hint">The IDs from the steps above</span></summary>
      <div class="cop-form-ids">
      <label>Directory (tenant) ID <input type="text" name="tenant_id" value="<?= $h($cs['tenant_id']) ?>" placeholder="0b1c2d3e-…" autocomplete="off" spellcheck="false"></label>
      <label>Application (client) ID <input type="text" name="client_id" value="<?= $h($cs['client_id'] ?: $graphClient) ?>" placeholder="0b1c2d3e-…" autocomplete="off" spellcheck="false"></label>
      <label>Application ID URI(s) from the sign-in registration <input type="text" name="audiences" value="<?= $h(implode(' ', $cs['audiences'])) ?>" placeholder="api://auth-…" autocomplete="off" spellcheck="false"></label>
      <label>Sign-in registration ID (for the package) <input type="text" name="reference_id" value="<?= $h($cs['reference_id']) ?>" placeholder="from the Teams developer portal" autocomplete="off" spellcheck="false"></label>
      </div>
      </details>
      <div class="cop-actions">
        <button type="submit" class="primary">Save</button>
        <a class="button" href="?download=package"><i class="fa fa-download" aria-hidden="true"></i> Download agent package</a>
      </div>
      <p class="muted small">The package contains the agent, its instructions and the MCP connection to this server. Download it again if the server address or the registration ID change.</p>
    </form>
    <?php else: ?>
      <p class="muted"><?= $cs['enabled'] ? 'Connected by your administrator. Open Copilot and pick the “Case Workspace” agent.' : 'Not connected yet — ask an administrator.' ?></p>
    <?php endif; ?>
  </section>

  <section class="panel cop-section">
    <h2><i class="fa fa-key" aria-hidden="true"></i> Other AI apps (personal access tokens)</h2>
    <p>For MCP apps such as Claude, ChatGPT connectors, VS Code or Cursor. Add a server of type <em>HTTP</em> with the address above and the header <code>Authorization: Bearer &lt;token&gt;</code>. The app then sees exactly what you see.</p>
    <?php if ($newToken !== null): ?>
      <div class="cop-new-token" data-keep="1">
        <strong>Your new token — copy it now, it is shown only once:</strong>
        <div class="copy-row"><input type="text" readonly value="<?= $h($newToken) ?>" onclick="this.select()" aria-label="New token"><button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value); this.textContent='Copied'">Copy</button></div>
        <p class="muted small">Example (Claude Code): <code>claude mcp add --transport http case-workspace <?= $h($mcpUrl) ?> --header "Authorization: Bearer <?= $h($newToken) ?>"</code></p>
      </div>
    <?php endif; ?>
    <?php if ($tokens): ?>
      <ul class="cop-tokens">
        <?php foreach ($tokens as $t): ?>
          <li><span><strong><?= $h($t['name']) ?></strong> <code><?= $h($t['token_hint']) ?>…</code><br><span class="muted small">created <?= $h(ecw_tz_local($t['created_at'], 'j M Y')) ?> · <?= $t['last_used_at'] ? 'last used ' . $h(ecw_tz_local($t['last_used_at'])) : 'never used' ?></span></span>
            <form method="post"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="do" value="token_revoke"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button type="submit" class="danger" onclick="if (window.ecwArm && !window.ecwArm(this, 'Click again to remove')) return false;">Remove</button></form></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <details class="st-fold st-fold-inline"<?= !$tokens || ($error !== null && ($_POST['do'] ?? '') === 'token_new') ? ' open' : '' ?>><summary><span class="st-fold-title"><i class="fa fa-plus" aria-hidden="true"></i> Create a token</span></summary>
    <form method="post" class="cop-token-form">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="do" value="token_new">
      <input type="text" name="name" maxlength="80" placeholder="Name, e.g. Claude on my laptop" aria-label="Token name" required>
      <button type="submit">Create token</button>
    </form>
    </details>
    <p class="muted small">Treat a token like a password. Remove it here when you stop using the app.</p>
  </section>
</main>
</div>
</body></html>
