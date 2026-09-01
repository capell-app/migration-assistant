<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Services\Import\Resolvers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Match a shared relation by a structural fingerprint of its canonical
 * schema definition.
 *
 * Use for Layouts and Types, where the developer-chosen "key" might drift
 * across environments but the field schema (columns in the admin/meta
 * JSON) is identical. We build a fingerprint by recursively sorting the
 * relevant JSON structures by key, stripping volatile fields (timestamps,
 * ids), json-encoding, and sha256-ing the result.
 *
 * Confidence is intentionally 0.7 — below uuid/key (1.0) and slightly
 * higher than a normalised-name fallback. A fingerprint match says "this
 * record has an identical schema shape", which is stronger than "the
 * name looks similar" but weaker than "the stable key matched".
 *
 * @template TModel of Model
 */
final readonly class FingerprintMatchResolver implements MatchResolver
{
    private const float CONFIDENCE = 0.7;

    private const array VOLATILE_KEYS = [
        'id',
        'uuid',
        'created_at',
        'updated_at',
        'deleted_at',
        'site_id',
        'theme_id',
        'order',
    ];

    /**
     * @param  class-string<TModel>  $modelClass
     * @param  list<string>  $schemaColumns  attribute names that together form the canonical schema
     * @param  bool  $scopeToSite  restrict candidates to $siteId plus records with a null
     *                             site_id. Only enable for models with a nullable site_id
     *                             column (e.g. Layout) — never for models without one.
     */
    public function __construct(
        private string $modelClass,
        private array $schemaColumns = ['admin', 'meta'],
        private bool $scopeToSite = false,
    ) {}

    public function resolve(array $descriptor, ?int $siteId = null): ?MatchResolution
    {
        $attributes = $descriptor['attributes'] ?? null;
        if (! is_array($attributes)) {
            return null;
        }

        $incomingFingerprint = $this->fingerprint($attributes);
        if ($incomingFingerprint === null) {
            return null;
        }

        $model = new $this->modelClass;
        $keyName = $model->getKeyName();

        $query = $this->modelClass::query()
            ->select(array_values(array_unique([$keyName, ...$this->schemaColumns])))
            ->orderBy($keyName);

        if ($this->scopeToSite) {
            $query->where(function (Builder $query) use ($siteId): void {
                $query->whereNull('site_id');

                if ($siteId !== null) {
                    $query->orWhere('site_id', $siteId);
                }
            });
        }

        /** @var iterable<Model> $candidates */
        $candidates = $query->cursor();

        foreach ($candidates as $candidate) {
            $localAttributes = $candidate->attributesToArray();
            if ($this->fingerprint($localAttributes) === $incomingFingerprint) {
                return new MatchResolution(
                    localId: $candidate->getKey(),
                    strategy: 'fingerprint',
                    confidence: self::CONFIDENCE,
                    reason: 'schema fingerprint match',
                );
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fingerprint(array $attributes): ?string
    {
        $structure = [];
        foreach ($this->schemaColumns as $column) {
            $structure[$column] = $this->normaliseValue($attributes[$column] ?? null);
        }

        $hasContent = array_any($structure, fn ($value): bool => ! in_array($value, [null, [], ''], true));

        if (! $hasContent) {
            return null;
        }

        return hash('sha256', json_encode($structure, JSON_THROW_ON_ERROR));
    }

    private function normaliseValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $normalised = [];
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array($key, self::VOLATILE_KEYS, true)) {
                continue;
            }

            $normalised[$key] = $this->normaliseValue($child);
        }

        if (! $isList) {
            ksort($normalised);
        }

        return $normalised;
    }
}
