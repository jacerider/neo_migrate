import { execSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { chromium, request } from 'playwright';
import { loadUrls, slug } from './config.mjs';

/**
 * Style properties recorded for every sampled element.
 */
const STYLE_PROPS = [
  'display', 'position', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height',
  'letter-spacing', 'text-transform', 'text-align', 'color', 'background-color', 'background-image',
  'padding', 'margin', 'max-width', 'width', 'height', 'gap', 'border', 'border-radius', 'box-shadow',
];

/**
 * Descendants sampled inside each section when recording styles.
 */
const STYLE_SAMPLES = ['h1', 'h2', 'h3', 'h4', 'p', 'a', '.button', '.btn', 'button', 'img', 'li', 'input'];

/**
 * The banner neo_migrate prints on every page while a theme preview is on.
 */
const PREVIEW_BANNER = '.neo-migrate-preview';

/**
 * Screenshots every visual URL at every width, and checks every status URL.
 */
export async function capture(config, { target: name, label, only, widths }) {
  const target = config.targets[name];
  if (!target) {
    throw new Error(`Unknown target "${name}". Known: ${Object.keys(config.targets).join(', ')}`);
  }
  const base = target.url;
  const urls = loadUrls(config).filter((url) => !only || only.includes(url.path));
  const dir = join(config.outputDir, 'captures', label);
  mkdirSync(dir, { recursive: true });

  // Status codes are checked anonymously on every target: a session changes
  // what a page looks like, not whether it exists.
  const status = await checkStatus(base, urls.filter((url) => url.check === 'status'));
  writeFileSync(join(dir, 'status.json'), JSON.stringify(status, null, 2));

  const browser = await chromium.launch();
  const session = target.login ? await startSession(browser, config, target) : undefined;
  const hide = [...config.hide, ...target.hide, ...(target.preview ? [PREVIEW_BANNER] : [])];
  const jobs = [];
  for (const url of urls.filter((u) => u.check === 'visual')) {
    for (const width of widths ?? config.widths) {
      jobs.push({ url, width });
    }
  }
  const results = [];
  let done = 0;
  const worker = async () => {
    while (jobs.length) {
      const job = jobs.shift();
      const result = await capturePage(browser, config, { base, session, hide }, dir, job).catch((error) => ({ path: job.url.path, width: job.width, error: String(error) }));
      results.push(result);
      done++;
      const tag = result.error ? `ERROR ${result.error}` : `${result.status} ${result.sections} sections`;
      process.stdout.write(`[${done}/${done + jobs.length}] ${job.url.path} @${job.width} — ${tag}\n`);
    }
  };
  await Promise.all(Array.from({ length: config.concurrency }, worker));
  await browser.close();

  const manifest = { label, target: name, base, preview: target.preview ?? null, captured: new Date().toISOString(), widths: widths ?? config.widths, pages: results };
  writeFileSync(join(dir, 'manifest.json'), JSON.stringify(manifest, null, 2));
  return manifest;
}

/**
 * One URL at one width: full-page shot, section boxes, text, meta, styles.
 */
async function capturePage(browser, config, { base, session, hide }, dir, { url, width }) {
  const context = await browser.newContext({
    storageState: session,
    viewport: { width, height: config.height },
    reducedMotion: 'reduce',
    ignoreHTTPSErrors: true,
    deviceScaleFactor: 1,
  });
  const page = await context.newPage();
  await page.route('**/*', (route) => {
    const request = route.request().url();
    return config.block.some((pattern) => request.includes(pattern)) ? route.abort() : route.continue();
  });
  try {
    const response = await page.goto(base + url.path, { waitUntil: 'load', timeout: 60000 });
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    await page.addStyleTag({
      content: [
        '*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }',
        // Smooth scrolling would turn settle()'s jumps into animations still
        // running when the shot is taken.
        'html, body { scroll-behavior: auto !important; }',
        ...hide.map((selector) => `${selector} { display: none !important; }`),
      ].join('\n'),
    });
    await settle(page);

    const out = join(dir, slug(url.path), String(width));
    mkdirSync(out, { recursive: true });
    const mask = config.mask.map((selector) => page.locator(selector));
    await page.screenshot({ path: join(out, 'full.png'), fullPage: true, animations: 'disabled', mask, maskColor: '#ff00ff' });

    const data = await page.evaluate(collect, { themes: config.themes, styleProps: STYLE_PROPS, samples: STYLE_SAMPLES });
    const meta = {
      path: url.path,
      kind: url.kind,
      width,
      status: response?.status() ?? null,
      url: page.url(),
      ...data.meta,
      theme: data.theme,
      sections: data.sections,
      text: data.text,
    };
    writeFileSync(join(out, 'meta.json'), JSON.stringify(meta, null, 2));
    writeFileSync(join(out, 'styles.json'), JSON.stringify(data.styles, null, 2));
    return { path: url.path, width, status: meta.status, theme: data.theme, sections: data.sections.length, dir: out };
  }
  finally {
    await context.close();
  }
}

/**
 * Logs in once with the target's one-time login command, turns on the theme
 * preview when the target asks for one, and returns the session for every
 * page context to reuse.
 */
export async function startSession(browser, config, target) {
  const output = execSync(target.login, { cwd: config.root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  const link = output.split('\n').map((line) => line.trim()).find((line) => /^https?:\/\//.test(line));
  if (!link) {
    throw new Error(`"${target.login}" printed no login link.`);
  }
  // Only the path is used: drush may print a link for a different host than
  // the one the target is reached on.
  const { pathname, search } = new URL(link);
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  try {
    const page = await context.newPage();
    await page.goto(target.url + pathname + search, { waitUntil: 'load', timeout: 60000 });
    if (/\/user\/reset\//.test(new URL(page.url()).pathname)) {
      throw new Error('The one-time login link was refused (already used or expired).');
    }
    if (target.preview) {
      await page.goto(`${target.url}/neo-migrate/preview/${target.preview}`, { waitUntil: 'load', timeout: 60000 });
      // The banner is printed only when Drupal itself sees the preview cookie,
      // so this proves the cookie survives any CDN in front of the site, not
      // just that the browser holds it.
      await page.goto(`${target.url}/`, { waitUntil: 'load', timeout: 60000 });
      if (!(await page.locator(PREVIEW_BANNER).count())) {
        throw new Error(`The "${target.preview}" preview did not switch on: no preview banner. Does the login user have "preview neo migration", and does the host pass the preview cookie through?`);
      }
    }
    return await context.storageState();
  }
  finally {
    await context.close();
  }
}

/**
 * Scrolls through the page so lazy content loads, then waits for fonts and
 * images before returning to the top.
 */
async function settle(page) {
  await page.evaluate(async () => {
    const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    for (let y = 0; y < document.documentElement.scrollHeight; y += Math.floor(window.innerHeight * 0.8)) {
      window.scrollTo(0, y);
      await pause(120);
    }
    window.scrollTo(0, 0);
    await document.fonts.ready;
    const loaded = (timeout) => Promise.all([...document.images].filter((img) => !img.complete).map((img) => new Promise((resolve) => {
      img.addEventListener('load', resolve, { once: true });
      img.addEventListener('error', resolve, { once: true });
      setTimeout(resolve, timeout);
    })));
    await loaded(20000);
    // An image style derivative is generated on its first request, which on a
    // fresh environment can outlast the wait, and a request that arrives while
    // another holds the generation lock gets a 503. Fetch each broken one until
    // it is served (up to 30s), then swap in a fresh copy of it to load again.
    const broken = [...document.images].filter((img) => img.complete && img.naturalWidth === 0 && img.currentSrc);
    for (const img of broken) {
      for (let attempt = 0; attempt < 10; attempt++) {
        const response = await fetch(img.currentSrc).catch(() => null);
        if (response?.ok) break;
        await pause(3000);
      }
      const node = img.closest('picture') ?? img;
      node.replaceWith(node.cloneNode(true));
    }
    if (broken.length) {
      await loaded(20000);
    }
    await pause(300);
  });
}

/**
 * Runs in the page: detects the theme, measures its sections, and records
 * text, head tags and computed styles.
 */
function collect({ themes, styleProps, samples }) {
  const visibleText = (el) => (el?.innerText ?? '').replace(/\s+/g, ' ').trim();
  const toBox = (rect) => ({ x: Math.round(rect.left + window.scrollX), y: Math.round(rect.top + window.scrollY), width: Math.round(rect.width), height: Math.round(rect.height) });
  const box = (el) => toBox(el.getBoundingClientRect());

  // The painted box: the smallest rectangle holding everything the element
  // shows — its own background or border if it has one, otherwise the text,
  // images and painted boxes inside it, clipped where an ancestor clips. Space
  // a section only reserves (padding, empty wrappers) is left out, so a
  // section spaced with margins compares equal to one spaced with padding.
  const REPLACED = new Set(['IMG', 'SVG', 'VIDEO', 'IFRAME', 'CANVAS', 'INPUT', 'SELECT', 'TEXTAREA', 'BUTTON', 'OBJECT', 'EMBED', 'HR']);
  const clear = (color) => color === 'transparent' || /rgba\(.*,\s*0\)$/.test(color);
  const paints = (cs) => !clear(cs.backgroundColor) || cs.backgroundImage !== 'none' || cs.boxShadow !== 'none'
    || ['Top', 'Right', 'Bottom', 'Left'].some((side) => parseFloat(cs[`border${side}Width`]) > 0 && cs[`border${side}Style`] !== 'none' && !clear(cs[`border${side}Color`]));
  // A pseudo-element paints when it shows text (an icon glyph, a dash) or
  // draws a box; a clearfix's `content: " "` does neither.
  const pseudo = (el) => ['::before', '::after'].some((which) => {
    const cs = getComputedStyle(el, which);
    if (!cs.content || cs.content === 'none' || cs.content === 'normal' || cs.display === 'none') return false;
    return cs.content.replace(/^["']|["']$/g, '').trim() !== '' || paints(cs);
  });
  const intersect = (a, b) => (!a ? b : !b ? a : { left: Math.max(a.left, b.left), top: Math.max(a.top, b.top), right: Math.min(a.right, b.right), bottom: Math.min(a.bottom, b.bottom) });
  const paintedBox = (root) => {
    let ink = null;
    const add = (rect, clip) => {
      const r = intersect(clip, { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom });
      if (r.right - r.left <= 1 || r.bottom - r.top <= 1) return;
      ink = ink ? { left: Math.min(ink.left, r.left), top: Math.min(ink.top, r.top), right: Math.max(ink.right, r.right), bottom: Math.max(ink.bottom, r.bottom) } : r;
    };
    const walk = (el, clip) => {
      const cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') return;
      // Visually hidden (sr-only) content paints nothing.
      if (cs.clip === 'rect(0px, 0px, 0px, 0px)' || cs.clipPath === 'inset(50%)') return;
      const rect = el.getBoundingClientRect();
      if (paints(cs) || REPLACED.has(el.tagName.toUpperCase()) || pseudo(el)) {
        add(rect, clip);
      }
      const inner = cs.overflowX !== 'visible' || cs.overflowY !== 'visible' ? intersect(clip, rect) : clip;
      for (const node of el.childNodes) {
        if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
          const range = document.createRange();
          range.selectNodeContents(node);
          for (const r of range.getClientRects()) add(r, inner);
        }
        else if (node.nodeType === Node.ELEMENT_NODE) {
          walk(node, inner);
        }
      }
    };
    // Clipped to the page, so content parked off-screen (a honeypot field)
    // does not stretch the box.
    const page = { left: -window.scrollX, top: -window.scrollY, right: document.documentElement.scrollWidth - window.scrollX, bottom: document.documentElement.scrollHeight - window.scrollY };
    walk(root, page);
    return ink ? toBox({ left: ink.left, top: ink.top, width: ink.right - ink.left, height: ink.bottom - ink.top }) : null;
  };
  const style = (el) => {
    const computed = getComputedStyle(el);
    return Object.fromEntries(styleProps.map((prop) => [prop, computed.getPropertyValue(prop).slice(0, 200)]));
  };

  const themeName = Object.keys(themes).find((name) => document.querySelector(themes[name].detect)) ?? null;
  const theme = themeName ? themes[themeName] : { sections: [{ name: 'body', selector: 'body' }] };

  const sections = [];
  const styles = {};
  for (const section of theme.sections) {
    let elements = [...document.querySelectorAll(section.selector)];
    if (!elements.length && section.fallback) {
      elements = [...document.querySelectorAll(section.fallback)].slice(0, 1);
    }
    if (!section.each) {
      elements = elements.slice(0, 1);
    }
    // Numbered by what is shown, so a hidden match leaves no gap in the keys.
    let index = 0;
    elements.forEach((el) => {
      const border = box(el);
      const rect = section.box === 'painted' ? paintedBox(el) : border;
      if (!rect?.width || !rect?.height) {
        return;
      }
      const key = section.each ? `${section.name}-${String(index).padStart(2, '0')}` : section.name;
      index++;
      sections.push({ key, name: section.name, index: index - 1, ...rect, box: section.box ?? 'border', border, classes: el.className?.toString().slice(0, 160) ?? '', text: visibleText(el).slice(0, 4000) });
      styles[key] = {
        root: style(el),
        samples: samples.flatMap((selector) => [...el.querySelectorAll(selector)].slice(0, 3).map((child) => ({
          selector,
          tag: child.tagName.toLowerCase(),
          classes: child.className?.toString().slice(0, 120) ?? '',
          text: visibleText(child).slice(0, 60),
          box: box(child),
          style: style(child),
        }))),
      };
    });
  }
  // The space above each section, down from the one before it, so spacing
  // stays comparable when painted boxes leave it out of the sections.
  sections.forEach((section, i) => {
    const previous = sections[i - 1];
    section.before = previous ? section.y - (previous.y + previous.height) : section.y;
  });

  const tags = {};
  for (const el of document.querySelectorAll('meta[name], meta[property]')) {
    tags[el.getAttribute('name') ?? el.getAttribute('property')] = el.getAttribute('content');
  }
  return {
    theme: themeName,
    meta: {
      title: document.title,
      canonical: document.querySelector('link[rel="canonical"]')?.getAttribute('href') ?? null,
      tags,
      h1: [...document.querySelectorAll('h1')].map(visibleText),
      height: document.documentElement.scrollHeight,
    },
    sections,
    text: visibleText(document.querySelector(theme.text ?? 'main') ?? document.body),
    styles,
  };
}

/**
 * Requests each status URL without following redirects.
 */
async function checkStatus(base, urls) {
  const api = await request.newContext({ ignoreHTTPSErrors: true });
  const results = [];
  for (const url of urls) {
    try {
      const response = await api.get(base + url.path, { maxRedirects: 0, timeout: 30000 });
      results.push({ path: url.path, kind: url.kind, status: response.status(), location: response.headers().location ?? null });
    }
    catch (error) {
      results.push({ path: url.path, kind: url.kind, error: String(error) });
    }
  }
  await api.dispose();
  return results;
}
