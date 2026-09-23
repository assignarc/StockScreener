<?php

namespace App\Entity;

use App\Repository\PersistentCacheRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * PersistentCache Entity
 *
 * Stores cached API responses, Finnhub market quotes, and ephemeral portfolio data
 * in SQLite with configurable time-to-live (TTL) expiration timestamps.
 */
#[ORM\Entity(repositoryClass: PersistentCacheRepository::class)]
#[ORM\Table(name: 'persistent_cache')]
#[ORM\Index(columns: ['cache_key'], name: 'idx_cache_key')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_cache_expires')]
class PersistentCache
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'cache_key', length: 255, unique: true)]
    private string $cacheKey;

    #[ORM\Column(name: 'cache_value', type: Types::TEXT)]
    private string $cacheValue;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'is_sensitive', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isSensitive = false;

    /**
     * Initializes a new cache item with key, payload, TTL, and sensitivity flag.
     *
     * @param string $key         Unique cache key.
     * @param mixed  $value       Serializable cache payload.
     * @param int    $ttlSeconds  Time-to-live in seconds from creation.
     * @param bool   $isSensitive Flag indicating whether the item contains sensitive data.
     */
    public function __construct(string $key, mixed $value, int $ttlSeconds = 3600, bool $isSensitive = false)
    {
        $this->cacheKey    = $key;
        $this->isSensitive = $isSensitive;
        $this->createdAt   = new \DateTimeImmutable();
        $this->expiresAt   = $this->createdAt->modify("+{$ttlSeconds} seconds");
        $this->setValue($value);
    }

    /**
     * Returns the primary key ID.
     *
     * @return int|null Entity identifier.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the unique cache key.
     *
     * @return string Cache key string.
     */
    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    /**
     * Decodes and returns the stored cache value.
     *
     * @return mixed Decoded cache payload.
     */
    public function getValue(): mixed
    {
        return json_decode($this->cacheValue, true);
    }

    /**
     * Encodes and updates the stored cache value.
     *
     * @param mixed $value Value to encode and persist.
     * @return static Current entity instance.
     */
    public function setValue(mixed $value): static
    {
        $this->cacheValue = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this;
    }

    /**
     * Returns the expiration datetime.
     *
     * @return \DateTimeImmutable Expiration timestamp.
     */
    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Updates the expiration timestamp by adding TTL seconds from now.
     *
     * @param int $ttlSeconds Lifespan in seconds.
     * @return static Current entity instance.
     */
    public function setTtl(int $ttlSeconds): static
    {
        $this->expiresAt = (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds");
        return $this;
    }

    /**
     * Checks if the cache entry has passed its expiration time.
     *
     * @return bool True if expired, false otherwise.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    /**
     * Returns the creation datetime.
     *
     * @return \DateTimeImmutable Creation timestamp.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Checks if this cache item is marked as sensitive.
     *
     * @return bool True if sensitive, false otherwise.
     */
    public function isSensitive(): bool
    {
        return $this->isSensitive;
    }
}
