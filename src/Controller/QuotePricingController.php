<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\QuotePricingConfigurationRepository;
use App\Security\AdminTokenGuard;
use App\Service\QuotePricingCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class QuotePricingController
{
    public function __construct(
        private readonly QuotePricingConfigurationRepository $repository,
        private readonly QuotePricingCatalog $catalogService,
    ) {
    }

    /**
     * Public catalog: labels and amounts the simulator renders. No admin metadata.
     */
    #[Route('/api/quote-pricing', methods: ['GET'])]
    public function getPublicCatalog(): JsonResponse
    {
        $configuration = $this->repository->getActive();

        return new JsonResponse([
            'version' => $configuration->getVersion(),
            'catalog' => QuotePricingCatalog::withDefaults($configuration->getCatalog()),
        ]);
    }

    #[Route('/api/admin/quote-pricing', methods: ['GET'])]
    public function getAdminCatalog(Request $request, AdminTokenGuard $guard): JsonResponse
    {
        $guard->assertAdmin($request);

        $configuration = $this->repository->getActive();

        return new JsonResponse([
            'version' => $configuration->getVersion(),
            'updatedAt' => $configuration->getUpdatedAt()->format('c'),
            // Completed here too, so the backoffice shows the list and saving
            // the grid writes it in for good.
            'catalog' => QuotePricingCatalog::withDefaults($configuration->getCatalog()),
        ]);
    }

    #[Route('/api/admin/quote-pricing', methods: ['PUT'])]
    public function updateCatalog(Request $request, EntityManagerInterface $em, AdminTokenGuard $guard): JsonResponse
    {
        $guard->assertAdmin($request);

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !isset($payload['catalog']) || !is_array($payload['catalog'])) {
            throw new BadRequestHttpException('Invalid JSON payload');
        }

        $catalog = $this->catalogService->normalize($payload['catalog']);

        $errors = $this->catalogService->validate($catalog);
        if ($errors !== []) {
            // The active grid stays untouched so a bad draft never reaches visitors.
            return new JsonResponse(['error' => 'invalid_catalog', 'errors' => $errors], 422);
        }

        $configuration = $this->repository->getActive();
        $configuration->setCatalog($catalog);
        $configuration->bumpVersion();

        $em->persist($configuration);
        $em->flush();

        return new JsonResponse([
            'ok' => true,
            'version' => $configuration->getVersion(),
            'updatedAt' => $configuration->getUpdatedAt()->format('c'),
        ]);
    }
}
