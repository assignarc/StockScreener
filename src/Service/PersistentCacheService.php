<?php

namespace App\Service;

use App\Entity\PersistentCache;
use App\Repository\PersistentCacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class PersistentCacheService
 *
 * Provides a resilient multi-tier persistent caching system backed by memory and SQLite database.
 * Supports configurable TTLs, cache-stampede mitigation locks, AES-256-GCM encryption for sensitive
 * broker balances, opportunistic eviction, and strict PII data sanitization.
 *
 * Design Reference: doc/database-caching.md
 */
class PersistentCacheService
{
    /** @var array<string, mixed> Runtime static memory cache */
    private array $memoryCache = [];

    /** @var array<string, bool> Active computation locks for stampede mitigation */
    private array $pendingLocks = [];

    /** @var string Cache encryption key */
    private string $encryptionKey;

    /**
     * @param EntityManagerInterface $em Doctrine entity manager.
     * @param PersistentCacheRepository $cacheRepo PersistentCache repository.
     * @param LoggerInterface $logger Application logger.
     * @param string $projectDir Application root directory path.
     */
    public function __construct(
        private EntityManagerInterface $em,
        private PersistentCacheRepository $cacheRepo,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {
        $this->encryptionKey = $_ENV['CACHE_ENCRYPTION_KEY'] ?? $_ENV['APP_SECRET'] ?? 'default_fallback_secret_key_1234567890';
        if (strlen($this->encryptionKey) < 32) {
            $this->encryptionKey = str_pad($this->encryptionKey, 32, '0');
        }
    }

    /**
     * Encrypt a serialized value using AES-256-GCM.
     *
     * @param mixed $value Payload to encrypt.
     * @return string Base64-encoded initialization vector, tag, and ciphertext.
     */
    private function encryptValue(mixed $value): string
    {
        $serialized = serialize($value);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-gcm'));
        $tag = '';
        $encrypted = openssl_encrypt($serialized, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Decrypt a value using AES-256-GCM.
     *
     * @param string $payload Base64-encoded encrypted payload.
     * @return mixed Unserialized payload or null on failure.
     */
    private function decryptValue(string $payload): mixed
    {
        try {
            $raw = base64_decode($payload);
            $ivLength = openssl_cipher_iv_length('aes-256-gcm');
            if (strlen($raw) < $ivLength + 16) return null;

            $iv = substr($raw, 0, $ivLength);
            $tag = substr($raw, $ivLength, 16);
            $encrypted = substr($raw, $ivLength + 16);

            $decrypted = openssl_decrypt($encrypted, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
            if ($decrypted === false) return null;

            return unserialize($decrypted);
        } catch (\Throwable $e) {
            $this->logger->error("Cache decryption failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve cached value from memory or SQLite, or execute fallback callback to populate cache.
     *
     * @param string $key Cache key string.
     * @param callable|null $fallback Optional computation callback.
     * @param int $ttlSeconds Time to live in seconds.
     * @param bool $isSensitive When true, encrypts payload using AES-256-GCM.
     * @return mixed Cached or computed value.
     */
    public function get(string $key, ?callable $fallback = null, int $ttlSeconds = 3600, bool $isSensitive = false): mixed
    {
        // 1. Check runtime memory cache
        if (array_key_exists($key, $this->memoryCache)) {
            return $this->memoryCache[$key];
        }

        // Opportunistic expired cache cleanup (1-in-25 probability)
        if (mt_rand(1, 25) === 1) {
            $this->pruneExpiredSafely();
        }

        // 2. Check persistent SQLite data.db
        try {
            $cached = $this->cacheRepo->findValid($key);
            if ($cached !== null && !$cached->isExpired()) {
                $rawVal = $cached->getValue();
                
                // If it's a string that looks like base64 and it's marked as sensitive, try to decrypt
                $val = ($isSensitive && is_string($rawVal) && base64_decode($rawVal, true) !== false) 
                    ? $this->decryptValue($rawVal) 
                    : $rawVal;
                
                if ($val !== null) {
                    $this->memoryCache[$key] = $val;
                    return $val;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Persistent cache read error for {$key}: " . $e->getMessage());
        }

        // 3. If no valid cache and fallback given, compute & persist with stampede lock
        if ($fallback !== null) {
            if (isset($this->pendingLocks[$key])) {
                // Return whatever is in memory or null to prevent re-entrant recursion
                return $this->memoryCache[$key] ?? null;
            }

            $this->pendingLocks[$key] = true;
            try {
                $freshValue = $fallback();
                if ($freshValue !== null && $freshValue !== false) {
                    $this->set($key, $freshValue, $ttlSeconds, $isSensitive);
                }
                return $freshValue;
            } catch (\Throwable $e) {
                $this->logger->error("Persistent cache fallback computation error for {$key}: " . $e->getMessage());
                throw $e;
            } finally {
                unset($this->pendingLocks[$key]);
            }
        }

        return null;
    }

    /**
     * Prune expired cache records from SQLite to prevent database bloat.
     *
     * @return int Number of purged rows.
     */
    public function pruneExpiredSafely(): int
    {
        try {
            return $this->cacheRepo->purgeExpired();
        } catch (\Throwable $e) {
            $this->logger->warning("Failed pruning expired cache: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Persist key-value pair into memory and SQLite persistent cache with TTL.
     *
     * @param string $key Cache key string.
     * @param mixed $value Value to store.
     * @param int $ttlSeconds Time to live in seconds.
     * @param bool $isSensitive When true, sanitizes PII and encrypts payload.
     */
    public function set(string $key, mixed $value, int $ttlSeconds = 3600, bool $isSensitive = false): void
    {
        if ($value === null) {
            return;
        }

        // If marked sensitive (e.g. broker portfolio), sanitize first
        if ($isSensitive && is_array($value)) {
            $value = $this->sanitizeBrokerData($value);
        }

        $this->memoryCache[$key] = $value;
        
        $valueToStore = $isSensitive ? $this->encryptValue($value) : $value;

        try {
            $existing = $this->cacheRepo->findOneBy(['cacheKey' => $key]);
            if ($existing) {
                $existing->setValue($valueToStore);
                $existing->setTtl($ttlSeconds);
            } else {
                $entry = new PersistentCache($key, $valueToStore, $ttlSeconds, $isSensitive);
                $this->em->persist($entry);
            }
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning("Persistent cache write error for {$key}: " . $e->getMessage());
        }
    }

    /**
     * Remove a specific key from memory and SQLite cache.
     *
     * @param string $key Cache key string.
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
     * Purge all cache keys matching a specific prefix (e.g., 'finnhub.', 'broker.').
     *
     * @param string $prefix Key prefix string.
     * @return int Number of deleted rows.
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
     * Alias for clearPrefix().
     *
     * @param string $prefix Key prefix string.
     * @return int Number of deleted rows.
     */
    public function purgeByPrefix(string $prefix): int
    {
        return $this->clearPrefix($prefix);
    }

    /**
     * Clear all persistent and in-memory cache entries.
     *
     * @return int Number of purged rows.
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
     * Retrieve cache usage metrics and database storage stats.
     *
     * @return array Cache diagnostics metrics.
     */
    public function getStats(): array
    {
        $dbPath = $this->projectDir . '/var/data.db';
        $dbSize = file_exists($dbPath) ? filesize($dbPath) : 0;

        try {
            $activeCount  = $this->cacheRepo->countActive();
            $finnhubCount = count($this->cacheRepo->findBy(['isSensitive' => false]));
            $brokerCount  = count($this->cacheRepo->findBy(['isSensitive' => true]));
        } catch (\Throwable) {
            $activeCount  = 0;
            $finnhubCount = 0;
            $brokerCount  = 0;
        }

        return [
            'activeEntries'  => $activeCount,
            'finnhubEntries' => $finnhubCount,
            'brokerEntries'  => $brokerCount,
            'schwabEntries'  => $brokerCount, // Alias for template compatibility
            'databaseSizeKb' => round($dbSize / 1024, 1),
            'storageType'    => 'SQLite Persistent DB (var/data.db)',
        ];
    }

    /**
     * Redact Personally Identifiable Information (PII) from brokerage portfolios:
     * - Masks account numbers to last 4 digits (***1234).
     * - Strips tokens, personal names, SSNs, routing numbers, and contact details.
     * - Retains structural financial data (symbols, quantities, strikes, market values).
     *
     * @param array $portfolio Raw portfolio dictionary.
     * @return array Sanitized portfolio dictionary.
     */
    public function sanitizeBrokerData(array $portfolio): array
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
