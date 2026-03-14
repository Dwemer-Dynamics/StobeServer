<?php
// YAML parser supporting simple key-value pairs and nested objects (via indentation)
if (!function_exists('parse_simple_yaml')) {
    function parse_simple_yaml($yaml) {
        $result = array();
        $lines = preg_split('/\r?\n/', strval($yaml));
        $stack = array(&$result);
        $indentStack = array(0);

        foreach ($lines as $line) {
            $indent = strlen($line) - strlen(ltrim($line));
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            if (preg_match('/^([a-zA-Z0-9_\-]+):\s*(.*)$/', $trimmed, $m)) {
                $key = $m[1];
                $val = trim($m[2]);

                while (count($indentStack) > 1 && $indent <= $indentStack[count($indentStack) - 1]) {
                    array_pop($stack);
                    array_pop($indentStack);
                }

                if ($val === '') {
                    $parsedVal = array();
                } elseif ($val[0] === '[' && $val[strlen($val) - 1] === ']') {
                    $arrayStr = substr($val, 1, -1);
                    $arrayItems = array_map('trim', explode(',', $arrayStr));
                    $parsedVal = array();
                    foreach ($arrayItems as $item) {
                        if ($item === '') {
                            continue;
                        }
                        if (is_numeric($item)) {
                            $parsedVal[] = $item + 0;
                        } elseif (strtolower($item) === 'true') {
                            $parsedVal[] = true;
                        } elseif (strtolower($item) === 'false') {
                            $parsedVal[] = false;
                        } elseif (strtolower($item) === 'null') {
                            $parsedVal[] = null;
                        } else {
                            if ((($item[0] === '"' && $item[strlen($item) - 1] === '"') ||
                                 ($item[0] === "'" && $item[strlen($item) - 1] === "'"))) {
                                $parsedVal[] = stripslashes(substr($item, 1, -1));
                            } else {
                                $parsedVal[] = $item;
                            }
                        }
                    }
                } elseif (is_numeric($val)) {
                    $parsedVal = $val + 0;
                } elseif (strtolower($val) === 'true') {
                    $parsedVal = true;
                } elseif (strtolower($val) === 'false') {
                    $parsedVal = false;
                } elseif (strtolower($val) === 'null') {
                    $parsedVal = null;
                } else {
                    if ((($val[0] === '"' && $val[strlen($val) - 1] === '"') ||
                         ($val[0] === "'" && $val[strlen($val) - 1] === "'"))) {
                        $parsedVal = stripslashes(substr($val, 1, - 1));
                    } else {
                        $parsedVal = $val;
                    }
                }

                $stack[count($stack) - 1][$key] = $parsedVal;
                if (is_array($parsedVal)) {
                    $stack[] = &$stack[count($stack) - 1][$key];
                    array_push($indentStack, $indent);
                }
            }
        }

        return $result;
    }
}
