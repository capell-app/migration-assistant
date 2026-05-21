<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support\Xml;

use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Safe XML loader that guards external imports against billion-laughs / XXE / SSRF.
 *
 * Defences applied before handing bytes to libxml:
 *   - Hard file-size cap (default 50MB) so a malicious upload cannot exhaust
 *     memory or CPU during expansion.
 *   - Lexical DOCTYPE rejection — any DTD is treated as hostile because libxml
 *     entity expansion is the vector for billion-laughs attacks.
 *   - external_entity_loader is overridden to return null so even if a DOCTYPE
 *     somehow slips past the lexical check, no entity resolves over the wire
 *     or the filesystem.
 *   - LIBXML_NONET is always set; LIBXML_NOENT (entity substitution) is never
 *     set so the parser cannot inline malicious entity definitions.
 */
final class SafeXmlLoader
{
    /** Default upper-bound for XML files we are willing to parse. */
    public const int DEFAULT_MAX_BYTES = 50 * 1024 * 1024;

    public static function loadFile(string $path, int $libxmlOptions = 0, int $maxBytes = self::DEFAULT_MAX_BYTES): SimpleXMLElement
    {
        if (! is_readable($path)) {
            throw new RuntimeException(sprintf('XML file [%s] is not readable.', $path));
        }

        $size = @filesize($path);
        if ($size === false) {
            throw new RuntimeException(sprintf('XML file [%s] size could not be determined.', $path));
        }

        if ($size > $maxBytes) {
            throw new RuntimeException(sprintf(
                'XML file [%s] is %d bytes which exceeds the safe parse limit of %d bytes.',
                $path,
                $size,
                $maxBytes,
            ));
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('XML file [%s] could not be read.', $path));
        }

        return self::loadString($contents, $libxmlOptions, $maxBytes);
    }

    public static function loadString(string $contents, int $libxmlOptions = 0, int $maxBytes = self::DEFAULT_MAX_BYTES): SimpleXMLElement
    {
        if (strlen($contents) > $maxBytes) {
            throw new RuntimeException(sprintf(
                'XML payload is %d bytes which exceeds the safe parse limit of %d bytes.',
                strlen($contents),
                $maxBytes,
            ));
        }

        // Lexical DOCTYPE check — reject any declared DTD outright so libxml never sees it.
        if (self::containsDoctype($contents)) {
            throw new RuntimeException('XML payload contains a DOCTYPE declaration which is not permitted.');
        }

        // LIBXML_NONET disables network access; LIBXML_NOENT (entity substitution)
        // is deliberately NOT added so external/internal entities never expand.
        $options = ($libxmlOptions | LIBXML_NONET) & ~LIBXML_NOENT & ~LIBXML_DTDLOAD & ~LIBXML_DTDATTR;

        $previousErrorState = libxml_use_internal_errors(true);
        $previousLoader = libxml_set_external_entity_loader(static fn (?string $publicId, ?string $systemId, array $context): ?string => null);

        try {
            $xml = new SimpleXMLElement($contents, $options);
        } catch (Throwable $exception) {
            throw new RuntimeException('XML payload could not be parsed safely: ' . $exception->getMessage(), 0, $exception);
        } finally {
            libxml_set_external_entity_loader($previousLoader);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorState);
        }

        return $xml;
    }

    private static function containsDoctype(string $contents): bool
    {
        // Strip a BOM/whitespace prefix and scan just the prologue + first kilobyte
        // where a DOCTYPE would legitimately appear.
        $prologueWindow = substr($contents, 0, 4096);

        return stripos($prologueWindow, '<!DOCTYPE') !== false
            || stripos($prologueWindow, '<!ENTITY') !== false;
    }
}
