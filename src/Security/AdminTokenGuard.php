<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AdminTokenGuard
{
    private string $adminToken;

    public function __construct(
        #[Autowire('%env(ADMIN_API_TOKEN)%')] string $adminToken,
    ) {
        $this->adminToken = $adminToken;
    }

    public function assertAdmin(Request $request): void
    {
        $auth = $request->headers->get('Authorization', '');
        $token = $auth;
        if (str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
        }

        if ($token === '' || $token !== $this->adminToken) {
            throw new AccessDeniedHttpException('Unauthorized');
        }
    }
}
