<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\HttpFoundation\Request;

final class SessionKeys
{
    public const KIND = 'auth_kind';
    public const USER_ID = 'user_id';
    public const TENANT_ID = 'tenant_id';
    public const TOKEN_VERSION = 'token_version';
    public const KIND_PLATFORM = 'platform';
    public const KIND_TENANT = 'tenant';
    public const COOKIE = 'ATTENDANCE_SESSION';

    public static function hasCookie(Request $request): bool
    {
        // MOCKSESSID is the cookie name used by Symfony's test session storage.
        foreach ([self::COOKIE, 'MOCKSESSID'] as $name) {
            if ($request->cookies->has($name)) {
                return true;
            }
        }

        return false;
    }
}
