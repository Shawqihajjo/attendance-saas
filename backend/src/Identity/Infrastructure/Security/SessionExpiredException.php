<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Http\ApiProblem;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class SessionExpiredException extends UnauthorizedHttpException implements ApiProblem
{
    public function __construct()
    {
        parent::__construct('', 'Sign in is required for this resource.');
    }

    public function problemCode(): string
    {
        return 'session_expired';
    }

    public function problemTitle(): string
    {
        return 'Authentication required';
    }
}
