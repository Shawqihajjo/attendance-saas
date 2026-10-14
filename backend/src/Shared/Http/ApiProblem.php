<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

interface ApiProblem extends HttpExceptionInterface
{
    public function problemCode(): string;

    public function problemTitle(): string;
}
