# Neo Migrate

Moves a legacy Drupal site — paragraphs, micon, escort, real_favicon, the aeon
and ux themes — onto the Neo suite, in place, with the public site looking the
same.

The module is a toolkit for one job, used by an AI agent (through the
`neo-migrate` skill) and reviewed by a person at each gate. It is installed for
the length of a migration and uninstalled afterwards; nothing the finished site
needs at runtime lives here.

## Status

Phases 0 (measuring the legacy site), 1 (installing Neo beside it) and 2
(mechanical conversions) are built. Later phases are built as the pilot reaches them; the skill lists which
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

These commands create config (run them locally, then export):

| Command | Does |
| --- | --- |
| `drush neo-migrate:toolbar [--theme=back] [--dry-run]` | Rebuilds escort's items as neo_toolbar items, maps their icons, grants `access neo_toolbar` to roles that had `access escort`, and optionally limits the toolbar to one theme. |
| `drush neo-migrate:tree-field [--field=field_full] [--dry-run]` | Adds a `neo_component_tree` field beside every paragraphs host field, hidden on the edit form and rendered wherever the legacy body is. While both exist, the legacy theme renders only the old body and the Neo front theme only the tree. |
| `drush neo-migrate:site-settings-types [--dry-run]` | Creates the neo_site_settings bundles the legacy values need (`hours`), from the `site_settings` map in `neo_migrate.legacy.yml`. |
| `drush neo-migrate:icon-field <type>.<field> --to=<name> [--dry-run]` | Adds a `neo_icon` twin beside a micon icon field, hidden on the form until the cutover. |
| `drush neo-migrate:favicon [--dry-run]` | Moves the real_favicon package used on the default theme into neo_favicon. For the cutover only. |
| `drush neo-migrate:metatags [--dry-run]` | Swaps legacy tokens for Neo tokens in the metatag defaults, from `token_map` in `neo_migrate.legacy.yml`. For the cutover only. |
| `drush neo-migrate:pallet <id> <hex> [--dry-run]` | Sets a neo_color pallet from one legacy brand colour, generating the ramp exactly as the pallet form does. Rebuild assets afterwards. |
| `drush neo-migrate:icons [--global] [--dry-run]` | Imports each micon package as a unique neo_icon library of the same name, so stored names such as `fa-wrench` keep resolving. Not global by default, so the legacy theme is untouched. |

These commands change content (run them on every environment, after its config is deployed; they are safe to repeat):

| Command | Does |
| --- | --- |
| `drush neo-migrate:site-settings [--dry-run]` | Copies this environment's legacy site settings into neo_site_settings. Run again at the cutover to pick up edits made in between. |
| `drush neo-migrate:icon-field-values <type>.<field> --to=<name> [--dry-run]` | Copies a micon field's values into its twin, keeping each entity's changed time and alias. |

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
