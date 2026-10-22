# Phase 5 — Releases

The migration reaches live in four releases, each written as a runbook in `migration/releases.md` and rehearsed on a multidev before a person deploys it. Claude never pushes to live, test or master, and asks the person for every new multidev.

| Release | What changes on live | Public site |
| --- | --- | --- |
| R1 | Neo installed beside the legacy stack, the admin on `back` with neo_toolbar, every page converted to its tree | unchanged |
| R2 | The cutover: `front` is the default theme; favicon, metatags and menu links move to Neo | the signed-off Neo design |
| R3 | Teardown, 1–2 weeks after R2 (or folded into R2, as the person decides): legacy content structures deleted, legacy modules and themes uninstalled | unchanged |
| R4 | `composer remove` of the legacy packages; neo_migrate uninstalled | unchanged |

Record the plan in `migration/state.yml` under `phase5.releases` once the person agrees to it, and each rehearsal's evidence beside it.

## Rehearsing

- **R1** is the site branch as it stands after G4. Rehearse it on a multidev holding live's code and database: the person creates one from live (the pilot's `phase1`, this site's `cutover`), you push the branch there and run the runbook exactly as written, timing each step. Capture the public site before and after; expect every section to pass.
- **R2** is built on its own branch (`cutover`) from the R1 branch, locally first, on a snapshot. Rehearse it on the same multidev after R1, so both run on live's content. Push R1's code there first if the multidev started from live's.
- Run module changes before `cim`, a few per `drush` process: a single import that installs and uninstalls many modules runs out of Pantheon's 256MB PHP limit part way and leaves the site half-imported (both pilots).
- A multidev from live starts with none of Neo's image derivatives. Warm it (`cli.mjs warm`) before capturing; the warm ends by printing a `drush neo-migrate:derivatives …` command for any derivative a web request could not convert.
- **Share images at the cutover.** Once the metatags use `[neo:image]`, neo builds each page's share image (the `neo_social` derivative) while rendering the page's head. On Pantheon a photo too large for the request (24.8MB here) takes the whole page down with a 502, and warming cannot help because the page never renders. R2 runs `drush neo-migrate:derivatives --share-images` right after the config import and content steps, before the edge cache is cleared.
- Commit a lock that `ddev composer update` wrote only after `ddev mutagen sync`: the host copy lags, and a push can carry the old lock.

## The runbook

Each release in `migration/releases.md` has: what changes on live (for the person, in visitors' and editors' terms), the branch, the rehearsal's result and timings, numbered steps (back up live; merge to dev; test from live's content; live), the checks with the exact capture and compare commands, and the rollback. The pilot's and this site's runbooks are the models.

## R2 on an exo_alchemist site

The person decides whether exo leaves at the cutover or in a later R3 (the first exo site took it out at the cutover; the pilot kept its legacy stack for a week of rollback). Either way:

- **Place the tree field in the default display** (`content.field_full`, formatter `neo_component_tree`) and re-save the display through the entity API so its dependencies are recorded. neo_alchemist's `[neo:description]` and `[neo:image]` find the tree only through it.
- **Metatags**: `neo-migrate:metatags` swaps exo's smart tokens. The phase 4 head trial says what else the person decided; `[current-page:metatag:title]` and `[current-page:metatag:description]` restore exo's og:title and og:description. A fixed share image for the front page belongs in the `front` metatag defaults (config), not in a node's own metatags (content).

**Removing exo** (in R2 or R3). After `neo-migrate:content` and `neo-migrate:verify` (verify reads the legacy trees), and before any legacy module is uninstalled, run `drush neo-migrate:teardown`. It turns Layout Builder off on the tree hosts' displays (placing the tree field in the default display if the cutover's config is not imported yet) and strips exo_alchemist's and Layout Builder's settings from them, deletes the exo blocks (`block_content` of `exo_*` types), their types and exo's site settings, and purges the deleted fields. Without it:

- uninstalling exo_alchemist **deletes the host displays** (they carry its third-party settings), and pages render only their title until the config import recreates them;
- exo_alchemist refuses to uninstall while its field types are in use on the blocks;
- exo_alchemist's delete hooks delete the media and files it created for a component's default content, which the converted trees still use (the command deletes the blocks with exo's generator switched off; check media and file counts before and after);
- the Layout Builder overrides field cannot be purged: core's `LayoutSectionItemList` asks the deleted field for its entity and throws "Field layout_builder__layout is unknown". The command empties that field's deleted-data tables first.

Then uninstall a few modules per `drush pmu`, leaves first: exo_alchemist_clone and the exo_list_builder submodules; exo_list_builder, exo_media, exo_linkit (exo_alchemist goes with them); exo_layout_builder; layout_builder; layout_discovery, block_content, entity_reference_revisions; exo_site_settings, exo_link, exo_menu, exo_fixed; exo_form, exo_modal, exo_imagine (exo_icon with them); exo_config_file; mna and exo. Enable neo_config_flow (`composer require jacerider/neo_config_flow`) before uninstalling exo_config: both only lock config when settings.php sets `$settings['config_readonly']`, and neo_config_flow does not unlock Pantheon's dev environment as exo_config did. Then `drush theme:uninstall` the legacy themes (ash, mna_theme, aeon, claro); their blocks go with them. Uninstall `exo` before checking the site: while it is installed without its theme plugin's provider (mna), every page answers 500.

**If exo stays until R3**, keep Layout Builder on at the cutover (turning it off deletes the overrides, the rollback copy): neo_migrate's coexistence renders the tree in the layout's place in the Neo front theme. Editors lose Layout Builder's tab by taking "configure any layout" from their roles. exo pads its `#exo-body` canvas by neo_toolbar's displacement, which the back theme already applies; neo_migrate zeroes that padding while exo is installed.

## R4

Not built yet. `composer remove` the legacy packages (the code had to stay for the uninstall) and their patches, then uninstall neo_migrate; its skill stays.
