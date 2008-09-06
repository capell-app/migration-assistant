<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\Core\Models\Media;
use Capell\MigrationAssistant\Services\Import\MediaContainerValidator;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/**
 * The shared boundary for archive and remote imports: validate the bytes,
 * canonicalise the filename and check the real destination before writing.
 */
final class StoreImportedMediaAction
{
    use AsFake;
    use AsObject;

    /** @param resource $stream */
    public function handle(
        mixed $stream,
        string $fileName,
        string $checksum,
        int $size,
        Model $owner,
        string $collectionName = 'default',
    ): Media {
        [$mimeType, $extension] = $this->mediaType($stream);
        $existing = Media::query()
            ->where('model_type', $owner->getMorphClass())
            ->where('model_id', $owner->getKey())
            ->where('custom_properties->checksum', $checksum)
            ->first();

        if ($existing instanceof Media && $existing->mime_type === $mimeType
            && pathinfo($existing->file_name, PATHINFO_EXTENSION) === $extension
            && $this->mediaFileExists($existing)) {
            return $existing;
        }

        $diskConfig = config('media-library.disk_name', 'public');
        $disk = is_string($diskConfig) ? $diskConfig : 'public';
        $safeFileName = $this->safeFileName($fileName, substr($checksum, strlen('sha256-')), $extension);

        $media = new Media;
        $media->forceFill([
            'model_type' => $owner->getMorphClass(),
            'model_id' => $owner->getKey(),
            'collection_name' => $collectionName,
            'name' => pathinfo($safeFileName, PATHINFO_FILENAME),
            'file_name' => $safeFileName,
            'mime_type' => $mimeType,
            'disk' => $disk,
            'conversions_disk' => $disk,
            'size' => $size,
            'manipulations' => [],
            'custom_properties' => ['checksum' => $checksum],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ])->save();

        try {
            $path = $this->safeStoragePath($media);
            rewind($stream);
            $stored = Storage::disk($disk)->put($path, $stream);

            throw_unless($stored === true, RuntimeException::class, sprintf(
                'Failed to store media binary [%s].',
                $fileName,
            ));
        } catch (Throwable $throwable) {
            $media->deleteQuietly();

            throw $throwable;
        }

        return $media;
    }

    private function mediaFileExists(Media $media): bool
    {
        $disk = $media->getAttribute('disk');
        $disk = is_string($disk) && $disk !== '' ? $disk : config('media-library.disk_name', 'public');
        $disk = is_string($disk) && $disk !== '' ? $disk : 'public';

        return Storage::disk($disk)->exists($this->safeStoragePath($media));
    }

    private function safeStoragePath(Media $media): string
    {
        $path = $media->getPathRelativeToRoot();
        $segments = explode('/', $path);
        throw_if(
            $path === '' || str_contains($path, '\\') || str_contains($path, "\0")
            || array_intersect($segments, ['', '.', '..']) !== []
            || basename($path) !== $media->file_name
            || preg_match('/\A[a-zA-Z0-9_-]+\.[a-z0-9]+\z/D', $media->file_name) !== 1,
            RuntimeException::class,
            'Unsafe media storage path.',
        );

        $filesystem = Storage::disk($media->disk);
        throw_unless($filesystem instanceof FilesystemAdapter, RuntimeException::class, 'Unsafe media storage path.');
        $location = $this->backingStorageLocation($filesystem, $path, $media->disk);
        if ($location === null) {
            return $path;
        }

        [$rootPath, $destinationPath] = $location;
        $root = $this->resolvedLocalPath($rootPath);
        $destination = $this->resolvedLocalPath($destinationPath);
        $application = $this->resolvedLocalPath(base_path());
        $storage = $this->resolvedLocalPath(storage_path());
        $public = $this->resolvedLocalPath(public_path());
        $compiledViews = config('view.compiled');
        $compiledViews = is_string($compiledViews) && $compiledViews !== ''
            ? $this->resolvedLocalPath($compiledViews) : $this->resolvedLocalPath(storage_path('framework/views'));
        $defaultViews = $this->resolvedLocalPath(storage_path('framework/views'));
        $inside = static fn (string $candidate, string $directory): bool => $candidate === $directory
            || str_starts_with($candidate, $directory . '/');

        // Resolve existing ancestors so a disk prefix or symlink cannot redirect
        // uploads into PHP source, the public document root or compiled Blade.
        throw_if(
            ! $inside($destination, $root) || $inside($destination, $public)
            || $inside($destination, $compiledViews) || $inside($destination, $defaultViews)
            || ($inside($destination, $application) && ! $inside($destination, $storage)),
            RuntimeException::class,
            'Unsafe media storage path.',
        );

        return $path;
    }

    /**
     * @param  array<string, mixed>|null  $declaredConfig
     * @return array{string, string}|null
     */
    private function backingStorageLocation(
        FilesystemAdapter $filesystem,
        string $path,
        ?string $diskName = null,
        int $depth = 0,
        ?array $declaredConfig = null,
    ): ?array {
        throw_if($depth > 32, RuntimeException::class, 'Unsafe media storage path.');
        $config = $filesystem->getConfig();
        $disks = config('filesystems.disks', []);
        $declared = is_array($disks) && $diskName !== null ? ($disks[$diskName] ?? null) : $declaredConfig;
        $decorator = is_array($declared) ? $declared : $config;
        $driver = $decorator['driver'] ?? null;
        $backing = $driver === 'read-through' ? ($decorator['primary'] ?? null) : ($decorator['disk'] ?? null);

        if ($backing !== null) {
            // Read-through inherits the primary's config, but only its own
            // prefix wraps writes. Scoped and custom decorators name a disk.
            $prefix = $decorator['prefix'] ?? '';
            throw_unless(is_string($prefix), RuntimeException::class, 'Unsafe media storage path.');
            $path = $prefix === '' ? $path : rtrim($prefix, '/') . '/' . $path;

            $inlineConfig = null;
            if (is_string($backing)) {
                $next = Storage::disk($backing);
            } else {
                throw_unless(is_array($backing), RuntimeException::class, 'Unsafe media storage path.');
                $inlineConfig = [];
                foreach ($backing as $key => $value) {
                    throw_unless(is_string($key), RuntimeException::class, 'Unsafe media storage path.');
                    $inlineConfig[$key] = $value;
                }
                $next = Storage::build($inlineConfig);
            }

            throw_unless($next instanceof FilesystemAdapter, RuntimeException::class, 'Unsafe media storage path.');

            return $this->backingStorageLocation(
                $next,
                $path,
                is_string($backing) ? $backing : null,
                $depth + 1,
                $inlineConfig,
            );
        }

        // Check the adapter rather than a driver allow-list: object stores and
        // custom remote adapters have keys, not absolute local destinations.
        if (! $filesystem->getAdapter() instanceof LocalFilesystemAdapter) {
            return null;
        }

        return [$filesystem->path(''), $filesystem->path($path)];
    }

    private function resolvedLocalPath(string $path): string
    {
        throw_if(
            ! str_starts_with($path, '/') || str_contains($path, "\0")
            || array_intersect(explode('/', $path), ['.', '..']) !== [],
            RuntimeException::class,
            'Unsafe media storage path.',
        );
        $path = rtrim($path, '/') ?: '/';
        $suffix = [];
        while (($resolved = realpath($path)) === false) {
            throw_if(is_link($path), RuntimeException::class, 'Unsafe media storage path.');
            array_unshift($suffix, basename($path));
            $path = dirname($path);
        }

        return $suffix === [] ? $resolved : rtrim($resolved, '/') . '/' . implode('/', $suffix);
    }

    /** @param resource $stream
     * @return array{string, string}
     */
    private function mediaType(mixed $stream): array
    {
        rewind($stream);
        $sample = fread($stream, 1024 * 1024);
        $mime = is_string($sample) ? new finfo(FILEINFO_MIME_TYPE)->buffer($sample) : false;
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/x-wav', 'audio/wav' => 'wav',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => null,
        };
        throw_unless(is_string($mime) && $extension !== null, RuntimeException::class, 'Unsupported media content.');

        (new MediaContainerValidator)->validate($stream, $mime);

        return [$mime, $extension];
    }

    private function safeFileName(string $fileName, string $hex, string $extension): string
    {
        $basename = basename(str_replace('\\', '/', $fileName));
        $stem = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($basename, PATHINFO_FILENAME));
        $stem = trim((string) $stem, '-');

        return ($stem === '' ? $hex : $stem) . '.' . $extension;
    }
}
