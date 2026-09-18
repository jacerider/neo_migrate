import { chromium } from 'playwright';
import { startSession } from './capture.mjs';

/**
 * Computed properties printed for every probed element.
 */
const PROPS = ['display', 'position', 'font-size', 'font-weight', 'line-height', 'vertical-align', 'padding', 'margin'];

/**
 * Prints unrounded boxes and key styles for the elements matching a selector
 * and their descendants, on one page of one target at one width. For chasing
 * the sub-pixel offsets a section diff points at, where capture's rounded
 * boxes look identical.
 */
export async function probe(config, { target: name, path, width, selector, depth }) {
  const target = config.targets[name];
  if (!target) {
    throw new Error(`Unknown target "${name}". Known: ${Object.keys(config.targets).join(', ')}`);
  }
  const browser = await chromium.launch();
  try {
    const session = target.login ? await startSession(browser, config, target) : undefined;
    const context = await browser.newContext({ storageState: session, viewport: { width, height: config.height }, ignoreHTTPSErrors: true, deviceScaleFactor: 1 });
    const page = await context.newPage();
    await page.goto(target.url + path, { waitUntil: 'load', timeout: 60000 });
    await page.evaluate(() => document.fonts.ready);
    const lines = await page.evaluate(({ selector, depth, props }) => {
      const out = [];
      const describe = (el, level) => {
        const rect = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        const classes = String(el.className?.baseVal ?? el.className ?? '').trim().split(/\s+/).filter(Boolean).slice(0, 4).join('.');
        const text = [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join(' ').slice(0, 24);
        out.push(`${'  '.repeat(level)}${el.tagName.toLowerCase()}${classes ? '.' + classes : ''}${text ? ` "${text}"` : ''}  [${[rect.left, rect.top + window.scrollY, rect.width, rect.height].map((v) => v.toFixed(2)).join(', ')}]  ${props.map((p) => `${p}=${style.getPropertyValue(p)}`).join(' ')}`);
        if (level < depth) {
          [...el.children].forEach((child) => describe(child, level + 1));
        }
      };
      document.querySelectorAll(selector).forEach((el) => describe(el, 0));
      return out;
    }, { selector, depth, props: PROPS });
    await context.close();
    return lines;
  }
  finally {
    await browser.close();
  }
}
