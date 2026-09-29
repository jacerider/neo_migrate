# Neo Migrate

Moves a legacy Drupal site onto the Neo suite, in place, with the public site
looking the same. Two legacy stacks are supported:

- **paragraphs** sites: paragraphs, micon, escort, real_favicon, the aeon and
  ux themes;
- **exo** sites: exo_alchemist (components placed through Layout Builder),
  exo_icon, exo_toolbar, exo_site_settings, exo_modal and the rest of the exo
  suite.

The legacy stack is read through a source adapter (`ParagraphsSource`,
`ExoAlchemistSource`, chained by `neo_migrate.source`), so the audit, the
inventory and the content converter work the same way on both.

The module is a toolkit for one job, used by an AI agent (through the
`neo-migrate` skill) and reviewed by a person at each gate. It is installed for
the length of a migration and uninstalled afterwards; nothing the finished site
needs at runtime lives here.

## Status

Phases 0 (measuring the legacy site), 1 (installing Neo beside it), 2
(mechanical conversions) and 3 (components and the content converter) are built
for both stacks; phase 4 (converting and verifying all content) for paragraphs
sites, with `neo-migrate:verify` still to learn the exo transforms. Later phases
are built as the migrations reach them; the skill lists which exist.

## Coexistence

Until the cutover both stacks are installed, and each theme keeps to its own:
the legacy themes render the legacy body (paragraphs, or the Layout Builder
layout) and the Neo front theme renders the component tree in its place. Where
the two stacks overlap — both icon systems' Twig `icon()` and fonts,
neo_tooltip on legacy forms, exo_modal and neo_modal both taking over core's
dialogs, exo's page canvas — `CoexistenceHooks` and `IconCoexistence` give each
theme its own.

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
| `drush neo-migrate:audit` | `audit.json`, `audit.md`: every legacy module, theme, paragraph or exo_alchemist component type, icon, toolbar item, site setting, favicon, metatag token and site-code reference, each marked mechanical, judgment, remove, skip or unclassified, plus a dry run of what uninstalling the legacy stack would delete. |
| `drush neo-migrate:inventory` | `inventory.before.json`: every entity holding a legacy tree (a paragraphs field, or a Layout Builder layout of exo_alchemist components), with its URL, field values and ordered tree. |
| `drush neo-migrate:urls` | `urls.json`: the public URLs to compare before and after. |
| `drush neo-migrate:metric` | Appends to `metrics.jsonl`; `--summary` prints totals. |

These commands create config (run them locally, then export):

| Command | Does |
| --- | --- |
| `drush neo-migrate:toolbar [--theme=back] [--dry-run]` | Rebuilds escort's or exo_toolbar's items as neo_toolbar items, maps their icons, grants `access neo_toolbar` to roles that had the legacy toolbar's permission, and optionally limits the toolbar to one theme. |
| `drush neo-migrate:tree-field [--field=field_full] [--dry-run]` | Adds a `neo_component_tree` field beside every legacy host field, hidden on the edit form and rendered wherever the legacy body is. Layout Builder displays are left alone; the Neo front theme renders the tree in the layout's place. While both exist, the legacy theme renders only the old body and the Neo front theme only the tree. |
| `drush neo-migrate:site-settings-types [--dry-run]` | Creates the neo_site_settings bundles and fields the legacy values need, from the `site_settings` map in `neo_migrate.legacy.yml` (or exo_site_settings' own fields). |
| `drush neo-migrate:icon-field <type>.<field> --to=<name> [--dry-run]` | Adds a `neo_icon` twin beside a micon icon field, hidden on the form until the cutover. |
| `drush neo-migrate:favicon [--dry-run]` | Moves the real_favicon package used on the default theme into neo_favicon. For the cutover only. |
| `drush neo-migrate:metatags [--dry-run]` | Swaps legacy tokens for Neo tokens in the metatag defaults, from `token_map` in `neo_migrate.legacy.yml`. For the cutover only. |
| `drush neo-migrate:pallet <id> <hex> [--dry-run]` | Sets a neo_color pallet from one legacy brand colour, generating the ramp exactly as the pallet form does. Rebuild assets afterwards. |
| `drush neo-migrate:icons [--global] [--packages=a,b] [--dry-run]` | Imports each micon or exo_icon package as a unique neo_icon library of the same name, so stored names such as `fa-wrench` or `mna-heating` keep resolving. exo's Font Awesome packages are left to neo_icon's stock libraries unless named. Not global by default, so the legacy theme is untouched. |
| `drush neo-migrate:entity-icons [--dry-run]` | Moves the icons set on content types, media types and vocabularies (micon or exo_icon) into neo_icon. |

These commands change content (run them on every environment, after its config is deployed; they are safe to repeat):

| Command | Does |
| --- | --- |
| `drush neo-migrate:site-settings [--dry-run]` | Copies this environment's legacy site settings (site_settings or exo_site_settings) into neo_site_settings. Run again at the cutover to pick up edits made in between. |
| `drush neo-migrate:content [--id=1,2] [--skip-unmapped] [--overwrite] [--dry-run]` | Converts each host's legacy tree into its component tree field, following `migration/neo_migrate.yml` (paragraph types under `paragraphs:`, exo_alchemist components under `components:`). Keeps changed times and aliases; refuses a tree edited since its last conversion unless `--overwrite`. |
| `drush neo-migrate:verify` | Checks every converted host against its legacy tree and the inventory (paragraphs sites; exo transforms to come). |
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

It reads `migration/parity.yml` and `migration/urls.json` (`--urls=<file>` for
another list, such as unpublished pages captured on a target that logs in),
and writes to `.neo-migrate/` (keep that out of git). The config format and how to read a
report are in `install/skills/neo-migrate/references/parity.md`.

## The skill

`install/skills/neo-migrate/` is copied into a site's `.claude/skills/` by
`drush neo-install`. It carries the phases, gates, and the reference for each
phase.
