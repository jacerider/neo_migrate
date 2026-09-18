# Changelog

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
