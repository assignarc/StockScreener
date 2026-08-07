<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class FinnhubService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ?string $finnhubApiKey = null
    ) {}

    public function getQuote(string $symbol, ?string $apiKey = null): ?array
    {
        $key = $apiKey ?: $this->finnhubApiKey ?: $_ENV['FINNHUB_API_KEY'] ?? null;
        if (!$key) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/quote', [
                'query' => [
                    'symbol' => strtoupper($symbol),
                    'token' => $key,
                ],
            ]);


            if ($response->getStatusCode() === 200) {
                $data = $response->toArray();
                if (isset($data['c']) && $data['c'] > 0) {
                    return [
                        'c' => $data['c'],  // Current price
                        'd' => $data['d'],  // Change
                        'dp' => $data['dp'], // Percent change
                        'h' => $data['h'],  // High
                        'l' => $data['l'],  // Low
                        'o' => $data['o'],  // Open
                        'pc' => $data['pc'], // Previous close
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error("Finnhub API error for symbol {$symbol}: " . $e->getMessage());
        }

        return null;
    }

    public function getBatchQuotes(array $symbols, ?string $apiKey = null): array
    {
        $results = [];
        foreach ($symbols as $symbol) {
            $quote = $this->getQuote($symbol, $apiKey);
            if ($quote) {
                $results[$symbol] = $quote;
            }
        }
        return $results;
    }

    public function getCompanyProfile(string $symbol, ?string $apiKey = null): ?array
    {
        $key = $apiKey ?: $this->finnhubApiKey ?: $_ENV['FINNHUB_API_KEY'] ?? null;
        if (!$key) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/stock/profile2', [
                'query' => [
                    'symbol' => strtoupper($symbol),
                    'token' => $key,
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                return $response->toArray();
            }
        } catch (\Throwable $e) {
            $this->logger->error("Finnhub Company Profile error for {$symbol}: " . $e->getMessage());
        }

        return null;
    }
}
