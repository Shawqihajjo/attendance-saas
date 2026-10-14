<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use Symfony\Component\Validator\Constraints as Assert;

final class LoginCredentials
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public readonly string $email = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public readonly string $password = '',
    ) {
    }
}
