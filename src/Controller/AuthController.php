<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController
{
    private string $adminToken;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire('%env(ADMIN_API_TOKEN)%')] string $adminToken,
    ) {
        $this->adminToken = $adminToken;
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

        $user = $this->em->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        if (!$user || !$this->hasher->isPasswordValid($user, $password)) {
            throw new UnauthorizedHttpException('Unauthorized');
        }

        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse([
            'token' => $this->adminToken,
            'expiresIn' => 86400,
        ]);
    }

    #[Route('/api/admin/logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/admin/me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $auth = $request->headers->get('Authorization', '');
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : $auth;
        if ($token !== $this->adminToken) {
            throw new UnauthorizedHttpException('Unauthorized');
        }

        $user = $this->em->getRepository(AdminUser::class)->findOneBy([]);

        return new JsonResponse([
            'id' => $user?->getId() ?? 1,
            'email' => $user?->getEmail() ?? 'admin',
            'lastLoginAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }
}
