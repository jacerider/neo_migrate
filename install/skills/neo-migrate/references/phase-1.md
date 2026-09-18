# Phase 1 — Install Neo beside the legacy stack

Neo's modules and themes go in; the public site and the legacy admin keep working as before. Take a database snapshot first (`ddev snapshot --name pre-phase1`) so the whole phase can be rolled back.

## Steps

1. **Composer.** Check `pkg status` shows nothing dirty, then require the Neo packages at the constraints the reference site uses, plus `drupal/linkit` and `jacerider/neo_migrate:dev-develop`:
   ```
   ddev composer require jacerider/neo:^1.0 jacerider/neo_theme:^1.0 jacerider/neo_alchemist:^1.0 \
     jacerider/neo_site_settings:^1.0 jacerider/neo_toolbar:^1.0 jacerider/neo_favicon:^1.0 \
     jacerider/neo_font:^1.0 jacerider/neo_image:^1.0 jacerider/neo_form:^1.0 drupal/linkit:^7.0 \
     jacerider/neo_migrate:dev-develop
   ```
   A loose neo_migrate checkout already at `web/modules/contrib/neo_migrate` blocks the source install: move it to `~/Projects/neo_migrate` (it is the peer clone) once it is clean and pushed. Then `pkg setup --apply` puts every Neo package on `develop`. A legacy package it flags as "needs a look" (dist install with local changes) is left alone; it is removed at teardown anyway.

2. **Themes.** Copy `web/themes/contrib/neo_theme/neo_base/install/neo/{front,back}` to `web/themes/`, renaming `<theme>.info.neo.yml` to `<theme>.info.yml` — the part of `drush neo:create` that applies to an existing site. Do not run `neo:create` itself.

3. **Modules, then themes.** Enable `neo neo_icon neo_modal neo_image neo_font neo_twig neo_favicon neo_site_settings neo_alchemist neo_alchemist_block telephone linkit`, then `drush theme:enable neo_base neo_front neo_back front back`. The defaults stay legacy.
   - Hold back `neo_toolbar`, `neo_menu_link`, `neo_icon_admin` and `neo_icon_local_task` until cutover: they clash with escort and micon.
   - Never enable `neo_metatag` (see SKILL.md), and hold back `neo_config_flow`, `neo_loader` and `neo_animate`.
   - Core copies the default theme's blocks into every newly installed theme that has none: neo_base, neo_front and neo_back each get a copy of the legacy theme's blocks. Delete the copies that depend on a legacy module (`ux_menu` asides, field blocks of legacy fields); the rest are harmless and match the reference site.

4. **Build.** `ddev drush neo-install` writes `package.json`, `tsconfig.json`, `vite.config.ts`, a Vite port in `.ddev/config.yaml`, and copies every module's skills into `.claude/skills`. Add the Neo block to `.gitignore` (`/neo.json`, `/tsconfig.neo.json`, `/.stylelintcache`, `!/config/files/*`). Then `ddev exec npm install` and `ddev exec npm run deploy`. npm's "allow-scripts" warning about esbuild's postinstall is harmless: the build works without it.

5. **Preview.** An admin with `preview neo migration` visits `/neo-migrate/preview/neo?destination=/some/page` to see the Neo themes (front on site pages, back on admin pages) while everyone else sees the legacy ones; `/neo-migrate/preview/off` stops it. A banner in the corner says a preview is on. The pair of legacy themes is recorded in `neo_migrate.settings` when neo_migrate is installed.

6. **Export.** `ddev drush cex -y`, then read the diff. Expected besides the new Neo config: entity_clone registers the new entity types as cloneable, and neo_alchemist adds `field_media_file` to existing document and video media types. Done when `config:status` reports no differences.

## Gate G1

- Parity: a fresh local capture against `prod-baseline` still passes every section, with no missing words and no head or status differences. The legacy theme's `detect` selector in `parity.yml` must match only the legacy theme — the Neo front theme reuses classes such as `.section.page`.
- Admin smoke test in the legacy admin theme: node edit forms, the webform UI's "Add element" dialog, and any other dialog editors use.
  - neo_modal replaces core's dialog library for every theme. In a legacy admin theme the dialogs open but render without neo's styles. Record how the site will handle this as a decision before G1 passes.
- The preview works both ways, and the banner shows.
- `config:status` reports no differences.
