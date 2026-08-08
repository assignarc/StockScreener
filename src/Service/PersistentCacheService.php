<?php

namespace App\Service;

use App\Entity\PersistentCache;
use App\Repository\PersistentCacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class PersistentCacheService
{
    /** @var array<string, mixed> Runtime static memory cache */
    private array $memoryCache = [];

    public function __construct(
        private EntityManagerInterface $em,
        private PersistentCacheRepository $cacheRepo,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {}

    /**
     * Retrieves a cached value, or executes fallback, persists result in data.db, and returns it.
     */
    public function get(string $key, ?callable $fallback = null, int $ttlSeconds = 3600, bool $isSensitive = false): mixed
    {
        // 1. Check runtime memory cache
        if (array_key_exists($key, $this->memoryCache)) {
            return $this->memoryCache[$key];
        }

        // 2. Check persistent SQLite data.db
        try {
            $cached = $this->cacheRepo->findValid($key);
            if ($cached !== null && !$cached->isExpired()) {
                $val = $cached->getValue();
                $this->memoryCache[$key] = $val;
                return $val;
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Persistent cache read error for {$key}: " . $e->getMessage());
        }

        // 3. If no valid cache and fallback given, compute & persist
        if ($fallback !== null) {
            try {
                $freshValue = $fallback();
                if ($freshValue !== null && $freshValue !== false) {
                    $this->set($key, $freshValue, $ttlSeconds, $isSensitive);
                }
                return $freshValue;
            } catch (\Throwable $e) {
                $this->logger->error("Persistent cache fallback computation error for {$key}: " . $e->getMessage());
                throw $e;
            }
        }

        return null;
    }

    /**
     * Persists a key-value pair into SQLite data.db with TTL
     */
    public function set(string $key, mixed $value, int $ttlSeconds = 3600, bool $isSensitive = false): void
    {
        if ($value === null) {
            return;
        }

        // If marked sensitive (e.g. Schwab portfolio), sanitize first
        if ($isSensitive && is_array($value)) {
            $value = $this->sanitizeSchwabData($value);
        }

        $this->memoryCache[$key] = $value;

        try {
            $existing = $this->cacheRepo->findOneBy(['cacheKey' => $key]);
            if ($existing) {
                $existing->setValue($value);
                $existing->setTtl($ttlSeconds);
            } else {
                $entry = new PersistentCache($key, $value, $ttlSeconds, $isSensitive);
                $this->em->persist($entry);
            }
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning("Persistent cache write error for {$key}: " . $e->getMessage());
        }
    }

    /**
     * Deletes a specific cache key
     */
    public function delete(string $key): void
    {
        unset($this->memoryCache[$key]);
        try {
            $entry = $this->cacheRepo->findOneBy(['cacheKey' => $key]);
            if ($entry) {
                $this->em->remove($entry);
                $this->em->flush();
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Persistent cache delete error for {$key}: " . $e->getMessage());
        }
    }

    /**
     * Purges keys matching a prefix (e.g. 'finnhub.' or 'schwab.')
     */
    public function clearPrefix(string $prefix): int
    {
        foreach (array_keys($this->memoryCache) as $k) {
            if (str_starts_with($k, $prefix)) {
                unset($this->memoryCache[$k]);
            }
        }

        try {
            return $this->cacheRepo->purgePrefix($prefix);
        } catch (\Throwable $e) {
            $this->logger->warning("Persistent cache clearPrefix error for {$prefix}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clears all persistent cache entries
     */
    public function clearAll(): int
    {
        $this->memoryCache = [];
        try {
            return $this->cacheRepo->purgeAll();
        } catch (\Throwable $e) {
            $this->logger->warning("Persistent cache clearAll error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Returns cache usage statistics
     */
    public function getStats(): array
    {
        $dbPath = $this->projectDir . '/var/data.db';
        $dbSize = file_exists($dbPath) ? filesize($dbPath) : 0;

        try {
            $activeCount = $this->cacheRepo->countActive();
            $finnhubCount = count($this->cacheRepo->findBy(['isSensitive' => false]));
            $schwabCount  = count($this->cacheRepo->findBy(['isSensitive' => true]));
        } catch (\Throwable) {
            $activeCount = 0;
            $finnhubCount = 0;
            $schwabCount = 0;
        }

        return [
            'activeEntries'  => $activeCount,
            'finnhubEntries' => $finnhubCount,
            'schwabEntries'  => $schwabCount,
            'databaseSizeKb' => round($dbSize / 1024, 1),
            'storageType'    => 'SQLite Persistent DB (var/data.db)',
        ];
    }

    /**
     * Strict Non-PII Sanitizer for Schwab Brokerage Data:
     * - Masks account numbers to last 4 digits (***1234).
     * - Strips full account numbers, authorization tokens, personal names, SSNs, routing numbers, and contact details.
     * - Retains only structural financial aggregates (symbols, quantities, strikes, expirations, market values).
     */
    public function sanitizeSchwabData(array $portfolio): array
    {
        $sanitized = $portfolio;

        // Strip any sensitive root keys if present
        unset($sanitized['access_token'], $sanitized['refresh_token'], $sanitized['tokens']);

        if (isset($sanitized['accounts']) && is_array($sanitized['accounts'])) {
            foreach ($sanitized['accounts'] as &$acc) {
                if (isset($acc['accountNumber'])) {
                    $acc['accountNumber'] = '***' . substr((string) $acc['accountNumber'], -4);
                }
                unset($acc['accountHolderName'], $acc['taxId'], $acc['ssn'], $acc['routingNumber']);
            }
            unset($acc);
        }

        if (isset($sanitized['aggregatedEquities']) && is_array($sanitized['aggregatedEquities'])) {
            foreach ($sanitized['aggregatedEquities'] as &$eq) {
                if (isset($eq['accountBreakdown']) && is_array($eq['accountBreakdown'])) {
                    foreach ($eq['accountBreakdown'] as &$ab) {
                        if (isset($ab['accountNumber'])) {
                            $ab['accountNumber'] = '***' . substr((string) $ab['accountNumber'], -4);
                        }
                    }
                    unset($ab);
                }
            }
            unset($eq);
        }

        return $sanitized;
    }
}
