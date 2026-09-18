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

6. **Move the admin.** neo_icon requires neo_modal, and neo_modal replaces core's dialog library in every theme: in a legacy admin theme (seven, claro) dialogs open unstyled. escort and the back theme also misrender together. So the admin moves in this phase, while the public site stays legacy until the front cutover:
   ```
   ddev drush pmu escort micon_local_task -y
   ddev drush en neo_toolbar neo_icon_local_task neo_icon_admin -y
   ddev drush config:set system.theme admin back -y
   ddev drush neo-migrate:toolbar --theme=back
   ```
   - `neo-migrate:toolbar` rebuilds escort's items as `escort_<id>` toolbar items (links, "manage" links to the filtered content list, "add" as a create item), maps their Font Awesome 4 icons, and grants `access neo_toolbar` to every role that had `access escort`. Once escort is uninstalled it reads both from the sync directory — so run it **before** the next `cex`, which drops escort's config and permission from the sync directory. If an export already happened, `git checkout` the role files and run it again.
   - `--theme=back` shows the toolbar only in the back theme. neo_toolbar's assets are built for Neo themes only, and over the legacy front theme it renders unstyled; editors on public pages get the legacy theme's own local-task tabs instead. Clear it with `--theme=any` at the front cutover.
   - A "same label" note means an escort link and a stock neo_toolbar item share a name. Decide which one editors need now. Example: escort's "Settings" opened the legacy site-settings form that still feeds the public footer, while neo's opens neo_site_settings, which stays empty until phase 2. Disable neo's item until then.
   - A legacy favicon module that maps favicons per theme (real_favicon) needs the back theme added, or admin pages lose the favicon.

7. **Rebuild.** Modules enabled after the first build (neo_toolbar here) have no compiled assets yet: `ddev drush cr`, then `ddev exec npm run deploy`, then confirm each new module's entrypoints are in both `web/themes/front/dist/manifest.json` and `web/themes/back/dist/manifest.json` (a deploy right after enabling once missed the back scope; `npm run build:back` added it). A library missing from the manifest is served as its raw `src/*.ts`. Locally, unaggregated, only that file fails and nothing looks wrong; with JS aggregation, as on Pantheon, the TypeScript syntax error takes the whole aggregate with it: `Drupal` is undefined, dropbuttons stay raw links, dialogs never open.

8. **Export.** `ddev drush cex -y`, then read the diff. Expected besides the new Neo config: entity_clone registers the new entity types as cloneable, neo_alchemist adds `field_media_file` to existing document and video media types, escort's config disappears, and the roles swap `access escort` for `access neo_toolbar`. Done when `config:status` reports no differences.

## Gate G1

- Parity: a fresh local capture against `prod-baseline` still passes every section, with no missing words and no head or status differences. The legacy theme's `detect` selector in `parity.yml` must match only the legacy theme — the Neo front theme reuses classes such as `.section.page`. Expected difference: `/user/login` now shows neo_back's login screen.
- Admin smoke test in the back theme: the toolbar items, node edit forms with the legacy body field, and the webform UI's "Add element" dialog.
- Public pages as a logged-in editor: no toolbar, the legacy local-task tabs, nothing else changed.
- The preview works both ways, and the banner shows.
- `config:status` reports no differences.
- Deployed to the multidev (push the branch, then `terminus drush <site>.<env> -- updb -y`, `cim -y`, `cr`), with parity against `prod-baseline` repeated on the multidev, and an admin page checked there with aggregation on: `Drupal` is defined, dropbuttons are initialised, a dialog opens.
- On Pantheon, neo_config_file must be 1.0.31 or later: earlier versions cannot install neo_icon through config import on a read-only codebase, and cannot extract icon or favicon packages where directories cannot be renamed.
- Remote drush through `terminus drush` drops `ev` code containing spaces or double quotes; build strings with `chr()` when a remote probe is needed.
