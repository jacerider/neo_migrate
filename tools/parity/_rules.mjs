import { chromium } from 'playwright';
const [url, sel, width, filter] = process.argv.slice(2);
const b = await chromium.launch(); const p = await b.newPage({ ignoreHTTPSErrors: true, viewport: { width: Number(width || 1440), height: 900 } });
await p.goto(url, { waitUntil: 'load' });
const out = await p.evaluate(([sel, filter]) => {
  const el = document.querySelector(sel); const res = [];
  for (const sh of document.styleSheets) { let rules; try { rules = sh.cssRules; } catch { continue; }
    const walk = (rs, media) => { for (const r of rs) { if (r.cssRules && !r.selectorText) { if (!r.media || matchMedia(r.media.mediaText).matches) walk(r.cssRules, r.media?.mediaText); continue; }
      if (r.selectorText && el.matches(r.selectorText) && (!filter || new RegExp(filter).test(r.style.cssText))) res.push((media ? '@' + media + ' ' : '') + r.cssText.slice(0, 260)); } };
    walk(rules); }
  return res; }, [sel, filter]);
console.log(out.join('\n')); await b.close();
