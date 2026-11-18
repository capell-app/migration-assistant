If your team runs more than one Capell site, you move pages or a whole site between them in the admin. An import there goes through a review before anything is written.

You build a package file from a page or a whole site with a command, copy it across, and upload it in the admin under Import Pages or Import Sites. The review lists every page in the file. Where an address already exists on the target site, you choose what happens to that page. Layouts and images are matched to those already on the target, and you choose what happens to the rest. After a validation step you type a confirmation phrase and the import is queued.

The writing happens in background jobs, which your host or developer must have running. Before it writes, it checks again that the person who started the import is still allowed to. With Publishing Studio installed, imports land in a draft workspace you name.

Two commands undo an import. One lists the records it created, the other removes those nobody has edited since, and a preview run shows the result first. Each import then appears in the Import Sessions list in the admin with its status.
