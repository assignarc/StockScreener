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

class BrokerManagerService
{
    /** @var array<string, BrokerInterface> */
    private array $brokers = [];

    public function __construct(
        private AppConfigService $appConfig,
        private PersistentCacheService $cache,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger
    ) {
        $this->initializeBrokers();
    }

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

    public function getBroker(string $id): ?BrokerInterface
    {
        return $this->brokers[$id] ?? null;
    }

    /**
     * @return array<string, BrokerInterface>
     */
    public function getBrokers(): array
    {
        return $this->brokers;
    }

    public function getAggregatedPortfolio(): array
    {
        $allPositions = [];
        $totalCash = 0.0;
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
            if (!empty($brokerAccounts)) {
                foreach ($brokerAccounts as $accIndex => $accItem) {
                    $accNum = $accItem['accountNumber'] ?? 'N/A';
                    $accNickname = $accItem['nickname'] ?? ($broker->getNickname() . ' (' . $accNum . ')');
                    $accType = $accItem['type'] ?? 'MARGIN';
                    $accVal = (float) ($accItem['liquidationValue'] ?? 0.0);
                    $accCash = (float) ($accItem['cashAvailable'] ?? 0.0);
                    $accPositions = $accItem['positions'] ?? [];

                    foreach ($accPositions as $posItem) {
                        $symbol = $posItem['symbol'] ?? 'UNKNOWN';
                        $assetType = $posItem['asset_type'] ?? $posItem['assetType'] ?? 'EQUITY';
                        $qty = (float) ($posItem['quantity'] ?? 0.0);
                        $mktVal = (float) ($posItem['market_value'] ?? $posItem['marketValue'] ?? 0.0);
                        $costBasis = (float) ($posItem['cost_basis'] ?? $posItem['averagePrice'] ?? 0.0);
                        $unrealizedPL = (float) ($posItem['unrealized_pl'] ?? $posItem['unrealizedPL'] ?? 0.0);
                        $unrealizedPLPct = (float) ($posItem['unrealized_pl_pct'] ?? $posItem['unrealizedPLPct'] ?? 0.0);

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

        foreach ($equityMap as $symbol => $e) {
            if ($e['assetType'] === 'OPTION') {
                // Parse underlying ticker symbol from standard OCC string: e.g. "NVDA 260807C00215000" -> "NVDA"
                if (preg_match('/^([A-Z0-9]+)\s*(\d{2})(\d{2})(\d{2})([CP])(\d{8})$/', $symbol, $match)) {
                    $root = $match[1];
                    $yy = $match[2];
                    $mm = $match[3];
                    $dd = $match[4];
                    $type = $match[5] === 'C' ? 'Call' : 'Put';
                    $strike = (float) (((int) $match[6]) / 1000);
                    $dateStr = "20{$yy}-{$mm}-{$dd}";

                    $contractCount = max(1, (int) abs($e['totalQuantity']));
                    $pledgedShares = $contractCount * 100;

                    if (!isset($optionsMap[$root])) {
                        $optionsMap[$root] = [];
                    }

                    $optionsMap[$root][] = [
                        'symbol' => $symbol,
                        'type' => $type,
                        'strike' => $strike,
                        'expiration' => $dateStr,
                        'contracts' => $contractCount,
                        'marketValue' => round($e['totalMarketValue'], 2),
                        'unrealizedPL' => round($e['totalUnrealizedPL'], 2),
                        'pledgedShares' => $pledgedShares,
                        'status' => "🔒 COVERED CALL ACTIVE — {$pledgedShares} Shares Pledged ({$contractCount} Contracts, Strike: \${$strike}, Exp: {$dateStr})",
                    ];

                    foreach ($e['accounts'] as $optAcc) {
                        $accNum = $optAcc['accountNumber'];
                        if (!isset($accountOptionPledges[$root][$accNum])) {
                            $accountOptionPledges[$root][$accNum] = 0;
                        }
                        $accountOptionPledges[$root][$accNum] += ($optAcc['quantity'] * 100);
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
                foreach ($linkedOpts as $opt) {
                    $pledgedShares += $opt['pledgedShares'];
                }

                $availableShares = max(0.0, $qty - $pledgedShares);
                $isFullyCovered = count($linkedOpts) > 0 && $availableShares <= 0;

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

                    if ($accPledged > 0 && $accAvail == 0) {
                        $badge = "🔒 0 Available (100% Pledged to Covered Calls)";
                        $badgeClass = "r";
                    } elseif ($accAvail < 100) {
                        $badge = "⚠️ {$accAvail} Unencumbered Shares (< 100 shares — CANNOT be used for Call options)";
                        $badgeClass = "y";
                    } else {
                        $badge = "🟢 {$accAvail} Unencumbered Shares (Eligible for {$eligibleContracts} Covered Call Contracts)";
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
                    ];
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
                    'pledgedShares' => $pledgedShares,
                    'availableShares' => round($availableShares, 4),
                    'isFullyCovered' => $isFullyCovered,
                    'accountBreakdown' => $accountBreakdown,
                    'canUseForCalls' => $availableShares >= 100,
                    'statusBadge' => count($linkedOpts) > 0 
                        ? ($isFullyCovered 
                            ? "🔒 COVERED CALL ACTIVE (100% Shares Pledged — 0 Available for new Calls)" 
                            : ($availableShares < 100 
                                ? "⚠️ {$availableShares} Shares Available (< 100 shares — CANNOT be used for Call options)" 
                                : "🟢 COVERED CALL ACTIVE ({$pledgedShares} Pledged — {$availableShares} Available for new Calls)"))
                        : ($availableShares < 100 
                            ? "⚠️ {$availableShares} Shares Available (< 100 shares — CANNOT be used for Call options)" 
                            : "🟢 UNENCUMBERED ({$availableShares} Shares Available for Covered Calls)"),
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
     * Aggregates history across all active brokers.
     */
    public function getAggregatedHistory(int $days = 30): array
    {
        $allHistory = [];
        foreach ($this->brokers as $broker) {
            if ($broker->isAuthorized()) {
                $h = $broker->getAccountHistory($days);
                foreach ($h as $item) {
                    $allHistory[] = $item;
                }
            }
        }

        // Sort by date descending
        usort($allHistory, function ($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });

        return $allHistory;
    }

    /**
     * Fetches option chain from preferred broker or first authorized broker.
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
}
