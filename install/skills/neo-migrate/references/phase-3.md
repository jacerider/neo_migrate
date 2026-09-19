# Phase 3 — Front theme and components

Rebuild the legacy look as Neo SDC components in the `front` theme, one legacy item type at a time, and prove each against the baseline. The content converter grows with the components: every component that passes adds its entry to the mapping file, so by the end of the phase every page converts.

Author components with the site's **neo-component** skill. This reference covers what is specific to a migration.

## Globals first

Before any content component: the palette (`neo-migrate:pallet` from the legacy brand colour), fonts, the root font size, the container and gutters, the header and footer as Alchemist blocks, and the mobile menu. Chrome sections compare by border box and must pass before content work starts, or every content comparison is shifted.

Then the site-wide rich text and rhythm, which every text-bearing component shares:

- **Prose** (`src/css/prose.css`): the legacy body copy, headings, links and list styles, measured from `styles.json`. Legacy heading sizes are often px set per breakpoint; copy them as px.
- **Buttons**: the legacy editor's button classes become neo `btn` classes. Set the look with `--btn-*` tokens in `@theme` (`_utilities.css`); a legacy solid-button shadow goes in `@utility btn-primary`. Outside forms `btn-primary` falls back to the base colour unless `--btn-primary-bg-color` is set.
- **Rhythm**: set `component-spacing` to the legacy gap between items. Check it per breakpoint: a legacy body that is a flex column (often for reordering on phones) does not collapse margins, so its items sit twice as far apart there.
- **Text format**: the `neo` format must allow what the mapping writes (button classes on `<a>`, `target`), with the buttons offered in the editor's Style menu.

## The loop, per legacy item type (busiest first)

1. **Measure.** `cli.mjs probe` on the legacy item at 375, 768 and 1440, plus its `styles.json`. Look for rules keyed to node ids or body classes: they belong in the mapping, not the component.
2. **Write** the component yml and twig, then create its `neo_component` entity (group `general` for content, `special` + protected for chrome). `drush neo:alchemist:validate front:<name>` must pass.
3. **Map** the item type in `migration/neo_migrate.yml` (below).
4. **Convert** the pages the component appears on. While the mapping is incomplete, `--skip-unmapped` converts a page without the item types not built yet, for previewing; the full run later replaces those trees.
5. **Compare** `capture --target=local-neo --only=<those pages>` against a legacy capture of the same pages, with `compare --list`. Iterate until every section passes or its difference is explained.
6. **Log** metrics for the component; commit on the multidev branch.

Done when every content section of every converted page passes, or is a `warn` with a written reason in `migration/state.yml`.

## The mapping file and converter

`drush neo-migrate:content [--id=1,2] [--dry-run] [--overwrite]` converts each host's current revision into its tree field, following `migration/neo_migrate.yml`:

```yaml
source: paragraphs
hosts:
  - { entity_type: node, field: field_body, target: field_full }
unmapped: fail              # an unmapped type stops that page, reported
prepend:                    # outside-the-tree items some pages showed
  - { component: title_s1, ids: [7, 8] }
markup:                     # applied to every rich text value
  format: neo
  classes: { button: 'btn btn-primary', 'button outline': 'btn btn-outline-primary' }
  unwrap: [span]
  attributes: { drop: [data-list-item-id] }
paragraphs:
  text:
    component: text_s1
    props:
      content: { from: field_text, transform: markup }
```

Props take `{from: <field>, transform: …}` or a fixed `{value: …}`. Transforms: `string`; `markup` (rewritten by the `markup` rules); `flag` (TRUE when the value equals `when`, or is truthy); `link` (uri, title, options); `heading` (one field per part: `{transform: heading, supertitle: field_a, title: field_b}`); `each` (nested items as an array prop, one entry per published item, each from its own `props:`, with the same filled-field rule); `image_media` (the file as an image media entity, reusing one that holds the same file with the same alt text; the bundle and source field default to `image` / `field_media_image`, or set `media: {image: {bundle, field}}`; with `as: <key>`, every value of a multi-value field as array entries `{<key>: …}`). A fixed `{value: lg}` is wrapped as a field item.

`bundle_props:` sets fixed props on every component converted on hosts of a bundle (`service: {spacing: {value: lg}}`), for a legacy theme that styled one content type differently. Check first that the legacy rule really reached every item: on the pilot a service-page margin rule lost on specificity to a generic one for most paragraph types. A mapped prop whose legacy field is empty is written hidden (the editor's "Hide"), because an unset prop renders the component's example — a button that was never there. A filled legacy field that no prop takes stops the page, so nothing is dropped silently; list deliberate omissions under the item's `ignore:`. Instances keep the legacy item's UUID (prepended ones get a UUID derived from host and component), so a re-run writes the same tree.

`--dry-run` runs everything, including creating media, inside a transaction it rolls back.

Props are written with their "use the default" option off, as the editor stores them; inside an array that option is keyed per entry (`<array>~<prop>~<delta>`), without which nested images show their placeholder. Every written prop is read back through the component before saving, and the tree field is validated (only it: a legacy field may already hold something its settings no longer allow); a value that does not come back as written fails the page. A save is a new revision with a log message, the changed time kept and Pathauto skipped. A re-run reports `unchanged`, and a tree edited since its last conversion is a `conflict` until `--overwrite`.

## Comparing neo sections with legacy ones

Legacy items were spaced by margins; Neo sections by padding. Content sections therefore compare by **painted box** (`box: painted` in `parity.yml`): the area the section actually paints, with the change in space above it reported separately. Select Neo content sections as the top-level components in `main`:

```yaml
- { name: content, selector: 'main [data-component-id]:not([data-component-id] [data-component-id])', each: true, fallback: 'main', box: painted }
```

A legacy element outside the body that Neo moves into the tree (a page title shown on some pages) joins the legacy selector list. Compare pairs repeated sections by their text, not their position, so a section on one side only (an item type not built yet) leaves the others paired with themselves.

A section that looks identical but warns at 2–4% is usually a photo: neo_image serves AVIF or WebP, whose compression noise differs from the legacy JPEG. Check the diff image before chasing it.

## Gotchas

- **neo_alchemist makes the page-title block `sr-only`** on pages that render a tree. A visible legacy title becomes a component in the tree (`prepend`).
- **Validating a tree renders its props**, which needs a render context outside a request; the converter provides one.
- **A floating header must measure itself when it starts floating**, not at init: before the fonts load the menu can wrap, and the placeholder keeps the wrong height.
- **neo_base scrolls smoothly.** Scroll handlers with `.throttle` lose the last event of a smooth scroll; the parity tool forces instant scrolling.
- **Legacy wrappers linger in the preview.** The ux module's off-canvas canvas still wraps the Neo page until it is uninstalled; it shows as black below the footer on short pages.
