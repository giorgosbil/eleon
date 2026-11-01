<?php declare(strict_types=1);
/**
 * Settings → AI agents moved into Settings → AI (the Agents tab of
 * web/settings/ai.php). Old links and bookmarks land there.
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
ecw_require_admin();
header('Location: ai.php?tab=agents', true, 301);
exit;
