<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Content;
use App\Security\AdminTokenGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ContentController
{
    #[Route('/api/content', methods: ['GET'])]
    public function getContent(EntityManagerInterface $em): JsonResponse
    {
        $content = $this->getSingletonContent($em);
        if (!$content) {
            return new JsonResponse([]);
        }

        return new JsonResponse($content->getPayload());
    }

    #[Route('/api/admin/content', methods: ['PUT'])]
    public function updateContent(
        Request $request,
        EntityManagerInterface $em,
        AdminTokenGuard $guard
    ): JsonResponse {
        $guard->assertAdmin($request);

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON payload');
        }

        $content = $this->getSingletonContent($em) ?? new Content();
        $content->setPayload($payload);

        $em->persist($content);
        $this->removeOlderContent($em, $content);
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    private function getSingletonContent(EntityManagerInterface $em): ?Content
    {
        $repo = $em->getRepository(Content::class);
        $content = $repo->find(1);
        if ($content instanceof Content) {
            return $content;
        }

        return $repo->findOneBy([], ['updatedAt' => 'DESC']);
    }

    private function removeOlderContent(EntityManagerInterface $em, Content $keep): void
    {
        $all = $em->getRepository(Content::class)->findAll();
        foreach ($all as $item) {
            if ($item->getId() !== $keep->getId()) {
                $em->remove($item);
            }
        }
    }
}
