<?php

declare(strict_types=1);

namespace App\Tenant\Attendance\Infrastructure;

use App\Media\ImageInspector;
use App\Media\MediaRejectedException;
use App\Media\MediaStore;
use App\Shared\Time\UtcTime;
use App\Tenant\Attendance\TerminalRejectedException;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class PhotoStore
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $connection,
        private readonly MediaStore $media,
        private readonly ImageInspector $images,
    ) {
    }

    public function store(string $tenantId, string $employeeId, string $contentType, string $bytes, \DateTimeImmutable $now, ?int $retentionDays = null): string
    {
        try {
            $type = $this->images->inspect($contentType, $bytes);
        } catch (MediaRejectedException $exception) {
            throw new TerminalRejectedException('photo_invalid', $exception->getMessage());
        }
        $photoId = Uuid::v7()->toRfc4122();
        try {
            $key = $this->media->write($tenantId, $photoId, $bytes);
        } catch (MediaRejectedException) {
            throw new TerminalRejectedException('photo_invalid', 'The photograph could not be stored.', 500);
        }
        $retainedUntil = null;
        if ($retentionDays !== null) {
            $retainedUntil = UtcTime::persist($now->add(new \DateInterval('P'.$retentionDays.'D')));
        }
        $this->connection->insert('attendance_photos', [
            'id' => $photoId,
            'employee_id' => $employeeId,
            'storage_key' => $key,
            'captured_at' => UtcTime::persist($now),
            'content_type' => $type,
            'byte_size' => strlen($bytes),
            'content_sha256' => hash('sha256', $bytes),
            'storage_driver' => $this->media->name(),
            'retained_until' => $retainedUntil,
        ]);

        return $photoId;
    }
}
