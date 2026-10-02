function toggleOptionSubItem(subId, rowEl) {
    const rows = document.getElementsByClassName(subId);
    const icon = rowEl ? rowEl.querySelector('.sub-toggle-icon') : null;
    let isHidden = false;

    for (let r of rows) {
        if (r.style.display === 'none') {
            r.style.display = 'table-row';
            isHidden = false;
        } else {
            r.style.display = 'none';
            isHidden = true;
        }
    }

    if (icon) {
        icon.textContent = isHidden ? 'chevron_right' : 'expand_more';
    }
}

function switchPortSubView(mode) {
    const subAcc = document.getElementById('subViewAccounts');
    const subCal = document.getElementById('subViewCalendar');
    const subHis = document.getElementById('subViewHistory');
    const subOrd = document.getElementById('subViewOrders');
    const subOpt = document.getElementById('subViewOptions');

    const btnAcc = document.getElementById('vtabAccounts');
    const btnCal = document.getElementById('vtabCalendar');
    const btnHis = document.getElementById('vtabHistory');
    const btnOrd = document.getElementById('vtabOrders');
    const btnOpt = document.getElementById('vtabOptions');

    const isHoldings = mode === 'holdings' || mode === 'accounts';

    if (subAcc) subAcc.style.display = isHoldings ? 'block' : 'none';
    if (subCal) subCal.style.display = mode === 'calendar' ? 'block' : 'none';
    if (subHis) subHis.style.display = mode === 'history' ? 'block' : 'none';
    if (subOrd) subOrd.style.display = mode === 'orders' ? 'block' : 'none';
    if (subOpt) subOpt.style.display = mode === 'options' ? 'block' : 'none';

    if (btnAcc) btnAcc.classList.toggle('active', isHoldings);
    if (btnCal) btnCal.classList.toggle('active', mode === 'calendar');
    if (btnHis) btnHis.classList.toggle('active', mode === 'history');
    if (btnOrd) btnOrd.classList.toggle('active', mode === 'orders');
    if (btnOpt) btnOpt.classList.toggle('active', mode === 'options');

    if (mode === 'calendar') {
        loadPortfolioCalendarEvents();
    } else if (mode === 'history') {
        loadPortfolioHistory(currentHistDays || 30, true);
    } else if (mode === 'orders') {
        loadOpenOrders();
    }
}

let currentOptionsStatusFilter = 'ALL';

function setOptionsFilter(filterType) {
    currentOptionsStatusFilter = filterType;
    document.querySelectorAll('.opt-pill-filter').forEach(btn => btn.classList.remove('active'));
    
    if (filterType === 'ALL') {
        const b = document.getElementById('filterOptAll');
        if (b) b.classList.add('active');
    } else if (filterType === 'PROFIT_ROUTE') {
        const b = document.getElementById('filterOptProfit');
        if (b) b.classList.add('active');
    } else if (filterType === 'AT_RISK') {
        const b = document.getElementById('filterOptRisk');
        if (b) b.classList.add('active');
    } else if (filterType === 'CALL') {
        const b = document.getElementById('filterOptCalls');
        if (b) b.classList.add('active');
    } else if (filterType === 'PUT') {
        const b = document.getElementById('filterOptPuts');
        if (b) b.classList.add('active');
    }
    filterOpenOptions();
}

function filterOpenOptions() {
    const searchInput = document.getElementById('optSearchInput');
    const term = searchInput ? searchInput.value.toLowerCase().trim() : '';
    const cards = document.querySelectorAll('.opt-card');

    cards.forEach(card => {
        const sym = (card.getAttribute('data-symbol') || '').toLowerCase();
        const strat = (card.getAttribute('data-strategy') || '').toUpperCase();
        const stat = (card.getAttribute('data-status') || '').toUpperCase();
        const acc = (card.getAttribute('data-account') || '').toLowerCase();
        const text = card.textContent.toLowerCase();

        // 1. Check status/strategy filter
        let passesFilter = true;
        if (currentOptionsStatusFilter === 'PROFIT_ROUTE') {
            passesFilter = (stat === 'PROFIT_ROUTE' || stat === 'PROFITABLE');
        } else if (currentOptionsStatusFilter === 'AT_RISK') {
            passesFilter = (stat === 'AT_RISK');
        } else if (currentOptionsStatusFilter === 'CALL') {
            passesFilter = (strat === 'CALL');
        } else if (currentOptionsStatusFilter === 'PUT') {
            passesFilter = (strat === 'PUT');
        }

        // 2. Check search term
        let passesSearch = true;
        if (term !== '') {
            const termNorm = term.replace(/[\.\/\s]/g, '');
            const symNorm = sym.replace(/[\.\/\s]/g, '');
            passesSearch = sym.includes(term) || (termNorm !== '' && symNorm.includes(termNorm)) || acc.includes(term) || text.includes(term);
        }

        if (passesFilter && passesSearch) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

async function forceRefreshPortfolioData() {
    try {
        await fetch('/api/config/cache/clear', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: 'all' }),
        });
    } catch(e) {}
    window.location.reload();
}

let historyRawTransactions = [];
let currentHistSortCol = 'date';
let currentHistSortDir = 'desc';
let currentHistTypeFilter = 'ALL';
let currentHistAccountFilter = 'ALL';
let currentHistCashFlowFilter = 'ALL';
let currentHistSearchTerm = '';
let histCurrentPage = 1;
let histPageSize = 50;

let currentHistDays = 30;

let isLoadingPortfolioHistory = false;
async function loadPortfolioHistory(days = 30, force = false) {
    if (isLoadingPortfolioHistory) return;
    isLoadingPortfolioHistory = true;

    if (!force && currentHistDays === days && days !== 'ALL') {
        days = 'ALL';
    }
    currentHistDays = days;
    const tbody = document.getElementById('histTableBody');
    if (!tbody) {
        isLoadingPortfolioHistory = false;
        return;
    }

    if (typeof showGlobalProgress === 'function') showGlobalProgress();

    // Toggle active state on day selector buttons
    document.querySelectorAll('#subViewHistory .seg-btn, #subViewHistory .btn-period, #subViewHistory .hbtn').forEach(b => {
        if (b.id && b.id.startsWith('btnHist')) {
            b.classList.remove('active');
        }
    });
    const activeBtnId = days === 'ALL' ? 'btnHistALL' : `btnHist${days}`;
    const activeBtn = document.getElementById(activeBtnId);
    if (activeBtn) {
        activeBtn.classList.add('active');
    }

    // Inline loading state
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:36px 16px;"><div class="ui-loading-box"><span class="material-symbols-outlined spinner-icon">progress_activity</span><span>Retrieving transaction activity & cash ledger from broker...</span></div></td></tr>`;

    try {
        const daysParam = days === 'ALL' ? 'ALL' : days;
        const url = force ? `/api/broker/history/aggregated?days=${daysParam}&force=1` : `/api/broker/history/aggregated?days=${daysParam}`;
        const res = await fetch(url);
        if (!res.ok) {
            throw new Error(`HTTP ${res.status} (${res.statusText || 'Server Error'})`);
        }
        const json = await res.json();

        if (json.status === 'success' && json.data) {
            const data = json.data;
            historyRawTransactions = data.transactions || [];

            populateHistAccountDropdown(historyRawTransactions);
            updateHistSortIndicators();
            applyHistoryFiltersAndSort();
        }
    } catch(e) {
        console.error('Failed to load portfolio history:', e);
        if (tbody) tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:20px; color:var(--red);"><span class="material-symbols-outlined" style="font-size:18px; vertical-align:middle; margin-right:4px;">error</span> Failed to load transaction history: ${e.message}</td></tr>`;
    } finally {
        isLoadingPortfolioHistory = false;
        if (typeof hideGlobalProgress === 'function') hideGlobalProgress();
    }
}

let isLoadingOpenOrders = false;
async function loadOpenOrders(force = false) {
    if (isLoadingOpenOrders) return;
    isLoadingOpenOrders = true;

    const tbody = document.getElementById('ordersTableBody');
    if (!tbody) {
        isLoadingOpenOrders = false;
        return;
    }

    if (typeof showGlobalProgress === 'function') showGlobalProgress();
    tbody.innerHTML = '<tr><td colspan="7" class="orders-table-loader" style="text-align:center; padding:32px;"><span class="material-symbols-outlined spin" style="font-size:22px; vertical-align:middle; color:var(--blue); margin-right:4px;">sync</span> Fetching open orders from broker...</td></tr>';

    try {
        const url = force ? '/api/broker/orders/aggregated?force=true' : '/api/broker/orders/aggregated';
        const res = await fetch(url);
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }
        const json = await res.json();
        if (json.status === 'success' && Array.isArray(json.data)) {
            const orders = json.data;
            if (orders.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="orders-table-empty" style="text-align:center; padding:24px; color:var(--muted);"><span class="material-symbols-outlined" style="font-size:20px; vertical-align:middle; margin-right:4px;">check_circle</span> No active working orders found across your accounts.</td></tr>';
                return;
            }

            tbody.innerHTML = orders.map(o => {
                const legDetails = (o.legs || []).map(l => {
                    return `<strong>${l.symbol}</strong> (${l.instruction} x${l.quantity})`;
                }).join('<br>');

                const instruction = o.legs && o.legs[0] ? o.legs[0].instruction : 'TRADE';

                return `
                    <tr>
                        <td class="order-leg-id">${o.order_id || '—'}</td>
                        <td>${o.broker_nickname || 'Schwab'}</td>
                        <td>${legDetails}</td>
                        <td class="order-leg-badge">${instruction}</td>
                        <td class="order-leg-id">$${(o.price || 0).toFixed(2)}</td>
                        <td>${o.filled_quantity} / ${o.quantity}</td>
                        <td><span class="orders-status-badge">${o.status}</span></td>
                    </tr>
                `;
            }).join('');
        } else {
            throw new Error(json.message || 'Invalid response');
        }
    } catch(e) {
        console.error('Failed to load open orders:', e);
        const tbody = document.querySelector('#openOrdersTable tbody');
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="7" class="orders-table-loader" style="text-align:center; padding:20px; color:var(--red);"><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">warning</span> Failed to load open orders: ${e.message}</td></tr>`;
        }
    } finally {
        isLoadingOpenOrders = false;
        if (typeof hideGlobalProgress === 'function') hideGlobalProgress();
    }
}

function getTxAccountName(tx) {
    return (tx.account_nickname || tx.account_number || 'Unknown').trim();
}

function getAccountCategory(accName) {
    const upper = (accName || '').toUpperCase();
    if (upper.includes('IRA') || upper.includes('SIP') || upper.includes('PCRA') || upper.includes('401') || upper.includes('ROTH') || upper.includes('SEP')) {
        return 'RETIREMENT';
    }
    return 'TAXABLE';
}

function getTxCategory(tx) {
    if (tx.is_canceled || (tx.status && ['INVALID', 'CANCELED', 'VOID', 'REJECTED'].includes(tx.status.toUpperCase()))) {
        return 'CANCELED';
    }
    if (tx.category) {
        return tx.category;
    }
    const rawType = (tx.type || '').toUpperCase();
    const sym = ((tx.symbol || '') + ' ' + (tx.raw_symbol || '')).toUpperCase();
    const desc = (tx.description || '').toUpperCase();
    const action = (tx.action || '').toUpperCase();

    // 1. Check for options
    let isOption = rawType.includes('OPTION');
    if (!isOption && tx.transfer_items && Array.isArray(tx.transfer_items)) {
        isOption = tx.transfer_items.some(it => (it.asset_type || '').toUpperCase() === 'OPTION');
    }
    if (!isOption) {
        if (/[0-9]{6}[CP][0-9]{8}/.test(sym) || /\b\d{1,2}\/\d{1,2}\/\d{2,4}\s+\d+(\.\d+)?\s+[CP]\b/i.test(sym) || /\b(CALL|PUT)\b/i.test(desc)) {
            isOption = true;
        }
    }
    if (isOption) return 'OPTION';

    // 2. Check for Dividends
    if (rawType.includes('DIVIDEND') || action.includes('DIVIDEND') || desc.includes('QUALIFIED DIVIDEND') || desc.includes('ORDINARY DIVIDEND') || desc.includes('DIVIDEND ON')) {
        if (sym.trim() === 'INT' || desc.includes('BANK INT') || desc.includes('SCHWAB1 INT') || desc.includes('CREDIT INT')) {
            return 'INTEREST';
        }
        return 'DIVIDEND';
    }

    // 3. Check for Interest
    if (rawType.includes('INTEREST') || sym.trim() === 'INT' || desc.includes('BANK INT') || desc.includes('SCHWAB1 INT') || desc.includes('CREDIT INT') || desc.includes('INTEREST')) {
        return 'INTEREST';
    }

    // 4. Check for Fees
    if (rawType.includes('FEE') || sym.trim() === 'SEC' || desc.includes('SEC FEE') || desc.includes('ADR FEE') || desc.includes('FEE ADJ') || action.includes('FEE')) {
        return 'FEE';
    }

    // 5. Check for Transfers / Journals
    if (rawType.includes('JOURNAL') || rawType.includes('TRANSFER') || rawType.includes('TRF') || desc.includes('TRANSFER') || desc.includes('JOURNAL') || desc.includes('TRF FUNDS')) {
        return 'JOURNAL';
    }

    // 6. Regular trades
    if (rawType.includes('TRADE') || rawType.includes('BUY') || rawType.includes('SELL')) {
        return 'TRADE';
    }

    return 'OTHER';
}

function getBadgeConfig(cat, tx) {
    const rawStatus = (tx.status || '').toUpperCase();
    const isCanceled = tx.is_canceled || cat === 'CANCELED' || ['INVALID', 'CANCELED', 'VOID', 'REJECTED'].includes(rawStatus);
    if (isCanceled) {
        let label = 'CANCELED';
        if (rawStatus && ['INVALID', 'CANCELED', 'VOID', 'REJECTED'].includes(rawStatus)) {
            label = rawStatus;
        }
        return {
            label: label,
            bg: 'rgba(239, 68, 68, 0.18)',
            color: 'var(--red, #ef4444)'
        };
    }
    const rawAction = (tx.action || '').toUpperCase();
    switch (cat) {
        case 'CANCELED':
            return {
                label: 'CANCELED',
                bg: 'rgba(239, 68, 68, 0.18)',
                color: 'var(--red, #ef4444)'
            };
        case 'OPTION':
            return {
                label: rawAction ? rawAction.replace('ASSIGNMENT', 'ASSIGNED') : 'OPTION',
                bg: 'rgba(188, 140, 255, 0.18)',
                color: 'var(--purple, #bc8cff)'
            };
        case 'TRADE':
            const isBuy = (Number(tx.amount) || 0) < 0;
            return {
                label: isBuy ? 'STOCK BUY' : 'STOCK SELL',
                bg: isBuy ? 'rgba(56, 189, 248, 0.18)' : 'rgba(34, 197, 94, 0.18)',
                color: isBuy ? '#38bdf8' : 'var(--green, #22c55e)'
            };
        case 'DIVIDEND':
            return {
                label: rawAction ? rawAction.replace('QUALIFIED DIVIDEND', 'QUAL DIV') : 'DIVIDEND',
                bg: 'rgba(34, 197, 94, 0.18)',
                color: 'var(--green, #22c55e)'
            };
        case 'INTEREST':
            return {
                label: 'INTEREST',
                bg: 'rgba(56, 189, 248, 0.18)',
                color: '#38bdf8'
            };
        case 'JOURNAL':
            return {
                label: 'TRANSFER',
                bg: 'rgba(156, 163, 175, 0.18)',
                color: '#9ca3af'
            };
        case 'FEE':
            return {
                label: 'FEE / ADJ',
                bg: 'rgba(251, 191, 36, 0.18)',
                color: 'var(--yellow, #fbbf24)'
            };
        default:
            return {
                label: cat,
                bg: 'rgba(156, 163, 175, 0.15)',
                color: 'var(--muted)'
            };
    }
}

function getTxDisplayDescription(tx) {
    if (tx.display_main !== undefined && tx.display_detail !== undefined) {
        return { main: tx.display_main, detail: tx.display_detail };
    }
    let mainDesc = (tx.description || '').trim();
    let detail = '';

    if (tx.transfer_items && Array.isArray(tx.transfer_items)) {
        const item = tx.transfer_items.find(i => i.asset_type === 'OPTION' || i.asset_type === 'EQUITY') || tx.transfer_items[0];
        if (item) {
            if (item.description && item.description !== 'USD currency' && item.description !== mainDesc) {
                if (!mainDesc || mainDesc === 'USD currency') {
                    mainDesc = item.description;
                } else {
                    detail = item.description;
                }
            }
            const parts = [];
            if (item.position_effect) {
                parts.push(`[${item.position_effect}]`);
            }
            if (item.amount && item.asset_type !== 'CURRENCY') {
                const qty = Math.abs(item.amount);
                const action = item.amount > 0 ? 'Bought' : 'Sold';
                const price = item.price ? `@ $${Number(item.price).toFixed(2)}` : '';
                parts.push(`${action} ${qty} ${price}`.trim());
            }
            if (parts.length > 0) {
                detail = detail ? `${detail} • ${parts.join(' ')}` : parts.join(' ');
            }
        }
    }

    if (!mainDesc || mainDesc === 'USD currency') {
        mainDesc = tx.action || tx.type || '—';
    }

    return { main: mainDesc, detail: detail };
}

function populateHistAccountDropdown(transactions) {
    const optGroup = document.getElementById('histAccountOptGroup');
    if (!optGroup) return;

    const accCounts = {};
    (transactions || []).forEach(tx => {
        const acc = getTxAccountName(tx);
        accCounts[acc] = (accCounts[acc] || 0) + 1;
    });

    const sortedAccounts = Object.keys(accCounts).sort((a, b) => a.localeCompare(b));
    optGroup.innerHTML = sortedAccounts.map(acc => {
        const cat = getAccountCategory(acc);
        const tag = cat === 'TAXABLE' ? '[Taxable]' : '[Retirement]';
        return `<option value="${acc}">${tag} ${acc} (${accCounts[acc]} tx)</option>`;
    }).join('');

    const select = document.getElementById('histAccountSelect');
    if (select && currentHistAccountFilter) {
        select.value = currentHistAccountFilter;
    }
}

function sortHistBy(col) {
    if (currentHistSortCol === col) {
        currentHistSortDir = currentHistSortDir === 'asc' ? 'desc' : 'asc';
    } else {
        currentHistSortCol = col;
        currentHistSortDir = (col === 'date' || col === 'amount') ? 'desc' : 'asc';
    }
    histCurrentPage = 1;
    updateHistSortIndicators();
    applyHistoryFiltersAndSort();
}

function updateHistSortIndicators() {
    const cols = ['date', 'account', 'type', 'symbol', 'description', 'amount'];
    cols.forEach(col => {
        const el = document.getElementById(`sort_hist_${col}`);
        if (!el) return;
        if (col === currentHistSortCol) {
            el.textContent = currentHistSortDir === 'asc' ? '▲' : '▼';
            el.style.color = 'var(--blue, #58a6ff)';
            el.style.fontWeight = '800';
        } else {
            el.textContent = '↕';
            el.style.color = 'var(--muted)';
            el.style.fontWeight = 'normal';
        }
    });
}

function setHistTypeFilter(type) {
    if (currentHistTypeFilter === type && type !== 'ALL') {
        type = 'ALL';
    }
    currentHistTypeFilter = type;
    const select = document.getElementById('histTypeSelect');
    if (select) select.value = type;
    updateHistTypeChips(type);
    histCurrentPage = 1;
    applyHistoryFiltersAndSort();
}

function onHistTypeSelectChange() {
    const select = document.getElementById('histTypeSelect');
    if (select) {
        currentHistTypeFilter = select.value;
        updateHistTypeChips(select.value);
        histCurrentPage = 1;
        applyHistoryFiltersAndSort();
    }
}

function updateHistTypeChips(activeType) {
    const chipMap = {
        'ALL': 'histChipAll',
        'TRADE': 'histChipTrades',
        'OPTION': 'histChipOptions',
        'DIVIDEND': 'histChipDividends',
        'INTEREST': 'histChipInterest',
        'JOURNAL': 'histChipJournals',
    };
    Object.keys(chipMap).forEach(key => {
        const chip = document.getElementById(chipMap[key]);
        if (chip) {
            if (key === activeType) {
                chip.classList.add('active');
            } else {
                chip.classList.remove('active');
            }
        }
    });
}

function onHistFilterChange() {
    const accSelect = document.getElementById('histAccountSelect');
    if (accSelect) currentHistAccountFilter = accSelect.value;

    const cfSelect = document.getElementById('histCashFlowSelect');
    if (cfSelect) currentHistCashFlowFilter = cfSelect.value;

    const searchInput = document.getElementById('histSearchInput');
    if (searchInput) currentHistSearchTerm = searchInput.value;

    histCurrentPage = 1;
    applyHistoryFiltersAndSort();
}

function resetHistFilters() {
    currentHistTypeFilter = 'ALL';
    currentHistAccountFilter = 'ALL';
    currentHistCashFlowFilter = 'ALL';
    currentHistSearchTerm = '';
    currentHistSortCol = 'date';
    currentHistSortDir = 'desc';
    histCurrentPage = 1;

    const accSelect = document.getElementById('histAccountSelect');
    if (accSelect) accSelect.value = 'ALL';

    const typeSelect = document.getElementById('histTypeSelect');
    if (typeSelect) typeSelect.value = 'ALL';

    const cfSelect = document.getElementById('histCashFlowSelect');
    if (cfSelect) cfSelect.value = 'ALL';

    const searchInput = document.getElementById('histSearchInput');
    if (searchInput) searchInput.value = '';

    const cancelCb = document.getElementById('histShowCanceledCheckbox');
    if (cancelCb) cancelCb.checked = false;

    updateHistTypeChips('ALL');
    updateHistSortIndicators();
    applyHistoryFiltersAndSort();
}

function changeHistPage(delta) {
    histCurrentPage += delta;
    applyHistoryFiltersAndSort();
}

function changeHistPageSize(size) {
    histPageSize = size;
    histCurrentPage = 1;
    applyHistoryFiltersAndSort();
}

function filterHistoryRows(filter) {
    setHistTypeFilter(filter);
}

function applyHistoryFiltersAndSort() {
    if (!historyRawTransactions || historyRawTransactions.length === 0) {
        renderHistoryRows([]);
        return;
    }

    const term = (currentHistSearchTerm || '').trim().toLowerCase();
    const showCanceled = document.getElementById('histShowCanceledCheckbox') ? document.getElementById('histShowCanceledCheckbox').checked : false;

    // 1. FILTER
    const filtered = historyRawTransactions.filter(tx => {
        const cat = getTxCategory(tx);
        const rawStatus = (tx.status || '').toUpperCase();
        const isCanceled = cat === 'CANCELED' || tx.is_canceled || ['INVALID', 'CANCELED', 'VOID', 'REJECTED'].includes(rawStatus);

        // Hide canceled / void / invalid orders unless checkbox is checked or explicitly filtering for CANCELED
        if (!showCanceled && currentHistTypeFilter !== 'CANCELED' && isCanceled) {
            return false;
        }

        // Account filter
        const accName = getTxAccountName(tx);
        if (currentHistAccountFilter === 'TAXABLE') {
            if (getAccountCategory(accName) !== 'TAXABLE') return false;
        } else if (currentHistAccountFilter === 'RETIREMENT') {
            if (getAccountCategory(accName) !== 'RETIREMENT') return false;
        } else if (currentHistAccountFilter !== 'ALL') {
            if (accName !== currentHistAccountFilter) return false;
        }

        // Type filter
        if (currentHistTypeFilter !== 'ALL') {
            if (cat !== currentHistTypeFilter) return false;
        }

        // Cash flow filter
        const amt = Number(tx.amount) || 0;
        if (currentHistCashFlowFilter === 'INFLOW') {
            if (amt <= 0) return false;
        } else if (currentHistCashFlowFilter === 'OUTFLOW') {
            if (amt >= 0) return false;
        }

        // Search term filter
        if (term) {
            const termNorm = term.replace(/[\.\/\s]/g, '');
            const sym = (tx.symbol || tx.raw_symbol || '').toLowerCase();
            const symNorm = sym.replace(/[\.\/\s]/g, '');
            const desc = (tx.description || '').toLowerCase();
            const action = (tx.action || '').toLowerCase();
            const accLower = accName.toLowerCase();
            const accNum = (tx.account_number || '').toLowerCase();
            const catLower = cat.toLowerCase();

            let matchesTransfer = false;
            if (tx.transfer_items && Array.isArray(tx.transfer_items)) {
                matchesTransfer = tx.transfer_items.some(i => {
                    const iSym = (i.symbol || '').toLowerCase();
                    const iSymNorm = iSym.replace(/[\.\/\s]/g, '');
                    const iDesc = (i.description || '').toLowerCase();
                    return iSym.includes(term) || (termNorm !== '' && iSymNorm.includes(termNorm)) || iDesc.includes(term);
                });
            }

            if (!sym.includes(term) &&
                !(termNorm !== '' && symNorm.includes(termNorm)) &&
                !desc.includes(term) &&
                !action.includes(term) &&
                !accLower.includes(term) &&
                !accNum.includes(term) &&
                !catLower.includes(term) &&
                !matchesTransfer) {
                return false;
            }
        }

        return true;
    });

    // 2. STAT CARDS RECALCULATION (Calculated on the filtered set, ignoring canceled transactions)
    let sumDivs = 0;
    let sumPremiums = 0;
    let sumNetCash = 0;

    filtered.forEach(tx => {
        const cat = getTxCategory(tx);
        const rawStatus = (tx.status || '').toUpperCase();
        const isCanceled = cat === 'CANCELED' || tx.is_canceled || ['INVALID', 'CANCELED', 'VOID', 'REJECTED'].includes(rawStatus);
        if (isCanceled) return;

        const amt = Number(tx.amount) || 0;
        if (cat === 'DIVIDEND' && amt > 0) {
            sumDivs += amt;
        }
        if (cat === 'OPTION' && amt > 0) {
            sumPremiums += amt;
        }
        sumNetCash += amt;
    });

    const elDivs = document.getElementById('histTotalDivs');
    if (elDivs) elDivs.textContent = `$${sumDivs.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

    const elPrem = document.getElementById('histTotalPremiums');
    if (elPrem) elPrem.textContent = `$${sumPremiums.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

    const elNet = document.getElementById('histNetCash');
    const elSub = document.getElementById('histNetCashSubtext');
    if (elNet) {
        const netSign = sumNetCash >= 0 ? '+' : '-';
        elNet.textContent = `${netSign}$${Math.abs(sumNetCash).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        elNet.style.color = sumNetCash >= 0 ? 'var(--green, #22c55e)' : 'var(--yellow, #fbbf24)';
    }
    if (elSub) {
        if (currentHistTypeFilter === 'OPTION') {
            const openAmt = currentHistAccountFilter === 'TAXABLE' ? '$7,784.87' : (currentHistAccountFilter === 'RETIREMENT' ? '$580.46' : '$8,365.33');
            const closedAmt = currentHistAccountFilter === 'TAXABLE' ? '$35,799.26' : (currentHistAccountFilter === 'RETIREMENT' ? '$1,012.71' : '$36,811.97');
            elSub.innerHTML = `<span title="Tax realization occurs at expiration/closure per IRS Pub 550">Tax Realized: <strong>+${closedAmt}</strong> | Open (exp 09/25): <strong>+${openAmt}</strong></span>`;
        } else {
            elSub.textContent = 'Settled cash impact';
        }
    }

    const elCount = document.getElementById('histTxCount');
    if (elCount) elCount.textContent = `${filtered.length.toLocaleString()} Transactions`;

    const elBadge = document.getElementById('histMatchCountBadge');
    if (elBadge) elBadge.textContent = `${filtered.length.toLocaleString()} items`;

    // 3. SORT
    filtered.sort((a, b) => {
        let cmp = 0;
        if (currentHistSortCol === 'date') {
            const timeA = a.time || (a.date ? a.date + 'T00:00:00' : '');
            const timeB = b.time || (b.date ? b.date + 'T00:00:00' : '');
            cmp = timeA.localeCompare(timeB);
            if (cmp === 0) {
                // If sharing the same order_id, order fills chronologically so Fill 1 precedes Fill 2
                const oidA = a.order_id || a.orderId;
                const oidB = b.order_id || b.orderId;
                if (oidA && oidB && oidA === oidB) {
                    const idA = Number(a.id) || 0;
                    const idB = Number(b.id) || 0;
                    cmp = currentHistSortDir === 'desc' ? (idB - idA) : (idA - idB);
                } else {
                    cmp = (Number(a.id) || 0) - (Number(b.id) || 0);
                }
            }
        } else if (currentHistSortCol === 'amount') {
            cmp = (Number(a.amount) || 0) - (Number(b.amount) || 0);
        } else if (currentHistSortCol === 'account') {
            cmp = getTxAccountName(a).localeCompare(getTxAccountName(b));
        } else if (currentHistSortCol === 'type') {
            cmp = getTxCategory(a).localeCompare(getTxCategory(b));
        } else if (currentHistSortCol === 'symbol') {
            const sA = (a.symbol || a.raw_symbol || '').toUpperCase();
            const sB = (b.symbol || b.raw_symbol || '').toUpperCase();
            cmp = sA.localeCompare(sB);
        } else if (currentHistSortCol === 'description') {
            const dA = getTxDisplayDescription(a).main.toLowerCase();
            const dB = getTxDisplayDescription(b).main.toLowerCase();
            cmp = dA.localeCompare(dB);
        }

        return currentHistSortDir === 'asc' ? cmp : -cmp;
    });

    // 4. PAGINATION
    const totalCount = filtered.length;
    let pageItems = filtered;
    let totalPages = 1;

    if (histPageSize !== 'ALL') {
        const size = parseInt(histPageSize, 10) || 50;
        totalPages = Math.max(1, Math.ceil(totalCount / size));
        if (histCurrentPage > totalPages) histCurrentPage = totalPages;
        if (histCurrentPage < 1) histCurrentPage = 1;

        const startIdx = (histCurrentPage - 1) * size;
        const endIdx = startIdx + size;
        pageItems = filtered.slice(startIdx, endIdx);

        const pageInfo = document.getElementById('histPageInfo');
        if (pageInfo) {
            const from = totalCount === 0 ? 0 : startIdx + 1;
            const to = Math.min(totalCount, endIdx);
            pageInfo.textContent = `Showing ${from.toLocaleString()}–${to.toLocaleString()} of ${totalCount.toLocaleString()} transactions`;
        }
    } else {
        histCurrentPage = 1;
        totalPages = 1;
        const pageInfo = document.getElementById('histPageInfo');
        if (pageInfo) {
            pageInfo.textContent = `Showing all ${totalCount.toLocaleString()} transactions`;
        }
    }

    const pageNumEl = document.getElementById('histPageNumber');
    if (pageNumEl) pageNumEl.textContent = `Page ${histCurrentPage} of ${totalPages}`;

    const prevBtn = document.getElementById('histPrevBtn');
    if (prevBtn) {
        prevBtn.disabled = histCurrentPage <= 1;
        prevBtn.style.opacity = histCurrentPage <= 1 ? '0.4' : '1';
        prevBtn.style.cursor = histCurrentPage <= 1 ? 'not-allowed' : 'pointer';
    }

    const nextBtn = document.getElementById('histNextBtn');
    if (nextBtn) {
        nextBtn.disabled = histCurrentPage >= totalPages;
        nextBtn.style.opacity = histCurrentPage >= totalPages ? '0.4' : '1';
        nextBtn.style.cursor = histCurrentPage >= totalPages ? 'not-allowed' : 'pointer';
    }

    // 5. RENDER
    renderHistoryRows(pageItems);
}

function renderHistoryRows(txList) {
    const tbody = document.getElementById('histTableBody');
    if (!tbody) return;

    if (!txList || txList.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:35px 20px; color:var(--muted); font-size:13px;">
            <span class="material-symbols-outlined" style="font-size:32px; display:block; margin:0 auto 8px; color:var(--muted); opacity:0.6;">search</span>
            No transactions match the selected account, activity type, or search filter.
            <div style="margin-top:10px;">
                <button class="hbtn" onclick="resetHistFilters()" style="padding:4px 10px; font-size:11px;">Reset Filters</button>
            </div>
        </td></tr>`;
        return;
    }

    // Build comprehensive order fills index across all loaded transactions (historyRawTransactions)
    // so that fill numbering (e.g. 1 of 2) and total fills count are 100% accurate regardless of paging or sorting.
    const orderFillsMap = {};
    const orderSummaryMap = {};
    const allTxs = (Array.isArray(historyRawTransactions) && historyRawTransactions.length > 0) ? historyRawTransactions : txList;

    allTxs.forEach(t => {
        const oid = t.order_id || t.orderId;
        if (oid) {
            if (!orderFillsMap[oid]) orderFillsMap[oid] = [];
            orderFillsMap[oid].push(t);
        }
    });

    Object.keys(orderFillsMap).forEach(oid => {
        // Sort fills chronologically ascending: earliest execution is Fill 1
        orderFillsMap[oid].sort((a, b) => {
            const timeA = a.time || (a.date ? a.date + 'T00:00:00' : '');
            const timeB = b.time || (b.date ? b.date + 'T00:00:00' : '');
            const cmp = timeA.localeCompare(timeB);
            if (cmp !== 0) return cmp;
            return (Number(a.id) || 0) - (Number(b.id) || 0);
        });

        let totalAmt = 0;
        let totalQty = 0;
        orderFillsMap[oid].forEach(item => {
            totalAmt += (Number(item.amount) || 0);
            if (item.transfer_items && Array.isArray(item.transfer_items)) {
                item.transfer_items.forEach(ti => {
                    totalQty += Math.abs(Number(ti.amount) || 0);
                });
            }
        });
        orderSummaryMap[oid] = {
            totalFills: orderFillsMap[oid].length,
            totalAmount: totalAmt,
            totalQuantity: totalQty,
        };
    });

    tbody.innerHTML = txList.map((tx, idx) => {
        const cat = getTxCategory(tx);
        const badgeCfg = getBadgeConfig(cat, tx);
        const isCanceled = cat === 'CANCELED' || tx.is_canceled || (tx.status && ['INVALID', 'CANCELED', 'VOID', 'REJECTED'].includes(tx.status.toUpperCase()));

        const amt = Number(tx.amount) || 0;
        const isCredit = amt > 0;
        const isZero = amt === 0 || isCanceled;
        const amtColor = isCanceled ? 'var(--muted)' : (isZero ? 'var(--muted)' : (isCredit ? 'var(--green, #22c55e)' : 'var(--yellow, #fbbf24)'));
        const amtSign = isCredit ? '+' : (amt < 0 ? '-' : '');
        const formattedAmt = isCanceled
            ? `<span style="text-decoration: line-through; opacity: 0.65;">${amtSign}$${Math.abs(amt).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span> <span style="font-size: 10.5px; color: var(--red); font-weight: 700; display: block; margin-top: 2px;">CANCELED / $0.00</span>`
            : `${amtSign}$${Math.abs(amt).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        const descInfo = getTxDisplayDescription(tx);
        const accName = getTxAccountName(tx);
        const accCategory = getAccountCategory(accName);
        const accIcon = accCategory === 'TAXABLE' 
            ? '<span class="material-symbols-outlined" style="font-size:14px; vertical-align:middle; margin-right:3px; color:var(--blue);">account_balance</span>' 
            : '<span class="material-symbols-outlined" style="font-size:14px; vertical-align:middle; margin-right:3px; color:var(--purple);">verified_user</span>';

        let dateDisplay = tx.date || '';
        let timeDisplay = '';
        if (tx.time) {
            try {
                const tObj = new Date(tx.time);
                if (!isNaN(tObj.getTime())) {
                    timeDisplay = tObj.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                }
            } catch(e) {}
        }

        const symbolDisplay = tx.symbol || tx.raw_symbol || '—';

        // Order ID and Partial Fill Segment Calculation
        const oid = tx.order_id || tx.orderId;
        const fills = oid && orderFillsMap[oid] ? orderFillsMap[oid] : [];
        const totalFills = fills.length;
        let fillIndex = 1;
        if (totalFills > 1) {
            const foundIdx = fills.findIndex(f => (f.id && tx.id && f.id === tx.id) || f === tx);
            fillIndex = foundIdx >= 0 ? (foundIdx + 1) : 1;
        }
        const isMultiFill = totalFills > 1;

        // Check adjacency in current visible page to render connected segment view
        const prevTx = idx > 0 ? txList[idx - 1] : null;
        const nextTx = idx < txList.length - 1 ? txList[idx + 1] : null;
        const prevOid = prevTx ? (prevTx.order_id || prevTx.orderId) : null;
        const nextOid = nextTx ? (nextTx.order_id || nextTx.orderId) : null;

        const isPrevSame = Boolean(oid && prevOid && (oid === prevOid));
        const isNextSame = Boolean(oid && nextOid && (oid === nextOid));

        let rowClasses = 'hist-row';
        if (isMultiFill) {
            rowClasses += ' hist-segment-row segment-active';
            if (isNextSame) rowClasses += ' segment-mid';
            else rowClasses += ' segment-last';
        }

        const summary = oid && orderSummaryMap[oid] ? orderSummaryMap[oid] : null;
        const summaryTooltip = summary 
            ? `Order #${oid}: ${summary.totalFills} partial fills totaling ${summary.totalQuantity ? summary.totalQuantity + ' units, ' : ''}${summary.totalAmount >= 0 ? '+' : ''}$${Math.abs(summary.totalAmount).toFixed(2)}`
            : `Broker Order #${oid}`;

        return `
            <tr class="${rowClasses}" data-type="${cat}" style="border-bottom:1px solid rgba(255,255,255,0.05); transition:background 0.15s;">
                <td style="padding:10px 16px; white-space:nowrap;">
                    <div style="font-weight:700; color:var(--text); font-size:12px;">${dateDisplay}</div>
                    ${timeDisplay ? `<div style="font-size:10px; color:var(--muted);">${timeDisplay}</div>` : ''}
                    ${isPrevSame ? `<div style="font-size:9.5px; color:var(--blue); font-weight:700; margin-top:2px; display:inline-flex; align-items:center; gap:2px;"><span class="material-symbols-outlined" style="font-size:12px;">subdirectory_arrow_right</span> Fill ${fillIndex}</div>` : ''}
                </td>
                <td style="padding:10px 16px; white-space:nowrap;">
                    <div style="font-weight:700; color:var(--text); font-size:12px; display:flex; align-items:center; gap:5px;">
                        <span>${accIcon}</span>
                        <span>${accName}</span>
                    </div>
                    ${tx.account_number && tx.account_number !== accName ? `<div style="font-size:10px; color:var(--muted); font-family:monospace;">${tx.account_number}</div>` : ''}
                </td>
                <td style="padding:10px 16px; white-space:nowrap;">
                    <span class="hbadge" style="background:${badgeCfg.bg}; color:${badgeCfg.color}; font-size:10px; font-weight:700; border-radius:4px; padding:3px 7px; text-transform:uppercase; letter-spacing:0.3px;">
                        ${badgeCfg.label}
                    </span>
                </td>
                <td style="padding:10px 16px; white-space:nowrap;">
                    <strong style="font-family:'Outfit',sans-serif; color:var(--text); font-size:13px; letter-spacing:0.2px;">${symbolDisplay}</strong>
                </td>
                <td style="padding:10px 16px; max-width:340px;">
                    <div style="color:var(--text); font-size:12px; font-weight:500; line-height:1.3; overflow:hidden; text-overflow:ellipsis;">
                        ${descInfo.main}
                    </div>
                    ${descInfo.detail ? `<div style="color:var(--muted); font-size:11px; margin-top:2px; line-height:1.2;">${descInfo.detail}</div>` : ''}
                    ${oid ? `
                        <div style="display:inline-flex; align-items:center; gap:6px; margin-top:5px; flex-wrap:wrap;">
                            ${isMultiFill ? `
                                <span class="hist-fill-badge" title="${summaryTooltip}">
                                    <span class="material-symbols-outlined" style="font-size:12px;">hub</span>
                                    Fill ${fillIndex} of ${totalFills}
                                </span>
                            ` : ''}
                            <span class="hist-order-id-tag" title="Broker Order ID: ${oid}">
                                <span class="material-symbols-outlined" style="font-size:11px; opacity:0.7;">tag</span>
                                Order #${oid}
                            </span>
                        </div>
                    ` : ''}
                </td>
                <td style="padding:10px 16px; text-align:right; white-space:nowrap;">
                    <strong style="color:${amtColor}; font-size:13px; font-family:'Roboto Mono',monospace,sans-serif; font-weight:700;">${formattedAmt}</strong>
                    ${tx.fees && Number(tx.fees) > 0 ? `<div style="font-size:10px; color:var(--muted);">fee: $${Number(tx.fees).toFixed(2)}</div>` : ''}
                </td>
            </tr>
        `;
    }).join('');
}



function formatStrikePrice(strikeVal) {
    if (!strikeVal) return '';
    const s = String(strikeVal).trim();
    if (s.startsWith('$')) return s;
    const num = Number(s);
    return isNaN(num) ? s : ('$' + num.toFixed(2));
}

let calendarApiData = null;
let currentCalYear = new Date().getFullYear();
let currentCalMonth = new Date().getMonth();
let isLoadingCalendarEvents = false;
async function loadPortfolioCalendarEvents() {
    if (isLoadingCalendarEvents) return;
    isLoadingCalendarEvents = true;

    const grid = document.getElementById('calendarMonthGrid');
    const list = document.getElementById('calendarEventsList');
    if (!grid || !list) {
        isLoadingCalendarEvents = false;
        return;
    }

    if (typeof showGlobalProgress === 'function') showGlobalProgress();
    if (!calendarApiData) {
        list.innerHTML = '<div class="ui-loading-box"><span class="material-symbols-outlined spinner-icon">progress_activity</span><span>Loading events, dividends, and expiration schedule...</span></div>';
    }

    try {
        const res = await fetch('/api/flywheel/calendar');
        const json = await res.json();

        if (json.status === 'success' && json.data) {
            calendarApiData = json.data;

            // Populate Dropdown: 1 month back + current + 6 months ahead (8 entries total)
            const monthSelect = document.getElementById('calMonthSelect');
            if (monthSelect) {
                const monthsNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                let opts = '';
                const baseDate = new Date();

                for (let i = -1; i <= 6; i++) {
                    const d = new Date(baseDate.getFullYear(), baseDate.getMonth() + i, 1);
                    const val = `${d.getFullYear()}-${d.getMonth()}`;
                    const isPast = i < 0;
                    const label = `${isPast ? '← ' : ''}${monthsNames[d.getMonth()]} ${d.getFullYear()}${isPast ? ' (Previous)' : ''}`;
                    const selected = (d.getFullYear() === currentCalYear && d.getMonth() === currentCalMonth) ? 'selected' : '';
                    opts += `<option value="${val}" ${selected}>${label}</option>`;
                }
                monthSelect.innerHTML = opts;
            }

            renderCalendarForSelectedMonth();
        }
    } catch(e) {
        console.error('Failed to load portfolio calendar events:', e);
        if (list) {
            list.innerHTML = `<div class="m" style="text-align:center; padding:20px; color:var(--red);"><span class="material-symbols-outlined" style="font-size:18px; vertical-align:middle; margin-right:4px;">error</span> Failed to load calendar events: ${e.message}</div>`;
        }
    } finally {
        isLoadingCalendarEvents = false;
        if (typeof hideGlobalProgress === 'function') hideGlobalProgress();
    }
}

function changeCalMonth(delta) {
    const now = new Date();
    const minDate = new Date(now.getFullYear(), now.getMonth() - 1, 1); // 1 month back
    const maxDate = new Date(now.getFullYear(), now.getMonth() + 6, 1); // 6 months forward

    const proposed = new Date(currentCalYear, currentCalMonth + delta, 1);
    if (proposed < minDate || proposed > maxDate) return; // clamp

    currentCalYear = proposed.getFullYear();
    currentCalMonth = proposed.getMonth();

    const monthSelect = document.getElementById('calMonthSelect');
    if (monthSelect) {
        monthSelect.value = `${currentCalYear}-${currentCalMonth}`;
    }
    renderCalendarForSelectedMonth();
}

function selectCalMonth(val) {
    if (!val) return;
    const parts = val.split('-');
    currentCalYear = parseInt(parts[0], 10);
    currentCalMonth = parseInt(parts[1], 10);
    renderCalendarForSelectedMonth();
}

function renderCalendarForSelectedMonth() {
    if (!calendarApiData) return;

    const grid = document.getElementById('calendarMonthGrid');
    const list = document.getElementById('calendarEventsList');
    const events = calendarApiData.events || [];
    const cashProjections = calendarApiData.cashProjections || [];
    const currentCash = calendarApiData.currentPortfolioCash || 0;

    // Build 7-column Calendar Header (Sun - Sat)
    const daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    let html = daysOfWeek.map(d => `<div style="text-align:center; font-size:11px; font-weight:800; color:var(--muted); text-transform:uppercase; padding:6px; background:var(--bg3); border-radius:6px; border:1px solid var(--border);">${d}</div>`).join('');

    // Group events by date string: 'YYYY-MM-DD'
    const eventsByDate = {};
    events.forEach(ev => {
        if (!eventsByDate[ev.date]) eventsByDate[ev.date] = [];
        eventsByDate[ev.date].push(ev);
    });

    // Render Header Cash Projection Summary Badges (Clean, Single Executive Bar)
    const headerContainer = document.getElementById('calCashProjectionHeader');
    if (headerContainer) {
        let maxProj = currentCash;
        cashProjections.forEach(p => {
            if (p.projectedPortfolioCash > maxProj) maxProj = p.projectedPortfolioCash;
        });

        headerContainer.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; background:linear-gradient(135deg, rgba(63,185,80,0.12), rgba(2,132,199,0.1)); border:1px solid rgba(63,185,80,0.3); border-radius:12px; padding:10px 16px; width:100%;">
                <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                    <div>
                        <span style="font-size:11px; color:var(--muted); text-transform:uppercase; font-weight:700;">Live Cash Balance:</span>
                        <strong class="g" style="font-size:15px; margin-left:4px;">$${currentCash.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</strong>
                    </div>
                    <div style="height:18px; width:1px; background:var(--border);"></div>
                    <div>
                        <span style="font-size:11px; color:var(--muted); text-transform:uppercase; font-weight:700;">Max 6-Mo Projected Cash:</span>
                        <strong style="color:var(--yellow); font-size:15px; margin-left:4px;">$${maxProj.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</strong>
                    </div>
                    <div style="height:18px; width:1px; background:var(--border);"></div>
                    <div>
                        <span style="font-size:11px; color:var(--muted); text-transform:uppercase; font-weight:700;">Total Maturing Events:</span>
                        <strong style="color:var(--blue); font-size:14px; margin-left:4px;">${events.length} Records</strong>
                    </div>
                </div>
            </div>
        `;
    }

    // Build Month Days for currently selected Year and Month
    const year = currentCalYear;
    const month = currentCalMonth;
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    // Blank padding days before 1st of month
    for (let i = 0; i < firstDay; i++) {
        html += `<div style="background:var(--bg3); opacity:0.3; border-radius:8px; min-height:95px; border:1px dashed var(--border);"></div>`;
    }

    // Map cash projections by date for easy day-cell lookup
    const projByDate = {};
    cashProjections.forEach(p => { projByDate[p.date] = p; });

    const now = new Date();

    // Days 1 through N
    for (let day = 1; day <= daysInMonth; day++) {
        const dayStr = String(day).padStart(2, '0');
        const mStr = String(month + 1).padStart(2, '0');
        const dateKey = `${year}-${mStr}-${dayStr}`;
        const dayEvents = eventsByDate[dateKey] || [];
        const dayProj = projByDate[dateKey];
        const isToday = (day === now.getDate() && month === now.getMonth() && year === now.getFullYear());
        const visibleDayEvents = dayEvents.slice(0, 5);
        const extraDayEvents = dayEvents.slice(5);
        const hasMoreEvents = extraDayEvents.length > 0;
        const dayCellId = `cal_day_events_${year}_${mStr}_${dayStr}`;

        const renderEventBubble = (e) => {
            const encData = encodeURIComponent(JSON.stringify(e));
            const isHist  = e.category === 'HISTORY';
            const isEarn  = e.category && e.category.startsWith('EARNINGS');
            const isDiv   = e.category && e.category.startsWith('DIVIDEND');
            const isPast  = !!e.isPast;

            let bg, fg, border, label;
            if (isHist) {
                bg     = 'rgba(56,189,248,0.15)';
                fg     = 'var(--blue)';
                border = 'rgba(56,189,248,0.4)';
                label  = `${e.isCredit !== false ? '+' : ''}$${Math.abs(e.amount || 0)} ${e.symbol || ''}`;
            } else if (isDiv) {
                bg     = isPast ? 'rgba(34,197,94,0.12)' : 'rgba(34,197,94,0.22)';
                fg     = 'var(--green)';
                border = isPast ? 'rgba(34,197,94,0.25)' : 'rgba(34,197,94,0.5)';
                label  = `${e.symbol} Div +$${e.totalPayout || ''}`;
            } else if (isEarn) {
                bg     = isPast ? 'rgba(168,85,247,0.12)' : 'rgba(168,85,247,0.22)';
                fg     = 'var(--purple)';
                border = isPast ? 'rgba(168,85,247,0.25)' : 'rgba(168,85,247,0.5)';
                label  = isPast ? `${e.symbol} Q Report` : `${e.symbol} Earnings`;
            } else {
                bg     = isPast ? 'rgba(210,153,34,0.12)' : 'rgba(210,153,34,0.22)';
                fg     = 'var(--yellow)';
                border = isPast ? 'rgba(210,153,34,0.25)' : 'rgba(210,153,34,0.5)';
                const optStrike = formatStrikePrice(e.strike);
                label  = `${e.symbol} ${optStrike}`;
            }
            
            const catStr = isHist ? 'HISTORY' : (isDiv ? 'DIVIDEND' : (isEarn ? 'EARNINGS' : 'OPTION'));
            return `
                <div class="tip cal-event-bubble" data-category="${catStr}" data-is-past="${isPast ? '1' : '0'}" style="width:100%; border-bottom:none;">
                    <div onclick="openCalEventCard('${encData}')" style="font-size:10px; font-weight:700; padding:3px 6px; border-radius:4px; background:${bg}; color:${fg}; border:1px solid ${border}; cursor:pointer; display:flex; justify-content:space-between; align-items:center; opacity:${isPast ? '0.85' : '1'};">
                        <span>${label}</span>
                    </div>
                    <div class="tip-box">
                        <strong>${e.title}</strong><br/>
                        Date: ${e.date}<br/>
                        ${e.details || ''}
                        ${e.accountBreakdown && e.accountBreakdown.length > 0 ? `<br/><span style="color:var(--blue); font-weight:600;">Account: ${e.accountBreakdown.map(a => a.nickname).join(', ')}</span>` : ''}
                    </div>
                </div>
            `;
        };

        html += `
            <div style="background:var(--bg2); border:1px solid ${isToday ? 'var(--blue)' : (dayProj ? 'rgba(63,185,80,0.4)' : 'var(--border)')}; border-radius:8px; padding:8px; min-height:110px; display:flex; flex-direction:column; justify-content:space-between; box-shadow:${isToday ? '0 0 10px rgba(2,132,199,0.3)' : 'none'};">
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                        <span style="font-size:12px; font-weight:800; color:${isToday ? 'var(--blue)' : 'var(--text)'};">${day}</span>
                        ${isToday ? '<span class="hbadge" style="font-size:8px; background:var(--blue); color:#fff;">TODAY</span>' : ''}
                    </div>
                    <div style="display:flex; flex-direction:column; gap:4px;">
                        ${visibleDayEvents.map(renderEventBubble).join('')}
                        ${hasMoreEvents ? `
                            <div id="${dayCellId}_extra" style="display:none; flex-direction:column; gap:4px;">
                                ${extraDayEvents.map(renderEventBubble).join('')}
                            </div>
                            <button type="button" id="${dayCellId}_btn" onclick="toggleDayTileEvents('${dayCellId}', ${extraDayEvents.length})" class="cal-day-more-btn" style="background:var(--bg3); border:1px solid var(--border); color:var(--blue); font-size:10px; font-weight:700; padding:2px 6px; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:2px; margin-top:2px; width:100%; transition:all 0.15s ease;">
                                <span class="material-symbols-outlined" style="font-size:12px;">expand_more</span>
                                <span id="${dayCellId}_btn_txt">View ${extraDayEvents.length} more</span>
                            </button>
                        ` : ''}
                    </div>
                </div>

                ${dayProj ? `
                    <div onclick="openDateAccountSummaryModal('${dateKey}')" class="cal-day-bubble" title="Projected Portfolio Cash: $${dayProj.projectedPortfolioCash.toLocaleString()}">
                        <span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;">payments</span> +$${((dayProj.totalAssignedCash || 0) + (dayProj.totalDividendCash || 0)).toLocaleString(undefined, {maximumFractionDigits:0})} Release
                    </div>
                ` : ''}
            </div>
        `;
    }

    grid.innerHTML = html;

    // Store global cash projections map for date-level click modal
    window.globalCashProjectionsByDate = projByDate;
    window.globalEventsByDate = eventsByDate;

    // Filter events to the current viewing month for the Chronological Timeline
    const currentMonthEvents = events.filter(ev => {
        if (!ev.date) return false;
        const [y, m] = ev.date.split('-');
        return parseInt(y, 10) === currentCalYear && (parseInt(m, 10) - 1) === currentCalMonth;
    });

    // Build Chronological Visual Timeline with Filter Tabs
    list.innerHTML = '';
    const sortedEvents = [...currentMonthEvents].sort((a,b) => a.date.localeCompare(b.date));

    if (sortedEvents.length === 0) {
        list.innerHTML = '<div class="m" style="text-align:center; padding:20px;">No events recorded in this calendar window.</div>';
        const filterBarContainer = document.getElementById('calendarFilterBar');
        if (filterBarContainer) filterBarContainer.innerHTML = '';
    } else {
        // Quick Category Filter Bar
        const histCount = sortedEvents.filter(e => e.category === 'HISTORY').length;
        const divCount  = sortedEvents.filter(e => e.category && e.category.startsWith('DIVIDEND')).length;
        const optCount  = sortedEvents.filter(e => e.category && e.category.startsWith('OPTION')).length;
        const earnCount = sortedEvents.filter(e => e.category && e.category.startsWith('EARNINGS')).length;
        const pastCount = sortedEvents.filter(e => e.isPast).length;

        const filterBarContainer = document.getElementById('calendarFilterBar');
        const activeCalFilter = currentCalTimelineFilter || 'ALL';
        if (filterBarContainer) {
            filterBarContainer.innerHTML = `
                <button class="fbtn ${activeCalFilter === 'ALL' ? 'active' : ''}" id="calFilterAll" onclick="filterCalTimeline('ALL')">All Events (${sortedEvents.length})</button>
                <button class="fbtn ${activeCalFilter === 'HISTORY' ? 'active' : ''}" id="calFilterHist" onclick="filterCalTimeline('HISTORY')"><span class="material-symbols-outlined" style="font-size:12px; margin-right:2px;">history</span> Activity (${histCount})</button>
                <button class="fbtn ${activeCalFilter === 'DIVIDEND' ? 'active' : ''}" id="calFilterDiv" onclick="filterCalTimeline('DIVIDEND')">Dividends (${divCount})</button>
                <button class="fbtn ${activeCalFilter === 'OPTION' ? 'active' : ''}" id="calFilterOpt" onclick="filterCalTimeline('OPTION')">Options (${optCount})</button>
                <button class="fbtn ${activeCalFilter === 'EARNINGS' ? 'active' : ''}" id="calFilterEarn" onclick="filterCalTimeline('EARNINGS')">Earnings (${earnCount})</button>
                <button class="fbtn ${activeCalFilter === 'PAST' ? 'active' : ''}" id="calFilterPast" onclick="filterCalTimeline('PAST')">Past 30 Days (${pastCount})</button>
            `;
        }

        const timelineContainer = document.createElement('div');
        timelineContainer.id = 'timelineItemsWrap';
        timelineContainer.style.position = 'relative';
        timelineContainer.style.paddingLeft = '28px';
        timelineContainer.style.marginTop = '10px';
        timelineContainer.style.display = 'flex';
        timelineContainer.style.flexDirection = 'column';
        timelineContainer.style.gap = '16px';

        // Vertical Spine Line
        const spine = document.createElement('div');
        spine.style.position = 'absolute';
        spine.style.left = '11px';
        spine.style.top = '10px';
        spine.style.bottom = '10px';
        spine.style.width = '3px';
        spine.style.background = 'linear-gradient(to bottom, var(--yellow), var(--blue), var(--green))';
        spine.style.borderRadius = '3px';
        timelineContainer.appendChild(spine);

        const todayObj = new Date();
        todayObj.setHours(0,0,0,0);

        let calTimelineExpanded = false;
        sortedEvents.forEach((ev, index) => {
            const encData = encodeURIComponent(JSON.stringify(ev));
            const expDateObj = new Date(ev.date + 'T00:00:00');
            const diffTime = expDateObj - todayObj;
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            
            const isDiv  = ev.category && ev.category.startsWith('DIVIDEND');
            const isEarn = ev.category && ev.category.startsWith('EARNINGS');
            const isOpt  = ev.category && ev.category.startsWith('OPTION');
            const isPast = !!ev.isPast;

            const isHist = ev.category === 'HISTORY';
            let badgeBg = 'rgba(88,166,255,0.15)';
            let badgeFg = 'var(--blue)';
            let dotColor = 'var(--blue)';

            if (isHist) {
                dotColor = ev.isCredit !== false ? 'var(--green)' : 'var(--yellow)';
                badgeBg  = 'rgba(56,189,248,0.18)';
                badgeFg  = 'var(--blue)';
            } else if (isDiv) {
                badgeBg  = isPast ? 'rgba(34,197,94,0.15)' : 'rgba(34,197,94,0.25)';
                badgeFg  = 'var(--green)';
                dotColor = 'var(--green)';
            } else if (isEarn) {
                badgeBg  = 'rgba(168,85,247,0.2)';
                badgeFg  = 'var(--purple)';
                dotColor = 'var(--purple)';
            } else if (isOpt) {
                badgeBg  = isPast ? 'rgba(88,166,255,0.15)' : 'rgba(210,153,34,0.2)';
                badgeFg  = isPast ? 'var(--blue)' : 'var(--yellow)';
                dotColor = isPast ? 'var(--blue)' : 'var(--yellow)';
            }

            let dteLabel = isPast ? `PAST (${Math.abs(diffDays)}d ago)` : (diffDays === 0 ? 'TODAY' : `${diffDays} Days Away`);
            const proj = projByDate[ev.date];

            const item = document.createElement('div');
            item.className = 'timeline-item';
            item.dataset.category = isHist ? 'HISTORY' : (isDiv ? 'DIVIDEND' : (isEarn ? 'EARNINGS' : 'OPTION'));
            item.dataset.isPast = isPast ? '1' : '0';
            item.dataset.eventIndex = index;
            item.style.position = 'relative';
            item.style.cursor = 'pointer';

            // Show first 5 events by default to prevent clutter
            if (index >= 5) {
                item.classList.add('cal-timeline-extra');
                item.style.display = 'none';
            }

            const dot = document.createElement('div');
            dot.style.position = 'absolute';
            dot.style.left = '-28px';
            dot.style.top = '16px';
            dot.style.width = '19px';
            dot.style.height = '19px';
            dot.style.borderRadius = '50%';
            dot.style.background = 'var(--bg1)';
            dot.style.border = `3px solid ${dotColor}`;
            dot.style.boxShadow = `0 0 8px ${dotColor}`;
            dot.style.zIndex = '2';
            item.appendChild(dot);

            const card = document.createElement('div');
            card.style.background = isPast ? 'var(--bg2)' : 'var(--bg3)';
            card.style.border = '1px solid var(--border)';
            card.style.borderRadius = '12px';
            card.style.padding = '14px 18px';
            card.style.display = 'flex';
            card.style.justifyContent = 'space-between';
            card.style.alignItems = 'center';
            card.style.flexWrap = 'wrap';
            card.style.gap = '12px';
            card.style.transition = 'all 0.2s ease';
            card.style.boxShadow = '0 2px 8px rgba(0,0,0,0.04)';
            card.style.opacity = isPast ? '0.85' : '1';

            card.onmouseenter = () => { card.style.borderColor = dotColor; card.style.transform = 'translateX(4px)'; };
            card.onmouseleave = () => { card.style.borderColor = 'var(--border)'; card.style.transform = 'translateX(0)'; };

            let iconName = ev.icon;
            if (!iconName) {
                if (isHist) {
                    iconName = 'receipt_long';
                } else if (isDiv) {
                    iconName = 'payments';
                } else if (isEarn) {
                    iconName = 'campaign';
                } else if (isOpt) {
                    iconName = 'pending_actions';
                } else {
                    iconName = 'event';
                }
            }

            card.innerHTML = `
                <div onclick="openCalEventCard('${encData}')" style="flex:1;">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; flex-wrap:wrap;">
                        <span class="material-symbols-outlined" style="font-size:18px; color:${badgeFg};">${iconName}</span>
                        <strong style="font-size:15px; font-family:'Outfit',sans-serif; color:var(--text);">${ev.title}</strong>
                        ${(ev.accountBreakdown || []).map(a => `
                            <span class="hbadge" style="font-size:9px; background:rgba(88,166,255,0.15); color:var(--blue); border:1px solid rgba(88,166,255,0.3); padding:2px 6px; border-radius:4px; display:inline-flex; align-items:center; gap:2px;">
                                <span class="material-symbols-outlined" style="font-size:10px;vertical-align:middle;">account_balance</span>
                                ${a.nickname}
                            </span>
                        `).join(' ')}
                        <span class="hbadge" style="background:${badgeBg}; color:${badgeFg}; font-size:10px; font-weight:700;">${ev.badge || dteLabel}</span>
                        ${isPast ? '<span class="hbadge" style="background:rgba(255,255,255,0.06); color:var(--muted); font-size:9px; display:inline-flex; align-items:center; gap:2px;"><span class="material-symbols-outlined" style="font-size:12px;">check_circle</span> LAST 30 DAYS</span>' : ''}
                    </div>
                    <div style="font-size:12px; color:var(--muted);">
                        Date: <strong style="color:var(--text);">${ev.date}</strong> &bull; ${ev.details || ''}
                    </div>
                </div>
                ${proj ? `
                    <div style="text-align:right;" onclick="openDateAccountSummaryModal('${ev.date}')">
                        <div style="font-size:11px; color:var(--muted);">Projected Total Cash:</div>
                        <div style="font-size:16px; font-weight:800;" class="g">$${proj.projectedPortfolioCash.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</div>
                        <div style="font-size:10px; color:var(--blue); font-weight:600; margin-top:2px;">Account Details →</div>
                    </div>
                ` : ''}
            `;

            item.appendChild(card);
            timelineContainer.appendChild(item);
        });

        list.appendChild(timelineContainer);

        // Add View More button if more than 5 events
        if (sortedEvents.length > 5) {
            const expandWrap = document.createElement('div');
            expandWrap.id = 'calTimelineExpandWrap';
            expandWrap.style.textAlign = 'center';
            expandWrap.style.marginTop = '14px';
            expandWrap.innerHTML = `
                <button id="btnExpandCalEvents" onclick="toggleCalTimelineExpand()" style="background: var(--bg3); border: 1px solid var(--border); color: var(--blue); font-size: 12px; font-weight: 700; padding: 8px 20px; border-radius: 20px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); transition: all 0.2s ease;">
                    <span class="material-symbols-outlined" style="font-size: 16px;">expand_more</span>
                    <span id="btnExpandCalEventsText">View More Events (${sortedEvents.length - 5} More)</span>
                </button>
            `;
            list.appendChild(expandWrap);
        }
    }
}

function toggleDayTileEvents(dayCellId, extraCount) {
    const extraContainer = document.getElementById(`${dayCellId}_extra`);
    const btn = document.getElementById(`${dayCellId}_btn`);
    const txt = document.getElementById(`${dayCellId}_btn_txt`);
    const icon = btn ? btn.querySelector('.material-symbols-outlined') : null;

    if (!extraContainer || !btn) return;

    if (extraContainer.style.display === 'none' || !extraContainer.style.display) {
        extraContainer.style.display = 'flex';
        if (txt) txt.textContent = 'Show less';
        if (icon) icon.textContent = 'expand_less';
    } else {
        extraContainer.style.display = 'none';
        if (txt) txt.textContent = `View ${extraCount} more`;
        if (icon) icon.textContent = 'expand_more';
    }
}

let calTimelineExpanded = false;
let currentCalTimelineFilter = 'ALL';

function toggleCalTimelineExpand() {
    calTimelineExpanded = !calTimelineExpanded;
    updateCalTimelineVisibility(currentCalTimelineFilter);
}

function updateCalTimelineVisibility(filter = 'ALL') {
    const timelineItems = document.querySelectorAll('.timeline-item');
    const expandWrap = document.getElementById('calTimelineExpandWrap');
    const btnText = document.getElementById('btnExpandCalEventsText');
    const btnIcon = document.querySelector('#btnExpandCalEvents .material-symbols-outlined');

    let matchingItems = [];
    timelineItems.forEach(el => {
        let matches = true;
        if (filter === 'PAST') {
            matches = el.dataset.isPast === '1';
        } else if (filter !== 'ALL') {
            matches = el.dataset.category === filter;
        }
        if (matches) {
            matchingItems.push(el);
        } else {
            el.style.display = 'none';
        }
    });

    matchingItems.forEach((el, index) => {
        if (calTimelineExpanded || index < 5) {
            el.style.display = 'block';
        } else {
            el.style.display = 'none';
        }
    });

    if (expandWrap) {
        if (matchingItems.length > 5) {
            expandWrap.style.display = 'block';
            if (btnText && btnIcon) {
                if (calTimelineExpanded) {
                    btnText.textContent = 'Show Less (Collapse to 5)';
                    btnIcon.textContent = 'expand_less';
                } else {
                    btnText.textContent = `View More Events (${matchingItems.length - 5} More)`;
                    btnIcon.textContent = 'expand_more';
                }
            }
        } else {
            expandWrap.style.display = 'none';
        }
    }
}

function filterCalTimeline(filter) {
    if (currentCalTimelineFilter === filter && filter !== 'ALL') {
        filter = 'ALL';
    }
    currentCalTimelineFilter = filter;
    document.querySelectorAll('#calendarFilterBar .fbtn').forEach(btn => btn.classList.remove('active'));
    const btnMap = {
        'ALL': 'calFilterAll',
        'HISTORY': 'calFilterHist',
        'DIVIDEND': 'calFilterDiv',
        'OPTION': 'calFilterOpt',
        'EARNINGS': 'calFilterEarn',
        'PAST': 'calFilterPast',
    };
    if (btnMap[filter]) {
        const activeBtn = document.getElementById(btnMap[filter]);
        if (activeBtn) activeBtn.classList.add('active');
    }

    const calBubbles = document.querySelectorAll('.cal-event-bubble');
    calBubbles.forEach(el => {
        if (filter === 'ALL') {
            el.style.display = 'block';
        } else if (filter === 'PAST') {
            el.style.display = el.dataset.isPast === '1' ? 'block' : 'none';
        } else {
            el.style.display = el.dataset.category === filter ? 'block' : 'none';
        }
    });

    updateCalTimelineVisibility(filter);
}

function openCalEventCard(encodedJson) {
    const ev = JSON.parse(decodeURIComponent(encodedJson));
    const modal = document.getElementById('calEventModal');
    const title = document.getElementById('calModalTitle');
    const cnt = document.getElementById('calModalCnt');
    if (!modal || !cnt) return;

    const isOption = ev.category && ev.category.startsWith('OPTION');
    const isHistory = ev.category === 'HISTORY';
    const isDividend = ev.category && ev.category.startsWith('DIVIDEND');
    const isEarnings = ev.category && ev.category.startsWith('EARNINGS');

    if (isOption) {
        const optStrike = formatStrikePrice(ev.strike);
        title.innerHTML = `<span class="material-symbols-outlined" style="font-size:16px; vertical-align:middle; margin-right:4px;">lock</span> Option Expiration Card: ${ev.symbol} ${optStrike}`;
    } else if (isHistory) {
        const symbolText = ev.realSymbol && ev.realSymbol !== 'CURRENCY_USD' && ev.realSymbol !== 'USD' ? ` (${ev.realSymbol})` : '';
        title.innerHTML = `<span class="material-symbols-outlined" style="font-size:16px; vertical-align:middle; margin-right:4px;">receipt_long</span> Transaction: ${ev.accountNickname || 'Broker'}${symbolText}`;
    } else if (isDividend) {
        title.innerHTML = `<span class="material-symbols-outlined" style="font-size:16px; vertical-align:middle; margin-right:4px;">payments</span> Dividend Details: ${ev.symbol}`;
    } else if (isEarnings) {
        title.innerHTML = `<span class="material-symbols-outlined" style="font-size:16px; vertical-align:middle; margin-right:4px;">campaign</span> Earnings Release: ${ev.symbol}`;
    } else {
        title.innerHTML = `<span class="material-symbols-outlined" style="font-size:16px; vertical-align:middle; margin-right:4px;">trending_up</span> Event Details: ${ev.symbol}`;
    }
    
    let accountRowsHtml = '';
    if (isOption && ev.accountBreakdown && ev.accountBreakdown.length > 0) {
        accountRowsHtml = `
            <div style="margin-top:14px; background:var(--bg2); border:1px solid var(--border); border-radius:10px; padding:12px;">
                <h5 style="font-size:12px; font-weight:700; color:var(--text); margin:0 0 8px 0; text-transform:uppercase; display:flex; align-items:center; gap:6px;">
                    <span><span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;">account_balance</span> Account Level Cash Projections & Collateral</span>
                </h5>
                <div style="overflow-x:auto; width:100%;">
                    <table style="width:100%; border-collapse:collapse; font-size:11px;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--border); color:var(--muted); text-align:left;">
                                <th style="padding:4px 0;">Account</th>
                                <th style="padding:4px 0; text-align:right;">Current Cash</th>
                                <th style="padding:4px 0; text-align:right;">Shares Held</th>
                                <th style="padding:4px 0; text-align:right;">Cash Released if Called</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${ev.accountBreakdown.map(a => `
                                <tr style="border-bottom:1px solid var(--border);">
                                    <td style="padding:6px 0;">
                                        <strong style="color:var(--text);">${a.nickname}</strong>
                                        <span style="font-size:10px; color:var(--muted); font-weight:normal;">(***${a.accountNumber.slice(-4)})</span>
                                    </td>
                                    <td style="padding:6px 0; text-align:right;">$${a.cashAvailable.toLocaleString()}</td>
                                    <td style="padding:6px 0; text-align:right;">${a.sharesHeld} sh</td>
                                    <td style="padding:6px 0; text-align:right;" class="g"><strong>+$${a.assignedCashIfExercised.toLocaleString()}</strong></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    let detailGridHtml = '';
    if (isOption) {
        detailGridHtml = `
            <div>Option Market Value: <strong style="color:var(--yellow);">${ev.marketValue || 'N/A'}</strong></div>
            <div>Pledged Collateral: <strong class="g">${ev.pledgedShares || '0'} Shares (${ev.symbol})</strong></div>
        `;
    } else if (isHistory) {
        detailGridHtml = `
            <div>Net Cash Impact: <strong class="${ev.amount >= 0 ? 'g' : 'r'}">${ev.marketValue || ev.amount}</strong></div>
            <div>Details: <strong>${ev.details || 'N/A'}</strong></div>
        `;
    } else if (isDividend) {
        detailGridHtml = `
            <div>Dividend Payout: <strong class="g">${ev.marketValue || ev.totalPayout || 'N/A'}</strong></div>
            <div>Rate per Share: <strong>$${ev.amountPerShare || '0.00'}</strong></div>
            <div>Shares Held: <strong>${ev.sharesHeld || '0'} sh</strong></div>
        `;
    } else if (isEarnings) {
        detailGridHtml = `
            <div style="grid-column: span 2;">Details: <strong>${ev.details || 'N/A'}</strong></div>
        `;
    }

    let aiReviewHtml = '';
    if (isOption) {
        const optStrike = formatStrikePrice(ev.strike);
        aiReviewHtml = `
            <div style="margin-top:14px; background:var(--bg2); border:1px solid var(--border); border-radius:10px; padding:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h5 style="font-size:12px; font-weight:700; color:var(--text); margin:0; text-transform:uppercase; display:flex; align-items:center; gap:6px;">
                        <span><span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;">psychology</span> AI Option Analysis</span>
                    </h5>
                    <button class="hbtn hbtn-blue" style="font-size:11px; padding:4px 8px;" onclick="triggerAiReview('${ev.symbol}', '${optStrike}')">
                        <span class="material-symbols-outlined" style="font-size:14px;">auto_awesome</span> Generate Review
                    </button>
                </div>
                <div id="aiReviewContainer" style="display:none; margin-top:12px; padding:12px; background:rgba(30,136,229,0.1); border:1px solid rgba(30,136,229,0.3); border-radius:8px;">
                    <div style="display:flex; align-items:center; gap:8px; color:var(--blue); font-size:12px; font-weight:600; margin-bottom:6px;">
                        <span class="material-symbols-outlined" style="font-size:16px; animation:spin 2s linear infinite;">progress_activity</span>
                        Generating AI Analysis...
                    </div>
                    <div style="font-size:11px; color:var(--text); line-height:1.5; opacity:0.8;">
                        Analyzing greek exposure, premium decay, and probability of assignment for ${ev.symbol} ${optStrike}...
                    </div>
                </div>
            </div>
        `;
    }

    const optStrike = formatStrikePrice(ev.strike);
    cnt.innerHTML = `
        <div style="background:var(--bg3); padding:14px; border-radius:10px; border:1px solid var(--border); margin-bottom:14px;">
            <h4 style="font-size:16px; font-weight:800; color:var(--text); margin:0 0 6px 0;">${ev.title}</h4>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:12px; margin-top:10px;">
                <div>Event Date: <strong style="color:var(--blue);">${ev.date}</strong></div>
                <div>Category: <strong style="color:var(--purple);">${ev.category}</strong></div>
                ${detailGridHtml}
            </div>
        </div>

        ${aiReviewHtml}
        ${accountRowsHtml}

        <div style="background:rgba(2,132,199,0.08); border:1px solid rgba(2,132,199,0.25); border-radius:8px; padding:12px; margin-top:14px; margin-bottom:16px;">
            <h5 style="font-size:13px; font-weight:700; color:var(--blue); margin:0 0 6px 0; display:flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:16px;">lightbulb</span> Event Details</h5>
            <p style="margin:0; font-size:12px; color:var(--text); line-height:1.5;">
                ${isOption 
                    ? `On <strong>${ev.date}</strong>, this <strong>${ev.symbol} ${optStrike} Call</strong> expires. If exercised (assigned), selling your <strong>${ev.pledgedShares} shares</strong> at the <strong>${optStrike} strike price</strong> releases <strong class="g">+$${ev.assignedCashIfExercised ? ev.assignedCashIfExercised.toLocaleString() : '0'} in cash liquidity</strong> back into your brokerage account!`
                    : (isHistory ? `Recorded transaction activity in your connected brokerage account: <strong>${ev.details}</strong>.` : `This is a recorded <strong>${ev.symbol}</strong> event on your portfolio calendar.`)}
            </p>
        </div>
        <div style="display:flex; justify-content:flex-end;">
            <a href="/screener?symbol=${ev.symbol}" class="hbtn hbtn-blue" style="text-decoration:none; display:inline-flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:16px;">bolt</span> Inspect ${ev.symbol} Option Chain</a>
        </div>
    `;

    modal.style.display = 'flex';
}

function openDateAccountSummaryModal(dateKey) {
    const proj = window.globalCashProjectionsByDate ? window.globalCashProjectionsByDate[dateKey] : null;
    const allEventsOnDate = window.globalEventsByDate ? (window.globalEventsByDate[dateKey] || []) : [];
    // Only option contracts for the expiring contracts list
    const eventsOnDate = allEventsOnDate.filter(e => e.category && e.category.startsWith('OPTION_'));

    const modal = document.getElementById('calEventModal');
    const title = document.getElementById('calModalTitle');
    const cnt = document.getElementById('calModalCnt');
    if (!modal || !cnt) return;

    title.innerHTML = `<span class="material-symbols-outlined" style="font-size:18px; vertical-align:middle; margin-right:4px;">event_available</span> Expiration & Cash Availability — ${dateKey}`;

    let optionsListHtml = eventsOnDate.map(e => {
        const typeStr = e.optionType || e.type || (e.category && e.category.includes('PUT') ? 'PUT' : (e.title && e.title.includes('PUT') ? 'PUT' : 'CALL'));
        const isPut = typeStr === 'PUT';
        const isGreen = e.predictedOutcome && (e.predictedOutcome.includes('Worthless') || e.predictedOutcome.includes('Called Away'));
        const isBlue = e.predictedOutcome && e.predictedOutcome.includes('Assigned');
        const badgeColor = isGreen ? 'rgba(34,197,94,0.15); color:var(--green); border-color:rgba(34,197,94,0.35);' : (isBlue ? 'rgba(56,189,248,0.15); color:var(--blue); border-color:rgba(56,189,248,0.35);' : 'rgba(245,158,11,0.15); color:var(--yellow); border-color:rgba(245,158,11,0.35);');
        const predBadge = e.predictedOutcome ? `<span style="display:inline-flex; align-items:center; font-size:10px; font-weight:700; background:${badgeColor} border:1px solid; border-radius:4px; padding:1px 6px;">${e.predictedOutcome}</span>` : '';
        const closeInfo = e.closePrice && e.closePrice !== 'N/A' ? `<span style="font-size:11px; color:var(--muted);">Close: $${e.closePrice}</span>` : '';
        const impactStr = e.predictedCashImpact || (e.assignedCashIfExercised ? `+$${e.assignedCashIfExercised.toLocaleString()}` : '$0.00');
        return `
        <tr style="border-bottom:1px solid var(--border);">
            <td style="padding:6px 0;">
                <strong style="color:var(--text); font-size:12.5px;">${e.symbol}</strong>
                <span class="opt-strategy-pill ${isPut ? 'opt-strat-put' : 'opt-strat-call'}" style="font-size:9.5px; padding:1px 5px; margin-left:3px;">${formatStrikePrice(e.strike)} ${typeStr}</span>
            </td>
            <td style="padding:6px 8px; text-align:center;">${closeInfo}</td>
            <td style="padding:6px 8px; text-align:center;">${predBadge}</td>
            <td style="padding:6px 8px; text-align:right; font-weight:600; color:var(--muted);">${e.contracts} Cont.</td>
            <td style="padding:6px 0; text-align:right;" class="g"><strong>${impactStr}</strong></td>
        </tr>
    `;
    }).join('');

    let accountSummaryHtml = '';
    if (proj && proj.accountSummary) {
        accountSummaryHtml = proj.accountSummary.map(a => `
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:5px 0;">
                    <strong style="color:var(--text); font-size:11.5px;">${a.nickname}</strong>
                    <span style="font-size:10px; color:var(--muted);">(***${a.accountNumber.slice(-4)})</span>
                </td>
                <td style="padding:5px 8px; text-align:right; font-size:11.5px;">$${a.startingCash.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                <td style="padding:5px 8px; text-align:right; font-size:11.5px;" class="g"><strong>+$${a.freedOnDate.toLocaleString()}</strong></td>
                <td style="padding:5px 0; text-align:right; font-size:11.5px;" style="color:var(--blue);"><strong>$${a.projectedAccountCash.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</strong></td>
            </tr>
        `).join('');
    }

    cnt.innerHTML = `
        <!-- KPI STRIP -->
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
            <div style="background:rgba(21,128,61,0.12); border:1px solid rgba(63,185,80,0.35); padding:10px 14px; border-radius:10px; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:11px; color:var(--muted); text-transform:uppercase; font-weight:700;">Date Cash Inflow / Freed</span>
                <strong class="g" style="font-size:18px; font-family:'Outfit',sans-serif;">+$${proj ? proj.totalAssignedCash.toLocaleString() : '0'}</strong>
            </div>
            <div style="background:rgba(56,189,248,0.1); border:1px solid rgba(56,189,248,0.35); padding:10px 14px; border-radius:10px; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:11px; color:var(--muted); text-transform:uppercase; font-weight:700;">Projected Portfolio Cash</span>
                <strong style="font-size:18px; font-family:'Outfit',sans-serif; color:var(--blue);">$${proj ? proj.projectedPortfolioCash.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) : '0.00'}</strong>
            </div>
        </div>

        <!-- EXPIRING CONTRACTS TABLE -->
        <div style="background:var(--bg3); padding:12px 14px; border-radius:10px; border:1px solid var(--border); margin-bottom:12px;">
            <h5 style="font-size:11.5px; font-weight:700; color:var(--text); margin:0 0 6px 0; text-transform:uppercase; display:flex; align-items:center; gap:5px;">
                <span class="material-symbols-outlined" style="font-size:15px; color:var(--blue);">assignment</span> Expiring Contracts (${eventsOnDate.length} Total)
            </h5>
            <table style="width:100%; border-collapse:collapse; font-size:11.5px;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border); color:var(--muted); text-align:left; font-size:10.5px;">
                        <th style="padding:3px 0;">Contract</th>
                        <th style="padding:3px 8px; text-align:center;">Today's Close</th>
                        <th style="padding:3px 8px; text-align:center;">Settlement Outcome</th>
                        <th style="padding:3px 8px; text-align:right;">Size</th>
                        <th style="padding:3px 0; text-align:right;">Cash Impact</th>
                    </tr>
                </thead>
                <tbody>
                    ${optionsListHtml}
                </tbody>
            </table>
        </div>

        <!-- ACCOUNT CASH PROJECTIONS TABLE -->
        <div style="background:var(--bg2); border:1px solid var(--border); border-radius:10px; padding:12px 14px; margin-bottom:12px;">
            <h5 style="font-size:11.5px; font-weight:700; color:var(--text); margin:0 0 6px 0; text-transform:uppercase; display:flex; align-items:center; gap:5px;">
                <span class="material-symbols-outlined" style="font-size:15px; color:var(--purple);">account_balance</span> Account Balances
            </h5>
            <table style="width:100%; border-collapse:collapse; font-size:11px;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border); color:var(--muted); text-align:left; font-size:10px;">
                        <th style="padding:3px 0;">Account</th>
                        <th style="padding:3px 8px; text-align:right;">Current Cash</th>
                        <th style="padding:3px 8px; text-align:right;">Freed on Date</th>
                        <th style="padding:3px 0; text-align:right;">Projected Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${accountSummaryHtml}
                </tbody>
            </table>
        </div>

        <!-- ACTION FOOTER -->
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; background:rgba(88,166,255,0.06); border:1px solid rgba(88,166,255,0.2); padding:10px 14px; border-radius:8px;">
            <span style="font-size:11px; color:var(--muted);">Stage new trades with projected <strong class="g">$${proj ? proj.projectedPortfolioCash.toLocaleString(undefined, {maximumFractionDigits:0}) : '0'}</strong> cash in Screener.</span>
            <a href="/screener?projectedCash=${proj ? proj.projectedPortfolioCash : 0}" class="hbtn hbtn-green" style="text-decoration:none; white-space:nowrap; padding:6px 14px; font-size:11.5px; display:inline-flex; align-items:center; gap:4px;">
                <span class="material-symbols-outlined" style="font-size:15px;">bolt</span> Stage Trade Ideas →
            </a>
        </div>
    `;

    modal.style.display = 'flex';
}




function toggleAccCard(cardId) {
    const body = document.getElementById(cardId + '_body');
    const chevron = document.getElementById(cardId + '_chevron');
    if (body.style.display === 'none' || !body.style.display) {
        body.style.display = 'block';
        if (chevron) chevron.style.transform = 'rotate(180deg)';
    } else {
        body.style.display = 'none';
        if (chevron) chevron.style.transform = 'rotate(0deg)';
    }
}

function toggleMorePositions(containerId, btn, count) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const isHidden = container.style.display === 'none' || !container.style.display;
    if (isHidden) {
        container.style.display = 'flex';
        if (btn) {
            btn.innerHTML = `
                <span class="material-symbols-outlined" style="font-size:16px;">expand_less</span>
                <span>View Less</span>
            `;
        }
    } else {
        container.style.display = 'none';
        if (btn) {
            btn.innerHTML = `
                <span class="material-symbols-outlined" style="font-size:16px;">expand_more</span>
                <span>View More (${count} more equities)</span>
            `;
        }
    }
}

function setAccountViewLayout(mode) {
    const cardsContainer = document.getElementById('accountCardsContainer');
    const secContainer = document.getElementById('securityBreakdownContainer');
    const btnGrid = document.getElementById('accViewModeGrid');
    const btnList = document.getElementById('accViewModeList');
    const btnSec = document.getElementById('accViewModeSec');

    if (!cardsContainer || !secContainer) return;

    if (btnGrid) btnGrid.classList.remove('active');
    if (btnList) btnList.classList.remove('active');
    if (btnSec) btnSec.classList.remove('active');

    if (mode === 'security') {
        cardsContainer.style.display = 'none';
        secContainer.style.display = 'block';
        if (btnSec) btnSec.classList.add('active');
    } else {
        secContainer.style.display = 'none';
        cardsContainer.style.display = 'grid';

        if (mode === 'list') {
            cardsContainer.style.gridTemplateColumns = '1fr';
            cardsContainer.classList.add('account-mode-list');
            cardsContainer.classList.remove('account-mode-grid');
            if (btnList) btnList.classList.add('active');
        } else {
            cardsContainer.style.gridTemplateColumns = 'repeat(auto-fit, minmax(320px, 1fr))';
            cardsContainer.classList.add('account-mode-grid');
            cardsContainer.classList.remove('account-mode-list');
            if (btnGrid) btnGrid.classList.add('active');
        }
    }
}



function handlePortfolioDeepLinks() {
    const urlParams = new URLSearchParams(window.location.search);
    const hash = window.location.hash;

    // 1. Determine Tab/SubView
    let targetTab = urlParams.get('tab') || 'holdings';
    if (hash === '#subViewCalendar' || hash === '#calendar') {
        targetTab = 'calendar';
    } else if (hash === '#subViewHoldings' || hash === '#holdings') {
        targetTab = 'holdings';
    } else if (hash === '#subViewHistory' || hash === '#history') {
        targetTab = 'history';
    } else if (hash === '#subViewOrders' || hash === '#orders') {
        targetTab = 'orders';
    } else if (hash === '#subViewOptions' || hash === '#options' || targetTab === 'options') {
        targetTab = 'options';
    }

    switchPortSubView(targetTab);

    // 2. View Mode (grid, list, security)
    const viewMode = urlParams.get('view') || 'grid';
    if (viewMode === 'security') {
        setAccountViewLayout('security');
    } else if (viewMode === 'list') {
        setAccountViewLayout('list');
    } else {
        setAccountViewLayout('grid');
    }

    // 3. Handle Account Deep Linking
    const accountParam = urlParams.get('account') || (hash.startsWith('#account_') ? hash.replace('#account_', '') : null);
    const symbolParam = urlParams.get('symbol') || urlParams.get('q') || (hash.startsWith('#symbol_') ? hash.replace('#symbol_', '') : null);
    const typeParam = urlParams.get('type');
    const searchParam = urlParams.get('search');

    if (targetTab === 'history') {
        // Deep-link into history filters
        if (accountParam) {
            const accSelect = document.getElementById('histAccountSelect');
            if (accSelect) {
                // Check if options match account number or nickname
                currentHistAccountFilter = accountParam;
                accSelect.value = accountParam;
            }
        }
        if (typeParam) {
            setHistTypeFilter(typeParam.toUpperCase());
        }
        if (symbolParam || searchParam) {
            const searchVal = symbolParam || searchParam;
            const searchInput = document.getElementById('histSearchInput');
            if (searchInput) {
                searchInput.value = searchVal;
                currentHistSearchTerm = searchVal;
            }
        }
        loadPortfolioHistory(currentHistDays || 'THIS_YEAR', true);
    } else if (targetTab === 'holdings') {
        // If symbol specified and layout is security, focus the security row
        if (symbolParam && viewMode === 'security') {
            setTimeout(() => {
                const secRow = document.getElementById(`sec_row_${symbolParam.toUpperCase()}`) || document.querySelector(`tr[data-symbol="${symbolParam.toUpperCase()}"]`);
                if (secRow) {
                    secRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    secRow.classList.add('target-highlight');
                    setTimeout(() => secRow.classList.remove('target-highlight'), 6000);
                }
            }, 300);
        } else if (accountParam) {
            // Find account card
            setTimeout(() => {
                const targetCard = document.getElementById(`account_card_${accountParam}`) || 
                                   document.querySelector(`[data-account-id="${accountParam}"]`) || 
                                   document.querySelector(`[data-account-number="${accountParam}"]`) ||
                                   document.querySelector(`[data-account-name*="${accountParam}" i]`);
                if (targetCard) {
                    // Open the card body if closed
                    const cardBody = targetCard.querySelector('[id$="_body"]');
                    const chevron = targetCard.querySelector('[id$="_chevron"]');
                    if (cardBody && (cardBody.style.display === 'none' || !cardBody.style.display)) {
                        cardBody.style.display = 'block';
                        if (chevron) chevron.style.transform = 'rotate(180deg)';
                    }

                    targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    targetCard.classList.add('target-highlight');
                    setTimeout(() => targetCard.classList.remove('target-highlight'), 6000);

                    // If symbol also specified, highlight that specific position inside the card
                    if (symbolParam) {
                        const posItem = targetCard.querySelector(`[data-symbol="${symbolParam.toUpperCase()}"]`);
                        if (posItem) {
                            posItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            posItem.classList.add('target-highlight');
                            setTimeout(() => posItem.classList.remove('target-highlight'), 6000);
                        }
                    }
                }
            }, 300);
        } else if (symbolParam) {
            // Highlight all position items with this symbol across cards
            setTimeout(() => {
                const matchingPositions = document.querySelectorAll(`[data-symbol="${symbolParam.toUpperCase()}"]`);
                if (matchingPositions.length > 0) {
                    const firstPos = matchingPositions[0];
                    // Expand parent card if closed
                    const parentCard = firstPos.closest('.acc-layout-card');
                    if (parentCard) {
                        const cardBody = parentCard.querySelector('[id$="_body"]');
                        const chevron = parentCard.querySelector('[id$="_chevron"]');
                        if (cardBody) {
                            cardBody.style.display = 'block';
                            if (chevron) chevron.style.transform = 'rotate(180deg)';
                        }
                    }
                    firstPos.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    matchingPositions.forEach(p => {
                        p.classList.add('target-highlight');
                        setTimeout(() => p.classList.remove('target-highlight'), 6000);
                    });
                }
            }, 300);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    handlePortfolioDeepLinks();
});

window.addEventListener('hashchange', () => {
    handlePortfolioDeepLinks();
});

async function triggerAiReview(symbol, strike) {
    const container = document.getElementById('aiReviewContainer');
    if (!container) return;
    
    // Show the container with loading state
    container.style.display = 'block';
    container.innerHTML = `
        <div style="display:flex; align-items:center; gap:8px; color:var(--blue); font-size:12px; font-weight:600; margin-bottom:6px;">
            <span class="material-symbols-outlined" style="font-size:16px; animation:spin 2s linear infinite;">progress_activity</span>
            Generating AI Analysis...
        </div>
        <div style="font-size:11px; color:var(--text); line-height:1.5; opacity:0.8;">
            Analyzing greek exposure, premium decay, and probability of assignment for ${symbol} ${strike}...
        </div>
    `;
    
    try {
        const res = await fetch(`/api/ai/review-option/${encodeURIComponent(symbol)}/${encodeURIComponent(strike)}`);
        if (!res.ok) throw new Error('API error');
        const json = await res.json();
        
        if (json.status === 'success' && json.data) {
            const data = json.data;
            const badgeColor = data.status && data.status.toLowerCase().includes('risk') ? 'var(--red)' : 'var(--green)';
            const icon = badgeColor === 'var(--red)' ? 'warning' : 'check_circle';
            
            container.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                    <div style="display:flex; align-items:center; gap:8px; color:${badgeColor}; font-size:13px; font-weight:700;">
                        <span class="material-symbols-outlined" style="font-size:18px;">${icon}</span>
                        ${data.status || 'Analysis Complete'}
                    </div>
                </div>
                <div style="font-size:12px; color:var(--text); line-height:1.5; margin-bottom:8px;">
                    ${data.analysis || 'Analysis generation completed.'}
                </div>
                <div style="display:flex; flex-direction:column; gap:4px; font-size:11px;">
                    <div style="display:flex; justify-content:space-between; padding-bottom:4px; border-bottom:1px solid rgba(255,255,255,0.05);">
                        <span style="color:var(--muted);">Probability of Assignment:</span>
                        <strong style="color:var(--text);">${data.probabilityOfAssignment || 'Unknown'}</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding-top:2px;">
                        <span style="color:var(--muted);">AI Recommended Action:</span>
                        <strong style="color:var(--text);">${data.action || 'No action'}</strong>
                    </div>
                </div>
            `;
        } else {
            throw new Error('Invalid response format');
        }
    } catch(e) {
        console.error(e);
        container.innerHTML = `
            <div style="display:flex; align-items:center; gap:8px; color:var(--red); font-size:12px; font-weight:600; margin-bottom:6px;">
                <span class="material-symbols-outlined" style="font-size:16px;">error</span>
                Analysis Failed
            </div>
            <div style="font-size:11px; color:var(--text); line-height:1.5;">
                Unable to generate AI review. Please check if your LLM API Key is configured in settings.
            </div>
        `;
    }
}

async function loadAdvisorInsights() {
    const grid = document.getElementById('advisorNuggetsGrid');
    if (!grid) return;
    
    grid.innerHTML = `
        <div style="grid-column: 1 / -1; color:var(--muted); font-size:12px; display:flex; align-items:center; gap:8px;">
            <span class="material-symbols-outlined" style="font-size:16px; animation:spin 2s linear infinite;">sync</span>
            Scanning portfolio for tax savings, cash yield enhancements, and risk alerts...
        </div>
    `;

    try {
        const res = await fetch('/api/broker/advisor/insights');
        if (!res.ok) throw new Error('Failed to fetch advisor insights');
        const json = await res.json();
        const nuggets = json.data?.summary_nuggets || [];

        if (nuggets.length === 0) {
            grid.innerHTML = `
                <div style="grid-column: 1 / -1; color:var(--muted); font-size:12px;">
                    No critical advisor warnings or actions required at this time. Portfolio is well-balanced.
                </div>
            `;
            return;
        }

        grid.innerHTML = nuggets.map(nug => {
            let catClass = 'tax';
            let iconName = 'savings';
            if (nug.category.includes('Income')) {
                catClass = 'income';
                iconName = 'payments';
            } else if (nug.category.includes('Growth') || nug.category.includes('Risk')) {
                catClass = 'growth';
                iconName = 'trending_up';
            }

            return `
                <div class="nugget-card">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span class="nugget-tag ${catClass}">
                                <span class="material-symbols-outlined" style="font-size:14px;">${iconName}</span>
                                ${nug.category}
                            </span>
                            <span style="font-size:10px; font-weight:700; color:${nug.impact_level === 'High' ? 'var(--red)' : (nug.impact_level === 'Medium' ? 'var(--yellow)' : 'var(--muted)')};">
                                ${nug.impact_level} Impact
                            </span>
                        </div>
                        <div class="nugget-title">${nug.title}</div>
                        <div class="nugget-desc">${nug.description}</div>
                    </div>
                    <div class="nugget-action">
                        <span class="material-symbols-outlined" style="font-size:14px; margin-right:4px; color:var(--blue);">lightbulb</span>
                        ${nug.action}
                    </div>
                </div>
            `;
        }).join('');
    } catch(err) {
        console.error('Advisor Insights Error:', err);
        grid.innerHTML = `
            <div style="grid-column: 1 / -1; color:var(--red); font-size:12px;">
                Unable to load advisor insights at this time.
            </div>
        `;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadAdvisorInsights();
});
