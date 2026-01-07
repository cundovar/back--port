<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "student_comments")]
class StudentComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "string", length: 120)]
    private string $authorName;

    #[ORM\Column(type: "string", length: 120)]
    private string $authorRole;

    #[ORM\Column(type: "text")]
    private string $content;

    #[ORM\Column(type: "string", length: 16)]
    private string $status = "pending";

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function setAuthorName(string $value): void
    {
        $this->authorName = $value;
    }

    public function getAuthorRole(): string
    {
        return $this->authorRole;
    }

    public function setAuthorRole(string $value): void
    {
        $this->authorRole = $value;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $value): void
    {
        $this->content = $value;
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

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $value): void
    {
        $this->approvedAt = $value;
    }
}
