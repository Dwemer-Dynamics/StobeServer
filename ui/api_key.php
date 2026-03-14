<?php
/**
 * Legacy endpoint kept for backward compatibility.
 * API keys are now managed in api_badges.php.
 */

header('Location: api_badges.php', true, 302);
exit;

