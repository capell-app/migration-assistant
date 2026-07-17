## What it does for you

Migration Assistant imports a Capell page or site archive into this Capell installation. It stages the archive as an import session, lets you review proposed page and relation decisions, validates the plan, then queues the import for execution.

## Setup requirements

Run the package migrations and keep a queue worker listening to the `migration-assistant` queue (or the connection and queue configured with `MIGRATOR_QUEUE_CONNECTION` and `MIGRATOR_QUEUE`). An import job can run for up to 15 minutes and tries three times, with one- and five-minute backoffs.

Keep Laravel's scheduler running. `migration-assistant:reclaim-stale` runs every ten minutes and requeues **Running** sessions that have not updated for 30 minutes by default. This recovers an interrupted worker; it does not reverse any records already written.

Completion and failure notifications are queued and encrypted. They go to the initiating user and any roles configured for that outcome, through the configured channels (mail and database by default). Keep both the queue worker and the selected notification channels operational if operators rely on those alerts.

## Start an import

Open **Recovery Center → Import pages** or **Import sites**. Upload a ZIP archive, enter a required workspace name, and optionally add a note. After the archive is read, work through the wizard in order:

1. Review the proposed page decisions.
2. Resolve imported relations when the wizard surfaces them. If no relation decisions are needed, it moves directly to validation.
3. Validate the plan and resolve any blocking errors.
4. Enter the required confirmation text when shown, then dispatch the import.

Dispatching queues the work; it does not complete the import in the browser. The wizard shows the queued/running/completed/failed status and links to the imported workspace when one is available.

The worker reopens the archive and rechecks its integrity, unresolved references, the initiating account, `page.import` permission, target, and current site access immediately before writing. Removing the account, permission, or site assignment while an import is queued therefore makes the session fail closed. Updating an existing shared relation additionally requires `page.import.update-shared-relations`.

Uploaded archives are size- and integrity-checked. Defaults allow 1 MB metadata JSON entries, 5 MB payload JSON entries, 50 MB media files, and 250 MB total uncompressed package data. Media is verified by SHA-256 and an existing media record is reused only when its checksum and stored file are usable. These controls reduce import risk, but do not replace reviewing the source and taking a backup.

## Review an import session

Open **System → Import Sessions** to filter sessions by kind, status, or initiating user. This resource is restricted to global administrators with `import-session.view`; it is not site-scoped. Open a session to inspect its timeline, validation report, result summary, page decisions, relation decisions, and manifest. A failed session also shows its failure reason.

If a **Running** session has become stale, the list may show **Recover stale imports**. This is a confirmed recovery action for stale sessions; it is not a rollback of completed imported content.

An operator with `import-session.cancel` can cancel a Draft, Parsed, Mapped, Validated, or Queued session. Cancellation does not stop a Running job. A failed session shows **Retry** only to an operator with `import-session.retry` and only while the source archive and stored decisions still make that session retriable; otherwise start a new import from the original archive.

## Roll back created records

A successful import creates a signed rollback report listing records that the execution report says it created. Rollback is a CLI-only recovery operation; there is no rollback button on the Import Session screen. Against a current backup, first inspect it with `migration-assistant:rollback-report <session-id-or-uuid>`, then use `migration-assistant:rollback-execute <session-id-or-uuid> --actor=<user-id> --dry-run` to preview the deletions.

Only then run `migration-assistant:rollback-execute` again without `--dry-run`. The named actor must still be active, hold `import-session.rollback` for every affected site, retain the relevant site assignments, and be allowed to delete each record. The command rejects a tampered report, deletes unchanged created records in reverse order, skips missing or subsequently changed records, and writes an audit row for every attempt.

This is not a database restore. It only considers records captured as created by the import; it does not promise to undo edits to reused records, later editorial changes, published copies, external side effects, or backups. Review the report's manual instructions and the dry-run skips before proceeding.

## Data handling and retention

Session manifests, decisions, resolution maps, validation/results, archive paths, target URLs, failure reasons, and most rollback details are encrypted at rest. Rollback provenance and its signature remain readable so they can be verified. Import archives and content can still contain personal or confidential data, so restrict the configured disk, working directories, queue payloads, notifications, logs, and backups.

The standard queued executor normally deletes its staged upload after successful completion and on most terminal failure paths, so do not treat Migration Assistant as archive storage. Import sessions, rollback reports, and rollback audit rows have no scheduled retention cleanup in this package; define a retention process that preserves operational and compliance evidence for only as long as required.

## Good to know

- Import pages and sites are separate entry points, but use the same review, resolution, validation, and queued-execution model.
- Imported work is staged in the selected workspace; dispatching the import does not publish it. Migration Assistant exposes `page.import.publish-live` for a downstream auto-publish integration, but the current wizard only stages the imported work for a separate editorial operation.
- Review every proposed decision and create a backup before importing. Migration Assistant does not make an archive safe to import merely because it is a ZIP file.
