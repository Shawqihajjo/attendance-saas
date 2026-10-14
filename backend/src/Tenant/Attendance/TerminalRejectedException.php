<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

use App\Shared\Http\ApiProblem;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TerminalRejectedException extends HttpException implements ApiProblem
{
    public function __construct(
        private readonly string $reason,
        string $message,
        int $status = 422,
    ) {
        parent::__construct($status, $message);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function problemCode(): string
    {
        return $this->reason;
    }

    public function problemTitle(): string
    {
        return 'Terminal rejected';
    }
}
