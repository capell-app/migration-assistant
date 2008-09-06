<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Services\Import\MediaContainerValidator;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$format = $argv[1] ?? 'mp3';
[$name, $mime] = match ($format) {
    'mp3' => ['mpeg1.mp3', 'audio/mpeg'],
    'ogg' => ['silence.ogg', 'audio/ogg'],
    default => throw new RuntimeException('Unknown audio fixture.'),
};
$bytes = file_get_contents(__DIR__ . '/media/' . $name);
$stream = tmpfile();
if (! is_string($bytes) || ! is_resource($stream)) {
    throw new RuntimeException('Cannot create audio fixture.');
}
$megabytes = (int) ($argv[2] ?? 8);
if ($format === 'mp3') {
    $size = $megabytes * 1024 * 1024;
    $syncsafe = '';
    for ($shift = 21; $shift >= 0; $shift -= 7) {
        $syncsafe .= chr(($size >> $shift) & 0x7F);
    }
    fwrite($stream, "ID3\x04\0\0" . $syncsafe);
    for ($left = $size; $left > 0; $left -= 8192) {
        fwrite($stream, str_repeat("\0", min($left, 8192)));
    }
}
for ($copies = intdiv($megabytes * 1024 * 1024, strlen($bytes)) + 1; $copies > 0; $copies--) {
    fwrite($stream, $bytes);
}
$baseline = memory_get_usage(true);
memory_reset_peak_usage();
$started = hrtime(true);
$rejected = false;
try {
    $validator = new MediaContainerValidator;
    $validator->validate($stream, $mime);
    fseek($stream, 0, SEEK_END);
    fwrite($stream, '<?php echo "unsafe";');
    try {
        $validator->validate($stream, $mime);
    } catch (RuntimeException $exception) {
        $rejected = $exception->getMessage() === 'Unsupported media content.';
    }
} finally {
    fclose($stream);
}
echo json_encode([
    'rejected' => $rejected,
    'peak_growth' => memory_get_peak_usage(true) - $baseline,
    'seconds' => (hrtime(true) - $started) / 1e9,
], JSON_THROW_ON_ERROR);
