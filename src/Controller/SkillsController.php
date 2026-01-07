<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SkillsSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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
}
