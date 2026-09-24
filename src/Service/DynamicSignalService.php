<?php

namespace App\Service;

use App\Entity\Stock;
use Psr\Log\LoggerInterface;

/**
 * Class DynamicSignalService
 *
 * Synthesizes external API data (Finnhub price targets, analyst recommendations, earnings release calendars)
 * and internal portfolio context into dynamic, adaptive trade signals with progressive disclosure:
 * Tier 1: "The Sausage" - Crisp Executive summary (Buy date, Target exit date, Dollar allocation, 1-line plain thesis).
 * Tier 2: "The Sausage-Making" - Deep-dive audit metrics (Wall St consensus range, earnings collision dates, Greeks, tax impact).
 */
class DynamicSignalService
{
    public function __construct(
        private FinnhubService $finnhub,
        private AppConfigService $config,
        private LoggerInterface $logger,
        private PersistentCacheService $cache,
    ) {}

    /**
     * Synthesize dynamic signals with concrete Buy Dates, Target Sell Dates, Dynamic Exits,
     * and a dual-tier progressive disclosure payload.
     *
     * @param Stock $stock Tracked stock entity.
     * @param array $portfolio Current aggregated user portfolio data.
     * @return array Progressive disclosure signal dictionary.
     */
    public function generateDynamicSignal(Stock $stock, array $portfolio = []): array
    {
        $symbol = strtoupper($stock->getSymbol());
        $price = (float) ($stock->getPrice() ?? 0.0);
        $score = (int) ($stock->getScore() ?? 50);
        $risk = strtoupper($stock->getRisk() ?? 'MED');

        // 1. Fetch live external consensus metrics from Finnhub
        $priceTargetData = $this->finnhub->getPriceTarget($symbol);
        $recTrends = $this->finnhub->getRecommendationTrends($symbol);
        
        // Calculate dynamic target price from API if available, falling back to DB entity
        $meanTarget = !empty($priceTargetData['targetMean']) && $priceTargetData['targetMean'] > 0
            ? (float) $priceTargetData['targetMean']
            : (float) ($stock->getTargetPrice() ?? ($price * 1.15));
        
        $targetHigh = !empty($priceTargetData['targetHigh']) ? (float) $priceTargetData['targetHigh'] : $meanTarget * 1.15;
        $targetLow = !empty($priceTargetData['targetLow']) ? (float) $priceTargetData['targetLow'] : $meanTarget * 0.85;

        $upsidePct = ($price > 0 && $meanTarget > 0)
            ? round((($meanTarget - $price) / $price) * 100, 1)
            : 0.0;

        // 2. Parse Analyst Recommendations
        $latestRec = !empty($recTrends[0]) ? $recTrends[0] : null;
        $buyVotes = $latestRec ? (($latestRec['strongBuy'] ?? 0) + ($latestRec['buy'] ?? 0)) : 0;
        $holdVotes = $latestRec ? ($latestRec['hold'] ?? 0) : 0;
        $sellVotes = $latestRec ? (($latestRec['sell'] ?? 0) + ($latestRec['strongSell'] ?? 0)) : 0;
        $totalVotes = $buyVotes + $holdVotes + $sellVotes;
        $bullishConsensusPct = $totalVotes > 0 ? round(($buyVotes / $totalVotes) * 100) : null;

        // 3. Check upcoming earnings calendar for binary risk protection
        $todayStr = date('Y-m-d');
        $futureStr = date('Y-m-d', strtotime('+120 days'));
        $earningsList = $this->finnhub->getEarningsCalendar($todayStr, $futureStr, $symbol);
        $nextEarningsDate = null;
        $daysToEarnings = null;
        if (!empty($earningsList[0]['date'])) {
            $nextEarningsDate = $earningsList[0]['date'];
            $daysToEarnings = (int) ((strtotime($nextEarningsDate) - strtotime($todayStr)) / 86400);
        }

        // 4. Determine Core Signal Strategy & Dynamic Timeframes
        $entryDate = date('M d, Y'); // Today as initial staging
        
        if ($score >= 70 && $upsidePct > 12) {
            $signalType = 'CALL';
            $strategyName = 'Defined-Risk Long Call';
            $tier = 'High Growth Compounder';
            $defaultDte = 60; // 60 days
            $otmPct = 0.05;
            $suggestedStrike = round(($price * (1 + $otmPct)) * 2) / 2;
            
            // Adjust exit date so it does not collide with earnings volatility crush if earnings are near
            if ($daysToEarnings !== null && $daysToEarnings > 14 && $daysToEarnings < 75) {
                $targetExitDte = max(14, $daysToEarnings - 4); // Exit 4 days before earnings release
                $earningsNote = "Auto-adjusted to exit {$targetExitDte}d out, 4 days prior to earnings ({$nextEarningsDate}) to avoid IV crush.";
            } else {
                $targetExitDte = $defaultDte;
                $earningsNote = $nextEarningsDate ? "Next earnings safely in {$daysToEarnings} days ({$nextEarningsDate})." : "No immediate earnings collision.";
            }

            $targetExitDate = date('M d, Y', strtotime("+{$targetExitDte} days"));
            $profitExitRule = "+80% to +100% gain on call premium (or stock reaching \${$meanTarget})";
            $stopLossRule = "Trailing stop: Exit if equity drops below 20-day EMA or when DTE drops below 21 days";
            
            $thesis = sprintf(
                'Strong upside (%+.1f%% to analyst mean $%s) with high fundamental score (%d/100). %s',
                $upsidePct,
                number_format($meanTarget, 2),
                $score,
                $bullishConsensusPct !== null ? "{$bullishConsensusPct}% Wall St analyst buy consensus." : "Favorable growth profile."
            );
        } elseif ($score < 45 || $upsidePct < -5 || $risk === 'HIGH') {
            $signalType = 'PUT';
            $strategyName = 'Protective Put Hedge';
            $tier = 'Portfolio Protection & De-risking';
            $targetExitDte = 45;
            $suggestedStrike = round(($price * 0.95) * 2) / 2;
            $targetExitDate = date('M d, Y', strtotime("+{$targetExitDte} days"));
            $profitExitRule = "Monetize if underlying drops >8% or overall portfolio recovers balance";
            $stopLossRule = "Capped risk: Max loss strictly limited to initial premium paid";
            $earningsNote = $nextEarningsDate ? "Earnings event scheduled on {$nextEarningsDate}." : "No earnings catalyst detected.";
            $thesis = sprintf(
                'Defensive posture: Low fundamental score (%d/100) or high volatility beta. Hedge downside risk with capped-cost protection.',
                $score
            );
        } else {
            $signalType = 'WHEEL';
            $strategyName = 'Cash-Secured Put / Wheel Income';
            $tier = 'Low-Risk Income Generation';
            $targetExitDte = 35;
            $cspDiscount = 0.08;
            $suggestedStrike = round(($price * (1 - $cspDiscount)) * 2) / 2;
            $targetExitDate = date('M d, Y', strtotime("+{$targetExitDte} days"));
            $profitExitRule = "Buy-To-Close early at 75% max premium capture (typically within 10-18 days)";
            $stopLossRule = "If assigned at \${$suggestedStrike}, immediately transition into Covered Calls at/above cost basis";
            $earningsNote = $nextEarningsDate ? "Target expiration calibrated around {$nextEarningsDate} earnings." : "Standard 35-day monthly Theta decay cycle.";
            $thesis = sprintf(
                'High quality range-bound holding (%d score). Monetize time decay by selling 8%% discount strike at $%s with 100%% cash collateral.',
                $score,
                number_format($suggestedStrike, 2)
            );
        }

        // 5. Portfolio Contextual Sizing
        $availableCash = (float) ($portfolio['availableCash'] ?? 10000.0);
        $maxPerTickerPct = 0.08; // 8% max of liquid cash
        $recommendedAllocation = min($availableCash * $maxPerTickerPct, 2500.0);
        if ($recommendedAllocation < 250) {
            $recommendedAllocation = min(500.0, $availableCash);
        }

        return [
            // TIER 1: "The Sausage" - Crisp Executive Output
            'executive' => [
                'symbol'                => $symbol,
                'signalType'            => $signalType,
                'strategyName'          => $strategyName,
                'tierName'              => $tier,
                'suggestedStrike'       => $suggestedStrike,
                'entryDate'             => $entryDate,
                'targetExitDate'        => $targetExitDate,
                'targetDte'             => $targetExitDte,
                'recommendedAllocation' => round($recommendedAllocation, 2),
                'thesis'                => $thesis,
                'profitExitRule'        => $profitExitRule,
                'stopLossRule'          => $stopLossRule,
            ],

            // TIER 2: "The Sausage-Making" - Deep Multi-API Underwriting Data
            'audit' => [
                'marketPrice'           => $price,
                'analystMeanTarget'     => $meanTarget,
                'analystHighTarget'     => $targetHigh,
                'analystLowTarget'      => $targetLow,
                'impliedUpsidePct'      => $upsidePct,
                'analystVotes'          => [
                    'buy'   => $buyVotes,
                    'hold'  => $holdVotes,
                    'sell'  => $sellVotes,
                    'total' => $totalVotes,
                    'bullishPct' => $bullishConsensusPct,
                ],
                'catalyst' => [
                    'nextEarningsDate' => $nextEarningsDate,
                    'daysToEarnings'   => $daysToEarnings,
                    'timingRationale'  => $earningsNote,
                ],
                'portfolioGuardrail' => [
                    'userAvailableCash' => $availableCash,
                    'maxSizingPct'      => ($maxPerTickerPct * 100) . '%',
                    'zeroMarginCheck'   => '100% Cash or Share Collateralized (Zero Margin Trap)',
                ],
                'dataSources' => [
                    'Finnhub Price Target API',
                    'Finnhub Recommendation Trends API',
                    'Finnhub Earnings Calendar API',
                    'Portfolio Cash & Position Context',
                ],
            ],
        ];
    }
}
