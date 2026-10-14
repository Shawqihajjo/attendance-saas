<?php

declare(strict_types=1);

namespace App\Platform\Device;

final class IpRange
{
    public static function valid(string $cidr): bool
    {
        $cidr = trim($cidr);
        if (!str_contains($cidr, '/')) {
            return filter_var($cidr, FILTER_VALIDATE_IP) !== false;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        if (!ctype_digit($bits)) {
            return false;
        }
        $network = inet_pton($subnet);
        if ($network === false) {
            return false;
        }
        $size = (int) $bits;

        return $size >= 0 && $size <= strlen($network) * 8;
    }

    public static function contains(string $ip, string $cidr): bool
    {
        if (!self::valid($cidr) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipBytes = inet_pton($ip);
        $networkBytes = inet_pton($subnet);
        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }
        $size = (int) $bits;
        $wholeBytes = intdiv($size, 8);
        $remainder = $size % 8;
        if ($wholeBytes > 0 && substr($ipBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }
}
