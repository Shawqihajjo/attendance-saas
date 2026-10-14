<?php

declare(strict_types=1);

namespace App\Platform\Device;

use Symfony\Component\HttpFoundation\Request;

final readonly class DeviceCall
{
    public function __construct(
        public ?string $peerIp,
        public ?string $appVersion,
        public ?string $network,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            $request->getClientIp(),
            self::header($request, 'X-Device-App-Version'),
            self::header($request, 'X-Device-Network'),
        );
    }

    private static function header(Request $request, string $name): ?string
    {
        $value = $request->headers->get($name);
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
