<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Services\Import\Resolvers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Match by stable key, falling back to a normalised-name lookup.
 *
 * Use when an incoming record has a developer-chosen identifier (layout
 * "key", type "key", site "slug"). The resolver prefers that exact match
 * because it survives renames; it only normalises the display name as a
 * last resort for packages produced by older exporters that lacked keys.
 *
 * @template TModel of Model
 */
final readonly class KeyedMatchResolver implements MatchResolver
{
    /**
     * @param  class-string<TModel>  $modelClass
     * @param  bool  $scopeToSite  restrict matches to $siteIds plus records with a null
     *                             site_id. Only enable for models with a nullable site_id
     *                             column (e.g. Layout) — never for models without one.
     */
    public function __construct(
        private string $modelClass,
        private string $keyColumn = 'key',
        private ?string $nameColumn = 'name',
        private bool $scopeToSite = false,
    ) {}

    /**
     * @param  list<int>  $siteIds
     */
    public function resolve(array $descriptor, array $siteIds = []): ?MatchResolution
    {
        $key = $descriptor[$this->keyColumn] ?? null;
        if (is_string($key) && $key !== '') {
            $model = $this->scopedQuery($siteIds)->where($this->keyColumn, $key)->first();
            if ($model instanceof Model) {
                return new MatchResolution(localId: $model->getKey(), strategy: $this->keyColumn);
            }
        }

        if ($this->nameColumn !== null) {
            $name = $descriptor[$this->nameColumn] ?? null;
            if (is_string($name) && $name !== '') {
                $normalised = $this->normalise($name);
                $modelClass = $this->modelClass;
                $wrappedNameColumn = (new $modelClass)->getConnection()->getQueryGrammar()->wrap($this->nameColumn);
                /** @var literal-string $normalisedNamePredicate */
                $normalisedNamePredicate = sprintf('LOWER(TRIM(%s)) = ?', $wrappedNameColumn);
                $model = $this->scopedQuery($siteIds)
                    ->whereRaw($normalisedNamePredicate, [$normalised])
                    ->first();
                if ($model instanceof Model) {
                    return new MatchResolution(
                        localId: $model->getKey(),
                        strategy: $this->nameColumn . ':normalised',
                        confidence: 0.7,
                    );
                }
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private function scopedQuery(array $siteIds): Builder
    {
        $query = $this->modelClass::query();

        if (! $this->scopeToSite) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($siteIds): void {
            $query->whereNull('site_id');

            if ($siteIds !== []) {
                $query->orWhereIn('site_id', $siteIds);
            }
        });
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
