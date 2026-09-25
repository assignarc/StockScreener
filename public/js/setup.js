// Setup Wizard JavaScript Logic
let brokerInstances = window.initialBrokerInstances || [];
if (!Array.isArray(brokerInstances) || brokerInstances.length === 0) {
    brokerInstances = [
        { id: 'b1', type: 'schwab', nickname: 'My Broker Account', app_key: '', app_secret: '' }
    ];
}

document.addEventListener('DOMContentLoaded', () => {
    renderBrokerInstances();
    toggleWizLlmProvider();
});

function toggleWizLlmProvider() {
    const providerEl = document.getElementById('wizLlmProvider');
    if (!providerEl) return;
    const provider = providerEl.value;
    document.querySelectorAll('.llm-provider-subpanel').forEach(p => {
        p.style.display = 'none';
    });
    if (provider === 'gemini') {
        const p = document.getElementById('wizPanelGemini');
        if (p) p.style.display = 'block';
    } else if (provider === 'openai') {
        const p = document.getElementById('wizPanelOpenai');
        if (p) p.style.display = 'block';
    } else if (provider === 'claude') {
        const p = document.getElementById('wizPanelClaude');
        if (p) p.style.display = 'block';
    } else if (provider === 'local') {
        const p = document.getElementById('wizPanelLocal');
        if (p) p.style.display = 'block';
    }
}

function showWizardStep(step) {
    for (let i = 1; i <= 5; i++) {
        const p = document.getElementById('wizStep' + i);
        const ind = document.getElementById('stepIndicator' + i);
        if (p) p.style.display = (i === step) ? 'block' : 'none';
        if (ind) ind.classList.toggle('active', i === step);
    }
}

function escapeHtml(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function renderBrokerInstances() {
    const container = document.getElementById('brokerInstancesContainer');
    if (!container) return;

    let html = '';
    brokerInstances.forEach((inst, index) => {
        const iNum = index + 1;
        const instId = inst.id || ('b' + iNum);
        const removeBtn = (brokerInstances.length > 1) 
            ? `<button type="button" class="slick-pill-btn" style="color:var(--red); border-color:rgba(248,113,113,0.3);" onclick="removeBrokerInstanceCard(${index})"><span class="material-symbols-outlined">delete</span> Remove</button>` 
            : '';

        const oauthBtn = (inst.type === 'schwab' || inst.type === 'etrade')
            ? `<a href="/api/broker/${encodeURIComponent(instId)}/login" target="_blank" class="hbtn hbtn-blue" style="font-size:12px; padding:6px 14px; display:inline-flex; align-items:center; gap:6px; font-weight:700; text-decoration:none;"><span class="material-symbols-outlined">link</span> Connect via OAuth</a>`
            : '';

        const currentScheme = window.location.protocol;
        const currentHost = window.location.host || '127.0.0.1:8000';
        const dynamicRedirectUrl = `${currentScheme}//${currentHost}/api/broker/${instId}/callback`;

        let helpContent = '';
        if (inst.type === 'schwab') {
            helpContent = `
                <div style="background:rgba(2,132,199,0.06); border:1px solid rgba(2,132,199,0.2); border-radius:10px; padding:12px; margin-top:14px; font-size:12px; line-height:1.5;">
                    <div style="font-weight:700; color:var(--blue); margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                        <span class="material-symbols-outlined" style="font-size:16px;">info</span> Charles Schwab OAuth Setup Guide:
                    </div>
                    <ol style="margin:0; padding-left:18px;">
                        <li>Register or log in at the <a href="https://developer.schwab.com/" target="_blank" style="color:var(--blue); text-decoration:underline;">Schwab Developer Portal</a>.</li>
                        <li>Create a new App and configure the Redirect URI to match your exact dynamic local callback URL below:
                            <div style="margin:4px 0; font-family:monospace; background:var(--bg1); padding:4px 8px; border-radius:6px; border:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:gap:6px;">
                                <span style="word-break:break-all; color:var(--text);">${dynamicRedirectUrl}</span>
                                <button type="button" class="slick-pill-btn" onclick="copyDynamicText('${dynamicRedirectUrl}', this)" style="padding:2px 8px;">Copy URL</button>
                            </div>
                        </li>
                        <li>Copy the generated <strong>App Key</strong> (Client ID) and <strong>App Secret</strong> into the fields below, then click <strong>Connect via OAuth</strong> above.</li>
                    </ol>
                </div>
            `;
        } else if (inst.type === 'tastytrade') {
            helpContent = `
                <div style="background:rgba(168,85,247,0.06); border:1px solid rgba(168,85,247,0.2); border-radius:10px; padding:12px; margin-top:14px; font-size:12px; line-height:1.5;">
                    <div style="font-weight:700; color:var(--purple); margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                        <span class="material-symbols-outlined" style="font-size:16px;">info</span> Tastytrade API Integration Guide:
                    </div>
                    <ol style="margin:0; padding-left:18px;">
                        <li>Tastytrade API keys correspond directly to your trading account username and password.</li>
                        <li>Input your Tastytrade Account Username in the <strong>App Key</strong> field.</li>
                        <li>Input your Account Password in the <strong>App Secret</strong> field (encrypted safely at rest).</li>
                    </ol>
                </div>
            `;
        } else if (inst.type === 'ibkr') {
            helpContent = `
                <div style="background:rgba(210,153,34,0.06); border:1px solid rgba(210,153,34,0.2); border-radius:10px; padding:12px; margin-top:14px; font-size:12px; line-height:1.5;">
                    <div style="font-weight:700; color:var(--yellow); margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                        <span class="material-symbols-outlined" style="font-size:16px;">info</span> Interactive Brokers Local Gateway Guide:
                    </div>
                    <ol style="margin:0; padding-left:18px;">
                        <li>Launch your local IBKR Client Portal API gateway (typically on port 5000).</li>
                        <li>No credentials need to be saved here; the app will handshake automatically via your local machine session.</li>
                        <li>Set <strong>App Key</strong> and <strong>App Secret</strong> to <code>gateway</code> or leave them empty.</li>
                    </ol>
                </div>
            `;
        } else if (inst.type === 'alpaca') {
            helpContent = `
                <div style="background:rgba(34,197,94,0.06); border:1px solid rgba(34,197,94,0.2); border-radius:10px; padding:12px; margin-top:14px; font-size:12px; line-height:1.5;">
                    <div style="font-weight:700; color:var(--green); margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                        <span class="material-symbols-outlined" style="font-size:16px;">info</span> Alpaca Markets Key Configuration Guide:
                    </div>
                    <ol style="margin:0; padding-left:18px;">
                        <li>Generate credentials on your Alpaca Web Dashboard (supports both Paper and Live accounts).</li>
                        <li>Enter your <strong>API Key ID</strong> in the Client ID field.</li>
                        <li>Enter your <strong>Secret Key</strong> in the App Secret field.</li>
                    </ol>
                </div>
            `;
        } else if (inst.type === 'etrade') {
            helpContent = `
                <div style="background:rgba(2,132,199,0.06); border:1px solid rgba(2,132,199,0.2); border-radius:10px; padding:12px; margin-top:14px; font-size:12px; line-height:1.5;">
                    <div style="font-weight:700; color:var(--blue); margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                        <span class="material-symbols-outlined" style="font-size:16px;">info</span> E*TRADE Developer Access Setup Guide:
                    </div>
                    <ol style="margin:0; padding-left:18px;">
                        <li>Set the E*TRADE OAuth Callback URI inside your E*TRADE portal configuration to:
                            <div style="margin:4px 0; font-family:monospace; background:var(--bg1); padding:4px 8px; border-radius:6px; border:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
                                <span style="word-break:break-all; color:var(--text);">${dynamicRedirectUrl}</span>
                                <button type="button" class="slick-pill-btn" onclick="copyDynamicText('${dynamicRedirectUrl}', this)" style="padding:2px 8px;">Copy URL</button>
                            </div>
                        </li>
                        <li>Provide your E*TRADE Consumer Key and Consumer Secret in the inputs below, then initiate authentication.</li>
                    </ol>
                </div>
            `;
        }

        html += `
        <div class="broker-instance-card" style="background:var(--bg3); border:1px solid var(--border); border-radius:14px; padding:22px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="hbadge" style="background:var(--blue); color:#fff; font-weight:800;">Slot ${instId.toUpperCase()}</span>
                    <strong style="font-size:14px; color:var(--text); font-family:'Outfit',sans-serif;">${escapeHtml(inst.nickname || 'Broker Connection ' + iNum)}</strong>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    ${oauthBtn}
                    ${removeBtn}
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:16px;">
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:var(--text); text-transform:uppercase; margin-bottom:6px;">Brokerage Integration Type</label>
                    <select class="styled-select-input" onchange="updateInstanceField(${index}, 'type', this.value)">
                        <option value="schwab" ${inst.type === 'schwab' ? 'selected' : ''}>Charles Schwab (OAuth 2.0 API)</option>
                        <option value="tastytrade" ${inst.type === 'tastytrade' ? 'selected' : ''}>Tastytrade API</option>
                        <option value="ibkr" ${inst.type === 'ibkr' ? 'selected' : ''}>Interactive Brokers (IBKR Gateway)</option>
                        <option value="alpaca" ${inst.type === 'alpaca' ? 'selected' : ''}>Alpaca Markets (Trading API)</option>
                        <option value="etrade" ${inst.type === 'etrade' ? 'selected' : ''}>E*TRADE / Morgan Stanley (OAuth)</option>
                        <option value="public" ${inst.type === 'public' ? 'selected' : ''}>Public.com Developer API</option>
                        <option value="robinhood" ${inst.type === 'robinhood' ? 'selected' : ''}>Robinhood (Crypto / Unofficial)</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:var(--text); text-transform:uppercase; margin-bottom:6px;">Short Account Nickname (Displayed on Portfolio)</label>
                    <input type="text" class="large-key-input" style="height:42px;" placeholder="e.g. Schwab Main, Schwab IRA, IBKR Margin" value="${escapeHtml(inst.nickname || '')}" oninput="updateInstanceField(${index}, 'nickname', this.value)">
                </div>
            </div>

            <!-- Credentials Fields -->
            <div style="margin-bottom:14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label style="font-size:11px; font-weight:700; color:var(--text); text-transform:uppercase;">App Key / Client ID</label>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="slick-pill-btn" onclick="pasteToField('bKey_${index}')"><span class="material-symbols-outlined">content_paste</span> Paste</button>
                        <button type="button" class="slick-pill-btn" onclick="copyFromField('bKey_${index}', this)"><span class="material-symbols-outlined">content_copy</span> Copy</button>
                    </div>
                </div>
                <input type="text" id="bKey_${index}" class="large-key-input" placeholder="App Key / Client ID..." value="${escapeHtml(inst.app_key || '')}" oninput="updateInstanceField(${index}, 'app_key', this.value)">
            </div>

            <div style="margin-bottom:14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label style="font-size:11px; font-weight:700; color:var(--text); text-transform:uppercase;">App Secret / Token</label>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="slick-pill-btn" onclick="toggleSecretVisibility('bSec_${index}', this)"><span class="material-symbols-outlined">visibility</span> Show</button>
                        <button type="button" class="slick-pill-btn" onclick="pasteToField('bSec_${index}')"><span class="material-symbols-outlined">content_paste</span> Paste</button>
                        <button type="button" class="slick-pill-btn" onclick="copyFromField('bSec_${index}', this)"><span class="material-symbols-outlined">content_copy</span> Copy</button>
                    </div>
                </div>
                <input type="password" id="bSec_${index}" class="large-key-input" placeholder="App Secret..." value="${escapeHtml(inst.app_secret || '')}" oninput="updateInstanceField(${index}, 'app_secret', this.value)">
            </div>

            ${helpContent}
        </div>
        `;
    });

    container.innerHTML = html;

    const addBtn = document.getElementById('btnAddBrokerInstance');
    if (addBtn) {
        addBtn.disabled = (brokerInstances.length >= 5);
        addBtn.style.opacity = (brokerInstances.length >= 5) ? '0.4' : '1';
    }

    const review = document.getElementById('reviewBroker');
    if (review) {
        const names = brokerInstances.map(i => `${i.nickname || 'Broker'} (${i.type.toUpperCase()})`).join(', ');
        review.textContent = names;
    }
}

function updateInstanceField(index, field, value) {
    if (brokerInstances[index]) {
        brokerInstances[index][field] = value;
        if (field === 'type') {
            renderBrokerInstances();
        } else if (field === 'nickname') {
            const review = document.getElementById('reviewBroker');
            if (review) {
                const names = brokerInstances.map(i => `${i.nickname || 'Broker'} (${i.type.toUpperCase()})`).join(', ');
                review.textContent = names;
            }
        }
    }
}

function addBrokerInstanceCard() {
    if (brokerInstances.length >= 5) return;
    const n = brokerInstances.length + 1;
    brokerInstances.push({
        id: 'b' + n,
        type: 'schwab',
        nickname: 'Schwab Account ' + n,
        app_key: '',
        app_secret: ''
    });
    renderBrokerInstances();
}

function removeBrokerInstanceCard(index) {
    if (brokerInstances.length <= 1) return;
    brokerInstances.splice(index, 1);
    renderBrokerInstances();
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

async function copyDynamicText(text, btn) {
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
        prompt('Copy callback link:', text);
    }
}

function clearField(fieldId) {
    const el = document.getElementById(fieldId);
    if (el) {
        el.value = '';
        el.dispatchEvent(new Event('input'));
        el.focus();
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

async function testFinnhubKey() {
    const keyEl = document.getElementById('wizFinnhubKey');
    const resDiv = document.getElementById('finnhubTestResult');
    if (!keyEl || !resDiv) return;
    const key = keyEl.value.trim();
    if (!key) {
        resDiv.innerHTML = '<span style="color:var(--yellow);">Please enter a Finnhub API Key first.</span>';
        return;
    }

    resDiv.innerHTML = '<span class="ui-loading-inline"><span class="material-symbols-outlined spinner-icon">progress_activity</span> Testing Finnhub connection…</span>';

    try {
        const res = await fetch('/api/setup/test-finnhub', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ apiKey: key })
        });
        const json = await res.json();

        if (json.status === 'success') {
            resDiv.innerHTML = `<span style="color:var(--green); display:inline-flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:16px;">check_circle</span> ${json.message}</span>`;
        } else {
            resDiv.innerHTML = `<span style="color:var(--red); display:inline-flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:16px;">error</span> ${json.message}</span>`;
        }
    } catch (e) {
        resDiv.innerHTML = `<span style="color:var(--red); display:inline-flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:16px;">error</span> Connection error: ${e.message}</span>`;
    }
}

async function completeSetupAndLaunch() {
    const btn = document.getElementById('btnSaveAndLaunch');
    const msg = document.getElementById('saveLaunchMsg');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined spin" style="font-size:14px;">progress_activity</span> Saving…';
    }

    const payload = {
        finnhub_api_key: document.getElementById('wizFinnhubKey') ? document.getElementById('wizFinnhubKey').value.trim() : '',
        llm_provider: document.getElementById('wizLlmProvider') ? document.getElementById('wizLlmProvider').value : 'gemini',
        gemini_api_key: document.getElementById('wizGeminiKey') ? document.getElementById('wizGeminiKey').value.trim() : '',
        gemini_model: document.getElementById('wizGeminiModel') ? document.getElementById('wizGeminiModel').value : '',
        openai_api_key: document.getElementById('wizOpenaiKey') ? document.getElementById('wizOpenaiKey').value.trim() : '',
        openai_model: document.getElementById('wizOpenaiModel') ? document.getElementById('wizOpenaiModel').value : '',
        claude_api_key: document.getElementById('wizClaudeKey') ? document.getElementById('wizClaudeKey').value.trim() : '',
        claude_model: document.getElementById('wizClaudeModel') ? document.getElementById('wizClaudeModel').value : '',
        local_llm_url: document.getElementById('wizLocalLlmUrl') ? document.getElementById('wizLocalLlmUrl').value.trim() : '',
        local_llm_api_key: document.getElementById('wizLocalLlmKey') ? document.getElementById('wizLocalLlmKey').value.trim() : '',
        local_llm_model: document.getElementById('wizLocalLlmModel') ? document.getElementById('wizLocalLlmModel').value.trim() : '',
        broker_instances: brokerInstances,
        mark_completed: true,
    };

    try {
        const res = await fetch('/api/setup/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();

        if (json.status === 'success') {
            if (msg) msg.innerHTML = '<span style="color:var(--green); display:inline-flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:16px;">check_circle</span> Setup Complete! Redirecting to your portfolio…</span>';
            setTimeout(() => {
                window.location.href = '/portfolio';
            }, 1000);
        } else {
            throw new Error(json.message ?? 'Save error');
        }
    } catch (e) {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined">save</span> Save Configurations & Launch Portfolio →';
        }
        if (msg) msg.innerHTML = `<span style="color:var(--red); display:inline-flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:16px;">error</span> ${e.message}</span>`;
    }
}
