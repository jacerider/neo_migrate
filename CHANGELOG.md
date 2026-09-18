# Changelog

## The theme preview works on Pantheon

**The preview cookie is now `STYXKEY_neo_migrate_preview`.** Pantheon's CDN
strips every request cookie except a few prefixes, so `neo_migrate_preview`
never reached Drupal on a multidev: the preview route set it, the browser kept
it, and every page still rendered the legacy theme. `STYXKEY` cookies pass
through and vary the edge cache. **Parity now proves the preview took** by
finding the preview banner on a page, which Drupal prints only when it sees
the cookie, instead of checking the browser's cookie jar.

## Social links keep their legacy icons

**`neo-migrate:site-settings-types` sets each social link's icon** on its
neo_link formatter from the new `link_icons` map: the legacy module drew every
social link as micon's `fa-<network>`, so the footer keeps the same glyph.
**`cli.mjs probe` also prints colours, borders, opacity and `::before` /
`::after` content**, which legacy themes use for decoration.

## Parity captures the Neo theme behind its preview, and probes offsets

**A parity target can log in and preview the Neo themes.** A target may be an
object with a `login` command (anything that prints a one-time login link, such
as `ddev drush uli --no-browser`), a `preview` mode and extra `hide` selectors.
The tool logs in once, switches the preview on, checks the cookie took, and
reuses the session for every page, so the Neo front theme can be compared
against the production baseline before the cutover.

**`cli.mjs probe` prints unrounded boxes and styles** for a selector and its
descendants on any target, for chasing sub-pixel offsets a section diff shows.

## Multi-name icon glyphs resolve by their first name

**The icon importer renames each IcoMoon glyph that has several names**
("bars, navicon, reorder") to its first name before import. neo_icon keyed
such glyphs by the joined string, so `fa-bars`, `fa-close` and 90 others found
nothing; the stylesheet already had a class per name, so only the lookup
changes.

## A legacy brand colour becomes a neo pallet in one command

**`drush neo-migrate:pallet <id> <hex>` sets a neo_color pallet from a single
colour.** `PalletGenerator` ports neo_color's own generator: each shade mixes
the colour toward white or black on `chroma.scale(['#fff', colour, '#000'])`
at the pallet form's fixed points, and a shade is marked for dark content when
its CIEDE2000 distance from white is 35 or less. Checked against a pallet the
form generated: all 11 shades and flags match.

## The favicon and metatag conversions are ready for the cutover

**`drush neo-migrate:favicon` moves the real_favicon package into
neo_favicon**: the package serving the default theme is decoded from its
config, registered as neo_favicon's config file (which unpacks it into
`public://neo-favicon`), and its tags copied into `neo_favicon.settings`.
**`drush neo-migrate:metatags` swaps legacy tokens for Neo tokens** in every
metatag default, from the new `token_map` in `neo_migrate.legacy.yml`, and
reports any legacy token left. Both wait for the cutover: the two favicon
modules write the same head tags, and the Neo tokens read the component tree.

**The skill has a phase 2 reference**: what converts now, what waits, and the
checks for gate G2.

## Site settings and icon fields move to neo, in two halves

**Structure is config, values are content.** `neo-migrate:site-settings-types`
creates the neo_site_settings bundles the legacy values need (an `hours` bundle
with a field per day) and `neo-migrate:site-settings` copies the values: the
address lines joined so neo's address tokens parse them, the phone, the social
links as links, the hours, and the site name. The legacy values sit in config
excluded from sync, so the copy runs on each environment against its own.
What maps where is the `site_settings` section of `neo_migrate.legacy.yml`.

**`neo-migrate:icon-field` gives a micon icon field a `neo_icon` twin**, hidden
on the edit form until the cutover, and `neo-migrate:icon-field-values` copies
the stored names across. The copy is repeatable, keeps each entity's changed
time, writes no revision, and stops Pathauto regenerating the alias.

**Content commands have their own class**, `NeoMigrateContentCommands`, apart
from the commands that create config.

## The component tree field is added beside the legacy body

**`drush neo-migrate:tree-field` adds the field converted content goes
into**: one `neo_component_tree` field (`field_full` by default) on every
bundle hosting paragraphs, custom trees allowed, hidden on the edit form and
rendered wherever the legacy body field is. **While both exist, each stack
renders only its own**: a view hook hides the tree in the legacy front theme
and the old body in the Neo front theme, using the field names recorded in
`neo_migrate.settings`.

## micon's icon packages can be carried into neo_icon unchanged

**`drush neo-migrate:icons` imports each micon package as a neo_icon
library.** The library takes the package's machine name and is marked
unique, so its icons are named `<library>-<name>` — the same strings micon
stored in fields, menus and templates. The package's own IcoMoon zip is
reused: written to `public://neo-file/<id>.zip`, registered as a config file
whose parent is the library, and saved, which unpacks it. The libraries are
not global unless `--global` is given, so they load only where a neo icon
renders and the legacy theme keeps drawing micon's icons until the cutover.
A glyph with several names ("close, remove, times") is known to neo_icon only
by the joined name, so micon's alias selectors for it do not resolve.

## Escort's toolbar can be rebuilt in neo_toolbar

**`drush neo-migrate:toolbar` turns escort's items into neo_toolbar items.**
Links stay links; "manage" items become links to the content list filtered by
their bundle; "add" becomes neo_toolbar's create item for the node types that
still exist. Items neo_toolbar already ships — the user menu, local tasks and
actions, the home link — are reported as covered instead of duplicated.
Created items are named `escort_<id>`, so running it again updates them.
Escort's right-hand regions land at the end of the rail. Every role that had
`access escort` gets `access neo_toolbar`. Escort's items and roles are read
from active config while escort is installed and from the sync directory once
it is not. `--theme=<theme>` shows the toolbar only on one theme, for the time
the admin has moved and the public site has not.

**Font Awesome 4 icon names resolve to neo_icon names**: tried as they are,
through the `icon_aliases` in `neo_migrate.legacy.yml`, then without an "-o"
suffix.

**The phase 1 reference covers moving the admin first**, because neo_modal
takes over core's dialogs in every theme.

## An admin can preview the Neo themes while visitors still see the legacy site

**`/neo-migrate/preview/neo` sets a cookie that serves the Neo themes to one
browser**: `front` on site pages and `back` on admin pages, through a theme
negotiator that also checks the `preview neo migration` permission on every
request. `/neo-migrate/preview/legacy` does the reverse once the Neo themes
are the defaults, and `/neo-migrate/preview/off` stops it. A banner shows
while a preview is on. The negotiator runs below core's AJAX base-page
negotiator, so an AJAX request keeps the theme of the page that made it. The
theme pairs live in `neo_migrate.settings`; the legacy pair is recorded from
`system.theme` when the module is installed.

**The skill has a phase 1 reference**: installing Neo beside the legacy stack,
with what core and the Neo modules do on the way in (block copies into new
themes, extra config in the export, neo_modal taking over dialogs in the
legacy admin theme).

## A legacy site can be measured before anything changes

**`drush neo-migrate:audit`** finds every legacy module, theme, paragraph
type, icon, toolbar item, favicon, metatag token and site-code reference, and
marks each mechanical, judgment, remove, skip or unclassified. It also runs
core's own dependency calculation as a dry run, so the config that
uninstalling the legacy stack would delete is known up front. Icons that no
package defines are reported with whether live content still uses them.

**`drush neo-migrate:inventory`** records every entity holding a paragraph
tree — URL, field values and the ordered tree, read from the revision each
reference points at — as the record later verification compares against.

**`drush neo-migrate:urls`** lists the public URLs to compare: nodes, open
webform pages, views pages, the login and 404 pages for screenshots, and
redirects and term pages for status checks.

**`drush neo-migrate:metric`** logs effort to `metrics.jsonl`, keeping work on
this module (`build`) apart from work on the site (`migrate`).

**The parity tool** (`tools/parity`) screenshots every public page at several
widths and compares two captures section by section, with missing-word, head
and status checks and an HTML report. Sections are cropped from one full-page
shot, so a fixed header cannot overlay them.

**The `neo-migrate` skill** carries the phases, gates and phase 0 reference.
