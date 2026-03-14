<?php
/**
 * Compatibility redirect.
 * Canonical dashboard page is /ui/home.php, but first-run users go to quickstart.
 */

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

$redirectTarget = 'home.php';
try {
    if (function_exists('stobeShouldRedirectToQuickstart') && stobeShouldRedirectToQuickstart()) {
        $redirectTarget = 'quickstart.php';
    }
} catch (Throwable $exception) {
    stobeLogException($exception, 'UI index quickstart redirect check failed');
}

header('Location: ' . $redirectTarget, true, 302);
exit;

