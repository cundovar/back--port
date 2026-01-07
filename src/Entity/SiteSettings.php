<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "site_settings")]
class SiteSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "string", length: 120)]
    private string $logoText = "Portfolio IA";

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $logoUrl = null;

    #[ORM\Column(type: "string", length: 180)]
    private string $contactEmail = "varascundo@gmail.com";

    #[ORM\Column(type: "string", length: 255)]
    private string $siteUrl = "https://devdoc.varascundo.com/spa/";

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLogoText(): string
    {
        return $this->logoText;
    }

    public function setLogoText(string $value): void
    {
        $this->logoText = $value;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function setLogoUrl(?string $value): void
    {
        $this->logoUrl = $value;
    }

    public function getContactEmail(): string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(string $value): void
    {
        $this->contactEmail = $value;
    }

    public function getSiteUrl(): string
    {
        return $this->siteUrl;
    }

    public function setSiteUrl(string $value): void
    {
        $this->siteUrl = $value;
    }
}
