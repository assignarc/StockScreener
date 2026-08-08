<?php

namespace App\Entity;

use App\Repository\PersistentCacheRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

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

    public function __construct(string $key, mixed $value, int $ttlSeconds = 3600, bool $isSensitive = false)
    {
        $this->cacheKey    = $key;
        $this->isSensitive = $isSensitive;
        $this->createdAt   = new \DateTimeImmutable();
        $this->expiresAt   = $this->createdAt->modify("+{$ttlSeconds} seconds");
        $this->setValue($value);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    public function getValue(): mixed
    {
        return json_decode($this->cacheValue, true);
    }

    public function setValue(mixed $value): static
    {
        $this->cacheValue = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setTtl(int $ttlSeconds): static
    {
        $this->expiresAt = (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds");
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isSensitive(): bool
    {
        return $this->isSensitive;
    }
}
