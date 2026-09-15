<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "projects")]
class Project
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ARCHIVED = 'archived';
    public const PUBLIC_STATUSES = [self::STATUS_PUBLISHED, self::STATUS_IN_PROGRESS];
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_IN_PROGRESS, self::STATUS_ARCHIVED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "string", length: 160)]
    private string $name;

    #[ORM\Column(type: "string", length: 180)]
    private string $slug = '';

    #[ORM\Column(type: "string", length: 200)]
    private string $stack;

    #[ORM\Column(type: "text")]
    private string $summary;

    #[ORM\Column(type: "text")]
    private string $bulletin;

    #[ORM\Column(type: "string", length: 255)]
    private string $siteUrl;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $repoUrl = null;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(type: "string", length: 120, nullable: true)]
    private ?string $duration = null;

    #[ORM\Column(type: "string", length: 16)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $clientProblem = null;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $mission = null;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $solution = null;

    #[ORM\Column(type: "json")]
    private array $outcomes = [];

    #[ORM\Column(type: "json")]
    private array $serviceTags = [];

    #[ORM\Column(type: "boolean")]
    private bool $featured = false;

    #[ORM\Column(type: "integer")]
    private int $sortOrder = 0;

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $value): void
    {
        $this->name = $value;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $value): void
    {
        $this->slug = $value;
    }

    public function getStack(): string
    {
        return $this->stack;
    }

    public function setStack(string $value): void
    {
        $this->stack = $value;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $value): void
    {
        $this->summary = $value;
    }

    public function getBulletin(): string
    {
        return $this->bulletin;
    }

    public function setBulletin(string $value): void
    {
        $this->bulletin = $value;
    }

    public function getSiteUrl(): string
    {
        return $this->siteUrl;
    }

    public function setSiteUrl(string $value): void
    {
        $this->siteUrl = $value;
    }

    public function getRepoUrl(): ?string
    {
        return $this->repoUrl;
    }

    public function setRepoUrl(?string $value): void
    {
        $this->repoUrl = $value;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $value): void
    {
        $this->imageUrl = $value;
    }

    public function getDuration(): ?string
    {
        return $this->duration;
    }

    public function setDuration(?string $value): void
    {
        $this->duration = $value;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $value): void
    {
        if ($value === 'wip') {
            $value = self::STATUS_IN_PROGRESS;
        }

        if (!in_array($value, self::STATUSES, true)) {
            $value = self::STATUS_DRAFT;
        }

        $this->status = $value;
    }

    public function getClientProblem(): ?string
    {
        return $this->clientProblem;
    }

    public function setClientProblem(?string $value): void
    {
        $this->clientProblem = $value;
    }

    public function getMission(): ?string
    {
        return $this->mission;
    }

    public function setMission(?string $value): void
    {
        $this->mission = $value;
    }

    public function getSolution(): ?string
    {
        return $this->solution;
    }

    public function setSolution(?string $value): void
    {
        $this->solution = $value;
    }

    public function getOutcomes(): array
    {
        return $this->outcomes;
    }

    public function setOutcomes(array $value): void
    {
        $this->outcomes = array_values(array_filter($value, static fn ($item): bool => is_string($item) && trim($item) !== ''));
    }

    public function getServiceTags(): array
    {
        return $this->serviceTags;
    }

    public function setServiceTags(array $value): void
    {
        $this->serviceTags = array_values(array_filter($value, static fn ($item): bool => is_string($item) && trim($item) !== ''));
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $value): void
    {
        $this->featured = $value;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $value): void
    {
        $this->sortOrder = $value;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
