<?php

/**
 * StobeServer - Streaming Response Wrapper
 * 
 * Wraps main.php for streaming LLM responses back to the Stobe DLL.
 * Response format: actor|action|message\r\n
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once(__DIR__ . '/main.php');
