<?php
$pronunciationEditId = intval($pronunciationEditRow['id'] ?? 0);
$pronunciationEditEnabled = stobeTtsPronunciationBoolean($pronunciationEditRow['enabled'] ?? true);
$previewConnectors = is_array($pronunciationPreviewOptions['connectors'] ?? null)
    ? $pronunciationPreviewOptions['connectors']
    : [];
$previewVoices = is_array($pronunciationPreviewOptions['voices'] ?? null)
    ? $pronunciationPreviewOptions['voices']
    : [];
$previewConnectorId = intval($pronunciationPreviewOptions['default_connector_id'] ?? 0);
$previewVoice = strval($pronunciationPreviewOptions['default_voice'] ?? '');
$pronunciationPageUrl = stobe_voice_build_url(['view' => 'pronunciations'], $isEmbed);
?>

<div
    id="pronunciation-studio"
    class="pronunciation-layout"
    data-preview-url="<?= h(rtrim($webRoot, '/') . '/ui/api/tts_pronunciation_preview.php') ?>"
>
    <section id="pronunciation-editor" class="pronunciation-card" aria-labelledby="pronunciation-editor-title">
        <div class="pronunciation-card-head">
            <h2 id="pronunciation-editor-title"><?= $pronunciationEditId > 0 ? 'Edit Pronunciation' : 'Add Pronunciation' ?></h2>
            <?php if ($pronunciationEditId > 0): ?>
                <a class="action-button" href="<?= h($pronunciationPageUrl . '#pronunciation-editor') ?>">New</a>
            <?php endif; ?>
        </div>
        <p class="pronunciation-help">Only generated speech changes. Dialogue text and saved history stay as written.</p>

        <form method="post" action="<?= h($pronunciationPageUrl) ?>">
            <input type="hidden" name="pronunciation_action" value="save">
            <input type="hidden" name="pronunciation_id" value="<?= h($pronunciationEditId) ?>">

            <div class="pronunciation-field-grid">
                <div>
                    <label for="pronunciation_source">Written</label>
                    <div class="pronunciation-input-preview">
                        <input
                            type="text"
                            id="pronunciation_source"
                            name="source_text"
                            maxlength="120"
                            value="<?= h($pronunciationEditRow['source_text'] ?? '') ?>"
                            placeholder="Example: Cat-Lon"
                            required
                        >
                        <button
                            type="button"
                            class="pronunciation-play"
                            data-preview-input="pronunciation_source"
                            aria-label="Preview written pronunciation"
                            title="Preview written text"
                        >&#9654;</button>
                    </div>
                </div>
                <div>
                    <label for="pronunciation_spoken">Spoken</label>
                    <div class="pronunciation-input-preview">
                        <input
                            type="text"
                            id="pronunciation_spoken"
                            name="spoken_text"
                            maxlength="240"
                            value="<?= h($pronunciationEditRow['spoken_text'] ?? '') ?>"
                            placeholder="Example: Cat Lon"
                            required
                        >
                        <button
                            type="button"
                            class="pronunciation-play"
                            data-preview-input="pronunciation_spoken"
                            aria-label="Preview spoken pronunciation"
                            title="Preview spoken text"
                        >&#9654;</button>
                    </div>
                </div>
                <div class="wide">
                    <label for="pronunciation_names">NPC Names <span class="pronunciation-scope-note">(optional)</span></label>
                    <input
                        type="text"
                        id="pronunciation_names"
                        name="npc_names"
                        maxlength="512"
                        value="<?= h($pronunciationEditRow['npc_names'] ?? '') ?>"
                        placeholder="Beep, Agnu"
                    >
                </div>
                <div>
                    <label for="pronunciation_races">Races <span class="pronunciation-scope-note">(optional)</span></label>
                    <input
                        type="text"
                        id="pronunciation_races"
                        name="races"
                        maxlength="512"
                        value="<?= h($pronunciationEditRow['races'] ?? '') ?>"
                        placeholder="Shek, Greenlander"
                    >
                </div>
                <div>
                    <label for="pronunciation_tags">Knowledge Tags <span class="pronunciation-scope-note">(optional)</span></label>
                    <input
                        type="text"
                        id="pronunciation_tags"
                        name="oghma_tags"
                        maxlength="512"
                        value="<?= h($pronunciationEditRow['oghma_tags'] ?? '') ?>"
                        placeholder="tech_hunters, ancient"
                    >
                </div>
            </div>

            <p class="pronunciation-scope-note">Separate alternatives with commas. Every filled filter must match the active speaker.</p>
            <label class="pronunciation-enabled" for="pronunciation_enabled">
                <input
                    type="checkbox"
                    id="pronunciation_enabled"
                    name="enabled"
                    value="1"
                    <?= $pronunciationEditEnabled ? 'checked' : '' ?>
                >
                Enabled
            </label>

            <div class="action-row">
                <button type="submit" class="action-button upload-csv">
                    <?= $pronunciationEditId > 0 ? 'Save Changes' : 'Add Pronunciation' ?>
                </button>
                <?php if ($pronunciationEditId > 0): ?>
                    <a class="action-button" href="<?= h($pronunciationPageUrl) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="pronunciation-card" aria-labelledby="pronunciation-list-title">
        <div class="pronunciation-card-head">
            <h2 id="pronunciation-list-title">Pronunciations</h2>
            <span class="small-muted"><?= h(count($pronunciationRows)) ?> shown</span>
        </div>

        <div class="pronunciation-preview-bar" aria-label="Pronunciation preview settings">
            <div>
                <label for="pronunciation_preview_connector">Connector</label>
                <select id="pronunciation_preview_connector" <?= empty($previewConnectors) ? 'disabled' : '' ?>>
                    <?php foreach ($previewConnectors as $connector): ?>
                        <?php $connectorId = intval($connector['id'] ?? 0); ?>
                        <option value="<?= h($connectorId) ?>" <?= $connectorId === $previewConnectorId ? 'selected' : '' ?>>
                            <?= h(($connector['label'] ?? '') !== '' ? $connector['label'] : ('Connector #' . $connectorId)) ?>
                            (<?= h($connector['driver'] ?? '') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="pronunciation_preview_voice">Installed Voice</label>
                <select id="pronunciation_preview_voice" <?= empty($previewVoices) ? 'disabled' : '' ?>>
                    <?php foreach ($previewVoices as $voice): ?>
                        <option value="<?= h($voice) ?>" <?= strcasecmp(strval($voice), $previewVoice) === 0 ? 'selected' : '' ?>>
                            <?= h($voice) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <audio id="pronunciation_preview_audio" class="pronunciation-preview-audio" controls preload="none"></audio>
                <p id="pronunciation_preview_status" class="pronunciation-preview-status" role="status" aria-live="polite">
                    <?= empty($previewConnectors) || empty($previewVoices) ? 'Add a connector and installed voice to use previews.' : 'Choose a play button to compare pronunciations.' ?>
                </p>
            </div>
        </div>

        <form class="pronunciation-toolbar" method="get" action="">
            <input type="hidden" name="view" value="pronunciations">
            <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <div>
                <label for="pronunciation_search">Search</label>
                <input
                    type="text"
                    id="pronunciation_search"
                    name="pronunciation_search"
                    value="<?= h($pronunciationSearch) ?>"
                    placeholder="Written, spoken, name, race, or tag"
                >
            </div>
            <div>
                <label for="pronunciation_tag_filter">Knowledge Tag</label>
                <select id="pronunciation_tag_filter" name="pronunciation_tag">
                    <option value="">All tags</option>
                    <?php foreach ($pronunciationTags as $tag): ?>
                        <option value="<?= h($tag) ?>" <?= strcasecmp($tag, $pronunciationTag) === 0 ? 'selected' : '' ?>><?= h($tag) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pronunciation-toolbar-actions">
                <button type="submit" class="action-button edit">Filter</button>
                <a class="action-button" href="<?= h($pronunciationPageUrl) ?>">Clear</a>
            </div>
        </form>

        <?php if (count($pronunciationRows) === 0): ?>
            <div class="pronunciation-empty">
                <h3><?= $pronunciationSearch !== '' || $pronunciationTag !== '' ? 'No matching pronunciations' : 'No pronunciations yet' ?></h3>
                <p><?= $pronunciationSearch !== '' || $pronunciationTag !== '' ? 'Clear the filters or try another search.' : 'Add the first written and spoken form using the editor.' ?></p>
            </div>
        <?php else: ?>
            <div class="pronunciation-list">
                <?php foreach ($pronunciationRows as $row): ?>
                    <?php
                    $rowId = intval($row['id'] ?? 0);
                    $rowEnabled = stobeTtsPronunciationBoolean($row['enabled'] ?? true);
                    $rowBuiltin = stobeTtsPronunciationBoolean($row['is_builtin'] ?? false);
                    $rowActionable = $rowId > 0;
                    $builtinFormId = 'pronunciation-builtin-form-' . $rowId;
                    $builtinEditorId = 'pronunciation-builtin-editor-' . $rowId;
                    $rowScopes = [];
                    if (trim(strval($row['npc_names'] ?? '')) !== '') {
                        $rowScopes[] = 'Name: ' . trim(strval($row['npc_names']));
                    }
                    if (trim(strval($row['races'] ?? '')) !== '') {
                        $rowScopes[] = 'Race: ' . trim(strval($row['races']));
                    }
                    if (trim(strval($row['oghma_tags'] ?? '')) !== '') {
                        $rowScopes[] = 'Tag: ' . trim(strval($row['oghma_tags']));
                    }
                    ?>
                    <article class="pronunciation-row <?= $rowEnabled ? '' : 'disabled' ?>">
                        <div class="pronunciation-value">
                            <span class="pronunciation-value-label">Written</span>
                            <div class="pronunciation-value-line">
                                <strong><?= h($row['source_text'] ?? '') ?></strong>
                                <button
                                    type="button"
                                    class="pronunciation-play"
                                    data-preview-text="<?= h($row['source_text'] ?? '') ?>"
                                    aria-label="Preview written text <?= h($row['source_text'] ?? '') ?>"
                                    title="Preview written text"
                                >&#9654;</button>
                            </div>
                        </div>
                        <div class="pronunciation-value">
                            <span class="pronunciation-value-label">Spoken</span>
                            <div class="pronunciation-value-line" <?= $rowBuiltin ? 'data-pronunciation-builtin-display' : '' ?>>
                                <strong><?= h($row['spoken_text'] ?? '') ?></strong>
                                <button
                                    type="button"
                                    class="pronunciation-play"
                                    data-preview-text="<?= h($row['spoken_text'] ?? '') ?>"
                                    aria-label="Preview spoken text <?= h($row['spoken_text'] ?? '') ?>"
                                    title="Preview spoken text"
                                >&#9654;</button>
                            </div>
                            <?php if ($rowBuiltin && $rowActionable): ?>
                                <div class="pronunciation-value-line pronunciation-builtin-editor"
                                     id="<?= h($builtinEditorId) ?>" data-pronunciation-builtin-editor hidden>
                                    <input type="text" id="<?= h($builtinEditorId) ?>-input" name="spoken_text"
                                           value="<?= h($row['spoken_text'] ?? '') ?>" maxlength="240" required
                                           form="<?= h($builtinFormId) ?>" data-pronunciation-builtin-input
                                           aria-label="Spoken form for <?= h($row['source_text'] ?? '') ?>">
                                    <button type="button" class="pronunciation-play"
                                            data-preview-input="<?= h($builtinEditorId) ?>-input"
                                            aria-label="Preview edited spoken text <?= h($row['source_text'] ?? '') ?>"
                                            title="Preview spoken text">&#9654;</button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="pronunciation-scopes">
                                <?php if (empty($rowScopes)): ?>
                                    <span class="pronunciation-scope global">All speakers</span>
                                <?php else: ?>
                                    <?php foreach ($rowScopes as $scope): ?>
                                        <span class="pronunciation-scope"><?= h($scope) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if ($rowBuiltin && $rowActionable): ?>
                                    <label class="pronunciation-enabled pronunciation-row-enabled">
                                        <input type="checkbox" name="enabled" value="1" form="<?= h($builtinFormId) ?>"
                                               <?= $rowEnabled ? 'checked' : '' ?>>
                                        Enabled
                                    </label>
                                <?php else: ?>
                                    <span class="pronunciation-state <?= $rowEnabled ? '' : 'off' ?>"><?= $rowEnabled ? 'Enabled' : 'Disabled' ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="pronunciation-actions">
                            <?php if ($rowBuiltin && $rowActionable): ?>
                                <form id="<?= h($builtinFormId) ?>" method="post" action="<?= h($pronunciationPageUrl) ?>"
                                      data-pronunciation-builtin-form>
                                    <input type="hidden" name="pronunciation_action" value="toggle" data-pronunciation-builtin-action>
                                    <input type="hidden" name="pronunciation_id" value="<?= h($rowId) ?>">
                                    <button type="submit" class="action-button" data-pronunciation-apply>Apply</button>
                                    <button type="button" class="action-button edit" data-pronunciation-edit
                                            aria-expanded="false" aria-controls="<?= h($builtinEditorId) ?>">Edit</button>
                                </form>
                                <form method="post" action="<?= h($pronunciationPageUrl) ?>">
                                    <input type="hidden" name="pronunciation_action" value="delete">
                                    <input type="hidden" name="pronunciation_id" value="<?= h($rowId) ?>">
                                    <button type="submit" class="btn-danger"
                                            onclick="return confirm('Delete this pronunciation?');">Delete</button>
                                </form>
                            <?php else: ?>
                                <a
                                    class="action-button edit"
                                    href="<?= h(stobe_voice_build_url(['view' => 'pronunciations', 'edit_pronunciation' => $rowId], $isEmbed, 'pronunciation-editor')) ?>"
                                >Edit</a>
                                <form method="post" action="<?= h($pronunciationPageUrl) ?>">
                                    <input type="hidden" name="pronunciation_action" value="toggle">
                                    <input type="hidden" name="pronunciation_id" value="<?= h($rowId) ?>">
                                    <input type="hidden" name="enabled" value="<?= $rowEnabled ? '0' : '1' ?>">
                                    <button type="submit" class="action-button"><?= $rowEnabled ? 'Disable' : 'Enable' ?></button>
                                </form>
                                <form method="post" action="<?= h($pronunciationPageUrl) ?>">
                                    <input type="hidden" name="pronunciation_action" value="delete">
                                    <input type="hidden" name="pronunciation_id" value="<?= h($rowId) ?>">
                                    <button
                                        type="submit"
                                        class="btn-danger"
                                        onclick="return confirm('Delete this pronunciation?');"
                                    >Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
(function () {
    const studio = document.getElementById('pronunciation-studio');
    if (!studio) return;

    const connector = document.getElementById('pronunciation_preview_connector');
    const voice = document.getElementById('pronunciation_preview_voice');
    const audio = document.getElementById('pronunciation_preview_audio');
    const status = document.getElementById('pronunciation_preview_status');
    const endpoint = studio.dataset.previewUrl || '';

    function setStatus(message) {
        if (status) status.textContent = message;
    }

    document.querySelectorAll('.pronunciation-play').forEach(function (button) {
        button.addEventListener('click', async function () {
            const inputId = button.dataset.previewInput || '';
            const input = inputId ? document.getElementById(inputId) : null;
            const text = (input ? input.value : (button.dataset.previewText || '')).trim();
            if (!text) {
                setStatus('Enter text before previewing it.');
                if (input) input.focus();
                return;
            }
            if (!connector || !voice || !connector.value || !voice.value || !endpoint) {
                setStatus('Choose an available connector and installed voice first.');
                return;
            }

            button.disabled = true;
            setStatus('Generating preview...');
            try {
                const body = new FormData();
                body.append('connector_id', connector.value);
                body.append('voice', voice.value);
                body.append('text', text);
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: body
                });
                const result = await response.json();
                if (!response.ok || !result.ok || !result.audio_url) {
                    throw new Error(result.error || 'Preview could not be generated.');
                }
                audio.src = result.audio_url;
                await audio.play();
                setStatus('Playing preview.');
            } catch (error) {
                setStatus(error && error.message ? error.message : 'Preview could not be generated.');
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('[data-pronunciation-builtin-form]').forEach(function (form) {
        const row = form.closest('.pronunciation-row');
        const editButton = form.querySelector('[data-pronunciation-edit]');
        const applyButton = form.querySelector('[data-pronunciation-apply]');
        const action = form.querySelector('[data-pronunciation-builtin-action]');
        const editor = row ? row.querySelector('[data-pronunciation-builtin-editor]') : null;
        const display = row ? row.querySelector('[data-pronunciation-builtin-display]') : null;
        const input = editor ? editor.querySelector('[data-pronunciation-builtin-input]') : null;
        if (!editButton || !applyButton || !action || !editor || !display || !input) return;
        let originalValue = input.value;

        function setEditing(editing) {
            if (editing) {
                originalValue = input.value;
                display.hidden = true;
                editor.hidden = false;
                row.classList.add('editing');
                action.value = 'save_builtin';
                applyButton.textContent = 'Save';
                editButton.textContent = 'Cancel';
                editButton.setAttribute('aria-expanded', 'true');
                input.focus();
                input.select();
                return;
            }
            input.value = originalValue;
            display.hidden = false;
            editor.hidden = true;
            row.classList.remove('editing');
            action.value = 'toggle';
            applyButton.textContent = 'Apply';
            editButton.textContent = 'Edit';
            editButton.setAttribute('aria-expanded', 'false');
        }

        editButton.addEventListener('click', function () {
            setEditing(editor.hidden);
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                setEditing(false);
                editButton.focus();
            }
        });
    });
})();
</script>
