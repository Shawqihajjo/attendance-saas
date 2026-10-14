<?php

declare(strict_types=1);

namespace App\Tenant\Attendance\Infrastructure;

use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Identity\Infrastructure\Security\AccessGate;
use App\Media\ImageInspector;
use App\Media\MediaNotFoundException;
use App\Media\MediaRejectedException;
use App\Media\MediaStore;
use App\Shared\Time\UtcTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class AttendancePhotoAccess
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $connection,
        private readonly MediaStore $media,
        private readonly ImageInspector $images,
        private readonly AccessGate $access,
        private readonly TenantAccountStore $audit,
    ) {
    }

    public function view(string $tenantId, string $photoId, \DateTimeImmutable $now, ?string $requestId): Response
    {
        $row = $this->row($photoId);
        $this->access->requireEmployeeView((string) $row['employee_id']);
        $this->expireIfDue($tenantId, $row, $now, $requestId);
        $bytes = $this->bytes($tenantId, $row);
        $this->audit->audit(
            'attendance.photo_viewed',
            'tenant_user',
            $this->access->actor()->id(),
            'attendance_photo',
            $photoId,
            $requestId,
            ['employeeId' => $row['employee_id']],
            $now,
        );
        $extension = $row['content_type'] === 'image/png' ? 'png' : 'jpg';

        return new Response($bytes, 200, [
            'Content-Type' => (string) $row['content_type'],
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="attendance-photo.'.$extension.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function delete(string $tenantId, string $photoId, string $actorId, \DateTimeImmutable $now, ?string $requestId): void
    {
        $row = $this->row($photoId);
        $this->erase($tenantId, $row, $now);
        $this->audit->audit(
            'attendance.photo_deleted',
            'tenant_user',
            $actorId,
            'attendance_photo',
            $photoId,
            $requestId,
            ['employeeId' => $row['employee_id']],
            $now,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function expireIfDue(string $tenantId, array $row, \DateTimeImmutable $now, ?string $requestId): void
    {
        $until = $row['retained_until'] ?? null;
        if (!is_string($until) || $until === '' || $until >= UtcTime::persist($now)) {
            return;
        }
        $photoId = (string) $row['id'];
        $this->erase($tenantId, $row, $now);
        $this->audit->audit(
            'attendance.photo_expired',
            'system',
            'retention',
            'attendance_photo',
            $photoId,
            $requestId,
            ['employeeId' => $row['employee_id']],
            $now,
        );

        throw new NotFoundHttpException('The photograph was not found.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function bytes(string $tenantId, array $row): string
    {
        if ((string) $row['storage_driver'] !== $this->media->name()) {
            throw new NotFoundHttpException('The photograph was not found.');
        }
        try {
            $bytes = $this->media->read($tenantId, (string) $row['storage_key']);
        } catch (MediaNotFoundException|MediaRejectedException) {
            throw new NotFoundHttpException('The photograph was not found.');
        }
        $expected = (string) $row['content_sha256'];
        if ($expected !== '' && !hash_equals($expected, hash('sha256', $bytes))) {
            throw new NotFoundHttpException('The photograph was not found.');
        }
        $declaredSize = $row['byte_size'];
        if ((is_int($declaredSize) || (is_string($declaredSize) && ctype_digit($declaredSize))) && (int) $declaredSize > 0 && strlen($bytes) !== (int) $declaredSize) {
            throw new NotFoundHttpException('The photograph was not found.');
        }
        try {
            $type = $this->images->inspect((string) $row['content_type'], $bytes, $expected !== '');
        } catch (MediaRejectedException) {
            throw new NotFoundHttpException('The photograph was not found.');
        }
        if ($type !== $row['content_type']) {
            throw new NotFoundHttpException('The photograph was not found.');
        }

        return $bytes;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function erase(string $tenantId, array $row, \DateTimeImmutable $now): void
    {
        try {
            $this->media->erase($tenantId, (string) $row['storage_key']);
        } catch (MediaRejectedException) {
            throw new NotFoundHttpException('The photograph was not found.');
        }
        $this->connection->update('attendance_photos', [
            'deleted_at' => UtcTime::persist($now),
            'storage_key' => '',
        ], ['id' => $row['id']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $photoId): array
    {
        if (!Uuid::isValid($photoId)) {
            throw new NotFoundHttpException('The photograph was not found.');
        }
        $row = $this->connection->fetchAssociative(
            'SELECT id, employee_id, storage_key, content_type, byte_size, content_sha256, storage_driver, retained_until, deleted_at
             FROM attendance_photos WHERE id = ?',
            [$photoId],
        );
        if (!is_array($row) || (is_string($row['deleted_at'] ?? null) && $row['deleted_at'] !== '') || !is_string($row['storage_key']) || $row['storage_key'] === '') {
            throw new NotFoundHttpException('The photograph was not found.');
        }

        return $row;
    }
}
