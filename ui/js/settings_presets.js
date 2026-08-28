(() => {
    const root = document.querySelector('[data-preset-config]');
    if (!root) return;
    const config = JSON.parse(root.dataset.presetConfig);
    const form = document.getElementById(config.formId);
    const select = root.querySelector('#stobePresetSelect');
    const status = root.querySelector('[role=status]');
    const name = root.querySelector('#stobePresetName');
    const file = root.querySelector('#stobePresetFile');
    const saveLabel = config.scope === 'profile' ? 'Save Profile' : 'Save All';
    let presets = [], catalog = {}, busy = false, pending = false;

    function tell(message, error = false) {
        status.textContent = message;
        status.dataset.error = String(error);
    }
    function controls() {
        select.disabled = busy || !presets.length;
        root.querySelectorAll('[data-preset-action]').forEach(button => {
            const customOnly = ['overwrite', 'delete'].includes(button.dataset.presetAction);
            button.disabled = busy || !presets.length || (customOnly && presets[Number(select.value)]?.builtin);
        });
    }
    async function request(payload) {
        const options = payload ? {method: 'POST', headers: {'Content-Type': 'application/json', 'X-Stobe-Preset-Token': config.token}, body: JSON.stringify(payload)} : {};
        const response = await fetch(config.endpoint, options);
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Preset request failed.');
        presets = data.presets;
        catalog = data.catalog;
        const previous = payload?.name || presets[Number(select.value)]?.name;
        select.replaceChildren(...presets.map((preset, index) => {
            const option = document.createElement('option');
            option.value = String(index);
            option.textContent = preset.name + (preset.builtin ? ' (built-in)' : '');
            option.selected = preset.name === previous;
            return option;
        }));
    }
    function field(key) {
        const prefix = config.scope === 'profile' ? 'meta_vis' : 'settings';
        return Array.from(form.elements).find(el => el.name === `${prefix}[${key}]` && el.type !== 'hidden');
    }
    function setFields(key) {
        return Array.from(form.elements).filter(el => el.name === `meta_vis[${key}][]` && el.type === 'checkbox');
    }
    function profileMetadata() {
        const data = JSON.parse(document.getElementById('metadata').value || '{}');
        if (!data || Array.isArray(data) || typeof data !== 'object') throw new Error('Metadata must be a JSON object.');
        return data;
    }
    function currentSettings() {
        const settings = {};
        if (config.scope === 'profile') profileMetadata();
        for (const key of Object.keys(catalog)) {
            if (catalog[key].type === 'set') {
                settings[key] = setFields(key).filter(input => input.checked).map(input => input.value);
                continue;
            }
            const input = field(key);
            if (input) settings[key] = input.type === 'checkbox' ? input.checked : input.value;
        }
        return settings;
    }
    function apply(settings) {
        const changed = [];
        const metadata = config.scope === 'profile' ? profileMetadata() : null;
        const before = currentSettings();
        // Check the complete form before changing any field; a stale page must not apply partially.
        for (const key of Object.keys(settings)) {
            if (!Object.hasOwn(catalog, key) || (catalog[key].type === 'set' ? !setFields(key).length : !field(key))) {
                throw new Error('A preset setting is unavailable. Reload the page.');
            }
        }
        for (const [key, value] of Object.entries(settings)) {
            if (catalog[key].type === 'set') {
                setFields(key).forEach(input => { input.checked = value.includes(input.value); });
                if (JSON.stringify(before[key]) !== JSON.stringify(value)) changed.push(key.replaceAll('_', ' ').toLowerCase());
                continue;
            }
            const input = field(key);
            if (!input) throw new Error('A preset setting is unavailable. Reload the page.');
            const previous = input.type === 'checkbox' ? input.checked : input.value;
            if (input.type === 'checkbox') input.checked = value;
            else input.value = String(value);
            if (String(previous) !== String(value)) {
                changed.push(key.replaceAll('_', ' ').toLowerCase());
                input.closest('.setting-row, .toggle-card, .provider-card')?.classList.add('stobe-preset-changed');
            }
            input.dispatchEvent(new Event('input', {bubbles: true}));
            input.dispatchEvent(new Event('change', {bubbles: true}));
        }
        if (metadata) {
            Object.assign(metadata, settings);
            // Merge only portable keys, preserving custom metadata, prompts and future settings.
            document.getElementById('metadata').value = JSON.stringify(metadata, null, 2);
        }
        pending = pending || changed.length > 0;
        tell(`${changed.length} setting(s) changed${changed.length ? ': ' + changed.join(', ') : ''}. Review them, then ${saveLabel}.`);
    }
    function envelope(preset) {
        return {format: 'stobe-settings-preset', version: 1, scope: config.scope, name: preset.name, settings: preset.settings};
    }
    select.addEventListener('change', () => {
        controls();
        tell(presets[Number(select.value)]?.description || '');
    });
    root.addEventListener('click', async event => {
        const button = event.target.closest('[data-preset-action]');
        if (!button || busy || button.disabled) return;
        const action = button.dataset.presetAction;
        const selected = presets[Number(select.value)];
        busy = true;
        controls();
        try {
            if (action === 'apply') {
                apply(selected.settings);
            } else if (action === 'save' || action === 'overwrite') {
                const presetName = action === 'overwrite' ? selected.name : name.value.trim();
                if (!presetName) throw new Error('Enter a name for the new preset.');
                if (action === 'overwrite' && !confirm(`Overwrite "${presetName}" with this form's behavior settings?`)) return;
                await request({...envelope({name: presetName, settings: currentSettings()}), action: 'save', overwrite: action === 'overwrite'});
                tell('Preset saved. Runtime settings have not changed.');
            } else if (action === 'delete') {
                if (!confirm(`Delete preset "${selected.name}"? Current settings will not change.`)) return;
                await request({action: 'delete', name: selected.name});
                tell('Preset deleted. Current settings have not changed.');
            } else if (action === 'export') {
                const link = document.createElement('a');
                const url = URL.createObjectURL(new Blob([JSON.stringify(envelope(selected), null, 2)], {type: 'application/json'}));
                link.href = url;
                link.download = `stobe-${config.scope}-preset.json`;
                link.click();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
                tell('Exported the selected preset.');
            } else if (action === 'import') file.click();
        } catch (error) {
            tell(error.message || 'Preset action failed.', true);
        } finally {
            busy = false;
            controls();
        }
    });
    file.addEventListener('change', async () => {
        if (!file.files[0] || busy) return;
        busy = true;
        controls();
        try {
            if (file.files[0].size > 16384) throw new Error('Preset files must be smaller than 16 KB.');
            const imported = JSON.parse(await file.files[0].text());
            if (!imported || imported.format !== 'stobe-settings-preset' || imported.version !== 1 || imported.scope !== config.scope) throw new Error('Choose a Stobe preset for this settings page.');
            await request({...imported, action: 'save', overwrite: false});
            tell('Preset imported. Select Apply to review its settings.');
        } catch (error) {
            tell(error.message || 'Could not import preset.', true);
        } finally {
            file.value = '';
            busy = false;
            controls();
        }
    });
    form.addEventListener('submit', event => { if (!event.defaultPrevented && form.checkValidity()) pending = false; });
    window.addEventListener('beforeunload', event => { if (pending) { event.preventDefault(); event.returnValue = ''; } });
    request().catch(error => tell(error.message || 'Could not load presets.', true)).finally(controls);
})();
