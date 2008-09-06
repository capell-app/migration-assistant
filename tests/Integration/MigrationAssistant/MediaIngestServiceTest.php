<?php

declare(strict_types=1);

use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Services\Import\MediaIngestService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;

function makeMediaArchive(string $hex, string $fileName, string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'capell-migration-assistant-') . '.zip';
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $entry = sprintf('media/%s.%s', $hex, pathinfo($fileName, PATHINFO_EXTENSION));
    $archive->addFromString($entry, $bytes);
    $archive->close();

    return $path;
}

function migrationArchivePngBytes(): string
{
    // Freshly encoded PNG: the previous fixture had a corrupt IDAT checksum.
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC', true);
    throw_unless(is_string($bytes), RuntimeException::class);

    return $bytes;
}

beforeEach(function (): void {
    Queue::fake();
});

it('accepts read-through media storage with an S3 primary', function (string $driver): void {
    $bytes = migrationArchivePngBytes();
    $adapter = Mockery::mock(FlysystemAdapter::class);
    $adapter->shouldReceive('writeStream')->once()->withArgs(
        static fn (string $path, mixed $stream, Config $config): bool => str_ends_with($path, '/hero.png') && is_resource($stream) && stream_get_contents($stream) === $bytes,
    );
    $primary = new FilesystemAdapter(new Filesystem($adapter), $adapter, ['driver' => $driver, 'root' => 'media-prefix']);
    Storage::set('import-primary', $primary);
    Storage::fake('import-fallback');
    config()->set('filesystems.disks.import-read-through', [
        'driver' => 'read-through', 'primary' => 'import-primary', 'fallback' => 'import-fallback',
    ]);
    config()->set('media-library.disk_name', 'import-read-through');
    $archive = makeMediaArchive(hash('sha256', $bytes), 'hero.png', $bytes);

    try {
        $id = (new MediaIngestService)->ingest($archive, [
            'checksum' => 'sha256-' . hash('sha256', $bytes), 'file_name' => 'hero.png',
        ], Page::factory()->create());
        expect(Media::query()->findOrFail($id)->disk)->toBe('import-read-through');
    } finally {
        unlink($archive);
    }
})->with(['s3', 'custom-object-store']);

it('checks the actual local primary of a read-through disk despite its overridden root', function (bool $inline): void {
    $primary = ['driver' => 'local', 'root' => public_path('import-read-through')];
    config()->set('filesystems.disks.import-primary', $primary);
    Storage::fake('import-fallback');
    config()->set('filesystems.disks.import-read-through', [
        'driver' => 'read-through',
        'primary' => $inline ? $primary : 'import-primary',
        'fallback' => 'import-fallback',
        'root' => storage_path('app/overridden-import-root'),
    ]);
    config()->set('media-library.disk_name', 'import-read-through');
    $bytes = migrationArchivePngBytes();
    $archive = makeMediaArchive(hash('sha256', $bytes), 'hero.png', $bytes);

    try {
        expect(fn (): int|string => (new MediaIngestService)->ingest($archive, [
            'checksum' => 'sha256-' . hash('sha256', $bytes), 'file_name' => 'hero.png',
        ], Page::factory()->create()))->toThrow(RuntimeException::class, 'Unsafe media storage path');
        expect(Media::query()->count())->toBe(0);
    } finally {
        unlink($archive);
    }
})->with([false, true]);

it('stores media on a read-through disk with a safe local primary', function (): void {
    $primary = Storage::fake('import-primary');
    Storage::fake('import-fallback');
    config()->set('filesystems.disks.import-read-through', [
        'driver' => 'read-through', 'primary' => 'import-primary', 'fallback' => 'import-fallback',
    ]);
    config()->set('media-library.disk_name', 'import-read-through');
    $bytes = migrationArchivePngBytes();
    $archive = makeMediaArchive(hash('sha256', $bytes), 'hero.png', $bytes);

    try {
        $id = (new MediaIngestService)->ingest($archive, [
            'checksum' => 'sha256-' . hash('sha256', $bytes), 'file_name' => 'hero.png',
        ], Page::factory()->create());
        expect($primary->get(Media::query()->findOrFail($id)->getPathRelativeToRoot()))->toBe($bytes);
    } finally {
        unlink($archive);
    }
});

it('ingests a new media binary and creates a Media row', function (): void {
    Storage::fake('public');

    $bytes = migrationArchivePngBytes();
    $hex = hash('sha256', $bytes);
    $archivePath = makeMediaArchive($hex, 'hero.png', $bytes);
    $owner = Page::factory()->create();

    $descriptor = [
        'ref' => 'media:777',
        'checksum' => 'sha256-' . $hex,
        'file_name' => 'hero.png',
        'mime_type' => 'image/png',
        'collection_name' => 'hero',
    ];

    $mediaId = (new MediaIngestService)->ingest($archivePath, $descriptor, $owner);

    $row = Media::query()->whereKey($mediaId)->firstOrFail();
    expect($row->getAttribute('file_name'))->toBe('hero.png')
        ->and($row->getAttribute('mime_type'))->toBe('image/png')
        ->and($row->getAttribute('collection_name'))->toBe('hero')
        ->and((int) $row->getAttribute('size'))->toBe(strlen($bytes))
        ->and($row->getAttribute('custom_properties')['checksum'] ?? null)->toBe('sha256-' . $hex);

    Storage::disk('public')->assertExists($row->getPathRelativeToRoot());
    Storage::disk('public')->assertMissing('migration-assistant/ingested/' . $hex . '.png');

    @unlink($archivePath);
});

it('returns the existing media id on checksum match (idempotent)', function (): void {
    Storage::fake('public');

    $bytes = migrationArchivePngBytes();
    $hex = hash('sha256', $bytes);
    $archivePath = makeMediaArchive($hex, 'img.png', $bytes);
    $owner = Page::factory()->create();

    $existing = new Media;
    $existing->forceFill([
        'model_type' => $owner->getMorphClass(),
        'model_id' => $owner->getKey(),
        'collection_name' => 'default',
        'name' => 'img',
        'file_name' => 'img.png',
        'mime_type' => 'image/png',
        'disk' => 'public',
        'conversions_disk' => 'public',
        'size' => strlen($bytes),
        'manipulations' => [],
        'custom_properties' => ['checksum' => 'sha256-' . $hex],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 1,
    ])->save();
    Storage::disk('public')->put($existing->getPathRelativeToRoot(), $bytes);

    $descriptor = [
        'ref' => 'media:1',
        'checksum' => 'sha256-' . $hex,
        'file_name' => 'img.png',
        'mime_type' => 'image/png',
    ];

    $resolvedId = (new MediaIngestService)->ingest($archivePath, $descriptor, $owner);

    expect($resolvedId)->toBe($existing->getKey())
        ->and(Media::query()->count())->toBe(1);

    @unlink($archivePath);
});

it('does not reuse checksum matches whose stored file is missing', function (): void {
    Storage::fake('public');

    $bytes = migrationArchivePngBytes();
    $hex = hash('sha256', $bytes);
    $archivePath = makeMediaArchive($hex, 'recovered.png', $bytes);
    $owner = Page::factory()->create();

    $existing = new Media;
    $existing->forceFill([
        'model_type' => $owner->getMorphClass(),
        'model_id' => $owner->getKey(),
        'collection_name' => 'default',
        'name' => 'missing',
        'file_name' => 'missing.png',
        'mime_type' => 'image/png',
        'disk' => 'public',
        'conversions_disk' => 'public',
        'size' => strlen($bytes),
        'manipulations' => [],
        'custom_properties' => ['checksum' => 'sha256-' . $hex],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 1,
    ])->save();

    $descriptor = [
        'ref' => 'media:missing-file',
        'checksum' => 'sha256-' . $hex,
        'file_name' => 'recovered.png',
        'mime_type' => 'image/png',
    ];

    $resolvedId = (new MediaIngestService)->ingest($archivePath, $descriptor, $owner);

    expect($resolvedId)->not->toBe($existing->getKey())
        ->and(Media::query()->count())->toBe(2);

    $row = Media::query()->whereKey($resolvedId)->firstOrFail();
    Storage::disk('public')->assertExists($row->getPathRelativeToRoot());

    @unlink($archivePath);
});

it('removes the media row when the disk write fails', function (): void {
    config()->set('media-library.disk_name', 'broken');
    $disk = Mockery::mock(Storage::fake('broken'));
    $disk->shouldReceive('put')->once()->andReturn(false);

    $bytes = migrationArchivePngBytes();
    $hex = hash('sha256', $bytes);
    $archivePath = makeMediaArchive($hex, 'broken.png', $bytes);
    $owner = Page::factory()->create();

    Storage::shouldReceive('disk')
        ->twice()
        ->with('broken')
        ->andReturn($disk);

    $descriptor = [
        'ref' => 'media:broken-disk',
        'checksum' => 'sha256-' . $hex,
        'file_name' => 'broken.png',
        'mime_type' => 'image/png',
    ];

    expect(fn (): int|string => (new MediaIngestService)->ingest($archivePath, $descriptor, $owner))
        ->toThrow(RuntimeException::class, 'Failed to store media binary');

    expect(Media::query()->count())->toBe(0);

    @unlink($archivePath);
});

it('throws when the archive entry is missing', function (): void {
    Storage::fake('public');

    $path = tempnam(sys_get_temp_dir(), 'capell-migration-assistant-') . '.zip';
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString('placeholder.txt', 'x');
    $archive->close();

    $owner = Page::factory()->create();

    $descriptor = [
        'ref' => 'media:404',
        'checksum' => 'sha256-' . str_repeat('0', 64),
        'file_name' => 'missing.png',
        'mime_type' => 'image/png',
    ];

    expect(fn (): int|string => (new MediaIngestService)->ingest($path, $descriptor, $owner))
        ->toThrow(RuntimeException::class, 'missing from archive');

    @unlink($path);
});

it('throws when archive media bytes do not match the declared checksum', function (): void {
    Storage::fake('public');

    $declaredBytes = 'expected-binary-contents';
    $actualBytes = 'tampered-binary-contents';
    $hex = hash('sha256', $declaredBytes);
    $archivePath = makeMediaArchive($hex, 'tampered.png', $actualBytes);
    $owner = Page::factory()->create();

    $descriptor = [
        'ref' => 'media:tampered',
        'checksum' => 'sha256-' . $hex,
        'file_name' => 'tampered.png',
        'mime_type' => 'image/png',
    ];

    expect(fn (): int|string => (new MediaIngestService)->ingest($archivePath, $descriptor, $owner))
        ->toThrow(RuntimeException::class, 'checksum mismatch');

    @unlink($archivePath);
});

it('rejects oversized archive media before storing it', function (): void {
    Storage::fake('public');
    config()->set('migration-assistant.limits.max_media_bytes', 5);

    $bytes = 'oversized-binary';
    $hex = hash('sha256', $bytes);
    $archivePath = makeMediaArchive($hex, 'large.png', $bytes);
    $owner = Page::factory()->create();

    $descriptor = [
        'ref' => 'media:large',
        'checksum' => 'sha256-' . $hex,
        'file_name' => 'large.png',
        'mime_type' => 'image/png',
    ];

    expect(fn (): int|string => (new MediaIngestService)->ingest($archivePath, $descriptor, $owner))
        ->toThrow(RuntimeException::class, 'exceeds the maximum import size');

    expect(Media::query()->count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBe([]);

    @unlink($archivePath);
});

it('rejects oversized archive media before reading the entry contents', function (): void {
    Storage::fake('public');
    config()->set('migration-assistant.limits.max_media_bytes', 5);

    $bytes = 'oversized-media-bytes';
    $hex = hash('sha256', $bytes);
    $archivePath = makeMediaArchive($hex, 'oversized.png', $bytes);
    $owner = Page::factory()->create();

    $descriptor = [
        'ref' => 'media:oversized',
        'checksum' => 'sha256-' . $hex,
        'file_name' => 'oversized.png',
        'mime_type' => 'image/png',
    ];

    expect(fn (): int|string => (new MediaIngestService)->ingest($archivePath, $descriptor, $owner))
        ->toThrow(RuntimeException::class, 'exceeds the maximum import size');

    expect(Media::query()->count())->toBe(0);

    @unlink($archivePath);
});

it('derives the stored extension and MIME from media content', function (): void {
    Storage::fake('public');
    $bytes = migrationArchivePngBytes();
    $hex = hash('sha256', $bytes);
    $archive = makeMediaArchive($hex, 'photo.php', $bytes);

    try {
        $id = (new MediaIngestService)->ingest($archive, [
            'checksum' => 'sha256-' . $hex,
            'file_name' => 'photo.php',
            'mime_type' => 'application/x-httpd-php',
        ], Page::factory()->create());
        $media = Media::query()->findOrFail($id);
        expect($media->file_name)->toBe('photo.png')
            ->and($media->mime_type)->toBe('image/png');
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    } finally {
        unlink($archive);
    }
});

it('stores disguised AVIF names as canonical static media outside the web root', function (string $fileName, string $storedName, bool $opaquePayload): void {
    $disk = Storage::fake('public');
    $bytes = file_get_contents(__DIR__ . '/../../Fixtures/media/idat.avif');
    throw_unless(is_string($bytes), RuntimeException::class);
    if ($opaquePayload) {
        $bytes .= pack('N', 21) . 'free' . '<?php echo 1;';
    }
    $archive = makeMediaArchive(hash('sha256', $bytes), $fileName, $bytes);

    try {
        $id = (new MediaIngestService)->ingest($archive, [
            'checksum' => 'sha256-' . hash('sha256', $bytes),
            'file_name' => $fileName,
            'mime_type' => 'application/x-httpd-php',
        ], Page::factory()->create());
        $media = Media::query()->findOrFail($id);
        $path = $media->getPathRelativeToRoot();
        expect($media->file_name)->toBe($storedName)
            ->and($media->mime_type)->toBe('image/avif')
            ->and($path)->toBe($id . '/' . $storedName)
            ->and($disk->get($path))->toBe($bytes)
            ->and($media->getUrl())->toBe($disk->url($path))
            ->and($media->getUrl())->toEndWith('/' . $storedName)
            ->and($disk->path($path))->toStartWith(storage_path() . '/')
            ->and($disk->path($path))->not->toStartWith(public_path() . '/');
    } finally {
        unlink($archive);
    }
})->with([
    ['x.php', 'x.avif'],
    ['x.avif.php', 'x-avif.avif'],
    ['../../x.avif.php', 'x-avif.avif'],
])->with([false, true]);

it('refuses media disks rooted in executable application paths', function (string $root): void {
    $path = match ($root) {
        'web root' => public_path('imported-media'),
        'application code' => base_path('app/imported-media'),
        'compiled views' => storage_path('framework/views/imported-media'),
        'configured compiled views' => config()->string('view.compiled') . '/imported-media',
        default => throw new InvalidArgumentException('Unknown unsafe root.'),
    };
    config()->set('media-library.disk_name', 'unsafe-import');
    config()->set('filesystems.disks.unsafe-import', ['driver' => 'local', 'root' => $path]);
    $bytes = migrationArchivePngBytes();
    $archive = makeMediaArchive(hash('sha256', $bytes), 'x.php', $bytes);

    try {
        expect(fn (): int|string => (new MediaIngestService)->ingest($archive, [
            'checksum' => 'sha256-' . hash('sha256', $bytes), 'file_name' => 'x.php',
        ], Page::factory()->create()))->toThrow(RuntimeException::class, 'Unsafe media storage path');
        expect(Media::query()->count())->toBe(0);
    } finally {
        unlink($archive);
    }
})->with(['web root', 'application code', 'compiled views', 'configured compiled views']);

it('refuses a symlink that redirects the generated media path outside the disk', function (): void {
    $disk = Storage::fake('public');
    config()->set('media-library.prefix', 'escape');
    $link = $disk->path('escape');
    expect(symlink(public_path(), $link))->toBeTrue();
    $bytes = migrationArchivePngBytes();
    $archive = makeMediaArchive(hash('sha256', $bytes), 'x.php', $bytes);

    try {
        expect(fn (): int|string => (new MediaIngestService)->ingest($archive, [
            'checksum' => 'sha256-' . hash('sha256', $bytes), 'file_name' => 'x.php',
        ], Page::factory()->create()))->toThrow(RuntimeException::class, 'Unsafe media storage path');
        expect(Media::query()->count())->toBe(0);
    } finally {
        unlink($archive);
        unlink($link);
    }
});

it('refuses unsafe intermediate extensions before reusing existing media', function (): void {
    $disk = Storage::fake('public');
    $bytes = migrationArchivePngBytes();
    $archive = makeMediaArchive(hash('sha256', $bytes), 'x.php', $bytes);
    $owner = Page::factory()->create();
    $descriptor = ['checksum' => 'sha256-' . hash('sha256', $bytes), 'file_name' => 'x.php'];

    try {
        $service = new MediaIngestService;
        $media = Media::query()->findOrFail($service->ingest($archive, $descriptor, $owner));
        $original = $media->getPathRelativeToRoot();
        $media->update(['file_name' => 'legacy.php.png']);
        $legacy = $media->getPathRelativeToRoot();
        $disk->move($original, $legacy);

        expect(fn (): int|string => $service->ingest($archive, $descriptor, $owner))
            ->toThrow(RuntimeException::class, 'Unsafe media storage path');
        expect(Media::query()->count())->toBe(1)
            ->and($disk->get($legacy))->toBe($bytes);
    } finally {
        unlink($archive);
    }
});

it('refuses generated media paths that escape the configured disk', function (): void {
    Storage::fake('public');
    config()->set('media-library.prefix', '../escaped-import');
    $bytes = migrationArchivePngBytes();
    $archive = makeMediaArchive(hash('sha256', $bytes), 'x.php', $bytes);

    try {
        expect(fn (): int|string => (new MediaIngestService)->ingest($archive, [
            'checksum' => 'sha256-' . hash('sha256', $bytes), 'file_name' => 'x.php',
        ], Page::factory()->create()))->toThrow(RuntimeException::class, 'Unsafe media storage path');
        expect(Media::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    } finally {
        unlink($archive);
    }
});

it('refuses active archive content even when described as a PNG', function (string $fileName, string $bytes): void {
    Storage::fake('public');
    $hex = hash('sha256', $bytes);
    $archive = makeMediaArchive($hex, $fileName, $bytes);

    try {
        expect(fn (): int|string => (new MediaIngestService)->ingest($archive, [
            'checksum' => 'sha256-' . $hex,
            'file_name' => $fileName,
            'mime_type' => 'image/png',
        ], Page::factory()->create()))->toThrow(RuntimeException::class, 'Unsupported media content');
        expect(Media::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    } finally {
        unlink($archive);
    }
})->with([
    ['shell.php', '<?php echo "unsafe";'],
    ['shell.phtml', '<?= "unsafe" ?>'],
    ['image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    ['image.png', '<html><script>alert(1)</script></html>'],
    ['polyglot.png', migrationArchivePngBytes() . '<?php echo "unsafe";'],
    'appended HTML beyond MIME sample' => ['html-polyglot.png', migrationArchivePngBytes() . str_repeat("\0", 2 * 1024 * 1024) . '<HtMl><body>unsafe</body></HtMl>'],
    'appended SVG beyond MIME sample' => ['svg-polyglot.png', migrationArchivePngBytes() . str_repeat("\0", 2 * 1024 * 1024) . '<SvG xmlns="http://www.w3.org/2000/svg"><path /></SvG>'],
    'appended script beyond MIME sample' => ['script-polyglot.png', migrationArchivePngBytes() . str_repeat("\0", 2 * 1024 * 1024) . '<script>alert(1)</script>'],
    'HTML fragment beyond MIME sample' => ['fragment-polyglot.png', migrationArchivePngBytes() . str_repeat("\0", 2 * 1024 * 1024) . '<img src=x onerror=alert(1)>'],
    'HTML across a read boundary' => ['html-boundary.png', str_pad(migrationArchivePngBytes(), 1024 * 1024 - 3, "\0") . '<html>unsafe</html>'],
    'SVG across a read boundary' => ['svg-boundary.png', str_pad(migrationArchivePngBytes(), 1024 * 1024 - 2, "\0") . '<svg>unsafe</svg>'],
    'PHP across a read boundary' => ['php-boundary.png', str_pad(migrationArchivePngBytes(), 1024 * 1024 - 1, "\0") . '<?php echo "unsafe";'],
]);

it('does not deduplicate media owned by another page or importing session', function (): void {
    Storage::fake('public');
    $bytes = migrationArchivePngBytes();
    $archive = makeMediaArchive(hash('sha256', $bytes), 'hero.png', $bytes);
    $foreignOwner = Page::factory()->create();
    $owner = Page::factory()->create();
    $descriptor = ['checksum' => 'sha256-' . hash('sha256', $bytes), 'file_name' => 'hero.png'];

    try {
        $service = new MediaIngestService;
        $foreignId = $service->ingest($archive, $descriptor, $foreignOwner);
        $localId = $service->ingest($archive, $descriptor, $owner);
        expect($localId)->not->toBe($foreignId)
            ->and($service->ingest($archive, $descriptor, $owner))->toBe($localId)
            ->and(Media::query()->findOrFail($foreignId)->model_id)->toBe($foreignOwner->getKey());
    } finally {
        unlink($archive);
    }
});
