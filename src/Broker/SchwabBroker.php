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
            $response = $this->httpClient->request('GET', self::TRADING_BASE_URL . '/accounts', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query'   => ['fields' => 'positions'],
                'timeout' => 8.0,
            ]);

            if ($response->getStatusCode() !== 200) {
                return ['error' => 'API returned HTTP ' . $response->getStatusCode()];
            }

            $accounts = $response->toArray();
            $portfolio = $this->sanitizePortfolioData($accounts);
            $this->cache->set($cacheKey, $portfolio, 60, true);
            return $portfolio;
        } catch (\Throwable $e) {
            $this->logger->error('Schwab Portfolio Fetch Error (' . $this->id . '): ' . $e->getMessage());
            return ['error' => 'Failed fetching portfolio: ' . $e->getMessage()];
        }
    }

    public function getAccountHistory(int $days = 30): array
    {
        $cacheKey = 'b' . $this->id . '.' . str_replace(' ', '_', strtolower($this->getNickname())) . '.history.' . $days;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', self::TRADING_BASE_URL . '/accounts', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => 8.0,
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $accounts = $response->toArray();
            $accountNumber = $accounts[0]['securitiesAccount']['accountNumber'] ?? null;
            if (!$accountNumber) {
                return [];
            }

            $startDate = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d\TH:i:s.000\Z');
            $endDate   = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.000\Z');

            $txResponse = $this->httpClient->request('GET', self::TRADING_BASE_URL . "/accounts/{$accountNumber}/transactions", [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query'   => ['startDate' => $startDate, 'endDate' => $endDate],
                'timeout' => 10.0,
            ]);

            if ($txResponse->getStatusCode() !== 200) {
                return [];
            }

            $transactions = $txResponse->toArray();
            $history = $this->sanitizeHistoryData($transactions);
            $this->cache->set($cacheKey, $history, 300, true);
            return $history;
        } catch (\Throwable $e) {
            $this->logger->error('Schwab History Fetch Error (' . $this->id . '): ' . $e->getMessage());
            return [];
        }
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
                    'strikeCount'   => 12,
                    'includeUnderlyingQuote' => 'true',
                    'strategy'      => 'SINGLE',
                ],
                'timeout' => 8.0,
            ]);

            if ($response->getStatusCode() !== 200) {
                return ['error' => 'Option chain API HTTP ' . $response->getStatusCode()];
            }

            $data = $response->toArray();
            $chain = $this->parseOptionChainResponse($data, $symbol, $currentPrice);
            $this->cache->set($cacheKey, $chain, 120, false);
            return $chain;
        } catch (\Throwable $e) {
            $this->logger->error('Schwab Option Chain Error (' . $this->id . '): ' . $e->getMessage());
            return ['error' => 'Option chain fetch failed: ' . $e->getMessage()];
        }
    }

    private function sanitizePortfolioData(array $accounts): array
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

            $accountList[] = [
                'accountNumber'          => $maskedNum,
                'nickname'               => $this->getNickname() . ' (' . $maskedNum . ')',
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

    private function sanitizeHistoryData(array $transactions): array
    {
        $sanitized = [];
        foreach ($transactions as $tx) {
            $sanitized[] = [
                'broker_id'   => $this->id,
                'id'          => $tx['activityId'] ?? bin2hex(random_bytes(6)),
                'date'        => $tx['tradeDate'] ?? $tx['settlementDate'] ?? date('Y-m-d'),
                'type'        => $tx['type'] ?? 'TRADE',
                'description' => $tx['description'] ?? '',
                'amount'      => (float) ($tx['netAmount'] ?? 0.0),
                'symbol'      => $tx['transferItems'][0]['instrument']['symbol'] ?? null,
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
