// tax_center.js - Tax Center & Schedule D / Wash Sales Management

// Raw data arrays serialized from server or fallback
const realizationsRaw = window.taxCenterData?.realizations || [];
const incomeRaw = window.taxCenterData?.incomeRecords || [];
const carryforwardsRaw = window.taxCenterData?.carryforwards || {};
const initialTaxLiability = window.taxCenterData?.taxLiability || {};
const historyRaw = window.taxCenterData?.history || [];

let currentFilterTimeframe = 'this_year';
let currentAccountCategory = 'ALL';
let currentActiveView = 'realized';
let groupStates = {};

let currentSortCol = 'sellDate';
let currentSortDir = 'desc';
let currentTxSortCol = 'date';
let currentTxSortDir = 'desc';

function formatCurrency(v) {
    const num = Number(v) || 0;
    return (num < 0 ? '-' : '') + '$' + Math.abs(num).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function sortTableBy(col) {
    if (currentSortCol === col) {
        currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
    } else {
        currentSortCol = col;
        currentSortDir = (col === 'symbol' || col === 'account' || col === 'term' || col === 'buyDate') ? 'asc' : 'desc';
    }
    updateSortIndicators();
    applyTaxFilters();
}

function sortTxBy(col) {
    if (currentTxSortCol === col) {
        currentTxSortDir = currentTxSortDir === 'asc' ? 'desc' : 'asc';
    } else {
        currentTxSortCol = col;
        currentTxSortDir = (col === 'symbol' || col === 'account_nickname' || col === 'type') ? 'asc' : 'desc';
    }
    updateSortIndicators();
    applyTaxFilters();
}

function updateSortIndicators() {
    ['sellDate', 'symbol', 'account', 'buyDate', 'term', 'qty', 'costBasis', 'proceeds', 'realizedGain', 'estTax'].forEach(col => {
        const el = document.getElementById('sort_' + col);
        if (el) {
            if (currentSortCol === col) {
                el.textContent = currentSortDir === 'asc' ? '▲' : '▼';
                el.style.color = 'var(--blue)';
            } else {
                el.textContent = '↕';
                el.style.color = 'var(--muted)';
            }
        }
    });

    ['date', 'symbol', 'account', 'type', 'amount'].forEach(col => {
        const elId = col === 'account' ? 'sort_tx_account' : ('sort_tx_' + col);
        const el = document.getElementById(elId);
        if (el) {
            if (currentTxSortCol === (col === 'account' ? 'account_nickname' : col)) {
                el.textContent = currentTxSortDir === 'asc' ? '▲' : '▼';
                el.style.color = 'var(--blue)';
            } else {
                el.textContent = '↕';
                el.style.color = 'var(--muted)';
            }
        }
    });
}

function switchTaxView(view) {
    currentActiveView = view;
    
    const tabs = {
        'realized': { tab: document.getElementById('tabRealizedGains'), view: document.getElementById('viewRealized') },
        'dividends': { tab: document.getElementById('tabDividends'), view: document.getElementById('viewDividends') },
        'all_tx': { tab: document.getElementById('tabAllTransactions'), view: document.getElementById('viewAllTx') },
    };

    Object.keys(tabs).forEach(k => {
        const item = tabs[k];
        if (!item.tab || !item.view) return;
        if (k === view) {
            item.tab.classList.add('active');
            item.tab.removeAttribute('style');
            item.view.style.display = 'block';
        } else {
            item.tab.classList.remove('active');
            item.tab.removeAttribute('style');
            item.view.style.display = 'none';
        }
    });

    applyTaxFilters();
}

function setFilterTimeframe(timeframe, btn) {
    if (currentFilterTimeframe === timeframe && timeframe !== 'all') {
        timeframe = 'all';
        btn = btn.parentElement.querySelector('button[onclick*="\'all\'"]') || btn;
    }
    const buttons = btn.parentElement.querySelectorAll('.fbtn');
    buttons.forEach(b => {
        b.classList.remove('active');
        b.removeAttribute('style');
    });
    if (btn) btn.classList.add('active');
    
    currentFilterTimeframe = timeframe;
    applyTaxFilters();
}

function setAccountCategory(cat, btn) {
    if (currentAccountCategory === cat && cat !== 'ALL') {
        cat = 'ALL';
        btn = btn.parentElement.querySelector('[data-cat="ALL"]') || btn;
    }
    const buttons = btn.parentElement.querySelectorAll('.cat-fbtn');
    buttons.forEach(b => {
        b.classList.remove('active');
        b.removeAttribute('style');
    });
    if (btn) btn.classList.add('active');

    currentAccountCategory = cat;
    document.getElementById('filterAccountCategory').value = cat;
    applyTaxFilters();
}

function setAssetFilter(type, btn) {
    const curVal = document.getElementById('filterAssetType').value;
    if (curVal === type && type !== 'ALL') {
        type = 'ALL';
        btn = btn.parentElement.querySelector('[data-asset="ALL"]') || btn;
    }
    const buttons = btn.parentElement.querySelectorAll('.asset-fbtn');
    buttons.forEach(b => {
        b.classList.remove('active');
        b.removeAttribute('style');
    });
    if (btn) btn.classList.add('active');

    document.getElementById('filterAssetType').value = type;
    applyTaxFilters();
}

function toggleTaxGroup(safeKey, headerEl) {
    const rows = document.querySelectorAll('.group-row-' + safeKey);
    const arrow = headerEl.querySelector('.group-arrow');
    const summarySpan = headerEl.querySelector('.hdr-summary');
    
    groupStates[safeKey] = !groupStates[safeKey];
    const isCollapsed = groupStates[safeKey];

    rows.forEach(r => {
        r.style.display = isCollapsed ? 'none' : '';
    });

    if (arrow) arrow.textContent = isCollapsed ? 'chevron_right' : 'expand_more';
    if (summarySpan) summarySpan.style.display = isCollapsed ? 'inline-flex' : 'none';
}

function toggleAllGroups(expand) {
    const groupBy = document.getElementById('filterGroupBy').value;
    if (groupBy === 'NONE') return;

    Object.keys(groupStates).forEach(safeKey => {
        groupStates[safeKey] = !expand;
    });

    applyTaxFilters();
}

function applyTaxFilters() {
    const searchVal = document.getElementById('filterSearch').value.toUpperCase().trim();
    const accountFilter = document.getElementById('filterAccount').value;
    const categoryFilter = document.getElementById('filterAccountCategory').value;
    const typeFilter = document.getElementById('filterAssetType').value;
    const groupBy = document.getElementById('filterGroupBy').value;

    // Update active highlight classes on select dropdowns and search input
    const accSelect = document.getElementById('filterAccount');
    if (accSelect) {
        if (accSelect.value !== 'ALL') accSelect.classList.add('filter-active');
        else accSelect.classList.remove('filter-active');
    }

    const groupSelect = document.getElementById('filterGroupBy');
    if (groupSelect) {
        if (groupSelect.value !== 'NONE') groupSelect.classList.add('filter-active');
        else groupSelect.classList.remove('filter-active');
    }

    const searchInput = document.getElementById('filterSearch');
    if (searchInput) {
        if (searchInput.value.trim() !== '') searchInput.classList.add('filter-active');
        else searchInput.classList.remove('filter-active');
    }
    
    const now = new Date();
    const d30 = new Date(); d30.setDate(now.getDate() - 30);
    const d60 = new Date(); d60.setDate(now.getDate() - 60);
    const d90 = new Date(); d90.setDate(now.getDate() - 90);

    const expandCollapseActions = document.getElementById('expandCollapseAllActions');
    if (expandCollapseActions) {
        expandCollapseActions.style.display = (groupBy === 'NONE') ? 'none' : 'flex';
    }

    // Helper to check account matching accurately across nickname, raw number, masked number, or ID
    function matchesAccount(recordAcc, recordAccNum, targetFilter) {
        if (!targetFilter || targetFilter === 'ALL') return true;
        const target = targetFilter.trim().toUpperCase();
        const rAcc = (recordAcc || '').trim().toUpperCase();
        const rNum = (recordAccNum || '').trim().toUpperCase();

        // Direct exact match on account nickname or number
        if (rAcc === target || rNum === target) return true;

        // Check if target is a masked number or contains account digits
        const targetDigits = target.replace(/\D/g, '');
        const rNumDigits = rNum.replace(/\D/g, '');
        const rAccDigits = rAcc.replace(/\D/g, '');

        if (targetDigits.length >= 4) {
            const targetLast4 = targetDigits.slice(-4);
            if (rNumDigits.length >= 4 && rNumDigits.slice(-4) === targetLast4) return true;
            if (rAccDigits.length >= 4 && rAccDigits.slice(-4) === targetLast4) return true;
        }

        // Exact match with parentheses stripped, e.g. "V-Brokerage (Taxable)" -> "V-Brokerage"
        const cleanTarget = target.split('(')[0].trim();
        const cleanAcc = rAcc.split('(')[0].trim();
        if (cleanTarget === cleanAcc) return true;

        return false;
    }

    // 1. FILTER REALIZATIONS
    const filteredRealizations = realizationsRaw.filter(r => {
        const rowDate = new Date(r.sellDate);
        let matchesTime = true;
        if (currentFilterTimeframe === '30d') matchesTime = (rowDate >= d30);
        else if (currentFilterTimeframe === '60d') matchesTime = (rowDate >= d60);
        else if (currentFilterTimeframe === '90d') matchesTime = (rowDate >= d90);
        else if (currentFilterTimeframe === 'this_year') matchesTime = (rowDate.getFullYear() === now.getFullYear());
        else if (currentFilterTimeframe === 'last_year') matchesTime = (rowDate.getFullYear() === now.getFullYear() - 1);

        const isRetirement = (r.taxStatus === 'RETIREMENT_IRA');
        let matchesCategory = true;
        if (categoryFilter === 'TAXABLE') matchesCategory = !isRetirement;
        else if (categoryFilter === 'RETIREMENT') matchesCategory = isRetirement;

        const matchesAcc = matchesAccount(r.account, r.accountNumber || r.account_number, accountFilter);
        const matchesType = (typeFilter === 'ALL' || r.assetType === typeFilter);
        const matchesSearch = (searchVal === '' || r.symbol.toUpperCase().includes(searchVal) || (r.rawSymbol || '').toUpperCase().includes(searchVal));

        return matchesTime && matchesCategory && matchesAcc && matchesType && matchesSearch;
    });

    // 2. FILTER DIVIDEND & INTEREST RECORDS
    const filteredIncome = incomeRaw.filter(inc => {
        const rowDate = new Date(inc.date);
        let matchesTime = true;
        if (currentFilterTimeframe === '30d') matchesTime = (rowDate >= d30);
        else if (currentFilterTimeframe === '60d') matchesTime = (rowDate >= d60);
        else if (currentFilterTimeframe === '90d') matchesTime = (rowDate >= d90);
        else if (currentFilterTimeframe === 'this_year') matchesTime = (rowDate.getFullYear() === now.getFullYear());
        else if (currentFilterTimeframe === 'last_year') matchesTime = (rowDate.getFullYear() === now.getFullYear() - 1);

        const isRetirement = (inc.taxStatus === 'RETIREMENT_IRA');
        let matchesCategory = true;
        if (categoryFilter === 'TAXABLE') matchesCategory = !isRetirement;
        else if (categoryFilter === 'RETIREMENT') matchesCategory = isRetirement;

        const matchesAcc = matchesAccount(inc.account, inc.accountNumber || inc.account_number, accountFilter);
        const matchesSearch = (searchVal === '' || inc.symbol.toUpperCase().includes(searchVal));

        return matchesTime && matchesCategory && matchesAcc && matchesSearch;
    });

    // 3. STATUTORY IRS SCHEDULE D NETTING CALCULATION
    let totalCostBasis = 0.0;
    let totalProceeds = 0.0;
    let totalRealizedGain = 0.0;

    let taxableSTGains = 0.0;
    let taxableSTLosses = 0.0;
    let taxableLTGains = 0.0;
    let taxableLTLosses = 0.0;

    let retireST = 0.0;
    let retireLT = 0.0;
    let totalWashDisallowed = 0.0;

    filteredRealizations.forEach(r => {
        totalCostBasis += (r.costBasis || 0);
        totalProceeds += (r.proceeds || 0);
        const gain = (r.adjustedGain !== undefined) ? r.adjustedGain : r.realizedGain;
        totalRealizedGain += gain;

        if (r.isWashSale) {
            totalWashDisallowed += (r.disallowedLoss || 0.0);
        }

        if (r.taxStatus === 'RETIREMENT_IRA') {
            if (r.term === 'LONG_TERM') retireLT += gain;
            else retireST += gain;
        } else {
            if (r.term === 'LONG_TERM') {
                if (gain >= 0) taxableLTGains += gain;
                else taxableLTLosses += Math.abs(gain);
            } else {
                if (gain >= 0) taxableSTGains += gain;
                else taxableSTLosses += Math.abs(gain);
            }
        }
    });

    // Dividends aggregation
    let qualifiedDivs = 0.0;
    let ordinaryDivs = 0.0;
    let interestIncome = 0.0;
    let totalIncomeAmt = 0.0;

    filteredIncome.forEach(inc => {
        totalIncomeAmt += (inc.amount || 0);
        if (inc.taxStatus !== 'RETIREMENT_IRA') {
            if (inc.category === 'QUALIFIED_DIVIDEND') qualifiedDivs += inc.amount;
            else if (inc.category === 'INTEREST_INCOME') interestIncome += inc.amount;
            else ordinaryDivs += inc.amount;
        }
    });

    // Cross-Netting
    let netST = taxableSTGains - taxableSTLosses;
    let netLT = taxableLTGains - taxableLTLosses;

    // Apply prior carryforwards if current year
    let stCarryApplied = 0.0;
    let ltCarryApplied = 0.0;
    if (currentFilterTimeframe === 'this_year' && carryforwardsRaw) {
        const carryST = carryforwardsRaw.shortTerm || 0.0;
        const carryLT = carryforwardsRaw.longTerm || 0.0;
        if (netST > 0 && carryST > 0) {
            stCarryApplied = Math.min(netST, carryST);
            netST -= stCarryApplied;
        }
        if (netLT > 0 && carryLT > 0) {
            ltCarryApplied = Math.min(netLT, carryLT);
            netLT -= ltCarryApplied;
        }
    }

    // Cross-netting ST and LT
    if (netST > 0 && netLT < 0) {
        const offset = Math.min(netST, Math.abs(netLT));
        netST -= offset;
        netLT += offset;
    } else if (netLT > 0 && netST < 0) {
        const offset = Math.min(netLT, Math.abs(netST));
        netLT -= offset;
        netST += offset;
    }

    const totalNetCapitalGain = netST + netLT;
    const capitalGainsTax = (Math.max(0, netST) * 0.20) + (Math.max(0, netLT) * 0.15);
    const dividendTax = (qualifiedDivs * 0.15) + (ordinaryDivs * 0.20) + (interestIncome * 0.20);

    const isExclusivelyRetirement = (categoryFilter === 'RETIREMENT') || (accountFilter !== 'ALL' && filteredRealizations.length > 0 && filteredRealizations.every(r => r.taxStatus === 'RETIREMENT_IRA'));
    const statutoryTaxLiability = isExclusivelyRetirement ? 0.0 : Math.max(0.0, capitalGainsTax + dividendTax);

    // 4. RENDER TOP CARDS
    const taxableNetGains = (taxableSTGains - taxableSTLosses) + (taxableLTGains - taxableLTLosses);
    document.getElementById('statTaxableGains').textContent = formatCurrency(taxableNetGains);
    document.getElementById('statTaxableGains').className = 'val ' + (taxableNetGains >= 0 ? 'g' : 'r');
    document.getElementById('statTaxableBreakdown').textContent = `ST: ${formatCurrency(taxableSTGains - taxableSTLosses)} | LT: ${formatCurrency(taxableLTGains - taxableLTLosses)}`;

    document.getElementById('statEstTax').textContent = formatCurrency(statutoryTaxLiability);
    document.getElementById('statEstTax').className = 'val ' + (statutoryTaxLiability > 0 ? 'r' : '');
    document.getElementById('statTaxBreakdown').textContent = isExclusivelyRetirement 
        ? 'Tax-Exempt (IRA Account)' 
        : `Cap Gains: ${formatCurrency(capitalGainsTax)} | Div/Int: ${formatCurrency(dividendTax)}`;

    document.getElementById('statDividendIncome').textContent = formatCurrency(totalIncomeAmt);
    document.getElementById('statDivBreakdown').textContent = `Qualified: ${formatCurrency(qualifiedDivs)} | Ord/Int: ${formatCurrency(ordinaryDivs + interestIncome)}`;

    const totalCarryIn = (carryforwardsRaw.total || 0.0);
    document.getElementById('statCarryforward').textContent = formatCurrency(totalCarryIn);
    document.getElementById('statCarryBreakdown').textContent = totalCarryIn > 0 ? `Shielding ${formatCurrency(stCarryApplied + ltCarryApplied)} of 2026 Gains` : 'No prior unabsorbed losses';

    const retireTotal = retireST + retireLT;
    document.getElementById('statRetirementGains').textContent = formatCurrency(retireTotal);
    document.getElementById('statRetirementGains').className = 'val ' + (retireTotal >= 0 ? 'g' : 'r');

    // Wash sale banner updates
    document.getElementById('bannerWashAmount').textContent = formatCurrency(totalWashDisallowed);

    // 5. SORT & RENDER REALIZATIONS TABLE
    filteredRealizations.sort((a, b) => {
        let valA = a[currentSortCol] ?? '';
        let valB = b[currentSortCol] ?? '';
        if (typeof valA === 'number' && typeof valB === 'number') {
            return currentSortDir === 'asc' ? (valA - valB) : (valB - valA);
        }
        valA = String(valA).toUpperCase();
        valB = String(valB).toUpperCase();
        if (valA < valB) return currentSortDir === 'asc' ? -1 : 1;
        if (valA > valB) return currentSortDir === 'asc' ? 1 : -1;
        return 0;
    });

    const realizationBody = document.getElementById('taxRealizationBody');
    realizationBody.innerHTML = '';

    if (filteredRealizations.length > 0) {
        if (groupBy === 'NONE') {
            filteredRealizations.forEach(r => {
                realizationBody.appendChild(createRealizationRow(r));
            });
        } else {
            let groups = {};
            filteredRealizations.forEach(r => {
                let key = 'Other';
                if (groupBy === 'TICKER') key = r.symbol || 'Unknown';
                else if (groupBy === 'ACCOUNT') key = r.account || 'Unknown';
                else if (groupBy === 'MONTH') key = (r.sellDate || '').substring(0, 7) || 'Unknown';

                if (!groups[key]) {
                    groups[key] = { items: [], costBasis: 0, proceeds: 0, gain: 0, tax: 0 };
                }
                groups[key].items.push(r);
                groups[key].costBasis += (r.costBasis || 0);
                groups[key].proceeds += (r.proceeds || 0);
                groups[key].gain += ((r.adjustedGain !== undefined) ? r.adjustedGain : r.realizedGain);
                groups[key].tax += (r.estTax || 0);
            });

            Object.keys(groups).forEach(grpKey => {
                const grp = groups[grpKey];
                const safeKey = grpKey.replace(/[^a-zA-Z0-9]/g, '_');
                if (groupStates[safeKey] === undefined) groupStates[safeKey] = false;
                const isCollapsed = groupStates[safeKey];

                const formattedGain = (grp.gain >= 0 ? '+' : '') + '$' + grp.gain.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                const gainColor = grp.gain >= 0 ? 'var(--green)' : 'var(--red)';

                const hdrRow = document.createElement('tr');
                hdrRow.style.background = 'var(--bg3)';
                hdrRow.style.borderBottom = '1px solid var(--border)';
                hdrRow.style.cursor = 'pointer';
                hdrRow.onclick = function() { toggleTaxGroup(safeKey, hdrRow); };
                hdrRow.innerHTML = `<td colspan="6" style="padding:12px 18px; font-weight:800; font-family:'Outfit',sans-serif; font-size:13px; color:var(--text);">
                    <div style="display:flex; align-items:center; justify-content:space-between; width:100%;">
                        <div style="display:flex; align-items:center; gap:6px;">
                            <span class="material-symbols-outlined group-arrow" style="margin-right:6px; font-size:16px; color:var(--blue); vertical-align:middle;">${isCollapsed ? 'chevron_right' : 'expand_more'}</span>
                            <span class="material-symbols-outlined" style="font-size:16px; color:var(--blue);">folder</span>
                            <span style="font-weight:700;">${grpKey}</span>
                            <span style="font-size:11px; color:var(--muted); font-weight:600; background:rgba(255,255,255,0.06); padding:2px 8px; border-radius:10px; margin-left:6px;">${grp.items.length} trades</span>
                        </div>
                        <div class="hdr-summary" style="display:${isCollapsed ? 'inline-flex' : 'none'}; gap:14px; font-size:11px; font-family:'SFMono-Regular',Consolas,monospace; font-weight:700; align-items:center; margin-right:8px;">
                            <span>Proceeds: <strong style="color:var(--text);">$${grp.proceeds.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                            <span>Realized: <strong style="color:${gainColor};">${formattedGain}</strong></span>
                        </div>
                    </div>
                </td>`;
                realizationBody.appendChild(hdrRow);

                grp.items.forEach(r => {
                    const row = createRealizationRow(r);
                    row.classList.add('group-row-' + safeKey);
                    if (isCollapsed) row.style.display = 'none';
                    realizationBody.appendChild(row);
                });

                const subRow = document.createElement('tr');
                subRow.classList.add('group-row-' + safeKey);
                subRow.style.background = 'rgba(255,255,255,0.02)';
                subRow.style.borderBottom = '2px solid var(--border)';
                subRow.style.fontSize = '12px';
                subRow.style.fontWeight = '700';
                if (isCollapsed) subRow.style.display = 'none';
                subRow.innerHTML = `
                    <td colspan="3" style="padding:10px 12px; text-align:right; color:var(--muted); font-size:13px; font-weight:700;">Sub-total (${grpKey}):</td>
                    <td style="padding:10px 12px; text-align:right; font-family:'SFMono-Regular',Consolas,monospace;">
                        <div style="color:var(--text); font-weight:800; font-size:13px;"><span style="font-size:10px; color:var(--muted); font-weight:600; margin-right:4px;">PROCEEDS</span>$${grp.proceeds.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                        <div style="font-size:12px; font-weight:600; color:var(--muted); margin-top:2px;"><span style="font-size:10px; margin-right:4px;">BASIS</span>$${grp.costBasis.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                    </td>
                    <td style="padding:10px 12px; text-align:right; font-family:'SFMono-Regular',Consolas,monospace; color:${gainColor}; font-weight:900; font-size:14px;">
                        <div>${formattedGain}</div>
                        <div style="font-size:11px; color:var(--muted); margin-top:2px; font-weight:600;">Net Realized</div>
                    </td>
                    <td style="padding:10px 12px; text-align:right; font-family:'SFMono-Regular',Consolas,monospace; color:${grp.tax > 0 ? 'var(--red)' : 'var(--muted)'}; font-weight:800; font-size:13px;">
                        <div>$${grp.tax.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                        <div style="font-size:11px; color:var(--muted); margin-top:2px;">Est. Tax</div>
                    </td>
                `;
                realizationBody.appendChild(subRow);
            });
        }
    } else {
        realizationBody.innerHTML = `<tr>
            <td colspan="6" style="text-align:center; padding:40px; color:var(--muted);">
                <span class="material-symbols-outlined" style="font-size:32px; display:block; margin-bottom:8px; opacity:0.5;">info</span>
                No capital gains realizations found matching active filters.
            </td>
        </tr>`;
    }

    // 6. RENDER DIVIDENDS & INTEREST TABLE
    const divBody = document.getElementById('divIncomeBody');
    divBody.innerHTML = '';
    if (filteredIncome.length > 0) {
        filteredIncome.forEach(inc => {
            const row = document.createElement('tr');
            row.style.borderBottom = '1px solid var(--border)';
            row.style.fontSize = '12px';
            row.style.background = 'var(--bg1)';
            const isQualified = (inc.category === 'QUALIFIED_DIVIDEND');
            const catBadge = isQualified 
                ? '<span class="hbadge" style="background:rgba(34,197,94,0.15); color:var(--green); font-size:10px; font-weight:800;">QUALIFIED (15%)</span>'
                : (inc.category === 'INTEREST_INCOME' 
                    ? '<span class="hbadge" style="background:rgba(56,189,248,0.15); color:var(--blue); font-size:10px; font-weight:800;">INTEREST (20%)</span>' 
                    : '<span class="hbadge" style="background:rgba(234,179,8,0.15); color:var(--yellow); font-size:10px; font-weight:800;">ORDINARY (20%)</span>');
            
            row.innerHTML = `
                <td style="padding:10px 12px; color:var(--text); font-weight:600;">${inc.date}</td>
                <td style="padding:10px 12px;"><strong style="color:var(--text); font-family:'JetBrains Mono',monospace;">${inc.symbol || 'CASH'}</strong></td>
                <td style="padding:10px 12px; color:var(--muted);">${inc.account}</td>
                <td style="padding:10px 12px;">${catBadge}</td>
                <td style="padding:10px 12px; color:var(--muted); font-size:11px;">${inc.description || '--'}</td>
                <td style="padding:10px 12px; text-align:right; font-family:'SFMono-Regular',Consolas,monospace; font-weight:800; color:var(--green);">+${formatCurrency(inc.amount)}</td>
                <td style="padding:10px 12px; text-align:right; color:${inc.taxStatus === 'RETIREMENT_IRA' ? 'var(--green)' : 'var(--red)'}; font-weight:700;">
                    ${inc.taxStatus === 'RETIREMENT_IRA' ? 'Tax-Exempt' : formatCurrency(inc.estTax || 0)}
                </td>
            `;
            divBody.appendChild(row);
        });
    } else {
        divBody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:30px; color:var(--muted);">No dividend or interest records for current filters.</td></tr>`;
    }

    // Update Dividends Header Banner
    document.getElementById('divFilteredBadge').textContent = `${filteredIncome.length} Records`;
    document.getElementById('tblDivQualified').textContent = formatCurrency(qualifiedDivs);
    document.getElementById('tblDivOrdinary').textContent = formatCurrency(ordinaryDivs + interestIncome);
    document.getElementById('tblDivTax').textContent = formatCurrency(dividendTax);

    // 7. FILTER AUDIT LOG TRANSACTIONS
    const filteredTx = historyRaw.filter(tx => {
        const rowDate = new Date(tx.date);
        let matchesTime = true;
        if (currentFilterTimeframe === '30d') matchesTime = (rowDate >= d30);
        else if (currentFilterTimeframe === '60d') matchesTime = (rowDate >= d60);
        else if (currentFilterTimeframe === '90d') matchesTime = (rowDate >= d90);
        else if (currentFilterTimeframe === 'this_year') matchesTime = (rowDate.getFullYear() === now.getFullYear());
        else if (currentFilterTimeframe === 'last_year') matchesTime = (rowDate.getFullYear() === now.getFullYear() - 1);

        const acc = tx.account_nickname || tx.account_number || '';
        const matchesAcc = matchesAccount(acc, tx.account_number, accountFilter);
        const matchesType = (typeFilter === 'ALL' || (typeFilter === 'OPTION' && (tx.symbol || '').includes(' ')) || (typeFilter === 'EQUITY' && !(tx.symbol || '').includes(' ')));
        const matchesSearch = (searchVal === '' || (tx.symbol || '').toUpperCase().includes(searchVal) || (tx.description || '').toUpperCase().includes(searchVal));

        return matchesTime && matchesAcc && matchesType && matchesSearch;
    });

    filteredTx.sort((a, b) => {
        let valA = a[currentTxSortCol] ?? '';
        let valB = b[currentTxSortCol] ?? '';
        if (typeof valA === 'number' && typeof valB === 'number') {
            return currentTxSortDir === 'asc' ? (valA - valB) : (valB - valA);
        }
        valA = String(valA).toUpperCase();
        valB = String(valB).toUpperCase();
        if (valA < valB) return currentTxSortDir === 'asc' ? -1 : 1;
        if (valA > valB) return currentTxSortDir === 'asc' ? 1 : -1;
        return 0;
    });

    const allTxBody = document.getElementById('allTxBody');
    allTxBody.innerHTML = '';
    if (filteredTx.length > 0) {
        filteredTx.forEach(tx => {
            allTxBody.appendChild(createTransactionRow(tx));
        });
    } else {
        allTxBody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:30px; color:var(--muted);">No transactions matching active filters.</td></tr>`;
    }

    // 8. UPDATE RECONCILED STICKY ROWS AND TABLE BANNERS
    const periodLabel = currentFilterTimeframe === '30d' ? '30 Days'
        : (currentFilterTimeframe === '60d' ? '60 Days'
        : (currentFilterTimeframe === '90d' ? '90 Days'
        : (currentFilterTimeframe === 'this_year' ? 'This Year'
        : (currentFilterTimeframe === 'last_year' ? 'Last Year' : 'All'))));
    const accLabel = accountFilter === 'ALL' ? (categoryFilter === 'TAXABLE' ? 'Taxable Accounts' : (categoryFilter === 'RETIREMENT' ? 'Retirement Accounts' : 'All Accounts')) : accountFilter;

    document.getElementById('realizedFilteredBadge').textContent = `${filteredRealizations.length} Realizations`;
    document.getElementById('tblTotalBasis').textContent = formatCurrency(totalCostBasis);
    document.getElementById('tblTotalProceeds').textContent = formatCurrency(totalProceeds);
    document.getElementById('tblTotalGain').textContent = (totalRealizedGain >= 0 ? '+' : '') + formatCurrency(totalRealizedGain);
    document.getElementById('tblTotalGain').style.color = totalRealizedGain >= 0 ? 'var(--green)' : 'var(--red)';

    // KEY RECONCILIATION: Display True Netted Statutory Tax in the Banner and Sticky Top Row
    document.getElementById('tblTotalTax').textContent = formatCurrency(statutoryTaxLiability);
    document.getElementById('tblTotalTax').style.color = statutoryTaxLiability > 0 ? 'var(--red)' : 'var(--muted)';

    document.getElementById('topRowAccount').textContent = accLabel;
    document.getElementById('topRowPeriod').textContent = periodLabel;
    document.getElementById('topRowQty').innerHTML = `<div style="font-weight:700; font-size:13px; color:var(--text);">${filteredRealizations.length}</div><div style="font-size:11px; color:var(--muted); margin-top:2px;">Trades</div>`;
    document.getElementById('topRowCostBasis').innerHTML = `<div style="color:var(--text); font-weight:800; font-size:13px;"><span style="font-size:10px; color:var(--muted); font-weight:600; margin-right:4px;">PROCEEDS</span>${formatCurrency(totalProceeds)}</div><div style="font-size:12px; font-weight:600; color:var(--muted); margin-top:2px;"><span style="font-size:10px; margin-right:4px;">BASIS</span>${formatCurrency(totalCostBasis)}</div>`;
    document.getElementById('topRowRealizedGain').innerHTML = `<div style="font-family:'SFMono-Regular',Consolas,monospace; font-weight:900; font-size:14px; color:${totalRealizedGain >= 0 ? 'var(--green)' : 'var(--red)'};">${(totalRealizedGain >= 0 ? '+' : '') + formatCurrency(totalRealizedGain)}</div><div style="font-size:11px; color:var(--muted); margin-top:2px; font-weight:600;">Taxable Gains</div>`;
    document.getElementById('topRowEstTax').innerHTML = `<div style="font-family:'SFMono-Regular',Consolas,monospace; font-weight:800; font-size:13px; color:${statutoryTaxLiability > 0 ? 'var(--red)' : 'var(--muted)'};">${formatCurrency(statutoryTaxLiability)}</div><div style="font-size:11px; color:var(--muted); margin-top:2px;">Liability</div>`;

    // Audit Log Summary Banner
    let totalTxCashFlow = 0.0;
    filteredTx.forEach(tx => { totalTxCashFlow += (tx.amount || 0); });

    // Option cash vs tax reconciliation badge in Schedule D banner
    const optBadge = document.getElementById('optionReconcileBadge');
    const optText = document.getElementById('optionReconcileText');
    if (optBadge && optText) {
        if (typeFilter === 'OPTION') {
            const openAmt = totalTxCashFlow - totalRealizedGain;
            if (Math.abs(openAmt) > 0.01) {
                optText.innerHTML = `Active Open Options: <strong>+${formatCurrency(openAmt)}</strong> (unrealized under IRS Pub 550 until 09/25 expiry) &bull; Settled Cash Flow: <strong>+${formatCurrency(totalTxCashFlow)}</strong>`;
                optBadge.style.display = 'inline-flex';
            } else {
                optBadge.style.display = 'none';
            }
        } else {
            optBadge.style.display = 'none';
        }
    }

    document.getElementById('txFilteredBadge').textContent = `${filteredTx.length} Transactions`;
    document.getElementById('tblTxNetFlow').textContent = (totalTxCashFlow >= 0 ? '+' : '') + formatCurrency(totalTxCashFlow);
    document.getElementById('tblTxNetFlow').style.color = totalTxCashFlow >= 0 ? 'var(--green)' : 'var(--red)';

    document.getElementById('topTxRowSymbol').textContent = searchVal || 'All Tickers';
    document.getElementById('topTxRowAccount').textContent = accLabel;
    document.getElementById('topTxRowType').textContent = typeFilter === 'ALL' ? 'All Types' : typeFilter;
    document.getElementById('topTxRowCashFlow').textContent = (totalTxCashFlow >= 0 ? '+' : '') + formatCurrency(totalTxCashFlow);
    document.getElementById('topTxRowCashFlow').style.color = totalTxCashFlow >= 0 ? 'var(--green)' : 'var(--red)';

    const label = document.getElementById('activeCountsText');
    if (currentActiveView === 'realized') label.textContent = `Showing ${filteredRealizations.length} realizations`;
    else if (currentActiveView === 'dividends') label.textContent = `Showing ${filteredIncome.length} dividend/interest records`;
    else label.textContent = `Showing ${filteredTx.length} transactions`;
}

function createRealizationRow(r) {
    const isCusip = /^[A-Z0-9]{9}$/.test((r.symbol || '').trim());
    const badgeType = isCusip ? 'CUSIP CODE' : r.assetType;
    const badgeColor = isCusip ? 'var(--yellow)' : (r.assetType === 'OPTION' ? 'var(--purple)' : 'var(--blue)');
    const badgeBg = isCusip ? 'rgba(210,153,34,0.15)' : (r.assetType === 'OPTION' ? 'rgba(168,85,247,0.15)' : 'rgba(56,189,248,0.15)');
    
    const descContent = r.description || '';
    const popoverContent = `
        <div class="desc-popover-wrapper" style="position:relative; display:inline-block; margin-left:4px;">
            <span class="material-symbols-outlined desc-info-icon" style="font-size:15px; color:var(--blue); cursor:pointer; vertical-align:middle;">info</span>
            <div class="desc-popover-box" style="display:none; position:absolute; left:0; top:22px; background:var(--bg3); border:1px solid var(--border); padding:10px 12px; border-radius:8px; font-size:11px; color:var(--text); width:240px; box-shadow:0 8px 24px rgba(0,0,0,0.5); z-index:9999; white-space:normal; line-height:1.4;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; border-bottom:1px solid var(--border); padding-bottom:4px;">
                    <span style="font-weight:700; color:var(--blue);">Asset Details</span>
                    <span class="hbadge" style="font-size:9px; background:${badgeBg}; color:${badgeColor}; font-weight:700;">${badgeType}</span>
                </div>
                ${descContent ? `<div style="color:var(--text);">${descContent}</div>` : `<div style="color:var(--muted);">${r.symbol} position</div>`}
            </div>
        </div>
    `;

    const termBadge = r.term === 'LONG_TERM' 
        ? '<span class="rb" style="background:rgba(34,197,94,0.15); color:var(--green); font-size:10px; font-weight:800; padding:1px 5px; border-radius:4px;" title="Long-Term (Held > 1 year)">LT</span>' 
        : '<span class="rb" style="background:rgba(210,153,34,0.15); color:var(--yellow); font-size:10px; font-weight:800; padding:1px 5px; border-radius:4px;" title="Short-Term (Held ≤ 1 year)">ST</span>';

    const washBadge = r.isWashSale 
        ? `<span class="hbadge" style="background:rgba(234,179,8,0.2); color:var(--yellow); font-size:9px; font-weight:800; padding:1px 5px; border-radius:4px; margin-left:4px;" title="IRC § 1091 Wash Sale: Disallowed loss $${(r.disallowedLoss || 0).toFixed(2)} added to replacement cost basis">WASH</span>`
        : '';

    const isRetirement = (r.taxStatus === 'RETIREMENT_IRA');
    const taxCellContent = isRetirement 
        ? '<span style="color:var(--muted); font-size:13px; font-weight:700;">$0.00</span>'
        : (r.isWashSale 
            ? '<span style="color:var(--yellow); font-size:13px; font-weight:700;">$0.00</span>'
            : `$${(r.estTax || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`);

    const taxSubtext = isRetirement
        ? '<span style="color:var(--green); font-size:11px; font-weight:600;">Tax-Sheltered (IRA)</span>'
        : (r.isWashSale
            ? '<span style="color:var(--yellow); font-size:11px; font-weight:600;">Wash Loss Deferred</span>'
            : (r.term === 'LONG_TERM' 
                ? '<span style="color:var(--muted); font-size:11px;">15% Pref. Rate</span>' 
                : '<span style="color:var(--muted); font-size:11px;">20% Ord. Rate</span>'));

    const gainSign = r.realizedGain >= 0 ? '+' : '';
    const gainFormatted = `${gainSign}$${r.realizedGain.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

    const row = document.createElement('tr');
    row.style.borderBottom = '1px solid var(--border)';
    row.style.fontSize = '13px';
    row.style.background = 'var(--bg1)';
    row.innerHTML = `
        <!-- COL 1: DATES (SOLD / ACQUIRED) -->
        <td style="padding:10px 12px; white-space:nowrap;">
            <div style="font-family:'SFMono-Regular',Consolas,monospace; font-weight:700; font-size:13px; color:var(--text); display:flex; align-items:center; gap:6px;">
                <span style="font-size:10px; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Sold</span>
                <span>${r.sellDate}</span>
            </div>
            <div style="display:flex; align-items:center; gap:5px; margin-top:4px; font-size:12px;">
                <span style="font-size:10px; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Acq</span>
                <span style="font-family:'SFMono-Regular',Consolas,monospace; color:var(--text); font-weight:600;">${r.buyDate}</span>
                ${termBadge}
                <span style="font-size:11px; color:var(--muted);">(${r.holdingDays || 0}d)</span>
            </div>
        </td>

        <!-- COL 2: SECURITY & ACCOUNT -->
        <td style="padding:10px 12px;">
            <div style="display:flex; align-items:center; gap:4px; flex-wrap:nowrap;">
                <strong style="color:var(--text); font-size:14px; font-family:'JetBrains Mono',monospace;">${r.symbol}</strong>
                ${popoverContent}
                ${washBadge}
            </div>
            <div style="display:flex; align-items:center; gap:4px; margin-top:4px; font-size:12px; color:var(--muted);" title="${r.account}">
                <span class="material-symbols-outlined" style="font-size:14px; color:var(--blue);">${isRetirement ? 'verified_user' : 'account_balance'}</span>
                <span style="color:var(--text); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px;">${r.account}</span>
                ${isRetirement ? '<span class="hbadge" style="font-size:9px; background:rgba(34,197,94,0.15); color:var(--green); padding:1px 4px; font-weight:800;">IRA</span>' : ''}
            </div>
        </td>

        <!-- COL 3: QUANTITY -->
        <td style="padding:10px 12px; text-align:right;">
            <div style="font-family:'SFMono-Regular',Consolas,monospace; font-weight:700; font-size:13px; color:var(--text);">
                ${r.qty}
            </div>
            <div style="font-size:11px; color:var(--muted); margin-top:4px; text-transform:uppercase; font-weight:600;">
                ${r.assetType === 'OPTION' ? 'Contracts' : 'Shares'}
            </div>
        </td>

        <!-- COL 4: PROCEEDS & BASIS -->
        <td style="padding:10px 12px; text-align:right; font-family:'SFMono-Regular',Consolas,monospace;">
            <div style="color:var(--text); font-weight:800; font-size:13px;">
                <span style="font-size:10px; color:var(--muted); font-weight:600; margin-right:4px;">PROCEEDS</span>$${r.proceeds.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}
            </div>
            <div style="font-weight:600; font-size:12px; color:var(--muted); margin-top:4px;">
                <span style="font-size:10px; color:var(--muted); margin-right:4px;">BASIS</span>$${r.costBasis.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}
            </div>
        </td>

        <!-- COL 5: REALIZED P&L -->
        <td style="padding:10px 12px; text-align:right; font-family:'SFMono-Regular',Consolas,monospace;">
            <div style="font-weight:900; font-size:14px;" class="${r.realizedGain >= 0 ? 'g' : 'r'}">
                ${gainFormatted}
            </div>
            <div style="font-size:11px; margin-top:4px; font-weight:600;">
                ${r.term === 'LONG_TERM' ? '<span style="color:var(--green);">Long-Term</span>' : '<span style="color:var(--yellow);">Short-Term</span>'}
            </div>
        </td>

        <!-- COL 6: EST. TAX -->
        <td style="padding:10px 12px; text-align:right; font-family:'SFMono-Regular',Consolas,monospace;">
            <div style="font-weight:800; font-size:13px; color:${!isRetirement && r.estTax > 0 ? 'var(--red)' : 'var(--muted)'};">
                ${taxCellContent}
            </div>
            <div style="font-size:11px; margin-top:4px;">
                ${taxSubtext}
            </div>
        </td>
    `;
    return row;
}

function createTransactionRow(tx) {
    const item = tx.transfer_items ? tx.transfer_items[0] : null;
    const assetType = item ? (item.asset_type || '').toUpperCase() : '';
    const isOption = assetType === 'OPTION' || (tx.symbol && /^[A-Z0-9]+\s*\d{6}[CP]\d{8}$/.test(tx.symbol));

    const row = document.createElement('tr');
    row.style.borderBottom = '1px solid var(--border)';
    row.style.fontSize = '12px';
    row.style.background = 'var(--bg1)';
    row.innerHTML = `
        <td style="padding:12px; color:var(--text); font-weight:600;">${tx.date}</td>
        <td style="padding:12px;">
            <strong style="color:var(--text); font-size:13px; font-family:'JetBrains Mono',monospace;">${tx.symbol || '--'}</strong>
        </td>
        <td style="padding:12px; color:var(--muted);">${tx.account_nickname || tx.account_number || '--'}</td>
        <td style="padding:12px;"><span class="hbadge" style="background:rgba(56,189,248,0.15); color:var(--blue); font-size:10px;">${tx.type || 'TRADE'}</span></td>
        <td style="padding:12px; color:var(--muted); font-size:11px;">${tx.description || '--'}</td>
        <td style="padding:12px; text-align:right; color:var(--text); font-family:'SFMono-Regular',Consolas,monospace;">${tx.price ? formatCurrency(tx.price) : '--'}</td>
        <td style="padding:12px; text-align:right; font-family:'SFMono-Regular',Consolas,monospace; font-weight:800; color:${tx.amount >= 0 ? 'var(--green)' : 'var(--red)'};">
            ${(tx.amount >= 0 ? '+' : '') + formatCurrency(tx.amount || 0)}
        </td>
    `;
    return row;
}

// Initial filter execution on DOM load with deep linking support
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const accountParam = urlParams.get('account');
    const tabParam = urlParams.get('tab');
    const searchParam = urlParams.get('search') || urlParams.get('symbol') || urlParams.get('q');
    const timeframeParam = urlParams.get('timeframe') || urlParams.get('period');

    if (tabParam) {
        switchTaxView(tabParam);
    }

    if (accountParam) {
        const accSelect = document.getElementById('filterAccount');
        const target = accountParam.trim().toUpperCase();
        let matched = false;
        if (accSelect) {
            // Find option matching account nickname, value, data-number, data-id, or text
            for (let opt of accSelect.options) {
                const optVal = opt.value.toUpperCase();
                const optText = opt.text.toUpperCase();
                const optNum = (opt.getAttribute('data-number') || '').toUpperCase();
                const optId = (opt.getAttribute('data-id') || '').toUpperCase();

                if (optVal === target || optNum === target || optId === target || optText.includes(target) || target.includes(optVal)) {
                    accSelect.value = opt.value;
                    matched = true;
                    break;
                }
            }

            // If not found directly in options, add it dynamically and select it
            if (!matched) {
                const newOpt = document.createElement('option');
                newOpt.value = accountParam;
                newOpt.text = `${accountParam}`;
                newOpt.selected = true;
                accSelect.appendChild(newOpt);
                accSelect.value = accountParam;
            }
        }
    }

    if (searchParam) {
        const sInput = document.getElementById('filterSearch');
        if (sInput) sInput.value = searchParam;
    }

    if (timeframeParam) {
        const fBtn = document.querySelector(`.tax-seg-track .fbtn[onclick*="${timeframeParam}"]`);
        if (fBtn) {
            setFilterTimeframe(timeframeParam, fBtn);
        }
    }

    applyTaxFilters();
});
