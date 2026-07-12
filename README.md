# Migration Assistant

<!-- prettier-ignore-start -->

## What This Extension Adds

Migration Assistant is an **Available**, **Schema-owning** Capell package in the **Capell Operations** product group. It ships as `capell-app/migration-assistant` and extends these surfaces: admin, console.

Migration Assistant is Capell's content-migration engine: upload a content package or flat file (CSV/XML), review and resolve incoming relations, run a dry-run validation, then execute on a queue with live progress. Every run produces a rollback report capturing exactly which records were created, so nothing is a one-way door. It is also the foundation other importers build on - the WordPress Importer plugs straight in - making it the first thing you install when bringing existing pages into Capell. Media is deduplicated by checksum and large payloads are size-guarded, so imports stay safe and idempotent.

After install, admins get package-owned management or reporting surfaces inside Capell.

Status details:

- Status: Available
- Tier: premium
- Bundle: operations
- Composer package: `capell-app/migration-assistant`
- Namespace: `Capell\MigrationAssistant`
- Theme key: not applicable

## Why It Matters

**For developers:** The package gives developers package-owned service providers, Actions, Data objects, models, and Filament classes instead of pushing this behaviour into core or application code.

**For teams:** Safely move pages and media into Capell - preview every change, validate before you write, and keep a rollback report for every import.

## Screens And Workflow

Screenshot contract: `docs/screenshots.json`.

- Import session index or host admin surface (admin, required).
- Import validation summary (admin, required).
- Recovery page imports (admin, required).
- Relation resolution review (admin, required).
- Rollback report view (admin, required).
- Package export intent screen (admin, required).

## Technical Shape

- Service providers: `Capell\MigrationAssistant\Providers\MigrationAssistantServiceProvider`.
- Config files: `packages/migration-assistant/config/migration-assistant.php`.
- Migrations: `packages/migration-assistant/database/migrations/2026_05_10_190859_01_create_import_sessions_table.php`, `packages/migration-assistant/database/migrations/2026_05_10_190859_02_create_import_rollback_reports_table.php`, `packages/migration-assistant/database/migrations/2026_06_04_000001_rename_import_rollback_reports_table.php`.
- Models: `ImportRollbackReport`, `ImportSession`.
- Filament classes: `ImportPagesPage`, `ImportSitesPage`, `ImportSessionResource`, `ListImportSessions`, `ViewImportSession`, `ImportSessionInfolist`, `ImportSessionsTable`.
- Policies: `ImportSessionPolicy`.
- Events: `ImportCompleted`, `ImportFailed`.
- Listeners: `SendImportSessionNotifications`.
- Actions: `BuildImportValidationSummaryAction`, `BuildPageReviewRows`, `BuildRelationResolveRowsAction`, `CancelImportSessionAction`, `ClaimImportSessionForExecutionAction`, `CreateImportRollbackReportAction`, `ExecuteImportRollbackAction`, `AdvancePageImportToValidationAction`, `DispatchPageImportAction`, `ExecuteExternalPageImportAction`, `RefreshPageImportStatusAction`, `ResolvePageImportConfirmationTargetAction`, `and 5 more`.
- Data objects: `DependencyGraph`, `ExportOptions`, `ExternalImportPreview`, `ExternalImportReadResult`, `ImportValidationSummary`, `ExternalPageImportExecutionResult`, `PageImportDecisionData`, `PageImportStatusData`, `PageImportWizardStateData`, `PackageManifest`, `PageImportTargetData`, `PageReviewRow`, `and 2 more`.
- Jobs: `ExecuteImportPlanJob`.
- Command signatures: `migration-assistant:export`, `migration-assistant:import`, `migration-assistant:rollback-execute`, `migration-assistant:rollback-report`, `migration-assistant:status`.
- Console command classes: `ExecuteMigrationAssistantRollbackCommand`, `ExportMigrationAssistantPackageCommand`, `ImportMigrationAssistantPackageCommand`, `ShowMigrationAssistantRollbackReportCommand`, `ShowMigrationAssistantStatusCommand`.
- Manifest contributions: `admin-page: Capell\MigrationAssistant\Manifest\ImportPagesPageContribution`, `admin-page: Capell\MigrationAssistant\Manifest\ImportSitesPageContribution`, `admin-resource: Capell\MigrationAssistant\Manifest\ImportSessionResourceContribution`, `configurator: Capell\MigrationAssistant\Manifest\MigrationAssistantConsoleCommandsContribution`, `health-check: Capell\MigrationAssistant\Manifest\MigrationAssistantHealthContribution`, `model: Capell\MigrationAssistant\Manifest\MigrationAssistantModelsContribution`, `permission: Capell\MigrationAssistant\Manifest\MigrationAssistantPermissionsContribution`.
- Health checks: `Capell\MigrationAssistant\Health\MigrationAssistantHealthCheck`.

## Data Model

- Required tables: `import_sessions`, `import_rollback_reports`.
- Models: `ImportRollbackReport`, `ImportSession`.
- Migration files: `2026_05_10_190859_01_create_import_sessions_table.php`, `2026_05_10_190859_02_create_import_rollback_reports_table.php`, `2026_06_04_000001_rename_import_rollback_reports_table.php`.
- Migration impact: run host migrations through the package install flow before opening package surfaces.
- Deletion/retention behaviour: Docs gap unless the package has an explicit pruning command, retention setting, or tested cascade path.

## Install Impact

- Admin navigation: adds package-owned Filament classes when registered.
- Permissions: `page.export`, `page.import`, `page.import.update-shared-relations`, `page.import.publish-live`, `import-session.view`, `import-session.cancel`, `import-session.retry`.
- Public routes: none detected in package route files.
- Database changes: package migrations are declared.
- Settings: no package settings declared.
- Queues or schedules: review package jobs or schedules before install.
- Cache tags: none declared.
- Commands: `migration-assistant:export`, `migration-assistant:import`, `migration-assistant:rollback-execute`, `migration-assistant:rollback-report`, `migration-assistant:status`.

## Common Pitfalls

- Run migrations before opening package resources or public routes.
- Run package commands from the host app; in this repository use `vendor/bin/pest` for package tests.
- Keep `composer.json`, `composer.local.json`, `capell.json`, docs, screenshots, and tests aligned when the package surface changes.

## Troubleshooting

| Symptom | Likely cause | Check | Fix |
| --- | --- | --- | --- |
| Package surface is missing after install | Provider or manifest is not loaded | Confirm `capell.json`, package `composer.json`, and provider registration | Reinstall the package, refresh Composer autoload, and clear host caches |
| Admin screen or command fails on missing table | Package migrations have not run | Check the tables listed in `Data Model` | Run host migrations and rerun the focused package test |
| Background work does not run | Queue worker or scheduled command is not active | Check package jobs, commands, and host scheduler configuration | Start the queue or scheduler, then run the focused command or package test |

## Quick Start

1. Install the package: `composer require capell-app/migration-assistant`.
2. Run the required setup: `php artisan migrate`.
3. Open the related Capell admin surface and verify Migration Assistant appears.

## Next Steps

- [Package docs](docs/README.md)
- [Overview](docs/overview.md)
- [Screenshot contract](docs/screenshots.json)
- [Marketplace assets](docs/assets/marketplace/)
- [Capell content language plan](../../docs/CONTENT_LANGUAGE_PLAN.md)
- [Capell documentation design system](../../docs/DESIGN_SYSTEM.md)
- [Capell and package ERD notes](../../docs/erd/capell-and-package-erds.md)
- Related packages: [Media Library](../media-library/README.md), [Seo Suite](../seo-suite/README.md), [Site Discovery](../site-discovery/README.md), [Url Manager](../url-manager/README.md), [Wordpress Importer](../wordpress-importer/README.md).
- Focused tests: `vendor/bin/pest packages/migration-assistant/tests --configuration=phpunit.xml`.

<!-- prettier-ignore-end -->
