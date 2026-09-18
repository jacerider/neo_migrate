---
name: neo-migrate
description: Migrate a legacy Drupal site (paragraphs, micon, escort, real_favicon, aeon/ux themes) onto the Neo suite with the neo_migrate module, phase by phase behind review gates, proving the frontend unchanged with screenshot parity. Use when asked to migrate, convert or move a site to Neo, to resume a migration (a `migration/state.yml` exists), to audit a legacy site, or to run or read a parity comparison.
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Neo migration

A legacy site moves onto the Neo suite **in place**: same database, same node IDs, URLs, menus and metatags, with the public pages looking the same. The work runs in **phases**; each ends at a **gate**, where a person reviews the evidence before the next phase starts. Every claim of "unchanged" is backed by **parity**: screenshots of every public page, compared section by section against the production **baseline**.

Everything the migration produces lives in the site's `migration/` directory (committed); screenshots and reports live in `.neo-migrate/` (ignored).

## Start or resume

1. Read `migration/state.yml`. It names the current phase, which gates have passed, and open decisions. No file means a new migration: copy [references/state.yml](references/state.yml) to `migration/state.yml` and start at phase 0.
2. Log the session: `ddev drush neo-migrate:metric session --session=<your session id>`.
3. Open the reference for the current phase and work its steps in order.

Done when the phase's gate criteria are all met and written into `state.yml` — then stop and ask the person to review. A gate is passed only when the person says so; record who and when.

## Phases

| Phase | Goal | Reference | Gate |
| --- | --- | --- | --- |
| 0 | Measure the legacy site: audit, inventory, URL list, production baseline | [phase-0.md](references/phase-0.md) | G0 |
| 1 | Install Neo beside the legacy stack and move the admin (back theme, neo_toolbar); nothing public changes | [phase-1.md](references/phase-1.md) | G1 |
| 2 | Mechanical conversions: icons, favicon, site settings, metatags, tree field | [phase-2.md](references/phase-2.md) | G2 |
| 3 | Front theme and components, iterated against parity | not built yet | G3 |
| 4 | Convert content into component trees, verify against the inventory | not built yet | G4 |
| 5 | Cutover, teardown and release, rehearsed on a multidev | not built yet | R1–R4 |
| 6 | Repeat on the next site; fleet go/no-go | not built yet | — |

A phase marked "not built yet" has no commands in neo_migrate. Build them as part of that phase (work of kind `build`), then write its reference here.

## Rules that hold in every phase

- **Metrics on every step.** Log start and end with `--kind=build` for work on neo_migrate itself and `--kind=migrate` for work on the site; log every time a person steps in as an `intervention` with its `--type` (decision, correction, unblock, review). See [metrics.md](references/metrics.md).
- **Config commands and content commands differ.** A command that creates config runs once locally and its result is exported to git; a command that changes content runs on every environment. Each command's help says which it is.
- **Neo modules change only by decision.** Code the finished site needs at runtime goes in the site or, when it helps every Neo site, in a Neo module — proposed with its justification and effect on other sites, and written only after the person approves. Migration-only code goes in neo_migrate.
- **`neo_metatag` stays disabled.** Its install hook overwrites every metatag default on the site.
- **Parity is read per section.** The full-page percentage moves whenever anything above shifts; the section results are the verdict. See [parity.md](references/parity.md).
