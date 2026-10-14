<?php

declare(strict_types=1);

namespace App\Platform\DatabaseExplorer;

use App\Shared\Http\ApiProblem;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class ExplorerUnavailableException extends ServiceUnavailableHttpException implements ApiProblem
{
    public function __construct(string $message = 'The read-only database explorer account is not configured.')
    {
        parent::__construct(null, $message);
    }

    public function problemCode(): string
    {
        return 'explorer_unavailable';
    }

    public function problemTitle(): string
    {
        return 'Database explorer unavailable';
    }
}
