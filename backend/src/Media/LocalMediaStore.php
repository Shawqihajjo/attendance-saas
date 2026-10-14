<?php

declare(strict_types=1);

namespace App\Media;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class LocalMediaStore implements MediaStore
{
    public function __construct(
        #[Autowire('%env(resolve:MEDIA_LOCAL_DIRECTORY)%')]
        private readonly string $directory,
    ) {
    }

    public function name(): string
    {
        return 'local';
    }

    public function write(string $tenantId, string $mediaId, string $bytes): string
    {
        $key = MediaKey::for($tenantId, $mediaId);
        $path = $this->path($tenantId, $key);
        if (is_link($path) || is_file($path)) {
            throw new MediaRejectedException('The photograph could not be stored.');
        }
        $folder = dirname($path);
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new MediaRejectedException('The photograph could not be stored.');
        }
        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new MediaRejectedException('The photograph could not be stored.');
        }
        chmod($path, 0600);

        return $key;
    }

    public function read(string $tenantId, string $key): string
    {
        $path = $this->path($tenantId, $key);
        if (is_link($path) || !is_file($path)) {
            throw new MediaNotFoundException('The photograph was not found.');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            throw new MediaNotFoundException('The photograph was not found.');
        }

        return $bytes;
    }

    public function erase(string $tenantId, string $key): void
    {
        $path = $this->path($tenantId, $key);
        if (is_link($path)) {
            throw new MediaRejectedException('The photograph could not be removed.');
        }
        if (!is_file($path)) {
            return;
        }
        $size = filesize($path);
        $handle = fopen($path, 'cb');
        if ($handle !== false && is_int($size) && $size > 0) {
            $written = 0;
            $chunk = random_bytes(min($size, 8192));
            while ($written < $size) {
                $step = fwrite($handle, $chunk, min(strlen($chunk), $size - $written));
                if ($step === false || $step === 0) {
                    break;
                }
                $written += $step;
            }
            fflush($handle);
            fclose($handle);
        } elseif ($handle !== false) {
            fclose($handle);
        }
        if (!unlink($path) && is_file($path)) {
            throw new MediaRejectedException('The photograph could not be removed.');
        }
    }

    private function path(string $tenantId, string $key): string
    {
        $mediaId = MediaKey::assert($tenantId, $key);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new MediaRejectedException('The photograph could not be stored.');
        }
        $root = realpath($this->directory);
        if ($root === false) {
            throw new MediaRejectedException('The photograph could not be stored.');
        }
        $folder = $root.DIRECTORY_SEPARATOR.$tenantId;
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new MediaRejectedException('The photograph could not be stored.');
        }
        $folderReal = realpath($folder);
        if ($folderReal === false || ($folderReal !== $root && !str_starts_with($folderReal, $root.DIRECTORY_SEPARATOR))) {
            throw new MediaRejectedException('The media reference is not usable.');
        }

        return $folderReal.DIRECTORY_SEPARATOR.$mediaId;
    }
}
