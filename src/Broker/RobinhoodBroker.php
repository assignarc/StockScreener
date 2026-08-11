<?php

namespace App\Broker;

use App\Service\AppConfigService;
use App\Service\PersistentCacheService;
use Psr\Log\LoggerInterface;

class RobinhoodBroker implements BrokerInterface
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
        return 'robinhood';
    }

    public function getNickname(): string
    {
        return $this->nickname ?: 'Robinhood Account (' . $this->id . ')';
    }

    public function isConfigured(): bool
    {
        return !empty($this->appKey);
    }

    public function isAuthorized(): bool
    {
        return !empty($this->appKey);
    }

    public function isTradingEnabled(): bool
    {
        $flag = $_ENV['TRADING_ENABLED'] ?? false;
        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    public function purgeTokens(): bool
    {
        $this->appConfig->set('broker.' . $this->id . '.credentials', null);
        $this->cache->purgeByPrefix('broker.' . $this->id . '.');
        return true;
    }

    public function refreshAccessToken(): ?string
    {
        return $this->appKey;
    }

    public function getAccessToken(): ?string
    {
        return $this->appKey;
    }

    public function getAuthUrl(string $redirectUri, ?string $state = null): ?string
    {
        return null; // Robinhood uses API Key / Token authentication
    }

    public function exchangeAuthCode(string $code, string $redirectUri): array
    {
        return ['error' => 'Robinhood uses API token authentication instead of OAuth code exchange.'];
    }

    public function getAccountPortfolio(): array
    {
        $cacheKey = 'b' . $this->id . '.' . str_replace(' ', '_', strtolower($this->getNickname())) . '.portfolio';
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        if (!$this->isConfigured()) {
            return [
                'error'          => 'Robinhood broker (' . $this->getNickname() . ') API key not configured.',
                'account_number' => 'UNCONFIGURED',
                'balances'       => ['cash' => 0.0, 'portfolio_value' => 0.0],
                'positions'      => [],
            ];
        }

        // Standardized response format for Robinhood / Generic integrations
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

    public function getAccountHistory(int $days = 30, bool $forceRefresh = false): array
    {
        return [];
    }

    public function getOpenOrders(bool $forceRefresh = false): array
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
