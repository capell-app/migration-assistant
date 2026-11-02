# Changelog

All notable changes to `capell-app/migration-assistant` will be documented in this file.

## Unreleased

- A rollback marks the cached URLs of the affected sites stale when the HTML cache is installed.
- Refuse a PostgreSQL source identity for a role without EXECUTE on `pg_control_system()` and name the missing privilege.
- Rollback reports can journal created role assignments (`model_has_roles`), and rolling back a created row purges it instead of soft-deleting it.
- Bound database source labels and manifests to the live server identity, and refuse a prepared session whose label has since been repointed.
- Stored database import failures now include the underlying exception class and message, without row values.
- Added guarded database-source manifests, persistent source-key tracking, and encrypted before-images for database import rollback.

- Added structured import execution errors and corrected the manifest admin wizard query budget to a pinned `120`.
- Added `migration-assistant:export` and `migration-assistant:import` for headless package export/import workflows in CI and scripted migrations.
- Added `ExecuteImportRollbackAction` and `migration-assistant:rollback-execute` for dry-run capable rollback execution from recorded created models.
- Corrected rollback report storage from `import_rollback_rollback-report` to `import_rollback_reports`, including a safe rename migration for existing installs.
- Added `migration-assistant:status` and `migration-assistant:rollback-report` console commands for headless status and rollback-report audits.
- Scoped manifest permissions and health checks to shipped page-import behavior while site import remains a hidden placeholder.
- Moved rollback instructions into package translations.

## 2026-06-03

- Rewrote the marketplace summary, manifest description, and composer description to be buyer-facing (preview, validate, rollback).
- Promoted the existing desktop hero plus session-index, validation-summary, relation-resolution, rollback-report, and package-export screenshots into the marketplace manifest.
- Replaced the stub `MigrationAssistantHealthCheck` with real diagnostics covering storage tables, model morph aliases, source readers, and the media ingest limit.
- Removed the unused `WordPressImport` and `SpreadsheetImport` import-session kinds that were never assigned to a session.
- Repaired garbled prose ("rollback-report", "Migration Assistant") in the collision-detector contract, review-row builder, overview/workflow docs, and the boost guideline.
