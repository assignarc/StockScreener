<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class FinnhubService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private PersistentCacheService $cache,
        private AppConfigService $appConfig,
        private ?string $finnhubApiKey = null,
    ) {}

    public function getEffectiveApiKey(?string $apiKey = null): ?string
    {
        return $apiKey ?: ($this->appConfig->getFinnhubApiKey() ?: $this->finnhubApiKey);
    }

    public function getQuote(string $symbol, ?string $apiKey = null, bool $forceRefresh = false): ?array
    {
        $symbol = strtoupper($symbol);
        $key = $this->getEffectiveApiKey($apiKey);
        if (!$key) {
            return null;
        }

        $cacheKey = "finnhub.quote.{$symbol}";
        if ($forceRefresh) {
            $this->cache->delete($cacheKey);
        }

        return $this->cache->get($cacheKey, function() use ($symbol, $key) {
            try {
                $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/quote', [
                    'query' => [
                        'symbol' => $symbol,
                        'token'  => $key,
                    ],
                    'timeout' => (float) $this->appConfig->get('api.timeout.finnhub.default', 3.0),
                    'max_duration' => (float) $this->appConfig->get('api.timeout.finnhub.default', 3.0) * 2.0,
                ]);

                if ($response->getStatusCode() === 200) {
                    $data = $response->toArray();
                    if (isset($data['c']) && $data['c'] > 0) {
                        return [
                            'c'  => $data['c'],  // Current price
                            'd'  => $data['d'],  // Change
                            'dp' => $data['dp'], // Percent change
                            'h'  => $data['h'],  // High
                            'l'  => $data['l'],  // Low
                            'o'  => $data['o'],  // Open
                            'pc' => $data['pc'], // Previous close
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error("Finnhub API error for symbol {$symbol}: " . $e->getMessage());
            }

            return null;
        }, (int) $this->appConfig->get('cache.ttl.finnhub.quote', 300)); // configurable TTL
    }

    /**
     * Parallel Asynchronous Batch Quote Retrieval via Symfony HttpClient streaming
     */
    public function getBatchQuotes(array $symbols, ?string $apiKey = null, bool $forceRefresh = false): array
    {
        $key = $this->getEffectiveApiKey($apiKey);
        $results = [];
        $pendingResponses = [];

        foreach ($symbols as $sym) {
            $symbol = strtoupper(trim($sym));
            if (empty($symbol)) {
                continue;
            }

            $cacheKey = "finnhub.quote.{$symbol}";
            if ($forceRefresh) {
                $this->cache->delete($cacheKey);
            }

            // Check cache first (0ms latency hit)
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $results[$symbol] = $cached;
                continue;
            }

            if ($key) {
                // Dispatch parallel non-blocking async HTTP request
                $pendingResponses[$symbol] = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/quote', [
                    'query' => [
                        'symbol' => $symbol,
                        'token'  => $key,
                    ],
                    'timeout' => 2.5,
                    'max_duration' => 5.0,
                ]);
            }
        }

        // Resolve pending parallel responses
        foreach ($pendingResponses as $symbol => $response) {
            try {
                if ($response->getStatusCode() === 200) {
                    $data = $response->toArray();
                    if (isset($data['c']) && $data['c'] > 0) {
                        $quote = [
                            'c'  => $data['c'],
                            'd'  => $data['d'],
                            'dp' => $data['dp'],
                            'h'  => $data['h'],
                            'l'  => $data['l'],
                            'o'  => $data['o'],
                            'pc' => $data['pc'],
                        ];
                        $this->cache->set("finnhub.quote.{$symbol}", $quote, 300);
                        $results[$symbol] = $quote;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Async batch quote error for {$symbol}: " . $e->getMessage());
            }
        }

        return $results;
    }

    public function getCompanyProfile(string $symbol, ?string $apiKey = null, bool $forceRefresh = false): ?array
    {
        $symbol = strtoupper($symbol);
        $key = $this->getEffectiveApiKey($apiKey);
        if (!$key) {
            return null;
        }

        $cacheKey = "finnhub.profile.{$symbol}";
        if ($forceRefresh) {
            $this->cache->delete($cacheKey);
        }

        return $this->cache->get($cacheKey, function() use ($symbol, $key) {
            try {
                $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/stock/profile2', [
                    'query' => [
                        'symbol' => $symbol,
                        'token'  => $key,
                    ],
                    'timeout' => 3.0,
                    'max_duration' => 6.0,
                ]);

                if ($response->getStatusCode() === 200) {
                    return $response->toArray();
                }
            } catch (\Throwable $e) {
                $this->logger->error("Finnhub Company Profile error for {$symbol}: " . $e->getMessage());
            }

            return null;
        }, 604800); // 7 days TTL
    }

    /**
     * Fetches Earnings Calendar from Finnhub API for specific symbol or date range
     */
    public function getEarningsCalendar(string $fromDate, string $toDate, ?string $symbol = null, bool $forceRefresh = false): array
    {
        $key = $this->getEffectiveApiKey();
        if (!$key) {
            return [];
        }

        $symbolStr = $symbol ? strtoupper($symbol) : 'ALL';
        $cacheKey  = "finnhub.earnings.{$fromDate}.{$toDate}.{$symbolStr}";
        if ($forceRefresh) {
            $this->cache->delete($cacheKey);
        }

        return $this->cache->get($cacheKey, function() use ($fromDate, $toDate, $symbol, $key) {
            try {
                $query = [
                    'from'  => $fromDate,
                    'to'    => $toDate,
                    'token' => $key,
                ];
                if ($symbol) {
                    $query['symbol'] = strtoupper($symbol);
                }

                $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/calendar/earnings', [
                    'query' => $query,
                    'timeout' => (float) $this->appConfig->get('api.timeout.finnhub.default', 3.0) + 0.5,
                    'max_duration' => ((float) $this->appConfig->get('api.timeout.finnhub.default', 3.0) + 0.5) * 2.0,
                ]);

                if ($response->getStatusCode() === 200) {
                    $data = $response->toArray();
                    return $data['earningsCalendar'] ?? [];
                }
            } catch (\Throwable $e) {
                $this->logger->error("Finnhub Earnings Calendar API error: " . $e->getMessage());
            }

            return [];
        }, (int) $this->appConfig->get('cache.ttl.finnhub.earnings', 86400)) ?? []; // configurable TTL
    }

    /**
     * Fetches Dividend history and upcoming dividend payouts from Finnhub API
     */
    public function getDividends(string $symbol, bool $forceRefresh = false): array
    {
        $symbol = strtoupper($symbol);
        $key = $this->getEffectiveApiKey();
        $cacheKey = "finnhub.divs.{$symbol}";
        if ($forceRefresh) {
            $this->cache->delete($cacheKey);
        }

        return $this->cache->get($cacheKey, function() use ($symbol, $key) {
            if (!$key) {
                return [];
            }

            try {
                $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/stock/dividend2', [
                    'query' => [
                        'symbol' => $symbol,
                        'token'  => $key,
                    ],
                    'timeout' => (float) $this->appConfig->get('api.timeout.finnhub.default', 3.0),
                    'max_duration' => (float) $this->appConfig->get('api.timeout.finnhub.default', 3.0) * 2.0,
                ]);

                if ($response->getStatusCode() === 200) {
                    $data = $response->toArray();
                    $divs = $data['data'] ?? [];
                    if (!empty($divs)) {
                        return $divs;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Finnhub Dividends API error for {$symbol}: " . $e->getMessage());
            }

            return [];
        }, (int) $this->appConfig->get('cache.ttl.finnhub.dividends', 86400)) ?? []; // configurable TTL
    }
}
