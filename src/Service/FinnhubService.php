<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Class FinnhubService
 *
 * Ingests financial market data, live equity quotes, corporate dividend calendars, earnings release dates,
 * general macroeconomic news, CUSIP ticker resolutions, and stock split histories from Finnhub API.
 * Uses persistent caching to protect API rate-limit quotas.
 *
 * Design Reference: doc/broker-integrations.md
 */
class FinnhubService
{
    /**
     * @param HttpClientInterface $httpClient External HTTP transport client.
     * @param LoggerInterface $logger Application logger.
     * @param PersistentCacheService $cache Persistent caching service.
     * @param AppConfigService $appConfig Application configuration service.
     * @param string|null $finnhubApiKey Optional environment fallback API key.
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private PersistentCacheService $cache,
        private AppConfigService $appConfig,
        private ?string $finnhubApiKey = null,
    ) {}

    /**
     * Resolve effective Finnhub API key from parameter, SQLite config, or environment.
     *
     * @param string|null $apiKey Optional explicit API key override.
     * @return string|null Resolved API key or null.
     */
    public function getEffectiveApiKey(?string $apiKey = null): ?string
    {
        return $apiKey ?: ($this->appConfig->getFinnhubApiKey() ?: $this->finnhubApiKey);
    }

    /**
     * Checks whether US Equity Markets (NYSE / NASDAQ) are currently open.
     * Regular market trading hours: Monday - Friday, 09:30 - 16:00 US Eastern Time (ET).
     *
     * @return bool True if US markets are currently in active trading session.
     */
    public function isUsMarketOpen(): bool
    {
        try {
            $nyTime = new \DateTimeImmutable('now', new \DateTimeZone('America/New_York'));
            $dayOfWeek = (int) $nyTime->format('N'); // 1 (Mon) to 7 (Sun)
            if ($dayOfWeek > 5) {
                return false; // Weekend
            }

            $timeMinutes = ((int) $nyTime->format('H') * 60) + (int) $nyTime->format('i');
            // 09:30 is 570 mins, 16:00 is 960 mins
            return $timeMinutes >= 570 && $timeMinutes <= 960;
        } catch (\Throwable $e) {
            return true; // Fallback to allowing calls if timezone parsing fails
        }
    }

    /**
     * Fetch real-time price quote for a ticker symbol.
     * Live quotes are requested during US market hours and cached for 15 minutes.
     * Outside market hours, cached or stale quote data is served to preserve API quota.
     *
     * @param string $symbol Equity ticker symbol.
     * @param string|null $apiKey Optional API key.
     * @param bool $forceRefresh When true, bypasses cache to execute live request.
     * @return array|null Normalized quote dictionary or null.
     */
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

        $quoteTtl = (int) $this->appConfig->get('cache.ttl.finnhub.quote', 900);

        // If outside market hours and not an explicit force refresh, serve cached or stale quote without hitting live API
        if (!$this->isUsMarketOpen() && !$forceRefresh) {
            $stale = $this->cache->getStale($cacheKey);
            if ($stale !== null) {
                return $stale;
            }
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
        }, $quoteTtl);
    }

    /**
     * Parallel non-blocking batch quote retrieval via HTTP client streaming.
     * Live quotes are requested during market hours and cached for 15 minutes.
     *
     * @param array $symbols List of ticker symbols.
     * @param string|null $apiKey Optional API key.
     * @param bool $forceRefresh When true, bypasses cache.
     * @return array Map of symbol to quote data arrays.
     */
    public function getBatchQuotes(array $symbols, ?string $apiKey = null, bool $forceRefresh = false): array
    {
        $key = $this->getEffectiveApiKey($apiKey);
        $results = [];
        $pendingResponses = [];
        $quoteTtl = (int) $this->appConfig->get('cache.ttl.finnhub.quote', 900);
        $marketOpen = $this->isUsMarketOpen();

        foreach ($symbols as $sym) {
            $symbol = strtoupper(trim($sym));
            if (empty($symbol)) {
                continue;
            }

            $cacheKey = "finnhub.quote.{$symbol}";
            if ($forceRefresh) {
                $this->cache->delete($cacheKey);
            }

            // Check cache first
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $results[$symbol] = $cached;
                continue;
            }

            // Outside market hours, fallback to stale cached quote if available
            if (!$marketOpen && !$forceRefresh) {
                $stale = $this->cache->getStale($cacheKey);
                if ($stale !== null) {
                    $results[$symbol] = $stale;
                    continue;
                }
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
                        $this->cache->set("finnhub.quote.{$symbol}", $quote, $quoteTtl);
                        $results[$symbol] = $quote;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Async batch quote error for {$symbol}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Fetch corporate profile data.
     *
     * @param string $symbol Equity ticker symbol.
     * @param string|null $apiKey Optional API key.
     * @param bool $forceRefresh When true, bypasses cache.
     * @return array|null Profile array or null.
     */
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
        }, (int) $this->appConfig->get('cache.ttl.finnhub.profile', 2592000));
    }

    /**
     * Fetch earnings release calendar for date range or specific symbol.
     *
     * @param string $fromDate Start date in YYYY-MM-DD format.
     * @param string $toDate End date in YYYY-MM-DD format.
     * @param string|null $symbol Optional ticker symbol filter.
     * @param bool $forceRefresh When true, bypasses cache.
     * @return array List of scheduled corporate earnings entries.
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
        }, (int) $this->appConfig->get('cache.ttl.finnhub.earnings', 86400)) ?? [];
    }

    /**
     * Fetch dividend payout calendar and historical cash distributions.
     *
     * @param string $symbol Equity ticker symbol.
     * @param bool $forceRefresh When true, bypasses cache.
     * @return array List of dividend event dictionaries.
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
        }, (int) $this->appConfig->get('cache.ttl.finnhub.dividends', 2592000)) ?? [];
    }

    /**
     * Fetch general market news articles.
     *
     * @param string $category News category slug.
     * @param bool $forceRefresh When true, bypasses cache.
     * @return array List of news items.
     */
    public function getMarketNews(string $category = 'general', bool $forceRefresh = false): array
    {
        $key = $this->getEffectiveApiKey();
        $cacheKey = "finnhub.news.{$category}";
        if ($forceRefresh) {
            $this->cache->delete($cacheKey);
        }

        return $this->cache->get($cacheKey, function() use ($category, $key) {
            try {
                $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/news', [
                    'query' => [
                        'category' => $category,
                        'token'  => $key,
                    ],
                    'timeout' => (float) $this->appConfig->get('api.timeout.finnhub.default', 3.0),
                ]);

                if ($response->getStatusCode() === 200) {
                    $data = $response->toArray(false);
                    return is_array($data) ? $data : [];
                }
                return [];
            } catch (\Exception $e) {
                $this->logger->error("Finnhub Market News Error: " . $e->getMessage(), ['category' => $category]);
                return [];
            }
        });
    }

    /**
     * Resolve security metadata by CUSIP or numeric identifier.
     *
     * @param string $cusip CUSIP string.
     * @param string|null $apiKey Optional API key.
     * @return array|null Resolved security profile or null.
     */
    public function lookupCusip(string $cusip, ?string $apiKey = null): ?array
    {
        $cusip = strtoupper(trim($cusip));
        $key = $this->getEffectiveApiKey($apiKey);
        if (!$key || empty($cusip)) {
            return null;
        }

        $cacheKey = "finnhub.cusip.{$cusip}";
        return $this->cache->get($cacheKey, function() use ($cusip, $key) {
            try {
                $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/stock/profile2', [
                    'query' => [
                        'cusip' => $cusip,
                        'token' => $key,
                    ],
                    'timeout' => (float) $this->appConfig->get('api.timeout.finnhub.default', 3.0),
                ]);

                if ($response->getStatusCode() === 200) {
                    $data = $response->toArray();
                    if (!empty($data['ticker']) || !empty($data['name'])) {
                        return [
                            'ticker' => $data['ticker'] ?? null,
                            'name' => $data['name'] ?? null,
                            'currency' => $data['currency'] ?? 'USD',
                            'exchange' => $data['exchange'] ?? null,
                            'finnhubIndustry' => $data['finnhubIndustry'] ?? null,
                            'type' => 'EQUITY',
                        ];
                    }
                }

                $searchResp = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/search', [
                    'query' => [
                        'q' => $cusip,
                        'token' => $key,
                    ],
                    'timeout' => (float) $this->appConfig->get('api.timeout.finnhub.default', 3.0),
                ]);

                if ($searchResp->getStatusCode() === 200) {
                    $searchData = $searchResp->toArray();
                    $results = $searchData['result'] ?? [];
                    if (!empty($results)) {
                        $top = $results[0];
                        return [
                            'ticker' => $top['symbol'] ?? null,
                            'name' => $top['description'] ?? null,
                            'type' => $top['type'] ?? 'SECURITY',
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Finnhub CUSIP lookup failed for {$cusip}: " . $e->getMessage());
            }

            return null;
        }, (int) $this->appConfig->get('cache.ttl.finnhub.profile', 2592000));
    }

    /**
     * Search Finnhub symbol directory for tickers matching query string.
     * Cached for 14 days; new search terms will fetch from API on first lookup.
     *
     * @param string $query Query string.
     * @param string|null $apiKey Optional API key.
     * @return array List of search match dictionaries.
     */
    public function searchSymbol(string $query, ?string $apiKey = null): array
    {
        $query = strtoupper(trim($query));
        $key = $this->getEffectiveApiKey($apiKey);
        if (!$key || empty($query)) {
            return [];
        }

        $cacheKey = "finnhub.search." . md5($query);
        return $this->cache->get($cacheKey, function() use ($query, $key) {
            try {
                $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/search', [
                    'query' => [
                        'q' => $query,
                        'token' => $key,
                    ],
                    'timeout' => (float) $this->appConfig->get('api.timeout.finnhub.default', 3.0),
                ]);

                if ($response->getStatusCode() === 200) {
                    $data = $response->toArray();
                    return $data['result'] ?? [];
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Finnhub search error for {$query}: " . $e->getMessage());
            }
            return [];
        }, (int) $this->appConfig->get('cache.ttl.finnhub.search', 1209600)) ?? [];
    }

    /**
     * Fetch historical stock split adjustments for a symbol.
     * Cached for 30 days.
     *
     * @param string $symbol Equity ticker symbol.
     * @param string|null $from Start date YYYY-MM-DD.
     * @param string|null $to End date YYYY-MM-DD.
     * @param string|null $apiKey Optional API key.
     * @return array List of stock split entries.
     */
    public function getStockSplits(string $symbol, ?string $from = null, ?string $to = null, ?string $apiKey = null): array
    {
        $symbol = strtoupper(trim($symbol));
        $key = $this->getEffectiveApiKey($apiKey);
        if (!$key || empty($symbol)) {
            return [];
        }

        $fromDate = $from ?: '2020-01-01';
        $toDate = $to ?: date('Y-m-d');
        $cacheKey = "finnhub.splits.{$symbol}.{$fromDate}.{$toDate}";

        return $this->cache->get($cacheKey, function() use ($symbol, $fromDate, $toDate, $key) {
            try {
                $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/stock/split', [
                    'query' => [
                        'symbol' => $symbol,
                        'from'   => $fromDate,
                        'to'     => $toDate,
                        'token'  => $key,
                    ],
                    'timeout' => (float) $this->appConfig->get('api.timeout.finnhub.default', 3.0),
                ]);

                if ($response->getStatusCode() === 200) {
                    $data = $response->toArray();
                    return is_array($data) ? $data : [];
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Finnhub Stock Splits API error for {$symbol}: " . $e->getMessage());
            }
        }, (int) $this->appConfig->get('cache.ttl.finnhub.splits', 2592000)) ?? [];
    }

    /**
     * Fetch analyst consensus 12-month price targets (high, low, mean, median).
     * Cached for 7 days.
     *
     * @param string $symbol Equity ticker symbol.
     * @param bool $forceRefresh When true, bypasses cache.
     * @return array Price target metrics.
     */
    public function getPriceTarget(string $symbol, bool $forceRefresh = false): array
    {
        $symbol = strtoupper(trim($symbol));
        $key = $this->getEffectiveApiKey();
        if (!$key || empty($symbol)) {
            return [];
        }

        $cacheKey = "finnhub.target.{$symbol}";
        if ($forceRefresh) {
            $this->cache->delete($cacheKey);
        }

        return $this->cache->get($cacheKey, function() use ($symbol, $key) {
            try {
                $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/stock/price-target', [
                    'query' => [
                        'symbol' => $symbol,
                        'token'  => $key,
                    ],
                    'timeout' => (float) $this->appConfig->get('api.timeout.finnhub.default', 3.0),
                ]);

                if ($response->getStatusCode() === 200) {
                    $data = $response->toArray();
                    return [
                        'symbol'       => $data['symbol'] ?? $symbol,
                        'targetHigh'   => $data['targetHigh'] ?? null,
                        'targetLow'    => $data['targetLow'] ?? null,
                        'targetMean'   => $data['targetMean'] ?? null,
                        'targetMedian' => $data['targetMedian'] ?? null,
                        'lastUpdated'  => $data['lastUpdated'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Finnhub Price Target API error for {$symbol}: " . $e->getMessage());
            }
            return [];
        }, (int) $this->appConfig->get('cache.ttl.finnhub.price_target', 604800)) ?? [];
    }

    /**
     * Fetch latest analyst recommendation trends (strong buy, buy, hold, sell, strong sell).
     * Cached for 7 days.
     *
     * @param string $symbol Equity ticker symbol.
     * @param bool $forceRefresh When true, bypasses cache.
     * @return array List of monthly analyst recommendation distributions.
     */
    public function getRecommendationTrends(string $symbol, bool $forceRefresh = false): array
    {
        $symbol = strtoupper(trim($symbol));
        $key = $this->getEffectiveApiKey();
        if (!$key || empty($symbol)) {
            return [];
        }

        $cacheKey = "finnhub.recs.{$symbol}";
        if ($forceRefresh) {
            $this->cache->delete($cacheKey);
        }

        return $this->cache->get($cacheKey, function() use ($symbol, $key) {
            try {
                $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/stock/recommendation', [
                    'query' => [
                        'symbol' => $symbol,
                        'token'  => $key,
                    ],
                    'timeout' => (float) $this->appConfig->get('api.timeout.finnhub.default', 3.0),
                ]);

                if ($response->getStatusCode() === 200) {
                    $data = $response->toArray();
                    return is_array($data) ? $data : [];
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Finnhub Recommendation Trends API error for {$symbol}: " . $e->getMessage());
            }
            return [];
        }, (int) $this->appConfig->get('cache.ttl.finnhub.recommendations', 604800)) ?? [];
    }
}
