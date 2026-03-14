<?php

if (!function_exists('stobeSynthesizeViaChatterbox')) {
    function stobeSynthesizeViaChatterbox(string $speechText, array &$runtime): string|false {
        return stobeSynthesizeViaLocalProviderCore('chatterbox', $speechText, $runtime);
    }
}

