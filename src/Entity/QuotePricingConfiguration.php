<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'portfolio_quote_pricing')]
class QuotePricingConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'json')]
    private array $catalog = [];

    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCatalog(): array
    {
        return $this->catalog;
    }

    public function setCatalog(array $catalog): void
    {
        $this->catalog = $catalog;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Bumped on every saved change so an estimate can record the exact grid it used.
     */
    public function bumpVersion(): void
    {
        ++$this->version;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
