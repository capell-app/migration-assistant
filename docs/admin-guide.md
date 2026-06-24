# Using Migration Assistant

This guide is for editors running a content import and owners planning a migration. Every step uses the labels you see on screen.

## Using Migration Assistant (editor how-to)

### How to start an import

1. Go to **Migration Assistant**.
2. Start a new import session and choose the source you are importing from.
3. The import opens as a session you can return to.

![An administrator reviews import sessions and their current migration state.](screenshots/import-session-index-or-host-admin-surface.png)

### How to map fields

1. In the import session, open **field mapping**.
2. Match each piece of old content (for example its title and body) to the right Capell field.
3. Save the mapping.

### How to check validation before importing

1. In the import session, open the validation summary.
2. Read the list of errors and warnings it found in your source.
3. Fix the source or the mapping for anything marked as an error, then check again.
4. Warnings are safe to proceed with, but worth reading first.

![An operator reviews validation errors and warnings before executing an import.](screenshots/import-validation-summary.png)

### How to match references to existing records

1. When your import links to other content (for example an author or a category), open the relation review step.
2. For each imported reference, choose the matching record that already exists in Capell.
3. Save your choices before you run the import, so links point to the right place.

![An operator maps imported references to existing records before execution.](screenshots/relation-resolution-review.png)

### How to preview and run

1. Use **Preview** to see what will be imported.
2. Check it carefully before running.
3. When it looks right, **run** the import.

### How to import pages through Recovery Center

1. Open **Recovery Center** and choose to import pages.
2. Work through validation, then match any references to existing records.
3. Run the import and watch its progress.
4. If something goes wrong, you can roll the import back from here.

![An operator imports pages through Recovery Center and reviews validation, relation resolution, execution, and rollback state.](screenshots/recovery-page-imports.png)

### How to review the results

1. Open the finished import session.
2. Read the status and the list of anything that was skipped.
3. Fix and re-import any items that didn't come across.

### How to undo an import

1. Open the import you want to undo.
2. Roll it back to remove what it added.
3. Read the rollback report to see exactly what was changed and what manual cleanup, if any, is still needed.

![An operator reviews what rollback changed and what manual cleanup remains.](screenshots/import-rollback-report-view.png)

### How to export content as a package

1. Choose to create an export package.
2. Confirm which resources are included before you download.
3. Download the package so you can move that content to another site.

![An operator prepares an export package and confirms included resources before download.](screenshots/package-export-intent-screen.png)

## Rolling out Migration Assistant (for owners)

### Turn on first

- **A backup and a small test import.** Always back up first, then import a small batch to confirm the mapping before importing everything.

### Add when needed

| Need                               | Enable                                                   |
| ---------------------------------- | -------------------------------------------------------- |
| Import from a specific system      | The matching source (for example the WordPress Importer) |
| Repeat or resume a large migration | Multiple import sessions                                 |

### Don't enable yet

- Don't run a full import before previewing a sample. Map and preview first, then run.

### Who does what

| Role       | First useful screen                             |
| ---------- | ----------------------------------------------- |
| Editor     | The import session: map, preview, and run       |
| Site owner | **Import sessions**: track progress and results |

## Troubleshooting for editors

| What you see                           | What it means                               | What to do                                                                    |
| -------------------------------------- | ------------------------------------------- | ----------------------------------------------------------------------------- |
| Imported content is in the wrong field | The field mapping was off                   | Fix the mapping and re-run the import                                         |
| Some items were skipped                | They didn't match the mapping or had errors | Review the results list, fix the source or mapping, and re-import those items |
| The import session won't start         | The source or connection wasn't set up      | Re-check the source settings and try again                                    |
| Images didn't come across              | Media wasn't included or mapped             | Confirm media is part of the import and re-run                                |
