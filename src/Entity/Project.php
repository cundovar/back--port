<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "projects")]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "string", length: 160)]
    private string $name;

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
    private string $status = "wip";

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
        $this->status = $value;
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
