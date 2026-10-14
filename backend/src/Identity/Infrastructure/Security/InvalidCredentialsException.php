<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Http\ApiProblem;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class InvalidCredentialsException extends UnauthorizedHttpException implements ApiProblem
{
    public function __construct()
    {
        parent::__construct('', 'The email or password is incorrect.');
    }

    public function problemCode(): string
    {
        return 'invalid_credentials';
    }

    public function problemTitle(): string
    {
        return 'Authentication failed';
    }
}
