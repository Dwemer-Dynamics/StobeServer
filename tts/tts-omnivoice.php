<?php

if (!function_exists('stobeSynthesizeViaOmniVoice')) {
    function stobeSynthesizeViaOmniVoice(string $speechText, array &$runtime): string|false {
        return stobeSynthesizeViaLocalProviderCore('omnivoice', $speechText, $runtime);
    }
}

