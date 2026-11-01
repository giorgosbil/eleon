<?php declare(strict_types=1);
/**
 * Email Case Workspace — application entry point.
 *
 * The one URL worth bookmarking. Everything else in this directory is reachable
 * from the navigation once signed in, so nobody needs to know a filename.
 */

require_once dirname(__DIR__) . '/lib/session.php';

if (ecw_context() !== null) {
    ecw_redirect(ecw_web_base() . 'app.php');
}

ecw_redirect(ecw_web_base() . 'login.php');
