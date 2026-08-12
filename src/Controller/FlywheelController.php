<?php

namespace App\Controller;

use App\Repository\StockRepository;
use App\Service\AppConfigService;
use App\Service\BrokerManagerService;
use App\Service\FinnhubService;
use App\Service\FlywheelService;
use App\Service\PersistentCacheService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/flywheel', name: 'api_flywheel_')]
class FlywheelController extends AbstractController
{
    public function __construct(
        private FlywheelService $flywheelService,
        private BrokerManagerService $brokerManager,
        private FinnhubService $finnhubService,
        private StockRepository $stockRepository,
        private AppConfigService $appConfig,
        private LoggerInterface $logger,
        private \App\Llm\LlmServiceRouter $llmRouter,
        private PersistentCacheService $cache,
    ) {}

    #[Route('/allocate', name: 'allocate', methods: ['POST'])]
    public function allocateCapital(Request $request): JsonResponse
    {
        $content = json_decode($request->getContent(), true);
        $capital = (float) ($content['capital'] ?? $this->appConfig->get('flywheel.default_risk_cap'));

        if ($capital <= 0) {
            return $this->json(['error' => 'Capital must be greater than 0'], 400);
        }

        $stocks     = $this->stockRepository->findAll();
        $allocation = $this->flywheelService->calculateAllocation($stocks, $capital);

        return $this->json(['status' => 'success', 'data' => $allocation]);
    }

    #[Route('/covered-call-suggestions', name: 'covered_call_suggestions', methods: ['GET'])]
    public function coveredCallSuggestions(): JsonResponse
    {
        $cachedLandscape = $this->cache->get('flywheel.engine.landscape', isSensitive: true);
        if ($cachedLandscape && isset($cachedLandscape['coveredCalls'])) {
            return $this->json(['status' => 'success', 'data' => $cachedLandscape['coveredCalls']]);
        }

        $portfolio   = $this->brokerManager->getAggregatedPortfolio();
        $suggestions = $this->flywheelService->generatePortfolioCoveredCallSuggestions($portfolio);

        return $this->json(['status' => 'success', 'data' => $suggestions]);
    }

    #[Route('/daily-planner', name: 'daily_planner', methods: ['GET', 'POST'])]
    public function dailyPlanner(Request $request): JsonResponse
    {
        $content   = json_decode($request->getContent(), true) ?? [];
        $riskCap   = (float) ($request->query->get('riskCap')
            ?? $content['riskCap']
            ?? $this->appConfig->get('flywheel.default_risk_cap'));

        $portfolio   = $this->brokerManager->getAggregatedPortfolio();
        $stocks      = $this->stockRepository->findAll();
        $allocation  = $this->flywheelService->calculateAllocation($stocks, $riskCap, $portfolio);
        
        $cachedLandscape = $this->cache->get('flywheel.engine.landscape', isSensitive: true);
        if ($cachedLandscape) {
            $earlyExits = $cachedLandscape['existingContracts'] ?? [];
            $coveredCalls = $cachedLandscape['coveredCalls']['suggestions'] ?? [];
            $engineStatus = 'Active (Cached ' . ($cachedLandscape['timestamp'] ?? '') . ')';
        } else {
            // Fallback to live generation if engine hasn't run
            $earlyExits  = $this->flywheelService->generateEarlyExitSuggestions($portfolio);
            $coveredCallsData = $this->flywheelService->generatePortfolioCoveredCallSuggestions($portfolio);
            $coveredCalls = $coveredCallsData['suggestions'] ?? [];
            $engineStatus = 'Inactive (Live Fallback)';
        }

        return $this->json([
            'status' => 'success',
            'data'   => [
                'riskSummary'    => [
                    'configuredCap'          => $riskCap,
                    'existingRiskUsed'       => $allocation['existingRiskUsed'],
                    'availableRiskRemaining' => $allocation['availableRiskRemaining'],
                    'engineStatus'           => $engineStatus,
                ],
                'earlyExitsBTC'  => $earlyExits,
                'coveredCallsSTO'=> $coveredCalls,
            ],
        ]);
    }

    #[Route('/confirm-trade', name: 'confirm_trade', methods: ['POST'])]
    public function confirmTrade(Request $request): JsonResponse
    {
        $trade  = json_decode($request->getContent(), true) ?? [];
        $result = $this->llmRouter->verifyTradePreExecution($trade);
        return $this->json(['status' => 'success', 'data' => $result]);
    }

    #[Route('/scenario/{symbol}', name: 'scenario', methods: ['GET'])]
    public function scenario(string $symbol): JsonResponse
    {
        $symbol    = strtoupper(trim($symbol));
        $stock     = $this->stockRepository->findOneBy(['symbol' => $symbol]);
        $price     = $stock ? ($stock->getPrice() ?? 100.0) : 100.0;

        $cspDiscountPct = (float) $this->appConfig->get('flywheel.signal.csp_discount_pct');
        $callOtmPct     = (float) $this->appConfig->get('flywheel.signal.call_otm_pct');
        $estPremiumPct  = (float) $this->appConfig->get('flywheel.covered_call.est_premium_pct');

        $putStrike  = round($price * (1 - $cspDiscountPct), 2);
        $callStrike = round($price * (1 + $callOtmPct), 2);
        $estPremium = round($price * $estPremiumPct, 2);

        return $this->json([
            'status' => 'success',
            'data'   => [
                'symbol'       => $symbol,
                'currentPrice' => $price,
                'strategies'   => [
                    'putSTO' => [
                        'name'               => 'Cash-Secured Put (STO - Sell To Open)',
                        'action'             => "Sell 1x Put at \${$putStrike}",
                        'askPremium'         => "\${$estPremium} / share (\$" . ($estPremium * 100) . ' Credit)',
                        'collateralRequired' => '$' . number_format($putStrike * 100, 2),
                        'orderType'          => "LIMIT at Mid-Price \${$estPremium}",
                        'pros'  => ['Immediate upfront cash income', "Acquire stock at " . round($cspDiscountPct * 100) . "% discount if assigned", '100% cash backed with zero margin risk'],
                        'cons'  => ['Capped profit at premium received', "Obligated to buy stock if price drops below \${$putStrike}"],
                        'scenarios' => [
                            'bullish' => "Stock moves +10%: Option expires worthless, keep 100% of \$" . ($estPremium * 100) . ' cash.',
                            'neutral' => "Stock stays flat: Theta decay works in your favor, capture \$" . ($estPremium * 100) . ' yield.',
                            'bearish' => "Stock drops -10%: Assigned 100 shares at \${$putStrike} (effective entry \$" . round($putStrike - $estPremium, 2) . ').',
                        ],
                    ],
                    'callSTO' => [
                        'name'               => 'Covered Call (STO - Sell To Open)',
                        'action'             => "Sell 1x Call at \${$callStrike}",
                        'askPremium'         => '$' . round($estPremium * 1.1, 2) . ' / share ($' . round($estPremium * 110, 2) . ' Credit)',
                        'collateralRequired' => "100 Owned Shares of {$symbol}",
                        'orderType'          => 'LIMIT at Mid-Price $' . round($estPremium * 1.1, 2),
                        'pros'  => ['Generate cash yield on owned shares', "Downside buffer equal to premium received", '100% covered by stock'],
                        'cons'  => ["Stock gains above \${$callStrike} are capped", "Shares may be called away at \${$callStrike}"],
                        'scenarios' => [
                            'bullish' => "Stock moves +10%: Shares called away at \${$callStrike}, lock in stock capital gain + premium.",
                            'neutral' => 'Stock stays flat: Keep stock and keep 100% of cash premium.',
                            'bearish' => 'Stock drops -10%: Premium offsets portion of stock loss.',
                        ],
                    ],
                ],
            ],
        ]);
    }

    #[Route('/calendar', name: 'calendar', methods: ['GET'])]
    public function calendar(): JsonResponse
    {
        $portfolioData    = $this->brokerManager->getAggregatedPortfolio();
        $equities         = $portfolioData['aggregatedEquities'] ?? [];
        $accounts         = $portfolioData['accounts'] ?? [];
        $currentCash      = (float) ($portfolioData['cashBalance'] ?? 0.0);

        $calendarEvents         = [];
        $cashProjectionsByDate  = [];

        // Track base cash per account
        $accountCashMap = [];
        foreach ($accounts as $acc) {
            $accNum = $acc['accountNumber'];
            $accountCashMap[$accNum] = [
                'accountNumber'        => $accNum,
                'nickname'             => $acc['nickname'] ?? "Account {$accNum}",
                'currentCash'          => (float) ($acc['cashAvailable'] ?? 0.0),
                'freedCollateralByDate'=> [],
            ];
        }

        // Support configurable history (e.g. 1 month back) and forward projection (e.g. 6 months)
        $monthsBack    = (int) $this->appConfig->get('calendar.months_back', 1);
        $monthsForward = (int) $this->appConfig->get('calendar.months_forward', 6);
        $startDate     = date('Y-m-01', strtotime("-{$monthsBack} month"));
        $endDate       = date('Y-m-t', strtotime("+{$monthsForward} month"));
        $todayStr      = date('Y-m-d');
        $heldSymbols   = array_column($equities, 'symbol');

        $stockNames = [];
        foreach ($this->stockRepository->findAll() as $s) {
            $stockNames[strtoupper($s->getSymbol())] = $s->getName();
        }
        $getName = function(string $sym) use ($stockNames): string {
            $sUpper = strtoupper($sym);
            if (isset($stockNames[$sUpper])) {
                return "{$stockNames[$sUpper]} ({$sUpper})";
            }
            return $sUpper;
        };

        // 1. Inject Earnings Calendar Events (both past 30 days and upcoming)
        try {
            $rawEarnings = $this->finnhubService->getEarningsCalendar($startDate, $endDate);
            foreach ($rawEarnings as $earn) {
                $earnSymbol = strtoupper($earn['symbol'] ?? '');
                if (!in_array($earnSymbol, $heldSymbols, true)) {
                    continue;
                }
                $earnDate = $earn['date'] ?? date('Y-m-d');
                $isPast   = ($earnDate < $todayStr);
                $hour = strtoupper($earn['hour'] ?? 'AMC');
                $hourText = match ($hour) {
                    'BMO'   => 'Before Open',
                    'AMC'   => 'After Close',
                    default => 'Market Hours',
                };
                $hourIcon = match ($hour) {
                    'BMO'   => 'light_mode',
                    'AMC'   => 'dark_mode',
                    default => 'schedule',
                };

                $displayName = $getName($earnSymbol);
                $calendarEvents[] = [
                    'title'       => $isPast ? "{$displayName} Past Earnings Q" . ($earn['quarter'] ?? '') : "{$displayName} Earnings Release",
                    'date'        => $earnDate,
                    'category'    => $isPast ? 'EARNINGS_PAST' : 'EARNINGS',
                    'symbol'      => $earnSymbol,
                    'isPast'      => $isPast,
                    'marketValue' => 'Q' . ($earn['quarter'] ?? '') . ' Earnings',
                    'details'     => "Company Announcement: {$hourText} | Est EPS: \$" . ($earn['epsEstimate'] ?? 'N/A')
                        . ' | Est Revenue: $' . (isset($earn['revenueEstimate'])
                            ? number_format($earn['revenueEstimate'] / 1_000_000, 1) . 'M'
                            : 'N/A'),
                    'badge'       => $isPast ? 'HISTORICAL EARNINGS REPORT' : 'UPCOMING EARNINGS ANNOUNCEMENT',
                    'icon'        => 'campaign',
                    'hourIcon'    => $hourIcon,
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Earnings calendar fetch error: ' . $e->getMessage());
        }

        // 2. Inject Dividend Inflow Events for held equities
        foreach ($equities as $eq) {
            $sym    = $eq['symbol'];
            $shares = (float) ($eq['quantity'] ?? 0);
            if ($shares <= 0) {
                continue;
            }

            try {
                $divs = $this->finnhubService->getDividends($sym);
                foreach ($divs as $div) {
                    $payDate = $div['paymentDate'] ?? $div['date'] ?? null;
                    if (!$payDate || $payDate < $startDate || $payDate > $endDate) {
                        continue;
                    }

                    $amount = (float) ($div['amount'] ?? 0.0);
                    $totalDividend = round($shares * $amount, 2);
                    if ($totalDividend <= 0) {
                        continue;
                    }

                    $isPastDiv = ($payDate < $todayStr);
                    $displayName = $getName($sym);
                    $calendarEvents[] = [
                        'title'          => $isPastDiv
                            ? "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;\">payments</span> {$displayName} Dividend Paid (+\${$totalDividend})"
                            : "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;\">payments</span> {$displayName} Expected Dividend (+\${$totalDividend})",
                        'date'           => $payDate,
                        'category'       => $isPastDiv ? 'DIVIDEND_PAID' : 'DIVIDEND',
                        'symbol'         => $sym,
                        'isPast'         => $isPastDiv,
                        'amountPerShare' => $amount,
                        'sharesHeld'     => $shares,
                        'totalPayout'    => $totalDividend,
                        'details'        => "\${$amount}/sh on {$shares} shares | Total Cash Inflow: +\${$totalDividend}",
                        'badge'          => $isPastDiv ? 'PAID CASH DIVIDEND (LAST 30 DAYS)' : 'UPCOMING CASH DIVIDEND',
                    ];

                    if (!$isPastDiv) {
                        $cashProjectionsByDate[$payDate] ??= [
                            'date'              => $payDate,
                            'totalAssignedCash' => 0.0,
                            'totalDividendCash' => 0.0,
                            'optionsCount'      => 0,
                            'dividendsCount'    => 0,
                            'accountSummary'    => [],
                        ];
                        $cashProjectionsByDate[$payDate]['totalDividendCash'] ??= 0.0;
                        $cashProjectionsByDate[$payDate]['totalDividendCash'] += $totalDividend;
                        $cashProjectionsByDate[$payDate]['dividendsCount'] ??= 0;
                        $cashProjectionsByDate[$payDate]['dividendsCount']++;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Dividend calendar fetch error for {$sym}: " . $e->getMessage());
            }
        }

        // 3. Process linked option contracts per equity
        foreach ($equities as $eq) {
            foreach ($eq['linkedOptions'] ?? [] as $opt) {
                $expDate          = $opt['expiration'];
                $contracts        = (int) ($opt['contracts'] ?? 1);
                $strike           = (float) ($opt['strike'] ?? 0);
                $type             = strtoupper($opt['type'] ?? 'CALL');
                $assignedCashValue = $strike * 100 * $contracts;
                $pledgedShares    = (int) ($opt['pledgedShares'] ?? ($contracts * 100));
                $isPastOption     = ($expDate < $todayStr);

                $accountBreakdown = [];
                $optAccs = $opt['accounts'] ?? [];
                if (!empty($optAccs)) {
                    foreach ($optAccs as $oAcc) {
                        $accNum = $oAcc['accountNumber'];
                        $oQty = abs($oAcc['quantity']);
                        $assignedCash = $strike * 100 * $oQty;
                        $nickname = $oAcc['nickname'] ?? "Account {$accNum}";

                        $accountBreakdown[] = [
                            'accountNumber'           => $accNum,
                            'nickname'                => $nickname,
                            'contracts'               => $oQty,
                            'assignedCashIfExercised' => $assignedCash,
                        ];

                        if (isset($accountCashMap[$accNum]) && !$isPastOption) {
                            $accountCashMap[$accNum]['freedCollateralByDate'][$expDate] ??= 0.0;
                            $accountCashMap[$accNum]['freedCollateralByDate'][$expDate] += $assignedCash;
                        }
                    }
                } else {
                    // Fallback to stock breakdown if option accounts are not populated
                    foreach ($eq['accountBreakdown'] ?? [] as $accB) {
                        $accNum     = $accB['accountNumber'];
                        $accPledged = (float) ($accB['pledgedShares'] ?? 0);
                        $accountAssignedCash = ($contracts > 0 && $pledgedShares > 0)
                            ? round($assignedCashValue * (min($pledgedShares, $accPledged) / $pledgedShares), 2)
                            : $assignedCashValue;

                        if ($accPledged > 0 || count($eq['accountBreakdown']) === 1) {
                            $accountBreakdown[] = [
                                'accountNumber'           => $accNum,
                                'nickname'                => $accB['nickname'] ?? "Account {$accNum}",
                                'cashAvailable'           => (float) ($accB['cashAvailable'] ?? 0.0),
                                'sharesHeld'              => (float) ($accB['quantity'] ?? 0),
                                'availableShares'         => $accB['availableShares'] ?? 0,
                                'assignedCashIfExercised' => $accountAssignedCash > 0 ? $accountAssignedCash : $assignedCashValue,
                            ];

                            if (isset($accountCashMap[$accNum]) && !$isPastOption) {
                                $accountCashMap[$accNum]['freedCollateralByDate'][$expDate] ??= 0.0;
                                $accountCashMap[$accNum]['freedCollateralByDate'][$expDate] += ($accountAssignedCash > 0 ? $accountAssignedCash : $assignedCashValue);
                            }
                        }
                    }
                }

                $calendarEvents[] = [
                    'title'                   => ($isPastOption ? '<span class="material-symbols-outlined" style="font-size:inherit;vertical-align:middle;">check_circle</span> ' : '<span class="material-symbols-outlined" style="font-size:inherit;vertical-align:middle;">lock</span> ') . $opt['symbol'] . ' Exp ($' . number_format($assignedCashValue, 0) . ' Cap)',
                    'date'                    => $expDate,
                    'category'                => $isPastOption ? 'OPTION_PAST' : 'OPTION_' . $type,
                    'symbol'                  => $eq['symbol'],
                    'strike'                  => '$' . number_format($strike, 2),
                    'contracts'               => $contracts,
                    'isPast'                  => $isPastOption,
                    'marketValue'             => '$' . number_format($opt['marketValue'], 2),
                    'pledgedShares'           => $pledgedShares,
                    'assignedCashIfExercised' => $assignedCashValue,
                    'accountBreakdown'        => $accountBreakdown,
                    'details'                 => "Strike: \${$strike} | Pledged: {$pledgedShares} sh | Cash Released if Called: \$" . number_format($assignedCashValue, 2),
                    'badge'                   => $isPastOption ? 'HISTORICAL OPTION EXPIRATION' : 'EXPIRATION & CASH PROJECTION',
                ];

                if (!$isPastOption) {
                    $cashProjectionsByDate[$expDate] ??= [
                        'date'              => $expDate,
                        'totalAssignedCash' => 0.0,
                        'totalDividendCash' => 0.0,
                        'optionsCount'      => 0,
                        'dividendsCount'    => 0,
                        'accountSummary'    => [],
                    ];
                    $cashProjectionsByDate[$expDate]['totalAssignedCash'] += $assignedCashValue;
                    $cashProjectionsByDate[$expDate]['optionsCount']++;
                }
            }
        }

        // 4. Inject Configurable Aggregated Account Transaction History directly into calendar timeline
        try {
            $daysBack = max(30, $monthsBack * 30);
            $historyTx = $this->brokerManager->getAggregatedHistory($daysBack);
            foreach ($historyTx as $tx) {
                $rawDate = $tx['date'] ?? date('Y-m-d');
                $txDate  = substr(trim($rawDate), 0, 10);
                $amt = (float) ($tx['amount'] ?? 0.0);
                $isCredit = $tx['isCredit'] ?? ($amt >= 0);
                $amtSign = $isCredit ? '+' : '';
                $formattedAmt = $amtSign . '$' . number_format(abs($amt), 2);
                $type = strtoupper($tx['type'] ?? 'TRADE');
                $badge = strtoupper($tx['badge'] ?? $type);
                $symbol = strtoupper($tx['symbol'] ?? 'ACCOUNT');

                $accName = $tx['account_nickname'] ?? $tx['nickname'] ?? $tx['account_number'] ?? 'Broker';
                $displayName = ($symbol !== 'ACCOUNT' && $symbol !== 'USD' && $symbol !== '') ? $getName($symbol) : $symbol;

                $calendarEvents[] = [
                    'title'          => "{$formattedAmt} {$displayName} {$badge}",
                    'date'           => $txDate,
                    'category'       => 'HISTORY',
                    'symbol'         => $symbol,
                    'isPast'         => true,
                    'marketValue'    => $formattedAmt,
                    'amount'         => $amt,
                    'isCredit'       => $isCredit,
                    'details'        => ($tx['description'] ?? 'Account Activity') . " | Account: " . $accName,
                    'badge'          => "RECORDED TRANSACTION ({$badge})",
                    'accountNumber'  => $tx['account_number'] ?? $tx['accountNumber'] ?? null,
                    'accountNickname'=> $accName,
                    'description'    => $tx['description'] ?? '',
                    'realSymbol'     => $tx['symbol'] ?? 'USD',
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('History calendar injection error: ' . $e->getMessage());
        }

        // Running projected portfolio cash timeline sorted by date
        ksort($cashProjectionsByDate);
        $runningProjectedCash = $currentCash;
        foreach ($cashProjectionsByDate as $date => &$proj) {
            $freedCap = ($proj['totalAssignedCash'] ?? 0.0) + ($proj['totalDividendCash'] ?? 0.0);
            $runningProjectedCash += $freedCap;
            $proj['projectedPortfolioCash'] = $runningProjectedCash;

            foreach ($accountCashMap as $accNum => $accData) {
                $freedOnDate = $accData['freedCollateralByDate'][$date] ?? 0.0;
                $proj['accountSummary'][] = [
                    'accountNumber'       => $accNum,
                    'nickname'            => $accData['nickname'],
                    'startingCash'        => $accData['currentCash'],
                    'freedOnDate'         => $freedOnDate,
                    'projectedAccountCash'=> $accData['currentCash'] + $freedOnDate,
                ];
            }
        }

        return $this->json([
            'status' => 'success',
            'data'   => [
                'events'                => $calendarEvents,
                'cashProjections'       => array_values($cashProjectionsByDate),
                'currentPortfolioCash'  => $currentCash,
                'accounts'              => array_values($accountCashMap),
                'summary'               => [
                    'totalEquitiesTracked' => count($equities),
                    'totalActiveOptions'   => array_sum(array_map(
                        fn($e) => count($e['linkedOptions'] ?? []),
                        $equities
                    )),
                ],
            ],
        ]);
    }
    #[Route('/engine/status', name: 'engine_status', methods: ['GET'])]
    public function engineStatus(): JsonResponse
    {
        $cachedLandscape = $this->cache->get('flywheel.engine.landscape', isSensitive: true);
        return $this->json(['status' => 'success', 'data' => $cachedLandscape]);
    }

    #[Route('/engine/run', name: 'engine_run', methods: ['POST'])]
    public function engineRun(): JsonResponse
    {
        $projectDir = $this->getParameter('kernel.project_dir');
        $command = "php {$projectDir}/bin/console app:flywheel:engine";
        
        // Run in background
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B " . $command, "r"));
        } else {
            shell_exec($command . " > /dev/null 2>&1 &");
        }

        return $this->json(['status' => 'success', 'message' => 'Engine started in background']);
    }
}
