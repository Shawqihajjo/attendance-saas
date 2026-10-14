<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Media\ImageInspector;
use App\Media\MediaKey;
use App\Media\MediaNotFoundException;
use App\Media\MediaRejectedException;
use App\Media\LocalMediaStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ImageInspectorTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\xFF\xD9";

    public function testAJpegAndAPngAreAcceptedWhenTheDeclaredTypeMatches(): void
    {
        $inspector = new ImageInspector();
        $png = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00";

        self::assertSame('image/jpeg', $inspector->inspect('image/jpeg', self::JPEG));
        self::assertSame('image/png', $inspector->inspect('image/png', $png));
        self::assertSame('image/jpeg', $inspector->inspect('image/jpeg', "\xFF\xD8\xFF\xE0\x00\x10JFIF", false));
    }

    public function testActiveContentAndMismatchedTypesAreRejected(): void
    {
        $inspector = new ImageInspector();

        $this->expectRejection(fn () => $inspector->inspect('image/jpeg', self::JPEG.'<?php'));
        $this->expectRejection(fn () => $inspector->inspect('image/jpeg', "\xFF\xD8\xFF<script>\xFF\xD9"));
        $this->expectRejection(fn () => $inspector->inspect('image/png', self::JPEG));
        $this->expectRejection(fn () => $inspector->inspect("image/jpeg\0", self::JPEG));
        $this->expectRejection(fn () => $inspector->inspect('image/jpeg', str_repeat('A', ImageInspector::MAX_BYTES + 1)));
    }

    public function testMediaKeysStayInsideTheTenantAndErasureRemovesTheBytes(): void
    {
        $tenantId = Uuid::v7()->toRfc4122();
        $mediaId = Uuid::v7()->toRfc4122();
        $store = new LocalMediaStore(sys_get_temp_dir().'/attendance-media-'.bin2hex(random_bytes(4)));
        $key = $store->write($tenantId, $mediaId, self::JPEG);

        self::assertSame($tenantId.'/'.$mediaId, $key);
        self::assertSame(self::JPEG, $store->read($tenantId, $key));
        $this->expectRejection(fn () => MediaKey::assert($tenantId, $tenantId.'/../'.$mediaId));
        $this->expectRejection(fn () => $store->read(Uuid::v7()->toRfc4122(), $key));

        $store->erase($tenantId, $key);
        $this->expectException(MediaNotFoundException::class);
        $store->read($tenantId, $key);
    }

    private function expectRejection(\Closure $call): void
    {
        try {
            $call();
            $rejected = null;
        } catch (MediaRejectedException $exception) {
            $rejected = $exception;
        }
        self::assertInstanceOf(MediaRejectedException::class, $rejected);
    }
}
