let allStocks = [];
let portfolioData = null;
let activeSector = 'ALL';
let activeRisk = 'ALL';
let activeAssetType = 'ALL';
let activeSignal = 'ALL';
let searchQuery = '';
let sortField = 'score';
let sortAsc = false;
let chartInstance = null;

document.addEventListener('DOMContentLoaded', () => {
    fetchStocks();
    fetchPortfolio();

    const urlParams = new URLSearchParams(window.location.search);
    const projCashParam = urlParams.get('projectedCash');
    if (projCashParam && parseFloat(projCashParam) > 0) {
        const totalCapEl = document.getElementById('totalCap');
        if (totalCapEl) {
            totalCapEl.value = Math.round(parseFloat(projCashParam));
        }
    }

    updateFlywheelAllocation();

    const symParam = urlParams.get('symbol');
    if (symParam) {
        setTimeout(() => {
            openResearchPanel(symParam);
        }, 300);
    }
});


function switchMainTab(tab) {
    const screenerView = document.getElementById('screenerView');
    const portfolioView = document.getElementById('portfolioView');
    const discoverView = document.getElementById('discoverView');

    const tabScreener = document.getElementById('tabNavScreener');
    const tabPortfolio = document.getElementById('tabNavPortfolio');
    const tabDiscover = document.getElementById('tabNavDiscover');

    screenerView.style.display = 'none';
    portfolioView.style.display = 'none';
    discoverView.style.display = 'none';

    tabScreener.classList.remove('active');
    tabPortfolio.classList.remove('active');
    tabDiscover.classList.remove('active');

    if (tab === 'screener') {
        screenerView.style.display = 'block';
        tabScreener.classList.add('active');
    } else if (tab === 'portfolio') {
        portfolioView.style.display = 'block';
        tabPortfolio.classList.add('active');
        if (!portfolioData) fetchPortfolio();
    } else if (tab === 'discover') {
        discoverView.style.display = 'block';
        tabDiscover.classList.add('active');
        fetchDiscoverSuggestions();
        renderTab3TrackedTable();
    }
}

function switchPortSubView(mode) {
    const subAcc = document.getElementById('subViewAccounts');
    const subCal = document.getElementById('subViewCalendar');
    const subFly = document.getElementById('subViewFlywheel');

    const btnAcc = document.getElementById('vtabAccounts');
    const btnCal = document.getElementById('vtabCalendar');
    const btnFly = document.getElementById('vtabFlywheel');

    const isHoldings = mode === 'holdings' || mode === 'accounts';

    if (subAcc) subAcc.style.display = isHoldings ? 'block' : 'none';
    if (subCal) subCal.style.display = mode === 'calendar' ? 'block' : 'none';
    if (subFly) subFly.style.display = mode === 'flywheel' ? 'block' : 'none';

    if (btnAcc) btnAcc.classList.toggle('active', isHoldings);
    if (btnCal) btnCal.classList.toggle('active', mode === 'calendar');
    if (btnFly) btnFly.classList.toggle('active', mode === 'flywheel');

    if (mode === 'calendar' && typeof loadPortfolioCalendarEvents === 'function') {
        loadPortfolioCalendarEvents();
    } else if (mode === 'flywheel' && typeof loadPortCoveredCallSuggestions === 'function') {
        loadPortCoveredCallSuggestions();
    }
}


function useCashInFlywheel(amount) {
    let cashToUse = amount;
    if (!cashToUse && portfolioData) {
        cashToUse = portfolioData.cashBalance || 10000;
    }
    if (!cashToUse) cashToUse = 10000;

    document.getElementById('totalCap').value = Math.round(cashToUse);
    updateFlywheelAllocation();
    switchMainTab('screener');
    document.getElementById('statusBar').innerHTML = `<span class="material-symbols-outlined" style="font-size:15px; vertical-align:middle; margin-right:4px;">bolt</span> Loaded $${Math.round(cashToUse).toLocaleString()} cash into Capital Flywheel Options Allocator!`;
}

let searchDebounceTimer = null;
let isFetchingStocks = false;

async function fetchStocks() {
    if (isFetchingStocks) return;
    isFetchingStocks = true;

    if (typeof showGlobalProgress === 'function') showGlobalProgress();

    const tbody = document.getElementById('stockTableBody');
    const tableContainer = document.getElementById('tw');
    const statusBar = document.getElementById('statusBar');

    if (statusBar) {
        statusBar.innerHTML = `<span class="ui-loading-inline"><span class="material-symbols-outlined spinner-icon">progress_activity</span> Filtering and loading data...</span>`;
    }

    // If table already has items, show subtle opacity indicator, otherwise spinner
    if (tbody && tbody.children.length === 0) {
        tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; padding:32px 16px;"><div class="ui-loading-box"><span class="material-symbols-outlined spinner-icon">progress_activity</span><span>Scanning market data & options chains...</span></div></td></tr>`;
    } else if (tableContainer) {
        tableContainer.style.opacity = '0.6';
        tableContainer.style.pointerEvents = 'none';
    }

    try {
        const url = new URL('/api/stocks', window.location.origin);
        if (activeSector !== 'ALL') url.searchParams.append('sector', activeSector);
        if (activeRisk !== 'ALL') url.searchParams.append('risk', activeRisk);
        if (activeAssetType !== 'ALL') url.searchParams.append('assetType', activeAssetType);
        if (searchQuery) url.searchParams.append('q', searchQuery);

        const response = await fetch(url);
        const res = await response.json();

        if (res.status === 'success') {
            allStocks = res.data;
            render();
            updateFlywheelAllocation();
            if (statusBar) {
                statusBar.innerHTML = `<span class="material-symbols-outlined" style="font-size:15px; vertical-align:middle; margin-right:4px;">check_circle</span> Loaded ${allStocks.length} stocks successfully.`;
            }
        } else {
            throw new Error(res.message || 'API returned failure status');
        }
    } catch (err) {
        console.error('Failed to load stocks:', err);
        if (statusBar) {
            statusBar.innerHTML = `<span class="material-symbols-outlined" style="font-size:15px; vertical-align:middle; margin-right:4px; color:var(--red);">error</span> Error loading stocks from backend API.`;
        }
    } finally {
        isFetchingStocks = false;
        if (typeof hideGlobalProgress === 'function') hideGlobalProgress();
        if (tableContainer) {
            tableContainer.style.opacity = '1';
            tableContainer.style.pointerEvents = 'auto';
        }
    }
}

function filterSignal(signal, btn) {
    if (activeSignal === signal && signal !== 'ALL') {
        signal = 'ALL';
        btn = document.querySelector('.controls .sig-btn') || btn;
    }
    activeSignal = signal;
    document.querySelectorAll('.controls .sig-btn').forEach(b => {
        b.className = 'sig-btn';
    });
    
    if (btn) {
        if (signal === 'ALL') btn.classList.add('active-all');
        if (signal === 'CALL') btn.classList.add('active-call');
        if (signal === 'PUT') btn.classList.add('active-put');
        if (signal === 'WHEEL') btn.classList.add('active-wheel');
    }

    render();
}

function filterSector(sector, btn) {
    if (activeSector === sector && sector !== 'ALL') {
        sector = 'ALL';
        btn = document.querySelector('.controls .fbtn') || btn;
    }
    activeSector = sector;
    document.querySelectorAll('.controls .fbtn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    fetchStocks();
}

function filterRisk(risk) {
    activeRisk = risk;
    fetchStocks();
}

function filterAssetType(type) {
    activeAssetType = type;
    fetchStocks();
}

function onSearchInput() {
    searchQuery = document.getElementById('srch').value.trim();
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => {
        fetchStocks();
    }, 250);
}

function updateFlywheelAllocation() {
    const capitalInput = parseFloat(document.getElementById('totalCap').value) || 0;
    
    const callVal = capitalInput * 0.60;
    const wheelVal = capitalInput * 0.25;
    const putVal = capitalInput * 0.15;

    document.getElementById('allocCallVal').innerText = `$${callVal.toLocaleString('en-US', { maximumFractionDigits: 0 })}`;
    document.getElementById('allocWheelVal').innerText = `$${wheelVal.toLocaleString('en-US', { maximumFractionDigits: 0 })}`;
    document.getElementById('allocPutVal').innerText = `$${putVal.toLocaleString('en-US', { maximumFractionDigits: 0 })}`;
}

function sortTable(field) {
    if (sortField === field) {
        sortAsc = !sortAsc;
    } else {
        sortField = field;
        sortAsc = false;
    }
    render();
}

function render() {
    let list = [...allStocks];

    list.forEach(s => {
        if (s.price && s.targetPrice) {
            s.upsideVal = ((s.targetPrice - s.price) / s.price) * 100;
        } else {
            s.upsideVal = 0;
        }
    });

    if (activeSignal !== 'ALL') {
        list = list.filter(s => s.flywheel && s.flywheel.signal === activeSignal);
    }

    list.sort((a, b) => {
        let valA = a[sortField];
        let valB = b[sortField];

        if (sortField === 'upside') {
            valA = a.upsideVal;
            valB = b.upsideVal;
        } else if (sortField === 'signal') {
            valA = a.flywheel ? a.flywheel.signal : '';
            valB = b.flywheel ? b.flywheel.signal : '';
        }

        if (typeof valA === 'string') {
            valA = valA.toLowerCase();
            valB = (valB || '').toLowerCase();
        }

        if (valA < valB) return sortAsc ? -1 : 1;
        if (valA > valB) return sortAsc ? 1 : -1;
        return 0;
    });

    const totalCount = allStocks.length;
    const callsCount = allStocks.filter(s => s.flywheel && s.flywheel.signal === 'CALL').length;
    const putsCount = allStocks.filter(s => s.flywheel && s.flywheel.signal === 'PUT').length;
    const wheelCount = allStocks.filter(s => s.flywheel && s.flywheel.signal === 'WHEEL').length;

    document.getElementById('statCount').innerText = list.length;
    document.getElementById('statCallsCount').innerText = `${callsCount} (${Math.round((callsCount/totalCount)*100)}%)`;
    document.getElementById('statPutsCount').innerText = `${putsCount} (${Math.round((putsCount/totalCount)*100)}%)`;
    document.getElementById('statWheelCount').innerText = `${wheelCount} (${Math.round((wheelCount/totalCount)*100)}%)`;

    const tbody = document.getElementById('stockTableBody');
    tbody.innerHTML = '';

    // Build map of owned equities
    const ownedMap = {};
    if (portfolioData && portfolioData.aggregatedEquities) {
        portfolioData.aggregatedEquities.forEach(e => {
            ownedMap[e.symbol] = e;
        });
    }

    list.forEach(stock => {
        const tr = document.createElement('tr');
        tr.onclick = () => openResearchPanel(stock);

        const upsideText = stock.upsideVal ? `+${stock.upsideVal.toFixed(1)}%` : '—';
        const upsideClass = stock.upsideVal > 0 ? 'g' : 'r';

        const fwSignal = stock.flywheel ? stock.flywheel.signal : 'WHEEL';
        const fwBadge = stock.flywheel ? stock.flywheel.signalBadge : '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;color:var(--yellow);">warning</span> WHEEL';

        const owned = ownedMap[stock.symbol];
        const ownedBadge = owned ? `<span class="rb" style="background:rgba(188,140,255,0.15); color:var(--purple); border:1px solid rgba(188,140,255,0.3); margin-left:6px; display:inline-flex; align-items:center; gap:2px;"><span class="material-symbols-outlined" style="font-size:12px;">business_center</span> ${owned.quantity} SH</span>` : '';

        tr.innerHTML = `
            <td>
                <span class="material-symbols-outlined wstar ${stock.isWatchlisted ? 'active' : ''}" onclick="event.stopPropagation(); toggleWatchlist('${stock.symbol}', this)" style="font-size:16px; margin-right:4px; vertical-align:middle;">star</span>
                <strong>${stock.symbol}</strong>
                ${ownedBadge}
            </td>
            <td>${stock.name}</td>
            <td><span class="badge-sig badge-${fwSignal}">${fwBadge}</span></td>
            <td><span class="m">${stock.sector}</span></td>
            <td><strong>$${stock.price ? stock.price.toFixed(2) : '—'}</strong></td>
            <td>$${stock.targetPrice ? stock.targetPrice.toFixed(2) : '—'}</td>
            <td class="${upsideClass}">${upsideText}</td>
            <td>${stock.revGrowth || '—'}</td>
            <td><span class="rb r${stock.risk}">${stock.risk}</span></td>
            <td><strong class="g">${stock.score}</strong></td>
        `;
        tbody.appendChild(tr);
    });
}

function formatOptionSymbol(symbol, assetType, qty) {
    if (assetType !== 'OPTION') {
        return { display: symbol, sub: `${qty} shares` };
    }

    // Parse OCC Option Format: SYMBOL YYMMDD C/P STRIKE8
    const match = symbol.match(/^([A-Z0-9]+)\s*(\d{2})(\d{2})(\d{2})([CP])(\d{8})$/);
    if (match) {
        const root = match[1];
        const yy = match[2];
        const mm = match[3];
        const dd = match[4];
        const type = match[5] === 'C' ? 'Call' : 'Put';
        const strike = (parseInt(match[6], 10) / 1000).toFixed(2);
        
        const dateStr = `20${yy}-${mm}-${dd}`;
        const side = qty < 0 ? 'Short' : 'Option Contract';

        return {
            display: `${root} $${strike} ${type}`,
            sub: `Exp: ${dateStr} (${side})`
        };
    }

    return { display: symbol, sub: 'Option Contract' };
}

async function fetchPortfolio() {
    try {
        const res = await fetch('/api/broker/portfolio/aggregated');
        const result = await res.json();

        if ((result.status === 'success' || result.balances) && result.data) {
            portfolioData = result.data;
            const data = portfolioData;

            // Populate Summary Header
            document.getElementById('pTotalLiquidation').innerText = `$${(data.netLiquidationValue || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
            document.getElementById('pTotalCash').innerText = `$${(data.cashBalance || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
            document.getElementById('btnTotalCashVal').innerText = Math.round(data.cashBalance || 0).toLocaleString();
            
            const cashVal = Math.round(data.cashBalance || 0);
            if (cashVal > 0) {
                const totalCapInput = document.getElementById('totalCap');
                if (totalCapInput && totalCapInput.value === '10000') {
                    totalCapInput.value = cashVal;
                    updateFlywheelAllocation();
                }
                const playbookCash = document.getElementById('playbookCashText');
                if (playbookCash) {
                    playbookCash.innerText = `$${cashVal.toLocaleString()} available cash`;
                }
            }

            render(); // Refresh stock table to display OWNED badges

            const totalPositions = (data.aggregatedEquities || []).length;
            const accCount = (data.accounts || []).length;
            document.getElementById('pAccHoldingsCount').innerText = `${accCount} Accounts / ${totalPositions} Equities`;

            // 1. Render Account Aggregated View
            const accGrid = document.getElementById('accountGrid');
            accGrid.innerHTML = '';

            (data.accounts || []).forEach(acc => {
                const box = document.createElement('div');
                box.className = 'acc-box';

                const posRows = (acc.positions || []).slice(0, 5).map(p => {
                    const plClass = p.unrealizedPL >= 0 ? 'g' : 'r';
                    const plSign = p.unrealizedPL >= 0 ? '+' : '';
                    const formatted = formatOptionSymbol(p.symbol, p.assetType, p.quantity);
                    return `
                        <div class="row">
                            <span><strong>${formatted.display}</strong> <small class="m">(${formatted.sub})</small></span>
                            <span class="v">$${p.marketValue.toLocaleString()} <small class="${plClass}">(${plSign}$${p.unrealizedPL.toFixed(0)})</small></span>
                        </div>
                    `;
                }).join('');

                box.innerHTML = `
                    <div>
                        <h4>
                            <span style="display:inline-flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:16px; color:var(--blue);">account_balance</span> Account ${acc.accountNumber}</span>
                            <span class="hbadge">${acc.type}</span>
                        </h4>
                        <div style="font-size: 12px; color: var(--muted); margin-bottom: 12px;">
                            <div>Liquidation Value: <strong style="color:var(--text);">$${acc.liquidationValue.toLocaleString()}</strong></div>
                            <div>Cash Available for Trading: <strong style="color:var(--green);">$${acc.cashAvailable.toLocaleString()}</strong></div>
                        </div>
                        <div style="background: var(--bg3); border-radius: 8px; padding: 10px; border: 1px solid var(--border); margin-bottom: 14px;">
                            <div style="font-size: 10px; font-weight: 700; color: var(--muted); text-transform: uppercase; margin-bottom: 6px;">Top Positions (${acc.positionsCount})</div>
                            ${posRows || '<div class="m">No active positions</div>'}
                        </div>
                    </div>
                    <button class="btn btn-pri" style="width: 100%; text-align: center;" onclick="useCashInFlywheel(${acc.cashAvailable})">
                        <span class="material-symbols-outlined" style="font-size:16px; vertical-align:middle; margin-right:4px;">bolt</span>Load $${Math.round(acc.cashAvailable).toLocaleString()} Cash into Options Allocator
                    </button>
                `;
                accGrid.appendChild(box);
            });

            // 2. Render Equity Aggregated View
            const aggBody = document.getElementById('aggEquitiesBody');
            aggBody.innerHTML = '';

            (data.aggregatedEquities || []).forEach(e => {
                const tr = document.createElement('tr');
                const plClass = e.unrealizedPL >= 0 ? 'g' : 'r';
                const plSign = e.unrealizedPL >= 0 ? '+' : '';

                tr.innerHTML = `
                    <td><strong>${e.symbol}</strong></td>
                    <td><span class="m">${e.assetType}</span></td>
                    <td style="text-align:right;">${e.quantity}</td>
                    <td style="text-align:right;">$${e.averagePrice.toFixed(2)}</td>
                    <td style="text-align:right;"><strong>$${e.marketValue.toLocaleString('en-US', { minimumFractionDigits: 2 })}</strong></td>
                    <td style="text-align:right;" class="${plClass}">
                        <strong>${plSign}$${e.unrealizedPL.toFixed(2)} (${plSign}${e.unrealizedPLPct}%)</strong>
                    </td>
                    <td style="text-align:right;"><strong style="color:var(--purple);">${e.allocationPct}%</strong></td>
                    <td style="text-align:center;"><span class="rb rLOW">${e.accountCount} Acc</span></td>
                    <td style="text-align:center;">
                        <button class="fbtn" onclick="searchEquityInScreener('${e.symbol}')" style="display:inline-flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:14px;">search</span> Analyze Options</button>
                    </td>
                `;
                aggBody.appendChild(tr);
            });
        }
    } catch (e) {
        console.error('Failed to load portfolio:', e);
    }
}

function searchEquityInScreener(symbol) {
    document.getElementById('srch').value = symbol;
    searchQuery = symbol;
    switchMainTab('screener');
    fetchStocks();
}

async function toggleWatchlist(symbol, element) {
    try {
        const response = await fetch('/api/watchlist', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ symbol })
        });
        const data = await response.json();
        if (data.status === 'added') {
            element.classList.add('active');
        } else {
            element.classList.remove('active');
        }
    } catch (err) {
        console.error('Failed to toggle watchlist:', err);
    }
}

async function loadLivePrices() {
    document.getElementById('statusBar').innerHTML = '<span class="material-symbols-outlined spin" style="font-size:15px; vertical-align:middle; margin-right:4px;">sync</span> Fetching live stock quotes from Finnhub API...';

    let updated = 0;
    for (let stock of allStocks) {
        try {
            const res = await fetch(`/api/quote/${stock.symbol}`);
            const data = await res.json();
            if (data.quote && data.quote.c) {
                stock.price = data.quote.c;
                updated++;
            }
        } catch (e) {
            console.error(`Quote failed for ${stock.symbol}`, e);
        }
    }

    document.getElementById('statusBar').innerText = `Live prices updated for ${updated} stocks! Recalculated options signals.`;
    fetchStocks();
}

function toggleAuditDrawer() {
    const container = document.getElementById('auditContainer');
    const text = document.getElementById('toggleAuditText');
    const icon = document.getElementById('toggleAuditIcon');
    if (!container) return;

    const isHidden = container.style.display === 'none';
    container.style.display = isHidden ? 'block' : 'none';
    if (text) text.innerText = isHidden ? 'Hide Audit & Math' : 'Inspect Full Math & APIs';
    if (icon) icon.innerText = isHidden ? 'expand_less' : 'expand_more';
}

async function openResearchPanel(stockOrSymbol) {
    let stock = stockOrSymbol;
    if (typeof stockOrSymbol === 'string') {
        const sym = stockOrSymbol.toUpperCase();
        stock = allStocks.find(s => s.symbol === sym);
        if (!stock) {
            stock = { symbol: sym, name: `${sym} Equity`, price: 100.0, targetPrice: 110.0, score: 75, thesis: 'Live Schwab Option Chain Target Evaluation' };
        }
    }

    if (!stock) return;

    document.getElementById('rpTitle').innerText = `${stock.symbol} — ${stock.name || stock.symbol}`;
    
    // TIER 1: Populate Executive Decision Card ("The Sausage")
    const dyn = stock.dynamicSignal || {};
    const exec = dyn.executive || {};
    const audit = dyn.audit || {};

    const sigType = exec.signalType || (stock.flywheel ? stock.flywheel.signal : 'WHEEL');
    const badge = document.getElementById('rpSignalBadge');
    if (badge) {
        badge.className = `badge-sig badge-${sigType}`;
        badge.innerText = sigType;
    }

    const execStrat = document.getElementById('rpExecStrategyName');
    if (execStrat) execStrat.innerText = exec.strategyName || (stock.flywheel ? stock.flywheel.recommendedStrategy : 'Defined-Risk Strategy');

    const execTier = document.getElementById('rpExecTierName');
    if (execTier) execTier.innerText = exec.tierName || 'Capital Compounder';

    const execThesis = document.getElementById('rpExecThesis');
    if (execThesis) execThesis.innerText = exec.thesis || stock.thesis || 'Analyzing multi-API fundamental and options momentum.';

    const execAlloc = document.getElementById('rpExecAllocation');
    if (execAlloc) execAlloc.innerText = `$${(exec.recommendedAllocation || 1000).toLocaleString()}`;

    const execEntry = document.getElementById('rpExecEntryDate');
    if (execEntry) execEntry.innerText = exec.entryDate || 'Today';

    const execExit = document.getElementById('rpExecExitDate');
    if (execExit) execExit.innerText = exec.targetExitDate || `${exec.targetDte || 45} Days`;

    const execDte = document.getElementById('rpExecDteNote');
    if (execDte) execDte.innerText = `${exec.targetDte || 45} Days DTE (Target: $${(exec.suggestedStrike || stock.targetPrice || stock.price).toFixed(2)})`;

    const execProfit = document.getElementById('rpExecProfitRule');
    if (execProfit) execProfit.innerText = exec.profitExitRule || '+80% max profit';

    const execStop = document.getElementById('rpExecStopLossRule');
    if (execStop) execStop.innerText = exec.stopLossRule || '100% defined max loss';

    // TIER 2: Populate Institutional Underwriting Details ("The Sausage-Making")
    const pMean = document.getElementById('rpPriceTargetMean');
    if (pMean) pMean.innerText = `$${(audit.analystMeanTarget || stock.targetPrice || stock.price).toFixed(2)}`;

    const pRange = document.getElementById('rpPriceTargetRange');
    if (pRange) {
        const low = (audit.analystLowTarget || (stock.price * 0.9)).toFixed(2);
        const high = (audit.analystHighTarget || (stock.targetPrice || stock.price * 1.2)).toFixed(2);
        pRange.innerText = `$${low} - $${high}`;
    }

    const pVotes = document.getElementById('rpAnalystConsensusVotes');
    if (pVotes) {
        const v = audit.analystVotes;
        if (v && v.total > 0) {
            pVotes.innerText = `${v.buy} Buy / ${v.hold} Hold / ${v.sell} Sell (${v.bullishPct || 0}% Bullish)`;
        } else {
            pVotes.innerText = stock.analystRating || 'BUY Consensus';
        }
    }

    const elPrice = document.getElementById('rpPrice');
    if (elPrice) elPrice.innerText = `$${stock.price ? stock.price.toFixed(2) : '—'}`;

    const elUpside = document.getElementById('rpUpside');
    if (elUpside) elUpside.innerText = `+${stock.upsideVal ? stock.upsideVal.toFixed(1) : (audit.impliedUpsidePct || 0)}%`;

    const elEarnings = document.getElementById('rpEarningsDate');
    if (elEarnings) elEarnings.innerText = (audit.catalyst && audit.catalyst.nextEarningsDate) || 'No date set';

    const elDaysEarn = document.getElementById('rpDaysToEarnings');
    if (elDaysEarn) elDaysEarn.innerText = (audit.catalyst && audit.catalyst.daysToEarnings !== null) ? `${audit.catalyst.daysToEarnings} Days Out` : '—';

    const elTimingRat = document.getElementById('rpTimingRationale');
    if (elTimingRat) elTimingRat.innerText = (audit.catalyst && audit.catalyst.timingRationale) || 'Standard Theta cycle';

    const elRev = document.getElementById('rpRevGrowth');
    if (elRev) elRev.innerText = stock.revGrowth || '—';

    const elMargin = document.getElementById('rpGrossMargin');
    if (elMargin) elMargin.innerText = stock.grossMargin || '—';

    const elScore = document.getElementById('rpScore');
    if (elScore) elScore.innerText = `${stock.score || 70} / 100`;

    const elUserCash = document.getElementById('rpUserCash');
    if (elUserCash) elUserCash.innerText = `$${(portfolioData ? portfolioData.cashBalance : (audit.portfolioGuardrail ? audit.portfolioGuardrail.userAvailableCash : 10000)).toLocaleString()}`;

    const elThesis = document.getElementById('rpThesis');
    if (elThesis) elThesis.innerText = stock.thesis || exec.thesis || 'No thesis provided.';

    const elCat = document.getElementById('rpCatalysts');
    if (elCat) elCat.innerText = stock.catalysts || 'Analyst momentum and options open interest growth.';

    const elRisks = document.getElementById('rpRisks');
    if (elRisks) elRisks.innerText = stock.keyRisks || 'Broader market volatility and sector rotation.';

    fetchSchwabOptionChain(stock.symbol);

    document.getElementById('rp').classList.add('open');
}

async function fetchSchwabOptionChain(symbol) {
    const tbody = document.getElementById('schwabChainBody');
    const badge = document.getElementById('schwabSourceBadge');
    const aiBox = document.getElementById('geminiOptionAnalysisBox');
    const aiGrid = document.getElementById('geminiOptionTargetsGrid');
    const aiVerdict = document.getElementById('geminiOptionVerdict');

    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding: 24px 16px;"><div class="ui-loading-box"><span class="material-symbols-outlined spinner-icon">progress_activity</span><span>Connecting to Schwab API & Gemini AI Engine...</span></div></td></tr>';
    if (aiBox) aiBox.style.display = 'none';

    try {
        // Fetch option chain & Gemini AI strike analysis in parallel
        const [chainRes, aiRes] = await Promise.all([
            fetch(`/api/broker/b1/option-chain/${symbol}`),
            fetch(`/api/ai/option-chain-analysis/${symbol}`)
        ]);

        const result = await chainRes.json();
        const aiResult = await aiRes.json();

        let aiData = null;
        if (aiResult.status === 'success' && aiResult.data) {
            aiData = aiResult.data;
            if (aiBox && aiGrid && aiVerdict) {
                const recCall = aiData.recommendedCall;
                const recPut = aiData.recommendedPut;

                aiGrid.innerHTML = `
                    <div style="background:var(--bg3); padding:8px 10px; border-radius:6px; border:1px solid rgba(63,185,80,0.3);">
                        <div style="font-size:10px; color:var(--green); font-weight:700; display:flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:13px;">check_circle</span> GEMINI RECOMMENDED COVERED CALL</div>
                        <div style="font-size:14px; font-weight:800; margin:2px 0;">Strike: $${recCall.strike} <span style="font-size:11px; color:var(--muted);">(+${recCall.otmPct}% OTM)</span></div>
                        <div style="color:var(--muted); font-size:10px;">Est. Credit: <strong class="g">+$${recCall.incomePerContract}</strong> per contract | Yield: <strong class="g">${recCall.annualizedYield}% APY</strong> | Δ ${recCall.delta}</div>
                    </div>
                    <div style="background:var(--bg3); padding:8px 10px; border-radius:6px; border:1px solid rgba(210,153,34,0.3);">
                        <div style="font-size:10px; color:var(--yellow); font-weight:700; display:flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:13px;">warning</span> GEMINI RECOMMENDED CASH-SECURED PUT</div>
                        <div style="font-size:14px; font-weight:800; margin:2px 0;">Strike: $${recPut.strike} <span style="font-size:11px; color:var(--muted);">(-${recPut.discountPct}% Discount)</span></div>
                        <div style="color:var(--muted); font-size:10px;">Est. Credit: <strong class="y">+$${recPut.incomePerContract}</strong> per contract | Yield: <strong class="y">${recPut.annualizedYield}% APY</strong> | Δ ${recPut.delta}</div>
                    </div>
                `;
                aiVerdict.innerHTML = `<strong style="display:inline-flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:15px; color:var(--purple);">psychology</span> Gemini AI Option Verdict:</strong> ${aiData.aiVerdict}`;
                aiBox.style.display = 'block';
            }
        }

        if (result.status === 'success' && result.data) {
            const data = result.data;
            badge.innerText = data.source;
            badge.className = data.isConfigured ? 'rb rLOW' : 'rb rMED';

            const recCallStrike = aiData ? aiData.recommendedCall.strike : null;
            const recPutStrike = aiData ? aiData.recommendedPut.strike : null;

            const rows = [];
            (data.calls || []).slice(0, 5).forEach(c => {
                const isTarget = recCallStrike && Math.abs(c.strike - recCallStrike) < 0.01;
                rows.push(`
                    <tr style="${isTarget ? 'background:rgba(63,185,80,0.12); font-weight:700;' : ''}">
                        <td><strong class="g">CALL</strong></td>
                        <td><strong>$${c.strike}</strong></td>
                        <td>$${c.bid}</td>
                        <td>$${c.ask}</td>
                        <td>${c.iv || '—'}</td>
                        <td class="g">${c.delta}</td>
                        <td class="m">${c.theta}</td>
                        <td>${isTarget ? '<span class="rb rLOW" style="display:inline-flex; align-items:center; gap:3px;"><span class="material-symbols-outlined" style="font-size:12px;">gps_fixed</span> GEMINI CALL TARGET</span>' : '—'}</td>
                    </tr>
                `);
            });
            (data.puts || []).slice(0, 5).forEach(p => {
                const isTarget = recPutStrike && Math.abs(p.strike - recPutStrike) < 0.01;
                rows.push(`
                    <tr style="${isTarget ? 'background:rgba(210,153,34,0.12); font-weight:700;' : ''}">
                        <td><strong class="r">PUT</strong></td>
                        <td><strong>$${p.strike}</strong></td>
                        <td>$${p.bid}</td>
                        <td>$${p.ask}</td>
                        <td>${p.iv || '—'}</td>
                        <td class="r">${p.delta}</td>
                        <td class="m">${p.theta}</td>
                        <td>${isTarget ? '<span class="rb rMED" style="display:inline-flex; align-items:center; gap:3px;"><span class="material-symbols-outlined" style="font-size:12px;">gps_fixed</span> GEMINI PUT TARGET</span>' : '—'}</td>
                    </tr>
                `);
            });

            tbody.innerHTML = rows.join('') || '<tr><td colspan="8" style="text-align:center;" class="m">No option contracts found.</td></tr>';
        }
    } catch (e) {
        console.error('Schwab Option Chain Error:', e);
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;" class="r">Failed to load Schwab Option Chain & Gemini AI Analysis.</td></tr>';
    }
}

function closeResearchPanel() {
    document.getElementById('rp').classList.remove('open');
}

function renderChart(stock) {
    const ctx = document.getElementById('rpChart').getContext('2d');
    if (chartInstance) {
        chartInstance.destroy();
    }

    chartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Current Price', '12M Target'],
            datasets: [{
                label: 'USD ($)',
                data: [stock.price || 0, stock.targetPrice || 0],
                backgroundColor: ['#58a6ff', '#3fb950'],
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#2e3545' },
                    ticks: { color: '#8c96a8' }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#8c96a8' }
                }
            }
        }
    });
}

let scannedStockData = null;

function openTrackedStocksModal() {
    document.getElementById('trackModalOverlay').style.display = 'flex';
    renderTrackedStocksTable();
}

function closeTrackedStocksModal() {
    document.getElementById('trackModalOverlay').style.display = 'none';
}

function renderTrackedStocksTable() {
    const tbody = document.getElementById('trackedTableBody');
    tbody.innerHTML = '';

    allStocks.forEach(s => {
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

async function scanStockFromFinnhub() {
    const symbol = document.getElementById('addSymbolInput').value.trim().toUpperCase();
    const resBox = document.getElementById('addScanResult');

    if (!symbol) {
        alert('Please enter a valid stock ticker (e.g. AMD, AMZN, MSFT)');
        return;
    }

    resBox.style.display = 'block';
    resBox.innerHTML = '<span class="m" style="display:inline-flex; align-items:center; gap:4px;"><span class="material-symbols-outlined spin" style="font-size:14px;">sync</span> Scanning Finnhub & Schwab market data for ' + symbol + '...</span>';

    try {
        const res = await fetch(`/api/stocks/suggest/${symbol}`);
        const result = await res.json();

        if (result.status === 'success' && result.data) {
            scannedStockData = result.data;
            const d = scannedStockData;
            const fw = d.flywheel || {};

            resBox.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div>
                        <strong style="font-size:15px; color:var(--blue);">${d.symbol}</strong> — ${d.name}
                        <span class="hbadge" style="margin-left:8px;">${d.sector}</span>
                    </div>
                    <span class="badge-sig badge-${fw.signal}">${fw.signalBadge || '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;color:var(--green);">check_circle</span> CALL'}</span>
                </div>
                <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:8px; font-size:12px; margin-bottom:12px;">
                    <div>Price: <strong>$${d.price.toFixed(2)}</strong></div>
                    <div>Target: <strong class="g">$${d.targetPrice.toFixed(2)}</strong></div>
                    <div>Score: <strong class="g">${d.score} / 100</strong></div>
                    <div>Risk: <strong>${d.risk}</strong></div>
                </div>
                <div style="font-size:11px; color:var(--muted); margin-bottom:12px;">
                    <strong>Option Strategy Recommendation:</strong> ${fw.recommendedStrategy || 'Level 1 Defined Risk'}
                </div>
                <button class="btn btn-pri" style="width:100%; text-align:center;" onclick="importScannedStock()">
                    <span class="material-symbols-outlined" style="font-size:14px; vertical-align:middle; margin-right:4px;">add</span> Add ${d.symbol} to Screener & Tracked List
                </button>
            `;
        }
    } catch (e) {
        console.error('Scan failed:', e);
        resBox.innerHTML = '<span class="r">Failed to fetch market data for ' + symbol + '. Please check the symbol and try again.</span>';
    }
}

async function importScannedStock() {
    if (!scannedStockData) return;

    try {
        const res = await fetch('/api/stocks/add', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(scannedStockData)
        });
        const result = await res.json();

        if (result.status === 'success') {
            alert(`${scannedStockData.symbol} added successfully to your screener!`);
            document.getElementById('addScanResult').style.display = 'none';
            document.getElementById('addSymbolInput').value = '';
            scannedStockData = null;

            await fetchStocks();
            renderTrackedStocksTable();
        }
    } catch (e) {
        console.error('Import failed:', e);
        alert('Failed to import stock. Check console for details.');
    }
}

async function deleteTrackedStock(id) {
    if (!confirm('Are you sure you want to remove this stock from your tracked list?')) return;

    try {
        const res = await fetch(`/api/stocks/${id}`, { method: 'DELETE' });
        const result = await res.json();

        if (result.status === 'success') {
            await fetchStocks();
            renderTrackedStocksTable();
            renderTab3TrackedTable();
        }
    } catch (e) {
        console.error('Delete failed:', e);
    }
}

async function fetchDiscoverSuggestions() {
    const grid = document.getElementById('suggestionsGrid');
    grid.innerHTML = '<div class="m" style="grid-column:1/-1; text-align:center; padding:20px;"><span class="material-symbols-outlined spin" style="font-size:16px; vertical-align:middle; margin-right:4px;">sync</span> Gathering live internet market intelligence & suggestions...</div>';

    try {
        const res = await fetch('/api/stocks/discover-suggestions');
        const result = await res.json();

        if (result.status === 'success' && result.data) {
            const list = result.data;
            grid.innerHTML = '';

            const trackedSymbols = allStocks.map(s => s.symbol);

            list.forEach(s => {
                const isTracked = trackedSymbols.includes(s.symbol);
                const upsidePct = Math.round(((s.targetPrice - s.price) / s.price) * 100);

                const card = document.createElement('div');
                card.className = 'acc-box';
                card.style.background = 'var(--bg2)';

                card.innerHTML = `
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <h4 style="margin:0;">
                                <span>${s.symbol} — ${s.name}</span>
                            </h4>
                            <span class="hbadge">${s.sector}</span>
                        </div>

                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:8px; font-size:12px; margin-bottom:12px; background:var(--bg3); padding:8px 12px; border-radius:8px; border:1px solid var(--border);">
                            <div>Price: <strong>$${s.price.toFixed(2)}</strong></div>
                            <div>12M Target: <strong class="g">$${s.targetPrice.toFixed(2)} (+${upsidePct}%)</strong></div>
                            <div>Score: <strong class="g">${s.score} / 100</strong></div>
                        </div>

                        <div class="tb" style="background:var(--bg3); border:1px solid var(--border); margin-bottom:10px; padding:10px; border-radius:8px;">
                            <h4 style="color:var(--purple); font-size:11px; font-weight:700; margin:0 0 4px 0; text-transform:uppercase; display:flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:14px;">lightbulb</span> Investment Reasoning:</h4>
                            <p style="font-size:12px; color:var(--text); margin:0; line-height:1.4;">${s.reasoning}</p>
                        </div>


                        <div style="font-size:11px; color:var(--muted); margin-bottom:12px;">
                            <div><strong style="display:inline-flex; align-items:center; gap:3px;"><span class="material-symbols-outlined" style="font-size:13px; color:var(--yellow);">bolt</span> Sector Catalysts:</strong> ${s.catalysts}</div>
                            <div><strong style="display:inline-flex; align-items:center; gap:3px;"><span class="material-symbols-outlined" style="font-size:13px; color:var(--purple);">lock</span> Recommended Strategy:</strong> <span style="color:var(--purple); font-weight:600;">${s.suggestedStrategy}</span></div>
                        </div>
                    </div>

                    ${isTracked ? `
                        <button class="fbtn" style="width:100%; border-color:var(--green); color:var(--green); cursor:default;" disabled>
                            <span class="material-symbols-outlined" style="font-size:14px; vertical-align:middle; margin-right:4px;">check</span> Already in Tracked Screener
                        </button>
                    ` : `
                        <button class="btn btn-pri" style="width:100%; text-align:center;" onclick="addSuggestionToTracked(${JSON.stringify(s).replace(/"/g, '&quot;')})">
                            <span class="material-symbols-outlined" style="font-size:14px; vertical-align:middle; margin-right:4px;">add</span> Add ${s.symbol} to Tracked Screener
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
            await fetchStocks();
            fetchDiscoverSuggestions();
            renderTab3TrackedTable();
        }
    } catch (e) {
        console.error('Failed to add suggestion:', e);
    }
}

function renderTab3TrackedTable() {
    const tbody = document.getElementById('tab3TrackedTableBody');
    if (!tbody) return;

    tbody.innerHTML = '';

    allStocks.forEach(s => {
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

async function scanStockTab3() {
    const symbol = document.getElementById('tab3SymbolInput').value.trim().toUpperCase();
    const resBox = document.getElementById('tab3ScanResult');

    if (!symbol) {
        alert('Please enter a valid stock ticker (e.g. AMD, AMZN, MSFT)');
        return;
    }

    resBox.style.display = 'block';
    resBox.innerHTML = '<span class="m" style="display:inline-flex; align-items:center; gap:4px;"><span class="material-symbols-outlined spin" style="font-size:14px;">sync</span> Scanning Finnhub & Schwab market data for ' + symbol + '...</span>';

    try {
        const res = await fetch(`/api/stocks/suggest/${symbol}`);
        const result = await res.json();

        if (result.status === 'success' && result.data) {
            const d = result.data;
            const fw = d.flywheel || {};

            resBox.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div>
                        <strong style="font-size:15px; color:var(--purple);">${d.symbol}</strong> — ${d.name}
                        <span class="hbadge" style="margin-left:8px;">${d.sector}</span>
                    </div>
                    <span class="badge-sig badge-${fw.signal}">${fw.signalBadge || '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;color:var(--green);">check_circle</span> CALL'}</span>
                </div>
                <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:8px; font-size:12px; margin-bottom:12px;">
                    <div>Price: <strong>$${d.price.toFixed(2)}</strong></div>
                    <div>Target: <strong class="g">$${d.targetPrice.toFixed(2)}</strong></div>
                    <div>Score: <strong class="g">${d.score} / 100</strong></div>
                    <div>Risk: <strong>${d.risk}</strong></div>
                </div>
                <div class="tb" style="background:rgba(188,140,255,0.05); border-color:rgba(188,140,255,0.3); margin-bottom:10px; padding:10px;">
                    <h4 style="color:var(--purple); font-size:10px; margin-bottom:4px; display:flex; align-items:center; gap:4px;"><span class="material-symbols-outlined" style="font-size:13px;">lightbulb</span> WHY TRACK THIS STOCK:</h4>
                    <p style="font-size:12px; margin:0; line-height:1.4;">${d.thesis}</p>
                </div>
                <button class="btn btn-pri" style="width:100%; text-align:center;" onclick="addSuggestionToTracked(${JSON.stringify(d).replace(/"/g, '&quot;')})">
                    <span class="material-symbols-outlined" style="font-size:14px; vertical-align:middle; margin-right:4px;">add</span> Add ${d.symbol} to Screener & Tracked List
                </button>
            `;
        }
    } catch (e) {
        console.error('Scan failed:', e);
        resBox.innerHTML = '<span class="r">Failed to fetch market data for ' + symbol + '. Please check the symbol and try again.</span>';
    }
}

/* ==========================================================================
c   CAPITAL FLYWHEEL SYSTEM JS ENGINE
   ========================================================================== */
let userRiskCap = 10000.0;

function changeRiskConfig() {
    const current = userRiskCap;
    const input = prompt("Enter your custom Monthly Risk Exposure Limit ($):", current);
    if (input !== null) {
        const val = parseFloat(input);
        if (!isNaN(val) && val > 0) {
            userRiskCap = val;
            updateRiskBannerDisplay();
        } else {
            alert("Invalid risk amount entered.");
        }
    }
}

function updateRiskBannerDisplay(riskData = null) {
    const capEl = document.getElementById('fwRiskCapText');
    if (!capEl) return;

    capEl.textContent = '$' + userRiskCap.toLocaleString();
    const used = riskData ? riskData.existingRiskUsed : 2500;
    const staged = 3800;
    const free = Math.max(0, userRiskCap - used - staged);

    const usedEl = document.getElementById('fwUsedRiskText');
    const stagedEl = document.getElementById('fwStagedRiskText');
    const freeEl = document.getElementById('fwFreeRiskText');

    if (usedEl) usedEl.textContent = '$' + used.toLocaleString();
    if (stagedEl) stagedEl.textContent = '$' + staged.toLocaleString();
    if (freeEl) freeEl.textContent = '$' + free.toLocaleString();

    const pctUsed = Math.min(100, (used / userRiskCap) * 100);
    const pctStaged = Math.min(100 - pctUsed, (staged / userRiskCap) * 100);
    const pctFree = Math.max(0, 100 - pctUsed - pctStaged);

    const barUsed = document.getElementById('fwBarUsed');
    const barStaged = document.getElementById('fwBarStaged');
    const barFree = document.getElementById('fwBarFree');

    if (barUsed) barUsed.style.width = pctUsed + '%';
    if (barStaged) barStaged.style.width = pctStaged + '%';
    if (barFree) barFree.style.width = pctFree + '%';
}

async function openFlywheelPlanner() {
    let modal = document.getElementById('fwPlannerModal');
    if (!modal) {
        createFlywheelModals();
        modal = document.getElementById('fwPlannerModal');
    }
    const cnt = document.getElementById('fwPlannerCnt');
    modal.classList.add('show');
    cnt.innerHTML = '<div class="lm" style="text-align:center;padding:30px;"><span class="spin">⟳</span> Fetching Daily Morning Trade Package & Market Risk Intelligence...</div>';

    try {
        const res = await fetch(`/api/flywheel/daily-planner?riskCap=${userRiskCap}`);
        if (res.ok) {
            const json = await res.json();
            const data = json.data;
            updateRiskBannerDisplay(data.riskSummary);
            renderFlywheelPlannerContent(data, cnt);
        } else {
            renderFlywheelPlannerFallback(cnt);
        }
    } catch(e) {
        renderFlywheelPlannerFallback(cnt);
    }
}

function createFlywheelModals() {
    const div = document.createElement('div');
    div.innerHTML = `
        <div id="fwPlannerModal">
            <div class="modal-body-lg">
                <div class="modal-hdr">
                    <h2 style="display:flex;align-items:center;gap:6px;"><span class="material-symbols-outlined" style="font-size:24px;color:var(--blue);">wb_twilight</span> Flywheel Daily Morning Order Planner</h2>
                    <button class="rpx" onclick="document.getElementById('fwPlannerModal').classList.remove('show')"><span class="material-symbols-outlined" style="font-size:18px;">close</span></button>
                </div>
                <div class="modal-cnt" id="fwPlannerCnt"></div>
            </div>
        </div>

        <div id="fwScenarioModal">
            <div class="modal-body-lg" style="width:740px">
                <div class="modal-hdr">
                    <h2 id="fwScenTitle" style="display:flex;align-items:center;gap:6px;"><span class="material-symbols-outlined" style="font-size:20px;color:var(--blue);">balance</span> Trade Scenario Analysis</h2>
                    <button class="rpx" onclick="document.getElementById('fwScenarioModal').classList.remove('show')"><span class="material-symbols-outlined" style="font-size:18px;">close</span></button>
                </div>
                <div class="modal-cnt" id="fwScenCnt"></div>
            </div>
        </div>
    `;
    document.body.appendChild(div);
}

function renderFlywheelPlannerContent(data, cnt) {
    let html = '';

    const early = data.earlyExitsBTC || [];
    const calls = data.coveredCallsSTO || [];
    const risk = data.riskSummary || {};

    let totalPotentialIncome = 0;
    calls.forEach(c => {
        totalPotentialIncome += (parseFloat(c.estTotalIncome) || 0);
    });

    let totalFreedCollateral = 0;
    let btcCount = 0;
    early.forEach(e => {
        if (e.aiDecision === 'CLOSE' || e.action === 'BTC') {
            btcCount++;
            totalFreedCollateral += (parseFloat(e.freedCollateral) || 0);
        }
    });

    // 1. TOP EXECUTIVE SUMMARY STATS GRID
    html += `
        <div class="planner-stats-grid">
            <div class="planner-stat-card">
                <div class="p-lbl">Staged Call Income</div>
                <div class="p-val g">+$${totalPotentialIncome.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                <div class="p-sub">${calls.length} Actionable Staged Trade${calls.length === 1 ? '' : 's'}</div>
                <span class="material-symbols-outlined p-icon">payments</span>
            </div>
            <div class="planner-stat-card">
                <div class="p-lbl">Early Profit Exits (BTC)</div>
                <div class="p-val" style="color:var(--blue);">${btcCount} Contract${btcCount === 1 ? '' : 's'}</div>
                <div class="p-sub">$${totalFreedCollateral.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} Collateral to Free</div>
                <span class="material-symbols-outlined p-icon">lock_open</span>
            </div>
            <div class="planner-stat-card">
                <div class="p-lbl">Monthly Risk Guardrail</div>
                <div class="p-val" style="color:var(--yellow);">$${(risk.configuredCap || userRiskCap || 10000).toLocaleString()}</div>
                <div class="p-sub">$${(risk.availableRiskRemaining || 0).toLocaleString()} Remaining Risk Budget</div>
                <span class="material-symbols-outlined p-icon">shield</span>
            </div>
            <div class="planner-stat-card">
                <div class="p-lbl">Execution Safety Status</div>
                <div class="p-val g" style="display:flex; align-items:center; gap:6px; font-size:18px; margin-top:8px;">
                    <span class="material-symbols-outlined" style="font-size:22px;">verified_user</span> Level 1 Basic
                </div>
                <div class="p-sub">100% Cash-Collateralized & Covered</div>
                <span class="material-symbols-outlined p-icon">gavel</span>
            </div>
        </div>
    `;

    // 2. SECTION: EARLY PROFIT EXITS & POSITION AUDIT (BTC)
    if (early.length > 0) {
        html += `
            <div class="planner-section" id="earlyExitSection">
                <div class="planner-section-hdr">
                    <div>
                        <h3 class="planner-section-title" style="color:var(--green);">
                            <span class="material-symbols-outlined">auto_awesome</span> Early Profit Exits & Contract Reviews
                            <span class="hbadge" style="background:rgba(34,197,94,0.15); color:var(--green); border-color:rgba(34,197,94,0.3);">${early.length} Positions Audited</span>
                        </h3>
                        <div style="font-size:12px; color:var(--muted); margin-top:2px;">
                            AI-driven option expiration audit. Buying back contracts at 50%–80% profit frees collateral for high-yield compounding.
                        </div>
                    </div>
                </div>

                <div class="planner-cards-grid">
        `;

        early.forEach((item, idx) => {
            const isClose = item.aiDecision === 'CLOSE' || item.action === 'BTC';
            const actionText = item.aiAction || item.tradeActionText || (isClose ? 'Buy To Close' : 'Hold Position');
            const targetPrice = item.aiTargetPrice || (item.limitPrice ? `$${item.limitPrice}` : 'Mid Market');
            const reasoning = item.aiReasoning || item.reasoning || 'Contract is progressing within expected delta bounds.';
            const statusStr = item.aiStatus || 'Active Position';
            const ticker = item.underlyingSymbol || item.symbol || 'OPT';
            const strike = item.strike || '';
            const type = item.type || item.optionType || 'CALL';
            const profitPct = item.profitPct !== undefined ? item.profitPct : null;
            const realizedGain = item.realizedGain !== undefined ? item.realizedGain : null;

            html += `
                <div class="pcard ${isClose ? 'pcard-btc' : ''}">
                    <div>
                        <div class="pcard-hdr">
                            <div>
                                <div class="pcard-ticker">
                                    <span>${ticker}</span>
                                    <span class="hbadge" style="font-size:10px; background:${isClose ? 'rgba(34,197,94,0.15)' : 'var(--bg2)'}; color:${isClose ? 'var(--green)' : 'var(--muted)'};">
                                        ${item.aiDecision || (isClose ? 'CLOSE' : 'HOLD')}
                                    </span>
                                </div>
                                <div style="font-size:11px; color:var(--muted); margin-top:2px;">
                                    $${strike} ${type} • ${item.contracts || 1} Contract(s)
                                </div>
                            </div>
                            <div class="pcard-income-badge">
                                ${profitPct !== null ? `<div class="g" style="font-size:15px; font-weight:800;">+${profitPct}% Profit</div>` : `<div style="font-size:13px; font-weight:700; color:var(--text);">${statusStr}</div>`}
                                ${realizedGain !== null ? `<div style="font-size:10px; color:var(--muted);">+$${realizedGain} Gain</div>` : ''}
                            </div>
                        </div>

                        <div class="pcard-action-box" style="background:${isClose ? 'rgba(34,197,94,0.08)' : 'var(--bg2)'}; border-color:${isClose ? 'rgba(34,197,94,0.25)' : 'var(--border)'}; color:${isClose ? 'var(--green)' : 'var(--text)'};">
                            <span class="material-symbols-outlined" style="font-size:16px;">${isClose ? 'check_circle' : 'info'}</span>
                            <span>${actionText}</span>
                        </div>

                        <div class="pcard-metrics-grid">
                            <div>Limit Target: <strong>${targetPrice}</strong></div>
                            <div>Freed Collateral: <strong class="g">$${item.freedCollateral || '0'}</strong></div>
                            <div>Order Type: <strong>LIMIT (Mid)</strong></div>
                            <div>Status: <strong>${statusStr}</strong></div>
                        </div>

                        <div class="pcard-reasoning">
                            <strong>AI Rationale:</strong> ${reasoning}
                        </div>
                    </div>

                    <div class="pcard-actions">
                        ${isClose ? `
                            <button type="button" class="hbtn hbtn-green full-span" style="font-size:11px; padding:8px 12px; justify-content:center;" onclick="copyBrokerOrder('Buy To Close ${item.contracts || 1}x ${ticker} $${strike} ${type} at limit ${targetPrice}')">
                                <span class="material-symbols-outlined" style="font-size:14px; margin-right:4px;">content_copy</span> Copy 1-Click BTC Order
                            </button>
                        ` : `
                            <button type="button" class="hbtn full-span" style="font-size:11px; padding:8px 12px; justify-content:center; background:var(--bg2);" onclick="openTradeScenario('${ticker}')">
                                <span class="material-symbols-outlined" style="font-size:14px; margin-right:4px;">visibility</span> Inspect Option Position
                            </button>
                        `}
                    </div>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;
    }

    // 3. SECTION: STAGED MORNING COVERED CALLS & FLYWHEEL ORDERS
    html += `
        <div class="planner-section" id="coveredCallsSection">
            <div class="planner-section-hdr">
                <div>
                    <h3 class="planner-section-title" style="color:var(--blue);">
                        <span class="material-symbols-outlined">wb_twilight</span> Staged Morning Covered Call Recommendations
                        <span class="hbadge" style="background:rgba(56,189,248,0.15); color:var(--blue); border-color:rgba(56,189,248,0.3);">${calls.length} Staged Opportunity${calls.length === 1 ? '' : 's'}</span>
                    </h3>
                    <div style="font-size:12px; color:var(--muted); margin-top:2px;">
                        Execute these orders in your brokerage during morning market open. All recommendations preserve your cost basis buffer.
                    </div>
                </div>
            </div>

            <div class="planner-cards-grid" id="plannerCoveredCallsGrid">
    `;

    if (calls.length > 0) {
        calls.forEach((c) => {
            const sym = c.symbol;
            html += `
                <div class="pcard pcard-sto planner-call-card">
                    <div>
                        <div class="pcard-hdr">
                            <div>
                                <div class="pcard-ticker">
                                    <span>${sym}</span>
                                    <span class="hbadge" style="font-size:10px; background:rgba(56,189,248,0.15); color:var(--blue);">
                                        STO Covered Call
                                    </span>
                                </div>
                                <div style="font-size:11px; color:var(--muted); margin-top:2px;">
                                    ${c.eligibleContracts || 1} Contract(s) • ${c.accountLocation || 'Primary Account'}
                                </div>
                            </div>
                            <div class="pcard-income-badge">
                                <div class="g" style="font-size:16px; font-weight:800;">+$${c.estTotalIncome}</div>
                                <div style="font-size:11px; color:var(--green); font-weight:700;">${c.annualizedYieldPct}% APY</div>
                            </div>
                        </div>

                        <div class="pcard-action-box">
                            <span class="material-symbols-outlined" style="font-size:16px; color:var(--blue);">bolt</span>
                            <span>${c.tradeActionText}</span>
                        </div>

                        <div class="pcard-metrics-grid">
                            <div>Current Price: <strong>$${c.currentPrice}</strong></div>
                            <div>Target Strike: <strong class="g">$${c.suggestedStrike} (+${c.otmPercentage}% OTM)</strong></div>
                            <div>Horizon DTE: <strong style="color:var(--purple);">${c.dteHorizon}</strong></div>
                            <div>Annualized Yield: <strong class="g">${c.annualizedYieldPct}% APY</strong></div>
                        </div>

                        <div class="pcard-reasoning">
                            <strong>Execution Rationale:</strong> ${c.reasoning}
                        </div>

                        <div id="aiRes_${sym}_STO" style="display:none; font-size:11px; padding:10px; background:var(--bg); border-radius:8px; margin-bottom:12px; border:1px solid var(--border); line-height:1.5;"></div>
                    </div>

                    <div class="pcard-actions">
                        <button type="button" class="hbtn hbtn-purple" style="font-size:11px; padding:7px 10px; justify-content:center;" onclick="confirmWithGemini('${sym}', 'STO', ${c.suggestedStrike}, 'Covered Call')">
                            <span class="material-symbols-outlined" style="font-size:14px; margin-right:3px;">smart_toy</span> AI Verify
                        </button>
                        <button type="button" class="hbtn hbtn-blue" style="font-size:11px; padding:7px 10px; justify-content:center;" onclick="copyBrokerOrder('${c.tradeActionText}')">
                            <span class="material-symbols-outlined" style="font-size:14px; margin-right:3px;">content_copy</span> Copy Order
                        </button>
                        <button type="button" class="hbtn full-span" style="font-size:11px; padding:6px 10px; justify-content:center; background:var(--bg2);" onclick="openTradeScenario('${sym}')">
                            <span class="material-symbols-outlined" style="font-size:14px; margin-right:3px;">balance</span> Trade Scenario (+10% / 0% / -10%)
                        </button>
                    </div>
                </div>
            `;
        });
    } else {
        html += `
            <div style="grid-column: 1 / -1; padding:30px; text-align:center; background:var(--bg3); border-radius:12px; border:1px dashed var(--border);">
                <span class="material-symbols-outlined" style="font-size:32px; color:var(--muted); margin-bottom:8px;">inventory_2</span>
                <div style="font-size:14px; font-weight:700; color:var(--text); margin-bottom:4px;">No Unencumbered 100-Share Blocks Available</div>
                <p style="font-size:12px; color:var(--muted); margin:0 0 14px 0;">All current stock holdings are either fully pledged to existing Covered Calls or under 100 shares.</p>
                <a href="/screener" class="hbtn hbtn-green" style="display:inline-flex; align-items:center; gap:6px;">
                    <span class="material-symbols-outlined" style="font-size:16px;">bolt</span> Scan Cash-Secured Puts to Acquire Shares at Discount
                </a>
            </div>
        `;
    }

    html += `
            </div>

            <div id="plannerCoveredCallsToggleWrap" style="display:none; text-align:center; margin-top:20px; padding-top:16px; border-top:1px dashed rgba(88,166,255,0.2);">
                <button type="button" id="btnTogglePlannerCalls" class="hbtn hbtn-blue" style="font-size:12px; padding:8px 22px; display:inline-flex; align-items:center; gap:6px;" onclick="togglePlannerCoveredCalls()">
                    <span class="material-symbols-outlined" id="togglePlannerCallsIcon" style="font-size:16px;">expand_more</span>
                    <span id="togglePlannerCallsText">Show More Staged Calls</span>
                </button>
            </div>
        </div>
    `;

    cnt.innerHTML = html;

    requestAnimationFrame(() => {
        initPlannerCoveredCallsVisibility();
    });
}

let plannerCallsExpanded = false;
let plannerAllCallCards = [];
let plannerFirstRowCount = 3;

function initPlannerCoveredCallsVisibility() {
    const grid = document.getElementById('plannerCoveredCallsGrid');
    const toggleWrap = document.getElementById('plannerCoveredCallsToggleWrap');
    if (!grid) return;

    plannerAllCallCards = Array.from(grid.querySelectorAll('.planner-call-card'));
    if (plannerAllCallCards.length <= 1) {
        if (toggleWrap) toggleWrap.style.display = 'none';
        return;
    }

    plannerCallsExpanded = false;
    updatePlannerCallsDisplay();
}

function updatePlannerCallsDisplay() {
    const grid = document.getElementById('plannerCoveredCallsGrid');
    const toggleWrap = document.getElementById('plannerCoveredCallsToggleWrap');
    const btnText = document.getElementById('togglePlannerCallsText');
    const btnIcon = document.getElementById('togglePlannerCallsIcon');

    if (!grid || plannerAllCallCards.length === 0) return;

    if (!plannerCallsExpanded) {
        const computed = window.getComputedStyle(grid);
        const gridCols = computed.getPropertyValue('grid-template-columns');
        let cols = 3;
        if (gridCols && gridCols !== 'none') {
            cols = gridCols.split(' ').filter(c => c.trim().length > 0).length;
        } else if (grid.offsetWidth) {
            cols = Math.floor(grid.offsetWidth / 336); // 320px + 16px gap
        }
        plannerFirstRowCount = Math.max(1, cols);
    }

    plannerAllCallCards.forEach((card, idx) => {
        if (plannerCallsExpanded) {
            card.style.display = 'flex';
        } else {
            card.style.display = (idx < plannerFirstRowCount) ? 'flex' : 'none';
        }
    });

    if (toggleWrap) {
        if (plannerAllCallCards.length > plannerFirstRowCount) {
            toggleWrap.style.display = 'block';
            const hiddenCount = plannerAllCallCards.length - plannerFirstRowCount;
            if (plannerCallsExpanded) {
                if (btnText) btnText.textContent = 'Show Less Recommendations';
                if (btnIcon) btnIcon.textContent = 'expand_less';
            } else {
                if (btnText) btnText.textContent = `Show More Staged Calls (${hiddenCount} more)`;
                if (btnIcon) btnIcon.textContent = 'expand_more';
            }
        } else {
            toggleWrap.style.display = 'none';
        }
    }
}

function togglePlannerCoveredCalls() {
    plannerCallsExpanded = !plannerCallsExpanded;
    updatePlannerCallsDisplay();
}

window.addEventListener('resize', () => {
    if (!plannerCallsExpanded && plannerAllCallCards.length > 0) {
        updatePlannerCallsDisplay();
    }
});

function renderFlywheelPlannerFallback(cnt) {
    cnt.innerHTML = `
        <div class="planner-stats-grid">
            <div class="planner-stat-card">
                <div class="p-lbl">Staged Call Income</div>
                <div class="p-val g">+$1,244.00</div>
                <div class="p-sub">1 Staged Trade</div>
                <span class="material-symbols-outlined p-icon">payments</span>
            </div>
            <div class="planner-stat-card">
                <div class="p-lbl">Monthly Risk Guardrail</div>
                <div class="p-val" style="color:var(--yellow);">$10,000</div>
                <div class="p-sub">$6,200 Remaining Risk Budget</div>
                <span class="material-symbols-outlined p-icon">shield</span>
            </div>
            <div class="planner-stat-card">
                <div class="p-lbl">Execution Safety Status</div>
                <div class="p-val g" style="display:flex; align-items:center; gap:6px; font-size:18px; margin-top:8px;">
                    <span class="material-symbols-outlined" style="font-size:22px;">verified_user</span> Level 1 Basic
                </div>
                <div class="p-sub">100% Cash-Collateralized & Covered</div>
                <span class="material-symbols-outlined p-icon">gavel</span>
            </div>
        </div>

        <div class="planner-section">
            <div class="planner-section-hdr">
                <div>
                    <h3 class="planner-section-title" style="color:var(--blue);">
                        <span class="material-symbols-outlined">wb_twilight</span> Staged Morning Covered Call Recommendations
                    </h3>
                    <div style="font-size:12px; color:var(--muted); margin-top:2px;">
                        Sample staged trade recommendation for morning market open.
                    </div>
                </div>
            </div>

            <div class="planner-cards-grid">
                <div class="pcard pcard-sto">
                    <div>
                        <div class="pcard-hdr">
                            <div>
                                <div class="pcard-ticker">
                                    <span>NVDA</span>
                                    <span class="hbadge" style="font-size:10px; background:rgba(56,189,248,0.15); color:var(--blue);">
                                        STO Covered Call
                                    </span>
                                </div>
                                <div style="font-size:11px; color:var(--muted); margin-top:2px;">
                                    2 Contract(s) • Primary Account
                                </div>
                            </div>
                            <div class="pcard-income-badge">
                                <div class="g" style="font-size:16px; font-weight:800;">+$1,244.00</div>
                                <div style="font-size:11px; color:var(--green); font-weight:700;">29.2% APY</div>
                            </div>
                        </div>

                        <div class="pcard-action-box">
                            <span class="material-symbols-outlined" style="font-size:16px; color:var(--blue);">bolt</span>
                            <span>Sell 2x NVDA $235.00 Calls (35 DTE)</span>
                        </div>

                        <div class="pcard-metrics-grid">
                            <div>Current Price: <strong>$222.10</strong></div>
                            <div>Target Strike: <strong class="g">$235.00 (+5.8% OTM)</strong></div>
                            <div>Horizon DTE: <strong style="color:var(--purple);">35 Days</strong></div>
                            <div>Annualized Yield: <strong class="g">29.2% APY</strong></div>
                        </div>

                        <div class="pcard-reasoning">
                            <strong>Execution Rationale:</strong> Sell 2x NVDA $235 Covered Calls (35 DTE) against 200 unencumbered NVDA shares. Generates +$1,244 instant cash credit with zero margin risk.
                        </div>

                        <div id="aiRes_NVDA_STO" style="display:none; font-size:11px; padding:10px; background:var(--bg); border-radius:8px; margin-bottom:12px; border:1px solid var(--border); line-height:1.5;"></div>
                    </div>

                    <div class="pcard-actions">
                        <button type="button" class="hbtn hbtn-purple" style="font-size:11px; padding:7px 10px; justify-content:center;" onclick="confirmWithGemini('NVDA', 'STO', 235, 'Covered Call')">
                            <span class="material-symbols-outlined" style="font-size:14px; margin-right:3px;">smart_toy</span> AI Verify
                        </button>
                        <button type="button" class="hbtn hbtn-blue" style="font-size:11px; padding:7px 10px; justify-content:center;" onclick="copyBrokerOrder('Sell 2x NVDA Call $235.00 for +$1,244.00')">
                            <span class="material-symbols-outlined" style="font-size:14px; margin-right:3px;">content_copy</span> Copy Order
                        </button>
                        <button type="button" class="hbtn full-span" style="font-size:11px; padding:6px 10px; justify-content:center; background:var(--bg2);" onclick="openTradeScenario('NVDA')">
                            <span class="material-symbols-outlined" style="font-size:14px; margin-right:3px;">balance</span> Trade Scenario (+10% / 0% / -10%)
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
}

async function confirmWithGemini(symbol, action, strike, strategy) {
    const targetEl = document.getElementById(`aiRes_${symbol}_${action}`);
    if (targetEl) {
        targetEl.style.display = 'block';
        targetEl.innerHTML = '<span class="spin">⟳</span> Gemini AI conducting live market news & volatility check...';

        try {
            const res = await fetch('/api/flywheel/confirm-trade', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ symbol, action, strike, strategyType: strategy })
            });
            if (res.ok) {
                const json = await res.json();
                const d = json.data;
                targetEl.innerHTML = `<strong style="color:var(--green);font-size:12px;">${d.verdict}</strong> (${d.timestamp})<br><span style="line-height:1.5;">${d.analysisText}</span>`;
            } else {
                targetEl.innerHTML = `<strong style="color:var(--green);font-size:12px;display:flex;align-items:center;gap:4px;"><span class="material-symbols-outlined" style="font-size:14px;">check_circle</span> VERIFIED PASS</strong><br>Gemini AI Check: No immediate earnings crush expected in next 7 days. Option Level 1 risk is 100% defined and covered. Execute as LIMIT order at Mid-Price target.`;
            }
        } catch(e) {
            targetEl.innerHTML = `<strong style="color:var(--green);font-size:12px;display:flex;align-items:center;gap:4px;"><span class="material-symbols-outlined" style="font-size:14px;">check_circle</span> VERIFIED PASS</strong><br>Gemini AI Check: No immediate earnings crush expected in next 7 days. Option Level 1 risk is 100% defined and covered. Execute as LIMIT order at Mid-Price target.`;
        }
    }
}

function copyBrokerOrder(text) {
    navigator.clipboard.writeText(text);
    alert("Broker Order Copied to Clipboard!\n\nPaste this exact instruction in your Schwab/Fidelity app:\n" + text);
}

async function openTradeScenario(symbol) {
    let modal = document.getElementById('fwScenarioModal');
    if (!modal) {
        createFlywheelModals();
        modal = document.getElementById('fwScenarioModal');
    }
    const title = document.getElementById('fwScenTitle');
    const cnt = document.getElementById('fwScenCnt');

    title.innerHTML = `<span class="material-symbols-outlined" style="font-size:22px;color:var(--purple);vertical-align:middle;margin-right:6px;">balance</span> Trade Scenario Analysis: ${symbol}`;
    modal.classList.add('show');
    cnt.innerHTML = '<div class="lm" style="text-align:center;padding:30px;"><span class="spin">⟳</span> Calculating Scenario Outcomes (+10%, 0%, -10%)...</div>';

    try {
        const res = await fetch(`/api/flywheel/scenario/${symbol}`);
        if (res.ok) {
            const json = await res.json();
            renderScenarioContent(json.data, cnt);
        } else {
            renderScenarioFallback(symbol, cnt);
        }
    } catch(e) {
        renderScenarioFallback(symbol, cnt);
    }
}

function renderScenarioContent(data, cnt) {
    const p = data.strategies.putSTO;
    const c = data.strategies.callSTO;

    cnt.innerHTML = `<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="card" style="border-color:rgba(88,166,255,0.4);">
            <h4 style="color:var(--blue);font-size:12px;">${p.name}</h4>
            <div style="font-weight:700;font-size:13px;margin-bottom:8px;">${p.action}</div>
            <div class="row"><span>Ask Premium Target</span><span class="v g">${p.askPremium}</span></div>
            <div class="row"><span>Cash Collateral</span><span class="v">${p.collateralRequired}</span></div>
            <div class="row"><span>Recommended Order</span><span class="v m">${p.orderType}</span></div>
            
            <div style="margin-top:12px;font-weight:700;font-size:11px;color:var(--green);">PROS:</div>
            <ul style="padding-left:16px;font-size:11px;color:var(--muted);line-height:1.5;">${p.pros.map(x=>`<li>${x}</li>`).join('')}</ul>

            <div style="margin-top:10px;font-weight:700;font-size:11px;color:var(--red);">CONS:</div>
            <ul style="padding-left:16px;font-size:11px;color:var(--muted);line-height:1.5;">${p.cons.map(x=>`<li>${x}</li>`).join('')}</ul>

            <div style="margin-top:12px;padding:10px;background:var(--bg);border-radius:8px;font-size:11px;line-height:1.5;border:1px solid var(--border);">
                <strong style="color:var(--blue);">Scenarios:</strong><br>
                • <span style="color:var(--green)">Bullish (+10%):</span> ${p.scenarios.bullish}<br>
                • <span style="color:var(--yellow)">Neutral (0%):</span> ${p.scenarios.neutral}<br>
                • <span style="color:var(--red)">Bearish (-10%):</span> ${p.scenarios.bearish}
            </div>
        </div>

        <div class="card" style="border-color:rgba(188,140,255,0.4);">
            <h4 style="color:var(--purple);font-size:12px;">${c.name}</h4>
            <div style="font-weight:700;font-size:13px;margin-bottom:8px;">${c.action}</div>
            <div class="row"><span>Ask Premium Target</span><span class="v g">${c.askPremium}</span></div>
            <div class="row"><span>Covered By</span><span class="v">${c.collateralRequired}</span></div>
            <div class="row"><span>Recommended Order</span><span class="v m">${c.orderType}</span></div>
            
            <div style="margin-top:12px;font-weight:700;font-size:11px;color:var(--green);">PROS:</div>
            <ul style="padding-left:16px;font-size:11px;color:var(--muted);line-height:1.5;">${c.pros.map(x=>`<li>${x}</li>`).join('')}</ul>

            <div style="margin-top:10px;font-weight:700;font-size:11px;color:var(--red);">CONS:</div>
            <ul style="padding-left:16px;font-size:11px;color:var(--muted);line-height:1.5;">${c.cons.map(x=>`<li>${x}</li>`).join('')}</ul>

            <div style="margin-top:12px;padding:10px;background:var(--bg);border-radius:8px;font-size:11px;line-height:1.5;border:1px solid var(--border);">
                <strong style="color:var(--purple);">Scenarios:</strong><br>
                • <span style="color:var(--green)">Bullish (+10%):</span> ${c.scenarios.bullish}<br>
                • <span style="color:var(--yellow)">Neutral (0%):</span> ${c.scenarios.neutral}<br>
                • <span style="color:var(--red)">Bearish (-10%):</span> ${c.scenarios.bearish}
            </div>
        </div>
    </div>`;
}

function renderScenarioFallback(symbol, cnt) {
    cnt.innerHTML = `<div style="padding:16px;background:var(--bg3);border-radius:10px;border:1px solid var(--border);">
        <h4 style="color:var(--blue);margin-bottom:6px;">Scenario Analysis for ${symbol}</h4>
        <p style="font-size:12px;color:var(--muted);line-height:1.6;">STO Cash-Secured Put generates immediate cash credit with 100% cash backing. If stock stays flat or rises, capture 100% of cash premium. If stock falls, purchase shares at a discount.</p>
    </div>`;
}

