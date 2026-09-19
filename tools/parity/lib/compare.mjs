import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { crop, diff, readPng, writePng } from './png.mjs';
import { slug } from './config.mjs';
import { renderReport } from './report.mjs';

/**
 * Compares two captures page by page and section by section.
 *
 * Sections are matched by key (`header`, `content-03`, …), not by position on
 * the page, so a section that moved down still compares with itself.
 */
export async function compare(config, labelA, labelB) {
  const dirA = join(config.outputDir, 'captures', labelA);
  const dirB = join(config.outputDir, 'captures', labelB);
  const manifestA = readJson(join(dirA, 'manifest.json'));
  const manifestB = readJson(join(dirB, 'manifest.json'));
  const reportDir = join(config.outputDir, 'reports', `${labelA}__${labelB}`);
  mkdirSync(join(reportDir, 'diff'), { recursive: true });

  const keys = new Map();
  for (const page of [...manifestA.pages, ...manifestB.pages]) {
    keys.set(`${page.path}@${page.width}`, { path: page.path, width: page.width });
  }

  const pages = [];
  for (const { path, width } of keys.values()) {
    const a = join(dirA, slug(path), String(width));
    const b = join(dirB, slug(path), String(width));
    pages.push(comparePage(config, { path, width, a, b, reportDir, bases: [manifestA.base, manifestB.base] }));
    process.stdout.write(`compared ${path} @${width}\n`);
  }
  pages.sort((x, y) => x.path.localeCompare(y.path) || x.width - y.width);

  const statusA = readJson(join(dirA, 'status.json'), []);
  const statusB = readJson(join(dirB, 'status.json'), []);
  const status = statusA.map((entry) => {
    const other = statusB.find((candidate) => candidate.path === entry.path);
    const location = (value, base) => value?.replace(base.replace(/\/$/, ''), '') ?? null;
    const same = other && other.status === entry.status && location(other.location, manifestB.base) === location(entry.location, manifestA.base);
    return { path: entry.path, kind: entry.kind, a: entry, b: other ?? null, same: Boolean(same) };
  });

  const sections = pages.flatMap((page) => page.sections);
  const totals = {
    pages: pages.length,
    sections: sections.length,
    pass: sections.filter((s) => s.result === 'pass').length,
    warn: sections.filter((s) => s.result === 'warn').length,
    fail: sections.filter((s) => s.result === 'fail').length,
    missing: sections.filter((s) => s.result === 'missing').length,
    textMissing: pages.reduce((sum, page) => sum + page.text.missingCount, 0),
    metaDifferences: pages.reduce((sum, page) => sum + page.meta.length, 0),
    statusDifferences: status.filter((entry) => !entry.same).length,
  };
  totals.passRate = totals.sections ? Math.round((totals.pass / totals.sections) * 1000) / 10 : 0;

  const summary = { a: { label: labelA, base: manifestA.base }, b: { label: labelB, base: manifestB.base }, thresholds: config.thresholds, totals, pages, status, compared: new Date().toISOString() };
  writeFileSync(join(reportDir, 'summary.json'), JSON.stringify(summary, null, 2));
  writeFileSync(join(reportDir, 'index.html'), renderReport(summary, (file) => relative(reportDir, file)));
  return { reportDir, totals, pages };
}

function comparePage(config, { path, width, a, b, reportDir, bases }) {
  const metaA = readJson(join(a, 'meta.json'), null);
  const metaB = readJson(join(b, 'meta.json'), null);
  const page = { path, width, a: metaA ? join(a, 'full.png') : null, b: metaB ? join(b, 'full.png') : null, statusA: metaA?.status ?? null, statusB: metaB?.status ?? null };
  if (!metaA || !metaB || !existsSync(page.a) || !existsSync(page.b)) {
    return { ...page, full: null, sections: [], text: { missing: [], extra: [], missingCount: 0, extraCount: 0 }, meta: [], note: 'captured on one side only' };
  }
  const pngA = readPng(page.a);
  const pngB = readPng(page.b);
  page.full = diff(pngA, pngB).percent;
  page.sizeA = { width: pngA.width, height: pngA.height };
  page.sizeB = { width: pngB.width, height: pngB.height };

  const byKey = (sections) => new Map(sections.map((section) => [section.key, section]));
  const sectionsA = byKey(metaA.sections);
  const sectionsB = byKey(metaB.sections);
  const keys = [...new Set([...sectionsA.keys(), ...sectionsB.keys()])];
  page.sections = keys.map((key) => {
    const sa = sectionsA.get(key);
    const sb = sectionsB.get(key);
    const entry = { path, width, key, a: sa ? pick(sa) : null, b: sb ? pick(sb) : null };
    if (!sa || !sb) {
      return { ...entry, result: 'missing', percent: null };
    }
    const result = diff(crop(pngA, sa.x, sa.y, sa.width, sa.height), crop(pngB, sb.x, sb.y, sb.width, sb.height));
    entry.percent = result.percent;
    entry.heightDelta = sb.height - sa.height;
    if (sa.before !== undefined && sb.before !== undefined) {
      entry.beforeDelta = sb.before - sa.before;
    }
    entry.result = result.percent <= config.thresholds.pass ? 'pass' : result.percent <= config.thresholds.fail ? 'warn' : 'fail';
    if (entry.result !== 'pass') {
      entry.diff = join(reportDir, 'diff', `${slug(path)}-${width}-${key}.png`);
      writePng(entry.diff, result.diff);
    }
    return entry;
  });

  page.text = textDiff(metaA.text, metaB.text);
  page.meta = metaDiff(metaA, metaB, bases);
  return page;
}

/**
 * Words present in one text and not the other, counted as multisets.
 */
function textDiff(a, b) {
  const words = (text) => (text ?? '').toLowerCase().replace(/[^\p{L}\p{N}\s]+/gu, ' ').split(/\s+/).filter(Boolean);
  const count = (list) => list.reduce((map, word) => map.set(word, (map.get(word) ?? 0) + 1), new Map());
  const ca = count(words(a));
  const cb = count(words(b));
  const missing = [];
  const extra = [];
  for (const [word, n] of ca) {
    for (let i = 0; i < n - (cb.get(word) ?? 0); i++) missing.push(word);
  }
  for (const [word, n] of cb) {
    for (let i = 0; i < n - (ca.get(word) ?? 0); i++) extra.push(word);
  }
  return { missingCount: missing.length, extraCount: extra.length, missing: missing.slice(0, 80), extra: extra.slice(0, 80) };
}

/**
 * Head values that differ, with each site's own host removed first.
 */
function metaDiff(a, b, [baseA, baseB]) {
  const strip = (value, base) => (typeof value === 'string' ? value.split(base.replace(/\/$/, '')).join('') : value ?? null);
  const differences = [];
  const check = (name, va, vb) => {
    const na = strip(va, baseA);
    const nb = strip(vb, baseB);
    if (na !== nb) differences.push({ name, a: na, b: nb });
  };
  check('title', a.title, b.title);
  check('canonical', a.canonical, b.canonical);
  check('status', a.status, b.status);
  for (const name of new Set([...Object.keys(a.tags ?? {}), ...Object.keys(b.tags ?? {})])) {
    check(name, a.tags?.[name], b.tags?.[name]);
  }
  return differences;
}

function pick(section) {
  return { x: section.x, y: section.y, width: section.width, height: section.height, before: section.before, box: section.box, classes: section.classes };
}

function readJson(file, fallback) {
  if (!existsSync(file)) {
    if (fallback !== undefined) return fallback;
    throw new Error(`Missing ${file}`);
  }
  return JSON.parse(readFileSync(file, 'utf8'));
}
