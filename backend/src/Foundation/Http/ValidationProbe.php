<?php

declare(strict_types=1);

namespace App\Foundation\Http;

use Symfony\Component\Validator\Constraints as Assert;

final class ValidationProbe
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 40)]
        public readonly string $name = '',
    ) {
    }
}
