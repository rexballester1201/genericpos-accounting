// modcheck.mjs — static checks for the accounting SPA's ES modules and pages.
//
//   node modcheck.mjs [projectRoot] [--strict]
//
//   1. every js/*.js parses (node --check)
//   2. every named import from a relative module is exported there, and every
//      default import has a default export
//   3. every pages/*.html data-module names a js/<module>.js that exports mount()
//   4. every page in js/router.js has pages/<page>.html (missing ones are listed;
//      with --strict they fail)
//   5. every literal data-icon="slug" (and the sidebar NAV icons) exists in js/icons.js
//
// Exit code 1 when anything fails. Read-only: it never writes into the project.
import { readFileSync, readdirSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { join, dirname, resolve, basename } from 'node:path';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';

const args = process.argv.slice(2);
const strict = args.includes('--strict');
const root = resolve(args.find((a) => !a.startsWith('--')) || 'D:/xampp/htdocs/dashboard/accounting');
const jsDir = join(root, 'js');
const pagesDir = join(root, 'pages');

let failures = 0;
const fail = (m) => { failures++; console.log('FAIL  ' + m); };
const info = (m) => console.log('info  ' + m);

const strip = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
const jsFiles = readdirSync(jsDir).filter((f) => f.endsWith('.js')).sort();
const source = new Map(jsFiles.map((f) => [f, strip(readFileSync(join(jsDir, f), 'utf8'))]));

// ── 1. syntax ───────────────────────────────────────────────────────────────
const tmp = join(tmpdir(), 'acc-modcheck');
mkdirSync(tmp, { recursive: true });
for (const f of jsFiles) {
  const file = join(jsDir, f);
  let r = spawnSync(process.execPath, ['--check', file], { encoding: 'utf8' });
  if (r.status !== 0 && /outside a module|Unexpected token 'export'/.test(r.stderr || '')) {
    const copy = join(tmp, basename(f, '.js') + '.mjs');
    writeFileSync(copy, readFileSync(file));
    r = spawnSync(process.execPath, ['--check', copy], { encoding: 'utf8' });
  }
  if (r.status !== 0) fail(f + ' does not parse:\n' + String(r.stderr || '').split('\n').slice(0, 6).join('\n'));
}

// ── 2. imports against exports ──────────────────────────────────────────────
function exportNames(src) {
  const names = new Set();
  for (const m of src.matchAll(/export\s+(?:async\s+)?function\s*\*?\s*([A-Za-z_$][\w$]*)/g)) names.add(m[1]);
  for (const m of src.matchAll(/export\s+(?:const|let|var|class)\s+([A-Za-z_$][\w$]*)/g)) names.add(m[1]);
  for (const m of src.matchAll(/export\s*\{([^}]*)\}/g)) {
    for (const part of m[1].split(',')) {
      const p = part.trim();
      if (!p) continue;
      const bits = p.split(/\s+as\s+/);
      names.add((bits[1] || bits[0]).trim());
    }
  }
  if (/export\s+default\b/.test(src)) names.add('default');
  return names;
}
const exportsOf = new Map([...source].map(([f, s]) => [f, exportNames(s)]));

let importsChecked = 0;
for (const [f, src] of source) {
  for (const m of src.matchAll(/import\s+([\w$*{}\s,]+?)\s+from\s+['"](\.{1,2}\/[^'"]+)['"]/g)) {
    const clause = m[1];
    const spec = m[2];
    const target = resolve(jsDir, spec);
    if (!existsSync(target)) { fail(f + ' imports ' + spec + ', which does not exist'); continue; }
    if (dirname(target) !== jsDir) continue;
    const exp = exportsOf.get(basename(target)) || new Set();
    const named = clause.match(/\{([\s\S]*)\}/);
    if (named) {
      for (const part of named[1].split(',')) {
        const p = part.trim();
        if (!p) continue;
        const orig = p.split(/\s+as\s+/)[0].trim();
        importsChecked++;
        if (!exp.has(orig)) fail(f + ' imports { ' + orig + ' } from ' + spec + ', which does not export it');
      }
    }
    const rest = clause.replace(/\{[\s\S]*\}/, '').replace(/\*\s*as\s+[\w$]+/, '').replace(/,/g, '').trim();
    if (rest) {
      importsChecked++;
      if (!exp.has('default')) fail(f + ' imports a default from ' + spec + ', which has no default export');
    }
  }
}

// ── 3. pages name a module that mounts ──────────────────────────────────────
const pageFiles = existsSync(pagesDir) ? readdirSync(pagesDir).filter((f) => f.endsWith('.html')).sort() : [];
for (const p of pageFiles) {
  const html = readFileSync(join(pagesDir, p), 'utf8');
  const m = html.match(/data-module="([^"]+)"/);
  if (!m) continue;
  const mod = m[1] + '.js';
  if (!source.has(mod)) { fail('pages/' + p + ' names module ' + m[1] + ', but js/' + mod + ' does not exist'); continue; }
  if (!exportsOf.get(mod).has('mount')) fail('js/' + mod + ' (for pages/' + p + ') does not export mount()');
}

// ── 4. router pages exist ───────────────────────────────────────────────────
const router = source.get('router.js') || '';
const routed = [...router.matchAll(/r\(\s*'([^']+)'\s*,\s*'([^']+)'/g)].map((m) => ({ path: m[1], page: m[2] }));
const missing = routed.filter((r) => !existsSync(join(pagesDir, r.page + '.html')));
const missingPages = [...new Set(missing.map((r) => r.page))];
if (missingPages.length) {
  const msg = 'routes without a page yet: ' + missingPages.map((p) => p + ' (' + missing.filter((r) => r.page === p).map((r) => r.path).join(', ') + ')').join('; ');
  if (strict) fail(msg); else info(msg);
}

// ── 5. icons ────────────────────────────────────────────────────────────────
const iconSrc = readFileSync(join(jsDir, 'icons.js'), 'utf8');
const icons = new Set([...iconSrc.matchAll(/^\s*'([a-z0-9-]+)':/gm)].map((m) => m[1]));
const used = [];
for (const [f, src] of source) for (const m of src.matchAll(/data-icon="([a-z0-9-]+)"/g)) used.push([f, m[1]]);
for (const p of pageFiles) for (const m of readFileSync(join(pagesDir, p), 'utf8').matchAll(/data-icon="([a-z0-9-]+)"/g)) used.push(['pages/' + p, m[1]]);
for (const m of (source.get('chrome.js') || '').matchAll(/\[\s*'[\w-]+'\s*,\s*'[^']*'\s*,\s*'([a-z0-9-]+)'\s*,\s*'/g)) used.push(['chrome.js NAV', m[1]]);
for (const [f, slug] of used) if (!icons.has(slug)) fail(f + ' uses icon "' + slug + '", which js/icons.js does not have');

console.log('\n' + jsFiles.length + ' modules, ' + importsChecked + ' imports, ' + pageFiles.length + ' pages, '
  + routed.length + ' routes, ' + used.length + ' icon uses checked — ' + (failures ? failures + ' FAILED' : 'all good'));
process.exit(failures ? 1 : 0);
