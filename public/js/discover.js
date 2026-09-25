document.addEventListener('DOMContentLoaded', () => {
    fetchDiscoverSuggestions();
    loadTrackedStocks();
});

let allTrackedStocks = [];

async function loadTrackedStocks() {
    try {
        const res = await fetch('/api/stocks');
        const data = await res.json();
        if (data.status === 'success') {
            allTrackedStocks = data.data;
            renderTrackedTable();
        }
    } catch(e) {
        console.error(e);
    }
}

function renderTrackedTable() {
    const tbody = document.getElementById('tab3TrackedTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';
    allTrackedStocks.forEach(s => {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid rgba(46,53,69,0.5)';
        tr.innerHTML = `
            <td style="padding:10px 12px;"><strong>${s.symbol}</strong></td>
            <td style="padding:10px 12px;">${s.name}</td>
            <td style="padding:10px 12px;"><span class="m">${s.sector}</span></td>
            <td style="padding:10px 12px; text-align:right;"><strong>$${s.price ? s.price.toFixed(2) : '—'}</strong></td>
            <td style="padding:10px 12px; text-align:right;">$${s.targetPrice ? s.targetPrice.toFixed(2) : '—'}</td>
            <td style="padding:10px 12px; text-align:center;"><strong class="g">${s.score}</strong></td>
            <td style="padding:10px 12px; text-align:center;">
                <button class="fbtn" style="color:var(--red); border-color:rgba(248,81,73,0.4); display:inline-flex; align-items:center; gap:4px;" onclick="deleteTrackedStock(${s.id})"><span class="material-symbols-outlined" style="font-size:14px;">delete</span> Delete</button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

async function fetchDiscoverSuggestions() {
    const grid = document.getElementById('suggestionsGrid');
    if (!grid) return;
    try {
        const res = await fetch('/api/ai/discover-suggestions');
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }
        const result = await res.json();

        if (result.status === 'success' && result.data) {
            const list = result.data;
            grid.innerHTML = '';
            const trackedSymbols = allTrackedStocks.map(s => s.symbol);

            list.forEach(s => {
                const isTracked = trackedSymbols.includes(s.symbol);
                const upsidePct = Math.round(((s.targetPrice - s.price) / s.price) * 100);

                const card = document.createElement('div');
                card.className = 'acc-box';
                card.style.background = 'var(--bg2)';

                const serializedObj = encodeURIComponent(JSON.stringify(s));

                card.innerHTML = `
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <h4 style="margin:0;">
                                <span>${s.symbol} — ${s.name}</span>
                            </h4>
                            <span class="hbadge">${s.sector}</span>
                        </div>

                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:8px; font-size:12px; margin-bottom:12px; background:var(--bg3); padding:8px 12px; border-radius:8px; border:1px solid var(--border);">
                            <div>Price: <strong>${s.price ? '$' + s.price.toFixed(2) : '—'}</strong></div>
                            <div>12M Target: <strong class="g">${s.targetPrice ? '$' + s.targetPrice.toFixed(2) + ' (+' + upsidePct + '%)' : '—'}</strong></div>
                            <div>Score: <strong class="g">${s.score ? s.score + ' / 100' : '—'}</strong></div>
                        </div>

                        ${(s.delta || s.probabilityOfProfit || s.impliedVolatilityRank) ? `
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:8px; font-size:11px; margin-bottom:12px; background:rgba(188,140,255,0.05); padding:8px; border-radius:8px; border:1px solid rgba(188,140,255,0.2);">
                            <div>Delta: <strong style="color:var(--purple);">${s.delta || 'N/A'}</strong></div>
                            <div>PoP: <strong style="color:var(--green);">${s.probabilityOfProfit || 'N/A'}</strong></div>
                            <div>IV Rank: <strong style="color:var(--blue);">${s.impliedVolatilityRank || 'N/A'}</strong></div>
                        </div>
                        ` : ''}

                        <div class="tb" style="background:rgba(88,166,255,0.05); border-color:rgba(88,166,255,0.3); margin-bottom:10px; padding:10px;">
                            <h4 style="color:var(--blue); font-size:10px; margin-bottom:4px;"><span class="material-symbols-outlined" style="font-size:14px; margin-right:4px;">lightbulb</span> WHY TRACK THIS (REASONING):</h4>
                            <p style="font-size:12px; margin:0; line-height:1.4;">${s.reasoning}</p>
                        </div>

                        <div style="font-size:11px; color:var(--muted); margin-bottom:12px;">
                            <div><strong><span class="material-symbols-outlined" style="font-size:14px; margin-right:4px;">bolt</span> Sector Catalysts:</strong> ${s.catalysts}</div>
                            <div style="margin-top:4px;"><strong><span class="material-symbols-outlined" style="font-size:14px; margin-right:4px;">lock</span> Recommended Strategy:</strong> <span style="color:var(--purple); font-weight:600;">${s.suggestedStrategy}</span></div>
                        </div>
                    </div>

                    ${isTracked ? `
                        <button class="fbtn" style="width:100%; border-color:var(--green); color:var(--green); cursor:default;" disabled>
                            <span class="material-symbols-outlined" style="font-size:14px; margin-right:4px;">check</span> Already in Tracked Screener
                        </button>
                    ` : `
                        <button class="btn btn-pri" style="width:100%; text-align:center; display:inline-flex; align-items:center; justify-content:center;" onclick="addSuggestionFromEncoded('${serializedObj}')">
                            <span class="material-symbols-outlined" style="font-size:14px; margin-right:4px;">add</span> Add ${s.symbol} to Tracked Screener
                        </button>
                    `}
                `;
                grid.appendChild(card);
            });
        }
    } catch (e) {
        console.error('Failed to load suggestions:', e);
    }
}

function addSuggestionFromEncoded(encoded) {
    try {
        const obj = JSON.parse(decodeURIComponent(encoded));
        addSuggestionToTracked(obj);
    } catch(e) {
        console.error('Failed to decode suggestion object:', e);
    }
}

async function addSuggestionToTracked(obj) {
    try {
        const res = await fetch('/api/stocks/add', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(obj)
        });
        const result = await res.json();

        if (result.status === 'success') {
            alert(`${obj.symbol} added to your tracked screener!`);
            await loadTrackedStocks();
            fetchDiscoverSuggestions();
        }
    } catch (e) {
        console.error('Failed to add suggestion:', e);
    }
}

async function scanStockTab3() {
    const inputEl = document.getElementById('tab3SymbolInput');
    const resBox = document.getElementById('tab3ScanResult');
    if (!inputEl || !resBox) return;

    const symbol = inputEl.value.trim().toUpperCase();

    if (!symbol) {
        alert('Please enter a valid stock ticker (e.g. AMD, AMZN, MSFT)');
        return;
    }

    resBox.style.display = 'block';
    resBox.innerHTML = '<span class="ui-loading-inline"><span class="material-symbols-outlined spinner-icon">progress_activity</span> Scanning Finnhub & broker market data for ' + symbol + '...</span>';

    try {
        const res = await fetch(`/api/stocks/suggest/${symbol}`);
        const result = await res.json();

        if (result.status === 'success' && result.data) {
            const d = result.data;
            const fw = d.flywheel || {};
            const serializedObj = encodeURIComponent(JSON.stringify(d));

            resBox.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div>
                        <strong style="font-size:15px; color:var(--purple);">${d.symbol}</strong> — ${d.name}
                        <span class="hbadge" style="margin-left:8px;">${d.sector}</span>
                    </div>
                    <span class="badge-sig badge-${fw.signal}">${fw.signalBadge || 'CALL'}</span>
                </div>
                <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:8px; font-size:12px; margin-bottom:12px;">
                    <div>Price: <strong>$${d.price.toFixed(2)}</strong></div>
                    <div>Target: <strong class="g">$${d.targetPrice.toFixed(2)}</strong></div>
                    <div>Score: <strong class="g">${d.score} / 100</strong></div>
                    <div>Risk: <strong>${d.risk}</strong></div>
                </div>
                <div class="tb" style="background:rgba(188,140,255,0.05); border-color:rgba(188,140,255,0.3); margin-bottom:10px; padding:10px;">
                    <h4 style="color:var(--purple); font-size:10px; margin-bottom:4px; display:flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:14px;">lightbulb</span> WHY TRACK THIS STOCK:</h4>
                    <p style="font-size:12px; margin:0; line-height:1.4;">${d.thesis}</p>
                </div>
                <button class="btn btn-pri" style="width:100%; text-align:center; display:inline-flex; align-items:center; justify-content:center; gap:6px;" onclick="addSuggestionFromEncoded('${serializedObj}')">
                    <span class="material-symbols-outlined" style="font-size:16px;">add</span> Add ${d.symbol} to Screener & Tracked List
                </button>
            `;
        }
    } catch (e) {
        console.error('Scan failed:', e);
        resBox.innerHTML = '<span class="r">Failed to fetch market data for ' + symbol + '. Please check the symbol and try again.</span>';
    }
}

async function deleteTrackedStock(id) {
    if (!confirm('Are you sure you want to remove this stock from your tracked list?')) return;

    try {
        const res = await fetch(`/api/stocks/${id}`, { method: 'DELETE' });
        const result = await res.json();

        if (result.status === 'success') {
            await loadTrackedStocks();
        }
    } catch (e) {
        console.error('Delete failed:', e);
    }
}
