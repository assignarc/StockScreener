document.addEventListener('DOMContentLoaded', () => {
    loadCoveredCallSuggestions();
    loadGeminiAiIdeas();
});

async function loadGeminiAiIdeas(isRefresh = false) {
    const grid = document.getElementById('geminiAiCardsGrid');
    if (isRefresh && grid) {
        grid.innerHTML = '<div class="m" style="grid-column:1/-1; text-align:center; padding:15px; display:flex; align-items:center; justify-content:center; gap:6px;"><span class="material-symbols-outlined" style="font-size:18px; color:var(--purple);">auto_awesome</span> Asking AI to analyze market data & generate trade ideas...</div>';
    }

    try {
        const res = await fetch('/api/ai/flywheel-ideas');
        const json = await res.json();

        if (json.status === 'success' && json.data && grid) {
            const d = json.data;
            const ideas = d.ideas || [];
            const badge = document.getElementById('geminiSourceBadge');
            if (d.source && badge) {
                badge.textContent = d.source;
            }

            grid.innerHTML = '';
            ideas.forEach(item => {
                const card = document.createElement('div');
                card.style.background = 'var(--bg2)';
                card.style.border = '1px solid rgba(188,140,255,0.3)';
                card.style.borderRadius = '10px';
                card.style.padding = '14px';
                card.style.display = 'flex';
                card.style.flexDirection = 'column';
                card.style.justifyContent = 'space-between';

                card.innerHTML = `
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <strong style="font-size:14px; color:var(--purple);">${item.title}</strong>
                            <span class="rb" style="background:rgba(188,140,255,0.18); color:var(--purple); border:1px solid rgba(188,140,255,0.4); font-size:10px;">${item.actionBadge || '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;">smart_toy</span> GEMINI AI'}</span>
                        </div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; font-size:11px; color:var(--muted); margin-bottom:10px; background:var(--bg3); padding:8px; border-radius:6px; border:1px solid var(--border);">
                            <div>Strategy: <strong style="color:var(--text);">${item.strategyType}</strong></div>
                            <div>Target Strike: <strong class="g">${item.targetStrike}</strong></div>
                            <div>Est. Premium: <strong class="g">${item.estimatedPremium}</strong></div>
                            <div>Return APY: <strong class="g">${item.annualizedYield}</strong></div>
                        </div>

                        <div style="font-size:11px; line-height:1.45; color:var(--text); margin-bottom:10px; display:flex; gap:4px; align-items:flex-start;">
                            <span class="material-symbols-outlined" style="font-size:16px; color:var(--yellow); flex-shrink:0;">lightbulb</span>
                            <div><strong>AI Reasoning:</strong> ${item.reasoning}</div>
                        </div>
                        <div style="font-size:11px; line-height:1.45; color:var(--muted); margin-bottom:12px; background:rgba(248,81,73,0.05); padding:6px 8px; border-radius:6px; border:1px solid rgba(248,81,73,0.2); display:flex; gap:4px; align-items:flex-start;">
                            <span class="material-symbols-outlined" style="font-size:16px; color:var(--red); flex-shrink:0;">security</span>
                            <div><strong>Level 1 Safety:</strong> ${item.riskGuardrail}</div>
                        </div>
                    </div>

                    <a href="/screener" class="hbtn hbtn-purple" style="width:100%; justify-content:center; text-align:center; text-decoration:none; display:inline-flex; align-items:center; gap:6px; padding:8px 0;">
                        <span class="material-symbols-outlined" style="font-size:16px;">bolt</span> Inspect ${item.ticker} Option Chain
                    </a>
                `;
                grid.appendChild(card);
            });
        }
    } catch(e) {
        console.error('Failed to load Gemini AI ideas:', e);
    }
}

let allCoveredCallElements = [];
let coveredCallsExpanded = false;
let firstRowCount = 1;

function getVisibleColumnCount(gridElement) {
    if (!gridElement || !gridElement.offsetWidth) return 4;
    const computed = window.getComputedStyle(gridElement);
    const gridCols = computed.getPropertyValue('grid-template-columns');
    if (gridCols && gridCols !== 'none') {
        const cols = gridCols.split(' ').filter(c => c.trim().length > 0);
        return Math.max(1, cols.length);
    }
    const width = gridElement.offsetWidth;
    const estimatedCols = Math.floor(width / 254);
    return Math.max(1, estimatedCols);
}

function updateCoveredCallsDisplay() {
    const grid = document.getElementById('coveredCallTradeCards');
    const toggleContainer = document.getElementById('coveredCallToggleContainer');
    const btnText = document.getElementById('toggleCoveredCallsText');
    const btnIcon = document.getElementById('toggleCoveredCallsIcon');

    if (!grid || allCoveredCallElements.length === 0) return;

    if (!coveredCallsExpanded) {
        firstRowCount = getVisibleColumnCount(grid);
    }

    allCoveredCallElements.forEach((el, index) => {
        if (coveredCallsExpanded) {
            el.style.display = 'flex';
        } else {
            el.style.display = (index < firstRowCount) ? 'flex' : 'none';
        }
    });

    if (toggleContainer) {
        if (allCoveredCallElements.length > firstRowCount) {
            toggleContainer.style.display = 'block';
            const hiddenCount = allCoveredCallElements.length - firstRowCount;
            if (coveredCallsExpanded) {
                if (btnText) btnText.textContent = 'Show Less Trades';
                if (btnIcon) btnIcon.textContent = 'expand_less';
            } else {
                if (btnText) btnText.textContent = `Show More Actionable Trades (${hiddenCount} more)`;
                if (btnIcon) btnIcon.textContent = 'expand_more';
            }
        } else {
            toggleContainer.style.display = 'none';
        }
    }
}

function toggleCoveredCallsVisibility() {
    coveredCallsExpanded = !coveredCallsExpanded;
    updateCoveredCallsDisplay();
}

window.addEventListener('resize', () => {
    if (!coveredCallsExpanded && allCoveredCallElements.length > 0) {
        updateCoveredCallsDisplay();
    }
});

async function loadCoveredCallSuggestions() {
    const grid = document.getElementById('coveredCallTradeCards');
    const toggleContainer = document.getElementById('coveredCallToggleContainer');
    try {
        const res = await fetch('/api/flywheel/covered-call-suggestions');
        const json = await res.json();

        if (json.status === 'success' && json.data && grid) {
            const d = json.data;
            const suggestions = d.suggestions || [];
            const playbookTotal = document.getElementById('playbookTotalIncome');
            if (playbookTotal) {
                playbookTotal.textContent = `+$${d.totalPotentialIncome.toLocaleString()} Instant Cash Premium Income`;
            }

            if (suggestions.length === 0) {
                grid.innerHTML = '<div class="m" style="grid-column:1/-1; text-align:center; padding:15px;">All portfolio shares are either pledged or under 100 shares. No Covered Call trades available right now.</div>';
                if (toggleContainer) toggleContainer.style.display = 'none';
                return;
            }

            grid.innerHTML = '';
            allCoveredCallElements = [];
            coveredCallsExpanded = false;

            suggestions.forEach((s) => {
                const card = document.createElement('div');
                card.style.background = 'var(--bg3)';
                card.style.border = '1px solid var(--border)';
                card.style.borderRadius = '10px';
                card.style.padding = '14px';
                card.style.display = 'flex';
                card.style.flexDirection = 'column';
                card.style.justifyContent = 'space-between';

                card.innerHTML = `
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <div>
                                <strong style="font-size:16px; color:var(--text);">${s.symbol}</strong>
                                <span class="hbadge" style="margin-left:6px; background:rgba(21,128,61,0.15); color:var(--green);">${s.eligibleContracts} Contract(s) Eligible</span>
                            </div>
                            <span class="g" style="font-size:15px; font-weight:800;">+$${s.estTotalIncome} Income</span>
                        </div>

                        <div style="background:rgba(21,128,61,0.08); border:1px solid rgba(21,128,61,0.25); border-radius:6px; padding:8px 10px; font-size:12px; margin-bottom:10px; color:var(--green); font-weight:700; display:flex; align-items:center; gap:6px;">
                            <span class="material-symbols-outlined" style="font-size:16px;">bolt</span> ${s.tradeActionText}
                        </div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; font-size:11px; color:var(--muted); margin-bottom:10px; background:var(--bg2); padding:8px; border-radius:6px; border:1px solid var(--border);">
                            <div>Current Price: <strong style="color:var(--text);">$${s.currentPrice}</strong></div>
                            <div>Target Strike: <strong class="g">$${s.suggestedStrike} (+${s.otmPercentage}% OTM)</strong></div>
                            <div>Horizon DTE: <strong style="color:var(--purple);">${s.dteHorizon}</strong></div>
                            <div>Annualized Yield: <strong class="g">${s.annualizedYieldPct}% APY</strong></div>
                        </div>

                        <p style="font-size:11px; color:var(--muted); margin:0 0 10px 0; line-height:1.4;">
                            ${s.reasoning}
                        </p>
                    </div>

                    <a href="/screener?symbol=${s.symbol}" class="hbtn hbtn-blue" style="width:100%; justify-content:center; text-align:center; text-decoration:none; display:inline-flex; align-items:center; gap:6px; padding:8px 0;">
                        <span class="material-symbols-outlined" style="font-size:16px;">search</span> Inspect ${s.symbol} Option Chain & AI Signals
                    </a>
                `;
                grid.appendChild(card);
                allCoveredCallElements.push(card);
            });

            requestAnimationFrame(() => {
                updateCoveredCallsDisplay();
            });
        }
    } catch(e) {
        console.error('Failed to load trade suggestions:', e);
    }
}
