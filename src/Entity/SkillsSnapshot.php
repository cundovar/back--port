<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "skills_snapshot")]
class SkillsSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "text")]
    private string $summaryText;

    #[ORM\Column(type: "json")]
    private array $topSkills = [];

    #[ORM\Column(type: "json")]
    private array $hiddenSkills = [];

    #[ORM\Column(type: "json")]
    private array $evidence = [];

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $generatedAt;

    public function __construct()
    {
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSummaryText(): string
    {
        return $this->summaryText;
    }

    public function setSummaryText(string $value): void
    {
        $this->summaryText = $value;
    }

    public function getTopSkills(): array
    {
        return $this->topSkills;
    }

    public function setTopSkills(array $value): void
    {
        $this->topSkills = $value;
    }

    public function getHiddenSkills(): array
    {
        return $this->hiddenSkills;
    }

    public function setHiddenSkills(array $value): void
    {
        $this->hiddenSkills = $value;
    }

    public function getEvidence(): array
    {
        return $this->evidence;
    }

    public function setEvidence(array $value): void
    {
        $this->evidence = $value;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }
}
