# Changelog

All notable changes to `capell-app/migration-assistant` will be documented in this file.

## Unreleased

- Prepared package metadata and documentation for ongoing Capell 4.x package work.

## 2026-06-03

- Rewrote the marketplace summary, manifest description, and composer description to be buyer-facing (preview, validate, rollback).
- Promoted the existing desktop hero plus session-index, validation-summary, relation-resolution, rollback-report, and package-export screenshots into the marketplace manifest.
- Replaced the stub `MigrationAssistantHealthCheck` with real diagnostics covering storage tables, model morph aliases, source readers, and the media ingest limit.
- Removed the unused `WordPressImport` and `SpreadsheetImport` import-session kinds that were never assigned to a session.
- Repaired garbled prose ("dashboard-dashboard_reports", "Migration AIOrchestrator") in the collision-detector contract, review-row builder, overview/workflow docs, and the boost guideline.
