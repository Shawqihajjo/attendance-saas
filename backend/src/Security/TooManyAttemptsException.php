<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\Http\ApiProblem;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class TooManyAttemptsException extends TooManyRequestsHttpException implements ApiProblem
{
    public function __construct()
    {
        parent::__construct(60, 'Too many attempts. Try again later.');
    }

    public function problemCode(): string
    {
        return 'rate_limited';
    }

    public function problemTitle(): string
    {
        return 'Too many attempts';
    }
}
