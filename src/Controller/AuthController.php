<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AuthController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/admin/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON payload');
        }

        $email = (string) ($payload['email'] ?? '');
        $password = (string) ($payload['password'] ?? '');

        if (empty($email) || empty($password)) {
            throw new BadRequestHttpException('Missing email or password');
        }

        $user = $this->em->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        if (!$user || !$this->hasher->isPasswordValid($user, $password)) {
            throw new UnauthorizedHttpException('Unauthorized', 'Invalid credentials');
        }

        $request->getSession()->start();
        $request->getSession()->set('admin_user_id', $user->getId());

        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'email' => $user->getEmail(),
        ], 200);
    }

    #[Route('/api/admin/logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $request->getSession()->invalidate();
        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/admin/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AdminUser) {
            throw new AccessDeniedException('Not authenticated');
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'lastLoginAt' => $user->getLastLoginAt()?->format(DATE_ATOM),
        ]);
    }
}
