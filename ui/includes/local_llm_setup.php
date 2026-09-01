<?php
/**
 * Quickstart local LLM setup - UI glue only. Probing, testing, profile writes,
 * the session lifecycle, and $_SESSION['local_llm_setup_csrf'] all belong to
 * lib/core/local_llm_setup.php; this file only holds the provider presets and
 * the include seam so ui/quickstart.php stays readable.
 */

function stobeLocalLlmSetupBackendPath(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib'
        . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'local_llm_setup.php';
}

/**
 * Loads the backend and lets it handle any local_llm_* request before markup is
 * emitted. Returns false when the backend is absent so the page can hide the
 * section instead of rendering controls that cannot work.
 *
 * Must be called after ui/quickstart.php has a $db handle: the backend's apply
 * path reuses the stobeQuickstart* profile helpers declared in that file.
 */
function stobeLocalLlmSetupBoot(sql $db): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $backend = stobeLocalLlmSetupBackendPath();
    if (!is_file($backend)) {
        $available = false;
        return $available;
    }

    require_once($backend);
    $available = function_exists('stobeLocalLlmHandleRequest');
    if (!$available) {
        return $available;
    }

    // Seeds $_SESSION['local_llm_setup_csrf'], then emits JSON and exits for
    // local_llm_probe / local_llm_test / local_llm_apply.
    stobeLocalLlmHandleRequest($db);

    return $available;
}

function stobeLocalLlmSetupCsrfToken(): string
{
    return trim(strval($_SESSION['local_llm_setup_csrf'] ?? ''));
}

/**
 * Conventional local runtime defaults. Each base_url is the full
 * OpenAI-compatible chat completions endpoint the server will call.
 */
function stobeLocalLlmSetupProviders(): array
{
    return [
        'lmstudio' => [
            'label' => 'LM Studio',
            'base_url' => 'http://127.0.0.1:1234/v1/chat/completions',
            'model_hint' => 'Use the model identifier shown on the LM Studio server tab, for example llama-3.1-8b-instruct.',
            'bind_hint' => 'LM Studio: enable "Serve on local network" so the server listens beyond loopback.',
        ],
        'ollama' => [
            'label' => 'Ollama',
            'base_url' => 'http://127.0.0.1:11434/v1/chat/completions',
            'model_hint' => 'Use a name from "ollama list", for example llama3.1:8b.',
            'bind_hint' => 'Ollama: set OLLAMA_HOST=0.0.0.0 before starting the service.',
        ],
        'llamacpp' => [
            'label' => 'llama.cpp (llama-server)',
            'base_url' => 'http://127.0.0.1:8080/v1/chat/completions',
            'model_hint' => 'Use the loaded model name or alias.',
            'bind_hint' => 'llama-server: start it with --host 0.0.0.0.',
        ],
        'koboldcpp' => [
            'label' => 'KoboldCPP',
            'base_url' => 'http://127.0.0.1:5001/v1/chat/completions',
            'model_hint' => 'Use the model name returned by Probe models.',
            'bind_hint' => 'Enable access from your local network in KoboldCPP.',
        ],
        'custom' => [
            'label' => 'Custom OpenAI-compatible',
            'base_url' => 'http://127.0.0.1:8000/v1/chat/completions',
            'model_hint' => 'Use the model id your runtime reports, then probe to confirm it is listed.',
            'bind_hint' => 'Bind your runtime to 0.0.0.0 (or your LAN address) rather than 127.0.0.1.',
        ],
    ];
}
