<?php

namespace App\Broker;

use App\Service\AppConfigService;
use App\Service\PersistentCacheService;
use Psr\Log\LoggerInterface;

class IbkrBroker implements BrokerInterface
{
    public function __construct(
        private string $id,
        private string $nickname,
        private ?string $appKey,
        private ?string $appSecret,
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
        return 'ibkr';
    }

    public function getNickname(): string
    {
        return $this->nickname ?: 'Interactive Brokers (' . $this->id . ')';
    }

    public function isConfigured(): bool
    {
        return true; // Client Portal Gateway default localhost:5000
    }

    public function isAuthorized(): bool
    {
        return true;
    }

    public function isTradingEnabled(): bool
    {
        $flag = $_ENV['TRADING_ENABLED'] ?? false;
        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    public function purgeTokens(): bool
    {
        $this->cache->purgeByPrefix('broker.' . $this->id . '.');
        return true;
    }

    public function refreshAccessToken(): ?string
    {
        return 'ibkr_gateway_session';
    }

    public function getAccessToken(): ?string
    {
        return 'ibkr_gateway_session';
    }

    public function getAuthUrl(string $redirectUri, ?string $state = null): ?string
    {
        return 'https://localhost:5000';
    }

    public function exchangeAuthCode(string $code, string $redirectUri): array
    {
        return ['status' => 'success', 'message' => 'IBKR Gateway authenticated'];
    }

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

    public function getAccountHistory(int $days = 30): array
    {
        return [];
    }

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
