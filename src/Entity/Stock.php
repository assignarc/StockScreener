<?php

namespace App\Entity;

use App\Repository\StockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockRepository::class)]
class Stock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private ?string $symbol = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 100)]
    private ?string $sector = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $price = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $targetPrice = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $marketCap = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $revGrowth = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $grossMargin = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $cashRunway = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $shortInterest = null;

    #[ORM\Column(length: 20)]
    private ?string $risk = 'MED';

    #[ORM\Column]
    private ?int $score = 50;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $analystRating = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $thesis = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $catalysts = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $keyRisks = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): ?string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $this->symbol = strtoupper($symbol);
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getSector(): ?string
    {
        return $this->sector;
    }

    public function setSector(string $sector): static
    {
        $this->sector = $sector;
        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(?float $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getTargetPrice(): ?float
    {
        return $this->targetPrice;
    }

    public function setTargetPrice(?float $targetPrice): static
    {
        $this->targetPrice = $targetPrice;
        return $this;
    }

    public function getMarketCap(): ?string
    {
        return $this->marketCap;
    }

    public function setMarketCap(?string $marketCap): static
    {
        $this->marketCap = $marketCap;
        return $this;
    }

    public function getRevGrowth(): ?string
    {
        return $this->revGrowth;
    }

    public function setRevGrowth(?string $revGrowth): static
    {
        $this->revGrowth = $revGrowth;
        return $this;
    }

    public function getGrossMargin(): ?string
    {
        return $this->grossMargin;
    }

    public function setGrossMargin(?string $grossMargin): static
    {
        $this->grossMargin = $grossMargin;
        return $this;
    }

    public function getCashRunway(): ?string
    {
        return $this->cashRunway;
    }

    public function setCashRunway(?string $cashRunway): static
    {
        $this->cashRunway = $cashRunway;
        return $this;
    }

    public function getShortInterest(): ?string
    {
        return $this->shortInterest;
    }

    public function setShortInterest(?string $shortInterest): static
    {
        $this->shortInterest = $shortInterest;
        return $this;
    }

    public function getRisk(): ?string
    {
        return $this->risk;
    }

    public function setRisk(string $risk): static
    {
        $this->risk = $risk;
        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = $score;
        return $this;
    }

    public function getAnalystRating(): ?string
    {
        return $this->analystRating;
    }

    public function setAnalystRating(?string $analystRating): static
    {
        $this->analystRating = $analystRating;
        return $this;
    }

    public function getThesis(): ?string
    {
        return $this->thesis;
    }

    public function setThesis(?string $thesis): static
    {
        $this->thesis = $thesis;
        return $this;
    }

    public function getCatalysts(): ?string
    {
        return $this->catalysts;
    }

    public function setCatalysts(?string $catalysts): static
    {
        $this->catalysts = $catalysts;
        return $this;
    }

    public function getKeyRisks(): ?string
    {
        return $this->keyRisks;
    }

    public function setKeyRisks(?string $keyRisks): static
    {
        $this->keyRisks = $keyRisks;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'symbol' => $this->symbol,
            'name' => $this->name,
            'sector' => $this->sector,
            'price' => $this->price,
            'targetPrice' => $this->targetPrice,
            'marketCap' => $this->marketCap,
            'revGrowth' => $this->revGrowth,
            'grossMargin' => $this->grossMargin,
            'cashRunway' => $this->cashRunway,
            'shortInterest' => $this->shortInterest,
            'risk' => $this->risk,
            'score' => $this->score,
            'analystRating' => $this->analystRating,
            'thesis' => $this->thesis,
            'catalysts' => $this->catalysts,
            'keyRisks' => $this->keyRisks,
        ];
    }
}
