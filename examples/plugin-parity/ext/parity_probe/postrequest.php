<?php

// Runs only after a model route completed its turn; output is discarded.
$GLOBALS['PLUGIN_PARITY_TRACE'][] = 'parity_probe:postrequest:' . strval($gameRequest[0] ?? '');
