<?php

declare(strict_types=1);

namespace App\Shared\Http;

interface ApiProblemExtension extends ApiProblem
{
    /**
     * @return array<string, int|string|bool|null>
     */
    public function problemExtensions(): array;
}
