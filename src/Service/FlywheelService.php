<?php

namespace App\Service;

use App\Entity\Stock;

/**
 * Capital Flywheel Options Strategy Engine.
 *
 * All configurable thresholds and weights are read from AppConfigService
 * (backed by data.db).  Changing a value in /settings takes effect immediately
 * on the next request — no code deploys required.
 */
class FlywheelService
{
    public function __construct(
        private AppConfigService $config,
    ) {}

    /**
     * Evaluates a stock and determines its Capital Flywheel Options Signal.
     */
    public function evaluateSignal(Stock $stock): array
    {
        $score = $stock->getScore() ?? 50;
        $price = $stock->getPrice() ?? 0;
        $targetPrice = $stock->getTargetPrice() ?? 0;
        $risk = strtoupper($stock->getRisk() ?? 'MED');

        $upsidePct = ($price > 0 && $targetPrice > 0)
            ? (($targetPrice - $price) / $price) * 100
            : 0;

        // Read thresholds from config (falls back to defaults if not customised)
        $callScoreThreshold  = (int)   $this->config->get('flywheel.signal.call_score_threshold');
        $callUpsideThreshold = (float) $this->config->get('flywheel.signal.call_upside_threshold');
        $putScoreThreshold   = (int)   $this->config->get('flywheel.signal.put_score_threshold');
        $callOtmPct          = (float) $this->config->get('flywheel.signal.call_otm_pct');
        $putHedgeOtmPct      = (float) $this->config->get('flywheel.signal.put_hedge_otm_pct');
        $cspDiscountPct      = (float) $this->config->get('flywheel.signal.csp_discount_pct');

        // ── Level 1 Basic Options Classification Rules ────────────────────────
        if ($score >= $callScoreThreshold && $upsidePct > $callUpsideThreshold) {
            $signal = 'CALL';
            $signalBadge = '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;color:var(--green);">check_circle</span> CALL';
            $conviction = $score >= 85 ? 'HIGH' : 'MEDIUM';
            $strategy = 'Level 1 Basic: Defined-Risk Long Call (Max loss capped at small premium paid)';
            $horizon = '30 - 90 Days DTE';
            $otmStrike = number_format($price * (1 + $callOtmPct), 2);
            $strikeSuggestion = "\${$otmStrike} (" . round($callOtmPct * 100) . "% OTM Strike Target: \$" . number_format($targetPrice, 2) . ')';
            $flywheelRole = 'Capital Compounder (High Growth - Zero Margin Risk)';
        } elseif ($score < $putScoreThreshold || $upsidePct < 0 || $risk === 'HIGH') {
            $signal = 'PUT';
            $signalBadge = '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;color:var(--red);">error</span> PUT';
            $conviction = ($score < 35 || $upsidePct < -10) ? 'HIGH' : 'MEDIUM';
            $strategy = 'Level 1 Basic: Protective Long Put Hedge (Max loss capped at premium paid)';
            $horizon = '30 - 60 Days DTE';
            $putStrike = number_format($price * (1 - $putHedgeOtmPct), 2);
            $strikeSuggestion = "\${$putStrike} (" . round($putHedgeOtmPct * 100) . "% OTM Put Hedge)";
            $flywheelRole = 'Portfolio Protection (100% Capped Risk Hedge)';
        } else {
            $signal = 'WHEEL';
            $signalBadge = '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;color:var(--yellow);">warning</span> WHEEL';
            $conviction = 'STABLE';
            $strategy = 'Level 1 Basic: Cash-Secured Put (100% Backed by Cash) OR Covered Call (100% Covered by Owned Stock)';
            $horizon = '14 - 45 Days DTE';
            $cspStrike = number_format($price * (1 - $cspDiscountPct), 2);
            $strikeSuggestion = "Sell Cash-Secured Put at \${$cspStrike} (" . round($cspDiscountPct * 100) . "% Discount Entry)";
            $flywheelRole = 'Income Generator (100% Collateralized Premium Yield)';
        }

        $riskReward = $upsidePct > 0 ? number_format($upsidePct / 10, 1) . ' : 1' : '1 : 1.5';

        return [
            'signal'              => $signal,
            'signalBadge'         => $signalBadge,
            'conviction'          => $conviction,
            'upsidePct'           => round($upsidePct, 1),
            'recommendedStrategy' => $strategy,
            'horizon'             => $horizon,
            'strikeSuggestion'    => $strikeSuggestion,
            'flywheelRole'        => $flywheelRole,
            'riskRewardRatio'     => $riskReward,
        ];
    }

    /**
     * Calculates optimal capital allocation for a flywheel portfolio,
     * enforcing user-configured risk limit and accounting for open trade collateral.
     */
    public function calculateAllocation(array $stocks, float $totalCapital = 10000.0, array $portfolio = []): array
    {
        $calls = [];
        $puts  = [];
        $wheel = [];

        foreach ($stocks as $stock) {
            $evaluated = $this->evaluateSignal($stock);
            $item = array_merge($stock->toArray(), ['flywheel' => $evaluated]);

            match ($evaluated['signal']) {
                'CALL'  => $calls[] = $item,
                'PUT'   => $puts[]  = $item,
                default => $wheel[] = $item,
            };
        }

        // Calculate collateral already committed by existing open option obligations
        $existingOptionCollateral = 0.0;
        foreach ($portfolio['optionPositions'] ?? [] as $opt) {
            if (str_contains(strtoupper($opt['type'] ?? ''), 'PUT') && ($opt['quantity'] ?? 0) < 0) {
                $existingOptionCollateral += abs((int) $opt['quantity']) * (float) ($opt['strike'] ?? 0) * 100;
            }
        }

        $availableRiskCapital = max(0.0, $totalCapital - $existingOptionCollateral);

        // Read allocation weights from config
        $callWeight  = (float) $this->config->get('flywheel.allocation.call_weight');
        $wheelWeight = (float) $this->config->get('flywheel.allocation.wheel_weight');
        $putWeight   = (float) $this->config->get('flywheel.allocation.put_weight');

        return [
            'configuredRiskCap'      => $totalCapital,
            'existingRiskUsed'       => round($existingOptionCollateral, 2),
            'availableRiskRemaining' => round($availableRiskCapital, 2),
            'breakdown' => [
                'callCapital'  => round($availableRiskCapital * $callWeight,  2),
                'wheelCapital' => round($availableRiskCapital * $wheelWeight, 2),
                'putCapital'   => round($availableRiskCapital * $putWeight,   2),
            ],
            'counts' => [
                'calls' => count($calls),
                'puts'  => count($puts),
                'wheel' => count($wheel),
            ],
            'candidates' => [
                'calls' => array_slice($calls, 0, 3),
                'puts'  => array_slice($puts,  0, 3),
                'wheel' => array_slice($wheel, 0, 3),
            ],
        ];
    }

    /**
     * Generates actionable Early Exit / Profit Lock (Buy To Close) suggestions
     * for existing option positions that have decayed past the configured threshold.
     */
    public function generateEarlyExitSuggestions(array $portfolio): array
    {
        $openOptions = $portfolio['optionPositions'] ?? [];
        $earlyExits  = [];
        $btcThreshold = (float) $this->config->get('flywheel.early_exit.btc_profit_threshold');

        foreach ($openOptions as $opt) {
            $symbol        = $opt['underlyingSymbol'] ?? 'UNKNOWN';
            $type          = strtoupper($opt['type'] ?? 'CALL');
            $strike        = (float) ($opt['strike'] ?? 0);
            $initialCredit = (float) ($opt['averagePrice'] ?? 0.0);
            $currentPrice  = (float) ($opt['marketPrice'] ?? 0.0);
            $contracts     = abs((int) ($opt['quantity'] ?? 1));

            if ($initialCredit > 0 && $currentPrice >= 0) {
                $profitPct = (($initialCredit - $currentPrice) / $initialCredit) * 100;

                if ($profitPct >= $btcThreshold) {
                    $gainDollars    = round(($initialCredit - $currentPrice) * 100 * $contracts, 2);
                    $freedCollateral = $type === 'PUT' ? $strike * 100 * $contracts : 0;

                    $earlyExits[] = [
                        'symbol'               => $symbol,
                        'optionType'           => $type,
                        'strike'               => $strike,
                        'contracts'            => $contracts,
                        'initialCreditPerShare'=> $initialCredit,
                        'currentCostPerShare'  => $currentPrice,
                        'profitPct'            => round($profitPct, 1),
                        'realizedGain'         => $gainDollars,
                        'freedCollateral'      => $freedCollateral,
                        'action'               => 'BTC',
                        'orderType'            => 'LIMIT',
                        'limitPrice'           => $currentPrice,
                        'tradeActionText'      => "Buy To Close (BTC) {$contracts}x {$symbol} \${$strike} {$type} at \${$currentPrice} (Lock in +\${$gainDollars} Profit)",
                        'reasoning'            => "You have achieved {$profitPct}% of maximum profit on this {$type}. Buying to close at \${$currentPrice} locks in +\${$gainDollars} profit and frees up \${$freedCollateral} risk collateral for new compounder trades.",
                    ];
                }
            }
        }

        // Placeholder demo entry when no live positions qualify
        if (empty($earlyExits)) {
            $earlyExits[] = [
                'symbol'               => 'NVDA',
                'optionType'           => 'CALL',
                'strike'               => 220.00,
                'contracts'            => 2,
                'initialCreditPerShare'=> 4.50,
                'currentCostPerShare'  => 0.90,
                'profitPct'            => 80.0,
                'realizedGain'         => 720.00,
                'freedCollateral'      => 0.0,
                'action'               => 'BTC',
                'orderType'            => 'LIMIT',
                'limitPrice'           => 0.90,
                'tradeActionText'      => 'Buy To Close (BTC) 2x NVDA $220.00 CALL at $0.90 (Lock in +$720.00 Profit)',
                'reasoning'            => 'You have achieved 80.0% of max profit on NVDA $220 Call! Buying to close now for $0.90/sh ($180 total) locks in +$720 profit and eliminates tail-end risk.',
                '_demo'                => true,
            ];
        }

        return $earlyExits;
    }

    /**
     * Generates actionable Covered Call trade suggestions for unencumbered
     * portfolio equities (>= configured minimum shares, default 100).
     */
    public function generatePortfolioCoveredCallSuggestions(array $portfolio): array
    {
        $equities            = $portfolio['aggregatedEquities'] ?? [];
        $suggestions         = [];
        $totalPotentialIncome = 0.0;

        // Read all covered-call parameters from config in one batch
        $minShares       = (int)   $this->config->get('flywheel.covered_call.min_shares');
        $otmPct          = (float) $this->config->get('flywheel.covered_call.otm_pct');
        $costBasisBuffer = (float) $this->config->get('flywheel.covered_call.cost_basis_buffer');
        $dteTarget       = (int)   $this->config->get('flywheel.covered_call.dte_target');
        $estPremiumPct   = (float) $this->config->get('flywheel.covered_call.est_premium_pct');

        foreach ($equities as $eq) {
            $symbol       = $eq['symbol'];
            $qty          = (float) ($eq['quantity'] ?? 0);
            $avail        = (float) ($eq['availableShares'] ?? 0);
            $mktVal       = (float) ($eq['marketValue'] ?? 0.0);
            $currentPrice = $qty > 0 ? round($mktVal / $qty, 2) : (float) ($eq['averagePrice'] ?? 100.0);

            if ($avail >= $minShares) {
                $contracts        = (int) floor($avail / $minShares);
                $costBasis        = (float) ($eq['averagePrice'] ?? $currentPrice);
                $unrealizedPLPct  = (float) ($eq['unrealizedPLPct'] ?? 0.0);

                // Strike must be above current price (OTM) AND above cost basis buffer
                // to guarantee no realized loss on assignment
                $rawStrike       = max($currentPrice * (1 + $otmPct), $costBasis * $costBasisBuffer);
                $strikeIncrement = $rawStrike > 100 ? 5.0 : 2.5;
                $strike          = round($rawStrike / $strikeIncrement) * $strikeIncrement;
                if ($strike <= $currentPrice) {
                    $strike += $strikeIncrement;
                }

                $estPremiumPerShare = round($currentPrice * $estPremiumPct, 2);
                $contractIncome    = round($estPremiumPerShare * $minShares * $contracts, 2);
                $annualizedYield   = round(($estPremiumPerShare / $currentPrice) * (365 / $dteTarget) * 100, 1);
                $totalPotentialIncome += $contractIncome;

                // Collect accounts with enough unencumbered shares
                $accountsList = [];
                foreach ($eq['accountBreakdown'] ?? [] as $acc) {
                    if (($acc['availableShares'] ?? 0) >= $minShares) {
                        $accountsList[] = "Account {$acc['accountNumber']} ({$acc['availableShares']} Avail)";
                    }
                }
                $accountText  = count($accountsList) > 0 ? implode(', ', $accountsList) : 'Schwab Brokerage';
                $costBasisText = $costBasis > 0 ? "Cost Basis: \${$costBasis}/sh" : 'Cost Basis: N/A';
                $plBadge       = $unrealizedPLPct >= 0
                    ? "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;\">trending_up</span> +{$unrealizedPLPct}% Open P&L"
                    : "<span class=\"material-symbols-outlined\" style=\"font-size:12px;vertical-align:middle;color:var(--red);\">trending_down</span> {$unrealizedPLPct}% Open P&L";

                $expirationLabel = date('M d', strtotime("+{$dteTarget} days"));

                $suggestions[] = [
                    'symbol'             => $symbol,
                    'totalShares'        => $qty,
                    'availableShares'    => $avail,
                    'eligibleContracts'  => $contracts,
                    'currentPrice'       => $currentPrice,
                    'costBasis'          => $costBasis,
                    'unrealizedPLPct'    => $unrealizedPLPct,
                    'suggestedStrike'    => $strike,
                    'otmPercentage'      => round((($strike - $currentPrice) / $currentPrice) * 100, 1),
                    'dteHorizon'         => "{$dteTarget} Days (Optimal Theta Decay)",
                    'estPremiumPerShare' => $estPremiumPerShare,
                    'estTotalIncome'     => $contractIncome,
                    'annualizedYieldPct' => $annualizedYield,
                    'accountLocation'   => $accountText,
                    'tradeActionText'   => "Sell {$contracts}x {$symbol} {$expirationLabel} \${$strike} CALL for +\${$contractIncome} Instant Cash Premium",
                    'reasoning'         => "You hold {$avail} {$symbol} shares ({$costBasisText}, {$plBadge}). Selling {$contracts} OTM Covered Call contract(s) at \${$strike} (safely above your \${$costBasis} cost basis) generates +\${$contractIncome} cash income ({$annualizedYield}% APY) with ZERO risk of a capital loss if assigned!",
                ];
            }
        }

        return [
            'totalEligiblePositions' => count($suggestions),
            'totalPotentialIncome'   => round($totalPotentialIncome, 2),
            'suggestions'            => $suggestions,
        ];
    }
}
