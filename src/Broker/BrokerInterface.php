<?php

namespace App\Broker;

interface BrokerInterface
{
    public function getId(): string;
    public function getType(): string;
    public function getNickname(): string;
    public function isConfigured(): bool;
    public function isAuthorized(): bool;
    public function isTradingEnabled(): bool;
    public function getAccountPortfolio(): array;
    public function getAccountHistory(int $days = 30, bool $forceRefresh = false): array;
    public function getOptionChain(string $symbol, float $currentPrice): array;
    public function purgeTokens(): bool;
    public function refreshAccessToken(): ?string;
    public function getAccessToken(): ?string;

    // OAuth specific methods (returns null if non-OAuth broker)
    public function getAuthUrl(string $redirectUri, ?string $state = null): ?string;
    public function exchangeAuthCode(string $code, string $redirectUri): array;
}
