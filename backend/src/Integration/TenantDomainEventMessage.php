<?php

declare(strict_types=1);

namespace App\Integration;

use Symfony\Component\Uid\Uuid;

/**
 * Internal queue contract. The trusted tenant is the server-applied TenantStamp.
 * tenantId on the message must match that stamp or the worker rejects the message.
 *
 * @phpstan-type EventPayload array<string, scalar|null>
 */
final readonly class TenantDomainEventMessage
{
    /** @var EventPayload */
    public array $payload;

    /**
     * @param array<mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $name,
        public string $occurredAt,
        public string $subjectId,
        array $payload,
    ) {
        if (!Uuid::isValid($eventId) || !Uuid::isValid($tenantId) || !Uuid::isValid($subjectId)) {
            throw new \InvalidArgumentException('A domain event requires UUID identifiers.');
        }
        DomainEventName::require($name);
        $checked = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('A domain event payload must be scalar values.');
            }
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('A domain event payload must be scalar values.');
            }
            $checked[$key] = $value;
        }
        $this->payload = $checked;
    }
}
