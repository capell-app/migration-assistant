<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support\Upgrade;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Capell\MigrationAssistant\Actions\InstallMigrationAssistantPermissionsAction;
use Override;

/**
 * Sites installed before permissions moved out of the boot path get the
 * permission matrix here, during `capell:upgrade`. Safe to run again.
 */
final class InstallMigrationAssistantPermissionsUpgradeStep extends AbstractUpgradeStep
{
    /** The container tag Core's upgrade runner collects steps from; Core owns the name and reads it as a literal. */
    public const string TAG = 'capell.upgrade-steps';

    #[Override]
    public function id(): string
    {
        return 'migration-assistant.install-permissions';
    }

    #[Override]
    public function label(): string
    {
        return 'Install Migration Assistant permissions';
    }

    #[Override]
    public function package(): string
    {
        return 'capell-app/migration-assistant';
    }

    #[Override]
    public function run(UpgradeContext $context): bool
    {
        InstallMigrationAssistantPermissionsAction::run();

        return true;
    }

    /** The permissions are shared roles data and stay in place; there is nothing to undo. */
    #[Override]
    public function rollback(UpgradeContext $context): bool
    {
        return true;
    }
}
