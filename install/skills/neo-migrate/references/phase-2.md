# Phase 2 — Mechanical conversions

Each legacy piece that needs no design decision moves to its Neo equivalent. Some of it is safe now, because nothing public reads it yet; the rest changes what visitors see, so it is built and checked now but applied at the cutover (phase 5).

Snapshot first: `ddev snapshot --name pre-phase2`.

## Now

| Step | Command | Kind | Check |
| --- | --- | --- | --- |
| Icon packages | `drush neo-migrate:icons` | config | Every icon name the audit lists resolves: `\Drupal::service('neo_icon.repository')->getIconFromLibrary($name)` returns an icon from the library of the same prefix. Use `getIconFromLibrary`, not `getIconBySelector` (that one matches CSS prefixes). |
| Component tree field | `drush neo-migrate:tree-field` | config | `field_full` on every paragraphs host bundle, hidden on the form, in each display that shows the legacy body; `neo_migrate.settings:coexistence` names both fields. |
| Site settings bundles | `drush neo-migrate:site-settings-types` | config | The bundles in the `site_settings` map exist with their fields. |
| Site settings values | `drush neo-migrate:site-settings` | content | Every mapped value set; `[site:address:city]` and friends parse the address. |
| Icon field twins | `drush neo-migrate:icon-field <type>.<field> --to=<name>` | config | One per micon field on a host entity (the audit's icon fields not on paragraphs). |
| Icon field values | `drush neo-migrate:icon-field-values <type>.<field> --to=<name>` | content | All values copied; a second run updates 0; changed times and aliases unchanged. |

Then `cex`, parity against `prod-baseline` (nothing public may move), commit, push, and on the multidev `cim` followed by every **content** command.

- Icon libraries are imported non-global, so the legacy theme, still drawing micon's own classes, is untouched. neo_icon keys a glyph with several names ("close, remove, times") by the joined name: micon's alias selectors for those glyphs (`fa-close`, `fa-bars`, `fa-gear`…) do not resolve. List any the audit shows in live content as a decision.
- The icon field twin stays hidden on the form until the cutover; editors keep using the micon field, and the values are copied again then.
- Site settings are copied again at the cutover, for the same reason.

## Built now, applied at the cutover

| Step | Command | Why it waits |
| --- | --- | --- |
| Favicon | `drush neo-migrate:favicon` | real_favicon and neo_favicon write the same head tags; only one may be configured. Test it on a snapshot now (it should unpack as many files as real_favicon's directory holds, and every tag should point at an existing file), then restore the snapshot. |
| Metatag tokens | `drush neo-migrate:metatags` | The Neo tokens read the component tree, which renders only on the Neo front theme. `--dry-run` now: every legacy token maps, none is left. The per-URL head comparison happens on the cutover rehearsal. |

`[neo:description]` falls back to the site slogan unless something alters it; if the legacy descriptions came from page content (paragraph_meta), the site needs a description alter reading the component tree, a Neo module decision.

## Gate G2

- Every stored icon name used by live content resolves; exceptions listed.
- `config:status` clean locally and on the multidev after `cim` and the content commands.
- Parity against `prod-baseline` unchanged.
- Favicon and metatag commands proven (snapshot test and dry run), waiting for the cutover.
