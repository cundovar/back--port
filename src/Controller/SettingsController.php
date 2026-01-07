<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SiteSettings;
use App\Security\AdminTokenGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController
{
    #[Route('/api/settings', methods: ['GET'])]
    public function getSettings(EntityManagerInterface $em): JsonResponse
    {
        $settings = $em->getRepository(SiteSettings::class)->findOneBy([]) ?? new SiteSettings();

        return new JsonResponse([
            'logoText' => $settings->getLogoText(),
            'logoUrl' => $settings->getLogoUrl(),
            'contactEmail' => $settings->getContactEmail(),
            'siteUrl' => $settings->getSiteUrl(),
        ]);
    }

    #[Route('/api/admin/settings', methods: ['PUT'])]
    public function updateSettings(
        Request $request,
        EntityManagerInterface $em,
        AdminTokenGuard $guard
    ): JsonResponse {
        $guard->assertAdmin($request);

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON payload');
        }

        $settings = $em->getRepository(SiteSettings::class)->findOneBy([]) ?? new SiteSettings();

        if (isset($payload['logoText'])) {
            $settings->setLogoText((string) $payload['logoText']);
        }
        if (array_key_exists('logoUrl', $payload)) {
            $settings->setLogoUrl($payload['logoUrl'] !== null ? (string) $payload['logoUrl'] : null);
        }
        if (isset($payload['contactEmail'])) {
            $settings->setContactEmail((string) $payload['contactEmail']);
        }
        if (isset($payload['siteUrl'])) {
            $settings->setSiteUrl((string) $payload['siteUrl']);
        }

        $em->persist($settings);
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }
}
