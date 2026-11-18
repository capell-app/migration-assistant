<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Support\Install\NullProgressReporter;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Override;

/**
 * Install step for the package lifecycle: the permission matrix is written once,
 * when the package is installed, never while a request or command boots.
 */
final class InstallMigrationAssistantPackageAction implements PackageLifecycleAction
{
    use AsFake;
    use AsObject;

    #[Override]
    public function handle(PackageData $package, array $arguments = [], ?ProgressReporter $reporter = null): void
    {
        $reporter ??= new NullProgressReporter;

        InstallMigrationAssistantPermissionsAction::run();

        $reporter->report((string) __('capell-migration-assistant::commands.install.permissions_installed'));
    }
}
