<?php

if (!function_exists('stobeSynthesizeViaXtts')) {
    function stobeSynthesizeViaXtts(string $speechText, array &$runtime): string|false {
        return stobeSynthesizeViaLocalProviderCore('xtts', $speechText, $runtime);
    }
}

