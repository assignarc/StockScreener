<?php

namespace App\Broker;

/**
 * Interface BrokerInterface
 *
 * Defines the standard polymorphic contract for brokerage adapters within the StockScreener
 * application. Implementations encapsulate authentication, portfolio balance queries,
 * historical ledger retrieval, order tracking, and option chain ingestion.
 *
 * Design Reference: doc/broker-integrations.md
 */
interface BrokerInterface
{
    /**
     * Get unique identifier for this broker instance.
     *
     * @return string Unique broker configuration ID.
     */
    public function getId(): string;

    /**
     * Get broker type slug (e.g., 'schwab', 'alpaca', 'ibkr').
     *
     * @return string Broker provider slug.
     */
    public function getType(): string;

    /**
     * Get human-readable nickname for this broker account.
     *
     * @return string Account nickname or fallback name.
     */
    public function getNickname(): string;

    /**
     * Determine whether required API credentials (keys, secrets, client IDs) are present.
     *
     * @return bool True if configured with valid credentials.
     */
    public function isConfigured(): bool;

    /**
     * Determine whether active authorization or a valid access token exists.
     *
     * @return bool True if authorized to execute API queries.
     */
    public function isAuthorized(): bool;

    /**
     * Check if live trading operations are permitted by environment configuration.
     *
     * @return bool True if TRADING_ENABLED=true in the runtime environment.
     */
    public function isTradingEnabled(): bool;

    /**
     * Fetch normalized account portfolio balances, total liquidation values, and active positions.
     *
     * @return array Standardized portfolio associative array with balances and positions.
     */
    public function getAccountPortfolio(): array;

    /**
     * Fetch historical trade settlement records, dividend distributions, and cash transfers.
     *
     * @param int $days Number of historical calendar days to query.
     * @param bool $forceRefresh When true, bypasses daily cache gates to query the external API.
     * @return array List of normalized transaction records sorted chronologically.
     */
    public function getAccountHistory(int $days = 30, bool $forceRefresh = false): array;

    /**
     * Fetch historical order entries and execution logs.
     *
     * @param int $days Number of historical calendar days to query.
     * @param bool $forceRefresh When true, bypasses cache to query the external API.
     * @return array List of normalized order records.
     */
    public function getOrderHistory(int $days = 30, bool $forceRefresh = false): array;

    /**
     * Fetch currently active or working orders pending execution.
     *
     * @param bool $forceRefresh When true, forces a fresh query to the broker API.
     * @return array List of sanitized open order records.
     */
    public function getOpenOrders(bool $forceRefresh = false): array;

    /**
     * Fetch the complete option chain for an underlying equity ticker.
     *
     * @param string $symbol Underlying equity ticker symbol.
     * @param float $currentPrice Current market price of the underlying equity.
     * @return array Associative array containing call and put option contracts.
     */
    public function getOptionChain(string $symbol, float $currentPrice): array;

    /**
     * Purge all cached authentication tokens and local caches for this broker instance.
     *
     * @return bool True on successful token eviction.
     */
    public function purgeTokens(): bool;

    /**
     * Renew the short-lived access token using a stored refresh token.
     *
     * @return string|null Active access token string, or null on failure.
     */
    public function refreshAccessToken(): ?string;

    /**
     * Retrieve the current valid access token, auto-refreshing if near expiration.
     *
     * @return string|null Valid access token or null if unauthenticated.
     */
    public function getAccessToken(): ?string;

    /**
     * Generate OAuth 2.0 authorization URL for user redirection during initial setup.
     *
     * @param string $redirectUri Registered OAuth callback URL.
     * @param string|null $state Optional CSRF protection state parameter.
     * @return string|null Full authorization URL, or null if non-OAuth broker.
     */
    public function getAuthUrl(string $redirectUri, ?string $state = null): ?string;

    /**
     * Exchange an authorization code for access and refresh token credentials.
     *
     * @param string $code OAuth authorization code returned by provider redirect.
     * @param string $redirectUri Registered OAuth callback URL.
     * @return array Associative response array containing token payload or error message.
     */
    public function exchangeAuthCode(string $code, string $redirectUri): array;
}
