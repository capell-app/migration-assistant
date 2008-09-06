<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Services\Import;

use RuntimeException;

/**
 * Framing checks do not prove that container payloads contain only codec data:
 * AVIF can also carry track samples, metadata and free boxes. The security
 * boundary is a sniffed MIME, its canonical extension and non-executable storage
 * served as static media (Content-Type and nosniff belong to the disk server).
 */
final class MediaContainerValidator
{
    private const int MAX_AVIF_ITEMS = 65536;

    private const int MAX_AVIF_EXTENTS = 262144;

    /** @param resource $stream */
    public function validate(mixed $stream, string $mime): void
    {
        $stat = fstat($stream);
        $reader = new MediaContainerReader($stream, $stat === false ? 0 : $stat['size']);
        rewind($stream);

        match ($mime) {
            'image/png' => $this->png($reader),
            'image/jpeg' => $this->jpeg($reader),
            'image/gif' => $this->gif($reader),
            'image/webp' => $this->riff($reader, 'WEBP'),
            'audio/wav', 'audio/x-wav' => $this->riff($reader, 'WAVE'),
            'audio/mpeg' => $this->mp3($reader),
            'audio/ogg' => $this->ogg($reader),
            'image/avif', 'video/mp4' => $this->isoBoxes($reader, $mime),
            'video/webm' => $this->webm($reader),
            default => $reader->require(false),
        };

        $reader->require($reader->remaining() === 0);
    }

    private function png(MediaContainerReader $reader): void
    {
        $reader->require($reader->read(8) === "\x89PNG\r\n\x1a\n");
        $header = false;
        $data = false;
        $dataEnded = false;
        $palette = false;
        $indexed = false;

        while (true) {
            $length = $reader->unsigned(4);
            $type = $reader->read(4);
            $reader->require($length <= 0x7FFFFFFF && $length <= $reader->remaining() - 4
                && preg_match('/\A[A-Za-z]{2}[A-Z][A-Za-z]\z/D', $type) === 1);
            $reader->require($header || $type === 'IHDR');
            $checksum = hash_init('crc32b');
            hash_update($checksum, $type);

            if ($type === 'IHDR') {
                $reader->require(! $header && $length === 13);
                $width = $reader->unsigned(4);
                $height = $reader->unsigned(4);
                $fields = $reader->read(5);
                hash_update($checksum, pack('NN', $width, $height) . $fields);
                $colour = ord($fields[1]);
                $depths = match ($colour) {
                    0 => [1, 2, 4, 8, 16],
                    2, 4, 6 => [8, 16],
                    3 => [1, 2, 4, 8],
                    default => [],
                };
                $reader->require($width > 0 && $width <= 0x7FFFFFFF && $height > 0 && $height <= 0x7FFFFFFF
                    && in_array(ord($fields[0]), $depths, true) && $fields[2] === "\0" && $fields[3] === "\0"
                    && ord($fields[4]) <= 1);
                $indexed = $colour === 3;
                $header = true;
            } else {
                if ($type === 'IDAT') {
                    $reader->require(! $dataEnded && (! $indexed || $palette));
                    $data = $data || $length > 0;
                } elseif ($data) {
                    $dataEnded = true;
                }
                if ($type === 'PLTE') {
                    $reader->require(! $palette && ! $data && $length > 0 && $length <= 768 && $length % 3 === 0);
                    $palette = true;
                }
                // Unknown critical chunks have no safe interpretation.
                $reader->require(ord($type[0]) >= 97 || in_array($type, ['PLTE', 'IDAT', 'IEND'], true));
                $reader->skip($length, $checksum);
            }

            $reader->require(hash_equals(hash_final($checksum, true), $reader->read(4)));
            if ($type === 'IEND') {
                $reader->require($length === 0 && $data);

                return;
            }
        }
    }

    private function jpeg(MediaContainerReader $reader): void
    {
        $reader->require($reader->read(2) === "\xff\xd8");
        $entropy = false;
        $frame = false;
        $scan = false;
        $height = false;

        while (true) {
            if ($entropy) {
                $reader->skipUntil("\xff");
            }
            $reader->require($reader->read(1) === "\xff");
            do {
                $marker = $reader->unsigned(1);
            } while ($marker === 0xFF);

            if ($marker === 0x01 || ($entropy && ($marker === 0 || ($marker >= 0xD0 && $marker <= 0xD7)))) {
                continue;
            }
            if ($marker === 0xD9) {
                $reader->require($frame && $scan && $height);

                return;
            }
            $reader->require($marker >= 0xC0 && $marker !== 0xD8 && ! ($marker >= 0xD0 && $marker <= 0xD7));
            $length = $reader->unsigned(2);
            $reader->require($length >= 2 && $length - 2 <= $reader->remaining());

            if (in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                $reader->require(! $frame && $length >= 11);
                $fields = $reader->read(6);
                $reader->require(ord($fields[0]) > 0 && substr($fields, 3, 2) !== "\0\0"
                    && ord($fields[5]) > 0 && $length === 8 + 3 * ord($fields[5]));
                $reader->skip($length - 8);
                $frame = true;
                $height = substr($fields, 1, 2) !== "\0\0";
            } elseif ($marker === 0xDA) {
                $reader->require($frame && $length >= 8);
                $components = $reader->unsigned(1);
                $reader->require($components > 0 && $length === 6 + 2 * $components);
                $reader->skip($length - 3);
                $scan = true;
            } elseif ($marker === 0xDC) {
                $reader->require($entropy && $length === 4 && ! $height);
                $height = $reader->unsigned(2) > 0;
                $reader->require($height);
            } else {
                $reader->skip($length - 2);
            }
            // DNL may interrupt an entropy-coded segment; other markers end it.
            $entropy = $marker === 0xDA || ($entropy && $marker === 0xDC);
        }
    }

    private function gif(MediaContainerReader $reader): void
    {
        $reader->require(in_array($reader->read(6), ['GIF87a', 'GIF89a'], true));
        $screen = $reader->read(7);
        $reader->require(substr($screen, 0, 2) !== "\0\0" && substr($screen, 2, 2) !== "\0\0");
        $this->gifPalette($reader, ord($screen[4]));
        $image = false;

        while (true) {
            $block = $reader->unsigned(1);
            if ($block === 0x3B) {
                $reader->require($image);

                return;
            }
            if ($block === 0x2C) {
                $descriptor = $reader->read(9);
                $reader->require(substr($descriptor, 4, 2) !== "\0\0" && substr($descriptor, 6, 2) !== "\0\0"
                    && (ord($descriptor[8]) & 0x18) === 0);
                $this->gifPalette($reader, ord($descriptor[8]));
                $codeSize = $reader->unsigned(1);
                $reader->require($codeSize >= 2 && $codeSize <= 8);
                $reader->require($this->gifSubBlocks($reader) > 0);
                $image = true;
            } elseif ($block === 0x21) {
                $label = $reader->unsigned(1);
                if ($label === 0xF9) {
                    $reader->require($reader->unsigned(1) === 4);
                    $reader->skip(4);
                    $reader->require($reader->unsigned(1) === 0);
                } else {
                    $reader->require(in_array($label, [0xFE, 0x01, 0xFF], true));
                    if ($label !== 0xFE) {
                        $length = $label === 0x01 ? 12 : 11;
                        $reader->require($reader->unsigned(1) === $length);
                        $reader->skip($length);
                    }
                    $this->gifSubBlocks($reader);
                }
            } else {
                $reader->require(false);
            }
        }
    }

    private function gifPalette(MediaContainerReader $reader, int $flags): void
    {
        if (($flags & 0x80) !== 0) {
            $reader->skip(3 * (1 << (($flags & 7) + 1)));
        }
    }

    private function gifSubBlocks(MediaContainerReader $reader): int
    {
        $total = 0;
        while (($length = $reader->unsigned(1)) !== 0) {
            $reader->skip($length);
            $total += $length;
        }

        return $total;
    }

    private function riff(MediaContainerReader $reader, string $form): void
    {
        $reader->require($reader->read(4) === 'RIFF');
        $size = $reader->unsigned(4, true);
        $reader->require($size === $reader->remaining() && $reader->read(4) === $form);
        $data = false;
        $format = $form === 'WEBP';

        while ($reader->remaining() > 0) {
            $type = $reader->read(4);
            $length = $reader->unsigned(4, true);
            $reader->require($length + ($length % 2) <= $reader->remaining());
            if ($form === 'WEBP') {
                $data = $data || (in_array($type, ['VP8 ', 'VP8L', 'ANMF'], true) && $length > 0);
            } else {
                if ($type === 'fmt ') {
                    $reader->require($length >= 16);
                    $format = true;
                }
                $data = $data || ($type === 'data' && $length > 0);
            }
            $reader->skip($length);
            if ($length % 2 === 1) {
                $reader->require($reader->read(1) === "\0");
            }
        }
        $reader->require($format && $data);
    }

    private function ogg(MediaContainerReader $reader): void
    {
        $data = false;
        while ($reader->remaining() > 0) {
            $header = $reader->read(27);
            $reader->require(substr($header, 0, 4) === 'OggS' && $header[4] === "\0"
                && (ord($header[5]) & 0xF8) === 0);
            $segments = ord($header[26]);
            $length = 0;
            for ($index = 0; $index < $segments; $index++) {
                $length += $reader->unsigned(1);
            }
            // Packet continuation and multiplexing do not alter page boundaries.
            $reader->skip($length);
            $data = $data || $length > 0;
        }
        $reader->require($data);
    }

    private function mp3(MediaContainerReader $reader): void
    {
        $first = true;
        $audio = false;
        while ($reader->remaining() > 0) {
            $prefix = $reader->read(3);
            if ($first && $prefix === 'ID3') {
                $this->id3Tag($reader);
                $first = false;

                continue;
            }
            $first = false;
            if ($prefix === 'TAG') {
                $reader->require($audio && $reader->remaining() === 125);
                $reader->skip(125);

                break;
            }
            $length = $this->mpegFrameLength($reader, $prefix . $reader->read(1));
            $reader->skip($length - 4);
            $audio = true;
        }
        $reader->require($audio);
    }

    private function id3Tag(MediaContainerReader $reader): void
    {
        $fields = $reader->read(7);
        $version = ord($fields[0]);
        $flags = ord($fields[2]);
        $reader->require(in_array($version, [2, 3, 4], true) && ord($fields[1]) !== 0xFF);
        $reservedFlags = match ($version) {
            2 => 0x3F,
            3 => 0x1F,
            default => 0x0F,
        };
        $reader->require(($flags & $reservedFlags) === 0);
        $size = 0;
        for ($index = 3; $index < 7; $index++) {
            $byte = ord($fields[$index]);
            $reader->require($byte < 0x80);
            $size = $size * 128 + $byte;
        }
        $reader->skip($size);
        if ($version === 4 && ($flags & 0x10) !== 0) {
            $reader->require($reader->read(10) === '3DI' . $fields);
        }
    }

    private function mpegFrameLength(MediaContainerReader $reader, string $header): int
    {
        $second = ord($header[1]);
        $third = ord($header[2]);
        $version = ($second >> 3) & 3;
        $layer = ($second >> 1) & 3;
        $bitrateIndex = $third >> 4;
        $sampleRateIndex = ($third >> 2) & 3;
        $padding = ($third >> 1) & 1;
        // Free-format frames have no header-derived length and cannot be bounded.
        $reader->require(ord($header[0]) === 0xFF && ($second & 0xE0) === 0xE0
            && $version !== 1 && $layer !== 0 && $bitrateIndex > 0 && $bitrateIndex < 15
            && (ord($header[3]) & 3) !== 2);
        $bitrates = match (true) {
            $version === 3 && $layer === 3 => [32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448],
            $version === 3 && $layer === 2 => [32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
            $version === 3 => [32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
            $layer === 3 => [32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256],
            default => [8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
        };
        $bitrate = $bitrates[$bitrateIndex - 1] * 1000;
        $sampleRate = match ($sampleRateIndex) {
            0 => 44100,
            1 => 48000,
            2 => 32000,
            default => throw new RuntimeException('Unsupported media content.'),
        } >> match ($version) {
            3 => 0,
            2 => 1,
            default => 2,
        };
        if ($layer === 3) {
            return (intdiv(12 * $bitrate, $sampleRate) + $padding) * 4;
        }
        $samples = $layer === 1 && $version !== 3 ? 72 : 144;

        return intdiv($samples * $bitrate, $sampleRate) + $padding;
    }

    private function isoBoxes(MediaContainerReader $reader, string $mime): void
    {
        $fileSize = $reader->remaining();
        $first = true;
        $metadata = false;
        $data = false;
        $locations = null;
        $idat = null;

        while ($reader->remaining() > 0) {
            [$type, $length] = $this->isoBoxHeader($reader, $reader->remaining());
            if ($first) {
                $reader->require($type === 'ftyp' && $length >= 8 && $length % 4 === 0);
                $brand = $reader->read(4);
                $reader->skip(4);
                $avif = in_array($brand, ['avif', 'avis'], true);
                for ($left = $length - 8; $left > 0; $left -= 4) {
                    $brand = $reader->read(4);
                    $avif = $avif || in_array($brand, ['avif', 'avis'], true);
                }
                $reader->require(($mime === 'image/avif') === $avif);
                $first = false;
            } else {
                if ($mime === 'image/avif' && $type === 'meta') {
                    $reader->require(! $metadata);
                    [$locations, $idat] = $this->avifMetadata($reader, $length);
                    $metadata = true;
                    $data = $data || ($idat !== null && $idat > 0);
                } else {
                    $metadata = $metadata || ($mime === 'video/mp4' && $type === 'moov');
                    if ($type === 'mdat' && $length > 0) {
                        $data = true;
                    }
                    if ($mime === 'image/avif') {
                        $reader->require(! in_array($type, ['iloc', 'idat'], true));
                    }
                    $reader->skip($length);
                }
            }
        }
        $reader->require(! $first && $metadata && $data);
        if ($mime === 'image/avif') {
            foreach ($locations ?? [] as [$method, $offset, $length]) {
                $limit = $method === 0 ? $fileSize : $idat;
                $reader->require($limit !== null && $offset <= $limit && $length <= $limit - $offset);
            }
        }
    }

    /** @return array{string, int} */
    private function isoBoxHeader(MediaContainerReader $reader, int $available): array
    {
        $reader->require($available >= 8);
        $size = $reader->unsigned(4);
        $type = $reader->read(4);
        $headerSize = 8;
        if ($size === 1) {
            $reader->require($available >= 16);
            $size = $reader->unsigned(8);
            $headerSize = 16;
        }
        // Size zero means "until EOF", which cannot distinguish appended content.
        $reader->require($size >= $headerSize && $size <= $available
            && preg_match('/\A[\x20-\x7e]{4}\z/D', $type) === 1);

        return [$type, $size - $headerSize];
    }

    /** @return array{?list<array{int, int, int}>, ?int} */
    private function avifMetadata(MediaContainerReader $reader, int $length): array
    {
        $end = $reader->remaining() - $length;
        $reader->require($length >= 4 && $reader->read(4) === "\0\0\0\0");
        $locations = null;
        $idat = null;
        while ($reader->remaining() > $end) {
            [$type, $childLength] = $this->isoBoxHeader($reader, $reader->remaining() - $end);
            if ($type === 'iloc') {
                $reader->require($locations === null);
                $locations = $this->avifLocations($reader, $childLength);
            } else {
                $reader->require($type !== 'mdat');
                if ($type === 'idat') {
                    $reader->require($idat === null);
                    $idat = $childLength;
                }
                $reader->skip($childLength);
            }
        }

        return [$locations, $idat];
    }

    /** @return list<array{int, int, int}> */
    private function avifLocations(MediaContainerReader $reader, int $length): array
    {
        $end = $reader->remaining() - $length;
        $version = $this->avifUnsigned($reader, 1, $end);
        $reader->require($version <= 2 && $this->avifUnsigned($reader, 3, $end) === 0);
        $sizes = $this->avifUnsigned($reader, 2, $end);
        $offsetSize = ($sizes >> 12) & 15;
        $lengthSize = ($sizes >> 8) & 15;
        $baseOffsetSize = ($sizes >> 4) & 15;
        $indexSize = $sizes & 15;
        foreach ([$offsetSize, $lengthSize, $baseOffsetSize, $indexSize] as $size) {
            $reader->require(in_array($size, [0, 4, 8], true));
        }
        $reader->require($version > 0 || $indexSize === 0);
        $idSize = $version === 2 ? 4 : 2;
        $itemCount = $this->avifUnsigned($reader, $idSize, $end);
        $reader->require($itemCount <= self::MAX_AVIF_ITEMS);
        $items = [];
        $extents = [];
        for ($item = 0; $item < $itemCount; $item++) {
            $id = $this->avifUnsigned($reader, $idSize, $end);
            $reader->require($id > 0 && ! isset($items[$id]));
            $items[$id] = true;
            $method = $version === 0 ? 0 : $this->avifUnsigned($reader, 2, $end);
            // External data and item-offset construction cannot be proved locally.
            $reader->require($method <= 1 && $this->avifUnsigned($reader, 2, $end) === 0);
            $baseOffset = $this->avifUnsigned($reader, $baseOffsetSize, $end);
            $extentCount = $this->avifUnsigned($reader, 2, $end);
            $reader->require($extentCount <= self::MAX_AVIF_EXTENTS - count($extents));
            for ($extent = 0; $extent < $extentCount; $extent++) {
                // The index is only meaningful for unsupported item-offset construction.
                $reader->require($indexSize <= $reader->remaining() - $end);
                $reader->skip($indexSize);
                $offset = $this->avifUnsigned($reader, $offsetSize, $end);
                $extentLength = $this->avifUnsigned($reader, $lengthSize, $end);
                // Zero/omitted length uses the bounded data source's end.
                $reader->require($offset <= PHP_INT_MAX - $baseOffset);
                $extents[] = [$method, $baseOffset + $offset, $extentLength];
            }
        }
        $reader->require($reader->remaining() === $end);

        return $extents;
    }

    private function avifUnsigned(MediaContainerReader $reader, int $bytes, int $end): int
    {
        $reader->require($bytes <= $reader->remaining() - $end);

        return $reader->unsigned($bytes);
    }

    private function webm(MediaContainerReader $reader): void
    {
        $reader->require($reader->read(4) === "\x1a\x45\xdf\xa3");
        $reader->skip($this->ebmlLength($reader));
        $reader->require($reader->read(4) === "\x18\x53\x80\x67");
        $length = $this->ebmlLength($reader);
        $reader->require($length > 0 && $length === $reader->remaining());
        $reader->skip($length);
    }

    private function ebmlLength(MediaContainerReader $reader): int
    {
        $first = $reader->unsigned(1);
        $mask = 0x80;
        $bytes = 1;
        while ($mask > 0 && ($first & $mask) === 0) {
            $mask >>= 1;
            $bytes++;
        }
        $reader->require($mask > 0);
        $value = $first & ($mask - 1);
        $unknown = $value === $mask - 1;
        for ($index = 1; $index < $bytes; $index++) {
            $byte = $reader->unsigned(1);
            $value = $value * 256 + $byte;
            $unknown = $unknown && $byte === 0xFF;
        }
        // Unknown-length live streams have no provable end for imported files.
        $reader->require(! $unknown);

        return $value;
    }
}
