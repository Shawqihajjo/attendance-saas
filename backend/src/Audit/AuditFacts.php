<?php

declare(strict_types=1);

namespace App\Audit;

use App\Platform\Device\DevicePrincipal;
use App\TenantContext\Domain\TenantContextHolder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

final class AuditFacts
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly TenantContextHolder $tenants,
        private readonly TokenStorageInterface $tokens,
    ) {
    }

    public function requestId(?string $provided): ?string
    {
        if (is_string($provided) && $provided !== '') {
            return substr($provided, 0, 64);
        }
        $requestId = $this->requests->getCurrentRequest()?->attributes->get('request_id');

        return is_string($requestId) && $requestId !== '' ? substr($requestId, 0, 64) : null;
    }

    public function clientIp(): ?string
    {
        $ip = $this->requests->getCurrentRequest()?->getClientIp();
        if (!is_string($ip) || $ip === '' || strlen($ip) > 64 || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ip;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function tenantId(array $metadata): ?string
    {
        $current = $this->tenants->current();
        if ($current !== null) {
            return $current->tenantId;
        }
        $claimed = $metadata['tenantId'] ?? null;
        if (is_string($claimed) && Uuid::isValid($claimed)) {
            return $claimed;
        }

        return null;
    }

    public function deviceId(): ?string
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof DevicePrincipal) {
            return null;
        }

        return $user->deviceId();
    }
}
