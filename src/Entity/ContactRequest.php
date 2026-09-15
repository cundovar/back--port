<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'portfolio_contact_requests')]
class ContactRequest
{
    final public const STATUS_NEW = 'new';
    final public const STATUS_IN_REVIEW = 'in_review';
    final public const STATUS_ANSWERED = 'answered';
    final public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 120)]
    private string $fullName;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(type: 'string', length: 120)]
    private string $company;

    #[ORM\Column(type: 'string', length: 120)]
    private string $position;

    #[ORM\Column(type: 'string', length: 120)]
    private string $missionType;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $budget = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $timeline = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $honeypot = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $qualifiedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): void
    {
        $this->fullName = trim($fullName);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = trim($email);
    }

    public function getCompany(): string
    {
        return $this->company;
    }

    public function setCompany(string $company): void
    {
        $this->company = trim($company);
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    public function setPosition(string $position): void
    {
        $this->position = trim($position);
    }

    public function getMissionType(): string
    {
        return $this->missionType;
    }

    public function setMissionType(string $missionType): void
    {
        $this->missionType = trim($missionType);
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $this->message = trim($message);
    }

    public function getBudget(): ?string
    {
        return $this->budget;
    }

    public function setBudget(?string $budget): void
    {
        $this->budget = $budget !== null ? trim($budget) : null;
    }

    public function getTimeline(): ?string
    {
        return $this->timeline;
    }

    public function setTimeline(?string $timeline): void
    {
        $this->timeline = $timeline !== null ? trim($timeline) : null;
    }

    public function getHoneypot(): ?string
    {
        return $this->honeypot;
    }

    public function setHoneypot(?string $honeypot): void
    {
        $this->honeypot = $honeypot;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, [self::STATUS_NEW, self::STATUS_IN_REVIEW, self::STATUS_ANSWERED, self::STATUS_ARCHIVED], true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $this->status = $status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getQualifiedAt(): ?\DateTimeImmutable
    {
        return $this->qualifiedAt;
    }

    public function setQualifiedAt(?\DateTimeImmutable $qualifiedAt): void
    {
        $this->qualifiedAt = $qualifiedAt;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = $notes !== null ? trim($notes) : null;
    }

    public function markAsInReview(): void
    {
        $this->setStatus(self::STATUS_IN_REVIEW);
        $this->qualifiedAt = new \DateTimeImmutable();
    }

    public function markAsAnswered(): void
    {
        $this->setStatus(self::STATUS_ANSWERED);
        $this->qualifiedAt ??= new \DateTimeImmutable();
    }

    public function markAsArchived(): void
    {
        $this->setStatus(self::STATUS_ARCHIVED);
    }
}
