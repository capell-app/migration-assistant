<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Actions\StoreImportedMediaAction;
use Capell\MigrationAssistant\Services\Import\MediaIngestService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

Container::setInstance(new Container);
Container::getInstance()->instance('config', new Repository);
$path = tempnam(sys_get_temp_dir(), 'bounded-media-');
if ($path === false) {
    throw new RuntimeException('Cannot create media fixture.');
}
$zipPath = $path . '.zip';
$source = fopen($path, 'w+b');
if ($source === false) {
    throw new RuntimeException('Cannot open media fixture.');
}
fwrite($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC', true) . '<');
for ($index = 0; $index < (int) ($argv[1] ?? 8); $index++) {
    fwrite($source, str_repeat('a', 1024 * 1024));
}
fclose($source);
$checksum = 'sha256-' . hash_file('sha256', $path);
$archive = new ZipArchive;
$archive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$archive->addFile($path, 'media.png');
$archive->close();
$archive->open($zipPath, ZipArchive::RDONLY);
$baseline = memory_get_usage(true);
memory_reset_peak_usage();
$started = hrtime(true);
$stream = null;
$rejected = false;

try {
    $service = new MediaIngestService;
    $verified = new ReflectionMethod($service, 'verifiedMediaStream')->invoke($service, $archive, 'media.png', $checksum);
    if (! is_array($verified) || ! is_resource($verified[0] ?? null)) {
        throw new RuntimeException('Expected a buffered media stream.');
    }
    $stream = $verified[0];
    $storage = new StoreImportedMediaAction;
    new ReflectionMethod($storage, 'mediaType')->invoke($storage, $stream);
} catch (RuntimeException $exception) {
    $rejected = $exception->getMessage() === 'Unsupported media content.';
} finally {
    if (is_resource($stream)) {
        fclose($stream);
    }
    $archive->close();
    unlink($zipPath);
    unlink($path);
}

echo json_encode([
    'rejected' => $rejected,
    'peak_growth' => memory_get_peak_usage(true) - $baseline,
    'seconds' => (hrtime(true) - $started) / 1e9,
], JSON_THROW_ON_ERROR);
