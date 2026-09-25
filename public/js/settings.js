let pendingChanges = {};

// On Page Load: Initialize all duration widgets from their raw seconds values
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[data-type="duration"]').forEach(input => {
        const fieldKey = input.id.replace('cfg_', '');
        initDurationWidget(fieldKey, parseInt(input.value) || 0);
    });
});

function initDurationWidget(fieldKey, totalSec) {
    const valInput = document.getElementById('dur_val_' + fieldKey);
    const unitSelect = document.getElementById('dur_unit_' + fieldKey);
    const hint = document.getElementById('dur_hint_' + fieldKey);
    if (!valInput || !unitSelect) return;

    if (totalSec >= 86400 && totalSec % 86400 === 0) {
        valInput.value = totalSec / 86400;
        unitSelect.value = "86400";
    } else if (totalSec >= 3600 && totalSec % 3600 === 0) {
        valInput.value = totalSec / 3600;
        unitSelect.value = "3600";
    } else if (totalSec >= 60 && totalSec % 60 === 0) {
        valInput.value = totalSec / 60;
        unitSelect.value = "60";
    } else {
        valInput.value = totalSec;
        unitSelect.value = "1";
    }
    if (hint) hint.textContent = `${totalSec.toLocaleString()}s`;
}

function onDurationFieldChange(fieldKey) {
    const valInput = document.getElementById('dur_val_' + fieldKey);
    const unitSelect = document.getElementById('dur_unit_' + fieldKey);
    const rawInput = document.getElementById('cfg_' + fieldKey);
    const hint = document.getElementById('dur_hint_' + fieldKey);
    if (!valInput || !unitSelect || !rawInput) return;

    const qty = Math.max(1, parseFloat(valInput.value) || 1);
    const multiplier = parseInt(unitSelect.value) || 1;
    const totalSeconds = Math.round(qty * multiplier);

    rawInput.value = totalSeconds;
    if (hint) hint.textContent = `${totalSeconds.toLocaleString()}s`;
    onFieldChange(rawInput);
}

function setDurationPreset(fieldKey, seconds) {
    const rawInput = document.getElementById('cfg_' + fieldKey);
    if (!rawInput) return;
    rawInput.value = seconds;
    initDurationWidget(fieldKey, seconds);
    onFieldChange(rawInput);
}

function syncSliderToInput(inputId, val) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.value = val;
    onFieldChange(input);
}

function syncInputToSlider(sliderId, input) {
    const slider = document.getElementById(sliderId);
    if (slider) slider.value = input.value;
    onFieldChange(input);
}

function syncPercentSlider(rawInputId, pctVal) {
    const rawInput = document.getElementById(rawInputId);
    const displayInput = document.getElementById('display_' + rawInputId.replace('cfg_', ''));
    const hintDec = document.getElementById('hint_dec_' + rawInputId.replace('cfg_', ''));

    const decimalVal = parseFloat((pctVal / 100).toFixed(4));
    if (displayInput) displayInput.value = pctVal;
    if (hintDec) hintDec.textContent = decimalVal;
    if (rawInput) {
        rawInput.value = decimalVal;
        onFieldChange(rawInput);
    }
}

function syncPercentInput(sliderId, rawInputId, displayInput) {
    const slider = document.getElementById(sliderId);
    const rawInput = document.getElementById(rawInputId);
    const hintDec = document.getElementById('hint_dec_' + rawInputId.replace('cfg_', ''));

    const pctVal = parseFloat(displayInput.value) || 0;
    if (slider) slider.value = pctVal;
    const decimalVal = parseFloat((pctVal / 100).toFixed(4));
    if (hintDec) hintDec.textContent = decimalVal;
    if (rawInput) {
        rawInput.value = decimalVal;
        onFieldChange(rawInput);
    }
}

function switchSettingsTab(tabId, btn) {
    document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    document.querySelectorAll('.settings-tab-pane').forEach(pane => {
        pane.style.display = (pane.id === tabId) ? 'flex' : 'none';
    });
}

function onFieldChange(input) {
    const key = input.dataset.key;
    const originalRaw = input.dataset.original;
    const currentRaw  = input.value;

    const min = input.dataset.min ? parseFloat(input.dataset.min) : null;
    const max = input.dataset.max ? parseFloat(input.dataset.max) : null;
    const isNumeric = (input.type === 'number' || input.dataset.type === 'duration');
    
    let isInvalid = false;
    if (isNumeric) {
        const numVal = parseFloat(currentRaw);
        if (isNaN(numVal) || (min !== null && numVal < min) || (max !== null && numVal > max)) {
            isInvalid = true;
        }
    }

    if (isInvalid) {
        input.classList.add('invalid');
    } else {
        input.classList.remove('invalid');
    }

    const isDirty = isNumeric
        ? (parseFloat(currentRaw) !== parseFloat(originalRaw))
        : (currentRaw.trim() !== originalRaw.trim());

    if (isDirty && !isInvalid) {
        pendingChanges[key] = isNumeric ? parseFloat(currentRaw) : currentRaw;
        input.classList.add('modified');
        input.classList.remove('saved');
    } else {
        delete pendingChanges[key];
        input.classList.remove('modified');
    }

    const hasInvalidFields = document.querySelectorAll('.cfg-input.invalid').length > 0;
    const hasChanges = Object.keys(pendingChanges).length > 0;
    
    const saveBtn = document.getElementById('btnSave');
    if (saveBtn) saveBtn.disabled = !hasChanges || hasInvalidFields;
    
    const statusEl = document.getElementById('saveStatus');
    if (statusEl) {
        if (hasInvalidFields) {
            statusEl.innerHTML = '<span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;color:var(--red);margin-right:4px;">error</span> Please correct values out of allowed range.';
            statusEl.className = 'save-status error';
        } else if (hasChanges) {
            statusEl.textContent = `${Object.keys(pendingChanges).length} unsaved change(s).`;
            statusEl.className = 'save-status';
        } else {
            statusEl.textContent = 'No unsaved changes.';
            statusEl.className = 'save-status';
        }
    }
}

function toggleKeyVisibility(fieldId, btn) {
    const el = document.getElementById(fieldId);
    if (!el) return;
    if (el.type === 'password') {
        el.type = 'text';
        btn.innerHTML = '<span class="material-symbols-outlined">visibility_off</span> Hide';
    } else {
        el.type = 'password';
        btn.innerHTML = '<span class="material-symbols-outlined">visibility</span> Show';
    }
}

async function pasteToField(fieldId) {
    const el = document.getElementById(fieldId);
    if (!el) return;
    try {
        const text = await navigator.clipboard.readText();
        if (text) {
            el.value = text.trim();
            el.dispatchEvent(new Event('input'));
            el.focus();
        }
    } catch(e) {
        el.focus();
        alert('Please press Ctrl+V (or Cmd+V) to paste into this field.');
    }
}

async function copyFromField(fieldId, btn) {
    const el = document.getElementById(fieldId);
    if (!el) return;
    const text = el.value.trim();
    if (!text) return;
    await copyText(text, btn);
}

async function copyText(text, btn) {
    try {
        await navigator.clipboard.writeText(text);
        if (btn) {
            const orig = btn.innerHTML;
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;margin-right:2px;">check</span> Copied!';
            btn.style.color = 'var(--green)';
            setTimeout(() => {
                btn.innerHTML = orig;
                btn.style.color = '';
            }, 2000);
        }
    } catch(e) {
        prompt('Copy to clipboard (Ctrl+C):', text);
    }
}

function toggleSecretVisibility(fieldId, btn) {
    const el = document.getElementById(fieldId);
    if (!el) return;
    if (el.type === 'password') {
        el.type = 'text';
        if (btn) btn.innerHTML = '<span class="material-symbols-outlined">visibility_off</span> Hide';
    } else {
        el.type = 'password';
        if (btn) btn.innerHTML = '<span class="material-symbols-outlined">visibility</span> Show';
    }
}

async function saveSettings() {
    const btn = document.getElementById('btnSave');
    const status = document.getElementById('saveStatus');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined spin" style="font-size:14px;">progress_activity</span> Saving…';
    }

    try {
        const res  = await fetch('/api/config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(pendingChanges),
        });
        const json = await res.json();

        if (json.status === 'success') {
            document.querySelectorAll('.cfg-input.modified').forEach(input => {
                input.classList.remove('modified');
                input.classList.add('saved');
                input.dataset.original = input.value;
                setTimeout(() => input.classList.remove('saved'), 3000);
            });

            pendingChanges = {};
            if (status) {
                status.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;color:var(--green);margin-right:4px;">check_circle</span> All changes saved to data.db successfully!';
                status.className = 'save-status success';
            }
            if (btn) btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:14px;">save</span> Save All Changes';
        } else {
            throw new Error(json.message ?? 'Unknown error');
        }
    } catch (e) {
        if (status) {
            status.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;color:var(--red);margin-right:4px;">error</span> Save failed: ' + e.message;
            status.className = 'save-status error';
        }
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:14px;">save</span> Save All Changes';
        }
    }
}

async function resetToDefaults() {
    if (!confirm('Reset ALL settings to factory defaults? This will overwrite any customisations.')) return;
    location.reload();
}

async function purgeCache(type = 'all') {
    const msg = document.getElementById('cachePurgeMsg');
    if (msg) msg.innerHTML = '<span class="ui-loading-inline"><span class="material-symbols-outlined spinner-icon">progress_activity</span> Purging cache…</span>';

    try {
        const res = await fetch('/api/config/cache/clear', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type }),
        });
        const json = await res.json();

        if (json.status === 'success') {
            if (msg) {
                msg.innerHTML = '<span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;color:var(--green);margin-right:4px;">check_circle</span> ' + json.message;
                setTimeout(() => { msg.textContent = ''; }, 4000);
            }
            if (json.stats) {
                const act = document.getElementById('cacheActiveCount');
                const fin = document.getElementById('cacheFinnhubCount');
                const sch = document.getElementById('cacheSchwabCount');
                const dbs = document.getElementById('cacheDbSize');
                if (act) act.textContent = `${json.stats.activeEntries || 0} Records`;
                if (fin) fin.textContent = `${json.stats.finnhubEntries || 0} Items`;
                if (sch) sch.textContent = `${json.stats.schwabEntries || 0} Masked`;
                if (dbs) dbs.textContent = `${json.stats.databaseSizeKb || 0} KB`;
            }
        }
    } catch (e) {
        if (msg) {
            msg.innerHTML = '<span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;color:var(--red);margin-right:4px;">error</span> Purge error: ' + e.message;
            msg.style.color = 'var(--red)';
        }
    }
}
