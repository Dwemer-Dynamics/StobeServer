<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['plugin_packages_csrf'])) {
    $_SESSION['plugin_packages_csrf'] = bin2hex(random_bytes(32));
}
$pluginPackagesCsrf = (string)$_SESSION['plugin_packages_csrf'];
session_write_close();

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = $uiPos !== false ? substr($scriptPath, 0, $uiPos) : '';
$webRoot = rtrim($webRoot === '/' ? '' : $webRoot, '/');
$isEmbedded = isset($_GET['embed']) && strval($_GET['embed']) === '1';

$TITLE = 'Stobe - Server Plugins';
ob_start();
include __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html';
if (!$isEmbedded) {
    include __DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'navbar.php';
}
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/ui/css/main.css">
<style>
main { padding: <?php echo $isEmbedded ? '10px 12px 24px' : '80px 12px 24px'; ?>; }
.plugin-shell {
    --pp-accent: var(--stobe-accent, #e6b76c);
    --pp-surface: var(--stobe-surface-1, #232323);
    --pp-surface-2: var(--stobe-surface-3, #1d1d1d);
    --pp-border: var(--stobe-border-soft, #3a3a3a);
    --pp-text: var(--stobe-text, #f5f1e8);
    --pp-muted: var(--stobe-text-muted, #aaa69e);
    --pp-success: var(--stobe-success, #42a36d);
    --pp-warning: var(--stobe-warning, #d5a849);
    --pp-danger: var(--stobe-danger, #c65353);
    max-width: 1080px; margin: 0 auto;
}
/* Compact inline heading row (.stobe-page-head in main.css) plus the existing rule. */
body .plugin-heading { margin: 0 0 10px; padding: 0 0 8px; border-bottom: 1px solid #3a3a3a; }
.plugin-heading h1 { margin: 0; color: #e6b76c !important; font-family: 'MagicCards', sans-serif; font-weight: normal; font-size: 1.3rem; }
.plugin-heading p { margin: 0; color: #bdbdbd; }
.plugin-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; margin-bottom: 8px; }
.plugin-toolbar .plugin-spacer { flex: 1 1 auto; }
.plugin-checked { color: var(--pp-muted); font-size: 0.8rem; }
.plugin-progress { display: grid; grid-template-columns: 1fr auto; gap: 4px 10px; align-items: center; margin-bottom: 8px; padding: 8px 10px; border: 1px solid var(--pp-border); border-radius: 6px; background: var(--pp-surface); }
.plugin-progress[hidden] { display: none; }
.plugin-progress progress { grid-column: 1 / -1; width: 100%; height: 8px; accent-color: var(--pp-accent); }
.plugin-progress-label { color: var(--pp-text); font-size: 0.86rem; }
.plugin-progress-detail { color: var(--pp-muted); font-size: 0.8rem; white-space: nowrap; }
.plugin-message { margin-bottom: 8px; padding: 7px 10px; border-radius: 6px; border: 1px solid var(--pp-border); background: var(--pp-surface); color: var(--pp-text); font-size: 0.86rem; }
.plugin-message:empty { display: none; }
.plugin-message.is-error { border-color: rgba(198, 83, 83, 0.6); color: #ff9d9d; }
.plugin-message.is-success { border-color: rgba(66, 163, 109, 0.55); color: #8fdcab; }
.plugin-section { margin-bottom: 12px; }
.plugin-section h2 { margin: 0 0 6px; font-family: 'MagicCards', sans-serif; font-weight: normal; font-size: 1.05rem; color: var(--pp-accent); }
.plugin-list { display: grid; gap: 4px; }
.plugin-row { display: grid; grid-template-columns: minmax(180px, 1fr) 110px minmax(140px, auto); gap: 4px 14px; align-items: center; border: 1px solid var(--pp-border); background: var(--pp-surface); border-radius: 6px; padding: 7px 10px; }
.plugin-row:focus-within { border-color: rgba(230, 183, 108, 0.45); }
.plugin-main { min-width: 0; }
.plugin-name { color: #fff; font-weight: 600; overflow-wrap: anywhere; }
.plugin-desc { color: var(--pp-muted); font-size: 0.8rem; margin-top: 1px; overflow-wrap: anywhere; }
.plugin-meta { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
.plugin-tag { display: inline-block; padding: 1px 7px; border: 1px solid #4a4a4a; border-radius: 999px; color: #c9c4ba; font-size: 0.72rem; line-height: 1.5; white-space: nowrap; }
.plugin-tag.is-ok { border-color: rgba(66, 163, 109, 0.55); color: #8fdcab; }
.plugin-tag.is-warn { border-color: rgba(213, 168, 73, 0.6); color: #f0cf85; }
.plugin-tag.is-accent { border-color: rgba(230, 183, 108, 0.5); color: var(--pp-accent); }
.plugin-version { color: var(--pp-accent); white-space: nowrap; font-size: 0.86rem; text-align: right; overflow: hidden; text-overflow: ellipsis; }
.plugin-version.is-muted { color: var(--pp-muted); }
.plugin-version small { display: block; color: var(--pp-muted); font-size: 0.74rem; }
.plugin-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: 2px; }
.plugin-actions select { min-height: 30px; padding: 2px 6px; font-size: 0.78rem; background: var(--pp-surface-2); color: var(--pp-text); border: 1px solid #4a4a4a; border-radius: 6px; }
.plugin-actions a { color: var(--pp-accent); font-size: 0.8rem; margin: 0 4px; }
.plugin-empty { padding: 9px 10px; border: 1px dashed var(--pp-border); border-radius: 6px; color: var(--pp-muted); font-size: 0.86rem; }
.plugin-footnote { margin: 4px 0 0; color: var(--pp-muted); font-size: 0.78rem; }
.plugin-shell button:focus-visible, .plugin-shell select:focus-visible, .plugin-shell a:focus-visible, .plugin-dialog button:focus-visible { outline: 2px solid var(--pp-accent, #e6b76c); outline-offset: 1px; }
/* main.css pads every file input; keep the picker out of layout, focus order and the accessibility tree. */
#plugin-file[hidden] { display: none !important; }
.plugin-dialog { max-width: 460px; width: calc(100% - 24px); padding: 0; border: 1px solid #4a4a4a; border-radius: 8px; background: #232323; color: #f5f1e8; }
.plugin-dialog::backdrop { background: rgba(0, 0, 0, 0.6); }
.plugin-dialog form { padding: 14px 16px 12px; }
.plugin-dialog h3 { margin: 0 0 8px; font-size: 1.05rem; color: #e6b76c; }
.plugin-dialog p { margin: 0 0 8px; font-size: 0.86rem; color: #d8d3ca; }
.plugin-dialog .plugin-dialog-actions { display: flex; justify-content: flex-end; gap: 4px; margin-top: 10px; }
@media (max-width: 700px) {
    .plugin-row { grid-template-columns: 1fr; }
    .plugin-version { text-align: left; }
    .plugin-actions { justify-content: flex-start; }
}
</style>

<main>
    <div class="plugin-shell" id="plugin-shell">
        <header class="plugin-heading stobe-page-head">
            <h1 class="stobe-page-head-title">Server Plugins</h1>
            <p class="stobe-page-head-note">Game-bundled packages sync when the game loads. Updates keep each plugin's declared settings and data.</p>
        </header>

        <div class="plugin-toolbar">
            <button id="plugin-upload" class="btn btn-primary btn-sm" type="button" aria-describedby="plugin-trust-note">Upload Package</button>
            <input id="plugin-file" type="file" accept=".dwpkg,.zip" hidden>
            <button id="plugin-check" class="btn btn-secondary btn-sm" type="button">Check for Updates</button>
            <button id="plugin-refresh" class="btn btn-secondary btn-sm" type="button">Refresh</button>
            <span class="plugin-spacer"></span>
            <span id="plugin-checked" class="plugin-checked"></span>
        </div>

        <div id="plugin-progress" class="plugin-progress" hidden>
            <span id="plugin-progress-label" class="plugin-progress-label"></span>
            <span id="plugin-progress-detail" class="plugin-progress-detail"></span>
            <progress id="plugin-progress-bar" max="100" aria-labelledby="plugin-progress-label"></progress>
        </div>
        <div id="plugin-message" class="plugin-message" role="status" aria-live="polite"></div>

        <section class="plugin-section" aria-labelledby="plugin-installed-heading">
            <h2 id="plugin-installed-heading">Installed</h2>
            <div id="plugin-installed" class="plugin-list" aria-busy="true"><div class="plugin-empty">Loading installed plugins...</div></div>
        </section>

        <section class="plugin-section" aria-labelledby="plugin-available-heading">
            <h2 id="plugin-available-heading">Available</h2>
            <div id="plugin-available" class="plugin-list"><div class="plugin-empty">Loading catalog...</div></div>
        </section>

        <p class="plugin-footnote">Installed means the package's files and database migrations were activated. It does not confirm that the plugin has run in game.</p>
        <p id="plugin-trust-note" class="plugin-footnote">Server plugins run PHP code on this server. Only upload packages from authors you trust.</p>
    </div>
</main>

<dialog id="plugin-remove-dialog" class="plugin-dialog" aria-labelledby="plugin-remove-title">
    <form method="dialog">
        <h3 id="plugin-remove-title">Remove plugin</h3>
        <p id="plugin-remove-body"></p>
        <p id="plugin-remove-data"></p>
        <p id="plugin-remove-game"></p>
        <div class="plugin-dialog-actions">
            <button value="cancel" class="btn btn-secondary btn-sm" type="submit" autofocus>Cancel</button>
            <button value="confirm" id="plugin-remove-confirm" class="btn btn-danger btn-sm" type="submit">Remove</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    'use strict';
    const PRODUCT = 'Stobe';
    const endpoint = <?php echo json_encode($webRoot . '/ui/api/plugin_packages.php', JSON_UNESCAPED_SLASHES); ?>;
    const csrfToken = <?php echo json_encode($pluginPackagesCsrf); ?>;
    const MAX_ARCHIVE_BYTES = 512 * 1024 * 1024;
    const CHUNK_BYTES = 1024 * 1024;
    const POLL_LIMIT = 240;

    const el = function (id) { return document.getElementById(id); };
    const installedList = el('plugin-installed');
    const availableList = el('plugin-available');
    const message = el('plugin-message');
    const progress = el('plugin-progress');
    const progressLabel = el('plugin-progress-label');
    const progressDetail = el('plugin-progress-detail');
    const progressBar = el('plugin-progress-bar');
    const fileInput = el('plugin-file');
    const uploadButton = el('plugin-upload');
    const checkButton = el('plugin-check');
    const refreshButton = el('plugin-refresh');
    const checkedLabel = el('plugin-checked');
    const dialog = el('plugin-remove-dialog');

    const state = { items: [], catalog: [], releases: {}, busy: false };

    function setMessage(text, kind) {
        message.className = 'plugin-message' + (kind ? ' is-' + kind : '');
        message.textContent = text || '';
    }

    function showProgress(label, percent, detail) {
        progress.hidden = false;
        progressLabel.textContent = label;
        progressDetail.textContent = detail || '';
        if (typeof percent === 'number') {
            progressBar.value = Math.max(0, Math.min(100, percent));
        } else {
            progressBar.removeAttribute('value');
        }
    }

    function hideProgress() {
        progress.hidden = true;
    }

    function setBusy(busy) {
        state.busy = busy;
        document.querySelectorAll('#plugin-shell button, #plugin-shell select').forEach(function (control) {
            control.disabled = busy;
        });
    }

    function formatBytes(bytes) {
        if (!bytes) return '0 MB';
        return (bytes / (1024 * 1024)).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0) + ' MB';
    }

    function canonical(name) {
        return String(name || '').toLowerCase();
    }

    async function api(action, options) {
        const init = { cache: 'no-store', credentials: 'same-origin', headers: {} };
        let url = endpoint + '?action=' + encodeURIComponent(action);
        if (options && options.query) {
            url += '&' + new URLSearchParams(options.query).toString();
        }
        if (options && options.raw) {
            init.method = 'POST';
            init.headers['Content-Type'] = 'application/octet-stream';
            init.body = options.raw;
        } else if (options && options.body) {
            init.method = 'POST';
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(Object.assign({ csrf_token: csrfToken }, options.body));
        }
        const response = await fetch(url, init);
        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }
        if (!response.ok || !payload || !payload.ok) {
            throw new Error((payload && payload.error) || ('Request failed (HTTP ' + response.status + ').'));
        }
        return payload;
    }

    function button(label, className, onClick) {
        const node = document.createElement('button');
        node.type = 'button';
        node.className = 'btn btn-sm ' + className;
        node.textContent = label;
        node.disabled = state.busy;
        node.addEventListener('click', onClick);
        return node;
    }

    function tag(text, kind, title) {
        const node = document.createElement('span');
        node.className = 'plugin-tag' + (kind ? ' is-' + kind : '');
        node.textContent = text;
        if (title) node.title = title;
        return node;
    }

    function catalogFor(name) {
        return state.catalog.find(function (entry) { return canonical(entry.name) === canonical(name); }) || null;
    }

    function channelLabel(entry, channelId) {
        const channel = entry && entry.channels.find(function (item) { return item.id === channelId; });
        return channel ? channel.label : channelId;
    }

    function originTags(item) {
        const tags = [];
        if (item.kind === 'built_in') {
            tags.push(tag('Built-in', '', 'Ships with ' + PRODUCT + ' and cannot be removed here.'));
            return tags;
        }
        if (item.kind === 'unmanaged') {
            tags.push(tag('Unmanaged folder', 'warn', 'Not installed through the package ledger, so this page leaves it untouched.'));
            return tags;
        }
        if (item.kind === 'retained') {
            tags.push(tag('Removed', 'warn'));
            tags.push(tag('Data retained', '', 'Its files are kept in package storage and its declared settings return if the same plugin is installed again.'));
            return tags;
        }
        const origin = item.origin || { type: 'game' };
        if (origin.type === 'catalog') {
            const entry = state.catalog.find(function (candidate) { return candidate.id === origin.catalog_id; });
            tags.push(tag('Catalog · ' + channelLabel(entry, origin.channel), 'accent'));
        } else if (origin.type === 'upload') {
            tags.push(tag('Uploaded'));
        } else {
            tags.push(tag('Game bundle', '', 'Installed by the game during load.'));
        }
        if (item.state === 'missing_files') {
            tags.push(tag('Files missing', 'warn', 'The ledger lists this package, but its ext/ folder is gone.'));
        } else {
            tags.push(tag('Installed', 'ok', 'Files and migrations are active. This does not confirm the plugin has run in game.'));
        }
        return tags;
    }

    function rowShell(nameText, descriptionText, tags) {
        const row = document.createElement('div');
        row.className = 'plugin-row';
        const main = document.createElement('div');
        main.className = 'plugin-main';
        const name = document.createElement('div');
        name.className = 'plugin-name';
        name.textContent = nameText;
        main.appendChild(name);
        if (descriptionText) {
            const description = document.createElement('div');
            description.className = 'plugin-desc';
            description.textContent = descriptionText;
            main.appendChild(description);
        }
        if (tags && tags.length) {
            const meta = document.createElement('div');
            meta.className = 'plugin-meta';
            tags.forEach(function (node) { meta.appendChild(node); });
            main.appendChild(meta);
        }
        row.appendChild(main);
        return row;
    }

    function versionCell(primary, secondary) {
        const cell = document.createElement('div');
        cell.className = 'plugin-version';
        cell.textContent = primary;
        if (secondary) {
            const small = document.createElement('small');
            small.textContent = secondary;
            cell.appendChild(small);
        }
        return cell;
    }

    function releaseFor(entryId, channelId) {
        return state.releases[entryId + '/' + channelId] || null;
    }

    function renderInstalled() {
        installedList.setAttribute('aria-busy', 'false');
        installedList.replaceChildren();
        if (!state.items.length) {
            const empty = document.createElement('div');
            empty.className = 'plugin-empty';
            empty.textContent = 'No server plugins are installed. Game-bundled packages appear here after the game loads.';
            installedList.appendChild(empty);
            return;
        }
        state.items.forEach(function (item) {
            const row = rowShell(item.name, item.description, originTags(item));
            let secondary = '';
            if (item.kind === 'retained' && item.removed_at) {
                secondary = 'Removed ' + new Date(item.removed_at).toLocaleDateString();
            } else if (item.installed_at) {
                secondary = new Date(item.installed_at).toLocaleDateString();
            }
            row.appendChild(versionCell(item.version ? 'v' + item.version : '—', secondary));

            const actions = document.createElement('div');
            actions.className = 'plugin-actions';
            if (item.kind === 'package') {
                const entry = catalogFor(item.name);
                const origin = item.origin || { type: 'game' };
                if (entry) {
                    entry.channels.forEach(function (channel) {
                        const release = releaseFor(entry.id, channel.id);
                        const sameChannel = origin.type === 'catalog' && origin.channel === channel.id;
                        if (sameChannel) {
                            if (release && release.version && release.version !== item.version) {
                                actions.appendChild(button('Update to v' + release.version, 'btn-save', function () { installFromCatalog(entry, channel.id); }));
                            } else if (release && release.version) {
                                actions.appendChild(tag('Up to date', 'ok'));
                            }
                        } else {
                            const label = origin.type === 'catalog' ? 'Switch to ' + channel.label : 'Install ' + channel.label;
                            actions.appendChild(button(release && release.version ? label + ' v' + release.version : label, 'btn-secondary', function () { installFromCatalog(entry, channel.id); }));
                        }
                    });
                }
                if (item.removable) {
                    const label = item.state === 'missing_files' ? 'Forget' : 'Remove';
                    actions.appendChild(button(label, 'btn-danger', function (event) { confirmRemove(item, event.currentTarget); }));
                }
            }
            row.appendChild(actions);
            installedList.appendChild(row);
        });
    }

    function renderAvailable() {
        availableList.replaceChildren();
        const installedNames = new Set(state.items.filter(function (item) { return item.kind !== 'retained'; }).map(function (item) { return canonical(item.name); }));
        const available = state.catalog.filter(function (entry) { return !installedNames.has(canonical(entry.name)); });
        if (!available.length) {
            const empty = document.createElement('div');
            empty.className = 'plugin-empty';
            empty.textContent = state.catalog.length
                ? 'Every catalog plugin is already installed.'
                : 'No compatible server plugins are listed in the ' + PRODUCT + ' catalog yet. You can still upload a .dwpkg or .zip package.';
            availableList.appendChild(empty);
            return;
        }
        available.forEach(function (entry) {
            const row = rowShell(entry.name, entry.description, []);
            const release = releaseFor(entry.id, entry.default_channel);
            const versionNode = versionCell(release && release.version ? 'v' + release.version : 'Not checked', release && release.error ? 'Check failed' : '');
            if (!release || !release.version) versionNode.classList.add('is-muted');
            if (release && release.error) versionNode.title = release.error;
            row.appendChild(versionNode);
            const actions = document.createElement('div');
            actions.className = 'plugin-actions';
            if (entry.homepage) {
                const link = document.createElement('a');
                link.href = entry.homepage;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = 'Details';
                actions.appendChild(link);
            }
            let select = null;
            if (entry.channels.length > 1) {
                select = document.createElement('select');
                select.setAttribute('aria-label', 'Channel for ' + entry.name);
                entry.channels.forEach(function (channel) {
                    const option = document.createElement('option');
                    option.value = channel.id;
                    option.textContent = channel.label;
                    option.selected = channel.id === entry.default_channel;
                    select.appendChild(option);
                });
                select.disabled = state.busy;
                actions.appendChild(select);
            }
            actions.appendChild(button('Install', 'btn-save', function () {
                installFromCatalog(entry, select ? select.value : entry.default_channel);
            }));
            row.appendChild(actions);
            availableList.appendChild(row);
        });
    }

    function render() {
        renderInstalled();
        renderAvailable();
    }

    async function loadInventory() {
        try {
            const payload = await api('inventory');
            state.items = Array.isArray(payload.items) ? payload.items : [];
            state.catalog = Array.isArray(payload.catalog) ? payload.catalog : [];
            render();
            if (payload.catalog_skipped > 0) {
                setMessage(payload.catalog_skipped === 1 ? '1 catalog entry was skipped because it is incomplete or not HTTPS.' : payload.catalog_skipped + ' catalog entries were skipped because they are incomplete or not HTTPS.', 'error');
            }
        } catch (error) {
            installedList.setAttribute('aria-busy', 'false');
            setMessage(error.message, 'error');
        }
    }

    async function runExclusive(work) {
        if (state.busy) return;
        setBusy(true);
        try {
            await work();
        } catch (error) {
            hideProgress();
            setMessage(error.message, 'error');
        } finally {
            setBusy(false);
            await loadInventory();
        }
    }

    function reportJob(job, verb) {
        hideProgress();
        if (job && job.status === 'completed') {
            setMessage(job.name + ' v' + job.version + ' ' + verb + '.', 'success');
        } else {
            setMessage((job && job.error) || 'The package install did not complete.', 'error');
        }
    }

    function describePhase(job) {
        switch (job.status) {
            case 'queued': return 'Waiting to start';
            case 'resolving': return 'Reading catalog release';
            case 'downloading': return 'Downloading';
            case 'validating': return 'Verifying checksums';
            case 'activating_server': return 'Activating and running migrations';
            default: return 'Working';
        }
    }

    function sleep(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    async function pollJob(jobId, finished) {
        let delay = 800;
        let failures = 0;
        for (let attempt = 0; attempt < POLL_LIMIT && !finished.done; attempt++) {
            await sleep(delay);
            if (finished.done) break;
            try {
                const payload = await api('status', { query: { job_id: jobId } });
                failures = 0;
                const job = payload.job;
                if (job.status === 'completed' || job.status === 'failed') {
                    return job;
                }
                const percent = job.bytes_total > 0 ? (job.bytes_received / job.bytes_total) * 100 : undefined;
                const detail = job.bytes_received > 0 ? formatBytes(job.bytes_received) : '';
                showProgress(describePhase(job) + (job.version ? ' v' + job.version : ''), percent, detail);
            } catch (error) {
                failures++;
                if (failures >= 5) {
                    throw new Error('Lost contact with the server while installing. Refresh to see the final state.');
                }
            }
            delay = Math.min(5000, Math.round(delay * 1.5));
        }
        return null;
    }

    function installFromCatalog(entry, channelId) {
        runExclusive(async function () {
            setMessage('');
            showProgress('Preparing ' + entry.name + ' (' + channelLabel(entry, channelId) + ')');
            const created = await api('catalog-install', { body: { id: entry.id, channel: channelId } });
            const jobId = created.job.id;
            const finished = { done: false };
            const runner = api('run-job', { body: { job_id: jobId } }).then(function (payload) {
                finished.done = true;
                return payload.job;
            }, function (error) {
                return { runnerError: error };
            });
            let pollError = null;
            const polled = pollJob(jobId, finished).catch(function (error) { pollError = error; return null; });
            let job = await runner;
            if (job && job.runnerError) {
                const runnerError = job.runnerError;
                if (!(runnerError instanceof TypeError)) {
                    // The server answered with an error, so the job will not progress further.
                    finished.done = true;
                    throw runnerError;
                }
                // The connection dropped while the server may still be working; keep polling.
                job = await polled;
                if (!job) throw pollError || new Error('The install is taking longer than expected. Refresh to see the final state.');
            }
            reportJob(job, 'installed from the ' + channelLabel(entry, channelId) + ' channel');
        });
    }

    function uploadPackage(file) {
        runExclusive(async function () {
            setMessage('');
            const extension = (file.name.split('.').pop() || '').toLowerCase();
            if (extension !== 'dwpkg' && extension !== 'zip') {
                throw new Error('Choose a .dwpkg or .zip server plugin package.');
            }
            if (file.size < 1 || file.size > MAX_ARCHIVE_BYTES) {
                throw new Error('Packages must be between 1 byte and 512 MB.');
            }
            const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_BYTES));
            showProgress('Uploading ' + file.name, 0, '0 of ' + formatBytes(file.size));
            const started = await api('browser-start-upload', { body: { archive_name: file.name, size: file.size, total_chunks: totalChunks } });
            const uploadId = started.upload.upload_id;
            let result = null;
            for (let index = 0; index < totalChunks; index++) {
                const chunk = file.slice(index * CHUNK_BYTES, Math.min(file.size, (index + 1) * CHUNK_BYTES));
                if (index === totalChunks - 1) {
                    showProgress('Verifying and activating ' + file.name);
                }
                result = await api('upload-chunk', { query: { upload_id: uploadId, index: String(index) }, raw: chunk });
                if (index < totalChunks - 1) {
                    const sent = Math.min(file.size, (index + 1) * CHUNK_BYTES);
                    showProgress('Uploading ' + file.name, (sent / file.size) * 100, formatBytes(sent) + ' of ' + formatBytes(file.size));
                }
            }
            reportJob(result && result.upload ? result.upload.job : null, 'installed from upload');
        });
    }

    function confirmRemove(item, opener) {
        const origin = item.origin || { type: 'game' };
        el('plugin-remove-title').textContent = (item.state === 'missing_files' ? 'Forget ' : 'Remove ') + item.name + '?';
        el('plugin-remove-body').textContent = item.state === 'missing_files'
            ? 'Its ext/ folder is already gone. This clears the package ledger entry.'
            : 'Its folder leaves ext/ and moves into package storage. Nothing is deleted.';
        el('plugin-remove-data').textContent = item.state === 'missing_files'
            ? ''
            : 'Database tables and the plugin’s declared settings and data are kept. Installing the same plugin again restores them.';
        el('plugin-remove-game').textContent = origin.type === 'game'
            ? 'This package came from the game. If its addon is still enabled in your mod manager, ' + PRODUCT + ' reinstalls it the next time the game loads. Disable the addon first to keep it removed.'
            : '';
        el('plugin-remove-confirm').textContent = item.state === 'missing_files' ? 'Forget' : 'Remove';
        dialog.returnValue = '';
        dialog.onclose = function () {
            if (opener && document.body.contains(opener)) opener.focus();
            if (dialog.returnValue !== 'confirm') return;
            runExclusive(async function () {
                setMessage('');
                showProgress('Removing ' + item.name);
                await api('remove', { body: { name: item.name, confirm: item.name } });
                hideProgress();
                const reinstall = origin.type === 'game' ? ' If the game addon is still enabled, it returns on the next game load.' : '';
                setMessage(item.name + ' was ' + (item.state === 'missing_files' ? 'forgotten.' : 'removed. Its data is retained.') + reinstall, 'success');
            });
        };
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else if (window.confirm(el('plugin-remove-title').textContent + ' ' + el('plugin-remove-body').textContent)) {
            dialog.returnValue = 'confirm';
            dialog.onclose();
        }
    }

    function checkUpdates() {
        runExclusive(async function () {
            setMessage('');
            showProgress('Checking catalog releases');
            const payload = await api('check-updates', { body: {} });
            state.releases = {};
            let failures = 0;
            (payload.releases || []).forEach(function (release) {
                state.releases[release.id + '/' + release.channel] = release;
                if (release.error) failures++;
            });
            hideProgress();
            checkedLabel.textContent = 'Checked ' + new Date(payload.checked_at).toLocaleTimeString();
            if (!(payload.releases || []).length) {
                setMessage('The catalog has no plugins to check.');
            } else if (failures) {
                setMessage(failures + ' release check' + (failures === 1 ? '' : 's') + ' failed. Other results are shown.', 'error');
            } else {
                setMessage('Catalog releases checked.', 'success');
            }
        });
    }

    uploadButton.addEventListener('click', function () {
        fileInput.value = '';
        fileInput.click();
    });
    fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) uploadPackage(fileInput.files[0]);
    });
    checkButton.addEventListener('click', checkUpdates);
    refreshButton.addEventListener('click', function () {
        if (state.busy) return;
        setMessage('');
        loadInventory();
    });

    loadInventory();
})();
</script>

<?php
include __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html';
$buffer = ob_get_contents();
ob_end_clean();
echo $buffer;
?>
