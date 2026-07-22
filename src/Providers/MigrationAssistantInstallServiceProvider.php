<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Providers;

use Capell\MigrationAssistant\Actions\InstallMigrationAssistantPermissionsAction;
use Illuminate\Support\ServiceProvider;

final class MigrationAssistantInstallServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        InstallMigrationAssistantPermissionsAction::run();
    }
}
