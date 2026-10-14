<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\SessionActorResolver;
use App\Identity\Domain\SessionKeys;

final class TenantSessionAuthenticator extends SessionAuthenticator
{
    public function __construct(SessionActorResolver $sessions)
    {
        parent::__construct($sessions, SessionKeys::KIND_TENANT);
    }
}
