<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Contracts;

use Filament\Navigation\NavigationItem;

interface ImportSessionSubNavigationExtender
{
    public const string TAG = 'capell-admin:import-session-sub-navigation-extender';

    /** @return array<int, NavigationItem> */
    public function getItems(): array;
}
