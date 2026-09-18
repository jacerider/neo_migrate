/**
 * The comparison as one static HTML page.
 *
 * Sections are shown by cropping the full-page captures with CSS, so the
 * report writes no before/after images of its own — only diff images for
 * sections over the pass threshold.
 */
export function renderReport(summary, rel) {
  const { totals, thresholds } = summary;
  const esc = (value) => String(value ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
  const badge = (result) => `<span class="badge ${result}">${result}</span>`;
  const view = (file, size, box, max = 460) => {
    if (!file || !size || !box) return '<div class="none">—</div>';
    const scale = Math.min(1, max / box.width);
    return `<div class="crop" style="width:${Math.round(box.width * scale)}px;height:${Math.round(box.height * scale)}px;background-image:url('${esc(rel(file))}');background-size:${Math.round(size.width * scale)}px auto;background-position:-${Math.round(box.x * scale)}px -${Math.round(box.y * scale)}px"></div>`;
  };

  const worst = (page) => page.sections.reduce((max, s) => Math.max(max, s.percent ?? 0), 0);
  const rows = summary.pages.map((page, i) => {
    const counts = ['pass', 'warn', 'fail', 'missing'].map((r) => page.sections.filter((s) => s.result === r).length);
    return `<tr>
      <td><a href="#p${i}">${esc(page.path)}</a></td><td>${page.width}</td>
      <td>${page.full ?? '—'}%</td><td>${worst(page)}%</td>
      <td>${counts[0]}</td><td class="${counts[1] ? 'warn' : ''}">${counts[1]}</td><td class="${counts[2] ? 'fail' : ''}">${counts[2]}</td><td class="${counts[3] ? 'fail' : ''}">${counts[3]}</td>
      <td class="${page.text.missingCount ? 'fail' : ''}">${page.text.missingCount}</td><td class="${page.meta.length ? 'warn' : ''}">${page.meta.length}</td>
    </tr>`;
  }).join('');

  const details = summary.pages.map((page, i) => {
    const sections = page.sections.map((s) => `
      <div class="section ${s.result}">
        <h4>${esc(s.key)} ${badge(s.result)} ${s.percent ?? '—'}%${s.heightDelta ? ` · height ${s.heightDelta > 0 ? '+' : ''}${s.heightDelta}px` : ''}</h4>
        <div class="trio">
          <figure>${view(page.a, page.sizeA, s.a)}<figcaption>${esc(summary.a.label)}</figcaption></figure>
          <figure>${view(page.b, page.sizeB, s.b)}<figcaption>${esc(summary.b.label)}</figcaption></figure>
          ${s.diff ? `<figure>${view(s.diff, { width: Math.max(s.a.width, s.b.width) }, { x: 0, y: 0, width: Math.max(s.a.width, s.b.width), height: Math.max(s.a.height, s.b.height) })}<figcaption>diff</figcaption></figure>` : ''}
        </div>
      </div>`).join('');
    const meta = page.meta.length ? `<table class="meta"><tr><th>Tag</th><th>${esc(summary.a.label)}</th><th>${esc(summary.b.label)}</th></tr>${page.meta.map((m) => `<tr><td>${esc(m.name)}</td><td>${esc(m.a)}</td><td>${esc(m.b)}</td></tr>`).join('')}</table>` : '';
    const text = page.text.missingCount || page.text.extraCount
      ? `<p class="text"><b>Missing words (${page.text.missingCount}):</b> ${esc(page.text.missing.join(' '))}<br><b>Extra words (${page.text.extraCount}):</b> ${esc(page.text.extra.join(' '))}</p>` : '';
    return `<section id="p${i}" class="page"><h3>${esc(page.path)} @ ${page.width}px <small>full page ${page.full ?? '—'}% · status ${page.statusA ?? '—'} → ${page.statusB ?? '—'}</small></h3>${page.note ? `<p class="fail">${esc(page.note)}</p>` : ''}${text}${meta}${sections}</section>`;
  }).join('');

  const status = summary.status.filter((s) => !s.same);
  const statusTable = status.length
    ? `<table><tr><th>Path</th><th>${esc(summary.a.label)}</th><th>${esc(summary.b.label)}</th></tr>${status.map((s) => `<tr><td>${esc(s.path)}</td><td>${esc(s.a?.status)} ${esc(s.a?.location)}</td><td>${esc(s.b?.status)} ${esc(s.b?.location)}</td></tr>`).join('')}</table>`
    : `<p>All ${summary.status.length} status checks match.</p>`;

  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Parity: ${esc(summary.a.label)} vs ${esc(summary.b.label)}</title>
<style>
:root{--pass:#1a7f37;--warn:#9a6700;--fail:#cf222e;--line:#d0d7de;--muted:#57606a}
body{font:14px/1.45 system-ui,sans-serif;margin:0;padding:24px;color:#1f2328;background:#fff}
h1{font-size:20px;margin:0 0 4px}h3{margin:32px 0 8px;border-top:1px solid var(--line);padding-top:16px}h3 small{color:var(--muted);font-weight:400}
h4{margin:16px 0 6px;font-size:13px}
.kpis{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0}.kpi{border:1px solid var(--line);border-radius:6px;padding:8px 12px;min-width:110px}.kpi b{display:block;font-size:20px}
table{border-collapse:collapse;margin:8px 0}td,th{border:1px solid var(--line);padding:4px 8px;text-align:left;vertical-align:top}th{background:#f6f8fa}
.warn{color:var(--warn)}.fail{color:var(--fail)}.pass{color:var(--pass)}
.badge{font-size:11px;padding:1px 6px;border-radius:10px;border:1px solid currentColor}.badge.pass{color:var(--pass)}.badge.warn{color:var(--warn)}.badge.fail,.badge.missing{color:var(--fail)}
.trio{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start}figure{margin:0}figcaption{font-size:11px;color:var(--muted)}
.crop{border:1px solid var(--line);background-repeat:no-repeat}.none{width:120px;height:40px;border:1px dashed var(--line);display:grid;place-items:center;color:var(--muted)}
.section.pass .trio{opacity:.85}.text{background:#fff8c5;padding:8px;border-radius:6px;word-break:break-word}.meta td{max-width:420px;word-break:break-word}
.wrap{overflow-x:auto}
</style></head><body>
<h1>Parity: ${esc(summary.a.label)} → ${esc(summary.b.label)}</h1>
<div style="color:var(--muted)">${esc(summary.a.base)} vs ${esc(summary.b.base)} · compared ${esc(summary.compared)} · pass ≤ ${thresholds.pass}%, fail &gt; ${thresholds.fail}%</div>
<div class="kpis">
<div class="kpi"><b>${totals.passRate}%</b>sections pass</div>
<div class="kpi"><b>${totals.pass}</b>pass</div><div class="kpi warn"><b>${totals.warn}</b>warn</div><div class="kpi fail"><b>${totals.fail}</b>fail</div><div class="kpi fail"><b>${totals.missing}</b>missing</div>
<div class="kpi ${totals.textMissing ? 'fail' : ''}"><b>${totals.textMissing}</b>missing words</div>
<div class="kpi ${totals.metaDifferences ? 'warn' : ''}"><b>${totals.metaDifferences}</b>head differences</div>
<div class="kpi ${totals.statusDifferences ? 'fail' : ''}"><b>${totals.statusDifferences}</b>status differences</div>
</div>
<div class="wrap"><table><tr><th>Page</th><th>Width</th><th>Full</th><th>Worst section</th><th>Pass</th><th>Warn</th><th>Fail</th><th>Missing</th><th>Missing words</th><th>Head diffs</th></tr>${rows}</table></div>
<h2>Status checks</h2>${statusTable}
<h2>Pages</h2>${details}
</body></html>`;
}
