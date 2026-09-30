#!/usr/bin/env node
/**
 * neo_migrate parity: before/after screenshot comparison.
 *
 * Run from the site root (it reads migration/parity.yml and migration/urls.json):
 *
 *   node <neo_migrate>/tools/parity/cli.mjs capture --target=prod --label=prod-baseline
 *   node <neo_migrate>/tools/parity/cli.mjs compare prod-baseline local-legacy
 *   node <neo_migrate>/tools/parity/cli.mjs probe --target=prod --path=/ --width=375 '.region.top'
 *
 * Options:
 *   --config=<file>     parity config (default migration/parity.yml)
 *   --urls=<file>       URL list in place of the config's (unpublished pages,
 *                       captured on a target that logs in)
 *   --only=/a,/b        capture only these paths
 *   --widths=375,1440   override the configured widths
 *   --list              compare: print every section's result, not just totals
 *   --path, --width, --depth   probe: the page, the width (default 1440) and
 *                       how many levels of descendants to print (default 3)
 */
import { parseArgs } from 'node:util';
import { loadConfig } from './lib/config.mjs';
import { capture, warm } from './lib/capture.mjs';
import { compare } from './lib/compare.mjs';
import { probe } from './lib/probe.mjs';

const { values, positionals } = parseArgs({
  allowPositionals: true,
  options: {
    config: { type: 'string', default: 'migration/parity.yml' },
    urls: { type: 'string' },
    target: { type: 'string' },
    label: { type: 'string' },
    only: { type: 'string' },
    widths: { type: 'string' },
    path: { type: 'string', default: '/' },
    width: { type: 'string', default: '1440' },
    depth: { type: 'string', default: '3' },
    list: { type: 'boolean' },
    help: { type: 'boolean', short: 'h' },
  },
});

const [command, ...args] = positionals;
const usage = () => {
  console.log('Usage:\n  cli.mjs capture --target=<name> --label=<label> [--only=/a,/b] [--widths=375,1440] [--urls=<file>]\n  cli.mjs warm --target=<name> [--only=/a,/b] [--widths=375,1440]\n  cli.mjs compare <labelA> <labelB> [--list]\n  cli.mjs probe --target=<name> [--path=/] [--width=1440] [--depth=3] <selector>');
};

try {
  if (values.help || !command) {
    usage();
    process.exit(values.help ? 0 : 1);
  }
  const config = loadConfig(values.config, values.urls);
  if (command === 'capture') {
    if (!values.target || !values.label) throw new Error('capture needs --target and --label.');
    const manifest = await capture(config, {
      target: values.target,
      label: values.label,
      only: values.only?.split(',').map((p) => p.trim()),
      widths: values.widths?.split(',').map(Number),
    });
    const errors = manifest.taken.filter((p) => p.error);
    const kept = manifest.pages.length - manifest.taken.length;
    console.log(`\nCaptured ${manifest.taken.length - errors.length} page views of ${values.target} as "${values.label}"${errors.length ? `, ${errors.length} failed` : ''}${kept ? `; kept its other ${kept}` : ''}.`);
    process.exit(errors.length ? 2 : 0);
  }
  if (command === 'warm') {
    if (!values.target) throw new Error('warm needs --target.');
    const { pages, passes, images } = await warm(config, {
      target: values.target,
      only: values.only?.split(',').map((p) => p.trim()),
      widths: values.widths?.split(',').map(Number),
    });
    console.log(`\nWarmed ${values.target} in ${passes} pass(es)${pages.length ? `; ${pages.length} page view(s) still failing` : '; every page view answered, with its images'}.`);
    if (images.length) {
      // A derivative the web cannot convert (a very large photo to AVIF on
      // Pantheon) is written from the command line instead.
      const paths = images.map((url) => `'${new URL(url).pathname}${new URL(url).search}'`);
      console.log(`Write the ${images.length} derivative(s) still failing from the command line, then warm again:\n  drush neo-migrate:derivatives ${paths.join(' ')}`);
    }
    process.exit(pages.length ? 2 : 0);
  }
  if (command === 'compare') {
    if (args.length !== 2) throw new Error('compare needs two capture labels.');
    const { reportDir, totals, pages } = await compare(config, args[0], args[1]);
    if (values.list) {
      const signed = (n) => (n ? `${n > 0 ? '+' : ''}${n}px` : '');
      for (const page of pages) {
        for (const s of page.sections) {
          const extra = [s.heightDelta ? `height ${signed(s.heightDelta)}` : '', s.beforeDelta ? `space above ${signed(s.beforeDelta)}` : ''].filter(Boolean).join(', ');
          console.log(`${page.path} @${page.width} ${s.key}: ${s.result} ${s.percent ?? '—'}%${extra ? ` (${extra})` : ''}`);
        }
      }
    }
    console.log(`\n${totals.passRate}% of ${totals.sections} sections pass (warn ${totals.warn}, fail ${totals.fail}, missing ${totals.missing}); ${totals.textMissing} missing words; ${totals.metaDifferences} head differences; ${totals.statusDifferences} status differences.`);
    console.log(`Report: ${reportDir}/index.html`);
    process.exit(0);
  }
  if (command === 'probe') {
    if (!values.target || args.length !== 1) throw new Error('probe needs --target and one selector.');
    const lines = await probe(config, { target: values.target, path: values.path, width: Number(values.width), selector: args[0], depth: Number(values.depth) });
    console.log(lines.length ? lines.join('\n') : `Nothing matches ${args[0]}.`);
    process.exit(0);
  }
  throw new Error(`Unknown command "${command}".`);
}
catch (error) {
  console.error(`Error: ${error.message}`);
  process.exit(1);
}
