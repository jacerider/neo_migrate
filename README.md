# Neo Migrate

Moves a legacy Drupal site — paragraphs, micon, escort, real_favicon, the aeon
and ux themes — onto the Neo suite, in place, with the public site looking the
same.

The module is a toolkit for one job, used by an AI agent (through the
`neo-migrate` skill) and reviewed by a person at each gate. It is installed for
the length of a migration and uninstalled afterwards; nothing the finished site
needs at runtime lives here.

## Status

Phases 0 (measuring the legacy site) and 1 (installing Neo beside it) are
built. Later phases are built as the pilot reaches them; the skill lists which
exist.

## Theme preview

While both stacks are installed, an admin with the `preview neo migration`
permission can see the other stack's themes in their own browser:
`/neo-migrate/preview/neo`, `/neo-migrate/preview/legacy`, and
`/neo-migrate/preview/off` (each accepts `?destination=/path`).

## Commands

All read-only. Output goes to `<project root>/migration/` unless `--dir` says
otherwise.

| Command | Writes |
| --- | --- |
| `drush neo-migrate:audit` | `audit.json`, `audit.md`: every legacy module, theme, paragraph type, icon, toolbar item, favicon, metatag token and site-code reference, each marked mechanical, judgment, remove, skip or unclassified, plus a dry run of what uninstalling the legacy stack would delete. |
| `drush neo-migrate:inventory` | `inventory.before.json`: every entity holding a paragraph tree, with its URL, field values and ordered tree. |
| `drush neo-migrate:urls` | `urls.json`: the public URLs to compare before and after. |
| `drush neo-migrate:metric` | Appends to `metrics.jsonl`; `--summary` prints totals. |

What counts as legacy, and what each piece becomes, is data:
`neo_migrate.legacy.yml`. Extend it as new sites turn up new modules.

## Parity tool

`tools/parity/` is a Node tool (Playwright + pixelmatch) that screenshots every
public page at several widths and compares two captures section by section.

```
cd web/modules/contrib/neo_migrate/tools/parity && npm install && npx playwright install chromium
cd -   # back to the site root
node web/modules/contrib/neo_migrate/tools/parity/cli.mjs capture --target=prod --label=prod-baseline
node web/modules/contrib/neo_migrate/tools/parity/cli.mjs compare prod-baseline local-legacy
```

It reads `migration/parity.yml` and `migration/urls.json`, and writes to
`.neo-migrate/` (keep that out of git). The config format and how to read a
report are in `install/skills/neo-migrate/references/parity.md`.

## The skill

`install/skills/neo-migrate/` is copied into a site's `.claude/skills/` by
`drush neo-install`. It carries the phases, gates, and the reference for each
phase.
