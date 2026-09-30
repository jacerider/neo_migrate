# Phase 5 — Releases

The migration reaches live in four releases, each written as a runbook in `migration/releases.md` and rehearsed on a multidev before a person deploys it. Claude never pushes to live, test or master, and asks the person for every new multidev.

| Release | What changes on live | Public site |
| --- | --- | --- |
| R1 | Neo installed beside the legacy stack, the admin on `back` with neo_toolbar, every page converted to its tree | unchanged |
| R2 | The cutover: `front` is the default theme; favicon, metatags and menu links move to Neo | the signed-off Neo design |
| R3 | Teardown, 1–2 weeks after R2: legacy content structures deleted, legacy modules and themes uninstalled | unchanged |
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

- **Keep Layout Builder on until R3.** Turning it off on a display deletes its `layout_builder__layout` field, every override with it: that is teardown, not cutover. Until then neo_migrate's coexistence renders the tree in the layout's place in the Neo front theme, so neo_migrate stays installed until R4.
- **Place the tree field in the default display** (`content.field_full`, formatter `neo_component_tree`) and re-save the display through the entity API so its dependencies are recorded. Layout Builder ignores it for rendering; neo_alchemist's `[neo:description]` and `[neo:image]` find the tree only through it.
- **Editors lose Layout Builder's tab** by taking "configure any layout" from their roles; the Neo editor's Layout tab stays. Check as a user of that role: `/node/<id>/layout` answers 403.
- **Metatags**: `neo-migrate:metatags` swaps exo's smart tokens. The phase 4 head trial says what else the person decided; `[current-page:metatag:title]` and `[current-page:metatag:description]` restore exo's og:title and og:description. A fixed share image for the front page belongs in the `front` metatag defaults (config), not in a node's own metatags (content).
- exo's `exo/throbber` and `exo_alchemist/front` libraries still attach on Neo pages until the exo suite goes in R3; they raise no errors.

## R3 and R4

Not built yet. The pilot's R3 deleted the legacy content entities, then uninstalled the legacy modules and themes in dependency order; R4 removed the packages with Composer and uninstalled neo_migrate. On an exo site R3 also turns Layout Builder off on the host displays (deleting the overrides), deletes the exo_alchemist `block_content` entities, and uninstalls `layout_builder` and `layout_discovery` once no display uses them.
