# Phase 4 — Convert and verify all content

Every host converts with the strict mapping from phase 3; this phase proves the result independently, checks what the conversion changes outside the page body (the head), and has a person edit the converted pages. Nothing public changes yet: the legacy theme still renders the legacy tree.

## 1. Convert everything, strictly

`drush neo-migrate:content` with no `--skip-unmapped`. Every host must end `written` or `unchanged`; a second run must report every host `unchanged` (if one rewrites each time, its source fingerprint is unstable — see phase 3). Then `drush neo:alchemist:validate` for every component and `drush neo:alchemist:integrity`.

## 2. Verify

`drush neo-migrate:verify [--id=…] [--inventory=…]` is read-only and exits non-zero on any error. It is written from the mapping, not with the converter, so a converter bug surfaces here instead of repeating. Per host:

- **Against the inventory** (`migration/inventory.before.json`): the host still exists with the same label, status, URL and other field values. A host whose changed time moved since the inventory was edited by a person, and these become warnings. A field item the field itself counts as empty (metatag's `[]`) is dropped by the conversion's save; that is not a change.
- **Against its legacy tree**: one component per legacy item, in order, with the item's status; each mapped prop reads back through the component as the legacy field held it — rich text by its visible words, strings exactly, images by file and alt text (and the file on disk), links by text and resolved address, numbers after the transform's arithmetic, webforms by id (and the webform must exist). A legacy field that is empty must show nothing.
- **Against the component**: a content prop nothing fills — not the mapping, not the stored tree, not a value provider — shows the component's example text. That is an error.
- **The conversion record**: the legacy tree changed since its conversion is an error (run `neo-migrate:content` again); the tree edited since is a warning.

Before trusting a green run, break it on purpose: in a transaction, edit a converted tree (a wrong title, a removed prop, two components swapped, a hidden component, a webform that does not exist, a changed alias, a changed media alt text) and check each is reported, then roll back. On the pilot every mutation was caught.

## 3. Visible text

`parity compare` of a legacy and a neo full capture lists missing words per page. Each must be explained; on the pilot the only ones were a legacy map's own Google error text, a capture made anonymously of an unpublished page, and `/user/login` (accepted at G1). Capture unpublished pages with a logged-in target on both sides.

## 4. The head

The metatag defaults are shared by both themes, so the Neo head differs only after `neo-migrate:metatags` swaps the legacy tokens (a cutover step). Trial it:

1. Capture the legacy head **first**: `capture --target=local --label=legacy-meta --widths=1440` (one width is enough).
2. `ddev snapshot`, `drush neo-migrate:metatags`, `drush cr`, capture the neo preview at one width.
3. Restore the snapshot, then `compare legacy-meta neo-meta` and group the differences by tag.

Descriptions: neo_alchemist (with *Describe pages by their first rich text* on, the default) fills `[neo:description]` from the first stored rich-text prop of the tree, cut at 160 characters on a word — what paragraph_meta's smart description took from the first text paragraph, with spaces where legacy ran blocks together. Neo describes the **front page** by the site slogan before any module is asked; with an empty slogan the front page has no description. Record every other head difference (titles, images, theme-color) for the cutover.

## 5. The editor

Open the editor **with the preview on** (`/neo-migrate/preview/neo`). Without it the editor canvas renders the components in the legacy default theme, unstyled. The editor route is `/node/<id>/alchemist`; one component's form is `/node/<id>/alchemist/<key>/edit/<uuid>`, where `<key>` is the tree field's name without `field_`. Script a pass over every editor page and every component form for HTTP errors, PHP messages and console errors before a person starts. A one-time login link works once: log in once per browser session, not once per page. Legacy form scripts (ux_form) can still attach in the admin theme on pages that embed a webform and throw on removed jQuery `once()`; they go when the legacy modules are uninstalled.

Then a person works through the checklist in `migration/state.yml` locally, on a snapshot they restore afterwards: edit and add each component, swap an image through the media library, change an icon, pick a webform, hide a component, edit site settings and see the footer follow, and use the toolbar's create, local-task and link items.

## Gate G4

`neo-migrate:verify` reports no errors (warnings explained); every missing word in the full comparison is explained; the full-site comparison stands as signed off at G3; the head trial's differences are recorded for the cutover; a person has worked through the editor checklist.
