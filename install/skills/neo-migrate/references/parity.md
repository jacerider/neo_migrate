# Parity

The screenshot comparison that proves the public site unchanged. Lives in `web/modules/contrib/neo_migrate/tools/parity/`; runs on the host (Node ≥ 20), from the site root.

## Commands

| Command | Does |
| --- | --- |
| `cli.mjs capture --target=<t> --label=<l> [--only=/a,/b] [--widths=375,1440]` | Screenshots every visual URL in `migration/urls.json` at every width; checks every status URL. Writes `.neo-migrate/captures/<l>/`. |
| `cli.mjs compare <a> <b> [--list]` | Compares two captures. Writes `.neo-migrate/reports/<a>__<b>/index.html` and `summary.json`; `--list` also prints every section's result, height change and change in space above. |
| `cli.mjs probe --target=<t> [--path=/] [--width=1440] [--depth=3] '<selector>'` | Prints unrounded boxes and key computed styles for the matching elements and their descendants. For the sub-pixel offsets a section diff points at, where the rounded boxes in `styles.json` look identical. |

Before each shot the tool blocks tracking scripts, disables animation and smooth scrolling, scrolls through the page so lazy content loads, waits for fonts and images, hides `hide:` selectors and paints `mask:` selectors magenta. Each capture also records per section: position, visible text, and computed styles of the section and its headings, paragraphs, links, buttons and images (`styles.json`) — the measured values to build the new components from, instead of reading the old SCSS.

## Reading a report

- **Sections are the verdict.** Single sections are matched by name (`header`, `footer`) and repeated ones (`content-03`) by what they say, each cropped from its own page, so one that moved still compares with itself and one present on one side only is reported as missing without shifting the rest. The "Full" column is the whole page and moves whenever anything shifts; use it only to notice that something shifted.
- **Results**: `pass` at or under `thresholds.pass` percent of differing pixels, `fail` over `thresholds.fail`, `warn` between (a person judges), `missing` when a section exists on one side only.
- **Missing words** lists words on the first capture absent from the second: lost content. It must be zero.
- **Head differences** compares title, canonical and every meta tag, with each site's host removed.

## Config: `migration/parity.yml`

```yaml
targets:
  prod: https://www.example.com
  local: https://example.ddev.site
widths: [375, 768, 1440]
height: 900
concurrency: 3
thresholds: { pass: 2, fail: 10 }
mask: ['iframe']                 # differs on every load
hide: ['[data-drupal-messages]'] # never visible to visitors on load
themes:
  client:                        # the legacy theme
    detect: '.dialog-off-canvas-main-canvas > .section.page'
    text: 'main'
    sections:
      - { name: header, selector: 'header.region' }
      - { name: content, selector: '.node.full > .field.body > *', each: true, fallback: 'main' }
      - { name: footer, selector: 'footer' }
```

The theme is the first whose `detect` selector is on the page. The Neo front theme gets its own entry in phase 3, using the same section names so keys line up.

Sections are numbered by what is shown: a match that paints nothing gets no key. A section is measured by its border box, or with `box: painted` by what it paints — its own background or border if it has one, otherwise the text, images and painted boxes inside it, clipped to the page and to any clipping ancestor, ignoring visually hidden content and empty clearfix pseudo-elements. Use `painted` for content sections when one side spaces its items with margins and the other with padding; the report then shows the change in space above each section separately (`space above +6px`).

### Capturing the Neo theme before the cutover

Until the cutover only a logged-in user with "preview neo migration" sees the Neo themes, so the target logs in and turns the preview on:

```yaml
targets:
  local-neo:
    url: https://example.ddev.site
    login: ddev drush uli --no-browser   # any command printing a one-time login link
    preview: neo                         # neo_migrate's theme preview mode
    hide: ['#block-local-tasks']         # admin-only chrome on this target
```

The tool logs in once, visits `/neo-migrate/preview/<mode>`, checks the preview cookie took, and reuses that session for every page; the preview banner is hidden. Status codes are still checked anonymously. On a multidev the login is `terminus drush <site>.<env> -- uli --no-browser`.

## Flaky sections

A section that fails in both directions across pages (114px on one, 228px on the next) is an unstable capture, not a difference. Find what varies with a one-off Playwright probe of that element before and after scrolling, then point the section at a stable element. Example from the pilot: `ux_header` fixes `.ux-header-wrapper`'s height once on load, at a value that depends on timing; comparing the `header` inside it is stable.

## Gotchas

- Judge rendering in Chromium, the browser the tool uses. Firefox showed empty boxes for legacy icon-font glyphs that Chromium and production render correctly.
- Local DDEV prints PHP deprecations into the message area; `hide: ['[data-drupal-messages]']` keeps them out of the comparison.
- With DDEV's Mutagen sync, files drush writes inside the container reach the host a moment later.
