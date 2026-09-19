<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CredentialsInterface;

final class SessionCredentials implements CredentialsInterface
{
    public function isResolved(): bool
    {
        return true;
    }

    public function markResolved(): void
    {
    }
}
