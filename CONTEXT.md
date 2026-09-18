# CONTEXT — neo_migrate

Terms specific to this module. General Drupal vocabulary does not belong here.

**Legacy stack** — the modules and themes a site is migrated off: paragraphs and its helpers, micon,
escort, real_favicon, aeon and its sub-theme, the ux modules, and the field types that only served
paragraphs. Which extensions count, and what each becomes, is listed in `neo_migrate.legacy.yml`.

**Handling** — what the audit says happens to a finding. _mechanical_: a neo_migrate command converts
it with no per-site decisions. _judgment_: decided per site by a person, or by the AI with a person
reviewing. _remove_: uninstalled at teardown, nothing carried over. _skip_: present but unused, not
carried over. _unclassified_: not in the catalog; a person decides, and the catalog learns.

**Host entity** — an entity whose field holds a tree: on a paragraphs site, a node with an
entity_reference_revisions field targeting paragraphs.

**Tree** — the ordered items a host field holds, with nested items beneath the fields that nest them.
Read by a **source adapter**, so the rest of the module never depends on which system built it.

**Live** — an item a host's current revision renders. The audit counts live items separately from
rows in the database, which include items only old revisions reference.

**Orphan bundle** — paragraph rows whose type no longer has config. Core refuses to uninstall
paragraphs while any exist, so teardown deletes them first.

**Inventory** — the snapshot of every host entity and its tree taken before anything changes; the
record verification compares the converted site against.

**Baseline** — the parity capture of production taken before the migration; every later capture
is compared with it.

**Section** — one comparable slice of a page in a parity capture: a chrome region (header, footer)
or one top-level item of the body. Matched across captures by key, not position.

**Gate** — the end of a phase, where a person reviews the evidence and says whether the next phase
may start. Recorded in `migration/state.yml`.
