<?php

namespace App\Broker;

use App\Service\AppConfigService;
use App\Service\PersistentCacheService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SchwabBroker implements BrokerInterface
{
    private const BASE_URL = 'https://api.schwabapi.com/marketdata/v1';
    private const TRADING_BASE_URL = 'https://api.schwabapi.com/trader/v1';

    public function __construct(
        private string $id,
        private string $nickname,
        private ?string $appKey,
        private ?string $appSecret,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private PersistentCacheService $cache,
        private AppConfigService $appConfig
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return 'schwab';
    }

    public function getNickname(): string
    {
        return $this->nickname ?: 'Schwab Account (' . $this->id . ')';
    }

    public function getEffectiveAppKey(): ?string
    {
        return !empty($this->appKey) ? $this->appKey : null;
    }

    public function getEffectiveAppSecret(): ?string
    {
        return !empty($this->appSecret) ? $this->appSecret : null;
    }

    public function isConfigured(): bool
    {
        return !empty($this->getEffectiveAppKey()) && !empty($this->getEffectiveAppSecret());
    }

    public function isTradingEnabled(): bool
    {
        $flag = $_ENV['TRADING_ENABLED'] ?? false;
        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    public function ensureTradingAllowed(): void
    {
        if (!$this->isTradingEnabled()) {
            throw new \RuntimeException(
                'ACCOUNT MUTATION BLOCKED: All brokerage account modifications are disabled ' .
                '(TRADING_ENABLED=false in .env). System is in Read-Only mode.'
            );
        }
    }

    public function isAuthorized(): bool
    {
        return $this->getAccessToken() !== null;
    }

    public function getAuthUrl(string $redirectUri, ?string $state = null): ?string
    {
        $params = [
            'client_id'    => $this->getEffectiveAppKey(),
            'redirect_uri' => $redirectUri,
        ];
        if ($state !== null) {
            $params['state'] = $state;
        }

        return 'https://api.schwabapi.com/v1/oauth/authorize?' . http_build_query($params);
    }

    public function exchangeAuthCode(string $code, string $redirectUri): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Schwab app_key and app_secret are missing in configuration'];
        }

        try {
            $credentials = base64_encode($this->getEffectiveAppKey() . ':' . $this->getEffectiveAppSecret());
            $response = $this->httpClient->request('POST', 'https://api.schwabapi.com/v1/oauth/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . $credentials,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type'   => 'authorization_code',
                    'code'         => $code,
                    'redirect_uri' => $redirectUri,
                ],
                'timeout'      => 5.0,
                'max_duration' => 10.0,
            ]);

            if ($response->getStatusCode() === 200) {
                $tokenData = $response->toArray();
                $tokenData['expires_at'] = time() + ($tokenData['expires_in'] ?? 1800);
                $this->writeTokenData($tokenData);
                return ['status' => 'success', 'data' => $tokenData, 'uri_used' => $redirectUri];
            }

            return ['error' => 'Schwab token exchange returned HTTP ' . $response->getStatusCode()];
        } catch (\Throwable $e) {
            $this->logger->error('Failed Schwab token exchange for broker ' . $this->id . ': ' . $e->getMessage());
            return ['error' => 'Token exchange failed: ' . $e->getMessage()];
        }
    }

    public function refreshAccessToken(): ?string
    {
        $tokenData = $this->readTokenData();
        if (!$tokenData) {
            return null;
        }

        $expiresAt = $tokenData['expires_at'] ?? 0;
        if (time() < $expiresAt - 60 && !empty($tokenData['access_token'])) {
            return $tokenData['access_token'];
        }

        $refreshToken = $tokenData['refresh_token'] ?? null;
        if (!$refreshToken) {
            return null;
        }

        try {
            $credentials = base64_encode($this->getEffectiveAppKey() . ':' . $this->getEffectiveAppSecret());
            $response = $this->httpClient->request('POST', 'https://api.schwabapi.com/v1/oauth/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . $credentials,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
                'timeout'      => 5.0,
                'max_duration' => 10.0,
            ]);

            if ($response->getStatusCode() === 200) {
                $newTokenData = $response->toArray();
                $newTokenData['refresh_token'] = $newTokenData['refresh_token'] ?? $refreshToken;
                $newTokenData['expires_at'] = time() + ($newTokenData['expires_in'] ?? 1800);
                $this->writeTokenData($newTokenData);
                return $newTokenData['access_token'] ?? null;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Schwab Refresh Token Error (' . $this->id . '): ' . $e->getMessage());
        }

        return null;
    }

    public function getAccessToken(): ?string
    {
        $tokenData = $this->readTokenData();
        if (!$tokenData) {
            return null;
        }

        $expiresAt = $tokenData['expires_at'] ?? 0;
        if (time() >= $expiresAt - 60) {
            return $this->refreshAccessToken();
        }

        return $tokenData['access_token'] ?? null;
    }

    public function purgeTokens(): bool
    {
        $this->appConfig->set('broker.' . $this->id . '.oauth_token', null);
        $this->cache->purgeByPrefix('broker.' . $this->id . '.');
        return true;
    }

    private function readTokenData(): ?array
    {
        $tokenData = $this->appConfig->get('broker.' . $this->id . '.oauth_token');
        if (is_array($tokenData) && !empty($tokenData['access_token'])) {
            return $tokenData;
        }

        return null;
    }

    private function writeTokenData(array $tokenData): void
    {
        $this->appConfig->set('broker.' . $this->id . '.oauth_token', $tokenData);
    }

    public function getAccountPortfolio(): array
    {
        $cacheKey = 'b' . $this->id . '.' . str_replace(' ', '_', strtolower($this->getNickname())) . '.portfolio';
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return [
                'error'          => 'Broker (' . $this->getNickname() . ') not authorized or token expired.',
                'account_number' => 'UNAUTHORIZED',
                'balances'       => ['cash' => 0.0, 'portfolio_value' => 0.0],
                'positions'      => [],
            ];
        }

        try {
            // 1. Query Schwab User Preferences API for live user-configured account nicknames
            $nicknameMap = [];
            try {
                $prefResponse = $this->httpClient->request('GET', self::TRADING_BASE_URL . '/userPreference', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                    ],
                    'timeout' => (float) $this->appConfig->get('api.timeout.broker.default', 8.0),
                ]);
                if ($prefResponse->getStatusCode() === 200) {
                    $prefData = $prefResponse->toArray();
                    $prefAccounts = $prefData['accounts'] ?? [];
                    foreach ($prefAccounts as $pa) {
                        $accNo = (string) ($pa['accountNumber'] ?? '');
                        if ($accNo !== '') {
                            $nicknameMap[$accNo] = $pa['nickName'] ?? $pa['nickname'] ?? null;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Schwab /userPreference API error: ' . $e->getMessage());
            }

            $response = $this->httpClient->request('GET', self::TRADING_BASE_URL . '/accounts', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query'   => ['fields' => 'positions'],
                'timeout' => (float) $this->appConfig->get('api.timeout.broker.default', 8.0),
            ]);

            if ($response->getStatusCode() !== 200) {
                return ['error' => 'API returned HTTP ' . $response->getStatusCode()];
            }

            $accounts = $response->toArray();
            $portfolio = $this->sanitizePortfolioData($accounts, $nicknameMap);
            $this->cache->set($cacheKey, $portfolio, (int) $this->appConfig->get('cache.ttl.broker.portfolio', 60), true);
            return $portfolio;
        } catch (\Throwable $e) {
            $this->logger->error('Schwab Portfolio Fetch Error (' . $this->id . '): ' . $e->getMessage());
            return ['error' => 'Failed fetching portfolio: ' . $e->getMessage()];
        }
    }

    public function getAccountHistory(int $days = 30, bool $forceRefresh = false): array
    {
        $cacheTtl = (int) $this->appConfig->get('cache.ttl.broker.history', 604800);
        $overallCacheKey = 'b' . $this->id . '.history.' . $days;
        
        if (!$forceRefresh) {
            $cachedOverall = $this->cache->get($overallCacheKey);
            if ($cachedOverall !== null) {
                return $cachedOverall;
            }
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return [];
        }

        $lastFetchKey = 'b' . $this->id . '.tx_last_fetched';
        $lastFetchDate = $this->cache->get($lastFetchKey);
        $todayStr = date('Y-m-d');
        $shouldFetchFromApi = $forceRefresh || ($lastFetchDate !== $todayStr);

        // Fetch user preferences for nickname mapping (only if querying API)
        $nicknameMap = [];
        if ($shouldFetchFromApi) {
            try {
                $prefResponse = $this->httpClient->request('GET', self::TRADING_BASE_URL . '/userPreference', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept'        => 'application/json',
                    ],
                    'timeout' => (float) $this->appConfig->get('api.timeout.broker.default', 8.0),
                ]);
                if ($prefResponse->getStatusCode() === 200) {
                    $prefData = $prefResponse->toArray();
                    foreach ($prefData['accounts'] ?? [] as $pa) {
                        $accNo = (string) ($pa['accountNumber'] ?? '');
                        if ($accNo !== '') {
                            $nicknameMap[$accNo] = $pa['nickName'] ?? $pa['nickname'] ?? null;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Schwab /userPreference API error in history: ' . $e->getMessage());
            }
        }

        // Fetch account hash values map for transactions endpoint (only if querying API)
        $hashMap = [];
        if ($shouldFetchFromApi) {
            try {
                $hashResponse = $this->httpClient->request('GET', self::TRADING_BASE_URL . '/accounts/accountNumbers', [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'timeout' => (float) $this->appConfig->get('api.timeout.broker.default', 8.0),
                ]);
                if ($hashResponse->getStatusCode() === 200) {
                    foreach ($hashResponse->toArray() as $h) {
                        $accNo = (string) ($h['accountNumber'] ?? '');
                        if ($accNo !== '' && !empty($h['hashValue'])) {
                            $hashMap[$accNo] = $h['hashValue'];
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Schwab /accounts/accountNumbers error in history: ' . $e->getMessage());
            }
        }

        try {
            $response = $this->httpClient->request('GET', self::TRADING_BASE_URL . '/accounts', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => (float) $this->appConfig->get('api.timeout.broker.default', 8.0),
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $accounts = $response->toArray();
            if (empty($accounts)) {
                return [];
            }

            $startDate = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d\TH:i:s.000\Z');
            $endDate   = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.000\Z');
            $timeout   = (float) $this->appConfig->get('api.timeout.broker.transactions', 10.0);
            $txTypes   = 'TRADE,DIVIDEND_OR_INTEREST,JOURNAL';

            $allHistory = [];
            foreach ($accounts as $accountItem) {
                $acc = $accountItem['securitiesAccount'] ?? [];
                $accountNumber = (string) ($acc['accountNumber'] ?? '');
                if (!$accountNumber) {
                    continue;
                }

                $hashVal = $hashMap[$accountNumber] ?? $accountNumber;
                $nick = $nicknameMap[$accountNumber] ?? null;
                
                // Load master list of all known transactions for this account (indefinite storage)
                $masterCacheKey = 'b' . $this->id . '.tx_master.' . $accountNumber;
                $masterList = $this->cache->get($masterCacheKey) ?: [];
                $masterMap = [];
                foreach ($masterList as $txItem) {
                    if (isset($txItem['id'])) {
                        $masterMap[$txItem['id']] = $txItem;
                    }
                }

                // Query Schwab only if we have not fetched today, or it is a force pull
                if ($shouldFetchFromApi) {
                    try {
                        $txResponse = $this->httpClient->request('GET', self::TRADING_BASE_URL . "/accounts/{$hashVal}/transactions", [
                            'headers' => ['Authorization' => 'Bearer ' . $token],
                            'query'   => [
                                'startDate' => $startDate,
                                'endDate'   => $endDate,
                                'types'     => $txTypes,
                            ],
                            'timeout' => $timeout,
                        ]);

                        if ($txResponse->getStatusCode() === 200) {
                            $transactions = $txResponse->toArray();
                            $freshTxs = $this->sanitizeHistoryData($transactions, $accountNumber, $nick);
                            
                            // Merge fresh transactions into master list using activity ID deduplication
                            $hasNew = false;
                            foreach ($freshTxs as $txItem) {
                                $txId = $txItem['id'];
                                if (!isset($masterMap[$txId])) {
                                    $masterMap[$txId] = $txItem;
                                    $hasNew = true;
                                }
                            }
                            
                            if ($hasNew || empty($masterList)) {
                                $masterList = array_values($masterMap);
                                // Persist master transaction list indefinitely (1 year)
                                $this->cache->set($masterCacheKey, $masterList, 31536000, true);
                            }
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error("Schwab Tx Error ({$this->id} / {$accountNumber}): " . $e->getMessage());
                    }
                }

                // Filter master list to get transactions matching the requested date range
                $limitDate = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d');
                foreach ($masterMap as $txItem) {
                    if (($txItem['date'] ?? '') >= $limitDate) {
                        $allHistory[] = $txItem;
                    }
                }
            }

            // Update last fetched date mark if we queried Schwab
            if ($shouldFetchFromApi) {
                $this->cache->set($lastFetchKey, $todayStr, 86400);
            }

            // Sort all transactions descending by date
            usort($allHistory, function ($a, $b) {
                return strcmp($b['date'] ?? '', $a['date'] ?? '');
            });

            $this->cache->set($overallCacheKey, $allHistory, $cacheTtl, true);
            return $allHistory;
        } catch (\Throwable $e) {
            $this->logger->error('Schwab History Fetch Error (' . $this->id . '): ' . $e->getMessage());
            return [];
        }
    }

    public function getOpenOrders(bool $forceRefresh = false): array
    {
        $cacheKey = 'b' . $this->id . '.' . str_replace(' ', '_', strtolower($this->getNickname())) . '.open_orders';
        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return [];
        }

        try {
            $from = (new \DateTimeImmutable('-7 days'))->format('Y-m-d\TH:i:s.000\Z');
            $to = (new \DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i:s.000\Z');

            $response = $this->httpClient->request('GET', self::TRADING_BASE_URL . '/accounts/orders', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query' => [
                    'fromEnteredTime' => $from,
                    'toEnteredTime' => $to,
                ],
                'timeout' => (float) $this->appConfig->get('api.timeout.broker.default', 8.0),
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $orders = $response->toArray();
            $parsed = $this->sanitizeOrdersData($orders);
            $this->cache->set($cacheKey, $parsed, 30, true);
            return $parsed;
        } catch (\Throwable $e) {
            $this->logger->error('Schwab Orders Fetch Error (' . $this->id . '): ' . $e->getMessage());
            return [];
        }
    }

    private function sanitizeOrdersData(array $orders): array
    {
        $sanitized = [];
        foreach ($orders as $order) {
            $status = strtoupper($order['status'] ?? '');
            $isOpen = in_array($status, ['WORKING', 'PENDING_ACTIVATION', 'PENDING_REPLACE', 'PENDING_CANCEL', 'ACCEPTED', 'QUEUED']);
            if (!$isOpen) {
                continue;
            }

            $legs = [];
            foreach ($order['orderLegCollection'] ?? [] as $leg) {
                $inst = $leg['instrument'] ?? [];
                $legs[] = [
                    'symbol' => $inst['symbol'] ?? 'UNKNOWN',
                    'underlying' => $inst['underlyingSymbol'] ?? '',
                    'instruction' => $leg['instruction'] ?? '',
                    'quantity' => (float) ($leg['quantity'] ?? 0),
                    'asset_type' => $inst['assetType'] ?? 'EQUITY',
                ];
            }

            $accNo = (string) ($order['accountNumber'] ?? '');
            $maskedNum = $accNo !== '' ? ('***' . substr($accNo, -4)) : '';

            $sanitized[] = [
                'order_id' => $order['orderId'] ?? null,
                'status' => $status,
                'entered_time' => $order['enteredTime'] ?? null,
                'order_type' => $order['orderType'] ?? 'LIMIT',
                'price' => (float) ($order['price'] ?? 0.0),
                'quantity' => (float) ($order['quantity'] ?? 0),
                'filled_quantity' => (float) ($order['filledQuantity'] ?? 0),
                'account_number' => $maskedNum,
                'legs' => $legs,
            ];
        }
        return $sanitized;
    }

    public function getOptionChain(string $symbol, float $currentPrice): array
    {
        $symbol = strtoupper(trim($symbol));
        $cacheKey = 'b' . $this->id . '.' . str_replace(' ', '_', strtolower($this->getNickname())) . '.chain.' . $symbol;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['error' => 'Broker (' . $this->getNickname() . ') unauthorized for option chain'];
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . '/chains', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query'   => [
                    'symbol'        => $symbol,
                    'contractType'  => 'ALL',
                    'strikeCount'   => (int) $this->appConfig->get('broker.option_chain.strike_count', 12),
                    'includeUnderlyingQuote' => 'true',
                    'strategy'      => 'SINGLE',
                ],
                'timeout' => (float) $this->appConfig->get('api.timeout.broker.default', 8.0),
            ]);

            if ($response->getStatusCode() !== 200) {
                return ['error' => 'Option chain API HTTP ' . $response->getStatusCode()];
            }

            $data = $response->toArray();
            $chain = $this->parseOptionChainResponse($data, $symbol, $currentPrice);
            $this->cache->set($cacheKey, $chain, (int) $this->appConfig->get('cache.ttl.broker.chain', 120), false);
            return $chain;
        } catch (\Throwable $e) {
            $this->logger->error('Schwab Option Chain Error (' . $this->id . '): ' . $e->getMessage());
            return ['error' => 'Option chain fetch failed: ' . $e->getMessage()];
        }
    }

    private function sanitizePortfolioData(array $accounts, array $nicknameMap = []): array
    {
        if (empty($accounts)) {
            return [
                'account_number' => 'N/A',
                'balances' => ['cash' => 0.0, 'portfolio_value' => 0.0],
                'positions' => [],
                'accounts' => [],
            ];
        }

        $totalCash = 0.0;
        $totalLiquidationValue = 0.0;
        $allPositions = [];
        $accountList = [];
        $accountNumbers = [];

        foreach ($accounts as $accountItem) {
            $acc = $accountItem['securitiesAccount'] ?? [];
            if (empty($acc)) {
                continue;
            }

            $rawAccountNum = (string) ($acc['accountNumber'] ?? '');
            $maskedNum = '***' . substr($rawAccountNum, -4);
            $accountNumbers[] = $maskedNum;

            $balances = $acc['currentBalances'] ?? $acc['initialBalances'] ?? [];
            $cash = (float) ($balances['cashBalance'] ?? $balances['cashAvailableForTrading'] ?? 0.0);
            $liquidationValue = (float) ($balances['liquidationValue'] ?? 0.0);

            $totalCash += $cash;
            $totalLiquidationValue += $liquidationValue;

            $accPositions = [];
            $rawPositions = $acc['positions'] ?? [];
            foreach ($rawPositions as $pos) {
                $instrument = $pos['instrument'] ?? [];
                $assetType  = strtoupper($instrument['assetType'] ?? 'EQUITY');
                $symbol     = $instrument['symbol'] ?? 'UNKNOWN';

                $qty = (float) ($pos['longQuantity'] ?? 0);
                if ($qty == 0) {
                    $qty = -1 * (float) ($pos['shortQuantity'] ?? 0);
                }

                $mktVal   = (float) ($pos['marketValue'] ?? 0.0);
                $cost     = (float) ($pos['averagePrice'] ?? 0.0);
                $curPrice = $qty != 0 ? abs($mktVal / $qty) : 0.0;

                $posItem = [
                    'broker_id'         => $this->id,
                    'broker_nickname'   => $this->getNickname(),
                    'symbol'            => $symbol,
                    'asset_type'        => $assetType,
                    'assetType'         => $assetType,
                    'quantity'          => $qty,
                    'cost_basis'        => $cost,
                    'averagePrice'      => $cost,
                    'current_price'     => $curPrice,
                    'market_value'      => $mktVal,
                    'marketValue'       => $mktVal,
                    'unrealized_pl'     => $mktVal - ($cost * $qty),
                    'unrealizedPL'      => $mktVal - ($cost * $qty),
                    'unrealized_pl_pct' => ($cost * $qty) != 0 ? (($mktVal - ($cost * $qty)) / abs($cost * $qty)) * 100 : 0.0,
                    'unrealizedPLPct'   => ($cost * $qty) != 0 ? (($mktVal - ($cost * $qty)) / abs($cost * $qty)) * 100 : 0.0,
                ];

                $accPositions[] = $posItem;
                $allPositions[] = $posItem;
            }

            $prefNickname = $nicknameMap[$rawAccountNum] ?? null;
            $rawNickname = $prefNickname 
                ?? $acc['nickname'] 
                ?? $acc['nickName'] 
                ?? $acc['accountNickname'] 
                ?? $acc['accountName'] 
                ?? $acc['name'] 
                ?? $acc['description'] 
                ?? $acc['accountTitle'] 
                ?? $accountItem['nickname'] 
                ?? $accountItem['nickName'] 
                ?? $accountItem['accountNickname'] 
                ?? $accountItem['accountName'] 
                ?? $accountItem['name'] 
                ?? $accountItem['description'] 
                ?? null;

            $typeStr = !empty($acc['type']) ? ucfirst(strtolower($acc['type'])) : 'Account';
            if (!empty($rawNickname) && trim($rawNickname) !== '') {
                $nickname = trim($rawNickname);
            } else {
                $nickname = $this->getNickname() . ' ' . $typeStr . ' (' . $maskedNum . ')';
            }

            $accountList[] = [
                'accountNumber'          => $maskedNum,
                'nickname'               => $nickname,
                'type'                   => $acc['type'] ?? 'MARGIN',
                'liquidationValue'       => $liquidationValue,
                'cashAvailable'          => $cash,
                'positions'              => $accPositions,
            ];
        }

        $maskedAccountNum = !empty($accountNumbers) ? implode(', ', array_unique($accountNumbers)) : 'N/A';

        return [
            'broker_id'       => $this->id,
            'broker_nickname' => $this->getNickname(),
            'account_number'  => $maskedAccountNum,
            'balances'        => [
                'cash'            => $totalCash,
                'portfolio_value' => $totalLiquidationValue,
            ],
            'positions'       => $allPositions,
            'accounts'        => $accountList,
        ];
    }

    private function sanitizeHistoryData(array $transactions, string $accountNumber = '', ?string $accountNickname = null): array
    {
        $sanitized = [];
        $maskedNum = $accountNumber !== '' ? ('***' . substr($accountNumber, -4)) : '';
        foreach ($transactions as $tx) {
            $rawDate = $tx['tradeDate'] ?? $tx['settlementDate'] ?? date('Y-m-d');
            
            // Extract transfer items details and calculate total fees
            $items = [];
            $totalFees = 0.0;
            foreach ($tx['transferItems'] ?? [] as $item) {
                $inst = $item['instrument'] ?? [];
                $feeType = $item['feeType'] ?? null;
                $itemAmt = (float) ($item['amount'] ?? 0.0);
                
                if ($feeType) {
                    $totalFees += abs($itemAmt);
                }
                
                $items[] = [
                    'asset_type'      => $inst['assetType'] ?? null,
                    'symbol'          => $inst['symbol'] ?? null,
                    'description'     => $inst['description'] ?? null,
                    'amount'          => $itemAmt,
                    'cost'            => (float) ($item['cost'] ?? 0.0),
                    'price'           => (float) ($item['price'] ?? 0.0),
                    'position_effect' => $item['positionEffect'] ?? null,
                    'fee_type'        => $feeType,
                ];
            }

            $symbol = $tx['transferItems'][0]['instrument']['symbol'] ?? null;
            $description = $tx['description'] ?? '';
            if ($symbol === 'CURRENCY_USD' && !empty($description)) {
                $divSym = $this->extractSymbolFromDescription($description);
                if ($divSym !== null) {
                    $symbol = $divSym;
                }
            }

            $sanitized[] = [
                'broker_id'       => $this->id,
                'broker_nickname' => $this->getNickname(),
                'account_number'  => $maskedNum,
                'account_nickname'=> $accountNickname ?: ($maskedNum ? $this->getNickname() . ' (' . $maskedNum . ')' : $this->getNickname()),
                'id'              => $tx['activityId'] ?? bin2hex(random_bytes(6)),
                'time'            => $tx['time'] ?? null,
                'date'            => substr(trim((string)$rawDate), 0, 10),
                'settlement_date' => isset($tx['settlementDate']) ? substr(trim($tx['settlementDate']), 0, 10) : null,
                'type'            => $tx['type'] ?? 'TRADE',
                'status'          => $tx['status'] ?? null,
                'sub_account'     => $tx['subAccount'] ?? null,
                'description'     => $description,
                'amount'          => (float) ($tx['netAmount'] ?? 0.0),
                'position_id'     => $tx['positionId'] ?? null,
                'order_id'        => $tx['orderId'] ?? null,
                'fees'            => $totalFees,
                'transfer_items'  => $items,
                'symbol'          => $symbol,
            ];
        }
        return $sanitized;
    }

    private function parseOptionChainResponse(array $data, string $symbol, float $currentPrice): array
    {
        $calls = [];
        $puts  = [];

        $callMap = $data['callExpDateMap'] ?? [];
        foreach ($callMap as $expDate => $strikes) {
            foreach ($strikes as $strikeStr => $contracts) {
                foreach ($contracts as $c) {
                    $calls[] = $this->formatOptionContract($c, 'CALL');
                }
            }
        }

        $putMap = $data['putExpDateMap'] ?? [];
        foreach ($putMap as $expDate => $strikes) {
            foreach ($strikes as $strikeStr => $contracts) {
                foreach ($contracts as $c) {
                    $puts[] = $this->formatOptionContract($c, 'PUT');
                }
            }
        }

        return [
            'broker_id'     => $this->id,
            'symbol'        => $symbol,
            'underlyingPrice' => (float) ($data['underlyingPrice'] ?? $currentPrice),
            'calls'         => $calls,
            'puts'          => $puts,
        ];
    }

    private function extractSymbolFromDescription(string $desc): ?string
    {
        $descUpper = strtoupper(trim($desc));
        if (empty($descUpper)) {
            return null;
        }

        // Hardcoded map for popular companies
        $lookupMap = [
            'AMERICAN EXPRESS' => 'AXP',
            'MASTERCARD'       => 'MA',
            'GENERAL DYNAMICS' => 'GD',
            'NETAPP'           => 'NTAP',
            'ORACLE'           => 'ORCL',
            'SCIENCE APPL'     => 'SAIC',
            'CISCO SYS'        => 'CSCO',
            'INTUIT'           => 'INTU',
            'KBR'              => 'KBR',
            'HEICO'            => 'HEI',
            'HEWLETT PACKARD'  => 'HPE',
            'AUTOHOME'         => 'ATHM',
            'ALIBABA'          => 'BABA',
            'NVIDIA'           => 'NVDA',
            'APPLE'            => 'AAPL',
            'MICROSOFT'        => 'MSFT',
            'ALPHABET'         => 'GOOGL',
            'GOOGLE'           => 'GOOGL',
            'AMAZON'           => 'AMZN',
            'META PLATFORMS'   => 'META',
            'BROADCOM'         => 'AVGO',
            'JPMORGAN'         => 'JPM',
            'TESLA'            => 'TSLA',
            'QUALCOMM'         => 'QCOM',
        ];

        foreach ($lookupMap as $companyName => $ticker) {
            if (str_contains($descUpper, $companyName)) {
                return $ticker;
            }
        }

        // Fallback: match standard uppercase ticker word
        if (preg_match_all('/\b[A-Z]{1,5}\b/', $descUpper, $matches)) {
            foreach ($matches[0] as $word) {
                if (in_array($word, ['CO', 'INC', 'LTD', 'CORP', 'CLASS', 'COM', 'DIV', 'USD', 'COMCLASS', 'NEW', 'FUNDS', 'TRF', 'FROM', 'TYPE', 'TO'])) {
                    continue;
                }
                return $word;
            }
        }

        return null;
    }

    private function formatOptionContract(array $c, string $type): array
    {
        return [
            'symbol'         => $c['symbol'] ?? '',
            'type'           => $type,
            'strike'         => (float) ($c['strikePrice'] ?? 0.0),
            'bid'            => (float) ($c['bid'] ?? 0.0),
            'ask'            => (float) ($c['ask'] ?? 0.0),
            'last'           => (float) ($c['last'] ?? 0.0),
            'volume'         => (int) ($c['totalVolume'] ?? 0),
            'openInterest'   => (int) ($c['openInterest'] ?? 0),
            'impliedVolatility' => (float) ($c['volatility'] ?? 0.0),
            'delta'          => (float) ($c['delta'] ?? 0.0),
            'expirationDate' => $c['expirationDate'] ?? '',
            'daysToExpiration' => (int) ($c['daysToExpiration'] ?? 0),
            'inTheMoney'     => (bool) ($c['inTheMoney'] ?? false),
        ];
    }
}
