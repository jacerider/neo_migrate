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
 * Screenshots every visual URL at every width, and checks every status URL.
 */
export async function capture(config, { target, label, only, widths }) {
  const base = config.targets?.[target];
  if (!base) {
    throw new Error(`Unknown target "${target}". Known: ${Object.keys(config.targets ?? {}).join(', ')}`);
  }
  const urls = loadUrls(config).filter((url) => !only || only.includes(url.path));
  const dir = join(config.outputDir, 'captures', label);
  mkdirSync(dir, { recursive: true });

  const status = await checkStatus(base, urls.filter((url) => url.check === 'status'));
  writeFileSync(join(dir, 'status.json'), JSON.stringify(status, null, 2));

  const browser = await chromium.launch();
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
      const result = await capturePage(browser, config, base, dir, job).catch((error) => ({ path: job.url.path, width: job.width, error: String(error) }));
      results.push(result);
      done++;
      const tag = result.error ? `ERROR ${result.error}` : `${result.status} ${result.sections} sections`;
      process.stdout.write(`[${done}/${done + jobs.length}] ${job.url.path} @${job.width} — ${tag}\n`);
    }
  };
  await Promise.all(Array.from({ length: config.concurrency }, worker));
  await browser.close();

  const manifest = { label, target, base, captured: new Date().toISOString(), widths: widths ?? config.widths, pages: results };
  writeFileSync(join(dir, 'manifest.json'), JSON.stringify(manifest, null, 2));
  return manifest;
}

/**
 * One URL at one width: full-page shot, section boxes, text, meta, styles.
 */
async function capturePage(browser, config, base, dir, { url, width }) {
  const context = await browser.newContext({
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
    const response = await page.goto(base.replace(/\/$/, '') + url.path, { waitUntil: 'load', timeout: 60000 });
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    await page.addStyleTag({
      content: [
        '*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }',
        ...config.hide.map((selector) => `${selector} { display: none !important; }`),
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
    await Promise.all([...document.images].filter((img) => !img.complete).map((img) => new Promise((resolve) => {
      img.addEventListener('load', resolve, { once: true });
      img.addEventListener('error', resolve, { once: true });
      setTimeout(resolve, 5000);
    })));
    await pause(300);
  });
}

/**
 * Runs in the page: detects the theme, measures its sections, and records
 * text, head tags and computed styles.
 */
function collect({ themes, styleProps, samples }) {
  const visibleText = (el) => (el?.innerText ?? '').replace(/\s+/g, ' ').trim();
  const box = (el) => {
    const rect = el.getBoundingClientRect();
    return { x: Math.round(rect.left + window.scrollX), y: Math.round(rect.top + window.scrollY), width: Math.round(rect.width), height: Math.round(rect.height) };
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
    elements.forEach((el, index) => {
      const key = section.each ? `${section.name}-${String(index).padStart(2, '0')}` : section.name;
      const rect = box(el);
      if (!rect.width || !rect.height) {
        return;
      }
      sections.push({ key, name: section.name, index, ...rect, classes: el.className?.toString().slice(0, 160) ?? '', text: visibleText(el).slice(0, 4000) });
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
      const response = await api.get(base.replace(/\/$/, '') + url.path, { maxRedirects: 0, timeout: 30000 });
      results.push({ path: url.path, kind: url.kind, status: response.status(), location: response.headers().location ?? null });
    }
    catch (error) {
      results.push({ path: url.path, kind: url.kind, error: String(error) });
    }
  }
  await api.dispose();
  return results;
}
