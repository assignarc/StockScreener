<?php

namespace App\Broker;

use App\Service\AppConfigService;
use App\Service\PersistentCacheService;
use Psr\Log\LoggerInterface;

/**
 * Class TastytradeBroker
 *
 * Implements BrokerInterface for Tastytrade Open API.
 * Provides interface contracts and portfolio stubs for Tastytrade session-based integration.
 *
 * Design Reference: doc/broker-integrations.md
 */
class TastytradeBroker implements BrokerInterface
{
    /**
     * @param string $id Unique broker instance identifier.
     * @param string $nickname Human-readable display nickname.
     * @param string|null $appKey Tastytrade Session Token or Client Key.
     * @param string|null $appSecret Optional secret parameter.
     * @param LoggerInterface $logger Application logger.
     * @param PersistentCacheService $cache Persistent caching service.
     * @param AppConfigService $appConfig Configuration service.
     */
    public function __construct(
        private string $id,
        private string $nickname,
        private ?string $appKey,
        private ?string $appSecret,
        private LoggerInterface $logger,
        private PersistentCacheService $cache,
        private AppConfigService $appConfig
    ) {}

    /**
     * Get unique identifier for this broker instance.
     *
     * @return string Unique broker configuration ID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get broker provider slug.
     *
     * @return string Always 'tastytrade'.
     */
    public function getType(): string
    {
        return 'tastytrade';
    }

    /**
     * Get human-readable nickname for this broker account.
     *
     * @return string Account nickname or fallback name.
     */
    public function getNickname(): string
    {
        return $this->nickname ?: 'Tastytrade Account (' . $this->id . ')';
    }

    /**
     * Check if required API credentials are configured.
     *
     * @return bool True if session token or API key is provided.
     */
    public function isConfigured(): bool
    {
        return !empty($this->appKey);
    }

    /**
     * Check if authorized.
     *
     * @return bool True if session token or API key is provided.
     */
    public function isAuthorized(): bool
    {
        return !empty($this->appKey);
    }

    /**
     * Check if live trading operations are permitted in the environment.
     *
     * @return bool True if TRADING_ENABLED=true in .env.
     */
    public function isTradingEnabled(): bool
    {
        $flag = $_ENV['TRADING_ENABLED'] ?? false;
        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Purge stored credentials and local caches for this broker.
     *
     * @return bool True on success.
     */
    public function purgeTokens(): bool
    {
        $this->appConfig->set('broker.' . $this->id . '.credentials', null);
        $this->cache->purgeByPrefix('broker.' . $this->id . '.');
        return true;
    }

    /**
     * Refresh access token (returns active session token).
     *
     * @return string|null Active session token.
     */
    public function refreshAccessToken(): ?string
    {
        return $this->appKey;
    }

    /**
     * Retrieve active session token.
     *
     * @return string|null Active session token.
     */
    public function getAccessToken(): ?string
    {
        return $this->appKey;
    }

    /**
     * Get OAuth authorization URL (not applicable for Tastytrade session auth).
     *
     * @param string $redirectUri Registered callback URI.
     * @param string|null $state Optional state parameter.
     * @return string|null Always null for Tastytrade.
     */
    public function getAuthUrl(string $redirectUri, ?string $state = null): ?string
    {
        return null;
    }

    /**
     * Exchange auth code (not applicable for Tastytrade).
     *
     * @param string $code Auth code.
     * @param string $redirectUri Registered callback URI.
     * @return array Status array.
     */
    public function exchangeAuthCode(string $code, string $redirectUri): array
    {
        return ['error' => 'Tastytrade uses Session Token authentication.'];
    }

    /**
     * Fetch normalized account portfolio.
     *
     * @return array Standardized portfolio associative array.
     */
    public function getAccountPortfolio(): array
    {
        $cacheKey = 'b' . $this->id . '.' . str_replace(' ', '_', strtolower($this->getNickname())) . '.portfolio';
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $portfolio = [
            'broker_id'       => $this->id,
            'broker_nickname' => $this->getNickname(),
            'account_number'  => '***' . substr(md5($this->id), 0, 4),
            'balances'        => [
                'cash'            => 0.0,
                'portfolio_value' => 0.0,
            ],
            'positions'       => [],
        ];

        $this->cache->set($cacheKey, $portfolio, 60, true);
        return $portfolio;
    }

    /**
     * Fetch historical trade settlement records.
     *
     * @param int $days Historical window in calendar days.
     * @param bool $forceRefresh When true, bypasses cache.
     * @return array List of normalized transaction records.
     */
    public function getAccountHistory(int $days = 30, bool $forceRefresh = false): array
    {
        return [];
    }

    /**
     * Fetch historical order entries.
     *
     * @param int $days Historical window in calendar days.
     * @param bool $forceRefresh When true, bypasses cache.
     * @return array List of normalized order records.
     */
    public function getOrderHistory(int $days = 30, bool $forceRefresh = false): array
    {
        return [];
    }

    /**
     * Fetch active open orders.
     *
     * @param bool $forceRefresh When true, bypasses cache.
     * @return array List of open order records.
     */
    public function getOpenOrders(bool $forceRefresh = false): array
    {
        return [];
    }

    /**
     * Fetch option chain for equity symbol.
     *
     * @param string $symbol Underlying equity ticker symbol.
     * @param float $currentPrice Current market price.
     * @return array Standardized option chain array.
     */
    public function getOptionChain(string $symbol, float $currentPrice): array
    {
        return [
            'broker_id'      => $this->id,
            'symbol'         => strtoupper($symbol),
            'underlyingPrice'=> $currentPrice,
            'calls'          => [],
            'puts'           => [],
        ];
    }
}
