# Parity

The screenshot comparison that proves the public site unchanged. Lives in `web/modules/contrib/neo_migrate/tools/parity/`; runs on the host (Node ≥ 20), from the site root.

## Commands

| Command | Does |
| --- | --- |
| `cli.mjs capture --target=<t> --label=<l> [--only=/a,/b] [--widths=375,1440]` | Screenshots every visual URL in `migration/urls.json` at every width; checks every status URL. Writes `.neo-migrate/captures/<l>/`. |
| `cli.mjs compare <a> <b>` | Compares two captures. Writes `.neo-migrate/reports/<a>__<b>/index.html` and `summary.json`. |

Before each shot the tool blocks tracking scripts, disables animation, scrolls through the page so lazy content loads, waits for fonts and images, hides `hide:` selectors and paints `mask:` selectors magenta. Each capture also records per section: position, visible text, and computed styles of the section and its headings, paragraphs, links, buttons and images (`styles.json`) — the measured values to build the new components from, instead of reading the old SCSS.

## Reading a report

- **Sections are the verdict.** Sections are matched by key (`header`, `content-03`, `footer`), each cropped from its own page, so one that moved still compares with itself. The "Full" column is the whole page and moves whenever anything shifts; use it only to notice that something shifted.
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

## Flaky sections

A section that fails in both directions across pages (114px on one, 228px on the next) is an unstable capture, not a difference. Find what varies with a one-off Playwright probe of that element before and after scrolling, then point the section at a stable element. Example from the pilot: `ux_header` fixes `.ux-header-wrapper`'s height once on load, at a value that depends on timing; comparing the `header` inside it is stable.

## Gotchas

- Judge rendering in Chromium, the browser the tool uses. Firefox showed empty boxes for legacy icon-font glyphs that Chromium and production render correctly.
- Local DDEV prints PHP deprecations into the message area; `hide: ['[data-drupal-messages]']` keeps them out of the comparison.
- With DDEV's Mutagen sync, files drush writes inside the container reach the host a moment later.
