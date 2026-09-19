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
4. **Convert** the pages that now convert completely: `drush neo-migrate:content --dry-run` lists them; then run it with `--id=`.
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

Props take `{from: <field>, transform: markup|string}` or a fixed `{value: …}`. Instances keep the legacy item's UUID (prepended ones get a UUID derived from host and component), so a re-run writes the same tree.

Every written prop is read back through the component before saving, and the host is validated; a value that does not come back as written fails the page. A save is a new revision with a log message, the changed time kept and Pathauto skipped. A re-run reports `unchanged`, and a tree edited since its last conversion is a `conflict` until `--overwrite`.

## Comparing neo sections with legacy ones

Legacy items were spaced by margins; Neo sections by padding. Content sections therefore compare by **painted box** (`box: painted` in `parity.yml`): the area the section actually paints, with the change in space above it reported separately. Select Neo content sections as the top-level components in `main`:

```yaml
- { name: content, selector: 'main [data-component-id]:not([data-component-id] [data-component-id])', each: true, fallback: 'main', box: painted }
```

A legacy element outside the body that Neo moves into the tree (a page title shown on some pages) joins the legacy selector list, so keys line up.

## Gotchas

- **neo_alchemist makes the page-title block `sr-only`** on pages that render a tree. A visible legacy title becomes a component in the tree (`prepend`).
- **Validating a tree renders its props**, which needs a render context outside a request; the converter provides one.
- **A floating header must measure itself when it starts floating**, not at init: before the fonts load the menu can wrap, and the placeholder keeps the wrong height.
- **neo_base scrolls smoothly.** Scroll handlers with `.throttle` lose the last event of a smooth scroll; the parity tool forces instant scrolling.
- **Legacy wrappers linger in the preview.** The ux module's off-canvas canvas still wraps the Neo page until it is uninstalled; it shows as black below the footer on short pages.
