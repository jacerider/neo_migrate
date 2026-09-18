import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import YAML from 'yaml';

/**
 * Defaults every site's parity.yml is layered on.
 */
const DEFAULTS = {
  urls: 'migration/urls.json',
  output: '.neo-migrate',
  widths: [375, 768, 1440],
  height: 900,
  concurrency: 3,
  thresholds: { pass: 2, fail: 10 },
  block: [
    'googletagmanager.com',
    'google-analytics.com',
    'analytics.google.com',
    'doubleclick.net',
    'connect.facebook.net',
    'facebook.com/tr',
    'bat.bing.com',
    'hotjar',
    'clarity.ms',
  ],
  mask: [],
  hide: [],
  themes: {},
};

/**
 * Loads migration/parity.yml (or the given file) from the site root.
 */
export function loadConfig(file = 'migration/parity.yml') {
  const path = resolve(process.cwd(), file);
  if (!existsSync(path)) {
    throw new Error(`No parity config at ${path}. Run from the site root, or pass --config.`);
  }
  const site = YAML.parse(readFileSync(path, 'utf8')) ?? {};
  const config = { ...DEFAULTS, ...site, thresholds: { ...DEFAULTS.thresholds, ...(site.thresholds ?? {}) } };
  config.block = [...new Set([...DEFAULTS.block, ...(site.block ?? [])])];
  config.root = process.cwd();
  config.file = path;
  config.outputDir = resolve(config.root, config.output);
  config.urlsFile = resolve(config.root, config.urls);
  return config;
}

/**
 * The URL list written by `drush neo-migrate:urls`.
 */
export function loadUrls(config) {
  if (!existsSync(config.urlsFile)) {
    throw new Error(`No URL list at ${config.urlsFile}. Run "drush neo-migrate:urls" first.`);
  }
  return JSON.parse(readFileSync(config.urlsFile, 'utf8'));
}

/**
 * A filesystem-safe name for a path: "/" is "front".
 */
export function slug(path) {
  const clean = path.replace(/[?#].*$/, '').replace(/^\/+|\/+$/g, '');
  return clean === '' ? 'front' : clean.replace(/[^a-z0-9_-]+/gi, '__');
}
