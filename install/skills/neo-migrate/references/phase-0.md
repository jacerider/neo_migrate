# Phase 0 — Measure the legacy site

Read-only on the site. Produces the evidence every later phase is judged against.

## Steps

1. **Enable neo_migrate locally**: `ddev drush en neo_migrate -y`. It has no dependencies, so it installs before any Neo module. `config:status` now shows `core.extension` different; that is expected until phase 1 exports config.

2. **Audit**: `ddev drush neo-migrate:audit` writes `migration/audit.json` and `migration/audit.md`. Read `audit.md` top to bottom. Done when every finding marked `judgment` or `unclassified` is either decided or listed as an open decision in `state.yml`.
   - Warnings about displays or components being "disabled" come from core's dependency dry run on copies; nothing is saved.
   - An icon "in no icon package" matters only when live content uses it; the finding says which.
   - Unknown modules: add ones that carry over to `keep:` in `neo_migrate.legacy.yml`, and legacy ones to `modules:` with their handling, so the next site starts smarter.

3. **Inventory**: `ddev drush neo-migrate:inventory` writes `migration/inventory.before.json`: every entity holding a tree, its URL and field values, and the ordered tree. This is the record phase 4 verifies against; take it from the database you will migrate.

4. **URL list**: `ddev drush neo-migrate:urls` writes `migration/urls.json`. Visual URLs get screenshotted; redirects and term pages are checked by status code. Read the list: a published page nobody links to (test pages, "not working" pages) is a question for the person, not something to rebuild silently.

5. **Parity config**: write `migration/parity.yml` from the template in [parity.md](parity.md). Find the legacy theme's section selectors by inspecting a node page: the chrome regions (top bar, header, footer) and the selector matching each top-level paragraph (usually `.node.full > .field.body > *`).

6. **Baseline**: install the tool once (`npm install` in `web/modules/contrib/neo_migrate/tools/parity`, then `npx playwright install chromium`), then from the site root:
   ```
   node web/modules/contrib/neo_migrate/tools/parity/cli.mjs capture --target=prod --label=prod-baseline
   node web/modules/contrib/neo_migrate/tools/parity/cli.mjs capture --target=local --label=local-legacy
   node web/modules/contrib/neo_migrate/tools/parity/cli.mjs compare prod-baseline local-legacy
   ```
   Open the report. Done when every section passes, there are no missing words and no head or status differences. Any failing section is either a real difference between the databases (pull fresh content, recapture) or an unstable capture (see "Flaky sections" in [parity.md](parity.md)); fix the cause and recapture both sides.

7. **Metrics**: log phase 0's work, and ask the person for their estimate of the whole migration done by hand, logged as `estimate` with `--actor=human`. It is the yardstick for the pilot.

## Gate G0

Record in `state.yml`, then ask the person to review:

- `audit.md` reviewed; open decisions listed.
- `inventory.before.json` and `urls.json` written from the database being migrated.
- `prod-baseline` captured; `prod-baseline` vs `local-legacy` at 100% sections passing, 0 missing words, 0 head and status differences — so local legacy can stand in for production in later phases.
- The human estimate logged.
