# Migration Assistant — Improvement & Growth Plan
> Package: capell-app/migration-assistant · Kind: package · Tier: premium · Product group: Capell Operations · Bundle: operations · Status: Draft

## 1. Snapshot

Migration Assistant is the generic Capell content-migration framework: it owns Recovery Center page import (upload → review → relation resolution → dry-run validation → queued execution → status polling) plus deterministic ZIP package export/import, CSV/XML source readers, a relation-resolver chain, media ingest, and rollback reports. Surfaces are `admin` + `console`; the working admin entry points are `ImportPagesPage` (`recovery-center/import-pages`) and `ImportSessionResource`, driven by Actions under `src/Actions/Imports` (`StartPageImportAction`, `AdvancePageImportToValidationAction`, `DispatchPageImportAction`, `RefreshPageImportStatusAction`) and `ExecuteImportPlanJob`. Owned tables are `import_sessions` and the rollback-report table; deps are `capell-app/admin`, `capell-app/core`, `lorisleiva/laravel-actions`, `spatie/laravel-package-tools`. It is the **framework**; `capell-app/wordpress-importer` is a **consumer** — wordpress-importer `require`s migration-assistant and registers a `WxrReader` into `ImportSourceRegistry` (`wordpress-importer/src/Providers/WordPressImporterServiceProvider.php:26-28`), so WordPress parsing stays out of this package.

Current marketplace summary (verbatim, `capell.json` → `marketplace.summary`):
> "MigrationAssistant provides package import workflows, source reads, mapping, preview, validation, execution state, and rollback reports."

Screenshot count mismatch: `capell.json` → `marketplace.screenshots` lists **1** image (`docs/assets/marketplace/extension-card.jpg`), while `docs/screenshots.json` declares **6** surfaces and `docs/screenshots/` contains **10** PNGs (light+dark pairs). The marketplace block is under-populated relative to the assets that already exist on disk.

## 2. Improvements (existing functionality)

Prioritized.

1. **Wire site import into the UI (or descope it from the manifest).** — The backend is complete and reachable from the job: `ExecuteImportPlanJob::importerFor()` returns `SiteImportService` when `kind === ImportSessionKind::SiteImport` (`src/Jobs/ExecuteImportPlanJob.php:160-166`) and `SiteImportService::import()` materialises Site/SiteDomain then delegates pages (`src/Services/Import/SiteImportService.php:23-46`). But nothing ever creates a `SiteImport` session: `StartPageImportAction` hardcodes `'kind' => ImportSessionKind::PageImport` (`src/Actions/Imports/StartPageImportAction.php:65`) and `ImportSitesPage` is an explicit placeholder whose `runImport()` throws `RuntimeException('Site imports are provided by the migration-assistant package.')` and whose `shouldRegisterNavigation()` returns `false` (`src/Filament/Pages/ImportSitesPage.php:36,53-56`). Build the site-import wizard (reuse `ImportPagesPage` mechanics) or, short-term, remove the dead `site.*` permissions / health check from the manifest until shipped. — `src/Filament/Pages/ImportSitesPage.php`, `src/Actions/Imports/StartPageImportAction.php` — **L**

2. **Fix the corrupted rollback-report table name across the codebase.** — A bad find/replace turned `reports` into `dashboard-dashboard_reports`. The physical table, model `$table`, provider migration registration, and all docs now read `import_rollback_dashboard-dashboard_reports`: `src/Models/ImportRollbackReport.php:37`, `database/migrations/2026_05_10_190859_02_create_import_rollback_dashboard-dashboard_reports_table.php:13,37`, `src/Providers/MigrationAssistantServiceProvider.php:62`, plus `README.md` and `docs/overview.md`. A hyphen in a SQL identifier is fragile (forces backtick-quoting everywhere) and clearly unintended. Rename to `import_rollback_reports` via a corrective migration + filename + model + manifest + docs sweep. — `database/migrations/`, `src/Models/ImportRollbackReport.php`, `src/Providers/MigrationAssistantServiceProvider.php` — **M**

3. **Repair garbled prose left by the same find/replace.** — `src/Contracts/PageCollisionDetector.php:10` reads "a null implementation that dashboard-dashboard_reports no collisions" (should be "reports"); identical breakage at `src/Actions/BuildPageReviewRows.php:20`. `docs/overview.md:9` and `docs/import-export-workflow.md:29` say "Capell Migration AIOrchestrator" / "Migration AIOrchestrator" (should be "Migration Assistant"). These ship in published private docs and the boost guidelines. — `src/Contracts/PageCollisionDetector.php`, `src/Actions/BuildPageReviewRows.php`, `docs/overview.md`, `docs/import-export-workflow.md` — **S**

4. **Implement the health-check methods the manifest promises.** — `MigrationAssistantHealthCheck` declares only `compatibleCapellApiVersion()` (`src/Health/MigrationAssistantHealthCheck.php`), yet `capell.json` → `healthChecks` registers four checks (`package-reader`, `site-import`, `rollback-report`, `media-ingest`, three `critical`) all pointing at this class. Follow the working pattern in `packages/password-policy/src/Health/PasswordPolicyHealthCheck.php`, which exposes a real method delegating to an Action. Add executable probes (e.g. manifest/integrity validation reachable, rollback report contains created records, media checksum/limit enforcement live). — `src/Health/MigrationAssistantHealthCheck.php` — **M**

5. **Reconcile `adminQueryBudget: 40` with the wizard's real query count.** — `capell.json` → `performance.adminQueryBudget` is 40, but `ImportPagesPage` is a multi-step Livewire wizard that hydrates review rows, resolve rows and validation summaries each poll. Either add a query-count assertion test to enforce the budget or correct the number; right now it is an unverified claim. — `capell.json`, `src/Filament/Pages/ImportPagesPage.php` — **S**

6. **Resolve the dead-vs-live `ImportSessionKind` cases.** — `WordPressImport` and `SpreadsheetImport` exist (`src/Enums/ImportSessionKind.php:11-12`) but are never assigned to a session anywhere in `src/`. wordpress-importer registers a reader, not a kind, so the WXR path likely runs as `PageImport`. Either delete the orphan cases or document that downstream importers set them — leaving them implies routing that does not exist. — `src/Enums/ImportSessionKind.php` — **S**

7. **Make `ExecuteImportPlanJob` retry-aware (`tries = 1`).** — The job sets `public int $tries = 1` (`src/Jobs/ExecuteImportPlanJob.php`), so any transient failure (lock contention, disk hiccup) is terminal. Media ingest is explicitly engineered to be idempotent and runs outside the import transaction, and `WithoutOverlapping(...)->dontRelease()` already guards concurrency — the design anticipates retries the config forbids. Raise `tries` (with backoff) now that the work is idempotent, or document why single-shot is deliberate. — `src/Jobs/ExecuteImportPlanJob.php` — **S**

## 3. Missing Features (gaps)

Tied to `capell.json` → `capabilities` (`migration-assistant`, `migration-assistant-admin`, `migration-assistant-console`) and migration-tool norms.

- **Console surface is empty (table stakes for `migration-assistant-console`).** No `Command`/`AsCommand`/`hasCommand` exists anywhere in `src/` despite the `console` surface and the `migration-assistant-console` capability. A migration tool needs `migrate:export`, `migrate:import`, `migrate:status`, `migrate:rollback-report` for CI, scripted server-to-server moves, and headless runs. This is the single largest manifest-vs-reality gap.
- **Automated rollback execution (differentiator).** Today rollback is *advisory only* — `CreateImportRollbackReportAction::instructionsFor()` emits a manual prose paragraph ("review each created model... remove any records..."). The report already stores `created_models` (`src/Models/ImportRollbackReport.php`), so a one-click "Undo this import" that deletes the recorded rows is achievable and would be a headline feature versus competitors that only log.
- **Field-mapping UI (table stakes).** `FieldMapper` (56 lines) and `ImportTargetRegistry` exist, but mapping is code-level; there is no column→field mapping screen in the wizard. Flat-file (CSV/XML) importers normally let an operator map source columns to target fields visually.
- **Resumable / chunked import for large datasets (table stakes at scale).** `PageImportService::import()` wraps the entire payload in a single `DB::transaction` (`src/Services/Import/PageImportService.php`) and the job has a 900s timeout. There is no batching, checkpointing, or progress-by-row — a large site will hit memory/timeout limits with no way to resume.
- **Redirect / URL preservation reporting (differentiator for SEO).** PageUrl rows are restored (`restorePageUrls`), but there is no surfaced report of preserved vs dropped URLs/redirects — a natural cross-sell hook into `url-manager` / `seo-suite`.
- **Validation report export.** `BuildImportValidationSummaryAction` produces an in-wizard summary but there is no downloadable/persisted validation artifact for sign-off before execution.
- **Source connectors beyond flat files (differentiator).** Only CSV + XML readers ship (`ImportSourceRegistry`). Direct DB/JSON/sitemap connectors would broaden the funnel; the registry is built for exactly this extension.

## 4. Issues / Risks

- **Manifest advertises capabilities that are unreachable in production.** `console` surface + `migration-assistant-console` capability with zero commands (§3); `site.import`/`site.export` permissions + a **critical** `migration-assistant.site-import` health check while the only site-import UI throws (`src/Filament/Pages/ImportSitesPage.php:53-55`). A marketplace reviewer exercising these will find them missing.
- **Stub health checks give false green.** `src/Health/MigrationAssistantHealthCheck.php` has no probe logic; the lone test asserts only the version string (`tests/Unit/MigrationAssistantCoverageTest.php:114`). Operators reading "Site imports materialise sites, domains, and pages" as healthy are being misled.
- **Single-transaction import = large-dataset memory/time risk.** Entire `payload` decoded and written inside one transaction (`src/Services/Import/PageImportService.php`); combined with `tries = 1` and a 900s timeout, a big import that fails mid-way rolls back wholesale with no resume (§2.7, §3).
- **Partial-failure handling is coarse.** `ExecuteImportPlanJob` truncates errors to the first 5 (`implode(' / ', array_slice($report->errors, 0, 5))`) and marks the whole session `Failed`; per-row failures inside `PageImportService` are collected but the user sees a truncated string, not a structured per-entry error report.
- **Test gaps on the highest-risk paths.** Strong coverage exists for readers, manifest validation, size-limit enforcement, resolvers, page import, and DTO contracts (`tests/` ~25 files). But: no test creates or executes a **SiteImport** session end-to-end through the job; no test exercises real **health-check** logic (because there is none); no **rollback execution** test (only report creation); the `ImportSitesPage` test asserts that it *throws* (`tests/Feature/Admin/ImportSitesPageTest.php:8`), codifying the stub.
- **Cache/public-output safety: low risk, confirmed.** `capell.json` → `performance.cacheSafety.cacheable=false`, `sensitiveOutput=false`, and the package emits no anonymous frontend output (`frontendRenderBudgetMs: 0`). Import-session detail is admin-gated via `ImportSessionPolicy` + `HasPageShield`. No public-leak surface found.
- **Performance budgets unverified.** `adminQueryBudget: 40` has no enforcing test (§2.5); `cacheTags: []` is consistent with a non-cacheable admin tool.
- **i18n is solid.** 73 `__()` calls, all namespaced under `capell-admin::exchanger.*` (e.g. `import_pages`, `cancel_session_confirm_body`); no hardcoded user-facing strings observed in the wizard. The only English-literal strings are the rollback **manual instructions** in `CreateImportRollbackReportAction::instructionsFor()` — these are persisted operator guidance and should be translatable.
- **Documentation ships corruption.** The `dashboard-dashboard_reports` and `AIOrchestrator` artefacts (§2.2, §2.3) are in published README/docs and the boost skill, undermining a "first-party / priority-support" premium positioning.

## 5. Marketplace & Selling

**Critique.** The current `capell.json` summary ("MigrationAssistant provides package import workflows, source reads, mapping, preview, validation, execution state, and rollback reports") is a comma-separated feature dump — internal-engineer voice, leads with the package name, no buyer benefit, never says *migrate from where to where*. The composer `description` ("MigrationAssistant export, import, and rollback report workflows for Capell.") is similarly inward-facing. Neither mentions the actual acquisition hook: **getting an existing site (incl. WordPress, via the importer) into Capell**.

**Improved 1-sentence summary:**
> Safely move pages, sites, and media into Capell — preview every change, validate before you write, and keep a rollback report for every import.

**Improved 3–4 sentence description:**
> Migration Assistant is Capell's content-migration engine: upload a content package or flat file (CSV/XML), review and resolve incoming relations, run a dry-run validation, then execute on a queue with live progress. Every run produces a rollback report capturing exactly which records were created, so nothing is a one-way door. It is also the foundation other importers build on — the WordPress Importer plugs straight in — making it the first thing you install when bringing an existing site onto Capell. Media is deduplicated by checksum and large payloads are size-guarded, so imports stay safe and idempotent.

**Screenshot/media gaps.** The manifest exposes only `extension-card.jpg` to the marketplace, but 10 polished PNGs (light/dark) already exist for the six key surfaces (session index, validation summary, relation resolution, rollback report, export intent). Promote these into `capell.json` → `marketplace.screenshots` with captions; the work is done, it just is not wired up. Add a short hero/loop of the upload→preview→execute flow (the `hero-desktop.jpg`/`hero-mobile.jpg` assets already exist on disk).

**Pricing / tier / bundle positioning.** Tier `premium`, bundle `operations`, license `paid`. Migration tools are **acquisition drivers, not revenue centers** — they are how a prospect's existing content lands inside the platform, after which every other package monetizes. Consider whether the *page-import* core belongs in a lower/free tier (or a time-limited "import window") to maximize funnel, keeping site-import, automated rollback, and console/CI commands as the premium upsell. Bundling it into `operations` is defensible, but a "Migration & SEO" cross-sell bundle (below) may convert better.

**Cross-sell via deps + Extension Suites.** Hard dependency edge already exists: `wordpress-importer` → `migration-assistant`, so a "coming from WordPress?" funnel should surface both. Natural Extension-Suite pairings: **wordpress-importer** (source connector), **url-manager** (preserve/redirect imported URLs — see §3 redirect reporting), **seo-suite** (post-import metadata/redirect hygiene), and **media-library** + **diagnostics** (already listed in "Best Used With"). Lead the listing with these.

**Differentiators / value props / target buyer.** Differentiators: dry-run validation *before* any write, deterministic checksum-verified packages, idempotent checksum-keyed media reuse, a persisted rollback report per import, and an open `ImportSourceReader` registry (any vendor can add a source). Target buyer: agencies/teams migrating an existing site (especially WordPress) onto Capell, and ops teams moving content between Capell environments.

**Keywords/tags (8–12):** `migration`, `import`, `export`, `wordpress-migration`, `content-migration`, `csv-import`, `xml-import`, `site-import`, `rollback`, `dry-run-validation`, `media-migration`, `cms-migration`.

## 6. Prioritized Roadmap

| Item | Bucket | Effort | Impact | Section ref |
| --- | --- | --- | --- | --- |
| Fix corrupted `import_rollback_*` table name (migration + model + provider + docs) | Now | M | High | §2.2 |
| Repair garbled prose ("dashboard-dashboard_reports", "AIOrchestrator") in src + docs | Now | S | Med | §2.3 |
| Promote existing 10 screenshots + hero into `capell.json` marketplace block | Now | S | High | §5 |
| Rewrite marketplace summary + composer description (buyer-facing) | Now | S | High | §5 |
| Implement real health-check probe methods (mirror password-policy) | Now | M | High | §2.4, §4 |
| Resolve manifest-vs-reality: descope `site.*`/site health check OR ship UI | Now | M | High | §2.1, §4 |
| Delete or document orphan `WordPressImport`/`SpreadsheetImport` kinds | Now | S | Med | §2.6 |
| Add console commands (export/import/status/rollback) for the `console` capability | Next | L | High | §3 |
| Build site-import wizard end-to-end (UI → SiteImport kind → job) | Next | L | High | §2.1, §3 |
| Ship automated one-click rollback execution from `created_models` | Next | M | High | §3 |
| Raise `ExecuteImportPlanJob` `tries`/backoff now that ingest is idempotent | Next | S | Med | §2.7 |
| Add query-budget + structured partial-failure tests; enforce `adminQueryBudget` | Next | M | Med | §2.5, §4 |
| Add visual field-mapping UI (CSV/XML column → target field) | Later | L | Med | §3 |
| Chunked/resumable large-dataset import with checkpointing | Later | L | High | §3, §4 |
| Redirect/URL-preservation report + url-manager/seo-suite cross-sell hooks | Later | M | Med | §3, §5 |
