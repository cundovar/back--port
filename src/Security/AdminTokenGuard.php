<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AdminUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AdminTokenGuard
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function assertAdmin(Request $request): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof AdminUser) {
            throw new AccessDeniedHttpException('Unauthorized');
        }
    }
}
