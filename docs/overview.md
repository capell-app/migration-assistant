# Migration Assistant

<!-- prettier-ignore-start -->

## What it does for you

Migration Assistant imports a Capell page or site archive into this Capell installation. It stages the archive as an import session, lets you review proposed page and relation decisions, validates the plan, then queues the import for execution.

## Start an import

Open **Recovery Center → Import pages** or **Import sites**. Upload a ZIP archive, enter a required workspace name, and optionally add a note. After the archive is read, work through the wizard in order:

1. Review the proposed page decisions.
2. Resolve imported relations when the wizard surfaces them. If no relation decisions are needed, it moves directly to validation.
3. Validate the plan and resolve any blocking errors.
4. Enter the required confirmation text when shown, then dispatch the import.

Dispatching queues the work; it does not complete the import in the browser. The wizard shows the queued/running/completed/failed status and links to the imported workspace when one is available.

## Review an import session

Open **System → Import Sessions** to filter sessions by kind, status, or initiating user. Open a session to inspect its timeline, validation report, result summary, page decisions, relation decisions, and manifest. A failed session also shows its failure reason.

If a queued or running session has become stale, the list may show **Recover stale imports**. This is a confirmed recovery action for stale sessions; it is not a rollback of completed imported content.

## Good to know

- Import pages and sites are separate entry points, but use the same review, resolution, validation, and queued-execution model.
- Imported work is staged in the selected workspace. Publishing it live is a separate editorial operation with its own permission boundary.
- Review every proposed decision and create a backup before importing. Migration Assistant does not make an archive safe to import merely because it is a ZIP file.

---

For how to use Migration Assistant, see the [admin guide](admin-guide.md).
For developers: see the [README](../README.md).

<!-- prettier-ignore-end -->
