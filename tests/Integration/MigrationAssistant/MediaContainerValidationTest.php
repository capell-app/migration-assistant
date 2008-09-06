<?php

declare(strict_types=1);

use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Services\Import\MediaContainerValidator;
use Capell\MigrationAssistant\Services\Import\MediaIngestService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

function migrationContainerBytes(string $format): string
{
    $image = imagecreatetruecolor(1, 1);
    throw_unless($image instanceof GdImage, RuntimeException::class);
    ob_start();
    match ($format) {
        'png' => imagepng($image),
        'jpeg' => imagejpeg($image),
        'gif' => imagegif($image),
        'webp' => imagewebp($image),
        default => throw new InvalidArgumentException('Unsupported raster fixture format.'),
    };
    $bytes = ob_get_clean();
    throw_unless(is_string($bytes), RuntimeException::class);

    return $bytes;
}

function migrationEncodedMediaBytes(string $name): string
{
    $bytes = file_get_contents(__DIR__ . '/../../Fixtures/media/' . $name);
    throw_unless(is_string($bytes), RuntimeException::class);

    return $bytes;
}

function validateMigrationContainer(string $bytes, string $mime): void
{
    $stream = tmpfile();
    throw_unless(is_resource($stream), RuntimeException::class);
    try {
        fwrite($stream, $bytes);
        (new MediaContainerValidator)->validate($stream, $mime);
    } finally {
        fclose($stream);
    }
}

function migrationAvifBox(string $type, string $payload): string
{
    return pack('N', strlen($payload) + 8) . $type . $payload;
}

/** @param list<array{int, int}> $extents */
function migrationAvifIloc(
    int $version,
    int $method,
    array $extents,
    int $offsetSize = 4,
    int $lengthSize = 4,
    int $baseOffsetSize = 0,
    int $indexSize = 0,
    int $baseOffset = 0,
): string {
    $unsigned = static fn (int $value, int $size): string => match ($size) {
        0 => '',
        4 => pack('N', $value),
        8 => pack('NN', $value >> 32, $value & 0xFFFFFFFF),
        default => throw new InvalidArgumentException('Unsupported fixture integer size.'),
    };
    $idSize = $version === 2 ? 4 : 2;
    $payload = chr($version) . "\0\0\0" . chr(($offsetSize << 4) | $lengthSize)
        . chr(($baseOffsetSize << 4) | $indexSize)
        . ($idSize === 4 ? pack('N', 1) : pack('n', 1))
        . ($idSize === 4 ? pack('N', 65537) : pack('n', 1))
        . ($version > 0 ? pack('n', $method) : '')
        . pack('n', 0) . $unsigned($baseOffset, $baseOffsetSize) . pack('n', count($extents));
    foreach ($extents as [$offset, $length]) {
        $payload .= ($version > 0 ? $unsigned(1, $indexSize) : '')
            . $unsigned($offset, $offsetSize) . $unsigned($length, $lengthSize);
    }

    return migrationAvifBox('iloc', $payload);
}

function migrationAvifBytes(string $locations, string $payload, bool $idat): string
{
    return migrationAvifBox('ftyp', 'avif' . pack('N', 0) . 'avifmif1')
        . migrationAvifBox('meta', "\0\0\0\0" . $locations . ($idat ? migrationAvifBox('idat', $payload) : ''))
        . ($idat ? '' : migrationAvifBox('mdat', $payload));
}

function migrationAvifItemTable(int $items, int $method = 1, int $baseOffset = 0): string
{
    $payload = "\x02\0\0\0\x44\0" . pack('N', $items);
    for ($id = 1; $id <= $items; $id++) {
        $payload .= pack('NnnnNN', $id, $method, 0, 1, $baseOffset + $id - 1, 1);
    }

    return migrationAvifBox('iloc', $payload);
}

it('accepts AVIF animation samples referenced by tracks beyond the first iloc frame', function (): void {
    // libavif references the first frame via iloc and later frames via track samples.
    $locations = migrationAvifIloc(0, 0, [[0, 4]]);
    $start = 44 + strlen($locations);
    $locations = migrationAvifIloc(0, 0, [[$start, 4]]);
    $bytes = migrationAvifBytes($locations, 'one!two!', false);
    $bytes = substr_replace($bytes, 'avis', 8, 4);
    $samples = migrationAvifBox('stco', "\0\0\0\0" . pack('NN', 1, $start))
        . migrationAvifBox('stsz', "\0\0\0\0" . pack('NN', 4, 2))
        . migrationAvifBox('stsc', "\0\0\0\0" . pack('NNNN', 1, 1, 2, 1));
    $bytes .= migrationAvifBox('moov', migrationAvifBox('trak', migrationAvifBox(
        'mdia',
        migrationAvifBox('minf', migrationAvifBox('stbl', $samples)),
    )));

    expect(fn () => validateMigrationContainer($bytes, 'image/avif'))->not->toThrow(RuntimeException::class);
});

it('accepts AVIF grids with at least 32 by 32 tiles plus a descriptor', function (int $side, bool $idat): void {
    $tiles = $side * $side;
    $locations = migrationAvifItemTable($tiles + 1);
    $base = $idat ? 0 : 44 + strlen($locations);
    $locations = migrationAvifItemTable($tiles + 1, $idat ? 1 : 0, $base);
    $locations = substr_replace($locations, pack('NN', $base + $tiles, 8), -8, 8);
    $payload = str_repeat('t', $tiles) . "\0\0" . chr($side - 1) . chr($side - 1) . pack('nn', $side, $side);

    expect(fn () => validateMigrationContainer(migrationAvifBytes($locations, $payload, $idat), 'image/avif'))
        ->not->toThrow(RuntimeException::class);
})->with([32, 33])->with([true, false]);

function ingestMigrationContainer(string $bytes, string $extension = 'png'): int|string
{
    $hex = hash('sha256', $bytes);
    $path = tempnam(sys_get_temp_dir(), 'media-container-');
    throw_unless(is_string($path), RuntimeException::class);
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString('media/' . $hex . '.' . $extension, $bytes);
    $archive->close();

    try {
        return (new MediaIngestService)->ingest($path, [
            'checksum' => 'sha256-' . $hex,
            'file_name' => 'image.' . $extension,
            'mime_type' => 'image/png',
        ], Page::factory()->create());
    } finally {
        unlink($path);
    }
}

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('public');
});

it('accepts real screenshots containing tag-like compressed bytes', function (string $name): void {
    $path = dirname(__DIR__, 5) . '/packages/live-chat/docs/screenshots/' . $name;
    $bytes = file_get_contents($path);
    expect($bytes)->toBeString();
    throw_unless(is_string($bytes), RuntimeException::class);
    $id = ingestMigrationContainer($bytes);
    $media = Media::query()->findOrFail($id);
    expect(Storage::disk('public')->get($media->getPathRelativeToRoot()))->toBe($bytes);
})->with(['live-chat-installations-dark.png', 'live-chat-conversation-detail-dark.png']);

it('accepts complete raster containers', function (string $format): void {
    $bytes = migrationContainerBytes($format);
    $id = ingestMigrationContainer($bytes, $format);
    $media = Media::query()->findOrFail($id);
    expect($media->mime_type)->toBe('image/' . $format)
        ->and($media->file_name)->toBe('image.' . ($format === 'jpeg' ? 'jpg' : $format));
})->with(['png', 'jpeg', 'gif', 'webp']);

it('rejects every byte after the raster container end', function (string $format, string $suffix): void {
    expect(fn (): int|string => ingestMigrationContainer(migrationContainerBytes($format) . $suffix, $format))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with(['png', 'jpeg', 'gif', 'webp'])->with([
    'HTML' => '<html><script>alert(1)</script></html>',
    'SVG' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
    'PHP' => '<?php echo "unsafe";',
    'non-markup suffix' => "\0",
    'second container' => fn (): string => migrationContainerBytes('png'),
]);

it('rejects truncated raster containers', function (string $format): void {
    expect(fn (): int|string => ingestMigrationContainer(substr(migrationContainerBytes($format), 0, -1), $format))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
})->with(['png', 'jpeg', 'gif', 'webp']);

it('rejects corrupt container lengths and required structure', function (string $format): void {
    $bytes = migrationContainerBytes($format);
    $bytes = match ($format) {
        'png' => substr_replace($bytes, pack('N', 0x7FFFFFFF), 8, 4),
        'jpeg' => substr_replace($bytes, "\0\1", 4, 2),
        'gif' => substr_replace($bytes, "\x7f", 19, 1),
        'webp' => substr_replace($bytes, pack('V', 0x7FFFFFFF), 4, 4),
        default => throw new InvalidArgumentException('Unsupported raster fixture format.'),
    };
    expect(fn (): int|string => ingestMigrationContainer($bytes, $format))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
})->with(['png', 'jpeg', 'gif', 'webp']);

it('walks JPEG segments rather than treating payload markers or text as file ends', function (): void {
    $metadata = "\xff\xd9<html>compressed metadata</html>";
    $bytes = migrationContainerBytes('jpeg');
    $bytes = substr($bytes, 0, 2) . "\xff\xe1" . pack('n', strlen($metadata) + 2) . $metadata . substr($bytes, 2);
    expect(ingestMigrationContainer($bytes, 'jpeg'))->not->toBeNull();
});

it('accepts progressive JPEG scans and stuffed or restart markers in entropy data', function (): void {
    $image = imagecreatetruecolor(16, 16);
    throw_unless($image instanceof GdImage, RuntimeException::class);
    imageinterlace($image, true);
    ob_start();
    imagejpeg($image);
    $bytes = ob_get_clean();
    throw_unless(is_string($bytes), RuntimeException::class);
    expect(ingestMigrationContainer($bytes, 'jpeg'))->not->toBeNull();

    // Markers inside entropy data are framing, rather than length-bearing segments.
    $bytes = substr_replace(migrationContainerBytes('jpeg'), "\xff\0\xff\xd0", -2, 0);
    expect(ingestMigrationContainer($bytes, 'jpeg'))->not->toBeNull();
});

it('rejects corrupt PNG checksums', function (): void {
    $bytes = migrationContainerBytes('png');
    $bytes[29] = chr(ord($bytes[29]) ^ 1);
    expect(fn (): int|string => ingestMigrationContainer($bytes))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
});

it('requires JPEG height in the frame or a DNL segment', function (): void {
    $bytes = migrationContainerBytes('jpeg');
    $frame = strpos($bytes, "\xff\xc0");
    throw_unless(is_int($frame), RuntimeException::class);
    $bytes = substr_replace($bytes, "\0\0", $frame + 5, 2);
    expect(fn (): int|string => ingestMigrationContainer($bytes, 'jpeg'))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
    $bytes = substr_replace($bytes, "\xff\xdc\0\x04\0\x01", -2, 0);
    expect(ingestMigrationContainer($bytes, 'jpeg'))->not->toBeNull();
});

it('accepts the standalone JPEG TEM marker within entropy data', function (): void {
    $bytes = substr_replace(migrationContainerBytes('jpeg'), "\xff\x01", -2, 0);
    expect(ingestMigrationContainer($bytes, 'jpeg'))->not->toBeNull();
});

it('does not mistake a GIF trailer inside a comment sub-block for the container end', function (): void {
    $comment = ';<html>metadata</html>';
    $bytes = substr_replace(migrationContainerBytes('gif'), "\x21\xfe" . chr(strlen($comment)) . $comment . "\0", -1, 0);
    expect(ingestMigrationContainer($bytes, 'gif'))->not->toBeNull();
    expect(fn (): int|string => ingestMigrationContainer($bytes . "\0", 'gif'))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
});

it('validates WAV declared length and chunk boundaries', function (): void {
    $chunks = 'fmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', 2) . "\0\0";
    $bytes = 'RIFF' . pack('V', strlen($chunks) + 4) . 'WAVE' . $chunks;
    expect(ingestMigrationContainer($bytes, 'wav'))->not->toBeNull();
    foreach ([$bytes . '<?php echo 1;', substr($bytes, 0, -1), substr_replace($bytes, pack('V', 999), 40, 4)] as $invalid) {
        expect(fn (): int|string => ingestMigrationContainer($invalid, 'wav'))
            ->toThrow(RuntimeException::class, 'Unsupported media content');
    }
});

it('validates finite ISO media box lengths and refuses open-ended boxes', function (): void {
    $bytes = pack('N', 24) . 'ftypisom' . pack('N', 0) . 'isommp42'
        . pack('N', 8) . 'moov' . pack('N', 12) . 'mdat' . "\0\0\0\0";
    expect(ingestMigrationContainer($bytes, 'mp4'))->not->toBeNull();
    foreach ([$bytes . '<svg/>', substr($bytes, 0, -1), substr_replace($bytes, pack('N', 0), 32, 4),
        substr_replace($bytes, pack('NNN', 1, 0xFFFFFFFF, 0xFFFFFFFF), 32, 4)] as $invalid) {
        expect(fn (): int|string => ingestMigrationContainer($invalid, 'mp4'))
            ->toThrow(RuntimeException::class, 'Unsupported media content');
    }
});

it('validates AVIF finite boxes and content sniffing', function (): void {
    $locations = migrationAvifIloc(0, 0, [[0, 4]]);
    $locations = migrationAvifIloc(0, 0, [[44 + strlen($locations), 4]]);
    $bytes = migrationAvifBytes($locations, "\0\0\0\0", false);
    expect(ingestMigrationContainer($bytes, 'avif'))->not->toBeNull();
    expect(fn (): int|string => ingestMigrationContainer($bytes . '<html/>', 'avif'))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
});

it('accepts real encoded audio containers', function (string $name, string $mime): void {
    $bytes = migrationEncodedMediaBytes($name);
    $id = ingestMigrationContainer($bytes, pathinfo($name, PATHINFO_EXTENSION));
    $media = Media::query()->findOrFail($id);
    expect($media->mime_type)->toBe($mime)
        ->and(Storage::disk('public')->get($media->getPathRelativeToRoot()))->toBe($bytes);
})->with([
    'MPEG 1' => ['mpeg1.mp3', 'audio/mpeg'],
    'MPEG 2' => ['mpeg2.mp3', 'audio/mpeg'],
    'MPEG 2.5' => ['mpeg25.mp3', 'audio/mpeg'],
    'Ogg Vorbis' => ['silence.ogg', 'audio/ogg'],
]);

it('accepts MP3 with bounded ID3v2 tags and an optional ID3v1 trailer', function (int $version, bool $trailer): void {
    // 130 padding bytes exercise both nonzero digits of the syncsafe size.
    $tag = 'ID3' . chr($version) . "\0\0\0\0\x01\x02" . str_repeat("\0", 130);
    $bytes = $tag . migrationEncodedMediaBytes('mpeg1.mp3') . ($trailer ? 'TAG' . str_repeat("\0", 125) : '');
    expect(ingestMigrationContainer($bytes, 'mp3'))->not->toBeNull();
})->with([2, 3, 4])->with([false, true]);

it('accepts MP3 with only an ID3v1 trailer', function (): void {
    expect(ingestMigrationContainer(migrationEncodedMediaBytes('mpeg1.mp3') . 'TAG' . str_repeat("\0", 125), 'mp3'))
        ->not->toBeNull();
});

it('accepts an MP3 ID3v2 footer matching the header', function (): void {
    $fields = "\x04\0\x10\0\0\0\x0d";
    $title = 'TIT2' . "\0\0\0\x03\0\0\0ok";
    $bytes = 'ID3' . $fields . $title . '3DI' . $fields . migrationEncodedMediaBytes('mpeg1.mp3');
    expect(fn () => validateMigrationContainer($bytes, 'audio/mpeg'))->not->toThrow(RuntimeException::class);
});

it('accepts real AVIF coded data in meta idat without mdat', function (): void {
    $bytes = migrationEncodedMediaBytes('idat.avif');
    expect($bytes)->not->toContain('mdat');
    $image = imagecreatefromavif(__DIR__ . '/../../Fixtures/media/idat.avif');
    expect($image)->toBeInstanceOf(GdImage::class);
    throw_unless($image instanceof GdImage, RuntimeException::class);
    expect(imagesx($image))->toBe(1)->and(imagesy($image))->toBe(1);
    $id = ingestMigrationContainer($bytes, 'avif');
    expect(Media::query()->findOrFail($id)->mime_type)->toBe('image/avif');
});

it('walks every MPEG version and layer at all sample rates with and without padding', function (): void {
    // Independently tabulated frame sizes at 128 kbit/s; each row covers the
    // three sample-rate indices. Layer I pads by four bytes, other layers by one.
    $cases = [
        [0xFF, 0x40, 4, [136, 128, 192]],
        [0xFD, 0x80, 1, [417, 384, 576]],
        [0xFB, 0x90, 1, [417, 384, 576]],
        [0xF7, 0x80, 4, [276, 256, 384]],
        [0xF5, 0xC0, 1, [835, 768, 1152]],
        [0xF3, 0xC0, 1, [417, 384, 576]],
        [0xE7, 0x80, 4, [556, 512, 768]],
        [0xE5, 0xC0, 1, [1671, 1536, 2304]],
        [0xE3, 0xC0, 1, [835, 768, 1152]],
    ];
    foreach ($cases as [$second, $bitrate, $slotSize, $lengths]) {
        foreach ($lengths as $rateIndex => $length) {
            foreach ([0, 1] as $padding) {
                $header = "\xff" . chr($second) . chr($bitrate | ($rateIndex << 2) | ($padding << 1)) . "\xc0";
                $frame = $header . str_repeat("\0", $length + $padding * $slotSize - 4);
                // Each header determines its own length, including VBR frames.
                $bytes = $frame . migrationEncodedMediaBytes('mpeg1.mp3');
                expect(fn () => validateMigrationContainer($bytes, 'audio/mpeg'))->not->toThrow(RuntimeException::class);
                expect(fn () => validateMigrationContainer(substr($frame, 0, -1), 'audio/mpeg'))
                    ->toThrow(RuntimeException::class, 'Unsupported media content');
            }
        }
    }
});

it('rejects free-format and reserved MPEG frame headers', function (string $header): void {
    $bytes = migrationEncodedMediaBytes('mpeg1.mp3') . $header . str_repeat("\0", 2300);
    expect(fn () => validateMigrationContainer($bytes, 'audio/mpeg'))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
})->with([
    'bad sync' => "\xfe\xfb\x90\xc0",
    'reserved version' => "\xff\xeb\x90\xc0",
    'reserved layer' => "\xff\xf9\x90\xc0",
    'free format' => "\xff\xfb\0\xc0",
    'reserved bitrate' => "\xff\xfb\xf0\xc0",
    'reserved sample rate' => "\xff\xfb\x9c\xc0",
    'reserved emphasis' => "\xff\xfb\x90\xc2",
]);

it('requires complete bounded MP3 tags and at least one audio frame', function (): void {
    $audio = migrationEncodedMediaBytes('mpeg1.mp3');
    $emptyTag = "ID3\x04\0\0\0\0\0\0";
    $invalid = [
        '', 'ID3', $emptyTag, 'TAG' . str_repeat("\0", 125),
        "ID3\x01\0\0\0\0\0\0" . $audio,
        "ID3\x04\xff\0\0\0\0\0" . $audio,
        "ID3\x04\0\x01\0\0\0\0" . $audio,
        "ID3\x03\0\x10\0\0\0\0" . $audio,
        "ID3\x04\0\0\x7f\x7f\x7f\x7f" . $audio,
        "ID3\x04\0\x10\0\0\0\0" . '3DI' . "\x04\0\x10\0\0\0\x01" . $audio,
        $emptyTag . $emptyTag . $audio, $audio . $emptyTag,
        $audio . 'TAG' . str_repeat("\0", 124),
        $audio . 'TAG' . str_repeat("\0", 126),
    ];
    for ($index = 6; $index < 10; $index++) {
        $invalid[] = substr_replace($emptyTag, "\x80", $index, 1) . $audio;
    }
    foreach ($invalid as $bytes) {
        expect(fn () => validateMigrationContainer($bytes, 'audio/mpeg'))
            ->toThrow(RuntimeException::class, 'Unsupported media content');
    }
});

it('walks Ogg lacing including full pages and packet continuation without stream state', function (): void {
    $header = 'OggS' . "\0\0" . str_repeat("\0", 20);
    $page = $header . "\xff" . str_repeat("\xff", 255) . str_repeat("\0", 255 * 255);
    $continuation = substr_replace($header, "\x05", 5, 1) . "\x01\0";
    expect(fn () => validateMigrationContainer($page . $continuation, 'audio/ogg'))->not->toThrow(RuntimeException::class);
    foreach (['', substr($page, 0, 26), substr($page, 0, 281), substr($page, 0, -1),
        substr_replace($page, 'X', 3, 1), substr_replace($page, "\x01", 4, 1),
        substr_replace($page, "\x08", 5, 1), $header . "\0", $page . 'OggS<html/>'] as $bytes) {
        expect(fn () => validateMigrationContainer($bytes, 'audio/ogg'))
            ->toThrow(RuntimeException::class, 'Unsupported media content');
    }
});

it('bounds AVIF child boxes to meta and accepts extended idat sizes', function (): void {
    $bytes = migrationEncodedMediaBytes('idat.avif');
    $idat = strpos($bytes, 'idat');
    throw_unless(is_int($idat), RuntimeException::class);
    $extended = substr_replace($bytes, pack('N', 1) . 'idat' . pack('NN', 0, 50), $idat - 4, 8);
    $extended = substr_replace($extended, pack('N', 287), 32, 4);
    expect(fn () => validateMigrationContainer($extended, 'image/avif'))->not->toThrow(RuntimeException::class);
    // The real iloc is relative to the idat payload, so its extent stays valid.
    expect(imagecreatefromstring($extended))->toBeInstanceOf(GdImage::class);
    foreach ([
        substr_replace($bytes, pack('N', 0), $idat - 4, 4),
        substr_replace($bytes, pack('N', 7), $idat - 4, 4),
        substr_replace($bytes, pack('N', 43), $idat - 4, 4) . "\0",
        substr_replace($bytes, 'free', $idat, 4),
        substr_replace($bytes, "\x01", 40, 1),
        substr_replace($bytes, pack('N', 278), 32, 4),
        substr_replace($extended, pack('NN', 0xFFFFFFFF, 0xFFFFFFFF), $idat + 4, 8),
    ] as $invalid) {
        expect(fn () => validateMigrationContainer($invalid, 'image/avif'))
            ->toThrow(RuntimeException::class, 'Unsupported media content');
    }
});

it('stores opaque AVIF idat bytes safely when both meta and idat are enlarged', function (): void {
    $bytes = migrationEncodedMediaBytes('idat.avif');
    expect(strlen($bytes))->toBe(311)
        ->and(substr($bytes, 32, 4))->toBe(pack('N', 279))
        ->and(substr($bytes, 269, 4))->toBe(pack('N', 42));
    $bytes .= '<?php echo 1;';
    $bytes = substr_replace($bytes, pack('N', 292), 32, 4);
    $bytes = substr_replace($bytes, pack('N', 55), 269, 4);
    $id = ingestMigrationContainer($bytes, 'avif');
    $media = Media::query()->findOrFail($id);
    expect($media->mime_type)->toBe('image/avif')
        ->and($media->file_name)->toBe('image.avif')
        ->and(Storage::disk('public')->get($media->getPathRelativeToRoot()))->toBe($bytes);
});

it('accepts the real AVIF payload referenced by file offsets in mdat', function (): void {
    $bytes = migrationEncodedMediaBytes('idat.avif');
    $bytes = substr_replace($bytes, pack('N', 237), 32, 4);
    $bytes = substr_replace($bytes, pack('n', 0), 109, 2);
    $bytes = substr_replace($bytes, pack('N', 277), 115, 4);
    $bytes = substr_replace($bytes, 'mdat', 273, 4);
    expect(fn () => validateMigrationContainer($bytes, 'image/avif'))->not->toThrow(RuntimeException::class);
    expect(imagecreatefromstring($bytes))->toBeInstanceOf(GdImage::class);
    expect(fn () => validateMigrationContainer($bytes . '<?php echo 1;', 'image/avif'))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
    $bytes .= '<?php echo 1;';
    $bytes = substr_replace($bytes, pack('N', 55), 269, 4);
    expect(fn () => validateMigrationContainer($bytes, 'image/avif'))
        ->not->toThrow(RuntimeException::class);
});

it('accepts framed AVIF data without item references to validate', function (bool $idat): void {
    expect(fn () => validateMigrationContainer(migrationAvifBytes('', 'data', $idat), 'image/avif'))
        ->not->toThrow(RuntimeException::class);
})->with([true, false]);

it('bounds AVIF file extents to EOF with multiple data boxes', function (): void {
    $locations = migrationAvifIloc(0, 0, [[0, 2], [0, 2]]);
    $start = 44 + strlen($locations);
    $locations = migrationAvifIloc(0, 0, [[$start + 10, 2], [$start, 2]]);
    $bytes = migrationAvifBytes($locations, 'ab', false) . migrationAvifBox('mdat', 'cd');
    expect(fn () => validateMigrationContainer($bytes, 'image/avif'))->not->toThrow(RuntimeException::class);

    $locations = migrationAvifIloc(0, 0, [[0, 13]]);
    $locations = migrationAvifIloc(0, 0, [[44 + strlen($locations), 13]]);
    $bytes = migrationAvifBytes($locations, 'ab', false) . migrationAvifBox('mdat', 'cd');
    expect(fn () => validateMigrationContainer($bytes, 'image/avif'))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
});

it('caps the total AVIF extents across items at 262144', function (int $tail): void {
    $payload = "\x02\0\0\0\x44\0" . pack('N', 5);
    for ($id = 1; $id <= 5; $id++) {
        $count = $id === 5 ? $tail : 65535;
        $payload .= pack('Nnnn', $id, 1, 0, $count) . str_repeat(pack('NN', 0, 4), $count);
    }
    $validate = fn () => validateMigrationContainer(migrationAvifBytes(migrationAvifBox('iloc', $payload), 'data', true), 'image/avif');
    if ($tail === 4) {
        expect($validate)->not->toThrow(RuntimeException::class);
    } else {
        expect($validate)->toThrow(RuntimeException::class, 'Unsupported media content');
    }
})->with([4, 5]);

it('accepts up to 65536 AVIF items and refuses the next item', function (int $items): void {
    $validate = fn () => validateMigrationContainer(migrationAvifBytes(migrationAvifItemTable($items), str_repeat('t', $items), true), 'image/avif');
    if ($items === 65536) {
        expect($validate)->not->toThrow(RuntimeException::class);
    } else {
        expect($validate)->toThrow(RuntimeException::class, 'Unsupported media content');
    }
})->with([65536, 65537]);

it('parses AVIF iloc versions sizes bases and multiple unordered overlapping extents', function (int $version, bool $idat, int $size): void {
    $method = $idat ? 1 : 0;
    $locations = migrationAvifIloc($version, $method, [[2, 2], [0, 3]], $size, $size, $size, $version > 0 ? $size : 0);
    $base = $idat ? 0 : 44 + strlen($locations);
    $locations = migrationAvifIloc($version, $method, [[2, 2], [0, 3]], $size, $size, $size, $version > 0 ? $size : 0, $base);
    expect(fn () => validateMigrationContainer(migrationAvifBytes($locations, 'data', $idat), 'image/avif'))
        ->not->toThrow(RuntimeException::class);
})->with([
    'version 0 file' => [0, false],
    'version 1 file' => [1, false],
    'version 2 file' => [2, false],
    'version 1 idat' => [1, true],
    'version 2 idat' => [2, true],
])->with([4, 8]);

it('accepts an omitted AVIF offset and combines nonzero idat bases with offsets', function (): void {
    $locations = migrationAvifIloc(1, 1, [[0, 4]], offsetSize: 0);
    expect(fn () => validateMigrationContainer(migrationAvifBytes($locations, 'data', true), 'image/avif'))
        ->not->toThrow(RuntimeException::class);
    $first = migrationAvifIloc(1, 1, [[0, 2]]);
    $second = migrationAvifIloc(1, 1, [[0, 2]], baseOffsetSize: 4, baseOffset: 2);
    // Distinct items can use different bases in the same idat payload.
    $locations = migrationAvifBox('iloc', substr($second, 8, 6) . pack('n', 2)
        . substr($first, 16, 6) . pack('N', 0) . substr($first, 22)
        . pack('n', 2) . substr($second, 18));
    expect(fn () => validateMigrationContainer(migrationAvifBytes($locations, 'data', true), 'image/avif'))
        ->not->toThrow(RuntimeException::class);
});

it('accepts bounded AVIF extents without requiring payload coverage', function (string $case): void {
    $extents = match ($case) {
        'tail' => [[0, 3]],
        'leading gap' => [[1, 3]],
        'internal gap' => [[0, 1], [2, 2]],
        'overlap does not cover tail' => [[0, 2], [0, 2]],
        'zero length' => [[0, 0]],
        'implicit length' => [[0, 4]],
        'missing extents' => [],
        default => throw new InvalidArgumentException('Unknown extent mutation.'),
    };
    $locations = migrationAvifIloc(1, 1, $extents, lengthSize: $case === 'implicit length' ? 0 : 4);
    expect(fn () => validateMigrationContainer(migrationAvifBytes($locations, 'data', true), 'image/avif'))
        ->not->toThrow(RuntimeException::class);
})->with([
    'tail', 'leading gap', 'internal gap', 'overlap does not cover tail',
    'zero length', 'implicit length', 'missing extents',
]);

it('rejects AVIF references outside their bounded data source', function (bool $idat, int $offset, int $length): void {
    $locations = migrationAvifIloc(1, $idat ? 1 : 0, [[$offset, $length]]);
    expect(fn () => validateMigrationContainer(migrationAvifBytes($locations, 'data', $idat), 'image/avif'))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
})->with([
    'past idat' => [true, 0, 5],
    'outside idat' => [true, 4, 1],
    'implicit outside idat' => [true, 5, 0],
    'past file' => [false, 0, 1000],
    'outside file' => [false, 1000, 1],
]);

it('accepts framed AVIF free boxes without interpreting their payload', function (): void {
    $bytes = migrationEncodedMediaBytes('idat.avif') . migrationAvifBox('free', '<?php echo 1;');
    expect(fn () => validateMigrationContainer($bytes, 'image/avif'))->not->toThrow(RuntimeException::class);
});

it('rejects malformed AVIF iloc tables', function (string $case): void {
    $locations = migrationAvifIloc(1, 1, [[0, 4]]);
    $locations = match ($case) {
        'version' => substr_replace($locations, "\x03", 8, 1),
        'flags' => substr_replace($locations, "\x01", 11, 1),
        'offset size' => substr_replace($locations, "\xf4", 12, 1),
        'length size' => substr_replace($locations, "\x4f", 12, 1),
        'base size' => substr_replace($locations, "\xf0", 13, 1),
        'index size' => substr_replace($locations, "\x0f", 13, 1),
        'method' => substr_replace($locations, pack('n', 2), 18, 2),
        'reserved method' => substr_replace($locations, pack('n', 0x1001), 18, 2),
        'external data' => substr_replace($locations, pack('n', 1), 20, 2),
        'truncated item count' => substr_replace($locations, pack('n', 1025), 14, 2),
        'version 2 item cap' => substr_replace(migrationAvifIloc(2, 1, [[0, 4]]), pack('N', 0xFFFFFFFF), 14, 4),
        'truncated extent count' => substr_replace($locations, pack('n', 4097), 22, 2),
        'truncated item' => migrationAvifBox('iloc', substr($locations, 8, 9)),
        'truncated extent' => migrationAvifBox('iloc', substr($locations, 8, -1)),
        'extra bytes' => migrationAvifBox('iloc', substr($locations, 8) . "\0"),
        'unsigned overflow' => substr_replace(migrationAvifIloc(1, 1, [[0, 4]], offsetSize: 8), str_repeat("\xff", 8), 24, 8),
        'addition overflow' => migrationAvifIloc(1, 1, [[1, 4]], baseOffsetSize: 8, baseOffset: PHP_INT_MAX),
        'duplicate iloc' => $locations . $locations,
        'duplicate item' => migrationAvifBox('iloc', substr($locations, 8, 6) . pack('n', 2)
            . substr($locations, 16) . substr($locations, 16)),
        'duplicate idat' => $locations . migrationAvifBox('idat', 'data'),
        'version 0 reserved index' => substr_replace(migrationAvifIloc(0, 0, [[0, 4]]), "\x04", 13, 1),
        default => throw new InvalidArgumentException('Unknown iloc mutation.'),
    };
    expect(fn () => validateMigrationContainer(migrationAvifBytes($locations, 'data', true), 'image/avif'))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
})->with([
    'version', 'flags', 'offset size', 'length size', 'base size', 'index size', 'method',
    'reserved method', 'external data', 'truncated item count', 'version 2 item cap', 'truncated extent count', 'truncated item',
    'truncated extent', 'extra bytes', 'unsigned overflow', 'addition overflow',
    'duplicate iloc', 'duplicate item', 'duplicate idat', 'version 0 reserved index',
]);

it('rejects bytes after real audio and idat AVIF containers', function (string $name, string $suffix): void {
    expect(fn (): int|string => ingestMigrationContainer(migrationEncodedMediaBytes($name) . $suffix, pathinfo($name, PATHINFO_EXTENSION)))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with(['mpeg1.mp3', 'mpeg2.mp3', 'mpeg25.mp3', 'silence.ogg', 'idat.avif'])->with([
    'HTML' => '<html><script>alert(1)</script></html>',
    'PHP' => '<?php echo "unsafe";',
    'one byte' => "\0",
]);

it('rejects HTML and PHP before or after an MP3 ID3v1 trailer', function (string $suffix, bool $after): void {
    $trailer = 'TAG' . str_repeat("\0", 125);
    $bytes = migrationEncodedMediaBytes('mpeg1.mp3') . ($after ? $trailer . $suffix : $suffix . $trailer);
    expect(fn (): int|string => ingestMigrationContainer($bytes, 'mp3'))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
})->with(['<html/>', '<?php echo 1;'])->with([false, true]);

it('rejects truncated real audio and idat AVIF containers', function (string $name): void {
    expect(fn (): int|string => ingestMigrationContainer(substr(migrationEncodedMediaBytes($name), 0, -1), pathinfo($name, PATHINFO_EXTENSION)))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
})->with(['mpeg1.mp3', 'mpeg2.mp3', 'mpeg25.mp3', 'silence.ogg', 'idat.avif']);

it('validates a declared WebM segment length and refuses unknown lengths', function (): void {
    $header = "\x42\x82\x84webm";
    $bytes = "\x1a\x45\xdf\xa3" . chr(0x80 | strlen($header)) . $header
        . "\x18\x53\x80\x67\x84\x16\x54\xae\x6b";
    expect(ingestMigrationContainer($bytes, 'webm'))->not->toBeNull();
    foreach ([$bytes . '<html/>', substr($bytes, 0, -1), substr_replace($bytes, "\xff", -5, 1)] as $invalid) {
        expect(fn (): int|string => ingestMigrationContainer($invalid, 'webm'))
            ->toThrow(RuntimeException::class, 'Unsupported media content');
    }
});

it('refuses MIME-sniffed formats without a supported bounded container parser', function (string $bytes): void {
    expect(fn (): int|string => ingestMigrationContainer($bytes))
        ->toThrow(RuntimeException::class, 'Unsupported media content');
})->with([
    'SVG' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
    'HTML' => '<html><script>alert(1)</script></html>',
    'script' => '<?php echo 1;',
]);

it('bounds unfinished tag-like input in a separate constrained process', function (int $megabytes): void {
    $process = new Process([
        PHP_BINARY, '-d', 'memory_limit=32M',
        __DIR__ . '/../../Fixtures/measure-media-validation.php', (string) $megabytes,
    ], dirname(__DIR__, 5));
    $process->setTimeout(10);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($result) || ! is_bool($result['rejected'] ?? null)
        || ! is_int($result['peak_growth'] ?? null) || ! is_float($result['seconds'] ?? null)) {
        throw new RuntimeException('Invalid media validation measurement.');
    }
    expect($result['rejected'])->toBeTrue()
        ->and($result['peak_growth'])->toBeLessThan(8 * 1024 * 1024)
        ->and($result['seconds'])->toBeLessThan(5.0);
})->with([8, 32]);

it('bounds audio frame and page walks in a separate constrained process', function (string $format, int $megabytes): void {
    $process = new Process([
        PHP_BINARY, '-d', 'memory_limit=32M',
        __DIR__ . '/../../Fixtures/measure-audio-validation.php', $format, (string) $megabytes,
    ], dirname(__DIR__, 5));
    $process->setTimeout(10);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($result) || ! is_bool($result['rejected'] ?? null)
        || ! is_int($result['peak_growth'] ?? null) || ! is_float($result['seconds'] ?? null)) {
        throw new RuntimeException('Invalid audio validation measurement.');
    }
    expect($result['rejected'])->toBeTrue()
        ->and($result['peak_growth'])->toBeLessThan(8 * 1024 * 1024)
        ->and($result['seconds'])->toBeLessThan(5.0);
})->with(['mp3', 'ogg'])->with([8, 32]);
