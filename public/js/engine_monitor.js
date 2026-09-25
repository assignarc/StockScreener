let pollingInterval = null;
const feedExpandedStates = {
    llmEvalsContainer: false,
    marketIntelContainer: false,
    flywheelIdeasContainer: false,
    flywheelCallsContainer: false
};

function toggleFeedViewMore(btn, containerId) {
    const isExpanded = feedExpandedStates[containerId] = !feedExpandedStates[containerId];
    const container = document.getElementById(containerId);
    if (!container) return;

    const items = container.querySelectorAll('.diag-item-card');
    items.forEach((item, index) => {
        if (index >= 4) {
            item.classList.toggle('diag-item-hidden', !isExpanded);
        }
    });

    if (btn) {
        btn.classList.toggle('expanded', isExpanded);
        const icon = btn.querySelector('.material-symbols-outlined');
        const text = btn.querySelector('.btn-text');
        const count = items.length - 4;
        if (icon) icon.textContent = isExpanded ? 'expand_less' : 'expand_more';
        if (text) text.textContent = isExpanded ? 'View Less' : `View More (${count} remaining)`;
    }
}

function runEngineLive() {
    const btn = document.getElementById('runEngineBtn');
    const icon = document.getElementById('runEngineIcon');
    
    if (icon) {
        icon.textContent = 'progress_activity';
        icon.classList.add('icon-spin');
    }
    if (btn) btn.disabled = true;

    fetch('/api/flywheel/engine/run', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        }
    }).then(res => res.json())
      .then(data => {
          setTimeout(() => {
              if (icon) {
                  icon.textContent = 'bolt';
                  icon.classList.remove('icon-spin');
              }
              if (btn) btn.disabled = false;
          }, 3000);
          
          clearInterval(pollingInterval);
          startPolling(2000);
          setTimeout(() => {
              clearInterval(pollingInterval);
              startPolling(5000);
          }, 30000);
      })
      .catch(err => {
          console.error(err);
          if (icon) {
              icon.textContent = 'bolt';
              icon.classList.remove('icon-spin');
          }
          if (btn) btn.disabled = false;
      });
}

function updateDashboardUI(landscape) {
    if (!landscape) return;
    
    const statePill = document.getElementById('engineStatePill');
    const pulseDot = document.getElementById('pulseDot');
    const stateText = document.getElementById('engineStateText');
    
    if (statePill) statePill.className = 'engine-state-pill active';
    if (pulseDot) pulseDot.className = 'pulse-dot';
    if (stateText) stateText.textContent = 'ACTIVE';
    
    const lastRunTime = document.getElementById('lastRunTime');
    if (lastRunTime) lastRunTime.textContent = landscape.timestamp || 'Just now';
    
    const runDuration = document.getElementById('runDuration');
    if (runDuration) runDuration.textContent = `(${landscape.durationMs || 0}ms execution)`;

    const providerName = document.getElementById('providerName');
    if (providerName && landscape.provider) providerName.textContent = landscape.provider;

    if (landscape.engineLogs && landscape.engineLogs.length > 0) {
        const logsHtml = landscape.engineLogs.map(log => {
            let lineClass = 'terminal-line';
            if (log.includes('ERROR')) lineClass += ' error';
            else if (log.includes('Warning') || log.includes('Snoozing')) lineClass += ' warn';
            else if (log.includes('successfully') || log.includes('Started')) lineClass += ' success';
            else if (log.includes('Fetching') || log.includes('Evaluating')) lineClass += ' info';
            return `<div class="${lineClass}">${escapeHtml(log)}</div>`;
        }).join('');
        
        const logContainer = document.getElementById('engineLogsContainer');
        if (logContainer) {
            const isScrolledToBottom = logContainer.scrollHeight - logContainer.clientHeight <= logContainer.scrollTop + 5;
            logContainer.innerHTML = logsHtml;
            if (isScrolledToBottom) {
                logContainer.scrollTop = logContainer.scrollHeight;
            }
        }
    }
    
    if (landscape.portfolioSummary) {
        const nlv = parseFloat(landscape.portfolioSummary.netLiquidationValue || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const cash = parseFloat(landscape.portfolioSummary.cashBalance || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const availCash = parseFloat(landscape.portfolioSummary.availableCash ?? landscape.portfolioSummary.cashBalance ?? 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        let accountBreakdownHtml = '';
        if (landscape.accounts && landscape.accounts.length > 0) {
            accountBreakdownHtml = landscape.accounts.map(acc => {
                const name = acc.nickname || `Account ${acc.accountNumber}`;
                const aCash = parseFloat(acc.cashAvailable || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                return `
                    <div class="diag-row" style="padding: 6px 0;">
                        <span style="font-size: 12px; color: var(--text);">${escapeHtml(name)}</span>
                        <span style="font-size: 12px; color: var(--green); font-weight: 700;">$${aCash}</span>
                    </div>
                `;
            }).join('');
        }

        const portContainer = document.getElementById('portfolioDataContainer');
        if (portContainer) {
            portContainer.innerHTML = `
                <div class="diag-row">
                    <span class="label">Net Liquidation Value</span>
                    <span class="value" style="font-family: 'Outfit', sans-serif; font-size: 16px;">$${nlv}</span>
                </div>
                <div class="diag-row">
                    <span class="label">Total Idle Cash</span>
                    <span class="value">$${cash}</span>
                </div>
                <div class="diag-row">
                    <span class="label">Unencumbered Cash</span>
                    <span class="value" style="color: var(--green);">$${availCash}</span>
                </div>
                <div class="diag-row">
                    <span class="label">Connected Broker Nodes</span>
                    <span class="diag-badge purple">${landscape.portfolioSummary.accountCount || 0} Active</span>
                </div>
                
                <div class="diag-node-section">
                    <div class="diag-node-title">Account Liquidity Breakdown</div>
                    ${accountBreakdownHtml}
                </div>
            `;
        }
    }

    const evalsContainer = document.getElementById('llmEvalsContainer');
    if (evalsContainer && landscape.existingContracts) {
        if (landscape.existingContracts.length > 0) {
            const isExp = feedExpandedStates.llmEvalsContainer;
            let evalsHtml = landscape.existingContracts.map((item, idx) => {
                const sym = item.underlyingSymbol || 'SYM';
                const decision = item.aiDecision || 'HOLD';
                const colorClass = decision === 'CLOSE' ? 'blue' : 'muted';
                const borderClass = decision === 'CLOSE' ? 'border-blue' : 'border-muted';
                const profit = item.profitPct || 0;
                const gain = parseFloat(item.realizedGain || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                const strike = item.strike || 'N/A';
                const hiddenClass = (idx >= 4 && !isExp) ? 'diag-item-hidden' : '';
                
                return `
                    <div class="diag-item-card ${borderClass} ${hiddenClass}">
                        <div class="diag-item-top">
                            <span class="diag-item-symbol">${escapeHtml(sym)} ${escapeHtml(item.type || '')}</span>
                            <span class="diag-badge ${colorClass}">${escapeHtml(decision)}</span>
                        </div>
                        <div class="diag-item-metrics">
                            <span>Profit: <strong style="color: var(--green);">${profit}%</strong></span>
                            <span>Strike: <strong>$${strike}</strong></span>
                            <span>Gain: <strong style="color: var(--green);">+$${gain}</strong></span>
                        </div>
                        <div class="diag-item-reasoning">
                            ${escapeHtml(eval.aiReasoning || '')}
                        </div>
                    </div>
                `;
            }).join('');

            if (landscape.existingContracts.length > 4) {
                const remaining = landscape.existingContracts.length - 4;
                evalsHtml += `
                    <button type="button" class="diag-view-more-btn ${isExp ? 'expanded' : ''}" onclick="toggleFeedViewMore(this, 'llmEvalsContainer')">
                        <span class="material-symbols-outlined">${isExp ? 'expand_less' : 'expand_more'}</span>
                        <span class="btn-text">${isExp ? 'View Less' : `View More (${remaining} remaining)`}</span>
                    </button>
                `;
            }

            evalsContainer.innerHTML = evalsHtml;
        } else {
            evalsContainer.innerHTML = `
                <div class="diag-empty-state">
                    <span class="material-symbols-outlined">task_alt</span>
                    <span>No open contracts requiring AI exit actions.</span>
                </div>
            `;
        }
    }

    const intelContainer = document.getElementById('marketIntelContainer');
    if (intelContainer && landscape.marketIntelligence) {
        let intelHtml = '';
        const isExp = feedExpandedStates.marketIntelContainer;
        let itemIndex = 0;
        
        if (landscape.marketIntelligence.error) {
            intelHtml = `
                <div class="diag-item-card" style="border-left: 4px solid var(--red); background: rgba(248, 81, 73, 0.08);">
                    <div style="display: flex; align-items: center; gap: 8px; color: var(--red); font-size: 13px;">
                        <span class="material-symbols-outlined">error</span>
                        <span>${escapeHtml(landscape.marketIntelligence.error)}</span>
                    </div>
                </div>
            `;
        } else {
            if (landscape.marketIntelligence.events && landscape.marketIntelligence.events.length > 0) {
                intelHtml += '<div class="diag-node-title">Catalyst & Macro Events</div>';
                intelHtml += landscape.marketIntelligence.events.map(event => {
                    itemIndex++;
                    const hiddenClass = (itemIndex > 4 && !isExp) ? 'diag-item-hidden' : '';
                    return `
                        <div class="diag-item-card border-blue ${hiddenClass}" style="padding: 10px 12px; margin-bottom: 8px;">
                            <strong style="font-size: 13px; display: block; margin-bottom: 4px; color: var(--text);">${escapeHtml(event.headline || '')}</strong>
                            <span style="font-size: 12px; color: var(--muted); line-height: 1.4;">${escapeHtml(event.impact || '')}</span>
                        </div>
                    `;
                }).join('');
            }

            if (landscape.marketIntelligence.stockPicks && landscape.marketIntelligence.stockPicks.length > 0) {
                intelHtml += '<div class="diag-node-title" style="margin-top: 14px;">Market Ticker Scans</div>';
                intelHtml += landscape.marketIntelligence.stockPicks.map(pick => {
                    itemIndex++;
                    const hiddenClass = (itemIndex > 4 && !isExp) ? 'diag-item-hidden' : '';
                    return `
                        <div class="diag-item-card border-green ${hiddenClass}" style="padding: 10px 12px; margin-bottom: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <strong style="font-size: 13px; color: var(--green);">${escapeHtml(pick.ticker || '')}</strong>
                                <span class="diag-badge green">Opportunity</span>
                            </div>
                            <span style="font-size: 12px; color: var(--muted); display: block; margin-top: 4px; line-height: 1.4;">${escapeHtml(pick.reasoning || '')}</span>
                        </div>
                    `;
                }).join('');
            }

            if (itemIndex > 4) {
                const remaining = itemIndex - 4;
                intelHtml += `
                    <button type="button" class="diag-view-more-btn ${isExp ? 'expanded' : ''}" onclick="toggleFeedViewMore(this, 'marketIntelContainer')">
                        <span class="material-symbols-outlined">${isExp ? 'expand_less' : 'expand_more'}</span>
                        <span class="btn-text">${isExp ? 'View Less' : `View More (${remaining} remaining)`}</span>
                    </button>
                `;
            }
        }
        
        if (intelHtml !== '') {
            intelContainer.innerHTML = intelHtml;
        }
    }

    const ideasContainer = document.getElementById('flywheelIdeasContainer');
    if (ideasContainer && landscape.newIdeas) {
        if (landscape.newIdeas.error) {
            ideasContainer.innerHTML = `
                <div class="diag-item-card" style="border-left: 4px solid var(--red); background: rgba(248, 81, 73, 0.08);">
                    <div style="display: flex; align-items: center; gap: 8px; color: var(--red); font-size: 13px;">
                        <span class="material-symbols-outlined">error</span>
                        <span>${escapeHtml(landscape.newIdeas.error)}</span>
                    </div>
                </div>
            `;
        } else if (landscape.newIdeas.ideas && landscape.newIdeas.ideas.length > 0) {
            const isExp = feedExpandedStates.flywheelIdeasContainer;
            let ideasHtml = landscape.newIdeas.ideas.map((idea, idx) => {
                const hiddenClass = (idx >= 4 && !isExp) ? 'diag-item-hidden' : '';
                return `
                    <div class="diag-item-card border-purple ${hiddenClass}">
                        <div class="diag-item-top">
                            <span class="diag-item-symbol">${escapeHtml(idea.ticker || '')} &bull; ${escapeHtml(idea.title || '')}</span>
                            <span class="diag-badge purple">${escapeHtml(idea.strategyType || '')}</span>
                        </div>
                        <div class="diag-item-metrics">
                            <span>Est. Premium: <strong style="color: var(--green);">${escapeHtml(idea.estimatedPremium || '')}</strong></span>
                            <span>APY: <strong style="color: var(--blue);">${escapeHtml(idea.APY || idea.annualizedYield || 'N/A')}</strong></span>
                            <span>Strike: <strong>${escapeHtml(idea.targetStrike || idea.strike || 'N/A')}</strong></span>
                        </div>
                        <div class="diag-item-reasoning">
                            ${escapeHtml(idea.reasoning || '')}
                        </div>
                        ${idea.riskGuardrail ? `
                            <div class="diag-item-guardrail">
                                <span class="material-symbols-outlined" style="font-size: 16px;">shield</span>
                                <span>${escapeHtml(idea.riskGuardrail)}</span>
                            </div>
                        ` : ''}
                    </div>
                `;
            }).join('');

            if (landscape.newIdeas.ideas.length > 4) {
                const remaining = landscape.newIdeas.ideas.length - 4;
                ideasHtml += `
                    <button type="button" class="diag-view-more-btn ${isExp ? 'expanded' : ''}" onclick="toggleFeedViewMore(this, 'flywheelIdeasContainer')">
                        <span class="material-symbols-outlined">${isExp ? 'expand_less' : 'expand_more'}</span>
                        <span class="btn-text">${isExp ? 'View Less' : `View More (${remaining} remaining)`}</span>
                    </button>
                `;
            }

            ideasContainer.innerHTML = ideasHtml;
        } else {
            ideasContainer.innerHTML = `
                <div class="diag-empty-state">
                    <span class="material-symbols-outlined">bubble_chart</span>
                    <span>No flywheel ideas generated in latest cycle.</span>
                </div>
            `;
        }
    }
    
    const callsContainer = document.getElementById('flywheelCallsContainer');
    if (callsContainer && landscape.coveredCalls) {
        if (landscape.coveredCalls.suggestions && landscape.coveredCalls.suggestions.length > 0) {
            const isExp = feedExpandedStates.flywheelCallsContainer;
            let callsHtml = landscape.coveredCalls.suggestions.map((call, idx) => {
                const hiddenClass = (idx >= 4 && !isExp) ? 'diag-item-hidden' : '';
                return `
                    <div class="diag-item-card border-green ${hiddenClass}">
                        <div class="diag-item-top">
                            <span class="diag-item-symbol">${escapeHtml(call.symbol || '')} &bull; ${escapeHtml(call.tradeActionText || '')}</span>
                            <span class="diag-badge green">${call.eligibleContracts || 1} Contract(s)</span>
                        </div>
                        <div class="diag-item-metrics">
                            <span>Est Income: <strong style="color: var(--green);">+$${parseFloat(call.estTotalIncome || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                            <span>APY: <strong style="color: var(--blue);">${call.annualizedYieldPct || 0}%</strong></span>
                            <span>Strike: <strong>$${call.suggestedStrike || 0} (${call.otmPercentage || 0}% OTM)</strong></span>
                        </div>
                        <div class="diag-item-reasoning">
                            ${escapeHtml(call.reasoning || '')}
                        </div>
                    </div>
                `;
            }).join('');

            if (landscape.coveredCalls.suggestions.length > 4) {
                const remaining = landscape.coveredCalls.suggestions.length - 4;
                callsHtml += `
                    <button type="button" class="diag-view-more-btn ${isExp ? 'expanded' : ''}" onclick="toggleFeedViewMore(this, 'flywheelCallsContainer')">
                        <span class="material-symbols-outlined">${isExp ? 'expand_less' : 'expand_more'}</span>
                        <span class="btn-text">${isExp ? 'View Less' : `View More (${remaining} remaining)`}</span>
                    </button>
                `;
            }

            callsContainer.innerHTML = callsHtml;
        } else {
            callsContainer.innerHTML = `
                <div class="diag-empty-state">
                    <span class="material-symbols-outlined">inventory_2</span>
                    <span>No unencumbered 100-share blocks available for covered calls.</span>
                </div>
            `;
        }
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

function fetchEngineStatus() {
    fetch('/api/flywheel/engine/status')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success' && data.data) {
                updateDashboardUI(data.data);
            }
        })
        .catch(err => console.error('Error fetching engine status:', err));
}

function startPolling(intervalMs) {
    if (pollingInterval) clearInterval(pollingInterval);
    pollingInterval = setInterval(fetchEngineStatus, intervalMs);
}

document.addEventListener('DOMContentLoaded', () => {
    startPolling(5000);
});
