<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Shared\Http\ApiProblem;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class StateConflictException extends ConflictHttpException implements ApiProblem
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    public function problemCode(): string
    {
        return 'conflict';
    }

    public function problemTitle(): string
    {
        return 'Conflict';
    }
}
