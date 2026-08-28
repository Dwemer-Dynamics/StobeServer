<?php
// The containing page supplies the scope, form ID, endpoint and CSRF token.
$presetConfig = [
    'scope' => $presetScope,
    'formId' => $presetFormId,
    'endpoint' => $webRoot . '/ui/settings_presets_api.php?scope=' . rawurlencode($presetScope),
    'token' => $presetToken,
];
?>
<link rel="stylesheet" href="<?= h($webRoot) ?>/ui/css/settings_presets.css">
<section class="stobe-presets" aria-label="Settings presets" data-preset-config="<?= h(json_encode($presetConfig)) ?>">
    <div class="stobe-preset-row">
        <label for="stobePresetSelect">Settings preset</label>
        <select id="stobePresetSelect" disabled><option>Loading presets…</option></select>
        <button type="button" data-preset-action="apply" disabled>Apply</button>
        <details class="stobe-preset-manage">
            <summary>Manage presets</summary>
            <div class="stobe-preset-tools">
                <label for="stobePresetName">New preset name</label>
                <input id="stobePresetName" type="text" maxlength="80" autocomplete="off">
                <button type="button" data-preset-action="save" disabled>Save as new</button>
                <button type="button" data-preset-action="overwrite" disabled>Overwrite selected</button>
                <button type="button" data-preset-action="export" disabled>Export selected</button>
                <button type="button" data-preset-action="import" disabled>Import preset</button>
                <button type="button" data-preset-action="delete" disabled>Delete selected</button>
                <input type="file" id="stobePresetFile" accept=".json,application/json" hidden>
            </div>
        </details>
    </div>
    <p class="stobe-preset-help">Applies behavior settings only. Review changes, then <?= $presetScope === 'profile' ? 'Save Profile' : 'Save All' ?>. Prompts, connectors and other settings stay unchanged.</p>
    <p class="stobe-preset-status" role="status" aria-live="polite"></p>
</section>
<script src="<?= h($webRoot) ?>/ui/js/settings_presets.js" defer></script>
