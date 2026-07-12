<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;

final class ImportTargetRegistry
{
    /** @var array<string, class-string> */
    private array $targets = [
        'page' => Page::class,
        'type' => Blueprint::class,
        'collection' => Page::class,
    ];

    /**
     * @param  class-string  $modelClass
     */
    public function register(string $key, string $modelClass): void
    {
        $this->targets[$key] = $modelClass;
    }

    /**
     * @return array<string, class-string>
     */
    public function all(): array
    {
        return $this->targets;
    }
}
