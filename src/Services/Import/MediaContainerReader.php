<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Services\Import;

use HashContext;
use RuntimeException;

/** Fixed-size buffering keeps marker walks linear, including JPEG entropy data. */
final class MediaContainerReader
{
    private string $buffer = '';

    private int $offset = 0;

    /** @param resource $stream */
    public function __construct(private mixed $stream, private int $remaining) {}

    public function remaining(): int
    {
        return $this->remaining;
    }

    public function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Unsupported media content.');
        }
    }

    public function read(int $length): string
    {
        $this->require($length >= 0 && $length <= 8192 && $length <= $this->remaining);
        $result = '';

        while (strlen($result) < $length) {
            $this->fill();
            $count = min($length - strlen($result), strlen($this->buffer) - $this->offset);
            $result .= substr($this->buffer, $this->offset, $count);
            $this->advance($count);
        }

        return $result;
    }

    public function skip(int $length, ?HashContext $checksum = null): void
    {
        $this->require($length >= 0 && $length <= $this->remaining);

        while ($length > 0) {
            $chunk = $this->read(min($length, 8192));
            if ($checksum !== null) {
                hash_update($checksum, $chunk);
            }
            $length -= strlen($chunk);
        }
    }

    public function skipUntil(string $byte): void
    {
        while ($this->remaining > 0) {
            $this->fill();
            $count = strcspn($this->buffer, $byte, $this->offset);
            $this->advance($count);
            if ($this->offset < strlen($this->buffer)) {
                return;
            }
        }

        $this->require(false);
    }

    public function unsigned(int $bytes, bool $littleEndian = false): int
    {
        $value = 0;
        $data = $this->read($bytes);
        if ($littleEndian) {
            $data = strrev($data);
        }
        for ($index = 0; $index < $bytes; $index++) {
            // Check before multiplying, so hostile 64-bit box sizes cannot overflow.
            $this->require($value <= intdiv(PHP_INT_MAX - ord($data[$index]), 256));
            $value = $value * 256 + ord($data[$index]);
        }

        return $value;
    }

    private function fill(): void
    {
        if ($this->offset < strlen($this->buffer)) {
            return;
        }

        $buffer = fread($this->stream, 8192);
        if ($buffer === false || $buffer === '') {
            throw new RuntimeException('Unsupported media content.');
        }
        $this->buffer = $buffer;
        $this->offset = 0;
    }

    private function advance(int $count): void
    {
        $this->offset += $count;
        $this->remaining -= $count;
    }
}
