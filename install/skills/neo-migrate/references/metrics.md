# Metrics

`migration/metrics.jsonl` answers the fleet question — is an AI-assisted migration worth repeating? — so it records effort honestly, including the effort of people.

```
ddev drush neo-migrate:metric <event> --phase=<0-6> --step=<what> --actor=<ai|human> --kind=<build|migrate> --minutes=<n> [--type=...] [--diff=<pct>] [--note=...]
ddev drush neo-migrate:metric --summary
```

| Event | Log it when |
| --- | --- |
| `session` | An AI session starts work on the migration (`--session=<id>`). |
| `start` / `end` | A step starts and ends; put the minutes on `end`. |
| `intervention` | A person steps in. `--type`: `decision` (chose between options), `correction` (fixed AI output), `unblock` (did something the AI could not), `review` (a gate or report review). |
| `estimate` | Up front: the person's estimate of the whole migration done by hand (`--actor=human --minutes=<n>`). Never counted in totals. |
| `note` | Anything worth keeping that is not effort: a surprise, a pre-existing bug found. |

`--kind=build` is work on neo_migrate itself; `--kind=migrate` is work on this site. The second site's `migrate` total is the repeatable cost per site.
