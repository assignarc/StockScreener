<?php

namespace App\Entity;

use App\Repository\AppConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppConfigRepository::class)]
#[ORM\Table(name: 'app_config')]
class AppConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'config_key', length: 255, unique: true)]
    private string $configKey;

    #[ORM\Column(name: 'config_value', type: Types::TEXT, nullable: true)]
    private ?string $configValue = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key, mixed $value)
    {
        $this->configKey = $key;
        $this->setValue($value);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConfigKey(): string
    {
        return $this->configKey;
    }

    /**
     * Returns the typed value (int, float, bool, string, array) decoded from JSON storage.
     */
    public function getValue(): mixed
    {
        if ($this->configValue === null) {
            return null;
        }

        $decoded = json_decode($this->configValue, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $this->configValue;
    }

    /**
     * Stores any scalar or array value as JSON for uniform TEXT column storage.
     */
    public function setValue(mixed $value): static
    {
        $this->configValue = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
