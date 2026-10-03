<?php

// Context marker: proves the context.php stage ran for this request type.
$GLOBALS['PLUGIN_PARITY_TRACE'][] = 'parity_probe:context:' . strval($gameRequest[0] ?? '');
