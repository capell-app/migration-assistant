# Using Migration Assistant

This guide is for editors running a content import and owners planning a migration. Every step uses the labels you see on screen.

## Using Migration Assistant (editor how-to)

### How to start an import

1. Go to **Migration Assistant**.
2. Start a new import session and choose the source you are importing from.
3. The import opens as a session you can return to.

### How to map fields

1. In the import session, open **field mapping**.
2. Match each piece of old content (for example its title and body) to the right Capell field.
3. Save the mapping.

### How to preview and run

1. Use **Preview** to see what will be imported.
2. Check it carefully before running.
3. When it looks right, **run** the import.

### How to review the results

1. Open the finished import session.
2. Read the status and the list of anything that was skipped.
3. Fix and re-import any items that didn't come across.

## Rolling out Migration Assistant (for owners)

### Turn on first

- **A backup and a small test import.** Always back up first, then import a small batch to confirm the mapping before importing everything.

### Add when needed

| Need | Enable |
| --- | --- |
| Import from a specific system | The matching source (for example the WordPress Importer) |
| Repeat or resume a large migration | Multiple import sessions |

### Don't enable yet

- Don't run a full import before previewing a sample. Map and preview first, then run.

### Who does what

| Role | First useful screen |
| --- | --- |
| Editor | The import session: map, preview, and run |
| Site owner | **Import sessions**: track progress and results |

## Troubleshooting for editors

| What you see | What it means | What to do |
| --- | --- | --- |
| Imported content is in the wrong field | The field mapping was off | Fix the mapping and re-run the import |
| Some items were skipped | They didn't match the mapping or had errors | Review the results list, fix the source or mapping, and re-import those items |
| The import session won't start | The source or connection wasn't set up | Re-check the source settings and try again |
| Images didn't come across | Media wasn't included or mapped | Confirm media is part of the import and re-run |
