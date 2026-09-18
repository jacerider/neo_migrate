#!/usr/bin/env node
/**
 * neo_migrate parity: before/after screenshot comparison.
 *
 * Run from the site root (it reads migration/parity.yml and migration/urls.json):
 *
 *   node <neo_migrate>/tools/parity/cli.mjs capture --target=prod --label=prod-baseline
 *   node <neo_migrate>/tools/parity/cli.mjs compare prod-baseline local-legacy
 *
 * Options:
 *   --config=<file>     parity config (default migration/parity.yml)
 *   --only=/a,/b        capture only these paths
 *   --widths=375,1440   override the configured widths
 */
import { parseArgs } from 'node:util';
import { loadConfig } from './lib/config.mjs';
import { capture } from './lib/capture.mjs';
import { compare } from './lib/compare.mjs';

const { values, positionals } = parseArgs({
  allowPositionals: true,
  options: {
    config: { type: 'string', default: 'migration/parity.yml' },
    target: { type: 'string' },
    label: { type: 'string' },
    only: { type: 'string' },
    widths: { type: 'string' },
    help: { type: 'boolean', short: 'h' },
  },
});

const [command, ...args] = positionals;
const usage = () => {
  console.log('Usage:\n  cli.mjs capture --target=<name> --label=<label> [--only=/a,/b] [--widths=375,1440]\n  cli.mjs compare <labelA> <labelB>');
};

try {
  if (values.help || !command) {
    usage();
    process.exit(values.help ? 0 : 1);
  }
  const config = loadConfig(values.config);
  if (command === 'capture') {
    if (!values.target || !values.label) throw new Error('capture needs --target and --label.');
    const manifest = await capture(config, {
      target: values.target,
      label: values.label,
      only: values.only?.split(',').map((p) => p.trim()),
      widths: values.widths?.split(',').map(Number),
    });
    const errors = manifest.pages.filter((p) => p.error);
    console.log(`\nCaptured ${manifest.pages.length - errors.length} page views of ${values.target} as "${values.label}"${errors.length ? `, ${errors.length} failed` : ''}.`);
    process.exit(errors.length ? 2 : 0);
  }
  if (command === 'compare') {
    if (args.length !== 2) throw new Error('compare needs two capture labels.');
    const { reportDir, totals } = await compare(config, args[0], args[1]);
    console.log(`\n${totals.passRate}% of ${totals.sections} sections pass (warn ${totals.warn}, fail ${totals.fail}, missing ${totals.missing}); ${totals.textMissing} missing words; ${totals.metaDifferences} head differences; ${totals.statusDifferences} status differences.`);
    console.log(`Report: ${reportDir}/index.html`);
    process.exit(0);
  }
  throw new Error(`Unknown command "${command}".`);
}
catch (error) {
  console.error(`Error: ${error.message}`);
  process.exit(1);
}
