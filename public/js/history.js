let growthRawData = window.initialGrowthData || {};
let currentViewMode = 'preTax';
let showSpy = false;
let currentPeriod = 'THIS_YEAR';
let chartInstance = null;

let sortCol = 'event_date';
let sortAsc = true;

document.addEventListener('DOMContentLoaded', function () {
    populateDropdowns();
    renderGrowthChart();
    applyFiltersAndSort();
});

function populateDropdowns() {
    const provSel = document.getElementById('selectProvider');
    const accSel = document.getElementById('selectAccount');
    if (!accSel) return;

    const accs = growthRawData.accounts || [];
    const curAcc = accSel.value || 'ALL';
    accSel.innerHTML = '<option value="ALL">All Accounts (Consolidated)</option>' + accs.map(a => `<option value="${a}">${a}</option>`).join('');
    accSel.value = curAcc;
}

function setGrowthView(mode) {
    currentViewMode = mode;
    const btnPre = document.getElementById('btnPreTax');
    const btnAfter = document.getElementById('btnAfterTax');
    if (btnPre && btnAfter) {
        if (mode === 'preTax') {
            btnPre.classList.add('active');
            btnAfter.classList.remove('active');
        } else {
            btnAfter.classList.add('active');
            btnPre.classList.remove('active');
        }
    }

    renderGrowthChart();
}

function toggleSpyBenchmark() {
    const chk = document.getElementById('chkShowSpy');
    if (chk) showSpy = chk.checked;
    if (chartInstance) {
        chartInstance.setDatasetVisibility(1, showSpy);
        chartInstance.update();
    }
}

function loadPeriod(period) {
    if (currentPeriod === period && period !== 'ALL') {
        period = 'ALL';
    }
    currentPeriod = period;
    document.querySelectorAll('.btn-period').forEach(b => {
        b.classList.remove('active', 'active-period');
    });
    const activeBtn = document.getElementById('btn' + period);
    if (activeBtn) {
        activeBtn.classList.add('active');
    }

    fetch('/portfolio/history-api?period=' + period)
        .then(res => res.json())
        .then(data => {
            growthRawData = data;
            populateDropdowns();
            updateStatsHeader(data.summary);
            applyFiltersAndSort();
            renderGrowthChart();
        })
        .catch(err => console.error('Error fetching history period:', err));
}

function formatPeriodLabel(p) {
    if (p === '30D' || p === '30') return '30 Days';
    if (p === '60D' || p === '60') return '60 Days';
    if (p === '90D' || p === '90') return '90 Days';
    if (p === 'THIS_YEAR' || p === 'YTD') return 'This Year';
    if (p === 'LAST_YEAR') return 'Last Year';
    if (p === 'ALL') return 'All';
    return p;
}

function uploadSchwabCsv(input) {
    if (!input.files || input.files.length === 0) return;
    const file = input.files[0];
    const formData = new FormData();
    formData.append('csv_file', file);
    formData.append('account_number', document.getElementById('selectAccount') ? document.getElementById('selectAccount').value || 'V-Brokerage' : 'V-Brokerage');

    const btn = input.previousElementSibling;
    const origText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.innerHTML = '<span class="material-symbols-outlined spin" style="font-size: 16px;">progress_activity</span> Uploading...';
        btn.disabled = true;
    }

    fetch('/portfolio/import-csv', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (btn) {
            btn.innerHTML = origText;
            btn.disabled = false;
        }
        if (res.success) {
            alert(`CSV Import Complete!\nImported: ${res.imported} new transactions\nSkipped (Duplicates): ${res.skipped}`);
            loadPeriod(currentPeriod);
        } else {
            alert('Import Error: ' + (res.error || 'Failed to import CSV'));
        }
    })
    .catch(err => {
        if (btn) {
            btn.innerHTML = origText;
            btn.disabled = false;
        }
        alert('Upload failed: ' + err.message);
    });
}

function updateStatsHeader(s) {
    if (!s) return;
    const cVal = document.getElementById('statCurrentVal');
    const aVal = document.getElementById('statAfterTaxVal');
    const tVal = document.getElementById('statEstTax');
    const eVal = document.getElementById('statTotalEvents');

    if (cVal) cVal.textContent = '$' + s.currentValue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (aVal) aVal.textContent = '$' + s.currentAfterTax.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (tVal) tVal.textContent = '$' + s.totalEstTax.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (eVal) eVal.textContent = s.totalEvents;

    const grossEl = document.getElementById('statGrossReturn');
    if (grossEl) {
        grossEl.textContent = (s.grossReturnPct >= 0 ? '+' : '') + s.grossReturnPct + '% Gross Growth';
        grossEl.style.color = s.grossReturnPct >= 0 ? 'var(--green)' : 'var(--red)';
    }

    const netEl = document.getElementById('statAfterTaxReturn');
    if (netEl) {
        netEl.textContent = (s.afterTaxReturnPct >= 0 ? '+' : '') + s.afterTaxReturnPct + '% Net Growth';
    }
}

function sortTable(column) {
    if (sortCol === column) {
        sortAsc = !sortAsc;
    } else {
        sortCol = column;
        sortAsc = true;
    }

    ['event_date', 'event_type', 'provider', 'account_number', 'symbol', 'impact_amount', 'cost_basis', 'realized_gain', 'est_tax'].forEach(col => {
        const el = document.getElementById('sort_' + col);
        if (el) el.textContent = '';
    });

    const activeIndicator = document.getElementById('sort_' + sortCol);
    if (activeIndicator) {
        activeIndicator.innerHTML = sortAsc ? '<span class="material-symbols-outlined" style="font-size:12px; vertical-align:middle;">arrow_upward</span>' : '<span class="material-symbols-outlined" style="font-size:12px; vertical-align:middle;">arrow_downward</span>';
    }

    applyFiltersAndSort();
}

function applyFiltersAndSort() {
    const provSel = document.getElementById('selectProvider');
    const accSel = document.getElementById('selectAccount');
    const selectedProvider = provSel ? provSel.value : 'ALL';
    const selectedAccount  = accSel ? accSel.value : 'ALL';

    let events = (growthRawData.events || []).slice();

    const dates = growthRawData.dates || [];
    if (dates.length > 0) {
        const minDate = dates[0].substring(0, 10);
        const maxDate = dates[dates.length - 1].substring(0, 10);
        events = events.filter(e => {
            const ed = (e.event_date || '').substring(0, 10);
            return ed >= minDate && ed <= maxDate;
        });
    }

    if (selectedProvider !== 'ALL') {
        events = events.filter(e => (e.provider || 'Schwab Primary') === selectedProvider);
    }

    if (selectedAccount !== 'ALL') {
        events = events.filter(e => {
            const acc = (e.account_number || '').trim();
            const sel = selectedAccount.trim();
            if (acc === sel) return true;
            const cleanSel = sel.replace('Account ', '').trim();
            const cleanAcc = acc.replace('Account ', '').trim();
            return cleanAcc === cleanSel && cleanSel.startsWith('***');
        });
    }

    let dynamicSTGains = 0.0;
    let dynamicLTGains = 0.0;
    events.forEach(ev => {
        const gain = parseFloat(ev.realized_gain || 0);
        const evType = (ev.event_type || '').toUpperCase();
        if (evType === 'LONG_TERM') {
            dynamicLTGains += gain;
        } else {
            dynamicSTGains += gain;
        }
    });
    const dynamicTax = Math.max(0, dynamicSTGains * 0.20) + Math.max(0, dynamicLTGains * 0.15);

    const estTaxEl = document.getElementById('statEstTax');
    const totalEventsEl = document.getElementById('statTotalEvents');
    if (estTaxEl) estTaxEl.textContent = '$' + dynamicTax.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (totalEventsEl) totalEventsEl.textContent = events.length;

    events.sort((a, b) => {
        let valA = a[sortCol];
        let valB = b[sortCol];

        if (typeof valA === 'string') valA = valA.toLowerCase();
        if (typeof valB === 'string') valB = valB.toLowerCase();

        if (valA < valB) return sortAsc ? -1 : 1;
        if (valA > valB) return sortAsc ? 1 : -1;
        return 0;
    });

    const sumEl = document.getElementById('tableFilterSummary');
    if (sumEl) sumEl.textContent = `Showing ${events.length} transactions & orders for ${formatPeriodLabel(currentPeriod)} timeline`;

    renderEventsTable(events);
}

function renderEventsTable(events) {
    const tbody = document.getElementById('eventsTableBody');
    if (!tbody) return;
    if (!events || events.length === 0) {
        tbody.innerHTML = `<tr><td colspan="10" style="padding: 24px; text-align: center; color: var(--muted);">No matching events or transactions found for the selected timeline and filters.</td></tr>`;
        return;
    }

    tbody.innerHTML = events.map((ev, index) => {
        const isOpt = ev.event_type === 'OPTION' || ev.event_type === 'OPTION_PREMIUM';
        const isDiv = ev.event_type === 'DIVIDEND';
        
        const badgeClass = isOpt 
            ? 'background: rgba(34,197,94,0.12); color: var(--green); border: 1px solid rgba(34,197,94,0.3);' 
            : (isDiv 
                ? 'background: rgba(56,189,248,0.12); color: var(--blue); border: 1px solid rgba(56,189,248,0.3);' 
                : 'background: rgba(168,85,247,0.12); color: var(--purple); border: 1px solid rgba(168,85,247,0.3);');
        
        const badgeText = isOpt ? 'OPTION' : (isDiv ? 'DIVIDEND' : 'EQUITY');

        const impactAmt = parseFloat(ev.impact_amount || 0);
        const costBasis = parseFloat(ev.cost_basis || 0);
        const realized = parseFloat(ev.realized_gain || 0);
        const tax = parseFloat(ev.est_tax || 0);

        let rawSym = ev.symbol || '';
        let isOptionSymbol = (ev.is_option === true) || (ev.event_type === 'OPTION') || pregOptionCheck(rawSym);
        let displaySymbol = ev.display_symbol || (isOptionSymbol ? rawSym.split(/\s+/)[0] : rawSym);

        let descText = ev.description || ev.title || '';
        if (descText.startsWith('TRADE:') || descText.startsWith('DIVIDEND:') || descText.startsWith('OPTION:') || descText.startsWith('OPTION_PREMIUM:')) {
            descText = (ev.description && ev.description !== descText) ? ev.description : '';
        }

        const rowId = `opt_detail_${index}`;

        return `
            <tr style="border-bottom: 1px solid var(--border);">
                <td style="padding: 10px 8px; font-weight: 600; white-space: nowrap;">${ev.event_date}</td>
                <td style="padding: 10px 8px; white-space: nowrap;"><span class="rb" style="padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; ${badgeClass}">${badgeText}</span></td>
                <td style="padding: 10px 8px; font-weight: 600; color: var(--text); white-space: nowrap;">${ev.provider || 'Schwab'}</td>
                <td style="padding: 10px 8px; font-weight: 600; color: var(--muted); white-space: nowrap;">${ev.account_number || 'Account'}</td>
                <td style="padding: 10px 8px; font-weight: 700; color: var(--text); white-space: nowrap;">
                    ${displaySymbol}
                    ${isOptionSymbol || isOpt ? `<button onclick="toggleRowDetail('${rowId}')" style="background: none; border: none; color: var(--blue); cursor: pointer; padding: 0 4px; font-size: 10px; font-weight: 700; display:inline-flex; align-items:center; gap:2px;"><span class="material-symbols-outlined" style="font-size:12px;">info</span> info</button>` : ''}
                </td>
                <td style="padding: 10px 8px; text-align: center;">
                    ${descText ? `
                        <div class="desc-popover-wrapper" style="position:relative; display:inline-block;">
                            <span class="material-symbols-outlined desc-info-icon" style="font-size:16px; color:var(--blue); cursor:pointer; vertical-align:middle;">info</span>
                            <div class="desc-popover-box" style="display:none; position:absolute; left:50%; transform:translateX(-50%); top:22px; background:var(--bg3); border:1px solid var(--border); padding:8px 12px; border-radius:8px; font-size:11px; color:var(--text); width:240px; box-shadow:0 8px 24px rgba(0,0,0,0.4); z-index:100; white-space:normal; text-align:left; line-height:1.4;">
                                <div style="font-weight:700; color:var(--blue); margin-bottom:2px;">Event Description</div>
                                ${descText}
                            </div>
                        </div>
                    ` : '<span style="color:var(--muted);">—</span>'}
                </td>
                <td style="padding: 10px 8px; text-align: right; font-weight: 700; color: ${impactAmt >= 0 ? 'var(--green)' : 'var(--red)'}; white-space: nowrap;">${impactAmt >= 0 ? '+' : ''}$${impactAmt.toFixed(2)}</td>
                <td style="padding: 10px 8px; text-align: right; font-weight: 600; color: var(--muted); white-space: nowrap;">${costBasis > 0 ? '$' + costBasis.toFixed(2) : '—'}</td>
                <td style="padding: 10px 8px; text-align: right; font-weight: 600; color: ${realized >= 0 ? 'var(--green)' : 'var(--red)'}; white-space: nowrap;">${realized >= 0 ? '+' : ''}$${realized.toFixed(2)}</td>
                <td style="padding: 10px 8px; text-align: right; font-weight: 600; color: var(--yellow); white-space: nowrap;">$${tax.toFixed(2)}</td>
            </tr>
            ${isOptionSymbol || isOpt ? `
                <tr id="${rowId}" style="display: none; background: rgba(15, 23, 42, 0.4); border-bottom: 1px solid var(--border);">
                    <td colspan="10" style="padding: 8px 16px; font-size: 11px; color: var(--muted);">
                        <span style="font-weight: 700; color: var(--text);">Contract Spec:</span> <code style="color: var(--blue);">${rawSym}</code>
                        ${descText ? `<span style="margin-left: 12px; font-weight: 700; color: var(--text);">Detail:</span> ${descText}` : ''}
                    </td>
                </tr>
            ` : ''}
        `;
    }).join('');
}

function pregOptionCheck(sym) {
    if (!sym) return false;
    return /^[A-Z0-9]+\s*\d{6}[CP]\d{8}$/.test(sym) 
        || /\b\d{1,2}\/\d{1,2}\/\d{2,4}\s+\d+(\.\d+)?\s+[CP]\b/i.test(sym)
        || /\b(Call|Put)\b/i.test(sym);
}

function toggleRowDetail(id) {
    const row = document.getElementById(id);
    if (row) {
        row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }
}

function renderGrowthChart() {
    const canvas = document.getElementById('growthChartCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (chartInstance) {
        chartInstance.destroy();
    }

    const labels = growthRawData.dates || [];
    const portSeries = currentViewMode === 'preTax' 
        ? (growthRawData.preTaxValues || []) 
        : (growthRawData.afterTaxValues || []);
    const spySeries = growthRawData.spyValues || [];

    const gradient = ctx.createLinearGradient(0, 0, 0, 400);
    if (currentViewMode === 'preTax') {
        gradient.addColorStop(0, 'rgba(56, 189, 248, 0.25)');
        gradient.addColorStop(1, 'rgba(56, 189, 248, 0.0)');
    } else {
        gradient.addColorStop(0, 'rgba(34, 197, 94, 0.25)');
        gradient.addColorStop(1, 'rgba(34, 197, 94, 0.0)');
    }

    chartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: currentViewMode === 'preTax' ? 'Portfolio Liquidation Value ($)' : 'After-Tax Net Value ($)',
                    data: portSeries,
                    borderColor: currentViewMode === 'preTax' ? '#38bdf8' : '#22c55e',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                },
                {
                    label: 'S&P 500 (SPY) Index Benchmark ($)',
                    data: spySeries,
                    borderColor: 'rgba(255, 255, 255, 0.4)',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    fill: false,
                    tension: 0.3,
                    pointRadius: 0,
                    hidden: !showSpy,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    labels: {
                        color: '#94a3b8',
                        font: { family: 'Inter', size: 12 }
                    }
                },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleColor: '#f8fafc',
                    bodyColor: '#cbd5e1',
                    borderColor: '#334155',
                    borderWidth: 1,
                    callbacks: {
                        label: function (context) {
                            return context.dataset.label + ': $' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2});
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255,255,255,0.05)' },
                    ticks: { color: '#94a3b8', font: { size: 11 } }
                },
                y: {
                    grid: { color: 'rgba(255,255,255,0.05)' },
                    ticks: {
                        color: '#94a3b8',
                        font: { size: 11 },
                        callback: function (val) {
                            return '$' + val.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}
