# Migration Assistant reference

<!-- prettier-ignore-start -->

Developer reference for `capell-app/migration-assistant`. Start with the [README](../README.md) for what the extension does and how to install it.

## Package Details

Migration Assistant is an **Available**, **Schema-owning** Capell package in the **Capell Operations** product group. It ships as `capell-app/migration-assistant` and extends these surfaces: admin, console.

- Status: Available
- Tier: free
- Bundle: operations
- Composer package: `capell-app/migration-assistant`
- Namespace: `Capell\MigrationAssistant`
- Theme key: not applicable

## Technical Shape

### Service providers

- `Capell\MigrationAssistant\Providers\MigrationMetadataServiceProvider`
- `Capell\MigrationAssistant\Providers\MigrationAssistantInstallServiceProvider`
- `Capell\MigrationAssistant\Providers\MigrationAssistantServiceProvider`
- `Capell\MigrationAssistant\Providers\AdminServiceProvider`

### Config files

- `packages/migration-assistant/config/migration-assistant.php`

### Migrations

- `packages/migration-assistant/database/migrations/2026_05_10_190859_01_create_import_sessions_table.php`
- `packages/migration-assistant/database/migrations/2026_05_10_190859_02_create_import_rollback_reports_table.php`
- `packages/migration-assistant/database/migrations/2026_06_04_000001_rename_import_rollback_reports_table.php`
- `packages/migration-assistant/database/migrations/2026_07_10_000002_encrypt_import_diagnostics.php`
- `packages/migration-assistant/database/migrations/2026_07_10_120000_harden_import_rollback_provenance.php`
- `packages/migration-assistant/database/migrations/2026_10_18_000001_add_import_session_legal_hold.php`
- `packages/migration-assistant/database/migrations/2026_10_29_000001_create_import_source_keys_table.php`
- `packages/migration-assistant/database/migrations/2026_10_29_000002_add_rollback_before_images.php`
- `packages/migration-assistant/database/migrations/2026_10_30_000001_add_source_identity_to_import_source_keys.php`
- `packages/migration-assistant/database/migrations/2026_10_30_000002_create_database_source_labels_table.php`

### Models

- `ImportRollbackAudit`
- `ImportRollbackReport`
- `ImportSession`

### Filament classes

- `ImportPagesPage`
- `ImportSitesPage`
- `ImportSessionResource`
- `ListImportSessions`
- `ViewImportSession`
- `ImportSessionInfolist`
- `ImportSessionsTable`

### Policies

- `ImportSessionPolicy`

### Extension contracts

- `DatabaseImportSessionExecutor`
- `ImportSessionExecutor`
- `ImportSessionSubNavigationExtender`
- `ImportSourceReader`
- `MigrationAssistantContextResolver`
- `MigrationAssistantRowContributor`
- `NullMigrationAssistantContextResolver`
- `NullMigrationAssistantRowContributor`
- `NullPageCollisionDetector`
- `NullPageImportTargetResolver`
- `PageCollisionDetector`
- `PageImportTargetResolver`
- `PathAwareImportSourceReader`
- `SafeDatabaseFailureContext`
- `SafeFailureMessage`

### Events

- `ImportCompleted`
- `ImportCompleting`
- `ImportFailed`

### Listeners

- `SendImportSessionNotifications`

### Actions

- `AssertDatabaseSourceLabelBoundAction`
- `AssertDedicatedImportProcessAction`
- `AssertInPlaceDatabaseIdentityAction`
- `AuthorizeInPlaceRollbackActorAction`
- `BuildImportRecoveryStatusAction`
- `BuildImportValidationSummaryAction`
- `BuildPageReviewRowsAction`
- `BuildRelationResolveRowsAction`
- `CancelImportSessionAction`
- `ClaimImportSessionForExecutionAction`
- `CreateDatabaseRollbackReportAction`
- `CreateImportRollbackReportAction`
- `ExecuteImportRollbackAction`
- `FinalisePlannedContentGraphAction`
- `AdvancePageImportToValidationAction`
- `AuthorizeExternalPageImportTargetAction`
- `BindMigrationArchiveUploadAction`
- `DispatchPageImportAction`
- `ExecuteExternalPageImportAction`
- `RefreshPageImportStatusAction`
- `ResolvePageImportConfirmationTargetAction`
- `ResolvePageImportSessionAction`
- `StartPageImportAction`
- `StartSiteImportAction`
- `InspectDatabaseImportWritePlanAction`
- `InstallMigrationAssistantPermissionsAction`
- `InvalidateRollbackCachesAction`
- `PrepareMigrationScreenshotDetailAction`
- `PruneImportEvidenceAction`
- `ReauthorizeImportSessionActorAction`
- `ReauthorizeImportSessionExecutionAction`
- `ReclaimStaleImportSessionsAction`
- `RegisterDatabaseSourceLabelAction`
- `RetryImportSessionAction`
- `SetImportLegalHoldAction`
- `StoreImportedMediaAction`

### Data objects

- `DatabaseImportWritePlan`
- `DatabaseRollbackChange`
- `DatabaseSourceManifest`
- `DependencyGraph`
- `ExportOptions`
- `ExternalImportPreview`
- `ExternalImportReadResult`
- `ExternalPageImportTargetData`
- `ImportRecoveryStatusData`
- `ImportRetentionReportData`
- `ImportValidationSummary`
- `ExternalPageImportExecutionResult`
- `PageImportDecisionData`
- `PageImportStatusData`
- `PageImportWizardStateData`
- `PackageManifest`
- `PageImportTargetData`
- `PageReviewRow`
- `RelationResolveRow`
- `RollbackExecutionResultData`
- `SourceKeyImportResult`

### Jobs

- `ExecuteImportPlanJob`

### Command signatures

- `migration-assistant:export`
- `migration-assistant:import`
- `migration-assistant:prune`
- `migration-assistant:reclaim-stale`
- `migration-assistant:rollback-execute`
- `migration-assistant:rollback-report`
- `migration-assistant:status`

### Scheduled commands

- `migration-assistant:reclaim-stale (everyTenMinutes; manifest declared)`

### Console command classes

- `ExecuteMigrationAssistantRollbackCommand`
- `ExportMigrationAssistantPackageCommand`
- `ImportMigrationAssistantPackageCommand`
- `PruneImportEvidenceCommand`
- `ReclaimStaleImportSessionsCommand`
- `ShowMigrationAssistantRollbackReportCommand`
- `ShowMigrationAssistantStatusCommand`

### Manifest contributions

- `admin-page: Capell\MigrationAssistant\Manifest\ImportPagesPageContribution`
- `admin-page: Capell\MigrationAssistant\Manifest\ImportSitesPageContribution`
- `admin-resource: Capell\MigrationAssistant\Manifest\ImportSessionResourceContribution`
- `console-command: Capell\MigrationAssistant\Manifest\MigrationAssistantConsoleCommandsContribution`
- `health-check: Capell\MigrationAssistant\Manifest\MigrationAssistantHealthContribution`
- `model: Capell\MigrationAssistant\Manifest\MigrationAssistantModelsContribution`
- `permission: Capell\MigrationAssistant\Manifest\MigrationAssistantPermissionsContribution`
- `scheduled-job: Capell\MigrationAssistant\Manifest\MigrationAssistantRecoveryScheduleContribution`

### Health checks

- `Capell\MigrationAssistant\Health\MigrationAssistantHealthCheck`

### Blade views

- `packages/migration-assistant/resources/views/pages/import-pages.blade.php`


## Data Model

- Required tables: `import_sessions`, `import_rollback_reports`, `import_rollback_audits`, `import_source_keys`, `database_source_labels`, `database_source_label_locks`.
- Models: `ImportRollbackAudit`, `ImportRollbackReport`, `ImportSession`.
- Core record references in migrations: `users via user_id`.
- Migration files: `2026_05_10_190859_01_create_import_sessions_table.php`, `2026_05_10_190859_02_create_import_rollback_reports_table.php`, `2026_06_04_000001_rename_import_rollback_reports_table.php`, `2026_07_10_000002_encrypt_import_diagnostics.php`, `2026_07_10_120000_harden_import_rollback_provenance.php`, `2026_10_18_000001_add_import_session_legal_hold.php`, `2026_10_29_000001_create_import_source_keys_table.php`, `2026_10_29_000002_add_rollback_before_images.php`, `2026_10_30_000001_add_source_identity_to_import_source_keys.php`, `2026_10_30_000002_create_database_source_labels_table.php`.
- Migration impact: run host migrations through the package install flow before opening package surfaces.
- Deletion/retention behaviour: migrations declare cascade-on-delete relationships and null-on-delete relationships; no timed pruning or retention schedule is declared in `capell.json`.

<!-- prettier-ignore-end -->
