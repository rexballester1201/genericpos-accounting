// build-docs.mjs — build the documentation set in docs/manual/ as standalone HTML.
//
//   node docs/build-docs.mjs
//
// Nine documents and a contents page, each a single self-contained file that
// opens from disk, prints on A4 and needs no server:
//
//   index.html                 the binder's contents
//   design-spec.html           DS·1   what it is and why it is shaped this way
//   technical-spec.html        TS·1   how it is built
//   users-guide.html           UG·1   the sixteen chapters, from docs/guide/*.md
//   admin-guide.html           AG·1   running it day to day
//   deployment-guide.html      DG·1   putting it on a server
//   troubleshooting-guide.html TG·1   when something is wrong
//   marketing.html             MK·1   what it is, for somebody choosing it
//   handover.html              HO·1   taking it over
//   audit.html                 AUD·1  the build audit, as issued
//
// Every document but the audit is written from a module in docs/manual-src/;
// the users' guide is rendered from docs/guide/*.md, the same source the Help
// screen reads, so the two can never drift. The audit is a working paper with
// its own letterhead and is copied in as it was issued.

import { readdirSync, readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, 'manual');
const src = join(here, 'manual-src');

const ISSUED = '20 September 2026';
const VERSION = '1.0';

// =============================================================================
// THE SET
// =============================================================================

export const SET = [
  { file: 'index.html', ref: '—', title: 'Contents', short: 'Contents', for: 'Everyone' },
  { file: 'design-spec.html', ref: 'DS·1', title: 'Design Specification', short: 'Design spec',
    for: 'Product owner, developer', sub: 'What the system is, who it is for, and why it is shaped the way it is.' },
  { file: 'technical-spec.html', ref: 'TS·1', title: 'Technical Specification', short: 'Technical spec',
    for: 'Developer', sub: 'The architecture, the data model, the API and the rules the code enforces.' },
  { file: 'users-guide.html', ref: 'UG·1', title: "User's Guide", short: "User's guide",
    for: 'Bookkeeper, accountant, owner', sub: 'Sixteen chapters, screen by screen, for the person keeping the books.' },
  { file: 'admin-guide.html', ref: 'AG·1', title: 'Administrator Guide', short: 'Admin guide',
    for: 'Administrator', sub: 'Users, settings, the scheduled job, backups and the audit trail.' },
  { file: 'deployment-guide.html', ref: 'DG·1', title: 'Deployment Guide', short: 'Deployment guide',
    for: 'Whoever installs it', sub: 'Requirements, installation, hardening, upgrades and rollback.' },
  { file: 'troubleshooting-guide.html', ref: 'TG·1', title: 'Troubleshooting Guide', short: 'Troubleshooting',
    for: 'Administrator, support', sub: 'Symptoms, what they usually mean, and what to do about them.' },
  { file: 'marketing.html', ref: 'MK·1', title: 'Product Overview', short: 'Product overview',
    for: 'Somebody choosing a system', sub: 'What it does, what it refuses to do, and who it suits.' },
  { file: 'handover.html', ref: 'HO·1', title: 'Handover Note', short: 'Handover',
    for: 'The next maintainer', sub: 'Where everything is, what runs unattended, and what is still open.' },
  { file: 'audit.html', ref: 'AUD·1', title: 'Build Audit', short: 'Build audit',
    for: 'Reviewer', sub: 'The working paper: 35 findings, all closed, and what was tested.' },
];

// =============================================================================
// THE HOUSE STYLE
// =============================================================================

const CSS = `
:root{
  --paper:#EDF0EC; --sheet:#F9FBF7; --ink:#141E19; --ink-2:#44534B; --ink-3:#66756D;
  --rule:#C8D3CC; --rule-2:#93A79A; --accent:#1B5940; --accent-soft:#DCE8E1;
  --gold:#8A5A12; --gold-soft:#F5EBD8; --code:#E2E9E4; --sel:#CFE3D7;
  --f-head:"Libre Franklin","Franklin Gothic Medium","Segoe UI",Arial,sans-serif;
  --f-body:"Source Serif 4","Source Serif Pro",Georgia,"Times New Roman",serif;
  --f-mono:"IBM Plex Mono",ui-monospace,Consolas,"Liberation Mono",monospace;
  color-scheme:light;
}
@media (prefers-color-scheme:dark){
  :root:not([data-theme="light"]){
    --paper:#0C120F; --sheet:#131A16; --ink:#DFE6E1; --ink-2:#A3B1A9; --ink-3:#81908A;
    --rule:#25312B; --rule-2:#44564D; --accent:#84CCA6; --accent-soft:#16261E;
    --gold:#E3AE61; --gold-soft:#2A2010; --code:#1A2420; --sel:#1E3A2B;
    color-scheme:dark;
  }
}
:root[data-theme="dark"]{
  --paper:#0C120F; --sheet:#131A16; --ink:#DFE6E1; --ink-2:#A3B1A9; --ink-3:#81908A;
  --rule:#25312B; --rule-2:#44564D; --accent:#84CCA6; --accent-soft:#16261E;
  --gold:#E3AE61; --gold-soft:#2A2010; --code:#1A2420; --sel:#1E3A2B;
  color-scheme:dark;
}

*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
body{margin:0;background:var(--paper);color:var(--ink);font-family:var(--f-body);
  font-size:17px;line-height:1.62;-webkit-font-smoothing:antialiased}
::selection{background:var(--sel);color:var(--ink)}
a{color:var(--accent);text-underline-offset:3px;text-decoration-thickness:1px}
a:focus-visible,button:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:2px}
code{font-family:var(--f-mono);font-size:.82em;background:var(--code);padding:.1em .36em;border-radius:3px;overflow-wrap:anywhere}
h1,h2,h3,h4{font-family:var(--f-head);color:var(--ink);text-wrap:balance;margin:0;letter-spacing:-.01em}
h1{font-weight:800;font-size:clamp(1.9rem,4vw,2.6rem);line-height:1.06;letter-spacing:-.018em}
h2{font-weight:700;font-size:1.42rem;line-height:1.22}
h3{font-weight:700;font-size:1.06rem;line-height:1.3}
h4{font-weight:600;font-size:.95rem;line-height:1.35}
p{margin:0}
.lbl,.eyebrow,dt,th{font-family:var(--f-head);font-weight:600;font-size:.7rem;letter-spacing:.09em;
  text-transform:uppercase;color:var(--ink-3)}

.skip{position:absolute;left:-9999px;top:0;background:var(--accent);color:var(--paper);
  padding:10px 16px;font-family:var(--f-head);font-weight:600;z-index:99}
.skip:focus{left:8px;top:8px}

/* ── the binder ─────────────────────────────────────────────────────────── */
.binder{display:grid;grid-template-columns:minmax(0,1fr);gap:0}
.rail{background:var(--sheet);border-bottom:1px solid var(--rule)}
.rail-in{padding:14px 20px;display:grid;gap:12px;max-width:1180px;margin-inline:auto}
.rail-brand{display:grid;gap:2px}
.rail-brand b{font-family:var(--f-head);font-weight:800;font-size:.95rem;letter-spacing:-.01em}
.rail-brand span{font-family:var(--f-head);font-size:.68rem;letter-spacing:.09em;text-transform:uppercase;color:var(--ink-3)}
.rail ol{list-style:none;margin:0;padding:0;display:flex;gap:6px;overflow-x:auto;
  -webkit-overflow-scrolling:touch;scrollbar-width:thin}
.rail a{display:flex;align-items:baseline;gap:8px;white-space:nowrap;text-decoration:none;
  font-family:var(--f-head);font-size:.86rem;font-weight:600;color:var(--ink-2);
  padding:6px 10px;border:1px solid var(--rule);border-radius:4px;background:var(--paper)}
.rail a:hover{color:var(--accent);border-color:var(--rule-2)}
.rail a[aria-current="page"]{color:var(--accent);border-color:var(--accent);background:var(--accent-soft)}
.rail .r{font-family:var(--f-mono);font-size:.72rem;font-weight:500;color:var(--ink-3)}
.rail a[aria-current="page"] .r{color:var(--accent)}

.doc{padding:34px 20px 90px;max-width:none;margin:0;display:grid;gap:40px}
.wrap{max-width:74ch;margin-inline:auto;width:100%;display:grid;gap:40px}

@media (min-width:1040px){
  .binder{grid-template-columns:270px minmax(0,1fr)}
  .rail{border-bottom:0;border-right:1px solid var(--rule);position:sticky;top:0;align-self:start;
    height:100vh;overflow-y:auto}
  .rail-in{padding:30px 22px;gap:22px}
  .rail ol{flex-direction:column;gap:3px;overflow:visible}
  .rail a{border:0;border-radius:3px;padding:7px 10px;background:transparent;white-space:normal}
  .rail a[aria-current="page"]{background:var(--accent-soft)}
  .doc{padding:48px 40px 110px}
}

/* ── the document's own head ────────────────────────────────────────────── */
.dochead{display:grid;gap:18px;border-bottom:1.5px solid var(--rule-2);padding-bottom:22px}
.dochead-top{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap}
.dochead h1{max-width:18ch}
.dochead .eyebrow{margin-bottom:8px;display:block}
.docref{border:1.5px solid var(--accent);color:var(--accent);border-radius:4px;padding:8px 14px;
  text-align:center;display:grid;gap:2px;flex:none}
.docref span{font-family:var(--f-head);font-size:.64rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase}
.docref strong{font-family:var(--f-mono);font-size:1.2rem;font-weight:600;letter-spacing:.03em}
.lead{font-size:1.14rem;line-height:1.55;color:var(--ink-2);max-width:62ch}
.control{margin:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px 26px;
  border-top:1px solid var(--rule);padding-top:16px}
.control div{display:grid;gap:3px;min-width:0}
.control dd{margin:0;font-family:var(--f-head);font-size:.9rem;line-height:1.4;color:var(--ink)}

/* ── contents ───────────────────────────────────────────────────────────── */
.toc{display:grid;gap:8px}
.toc ol{list-style:none;margin:0;padding:0;display:grid;gap:2px;
  grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
.toc a{display:flex;gap:10px;text-decoration:none;font-family:var(--f-head);font-size:.92rem;
  font-weight:600;color:var(--ink-2);padding:5px 0}
.toc a:hover{color:var(--accent)}
.toc .n{font-family:var(--f-mono);font-weight:500;color:var(--accent);font-size:.84rem;flex:none}

/* ── sections ───────────────────────────────────────────────────────────── */
.sec{display:grid;gap:16px;scroll-margin-top:20px}
.sec>h2{display:flex;gap:12px;align-items:baseline;border-bottom:1px solid var(--rule);padding-bottom:9px}
.sec>h2 .n{font-family:var(--f-mono);font-weight:600;font-size:.98rem;color:var(--accent);flex:none}
.sec h3{margin-top:10px}
.sec ul,.sec ol{margin:0;padding-left:1.25em;display:grid;gap:8px}
.sec li>ul,.sec li>ol{margin-top:8px}
.sec p+p{margin-top:0}
.note{background:var(--sheet);border-left:3px solid var(--accent);padding:14px 18px;display:grid;gap:8px}
.note.warn{border-left-color:var(--gold);background:var(--gold-soft)}
.note .lbl{color:var(--accent)}
.note.warn .lbl{color:var(--gold)}

.rule-card{background:var(--sheet);border:1px solid var(--rule-2);border-radius:5px;
  padding:18px 20px;display:grid;gap:10px}
.rule-card h3{color:var(--accent)}

dl.defs{margin:0;display:grid;gap:14px}
dl.defs>div{display:grid;gap:4px}
dl.defs dt{font-size:.74rem}
dl.defs dd{margin:0}

/* ── tables ─────────────────────────────────────────────────────────────── */
.tablewrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--rule);
  border-radius:5px;background:var(--sheet)}
table{border-collapse:collapse;width:100%;font-family:var(--f-head);font-size:.9rem}
th,td{padding:9px 14px;text-align:left;vertical-align:top;border-bottom:1px solid var(--rule)}
thead th{border-bottom:1.5px solid var(--rule-2);white-space:nowrap}
tbody tr:last-child td{border-bottom:0}
td code{font-size:.84em}
.num{text-align:right;font-family:var(--f-mono);font-variant-numeric:tabular-nums;white-space:nowrap}
td.k{font-weight:700;white-space:nowrap}

/* ── code ───────────────────────────────────────────────────────────────── */
pre{margin:0;background:var(--code);border:1px solid var(--rule);border-radius:5px;
  padding:14px 16px;overflow-x:auto;-webkit-overflow-scrolling:touch}
pre code{background:none;padding:0;font-size:.82rem;line-height:1.6}

blockquote{margin:0;border-left:3px solid var(--rule-2);padding-left:16px;color:var(--ink-2)}

/* ── the guide's chapters ───────────────────────────────────────────────── */
.chapter{display:grid;gap:16px;scroll-margin-top:20px;padding-top:12px}
.chapter>h2{display:flex;gap:12px;align-items:baseline;border-bottom:1.5px solid var(--rule-2);padding-bottom:10px}
.chapter>h2 .n{font-family:var(--f-mono);font-weight:600;color:var(--accent);flex:none}
.chapter .summary{color:var(--ink-2);font-size:1.02rem}

/* ── the foot ───────────────────────────────────────────────────────────── */
.docfoot{border-top:1px solid var(--rule);padding-top:16px;display:flex;flex-wrap:wrap;
  justify-content:space-between;gap:10px 20px;font-family:var(--f-head);font-size:.82rem;color:var(--ink-3)}
.docfoot a{font-weight:600;text-decoration:none}

/* ── the contents page ──────────────────────────────────────────────────── */
.cards{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr))}
.card{background:var(--sheet);border:1px solid var(--rule);border-radius:5px;padding:18px 20px;
  display:grid;gap:8px;align-content:start;text-decoration:none;color:inherit}
.card:hover{border-color:var(--rule-2)}
.card .r{font-family:var(--f-mono);font-size:.78rem;font-weight:600;color:var(--accent)}
.card h3{color:var(--ink)}
.card p{font-size:.95rem;color:var(--ink-2);line-height:1.5}
.card .who{font-family:var(--f-head);font-size:.74rem;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-3)}

@media print{
  :root:root:root{--paper:#fff;--sheet:#fff;--ink:#000;--ink-2:#333;--ink-3:#555;
    --rule:#bbb;--rule-2:#888;--accent:#1B5940;--accent-soft:#fff;--gold:#7a4f10;
    --gold-soft:#fff;--code:#eee;color-scheme:light}
  @page{size:A4;margin:16mm 15mm}
  body{font-size:10.5pt}
  .rail,.skip,.toc{display:none}
  .binder{display:block}
  .doc{padding:0;gap:26px}
  .wrap{max-width:none;gap:26px}
  .sec,.chapter,tr,.note,.rule-card,pre{break-inside:avoid}
  h2,h3{break-after:avoid}
  .tablewrap{overflow:visible}
  a{color:inherit;text-decoration:none}
  .docfoot{margin-top:18px}
}
`.trim();

// =============================================================================
// THE SHELL
// =============================================================================

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const rail = (current) => `<nav class="rail" aria-label="The documentation set">
  <div class="rail-in">
    <div class="rail-brand"><b>GenericPOS Accounting</b><span>Documentation set · v${VERSION}</span></div>
    <ol>
${SET.map((d) => `      <li><a href="${d.file}"${d.file === current ? ' aria-current="page"' : ''}><span class="r">${d.ref}</span>${esc(d.short)}</a></li>`).join('\n')}
    </ol>
  </div>
</nav>`;

/** The contents list a document builds from its own <h2 id> headings. */
const tocOf = (body) => {
  const items = [...body.matchAll(/<(?:section|article) class="(?:sec|chapter)" id="([^"]+)">\s*<h2[^>]*><span class="n">([^<]*)<\/span>([\s\S]*?)<\/h2>/g)]
    .map((m) => ({ id: m[1], n: m[2], text: m[3].replace(/<[^>]*>/g, '').trim() }));
  if (items.length < 3) return '';
  return `<nav class="toc" aria-label="Contents of this document">
      <p class="lbl">In this document</p>
      <ol>
${items.map((i) => `        <li><a href="#${i.id}"><span class="n">${i.n}</span>${i.text}</a></li>`).join('\n')}
      </ol>
    </nav>`;
};

const page = (d, body, extra = '') => {
  const prev = SET[SET.indexOf(d) - 1];
  const next = SET[SET.indexOf(d) + 1];
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>${esc(d.title)} · GenericPOS Accounting</title>
<meta name="description" content="${esc(d.sub || 'The documentation set for GenericPOS Accounting.')}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Libre+Franklin:wght@600;700;800&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;1,8..60,400&display=swap">
<style>
${CSS}
${extra}
</style>
</head>
<body>
<a class="skip" href="#doc">Skip to the document</a>
<div class="binder">
${rail(d.file)}
  <main class="doc" id="doc">
    <div class="wrap">
    <header class="dochead">
      <div class="dochead-top">
        <div>
          <span class="eyebrow">GenericPOS Accounting · Documentation set</span>
          <h1>${esc(d.title)}</h1>
        </div>
        <div class="docref" aria-label="Document reference ${esc(d.ref)}"><span>Doc ref</span><strong>${esc(d.ref)}</strong></div>
      </div>
      ${d.sub ? `<p class="lead">${esc(d.sub)}</p>` : ''}
      <dl class="control">
        <div><dt>Version</dt><dd>${VERSION}</dd></div>
        <div><dt>Issued</dt><dd>${ISSUED}</dd></div>
        <div><dt>Written for</dt><dd>${esc(d.for)}</dd></div>
        <div><dt>Applies to</dt><dd>Branch <code>build/accounting</code></dd></div>
      </dl>
    </header>
${tocOf(body)}
${body}
    <footer class="docfoot">
      <span>${esc(d.ref)} · ${esc(d.title)} · v${VERSION}, ${ISSUED}</span>
      <span>${prev ? `<a href="${prev.file}">← ${esc(prev.short)}</a>` : ''}${prev && next ? ' &nbsp;·&nbsp; ' : ''}${next ? `<a href="${next.file}">${esc(next.short)} →</a>` : ''}</span>
    </footer>
    </div>
  </main>
</div>
</body>
</html>
`;
};

// =============================================================================
// MARKDOWN — the same subset the Help screen renders
// =============================================================================

const slugify = (s) => s.toLowerCase().replace(/['‘’]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

/**
 * The guide's chapters were written to be read one at a time, so a link inside
 * one is a bare "#anchor" and a link to another names its file. Here all
 * sixteen share a page, so both become anchors carrying the chapter's slug.
 */
const inline = (s, prefix) => esc(s)
  .replace(/`([^`]+)`/g, (m, c) => '<code>' + c + '</code>')
  .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (m, t, h) => {
    let href = h;
    if (/^#/.test(h)) href = '#' + prefix + '-' + h.slice(1);
    else if (/^\d\d-.*\.md(#.*)?$/.test(h)) href = '#' + h.replace(/\.md/, '').replace('#', '-');
    return '<a href="' + esc(href) + '">' + t + '</a>';
  })
  .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
  .replace(/(^|[\s(])\*([^*]+)\*/g, '$1<em>$2</em>');

function markdown(body, prefix) {
  const lines = body.replace(/\r\n?/g, '\n').split('\n');
  const html = [];
  const used = new Set();
  /* Two sections of one chapter can carry the same heading — "What goes into
     the books" appears twice in several. On one page the id has to be unique. */
  const uniq = (id) => {
    let x = id, n = 1;
    while (used.has(x)) x = id + '-' + ++n;
    used.add(x);
    return x;
  };
  let i = 0;
  const flushList = (tag, items) => html.push('<' + tag + '>' + items.map((x) => '<li>' + inline(x, prefix) + '</li>').join('') + '</' + tag + '>');

  while (i < lines.length) {
    const line = lines[i];

    if (/^```/.test(line)) {
      const buf = [];
      i++;
      while (i < lines.length && !/^```/.test(lines[i])) buf.push(lines[i++]);
      i++;
      html.push('<pre><code>' + esc(buf.join('\n')) + '</code></pre>');
      continue;
    }
    const h = line.match(/^(#{2,4})\s+(.*)$/);
    if (h) {
      const lvl = h[1].length + 1 > 4 ? 4 : h[1].length + 1;
      html.push('<h' + lvl + ' id="' + uniq(prefix + '-' + slugify(h[2])) + '">' + inline(h[2], prefix) + '</h' + lvl + '>');
      i++;
      continue;
    }
    if (/^\s*\|/.test(line) && /^\s*\|[\s:|-]+\|\s*$/.test(lines[i + 1] || '')) {
      const cells = (r) => r.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
      const head = cells(line);
      i += 2;
      const rows = [];
      while (i < lines.length && /^\s*\|/.test(lines[i])) rows.push(cells(lines[i++]));
      html.push('<div class="tablewrap"><table><thead><tr>' + head.map((c) => '<th>' + inline(c, prefix) + '</th>').join('')
        + '</tr></thead><tbody>' + rows.map((r) => '<tr>' + r.map((c) => '<td>' + inline(c, prefix) + '</td>').join('') + '</tr>').join('')
        + '</tbody></table></div>');
      continue;
    }
    if (/^\s*[-*]\s+/.test(line)) {
      const items = [];
      while (i < lines.length && /^\s*[-*]\s+/.test(lines[i])) items.push(lines[i++].replace(/^\s*[-*]\s+/, ''));
      flushList('ul', items);
      continue;
    }
    if (/^\s*\d+\.\s+/.test(line)) {
      const items = [];
      while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i])) items.push(lines[i++].replace(/^\s*\d+\.\s+/, ''));
      flushList('ol', items);
      continue;
    }
    if (/^>\s?/.test(line)) {
      const buf = [];
      while (i < lines.length && /^>\s?/.test(lines[i])) buf.push(lines[i++].replace(/^>\s?/, ''));
      html.push('<blockquote><p>' + inline(buf.join(' '), prefix) + '</p></blockquote>');
      continue;
    }
    if (line.trim() === '') { i++; continue; }

    const buf = [];
    while (i < lines.length && lines[i].trim() !== '' && !/^(#{2,4}\s|```|>\s?|\s*[-*]\s|\s*\d+\.\s|\s*\|)/.test(lines[i])) buf.push(lines[i++]);
    html.push('<p>' + inline(buf.join(' '), prefix) + '</p>');
  }
  return html.join('\n');
}

// =============================================================================
// BUILD
// =============================================================================

mkdirSync(out, { recursive: true });
const written = [];
const write = (file, html) => {
  writeFileSync(join(out, file), html);
  written.push([file, html.length]);
};

// ── the seven written documents ──
for (const d of SET) {
  if (['index.html', 'users-guide.html', 'audit.html'].includes(d.file)) continue;
  const mod = await import('file://' + join(src, d.file.replace('.html', '.mjs')).replace(/\\/g, '/'));
  write(d.file, page(d, mod.body.trim()));
}

// ── the users' guide, from the chapters the Help screen reads ──
{
  const gsrc = join(here, 'guide');
  const files = readdirSync(gsrc).filter((f) => /^\d\d-.*\.md$/.test(f)).sort();
  let words = 0;
  const chapters = files.map((file) => {
    const raw = readFileSync(join(gsrc, file), 'utf8').replace(/\r\n?/g, '\n');
    const m = raw.match(/^---\n([\s\S]*?)\n---\n?/);
    const meta = {};
    if (m) for (const l of m[1].split('\n')) { const j = l.indexOf(':'); if (j > 0) meta[l.slice(0, j).trim()] = l.slice(j + 1).trim(); }
    const text = (m ? raw.slice(m[0].length) : raw).trim();
    words += text.split(/\s+/).length;
    const slug = file.replace(/\.md$/, '');
    return { slug, no: slug.slice(0, 2), title: meta.title || slug, summary: meta.summary || '', text };
  });

  const body = chapters.map((c) => `    <article class="chapter" id="${c.slug}">
      <h2><span class="n">${c.no}</span>${esc(c.title)}</h2>
      ${c.summary ? `<p class="summary">${esc(c.summary)}</p>` : ''}
${markdown(c.text, c.slug)}
    </article>`).join('\n');

  const d = SET.find((x) => x.file === 'users-guide.html');
  write('users-guide.html', page(d, body));
  console.log('  users-guide.html: ' + chapters.length + ' chapters, ' + words.toLocaleString('en') + ' words');
}

// ── the audit, as issued, with a way back to the binder ──
{
  const from = join(here, 'manual-src', 'audit-source.html');
  if (existsSync(from)) {
    const a = readFileSync(from, 'utf8');
    const at = a.indexOf('<main class="wp">');
    if (at < 0) throw new Error('audit-source.html: no <main class="wp"> to split on');
    const bar = `<nav style="font:600 13px/1.5 'Libre Franklin',Arial,sans-serif;padding:11px 20px;`
      + `border-bottom:1px solid var(--rule);background:var(--sheet);display:flex;gap:14px;flex-wrap:wrap">`
      + `<a href="index.html" style="color:var(--green);text-decoration:none">&#8592; GenericPOS Accounting &middot; documentation set</a>`
      + `<span style="color:var(--ink-3);font-weight:400">AUD&middot;1 is a working paper and keeps its own letterhead.</span></nav>`;
    write('audit.html', '<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n'
      + '<meta name="viewport" content="width=device-width, initial-scale=1">\n'
      + a.slice(0, at) + '</head>\n<body>\n' + bar + '\n' + a.slice(at) + '\n</body>\n</html>\n');
  } else {
    console.log('  audit.html: SKIPPED — docs/manual-src/audit-source.html is missing');
  }
}

// ── the contents page ──
{
  const d = SET[0];
  const cards = SET.slice(1).map((x) => `      <a class="card" href="${x.file}">
        <span class="r">${x.ref}</span>
        <h3>${esc(x.title)}</h3>
        <p>${esc(x.sub)}</p>
        <span class="who">${esc(x.for)}</span>
      </a>`).join('\n');

  const body = `    <section class="sec" id="the-set">
      <h2><span class="n">1</span>The set</h2>
      <p>Nine documents. Each opens on its own, prints on A4, and links back here. Together they cover the
      product, the code, the people who use it, the people who run it, and the review it has been through.</p>
      <div class="cards">
${cards}
      </div>
    </section>

    <section class="sec" id="where-to-start">
      <h2><span class="n">2</span>Where to start</h2>
      <div class="tablewrap"><table>
        <thead><tr><th>If you are…</th><th>Read</th></tr></thead>
        <tbody>
          <tr><td class="k">keeping the books</td><td><a href="users-guide.html">UG·1 User's Guide</a> — start at chapter 01 and work forward. It is also the Help screen inside the app.</td></tr>
          <tr><td class="k">installing it</td><td><a href="deployment-guide.html">DG·1 Deployment Guide</a>, then <a href="admin-guide.html">AG·1</a> for the settings and the scheduled job.</td></tr>
          <tr><td class="k">running it</td><td><a href="admin-guide.html">AG·1 Administrator Guide</a>, with <a href="troubleshooting-guide.html">TG·1</a> beside it.</td></tr>
          <tr><td class="k">taking over the code</td><td><a href="handover.html">HO·1 Handover Note</a> first, then <a href="technical-spec.html">TS·1</a> and <a href="design-spec.html">DS·1</a>.</td></tr>
          <tr><td class="k">deciding whether to use it</td><td><a href="marketing.html">MK·1 Product Overview</a>, then <a href="audit.html">AUD·1</a> for what an independent pass found.</td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="sec" id="keeping-it-true">
      <h2><span class="n">3</span>Keeping it true</h2>
      <p>These files are built, not hand-edited. The prose for seven of them lives in
      <code>docs/manual-src/*.mjs</code>; the User's Guide is rendered from <code>docs/guide/*.md</code>, the same
      sixteen chapters the Help screen reads, so the printed guide and the in-app guide can never disagree; the
      audit is the working paper as issued.</p>
      <pre><code>node docs/build-docs.mjs     # docs/manual-src + docs/guide → docs/manual/*.html
node docs/build-guide.mjs    # docs/guide/*.md → help/guide.json (the Help screen)</code></pre>
      <div class="note"><p class="lbl">Version</p><p>Version ${VERSION}, issued ${ISSUED}, against branch
      <code>build/accounting</code>. Change the code and these do not follow by themselves — rebuild them, and
      raise the version in <code>docs/build-docs.mjs</code> when what they say changes.</p></div>
      <div class="note warn"><p class="lbl">Not on the running site</p><p><code>docs/</code> is refused by
      <code>.htaccess</code>, which is what keeps the source and the schema off the web. Read this set from a
      checkout of the repository, not from the installed system. The user's guide <em>is</em> in the app —
      Help, on the menu — because it is published there as data rather than as files.</p></div>
    </section>`;
  write('index.html', page(d, body));
}

console.log('\n' + written.length + ' files → docs/manual/');
for (const [f, n] of written) console.log('  ' + f.padEnd(28) + (n / 1024).toFixed(1).padStart(7) + ' KB');
