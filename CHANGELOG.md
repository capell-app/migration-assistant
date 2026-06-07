# Changelog

All notable changes to `capell-app/migration-assistant` will be documented in this file.

## Unreleased

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
