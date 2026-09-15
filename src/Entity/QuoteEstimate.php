<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'portfolio_quote_estimates')]
class QuoteEstimate
{
    final public const STATUS_NEW = 'new';
    final public const STATUS_REVIEWED = 'reviewed';
    final public const STATUS_QUALIFIED = 'qualified';
    final public const STATUS_ARCHIVED = 'archived';

    final public const AI_SOURCE_DEEPSEEK = 'deepseek';
    final public const AI_SOURCE_FALLBACK = 'fallback';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 40)]
    private string $serviceKey;

    #[ORM\Column(type: 'json')]
    private array $answers = [];

    #[ORM\Column(type: 'string', length: 120)]
    private string $fullName;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $company = null;

    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'integer')]
    private int $minimumAmount;

    #[ORM\Column(type: 'integer')]
    private int $maximumAmount;

    #[ORM\Column(type: 'json')]
    private array $calculationDetail = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $aiSummary = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $aiRecommendedScope = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $aiMissingQuestions = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $aiRiskFlags = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $aiSource = self::AI_SOURCE_FALLBACK;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $qualifiedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getServiceKey(): string
    {
        return $this->serviceKey;
    }

    public function setServiceKey(string $serviceKey): void
    {
        $this->serviceKey = $serviceKey;
    }

    public function getAnswers(): array
    {
        return $this->answers;
    }

    public function setAnswers(array $answers): void
    {
        $this->answers = array_filter($answers, static fn ($value): bool => $value !== null);
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

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): void
    {
        $this->company = $company !== null ? trim($company) : null;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone !== null ? trim($phone) : null;
    }

    public function getMinimumAmount(): int
    {
        return $this->minimumAmount;
    }

    public function setMinimumAmount(int $minimumAmount): void
    {
        $this->minimumAmount = $minimumAmount;
    }

    public function getMaximumAmount(): int
    {
        return $this->maximumAmount;
    }

    public function setMaximumAmount(int $maximumAmount): void
    {
        $this->maximumAmount = $maximumAmount;
    }

    public function getCalculationDetail(): array
    {
        return $this->calculationDetail;
    }

    public function setCalculationDetail(array $calculationDetail): void
    {
        $this->calculationDetail = $calculationDetail;
    }

    public function getAiSummary(): ?string
    {
        return $this->aiSummary;
    }

    public function setAiSummary(?string $aiSummary): void
    {
        $this->aiSummary = $aiSummary;
    }

    public function getAiRecommendedScope(): ?array
    {
        return $this->aiRecommendedScope;
    }

    public function setAiRecommendedScope(?array $aiRecommendedScope): void
    {
        $this->aiRecommendedScope = $aiRecommendedScope;
    }

    public function getAiMissingQuestions(): ?array
    {
        return $this->aiMissingQuestions;
    }

    public function setAiMissingQuestions(?array $aiMissingQuestions): void
    {
        $this->aiMissingQuestions = $aiMissingQuestions;
    }

    public function getAiRiskFlags(): ?array
    {
        return $this->aiRiskFlags;
    }

    public function setAiRiskFlags(?array $aiRiskFlags): void
    {
        $this->aiRiskFlags = $aiRiskFlags;
    }

    public function getAiSource(): string
    {
        return $this->aiSource;
    }

    public function setAiSource(string $aiSource): void
    {
        if (!in_array($aiSource, [self::AI_SOURCE_DEEPSEEK, self::AI_SOURCE_FALLBACK], true)) {
            throw new \InvalidArgumentException("Invalid AI source: {$aiSource}");
        }
        $this->aiSource = $aiSource;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, [self::STATUS_NEW, self::STATUS_REVIEWED, self::STATUS_QUALIFIED, self::STATUS_ARCHIVED], true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $this->status = $status;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = $notes !== null ? trim($notes) : null;
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

    public function markAsQualified(): void
    {
        $this->setStatus(self::STATUS_QUALIFIED);
        $this->qualifiedAt ??= new \DateTimeImmutable();
    }

    public function markAsArchived(): void
    {
        $this->setStatus(self::STATUS_ARCHIVED);
    }
}
