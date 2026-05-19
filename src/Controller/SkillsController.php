<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SkillsSnapshot;
use App\Security\AdminTokenGuard;
use App\Service\SkillsSnapshotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class SkillsController
{
    #[Route('/api/skills', methods: ['GET'])]
    public function getSkills(EntityManagerInterface $em): JsonResponse
    {
        $snapshot = $em->getRepository(SkillsSnapshot::class)->findOneBy([], ['generatedAt' => 'DESC']);
        if (!$snapshot) {
            throw new NotFoundHttpException('Skills snapshot not found');
        }

        return new JsonResponse([
            'summaryText' => $snapshot->getSummaryText(),
            'topSkills' => $snapshot->getTopSkills(),
            'hiddenSkills' => $snapshot->getHiddenSkills(),
            'evidence' => $snapshot->getEvidence(),
            'generatedAt' => $snapshot->getGeneratedAt()->format('Y-m-d'),
        ]);
    }

    #[Route('/api/admin/skills/generate', methods: ['POST'])]
    public function generate(
        Request $request,
        AdminTokenGuard $guard,
        SkillsSnapshotService $snapshotService
    ): JsonResponse {
        $guard->assertAdmin($request);

        $payload = json_decode($request->getContent(), true);
        $skipAi = isset($payload['skipAi']) && $payload['skipAi'] === true;

        $result = $snapshotService->generate($skipAi);
        $snapshot = $result['snapshot'];

        return new JsonResponse([
            'ok' => true,
            'aiUsed' => $result['aiUsed'],
            'snapshot' => [
                'summaryText' => $snapshot->getSummaryText(),
                'topSkills' => $snapshot->getTopSkills(),
                'hiddenSkills' => $snapshot->getHiddenSkills(),
                'evidence' => $snapshot->getEvidence(),
                'generatedAt' => $snapshot->getGeneratedAt()->format('Y-m-d'),
            ],
        ]);
    }
}
