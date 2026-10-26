<?php

// Second example plugin: loads after parity_probe (byte-sorted directory order).
$GLOBALS['PLUGIN_PARITY_TRACE'][] = 'parity_probe_order:globals';

chimRegisterPromptInjection('character_bottom', 'parity_probe_order.marker', '<parity_probe_order/>', 10);
