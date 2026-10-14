<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AuthenticatedActor;

final class SessionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(?AuthenticatedActor $actor): array
    {
        if (!$actor instanceof AuthenticatedActor) {
            return [
                'authenticated' => false,
                'principal' => null,
                'displayName' => null,
                'kind' => null,
                'roles' => [],
                'permissions' => [],
            ];
        }

        return [
            'authenticated' => true,
            'principal' => $actor->email(),
            'displayName' => $actor->displayName(),
            'kind' => $actor->kind(),
            'roles' => $actor->appRoles(),
            'permissions' => $actor->permissions(),
        ];
    }
}
