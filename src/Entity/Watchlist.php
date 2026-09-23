<?php

namespace App\Entity;

use App\Repository\WatchlistRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Watchlist Entity
 *
 * Represents an individual stock symbol saved to the user's active watchlist.
 */
#[ORM\Entity(repositoryClass: WatchlistRepository::class)]
class Watchlist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private ?string $symbol = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $addedAt = null;

    /**
     * Initializes a new watchlist entry with the current timestamp.
     */
    public function __construct()
    {
        $this->addedAt = new \DateTimeImmutable();
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
     * Returns the stock symbol.
     *
     * @return string|null Uppercase ticker symbol.
     */
    public function getSymbol(): ?string
    {
        return $this->symbol;
    }

    /**
     * Sets the stock symbol.
     *
     * @param string $symbol Stock ticker symbol.
     * @return static Current entity instance.
     */
    public function setSymbol(string $symbol): static
    {
        $this->symbol = strtoupper($symbol);
        return $this;
    }

    /**
     * Returns the timestamp when the ticker was added to the watchlist.
     *
     * @return \DateTimeImmutable|null Addition timestamp.
     */
    public function getAddedAt(): ?\DateTimeImmutable
    {
        return $this->addedAt;
    }
}
