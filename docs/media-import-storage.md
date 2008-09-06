# Imported media storage

Archive and WordPress imports use `StoreImportedMediaAction`. The action sniffs
the bytes, applies the existing media-container validator, chooses the canonical
extension and sanitises the basename before storing or reusing a media row.
WordPress retains its image-only policy and bounded, host-pinned download.

WordPress Importer already requires Migration Assistant in both `composer.json`
and `capell.json`. The shared action belongs to that existing dependency; neither
Core nor unrelated optional packages import it.

Storage validation follows read-through primaries and scoped or custom decorator
backing disks, including inline configurations. Local roots and destinations are
checked after resolving existing ancestors and symlinks. An outer decorator's
root cannot conceal its primary's actual root. Non-local adapters use object keys
and are not subjected to an absolute local-path check.

## Storage caller sweep

Search the whole `packages/*/src` tree with:

```sh
rg -n 'addMedia|addMediaFromUrl|usingFileName|putFileAs|storeAs' packages/*/src
rg -n 'Storage::|->put\(|file_put_contents|File::put|->store\(' packages/*/src
```

`StoreImportedMediaAction` is the boundary for **imported** bytes (archive and
WordPress). Interactive, generated and fixture writers below keep their own
sniffing, allow-list and naming because they belong to packages that must not
depend on Migration Assistant; a single shared media-storage boundary belongs in
Core and is tracked separately.

The table names each media-writing source path. All paths are relative to
`packages/`; multiple calls in a file are listed separately within its row.
Collection registrations are declarations, not media writes. There are no
`addMediaFromUrl`, `putFileAs` or `storeAs` calls in package source.

| Source path                                                             | Status and input boundary                                                                                                                                                                                                                                                                          |
| ----------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `wordpress-importer/src/Actions/ImportWordPressMediaForPagesAction.php` | Fixed: host-pinned remote images go through the shared validator, canonical filename and destination checks.                                                                                                                                                                                       |
| `migration-assistant/src/Services/Import/MediaIngestService.php`        | Retained and shared: size/checksum-verified archive streams delegate to `StoreImportedMediaAction`; owner-scoped reuse is checked through the same boundary.                                                                                                                                       |
| `migration-assistant/src/Actions/StoreImportedMediaAction.php`          | Shared write boundary for the two import callers, including real backing-storage validation.                                                                                                                                                                                                       |
| `agent-bridge/src/Actions/Media/UploadMediaCapabilityAction.php`        | Authenticated base64 upload: content sniffing and MIME/extension allow-list precede attachment; stored and previewed names use a sanitised stem and the canonical extension.                                                                                                                       |
| `media-ai/src/Actions/ApplyImageDoctorMetadataAction.php`               | Confirmed: provider raster output is sniffed, allow-listed and matched to the reported MIME; `imageFileName()` strips path and dotted stem components and enforces a corresponding raster extension. Stores on the existing source media's disk. This is generated output, not archive/URL import. |
| `demo-kit/src/Actions/CreateKitchenSinkSourceWidgetsAction.php`         | Confirmed local fixtures: slugged names select bundled `.jpg` assets only.                                                                                                                                                                                                                         |
| `demo-kit/src/Actions/InstallKitchenSinkDemoPageAction.php`             | Confirmed local fixtures: slugged names select bundled `.jpg` assets only.                                                                                                                                                                                                                         |
| `demo-kit/src/Actions/CreateKitchenSinkContextPagesAction.php`          | Confirmed local fixtures: slugged names select bundled `.jpg` assets only.                                                                                                                                                                                                                         |
| `demo-kit/src/Actions/CreateKitchenSinkVariantWidgetsAction.php`        | Confirmed local fixtures: slugged names select bundled `.jpg` assets only.                                                                                                                                                                                                                         |
| `demo-kit/src/Support/Creator/BaseDemoCreator.php`                      | Confirmed local fixtures: `createMedia()` stores bundled images/video; `createWidgetMedia()` stores the bundled main asset and a bundled JPEG poster. No downloaded bytes enter these calls.                                                                                                       |
| `layout-builder/src/Support/Creator/BaseDemoCreator.php`                | Confirmed local fixtures: `createWidgetMedia()` stores the bundled main asset and JPEG poster. No downloaded bytes enter these calls.                                                                                                                                                              |
| `media-library/src/Concerns/InteractsWithCuratorMedia.php`              | Interactive upload backend: independently sniffs and allow-lists MIME/extension, sanitises SVG and uses generated storage names. Imported WordPress bytes no longer enter this upload route.                                                                                                       |
| `media-library/src/Models/CuratorMedia.php`                             | Existing SVG sanitisation write, not a new import or remote download.                                                                                                                                                                                                                              |
| `live-chat/src/Actions/StoreLiveChatAttachmentsAction.php`              | Interactive attachments: validates MIME and size, uses a generated UUID and sniffed extension. No archive or remote fetch.                                                                                                                                                                         |
| `form-builder/src/Actions/BuildSubmissionPayloadDataAction.php`         | Interactive form uploads: generated storage names on the configured upload disk. No archive or remote fetch.                                                                                                                                                                                       |
| `layout-builder/src/Actions/GenerateLayoutPreviewImageAction.php`       | Locally rendered PNG with a fixed `.png` destination. No imported or remote media.                                                                                                                                                                                                                 |

Other write matches store archives, WXR/JSON/CSV data, generated source/assets,
reports, caches or sitemaps. They do not attach imported media. In particular,
`ImportMigrationAssistantPackageCommand` stores the incoming archive on its
archive disk; media extraction still passes through the shared action.

## Regression coverage

`ImportWordPressMediaForPagesActionTest` checks image bytes fetched from `.html`
and `.php` URLs, standalone SVG/HTML, appended HTML and executable destinations.
`MediaIngestServiceTest` checks read-through S3/custom remote primaries, safe local
primaries and named/inline local primaries concealed by an outer root override,
alongside the existing traversal, symlink, canonical naming and reuse checks.
The remote test exercises Laravel's read-through writer with an injected remote
adapter; it does not contact a live S3 service.
