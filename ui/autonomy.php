<?php

error_reporting(E_ALL);

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once $path . 'lib/bootstrap.php';

try {
    require_once $path . 'debug/db_updates.php';
    $initialSession = stobeAutonomyGetSession();
    $eligibleNpcs = stobeAutonomyListEligibleNpcs();
    $initialEvents = stobeAutonomyListEvents(50);
} catch (Throwable $exception) {
    stobeLogException($exception, 'Autonomy UI initialization failed');
    $initialSession = [];
    $eligibleNpcs = [];
    $initialEvents = [];
}

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$webRoot = rtrim(dirname(dirname($scriptPath)), '/');
if ($webRoot === '/') {
    $webRoot = '';
}
$requestedNpcId = max(0, intval($_GET['npc_id'] ?? 0));

function autonomyH(mixed $value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autonomy | Stobe</title>
    <link rel="stylesheet" href="<?= autonomyH($webRoot) ?>/ui/lib/ui/bootstrap/bootstrap.min.css">
    <style>
        :root {
            --ink: #171b19;
            --panel: #202825;
            --panel-2: #29332f;
            --line: #485750;
            --paper: #e8e0cd;
            --muted: #a8b1aa;
            --signal: #68c2ad;
            --brass: #d8a85d;
            --danger: #d95d4f;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: var(--paper);
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            background:
                linear-gradient(135deg, rgba(104,194,173,.07) 0 25%, transparent 25% 50%, rgba(216,168,93,.04) 50% 75%, transparent 75%) 0 0 / 52px 52px,
                radial-gradient(circle at 15% 10%, #34443d 0, #171b19 42%, #101311 100%);
            min-height: 100vh;
        }
        main { width: min(1320px, calc(100% - 32px)); margin: 32px auto 64px; }
        .masthead { display:flex; justify-content:space-between; gap:24px; align-items:end; border-bottom:1px solid var(--line); padding:18px 0 22px; }
        .eyebrow { color:var(--signal); letter-spacing:.2em; text-transform:uppercase; font-size:12px; font-weight:800; }
        h1 { margin:7px 0 3px; font-family:Georgia, serif; font-size:clamp(38px, 6vw, 72px); line-height:.9; font-weight:500; }
        .phase-note { max-width:520px; color:var(--muted); line-height:1.55; text-align:right; }
        .layout { display:grid; grid-template-columns:minmax(340px, .9fr) minmax(460px, 1.4fr); gap:18px; margin-top:18px; }
        .panel { background:linear-gradient(145deg, rgba(41,51,47,.96), rgba(28,35,32,.96)); border:1px solid var(--line); box-shadow:0 20px 60px rgba(0,0,0,.28); padding:22px; }
        .panel h2 { margin:0 0 18px; color:var(--brass); font-family:Georgia, serif; font-weight:500; }
        label { display:block; color:var(--muted); font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; margin:16px 0 7px; }
        select, textarea { width:100%; color:var(--paper); background:#151a18; border:1px solid var(--line); border-radius:3px; padding:12px; font:inherit; }
        textarea { min-height:112px; resize:vertical; line-height:1.45; }
        .policy { display:flex; justify-content:space-between; gap:12px; margin-top:16px; padding:12px; background:rgba(104,194,173,.08); border-left:3px solid var(--signal); }
        .policy strong { color:var(--signal); }
        .controls { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:9px; margin-top:20px; }
        button { border:1px solid var(--line); background:#303c37; color:var(--paper); padding:11px 12px; font-weight:800; cursor:pointer; }
        button:hover:not(:disabled) { border-color:var(--signal); color:#fff; }
        button:disabled { cursor:not-allowed; opacity:.42; }
        .primary { background:var(--signal); border-color:var(--signal); color:var(--ink); }
        .danger { background:var(--danger); border-color:var(--danger); color:#fff; }
        .emergency { grid-column:1 / -1; background:#6d2822; border-color:var(--danger); }
        .message { min-height:22px; margin-top:14px; color:var(--muted); font-size:13px; }
        .message.error { color:#ff9187; }
        .status-grid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px; }
        .status-cell { min-height:84px; background:rgba(12,16,14,.42); border:1px solid #394640; padding:12px; }
        .status-cell span { color:var(--muted); display:block; text-transform:uppercase; letter-spacing:.09em; font-size:10px; }
        .status-cell strong { display:block; margin-top:8px; color:var(--paper); overflow-wrap:anywhere; }
        .state { color:var(--signal) !important; }
        .online { color:var(--signal) !important; }
        .offline { color:#ff9187 !important; }
        .detail { margin-top:14px; border-top:1px solid var(--line); padding-top:14px; }
        .detail dt { color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.08em; margin-top:10px; }
        .detail dd { margin:4px 0 0; white-space:pre-wrap; overflow-wrap:anywhere; }
        .timeline { grid-column:1 / -1; }
        .events { display:grid; gap:8px; }
        .event { display:grid; grid-template-columns:170px 130px 1fr; gap:12px; align-items:start; padding:11px 0; border-top:1px solid #37423d; }
        .event:first-child { border-top:0; }
        .event-type { color:var(--brass); font-weight:800; }
        .event-state { color:var(--signal); font-size:12px; }
        .event-reason { color:var(--muted); overflow-wrap:anywhere; }
        .empty { color:var(--muted); font-style:italic; }
        @media (max-width: 860px) {
            main { width:min(100% - 20px, 680px); margin-top:16px; }
            .masthead { align-items:start; flex-direction:column; }
            .phase-note { text-align:left; }
            .layout { grid-template-columns:1fr; }
            .timeline { grid-column:auto; }
            .status-grid { grid-template-columns:repeat(2, minmax(0,1fr)); }
            .event { grid-template-columns:1fr; gap:4px; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/tmpl/navbar.php'; ?>
<main>
    <header class="masthead">
        <div><div class="eyebrow">Phase 1 Control Plane</div><h1>Autonomy</h1></div>
        <div class="phase-note">Select one player-faction NPC and control the plugin state. Phase 1 observes and validates only: it does not call an LLM or issue game actions.</div>
    </header>
    <div class="layout">
        <section class="panel">
            <h2>Operator Controls</h2>
            <label for="npc-select">Player-faction NPC</label>
            <select id="npc-select">
                <option value="0">Select a detected NPC</option>
                <?php foreach ($eligibleNpcs as $npc): ?>
                    <?php $selected = $requestedNpcId > 0 ? $requestedNpcId === intval($npc['id']) : intval($initialSession['npc_id'] ?? 0) === intval($npc['id']); ?>
                    <option value="<?= intval($npc['id']) ?>"<?= $selected ? ' selected' : '' ?>><?= autonomyH($npc['name']) ?> | <?= autonomyH($npc['storage_id']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="directive">Long-term directive</label>
            <textarea id="directive" placeholder="Optional direction for later autonomy phases."><?= autonomyH($initialSession['long_term_directive'] ?? '') ?></textarea>
            <div class="policy"><span>Policy preset</span><strong>Full Autonomy</strong></div>
            <div class="controls">
                <button id="save" type="button">Save Selection</button>
                <button id="start" class="primary" type="button">Start</button>
                <button id="pause" type="button">Pause</button>
                <button id="resume" type="button">Resume</button>
                <button id="stop" class="danger" type="button">Stop</button>
                <button id="emergency" class="emergency" type="button">Emergency Stop</button>
            </div>
            <div id="message" class="message" role="status"></div>
        </section>
        <section class="panel">
            <h2>Runtime State</h2>
            <div class="status-grid">
                <div class="status-cell"><span>Requested</span><strong id="desired" class="state">DISABLED</strong></div>
                <div class="status-cell"><span>Plugin</span><strong id="plugin" class="state">DISABLED</strong></div>
                <div class="status-cell"><span>Plugin link</span><strong id="online">Offline</strong></div>
                <div class="status-cell"><span>Control revision</span><strong id="revision">0</strong></div>
                <div class="status-cell"><span>Plugin revision</span><strong id="plugin-revision">0</strong></div>
                <div class="status-cell"><span>Runtime serial</span><strong id="runtime-serial">None</strong></div>
            </div>
            <dl class="detail">
                <dt>Selected NPC</dt><dd id="selected-npc">None</dd>
                <dt>Last observation</dt><dd id="observation">None</dd>
                <dt>Last error</dt><dd id="last-error">None</dd>
            </dl>
        </section>
        <section class="panel timeline">
            <h2>Control Timeline</h2>
            <div id="events" class="events"><div class="empty">No autonomy events recorded.</div></div>
        </section>
    </div>
</main>
<script src="<?= autonomyH($webRoot) ?>/ui/lib/ui/bootstrap/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const initial = <?= json_encode(['session' => $initialSession, 'events' => $initialEvents], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const stateUrl = <?= json_encode($webRoot . '/autonomy_state.php?include_events=1') ?>;
    const controlUrl = <?= json_encode($webRoot . '/autonomy_control.php') ?>;
    let session = initial.session || {};
    let busy = false;
    const el = id => document.getElementById(id);
    const text = value => String(value == null || value === '' ? 'None' : value);

    function renderEvents(events) {
        const root = el('events');
        root.textContent = '';
        if (!Array.isArray(events) || events.length === 0) {
            const empty = document.createElement('div'); empty.className = 'empty'; empty.textContent = 'No autonomy events recorded.'; root.appendChild(empty); return;
        }
        events.forEach(item => {
            const row = document.createElement('div'); row.className = 'event';
            const type = document.createElement('div'); type.className = 'event-type'; type.textContent = text(item.event_type);
            const state = document.createElement('div'); state.className = 'event-state'; state.textContent = text(item.state);
            const reason = document.createElement('div'); reason.className = 'event-reason'; reason.textContent = item.reason || item.outcome || item.created_at || '';
            row.append(type, state, reason); root.appendChild(row);
        });
    }

    function render(data) {
        session = data.session || session;
        el('desired').textContent = text(session.desired_state);
        el('plugin').textContent = text(session.plugin_state);
        el('revision').textContent = text(session.control_revision);
        el('plugin-revision').textContent = text(session.plugin_control_revision);
        el('runtime-serial').textContent = session.runtime_serial ? text(session.runtime_serial) : 'None';
        el('selected-npc').textContent = session.npc_name ? `${session.npc_name} (${session.npc_storage_id || 'no storage ID'})` : 'None';
        el('observation').textContent = text(session.last_observation);
        el('last-error').textContent = text(session.last_error);
        const online = !!session.plugin_online;
        el('online').textContent = online ? 'Online' : 'Offline';
        el('online').className = online ? 'online' : 'offline';
        if (!busy && session.npc_id && !el('npc-select').value) el('npc-select').value = String(session.npc_id);
        if (!busy && document.activeElement !== el('directive')) el('directive').value = session.long_term_directive || '';
        renderEvents(data.events || []);
        const selected = Number(session.npc_id || 0) > 0;
        el('start').disabled = busy || !selected || !!session.enabled;
        el('pause').disabled = busy || !session.enabled || session.desired_state === 'PAUSED_USER';
        const resumable = ['PAUSED_USER', 'PAUSED_UNSAFE', 'ERROR'].includes(session.desired_state) ||
            ['PAUSED_USER', 'PAUSED_UNSAFE', 'ERROR'].includes(session.plugin_state);
        el('resume').disabled = busy || !session.enabled || !resumable;
        el('stop').disabled = busy || !session.enabled;
        el('emergency').disabled = busy || !session.enabled;
        el('save').disabled = busy;
    }

    async function refresh() {
        try {
            const response = await fetch(stateUrl, {cache: 'no-store'});
            const data = await response.json();
            if (response.ok && data.ok) render(data);
        } catch (_) {}
    }

    async function control(action) {
        busy = true; render({session, events: []});
        const message = el('message'); message.className = 'message'; message.textContent = 'Applying control change...';
        const body = {action, control_revision: Number(session.control_revision || 0)};
        if (action === 'select') {
            body.npc_id = Number(el('npc-select').value || 0);
            body.long_term_directive = el('directive').value;
        }
        try {
            const response = await fetch(controlUrl, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
            session = data.session; message.textContent = 'Control change saved.';
        } catch (error) {
            message.className = 'message error'; message.textContent = `Unable to apply control: ${error.message}`;
        } finally {
            busy = false; await refresh();
        }
    }

    el('save').addEventListener('click', () => control('select'));
    ['start','pause','resume','stop','emergency'].forEach(id => el(id).addEventListener('click', () => control(id === 'emergency' ? 'emergency_stop' : id)));
    render(initial);
    setInterval(refresh, 1500);
})();
</script>
</body>
</html>
