<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StudentComment;
use App\Security\AdminTokenGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CommentController
{
    #[Route('/api/comments', methods: ['GET'])]
    public function listApproved(EntityManagerInterface $em): JsonResponse
    {
        $comments = $em->getRepository(StudentComment::class)->findBy(['status' => 'approved']);
        $data = array_map(fn (StudentComment $comment) => $this->mapComment($comment), $comments);

        return new JsonResponse($data);
    }

    #[Route('/api/comments', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = $this->parseJson($request);

        $comment = new StudentComment();
        $comment->setAuthorName((string) ($payload['authorName'] ?? ''));
        $comment->setAuthorRole((string) ($payload['authorRole'] ?? ''));
        $comment->setContent((string) ($payload['content'] ?? ''));
        $comment->setStatus('pending');

        $em->persist($comment);
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/admin/comments', methods: ['GET'])]
    public function listAll(Request $request, EntityManagerInterface $em, AdminTokenGuard $guard): JsonResponse
    {
        $guard->assertAdmin($request);
        $comments = $em->getRepository(StudentComment::class)->findAll();
        $data = array_map(fn (StudentComment $comment) => $this->mapComment($comment), $comments);

        return new JsonResponse($data);
    }

    #[Route('/api/admin/comments/{id}', methods: ['PATCH'])]
    public function updateStatus(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        AdminTokenGuard $guard
    ): JsonResponse {
        $guard->assertAdmin($request);
        $comment = $em->getRepository(StudentComment::class)->find($id);
        if (!$comment) {
            throw new NotFoundHttpException('Comment not found');
        }

        $payload = $this->parseJson($request);
        $status = (string) ($payload['status'] ?? 'pending');
        $comment->setStatus($status);
        if ($status === 'approved') {
            $comment->setApprovedAt(new \DateTimeImmutable());
        }

        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/admin/comments/{id}', methods: ['DELETE'])]
    public function delete(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        AdminTokenGuard $guard
    ): JsonResponse {
        $guard->assertAdmin($request);
        $comment = $em->getRepository(StudentComment::class)->find($id);
        if (!$comment) {
            throw new NotFoundHttpException('Comment not found');
        }

        $em->remove($comment);
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    private function parseJson(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON payload');
        }
        return $payload;
    }

    private function mapComment(StudentComment $comment): array
    {
        return [
            'id' => $comment->getId(),
            'authorName' => $comment->getAuthorName(),
            'authorRole' => $comment->getAuthorRole(),
            'content' => $comment->getContent(),
            'status' => $comment->getStatus(),
            'createdAt' => $comment->getCreatedAt()->format('Y-m-d'),
        ];
    }
}
