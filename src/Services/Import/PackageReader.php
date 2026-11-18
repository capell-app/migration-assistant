<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Services\Import;

use RuntimeException;
use ZipArchive;

/** Verifies every archive entry with bounded reads before exposing its manifest and payload. */
final class PackageReader
{
    public function read(string $archivePath): PackageReadResult
    {
        throw_unless(is_file($archivePath), RuntimeException::class, sprintf('Package archive [%s] does not exist.', $archivePath));
        $limits = [
            'metadata' => $this->limit('max_metadata_json_bytes', 1024 * 1024),
            'payload' => $this->limit('max_payload_json_bytes', 5 * 1024 * 1024),
            'media' => $this->limit('max_media_bytes', 50 * 1024 * 1024),
            'package' => $this->limit('max_package_uncompressed_bytes', 250 * 1024 * 1024),
            'entries' => $this->limit('max_archive_entries', 10000),
        ];
        $archive = new ZipArchive;
        $opened = $archive->open($archivePath, ZipArchive::RDONLY);
        throw_unless($opened === true, RuntimeException::class, sprintf('Failed to open package archive [%s] for reading (code %d).', $archivePath, $opened));
        $contents = [];
        $checksums = [];
        $total = 0;

        try {
            throw_if($archive->numFiles > $limits['entries'], RuntimeException::class, 'Package archive exceeds the maximum entry count.');
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->getNameIndex($index);
                throw_unless(is_string($entry), RuntimeException::class, 'Unable to read archive entry name.');
                throw_if(array_key_exists($entry, $checksums), RuntimeException::class, 'Package archive contains duplicate entries.');
                if (str_ends_with($entry, '/')) {
                    continue;
                }

                $metadata = in_array($entry, ['manifest.json', 'integrity.json'], true);
                $payload = str_starts_with($entry, 'pages/') || str_starts_with($entry, 'relations/');
                $media = str_starts_with($entry, 'media/');
                $maxBytes = $metadata ? $limits['metadata'] : ($payload ? $limits['payload'] : ($media ? $limits['media'] : $limits['package']));
                $message = match (true) {
                    $metadata => sprintf('Metadata JSON [%s] exceeds the maximum metadata JSON size.', $entry),
                    $payload => sprintf('JSON payload [%s] exceeds the maximum JSON payload size.', $entry),
                    $media => sprintf('Media binary [%s] exceeds the maximum import size.', $entry),
                    default => 'Package archive exceeds the maximum package size.',
                };
                $stream = $archive->getStream($entry);
                throw_if($stream === false, RuntimeException::class, sprintf('Failed to read archive entry [%s].', $entry));
                $hash = hash_init('sha256');
                $bytes = 0;
                $body = '';
                try {
                    while (! feof($stream)) {
                        $chunk = fread($stream, max(1, min(8192, $maxBytes - $bytes + 1, $limits['package'] - $total + 1)));
                        throw_if($chunk === false || ($chunk === '' && ! feof($stream)), RuntimeException::class, sprintf('Failed to read archive entry [%s].', $entry));
                        $bytes += strlen($chunk);
                        $total += strlen($chunk);
                        throw_if($bytes > $maxBytes, RuntimeException::class, $message);
                        throw_if($total > $limits['package'], RuntimeException::class, 'Package archive exceeds the maximum package size.');
                        hash_update($hash, $chunk);
                        if ($metadata || $payload) {
                            $body .= $chunk;
                        }
                    }
                } finally {
                    fclose($stream);
                }
                $checksums[$entry] = 'sha256-' . hash_final($hash);
                if ($metadata || $payload) {
                    $contents[$entry] = $body;
                }
            }
        } finally {
            $archive->close();
        }

        $manifest = $this->json($contents, 'manifest.json');
        $integrity = $this->json($contents, 'integrity.json');
        $listed = $integrity['files'] ?? null;
        throw_unless(is_array($listed), RuntimeException::class, 'Package integrity must contain a file checksum map.');
        $payload = [];
        foreach ($listed as $entry => $checksum) {
            throw_unless(is_string($entry) && is_string($checksum), RuntimeException::class, 'Package integrity contains an invalid file checksum.');
            throw_unless(array_key_exists($entry, $checksums), RuntimeException::class, sprintf('Integrity entry [%s] is missing from archive.', $entry));
            throw_unless(hash_equals($checksum, $checksums[$entry]), RuntimeException::class, sprintf('Integrity checksum mismatch for [%s].', $entry));
            if ((str_starts_with($entry, 'pages/') || str_starts_with($entry, 'relations/')) && isset($contents[$entry])) {
                $payload[$entry] = $contents[$entry];
            }
        }

        return new PackageReadResult(archivePath: $archivePath, manifest: $manifest, integrity: $integrity, payload: $payload);
    }

    private function limit(string $key, int $default): int
    {
        $value = config('migration-assistant.limits.' . $key, $default);
        throw_unless(is_int($value) && $value > 0, RuntimeException::class, sprintf('Package archive limit [%s] must be a positive integer.', $key));

        return $value;
    }

    /**
     * @param  array<string, string>  $contents
     * @return array<string, mixed>
     */
    private function json(array $contents, string $entry): array
    {
        throw_unless(isset($contents[$entry]), RuntimeException::class, sprintf('Required archive entry [%s] is missing.', $entry));
        $decoded = json_decode($contents[$entry], true, 512, JSON_THROW_ON_ERROR);
        throw_unless(is_array($decoded), RuntimeException::class, sprintf('Archive entry [%s] must be a JSON object.', $entry));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
