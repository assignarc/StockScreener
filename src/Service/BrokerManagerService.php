<?php

namespace App\Service;

use App\Broker\AlpacaBroker;
use App\Broker\BrokerInterface;
use App\Broker\EtradeBroker;
use App\Broker\IbkrBroker;
use App\Broker\PublicBroker;
use App\Broker\RobinhoodBroker;
use App\Broker\SchwabBroker;
use App\Broker\TastytradeBroker;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class BrokerManagerService
 *
 * Orchestrates multi-broker instances, resolves polymorphic adapters, aggregates
 * multi-account portfolios, computes unencumbered share blocks for options trading,
 * and harmonizes transaction history feeds.
 *
 * Design Reference: doc/broker-integrations.md
 */
class BrokerManagerService
{
    /** @var array<string, BrokerInterface> Map of initialized broker adapter instances */
    private array $brokers = [];

    /**
     * @param AppConfigService $appConfig Configuration service.
     * @param PersistentCacheService $cache Persistent caching service.
     * @param HttpClientInterface $httpClient External HTTP client.
     * @param LoggerInterface $logger Application logger.
     * @param \Doctrine\DBAL\Connection|null $connection Database connection for local portfolio events.
     */
    public function __construct(
        private AppConfigService $appConfig,
        private PersistentCacheService $cache,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ?\Doctrine\DBAL\Connection $connection = null
    ) {
        $this->initializeBrokers();
    }

    /**
     * Initialize broker instances from configuration.
     */
    private function initializeBrokers(): void
    {
        $instances = $this->appConfig->getBrokerInstances();
        foreach ($instances as $inst) {
            $id        = $inst['id'] ?? 'b1';
            $type      = strtolower($inst['type'] ?? 'schwab');
            $nickname  = $inst['nickname'] ?? ('Broker ' . strtoupper($id));
            $appKey    = $inst['app_key'] ?? null;
            $appSecret = $inst['app_secret'] ?? null;

            $this->brokers[$id] = match ($type) {
                'schwab' => new SchwabBroker(
                    id: $id,
                    nickname: $nickname,
                    appKey: $appKey,
                    appSecret: $appSecret,
                    httpClient: $this->httpClient,
                    logger: $this->logger,
                    cache: $this->cache,
                    appConfig: $this->appConfig
                ),
                'tastytrade' => new TastytradeBroker(
                    id: $id,
                    nickname: $nickname,
                    appKey: $appKey,
                    appSecret: $appSecret,
                    logger: $this->logger,
                    cache: $this->cache,
                    appConfig: $this->appConfig
                ),
                'ibkr' => new IbkrBroker(
                    id: $id,
                    nickname: $nickname,
                    appKey: $appKey,
                    appSecret: $appSecret,
                    logger: $this->logger,
                    cache: $this->cache,
                    appConfig: $this->appConfig
                ),
                'alpaca' => new AlpacaBroker(
                    id: $id,
                    nickname: $nickname,
                    appKey: $appKey,
                    appSecret: $appSecret,
                    logger: $this->logger,
                    cache: $this->cache,
                    appConfig: $this->appConfig
                ),
                'etrade' => new EtradeBroker(
                    id: $id,
                    nickname: $nickname,
                    appKey: $appKey,
                    appSecret: $appSecret,
                    logger: $this->logger,
                    cache: $this->cache,
                    appConfig: $this->appConfig
                ),
                'public' => new PublicBroker(
                    id: $id,
                    nickname: $nickname,
                    appKey: $appKey,
                    appSecret: $appSecret,
                    logger: $this->logger,
                    cache: $this->cache,
                    appConfig: $this->appConfig
                ),
                default => new RobinhoodBroker(
                    id: $id,
                    nickname: $nickname,
                    appKey: $appKey,
                    appSecret: $appSecret,
                    logger: $this->logger,
                    cache: $this->cache,
                    appConfig: $this->appConfig
                ),
            };
        }
    }

    /**
     * Retrieve a broker adapter by instance identifier or type slug.
     *
     * @param string $id Broker ID or type slug.
     * @return BrokerInterface|null Broker adapter instance or null.
     */
    public function getBroker(string $id): ?BrokerInterface
    {
        if (isset($this->brokers[$id])) {
            return $this->brokers[$id];
        }
        foreach ($this->brokers as $bId => $broker) {
            if (strtolower($bId) === strtolower($id) || strtolower($broker->getType()) === strtolower($id)) {
                return $broker;
            }
        }
        return !empty($this->brokers) ? reset($this->brokers) : null;
    }

    /**
     * Get all registered broker adapter instances.
     *
     * @return array<string, BrokerInterface> Associative map of broker instances.
     */
    public function getBrokers(): array
    {
        return $this->brokers;
    }

    /**
     * Aggregate portfolio balances, liquidation values, equity positions, and calculate
     * unencumbered share blocks across all registered and authorized brokers.
     *
     * @return array Standardized aggregated portfolio response.
     */
    public function getAggregatedPortfolio(): array
    {
        $allPositions = [];
        $totalCash = 0.0;
        $totalAvailableCash = 0.0;
        $totalPortfolioVal = 0.0;
        $authorizedCount = 0;
        $accountsSummary = [];
        $equityMap = [];

        foreach ($this->brokers as $id => $broker) {
            if (!$broker->isConfigured()) {
                continue;
            }

            $portfolio = $broker->getAccountPortfolio();
            if (isset($portfolio['error']) && !$broker->isAuthorized()) {
                $accountsSummary[] = [
                    'id'          => $id,
                    'nickname'    => $broker->getNickname(),
                    'type'        => $broker->getType(),
                    'authorized'  => false,
                    'status'      => 'Authorization Required',
                ];
                continue;
            }

            $authorizedCount++;
            $balances = $portfolio['balances'] ?? [];
            $cash = (float) ($balances['cash'] ?? 0.0);
            $pVal = (float) ($balances['portfolio_value'] ?? 0.0);

            $totalCash += $cash;
            $totalPortfolioVal += $pVal;

            // Process each individual account
            $brokerAccounts = $portfolio['accounts'] ?? [];
            $orders = $broker->getOpenOrders();
            if (!empty($brokerAccounts)) {
                foreach ($brokerAccounts as $accIndex => $accItem) {
                    $accNum = $accItem['accountNumber'] ?? 'N/A';
                    $accNickname = !empty($accItem['nickname']) ? $accItem['nickname'] : $broker->getNickname();
                    $accType = $accItem['type'] ?? 'MARGIN';
                    $accVal = (float) ($accItem['liquidationValue'] ?? 0.0);
                    $accCash = (float) ($accItem['cashAvailable'] ?? 0.0);
                    $accPositions = $accItem['positions'] ?? [];
                    $accTotalRequiredCash = 0.0;

                    $accMasked = $accNum !== 'N/A' ? ('***' . substr($accNum, -4)) : '';
                    $matchedOrders = [];
                    foreach ($orders as $o) {
                        if (($o['account_number'] ?? '') === $accMasked) {
                            $matchedOrders[] = $o;
                        }
                    }

                    foreach ($accPositions as $posItem) {
                        $rawSym = $posItem['symbol'] ?? 'UNKNOWN';
                        $assetType = $posItem['asset_type'] ?? $posItem['assetType'] ?? 'EQUITY';
                        $symbol = $assetType === 'OPTION' ? TaxEngine::normalizeOptionSymbol($rawSym) : TaxEngine::normalizeSymbol($rawSym);
                        $qty = (float) ($posItem['quantity'] ?? 0.0);
                        $mktVal = (float) ($posItem['market_value'] ?? $posItem['marketValue'] ?? 0.0);
                        $costBasis = (float) ($posItem['cost_basis'] ?? $posItem['averagePrice'] ?? 0.0);
                        $unrealizedPL = (float) ($posItem['unrealized_pl'] ?? $posItem['unrealizedPL'] ?? 0.0);
                        $unrealizedPLPct = (float) ($posItem['unrealized_pl_pct'] ?? $posItem['unrealizedPLPct'] ?? 0.0);

                        if ($assetType === 'OPTION') {
                            $putCall = $posItem['putCall'] ?? '';
                            $strike = (float) ($posItem['strikePrice'] ?? 0.0);
                            if ($qty < 0 && $putCall === 'PUT') {
                                // Strictly enforce 100% Cash-Secured Put requirement
                                $reqCash = abs($qty) * $strike * 100;
                                $accTotalRequiredCash += $reqCash;
                            }
                        }

                        $allPositions[] = [
                            'broker_id'      => $id,
                            'broker_nickname'=> $broker->getNickname(),
                            'symbol'         => $symbol,
                            'asset_type'     => $assetType,
                            'quantity'       => $qty,
                            'cost_basis'     => $costBasis,
                            'market_value'   => $mktVal,
                            'unrealized_pl'  => $unrealizedPL,
                            'unrealized_pl_pct' => $unrealizedPLPct,
                        ];

                        // Aggregate by Symbol
                        if (!isset($equityMap[$symbol])) {
                            $equityMap[$symbol] = [
                                'symbol' => $symbol,
                                'assetType' => $assetType,
                                'totalQuantity' => 0.0,
                                'totalCostBasis' => 0.0,
                                'totalMarketValue' => 0.0,
                                'totalUnrealizedPL' => 0.0,
                                'accountCount' => 0,
                                'accounts' => [],
                            ];
                        }

                        $equityMap[$symbol]['totalQuantity'] += $qty;
                        $equityMap[$symbol]['totalCostBasis'] += ($qty * $costBasis);
                        $equityMap[$symbol]['totalMarketValue'] += $mktVal;
                        $equityMap[$symbol]['totalUnrealizedPL'] += $unrealizedPL;
                        $equityMap[$symbol]['accountCount']++;
                        $equityMap[$symbol]['accounts'][] = [
                            'accountNumber' => $accNum,
                            'nickname' => $accNickname,
                            'type' => $accType,
                            'quantity' => $qty,
                            'marketValue' => $mktVal,
                            'averagePrice' => $costBasis,
                        ];
                    }

                    usort($accPositions, function($a, $b) {
                        $valA = (float) ($a['marketValue'] ?? $a['market_value'] ?? 0.0);
                        $valB = (float) ($b['marketValue'] ?? $b['market_value'] ?? 0.0);
                        return $valB <=> $valA;
                    });

                    // Deduct collateral from available cash
                    $accCash = max(0.0, $accCash - $accTotalRequiredCash);
                    $totalAvailableCash += $accCash;

                    $accountsSummary[] = [
                        'id'                 => $id . '_' . $accIndex,
                        'nickname'           => $accNickname,
                        'type'               => $accType,
                        'authorized'         => true,
                        'accountNumber'      => $accNum,
                        'account_num'        => $accNum,
                        'cash'               => $accCash,
                        'cashAvailable'      => $accCash,
                        'value'              => $accVal,
                        'liquidationValue'   => $accVal,
                        'positionsCount'     => count($accPositions),
                        'positions'          => $accPositions,
                        'openOrders'         => $matchedOrders,
                    ];
                }
            } else {
                // Fallback for flat lists with no account structures
                $rawPositions = $portfolio['positions'] ?? [];
                foreach ($rawPositions as $pos) {
                    $symbol = $pos['symbol'] ?? 'UNKNOWN';
                    $assetType = $pos['asset_type'] ?? 'EQUITY';
                    $qty = (float) ($pos['quantity'] ?? 0.0);
                    $mktVal = (float) ($pos['market_value'] ?? 0.0);
                    $costBasis = (float) ($pos['cost_basis'] ?? 0.0);
                    $unrealizedPL = (float) ($pos['unrealized_pl'] ?? 0.0);
                    $allPositions[] = $pos;

                    if (!isset($equityMap[$symbol])) {
                        $equityMap[$symbol] = [
                            'symbol' => $symbol,
                            'assetType' => $assetType,
                            'totalQuantity' => 0.0,
                            'totalCostBasis' => 0.0,
                            'totalMarketValue' => 0.0,
                            'totalUnrealizedPL' => 0.0,
                            'accountCount' => 0,
                            'accounts' => [],
                        ];
                    }
                    $equityMap[$symbol]['totalQuantity'] += $qty;
                    $equityMap[$symbol]['totalCostBasis'] += ($qty * $costBasis);
                    $equityMap[$symbol]['totalMarketValue'] += $mktVal;
                    $equityMap[$symbol]['totalUnrealizedPL'] += $unrealizedPL;
                    $equityMap[$symbol]['accountCount']++;
                    $equityMap[$symbol]['accounts'][] = [
                        'accountNumber' => $portfolio['account_number'] ?? 'N/A',
                        'nickname' => $broker->getNickname(),
                        'type' => $broker->getType(),
                        'quantity' => $qty,
                        'marketValue' => $mktVal,
                        'averagePrice' => $costBasis,
                    ];
                }

                $accountsSummary[] = [
                    'id'                 => $id,
                    'nickname'           => $broker->getNickname(),
                    'type'               => $broker->getType(),
                    'authorized'         => true,
                    'accountNumber'      => $portfolio['account_number'] ?? 'N/A',
                    'account_num'        => $portfolio['account_number'] ?? 'N/A',
                    'cash'               => $cash,
                    'cashAvailable'      => $cash,
                    'value'              => $pVal,
                    'liquidationValue'   => $pVal,
                    'positionsCount'     => count($rawPositions),
                    'positions'          => $rawPositions,
                ];
            }
        }

        // --- EXTRACT OPTIONS & PLEDGED SHARES LINKED TO STOCKS ---
        $optionsMap = [];
        $accountOptionPledges = [];
        $accountOptions = [];

        foreach ($equityMap as $symbol => $e) {
            if ($e['assetType'] === 'OPTION') {
                $normSym = TaxEngine::normalizeOptionSymbol($symbol);
                // Parse underlying ticker symbol from OCC string: e.g. "NVDA 260807C00215000" -> "NVDA"
                if (preg_match('/^([A-Z0-9]+)\s*(\d{2})(\d{2})(\d{2})([CP])(\d{8})$/', $normSym, $match)) {
                    $root = $match[1];
                    $yy = $match[2];
                    $mm = $match[3];
                    $dd = $match[4];
                    $type = $match[5] === 'C' ? 'Call' : 'Put';
                    $strike = (float) (((int) $match[6]) / 1000);
                    $dateStr = "20{$yy}-{$mm}-{$dd}";

                    $contractCount = max(1, (int) abs($e['totalQuantity']));
                    $pledgedShares = $type === 'Call' ? ($contractCount * 100) : 0;
                    $cashCollateral = $type === 'Put' ? ($contractCount * 100 * $strike) : 0.0;

                    $readableSym = TaxEngine::formatOptionReadable($normSym);
                    $strikeStr = number_format($strike, 2);
                    $cashStr = number_format($cashCollateral, 2);

                    $status = $type === 'Call'
                        ? "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;\">lock</span> COVERED CALL ACTIVE — {$pledgedShares} Shares Pledged ({$contractCount} Contracts, Strike: \${$strikeStr}, Exp: {$dateStr})"
                        : "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;\">shield</span> CASH-SECURED PUT ACTIVE — \${$cashStr} Cash Collateral ({$contractCount} Contracts, Strike: \${$strikeStr}, Exp: {$dateStr})";

                    $optAccounts = [];
                    foreach ($e['accounts'] as $optAcc) {
                        $optAccounts[] = [
                            'accountNumber' => $optAcc['accountNumber'],
                            'nickname' => $optAcc['nickname'] ?? '',
                            'quantity' => $optAcc['quantity'],
                        ];
                    }

                    $optItem = [
                        'symbol' => $normSym,
                        'rawSymbol' => $symbol,
                        'readableSymbol' => $readableSym,
                        'type' => $type,
                        'strike' => $strike,
                        'strikeStr' => $strikeStr,
                        'expiration' => $dateStr,
                        'contracts' => $contractCount,
                        'marketValue' => round($e['totalMarketValue'], 2),
                        'unrealizedPL' => round($e['totalUnrealizedPL'], 2),
                        'pledgedShares' => $pledgedShares,
                        'cashCollateral' => $cashCollateral,
                        'cashCollateralStr' => $cashStr,
                        'status' => $status,
                        'accounts' => $optAccounts,
                    ];

                    if (!isset($optionsMap[$root])) {
                        $optionsMap[$root] = [];
                    }
                    $optionsMap[$root][] = $optItem;

                    // Map options directly to their owning accounts
                    foreach ($e['accounts'] as $optAcc) {
                        $accNum = $optAcc['accountNumber'];
                        if (!isset($accountOptions[$root][$accNum])) {
                            $accountOptions[$root][$accNum] = [];
                        }
                        $accountOptions[$root][$accNum][] = $optItem;

                        if ($type === 'Call') {
                            if (!isset($accountOptionPledges[$root][$accNum])) {
                                $accountOptionPledges[$root][$accNum] = 0;
                            }
                            $accountOptionPledges[$root][$accNum] += (abs($optAcc['quantity']) * 100);
                        }
                    }
                }
            }
        }

        // Ensure every root stock with options exists in $equityMap, and all accounts holding options exist in $equityMap[$root]['accounts']
        foreach ($optionsMap as $root => $opts) {
            if (!isset($equityMap[$root])) {
                $equityMap[$root] = [
                    'symbol' => $root,
                    'assetType' => 'EQUITY',
                    'totalQuantity' => 0.0,
                    'totalCostBasis' => 0.0,
                    'totalMarketValue' => 0.0,
                    'totalUnrealizedPL' => 0.0,
                    'accountCount' => 0,
                    'accounts' => [],
                ];
            }
            foreach ($opts as $optItem) {
                foreach ($optItem['accounts'] as $optAcc) {
                    $found = false;
                    foreach ($equityMap[$root]['accounts'] as &$accRef) {
                        if ($accRef['accountNumber'] === $optAcc['accountNumber']) {
                            $found = true;
                            break;
                        }
                    }
                    unset($accRef);
                    if (!$found) {
                        $equityMap[$root]['accounts'][] = [
                            'accountNumber' => $optAcc['accountNumber'],
                            'nickname' => $optAcc['nickname'] ?? '',
                            'type' => 'MARGIN',
                            'quantity' => 0.0,
                            'marketValue' => 0.0,
                            'averagePrice' => 0.0,
                        ];
                        $equityMap[$root]['accountCount']++;
                    }
                }
            }
        }

        // --- BUILD FINAL AGGREGATED EQUITIES RESPONSE ARRAY ---
        $aggregatedEquities = [];

        foreach ($equityMap as $symbol => $e) {
            if ($e['assetType'] !== 'OPTION') {
                $cost = $e['totalCostBasis'];
                $qty = $e['totalQuantity'];
                $mkt = $e['totalMarketValue'];
                $pl = $e['totalUnrealizedPL'];

                $linkedOpts = $optionsMap[$symbol] ?? [];
                $pledgedShares = 0;
                $callCount = 0;
                $putCount = 0;
                $tickerCashCollateral = 0.0;

                foreach ($linkedOpts as $opt) {
                    if ($opt['type'] === 'Call') {
                        $pledgedShares += $opt['pledgedShares'];
                        $callCount++;
                    } else {
                        $putCount++;
                        $tickerCashCollateral += $opt['cashCollateral'];
                    }
                }

                $availableShares = max(0.0, $qty - $pledgedShares);
                $isFullyCovered = $callCount > 0 && $availableShares <= 0;

                // Account breakdown
                $accountBreakdown = [];
                foreach ($e['accounts'] as $accInfo) {
                    $accNum = $accInfo['accountNumber'];
                    $accQty = $accInfo['quantity'];
                    $accPledged = $accountOptionPledges[$symbol][$accNum] ?? 0;
                    $accPledged = min($accQty, $accPledged);
                    $accAvail = max(0.0, $accQty - $accPledged);
                    $canUseForCalls = $accAvail >= 100;
                    $eligibleContracts = floor($accAvail / 100);

                    $accLinkedOpts = $accountOptions[$symbol][$accNum] ?? [];
                    $hasAccCalls = false;
                    $hasAccPuts = false;
                    $accCashCollateral = 0.0;
                    foreach ($accLinkedOpts as $alo) {
                        if ($alo['type'] === 'Call') {
                            $hasAccCalls = true;
                        } else {
                            $hasAccPuts = true;
                            $accCashCollateral += $alo['cashCollateral'];
                        }
                    }

                    if ($accQty == 0 && count($accLinkedOpts) > 0) {
                        if ($hasAccPuts) {
                            $cStr = number_format($accCashCollateral, 2);
                            $badge = "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;color:var(--blue);\">shield</span> 0 Shares Held (Cash-Secured Put Active — \${$cStr} Cash Collateral)";
                            $badgeClass = "b";
                        } else {
                            $badge = "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;\">lock</span> 0 Shares Held (Option Active)";
                            $badgeClass = "m";
                        }
                    } elseif ($accPledged > 0 && $accAvail == 0) {
                        $badge = "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;\">lock</span> 0 Available (100% Pledged to Covered Calls)";
                        $badgeClass = "r";
                    } elseif ($accAvail < 100) {
                        $badge = "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;color:var(--yellow, #fbbf24);\">warning</span> {$accAvail} Unencumbered Shares (< 100 shares — CANNOT be used for Call options)";
                        $badgeClass = "y";
                    } else {
                        $badge = "<span class=\"material-symbols-outlined\" style=\"font-size:12px;vertical-align:middle;color:var(--green);\">check_circle</span> {$accAvail} Unencumbered Shares (Eligible for {$eligibleContracts} Covered Call Contracts)";
                        $badgeClass = "g";
                    }

                    $accountBreakdown[] = [
                        'accountNumber' => $accNum,
                        'nickname' => $accInfo['nickname'] ?? '',
                        'type' => $accInfo['type'],
                        'quantity' => $accQty,
                        'marketValue' => $accInfo['marketValue'],
                        'pledgedShares' => $accPledged,
                        'availableShares' => round($accAvail, 4),
                        'canUseForCalls' => $canUseForCalls,
                        'eligibleContracts' => $eligibleContracts,
                        'statusBadge' => $badge,
                        'badgeClass' => $badgeClass,
                        'linkedOptions' => $accLinkedOpts,
                    ];
                }

                // Top-level status badge
                if ($callCount > 0 && $isFullyCovered) {
                    $topStatusBadge = "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;\">lock</span> COVERED CALL ACTIVE (100% Shares Pledged — 0 Available for new Calls)";
                } elseif ($callCount > 0 && $availableShares < 100) {
                    $topStatusBadge = "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;color:var(--yellow, #fbbf24);\">warning</span> COVERED CALL ACTIVE ({$pledgedShares} Pledged — {$availableShares} Shares Available, < 100)";
                } elseif ($callCount > 0) {
                    $topStatusBadge = "<span class=\"material-symbols-outlined\" style=\"font-size:12px;vertical-align:middle;color:var(--green);\">check_circle</span> COVERED CALL ACTIVE ({$pledgedShares} Pledged — {$availableShares} Available for new Calls)";
                } elseif ($putCount > 0) {
                    $cStr = number_format($tickerCashCollateral, 2);
                    if ($availableShares >= 100) {
                        $topStatusBadge = "<span class=\"material-symbols-outlined\" style=\"font-size:12px;vertical-align:middle;color:var(--green);\">check_circle</span> UNENCUMBERED SHARES ({$availableShares} Shares Available for Calls) + CSP ACTIVE (\${$cStr} Cash)";
                    } else {
                        $topStatusBadge = "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;color:var(--blue);\">shield</span> CASH-SECURED PUT ACTIVE (\${$cStr} Cash Collateral)";
                    }
                } elseif ($availableShares < 100) {
                    $topStatusBadge = "<span class=\"material-symbols-outlined\" style=\"font-size:inherit;vertical-align:middle;color:var(--yellow, #fbbf24);\">warning</span> {$availableShares} Shares Available (< 100 shares — CANNOT be used for Call options)";
                } else {
                    $topStatusBadge = "<span class=\"material-symbols-outlined\" style=\"font-size:12px;vertical-align:middle;color:var(--green);\">check_circle</span> UNENCUMBERED ({$availableShares} Shares Available for Covered Calls)";
                }

                $aggregatedEquities[] = [
                    'symbol' => $symbol,
                    'assetType' => $e['assetType'],
                    'quantity' => round($qty, 4),
                    'averagePrice' => $qty > 0 ? round($cost / $qty, 2) : 0.0,
                    'marketValue' => round($mkt, 2),
                    'unrealizedPL' => round($pl, 2),
                    'unrealizedPLPct' => $cost > 0 ? round(($pl / $cost) * 100, 1) : 0.0,
                    'allocationPct' => $totalPortfolioVal > 0 ? round(($mkt / $totalPortfolioVal) * 100, 1) : 0.0,
                    'accountCount' => $e['accountCount'],
                    'linkedOptions' => $linkedOpts,
                    'callCount' => $callCount,
                    'putCount' => $putCount,
                    'cashCollateral' => $tickerCashCollateral,
                    'pledgedShares' => $pledgedShares,
                    'availableShares' => round($availableShares, 4),
                    'isFullyCovered' => $isFullyCovered,
                    'accountBreakdown' => $accountBreakdown,
                    'canUseForCalls' => $availableShares >= 100,
                    'statusBadge' => $topStatusBadge,
                ];
            }
        }

        usort($aggregatedEquities, fn($a, $b) => $b['marketValue'] <=> $a['marketValue']);

        // Load active broker instance details to construct the dataSource block expected by templates
        $instances = $this->appConfig->getBrokerInstances();
        $primaryInst = $instances[0] ?? ['type' => 'schwab', 'nickname' => 'Primary Broker'];
        $providerName = match(strtolower($primaryInst['type'] ?? 'schwab')) {
            'schwab'     => 'Charles Schwab Trader API',
            'ibkr'       => 'Interactive Brokers API',
            'tastytrade' => 'Tastytrade API',
            'alpaca'     => 'Alpaca Markets API',
            default      => 'Connected Brokerage API'
        };

        return [
            'status'                => 'success',
            'authorized_count'      => $authorizedCount,
            'total_brokers'         => count($this->brokers),
            'netLiquidationValue'   => $totalPortfolioVal,
            'cashBalance'           => $totalCash,
            'availableCash'         => $totalAvailableCash,
            'accounts'              => $accountsSummary,
            'balances'              => [
                'cash'            => $totalCash,
                'portfolio_value' => $totalPortfolioVal,
            ],
            'positions'             => $allPositions,
            'aggregatedEquities'    => $aggregatedEquities,
            'dataSource'            => [
                'provider'      => $providerName,
                'providerCode'  => strtoupper($primaryInst['type'] ?? 'schwab'),
                'nickname'      => $primaryInst['nickname'] ?? 'Primary Broker',
                'endpoint'      => '/trader/v1/accounts?fields=positions',
                'mode'          => 'LIVE_API',
                'timestamp'     => date('H:i T'),
                'isSanitized'   => true,
                'totalAccounts' => count($accountsSummary),
            ]
        ];
    }

    /**
     * Aggregates history across all active brokers and local imported portfolio events.
     * Real-time API calls always take authoritative precedence over cached/CSV records.
     *
     * @param int $days Historical window in calendar days.
     * @param bool $forceRefresh When true, bypasses daily cache gate.
     * @return array List of normalized transaction records sorted chronologically descending.
     */
    public function getAggregatedHistory(int $days = 30, bool $forceRefresh = false): array
    {
        $allHistory = [];
        $apiDedupeIndex = [];

        // Helper to normalize stock and option symbology
        $normalizeSym = function(string $s): string {
            return TaxEngine::normalizeSymbol($s);
        };

        // 1. Fetch live broker history (authoritative source of truth)
        foreach ($this->brokers as $broker) {
            $h = $broker->getAccountHistory($days, $forceRefresh);
            foreach ($h as $item) {
                $date = $item['date'] ?? '';
                $sym = strtoupper(trim($item['symbol'] ?? ''));
                $normSym = $normalizeSym($sym);

                // Format options to readable and equities to standard tickers
                if (TaxEngine::isOptionSymbol($sym)) {
                    $item['symbol'] = TaxEngine::formatOptionReadable($normSym);
                    $item['raw_symbol'] = $normSym;
                } else {
                    $item['symbol'] = $normSym;
                }

                $allHistory[] = $item;
                $amt = round((float)($item['amount'] ?? 0), 2);
                $acc = strtoupper(trim($item['account_nickname'] ?? $item['account_number'] ?? ''));

                // Index by raw symbol, normalized symbol, and amount
                $apiDedupeIndex["{$date}_{$sym}_{$amt}_{$acc}"] = true;
                $apiDedupeIndex["{$date}_{$sym}_{$amt}"] = true;
                $apiDedupeIndex["{$date}_{$normSym}_{$amt}_{$acc}"] = true;
                $apiDedupeIndex["{$date}_{$normSym}_{$amt}"] = true;
            }
        }

        // 2. Merge locally imported CSV portfolio events from DB (fallback for historical periods not returned by API)
        if ($this->connection) {
            try {
                $limitDate = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d');
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT event_date as date, event_type as type, provider, account_number, symbol, impact_amount as amount, quantity, price, fees, action, title, description, cost_basis FROM portfolio_events WHERE event_date >= :limitDate ORDER BY event_date DESC',
                    ['limitDate' => $limitDate]
                );

                $dbDedupeIndex = [];
                foreach ($rows as $r) {
                    $date = $r['date'] ?? '';
                    $sym = strtoupper(trim($r['symbol'] ?? ''));
                    $normSym = $normalizeSym($sym);
                    $amt = round((float)($r['amount'] ?? 0), 2);
                    $acc = strtoupper(trim($r['account_number'] ?? ''));

                    // If a live API transaction already exists for this date, symbol, and amount, API overwrites/takes precedence
                    if (
                        isset($apiDedupeIndex["{$date}_{$sym}_{$amt}_{$acc}"]) 
                        || isset($apiDedupeIndex["{$date}_{$sym}_{$amt}"])
                        || isset($apiDedupeIndex["{$date}_{$normSym}_{$amt}_{$acc}"])
                        || isset($apiDedupeIndex["{$date}_{$normSym}_{$amt}"])
                    ) {
                        continue;
                    }

                    // Also deduplicate duplicate rows within the database itself (e.g. OCC vs Human readable imported rows)
                    $dbKey = "{$date}_{$normSym}_{$amt}_{$acc}";
                    if (isset($dbDedupeIndex[$dbKey])) {
                        continue;
                    }
                    $dbDedupeIndex[$dbKey] = true;

                    $desc = $r['description'] ?? $r['title'] ?? '';
                    $action = strtoupper($r['action'] ?? '');
                    $qty = (float) ($r['quantity'] ?? 0.0);
                    $price = (float) ($r['price'] ?? 0.0);
                    $amount = (float) ($r['amount'] ?? 0.0);
                    
                    // For Sell actions, qty should be negative for FIFO matching in TaxEngine
                    $isSell = (str_contains($action, 'SELL') || str_contains(strtoupper($desc), 'SELL'));
                    if ($isSell && $qty > 0) {
                        $qty = -$qty;
                    }

                    $isJournal = in_array($action, ['JOURNAL', 'TRANSFER', 'SECURITY TRANSFER', 'SYSTEM TRANSFER']);
                    $txType = $isJournal ? 'JOURNAL' : ($r['type'] === 'EQUITY' ? 'TRADE' : $r['type']);

                    $allHistory[] = [
                        'id' => 'db_' . md5("{$date}_{$sym}_{$amt}_{$acc}_" . $desc),
                        'date' => $r['date'],
                        'type' => $txType,
                        'action' => $action,
                        'symbol' => $r['symbol'],
                        'amount' => $amount,
                        'fees' => (float) ($r['fees'] ?? 0.0),
                        'description' => $desc,
                        'account_number' => $r['account_number'] ?? 'V-Brokerage',
                        'account_nickname' => $r['account_number'] ?? 'V-Brokerage',
                        'transfer_items' => [
                            [
                                'symbol' => $r['symbol'],
                                'amount' => $qty,
                                'price' => $price > 0 ? $price : abs($amount),
                                'asset_type' => $r['type'] === 'OPTION' ? 'OPTION' : 'EQUITY',
                                'cost' => (float) ($r['cost_basis'] ?? 0.0),
                            ]
                        ]
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('BrokerManagerService DB events merge error: ' . $e->getMessage());
            }
        }

        // Sort by date descending
        usort($allHistory, function ($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });

        // 3. Normalize all history records through the single canonical transaction pipeline
        $normalizedHistory = [];
        foreach ($allHistory as $item) {
            $normalizedHistory[] = self::normalizeTransaction($item);
        }

        return $normalizedHistory;
    }

    /**
     * Canonical transaction normalization pipeline.
     * Enriches transactions with standard category, symbols, formatted descriptions, and account classifications.
     *
     * @param array $tx Raw or semi-sanitized transaction array.
     * @return array Fully normalized canonical transaction.
     */
    public static function normalizeTransaction(array $tx): array
    {
        $rawType  = strtoupper(trim((string)($tx['type'] ?? '')));
        $rawSym   = trim((string)($tx['symbol'] ?? ''));
        $rawDesc  = trim((string)($tx['description'] ?? ''));
        $action   = strtoupper(trim((string)($tx['action'] ?? '')));
        $amt      = (float) ($tx['amount'] ?? 0.0);
        $fees     = (float) ($tx['fees'] ?? 0.0);

        // Analyze transfer items
        $transferItems = $tx['transfer_items'] ?? [];
        $hasOptionItem = false;
        $mainItem      = null;

        if (is_array($transferItems)) {
            foreach ($transferItems as $it) {
                $itemAssetType = strtoupper(trim((string)($it['asset_type'] ?? '')));
                if ($itemAssetType === 'OPTION') {
                    $hasOptionItem = true;
                }
                if ($mainItem === null && in_array($itemAssetType, ['OPTION', 'EQUITY'])) {
                    $mainItem = $it;
                }
            }
            if ($mainItem === null && !empty($transferItems)) {
                $mainItem = $transferItems[0];
            }
        }

        // Determine if this is an option transaction
        $itemDesc = $mainItem ? trim((string)($mainItem['description'] ?? '')) : '';
        $itemSym  = $mainItem ? trim((string)($mainItem['symbol'] ?? '')) : '';

        $isOption = $hasOptionItem
            || $rawType === 'OPTION'
            || str_contains($rawType, 'OPTION')
            || TaxEngine::isOptionSymbol($rawSym, $rawDesc)
            || ($itemSym && TaxEngine::isOptionSymbol($itemSym, $itemDesc))
            || ($itemDesc && TaxEngine::isOptionSymbol('', $itemDesc))
            || preg_match('/\b(CALL|PUT)\b/i', $rawDesc)
            || preg_match('/\b(CALL|PUT)\b/i', $itemDesc);

        // Guard against bond redemption or CD interest being mistaken for options
        $isBondOrCd = str_contains($rawDesc, '**CALLED**') 
            || str_contains($rawDesc, 'BOND INTEREST') 
            || str_contains($rawDesc, 'CD INTEREST') 
            || str_contains($rawDesc, '%CD')
            || str_contains($itemDesc, '**CALLED**')
            || str_contains($itemDesc, 'BOND INTEREST');

        if ($isBondOrCd) {
            $isOption = false;
        }

        $upperSym  = strtoupper($rawSym);
        $upperDesc = strtoupper($rawDesc);

        // Assign canonical category
        if ($isOption) {
            $category = 'OPTION';
        } elseif ($rawType === 'DIVIDEND' || str_contains($rawType, 'DIV') || str_contains($action, 'DIVIDEND') || str_contains($upperDesc, 'DIVIDEND')) {
            if ($upperSym === 'INT' || str_contains($upperDesc, 'BANK INT') || str_contains($upperDesc, 'SCHWAB1 INT') || str_contains($upperDesc, 'CREDIT INT') || str_contains($upperDesc, 'INTEREST')) {
                $category = 'INTEREST';
            } else {
                $category = 'DIVIDEND';
            }
        } elseif ($rawType === 'INTEREST' || str_contains($rawType, 'INTEREST') || $upperSym === 'INT' || str_contains($upperDesc, 'INTEREST')) {
            $category = 'INTEREST';
        } elseif ($rawType === 'FEE' || str_contains($rawType, 'FEE') || $upperSym === 'SEC' || str_contains($upperDesc, 'FEE') || str_contains($action, 'FEE')) {
            $category = 'FEE';
        } elseif ($rawType === 'JOURNAL' || str_contains($rawType, 'JOURNAL') || str_contains($rawType, 'TRANSFER') || str_contains($upperDesc, 'TRANSFER') || str_contains($upperDesc, 'JOURNAL') || str_contains($action, 'JOURNAL') || str_contains($action, 'TRANSFER')) {
            $category = 'JOURNAL';
        } elseif ($rawType === 'TRADE' || str_contains($rawType, 'TRADE') || str_contains($action, 'BUY') || str_contains($action, 'SELL')) {
            $category = 'TRADE';
        } else {
            $category = 'OTHER';
        }

        // Canonical and Display Symbols
        $effectiveSym = ($rawSym && $rawSym !== 'CURRENCY_USD') ? $rawSym : ($itemSym ?: $rawSym);
        $canonicalSymbol = TaxEngine::normalizeSymbol($effectiveSym);

        if ($isOption) {
            $displaySymbol = TaxEngine::formatOptionReadable($canonicalSymbol);
            $underlyingSymbol = '';
            if (preg_match('/^([A-Z0-9]+)\s+/i', $canonicalSymbol, $m)) {
                $underlyingSymbol = strtoupper($m[1]);
            }
        } else {
            $displaySymbol = $canonicalSymbol;
            $underlyingSymbol = $canonicalSymbol;
        }

        // Display descriptions
        $mainDesc = $rawDesc;
        $detailParts = [];

        if ($mainItem) {
            $cleanItemDesc = trim((string)($mainItem['description'] ?? ''));
            if ($cleanItemDesc && $cleanItemDesc !== 'USD currency' && $cleanItemDesc !== $mainDesc) {
                if (!$mainDesc || $mainDesc === 'USD currency') {
                    $mainDesc = $cleanItemDesc;
                } else {
                    $detailParts[] = $cleanItemDesc;
                }
            }
            if (!empty($mainItem['position_effect'])) {
                $detailParts[] = '[' . strtoupper($mainItem['position_effect']) . ']';
            }
            $itemAmt = (float)($mainItem['amount'] ?? 0.0);
            if ($itemAmt != 0 && strtoupper($mainItem['asset_type'] ?? '') !== 'CURRENCY') {
                $qty = abs($itemAmt);
                $act = $itemAmt > 0 ? 'Bought' : 'Sold';
                $price = !empty($mainItem['price']) ? '@ $' . number_format((float)$mainItem['price'], 2) : '';
                $detailParts[] = trim("{$act} {$qty} {$price}");
            }
        }

        if (!$mainDesc || $mainDesc === 'USD currency') {
            $mainDesc = $tx['action'] ?? $tx['type'] ?? '—';
        }

        $detail = implode(' • ', array_filter($detailParts));

        $accName = trim((string)($tx['account_nickname'] ?? $tx['account_number'] ?? 'Brokerage'));
        $accCategory = TaxEngine::isRetirementAccount($accName, $tx['sub_account'] ?? '') ? 'RETIREMENT' : 'TAXABLE';

        $tx['category']          = $category;
        $tx['is_option']         = $isOption;
        $tx['canonical_symbol']  = $canonicalSymbol;
        $tx['display_symbol']    = $displaySymbol;
        $tx['symbol']            = $displaySymbol;
        $tx['raw_symbol']        = $canonicalSymbol;
        $tx['underlying_symbol'] = $underlyingSymbol;
        $tx['display_main']      = $mainDesc;
        $tx['display_detail']    = $detail;
        $tx['is_credit']         = ($amt > 0);
        $tx['account_category']  = $accCategory;
        $tx['account_name']      = $accName;

        return $tx;
    }

    /**
     * Aggregates order fill history across all active brokers.
     *
     * @param int $days Historical window in calendar days.
     * @param bool $forceRefresh When true, bypasses cache.
     * @return array List of normalized order records.
     */
    public function getAggregatedOrderHistory(int $days = 30, bool $forceRefresh = false): array
    {
        $allOrders = [];
        foreach ($this->brokers as $broker) {
            if (!$broker->isConfigured()) continue;
            $orders = $broker->getOrderHistory($days, $forceRefresh);
            foreach ($orders as $item) {
                $allOrders[] = $item;
            }
        }

        usort($allOrders, function ($a, $b) {
            return strcmp($b['enteredTime'] ?? '', $a['enteredTime'] ?? '');
        });

        return $allOrders;
    }

    /**
     * Fetches option chain from preferred broker or first authorized broker.
     *
     * @param string $symbol Underlying equity ticker symbol.
     * @param float $currentPrice Current market price.
     * @param string|null $preferredBrokerId Optional preferred broker identifier.
     * @return array Normalized option chain array.
     */
    public function getOptionChain(string $symbol, float $currentPrice, ?string $preferredBrokerId = null): array
    {
        if ($preferredBrokerId && isset($this->brokers[$preferredBrokerId])) {
            $broker = $this->brokers[$preferredBrokerId];
            if ($broker->isAuthorized()) {
                return $broker->getOptionChain($symbol, $currentPrice);
            }
        }

        foreach ($this->brokers as $broker) {
            if ($broker->isAuthorized()) {
                return $broker->getOptionChain($symbol, $currentPrice);
            }
        }

        return [
            'error'           => 'No authorized broker available to fetch option chain.',
            'symbol'          => strtoupper($symbol),
            'underlyingPrice' => $currentPrice,
            'calls'           => [],
            'puts'            => [],
        ];
    }

    /**
     * Fetch active open orders across all registered brokers.
     *
     * @param bool $forceRefresh When true, bypasses cache to query API.
     * @return array List of open order dictionaries.
     */
    public function getAggregatedOpenOrders(bool $forceRefresh = false): array
    {
        $allOrders = [];
        foreach ($this->brokers as $id => $broker) {
            if (!$broker->isConfigured()) {
                continue;
            }
            try {
                $orders = $broker->getOpenOrders($forceRefresh);
                foreach ($orders as $o) {
                    $o['broker_id'] = $id;
                    $o['broker_nickname'] = $broker->getNickname();
                    $allOrders[] = $o;
                }
            } catch (\Throwable $e) {
                $this->logger->error("Error fetching open orders from broker {$id}: " . $e->getMessage());
            }
        }
        return $allOrders;
    }
}
