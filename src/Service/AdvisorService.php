<?php

namespace App\Service;

/**
 * AdvisorService
 *
 * Synthesizes portfolio data, tax lot analysis, cash balances, and position dynamics
 * into actionable advisory insights ("nuggets") for novice to mid-level investors.
 */
class AdvisorService
{
    private TaxEngine $taxEngine;

    public function __construct(TaxEngine $taxEngine)
    {
        $this->taxEngine = $taxEngine;
    }

    /**
     * Generates prioritized advisor recommendations categorized into Tax, Income, and Growth.
     *
     * @param array $portfolio Data structure containing accounts, positions, cash, and balances
     * @param array $taxReport Realized/unrealized tax loss data from TaxEngine
     * @return array Categorized advisor insights
     */
    public function generateActionableInsights(array $portfolio, array $taxReport = []): array
    {
        $insights = [
            'tax_savings' => $this->analyzeTaxSavingsOpportunities($portfolio, $taxReport),
            'income_generation' => $this->analyzeIncomeOpportunities($portfolio),
            'growth_optimization' => $this->analyzeGrowthOpportunities($portfolio),
            'summary_nuggets' => [],
        ];

        // Pick top nugget from each category to populate executive nuggets
        foreach (['tax_savings', 'income_generation', 'growth_optimization'] as $category) {
            if (!empty($insights[$category])) {
                $insights['summary_nuggets'][] = $insights[$category][0];
            }
        }

        return $insights;
    }

    /**
     * Identifies Tax Loss Harvesting (TLH) and tax-efficient placement opportunities.
     */
    private function analyzeTaxSavingsOpportunities(array $portfolio, array $taxReport): array
    {
        $taxInsights = [];

        // 1. Tax Loss Harvesting Scanner
        $unrealizedLosses = 0.0;
        $harvestablePositions = [];

        if (isset($taxReport['unrealized_lots']) && is_array($taxReport['unrealized_lots'])) {
            foreach ($taxReport['unrealized_lots'] as $lot) {
                if (($lot['gain_loss'] ?? 0) < -100) { // Threshold: >$100 unrealized loss
                    $unrealizedLosses += abs($lot['gain_loss']);
                    $harvestablePositions[] = [
                        'symbol' => $lot['symbol'] ?? 'Unknown',
                        'loss' => abs($lot['gain_loss']),
                        'holding_period' => $lot['term'] ?? 'short',
                    ];
                }
            }
        }

        if ($unrealizedLosses > 250) {
            $taxInsights[] = [
                'type' => 'tax_loss_harvesting',
                'title' => 'Tax-Loss Harvesting Opportunity Available',
                'impact_level' => 'High',
                'category' => 'Tax Savings',
                'description' => sprintf(
                    'You have $%.2f in unrealized capital losses across %d position(s). Harvesting these losses can offset capital gains or up to $3,000 in ordinary income.',
                    $unrealizedLosses,
                    count($harvestablePositions)
                ),
                'action' => 'Review tax-loss harvesting candidates and ensure no repurchase occurs within 30 days to avoid wash sales.',
                'details' => array_slice($harvestablePositions, 0, 3),
            ];
        }

        // 2. High-Yield Asset Location Check (holding income assets in taxable accounts)
        $taxableHighYield = 0.0;
        if (isset($portfolio['positions']) && is_array($portfolio['positions'])) {
            foreach ($portfolio['positions'] as $pos) {
                $symbol = strtoupper($pos['symbol'] ?? '');
                // Basic check for known bond/dividend funds in taxable accounts
                if (in_array($symbol, ['SGOV', 'BIL', 'TLT', 'AGG', 'BND', 'JEPI', 'JEPQ', 'XYLD']) && ($pos['account_type'] ?? 'taxable') === 'taxable') {
                    $taxableHighYield += ($pos['market_value'] ?? 0);
                }
            }
        }

        if ($taxableHighYield > 1000) {
            $taxInsights[] = [
                'type' => 'asset_location',
                'title' => 'Tax Drag Warning on High-Yield Holding',
                'impact_level' => 'Medium',
                'category' => 'Tax Savings',
                'description' => sprintf(
                    'You are holding $%.2f of income-generating funds in taxable accounts. Ordinary dividend tax rates may reduce net yield.',
                    $taxableHighYield
                ),
                'action' => 'Consider relocating yield-focused funds to a Roth or Traditional IRA to shield income from annual taxes.',
            ];
        }

        if (empty($taxInsights)) {
            $taxInsights[] = [
                'type' => 'tax_optimal',
                'title' => 'Tax Efficiency Looks Optimal',
                'impact_level' => 'Low',
                'category' => 'Tax Savings',
                'description' => 'No critical tax drag or large unharvested loss clusters were detected in your current holdings.',
                'action' => 'Maintain current asset placement strategy.',
            ];
        }

        return $taxInsights;
    }

    /**
     * Identifies yield enhancement and income generation options.
     */
    private function analyzeIncomeOpportunities(array $portfolio): array
    {
        $incomeInsights = [];

        // 1. Idle Unencumbered Cash Yield Scan
        // Only evaluate unencumbered, free liquid cash not pledged to cash-secured puts or pending commitments
        $unencumberedCash = 0.0;
        if (isset($portfolio['availableCash'])) {
            $unencumberedCash = (float) $portfolio['availableCash'];
        } elseif (isset($portfolio['balances']['available_cash'])) {
            $unencumberedCash = (float) $portfolio['balances']['available_cash'];
        } elseif (isset($portfolio['balances']['cash']) || isset($portfolio['cash_balance'])) {
            $unencumberedCash = (float) ($portfolio['balances']['cash'] ?? $portfolio['cash_balance'] ?? 0);
        }

        if ($unencumberedCash > 5000) {
            $incomeInsights[] = [
                'type' => 'cash_yield',
                'title' => 'Uninvested Cash Yield Enhancement',
                'impact_level' => 'High',
                'category' => 'Income Generation',
                'description' => sprintf(
                    'You currently hold $%.2f in liquid unencumbered cash. Moving idle cash into ultra-short T-Bill ETFs (e.g. SGOV, BIL) or high-yield cash sweep can generate ~4.5%%-5.0%% annual yield.',
                    $unencumberedCash
                ),
                'action' => sprintf('Potential annual income boost: ~$%.2f with minimal credit risk.', $unencumberedCash * 0.045),
            ];
        }

        // 2. Covered Call Income Opportunity for 100+ Unencumbered Shares
        if (isset($portfolio['aggregatedEquities']) && is_array($portfolio['aggregatedEquities'])) {
            foreach ($portfolio['aggregatedEquities'] as $eq) {
                $availQty = (float) ($eq['availableShares'] ?? $eq['quantity'] ?? 0);
                $symbol = strtoupper($eq['symbol'] ?? '');
                if ($availQty >= 100 && !str_contains($symbol, ' ')) {
                    $incomeInsights[] = [
                        'type' => 'covered_call',
                        'title' => sprintf('Covered Call Option Potential on %s', $symbol),
                        'impact_level' => 'Medium',
                        'category' => 'Income Generation',
                        'description' => sprintf(
                            'You own %d unencumbered shares of %s (%d full option contract unit available). Writing out-of-the-money covered calls can generate monthly option premium income.',
                            (int) $availQty,
                            $symbol,
                            (int) floor($availQty / 100)
                        ),
                        'action' => 'Evaluate selling 30-45 DTE covered calls at a strike above your cost basis.',
                    ];
                    break; // Limit to top candidate
                }
            }
        } elseif (isset($portfolio['positions']) && is_array($portfolio['positions'])) {
            foreach ($portfolio['positions'] as $pos) {
                $qty = (float) ($pos['quantity'] ?? 0);
                $symbol = strtoupper($pos['symbol'] ?? '');
                if ($qty >= 100 && !str_contains($symbol, ' ')) {
                    $incomeInsights[] = [
                        'type' => 'covered_call',
                        'title' => sprintf('Covered Call Option Potential on %s', $symbol),
                        'impact_level' => 'Medium',
                        'category' => 'Income Generation',
                        'description' => sprintf(
                            'You own %d shares of %s (%d full option contract unit). Writing out-of-the-money covered calls can generate monthly option premium income.',
                            (int) $qty,
                            $symbol,
                            (int) floor($qty / 100)
                        ),
                        'action' => 'Evaluate selling 30-45 DTE covered calls at a strike above your cost basis.',
                    ];
                    break; // Limit to top candidate
                }
            }
        }

        if (empty($incomeInsights)) {
            $incomeInsights[] = [
                'type' => 'income_stable',
                'title' => 'Income Allocation Steady',
                'impact_level' => 'Low',
                'category' => 'Income Generation',
                'description' => 'Cash balances are well managed, and portfolio position sizing matches current yield preferences.',
                'action' => 'Reinvest incoming dividends automatically via DRIP.',
            ];
        }

        return $incomeInsights;
    }

    /**
     * Identifies growth optimization, rebalancing, and concentration risks.
     */
    private function analyzeGrowthOpportunities(array $portfolio): array
    {
        $growthInsights = [];

        // 1. Single Ticker Concentration Risk (>25% of Portfolio)
        $totalVal = (float) ($portfolio['total_value'] ?? $portfolio['balances']['liquidation_value'] ?? 0);
        if ($totalVal > 0 && isset($portfolio['positions']) && is_array($portfolio['positions'])) {
            foreach ($portfolio['positions'] as $pos) {
                $mktVal = (float) ($pos['market_value'] ?? 0);
                $pct = ($mktVal / $totalVal) * 100;
                $symbol = strtoupper($pos['symbol'] ?? '');

                if ($pct > 25.0 && !str_contains($symbol, ' ')) {
                    $growthInsights[] = [
                        'type' => 'concentration_risk',
                        'title' => sprintf('High Single-Stock Exposure: %s (%.1f%%)', $symbol, $pct),
                        'impact_level' => 'High',
                        'category' => 'Growth & Risk',
                        'description' => sprintf(
                            'Position %s represents %.1f%% of your total portfolio ($%.2f out of $%.2f). High single-stock concentration increases volatility risk.',
                            $symbol,
                            $pct,
                            $mktVal,
                            $totalVal
                        ),
                        'action' => 'Consider trimming gains gradually or using stop-limit protection to diversify into broad market ETFs.',
                    ];
                    break;
                }
            }
        }

        if (empty($growthInsights)) {
            $growthInsights[] = [
                'type' => 'diversified',
                'title' => 'Balanced Growth Distribution',
                'impact_level' => 'Low',
                'category' => 'Growth & Risk',
                'description' => 'No single stock exceeds critical concentration bounds. Diversification remains healthy.',
                'action' => 'Continue regular dollar-cost averaging into long-term holdings.',
            ];
        }

        return $growthInsights;
    }
}
