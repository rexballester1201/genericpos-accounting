/**
 * help.js — the user's guide, inside the app
 *
 * GenericPOS Accounting · ES module · everybody signed in
 *
 *   /help              the chapters
 *   /help/{chapter}    one chapter, with its own contents list
 *
 * The chapters are written as Markdown in docs/guide/ and built into
 * help/guide.json (node docs/build-guide.mjs). This renders the small subset
 * the guide uses — headings, paragraphs, bold, italic, code, links, lists,
 * tables, fenced code and blockquotes — and nothing else, so nothing in a
 * chapter can put markup of its own into the page.
 *
 * Links between chapters are written as 12-financial-reports.md#aging and
 * become links inside the app; links like /invoices open that screen.
 */

import { qs, esc, emptyState } from './ui.js';

let GUIDE = null;      // kept for the life of the tab: it is one file, read once

const slugify = (s) => s.toLowerCase().replace(/['‘’]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

/** An anchor's attributes for one of the three kinds of link a chapter uses. */
function linkAttrs(href) {
  const h = String(href).trim();
  if (/^https?:\/\//i.test(h)) return 'href="' + esc(h) + '" target="_blank" rel="noopener noreferrer"';
  const md = h.match(/^([0-9]{2}-[a-z0-9-]+)\.md(#.*)?$/);
  if (md) return 'href="' + esc('help/' + md[1] + (md[2] || '')) + '"';
  if (h.startsWith('#')) return 'href="' + esc(h) + '"';
  return 'href="' + esc(h.replace(/^\//, '')) + '"';
}

function inline(s) {
  return esc(s)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
    .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, text, href) => '<a ' + linkAttrs(href) + '>' + text + '</a>');
}

const cells = (row) => row.replace(/^\||\|$/g, '').split('|').map((c) => c.trim());

/** The guide's Markdown subset → HTML. */
function mdToHtml(md) {
  const lines = md.split('\n');
  const out = [];
  let i = 0;

  const paragraph = (buf) => { if (buf.length) out.push('<p>' + inline(buf.join(' ')) + '</p>'); };

  while (i < lines.length) {
    const line = lines[i];

    if (/^```/.test(line)) {                                   // fenced code
      const code = [];
      i++;
      while (i < lines.length && !/^```/.test(lines[i])) code.push(lines[i++]);
      i++;
      out.push('<pre><code>' + esc(code.join('\n')) + '</code></pre>');
      continue;
    }

    const h = line.match(/^(#{1,4})\s+(.*)$/);
    if (h) {                                                   // headings
      const level = h[1].length;
      const text = h[2].trim();
      out.push(level === 1
        ? '<h1>' + inline(text) + '</h1>'
        : '<h' + level + ' id="' + esc(slugify(text)) + '">' + inline(text) + '</h' + level + '>');
      i++;
      continue;
    }

    if (/^\s*\|.*\|\s*$/.test(line) && /^\s*\|[\s:-]+\|\s*$/.test(lines[i + 1] || '')) {   // table
      const head = cells(line.trim());
      i += 2;
      const body = [];
      while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) body.push(cells(lines[i++].trim()));
      out.push('<div class="table-wrap"><table class="table table-compact"><thead><tr>'
        + head.map((c) => '<th>' + inline(c) + '</th>').join('') + '</tr></thead><tbody>'
        + body.map((r) => '<tr>' + r.map((c) => '<td>' + inline(c) + '</td>').join('') + '</tr>').join('')
        + '</tbody></table></div>');
      continue;
    }

    if (/^>\s?/.test(line)) {                                  // blockquote (Tip, Note, Warning)
      const buf = [];
      while (i < lines.length && /^>\s?/.test(lines[i])) buf.push(lines[i++].replace(/^>\s?/, ''));
      const kind = /^\*\*(Tip|Note|Warning)/.exec(buf[0] || '');
      out.push('<blockquote class="' + (kind ? 'is-' + kind[1].toLowerCase() : '') + '">' + inline(buf.join(' ')) + '</blockquote>');
      continue;
    }

    const li = line.match(/^(\s*)([-*]|\d+\.)\s+(.*)$/);
    if (li) {                                                  // lists, one level of nesting
      const ordered = /\d/.test(li[2]);
      const tag = ordered ? 'ol' : 'ul';
      const items = [];
      let sub = null;
      while (i < lines.length) {
        const m2 = lines[i].match(/^(\s*)([-*]|\d+\.)\s+(.*)$/);
        if (!m2) {
          /* a plain line right under an item continues it */
          if (items.length && /^\s{3,}\S/.test(lines[i])) { items[items.length - 1] += ' ' + inline(lines[i].trim()); i++; continue; }
          break;
        }
        const deep = m2[1].length >= 2;
        if (deep) {
          sub = sub || [];
          sub.push(inline(m2[3]));
          i++;
          continue;
        }
        if (sub && items.length) { items[items.length - 1] += '<ul>' + sub.map((s) => '<li>' + s + '</li>').join('') + '</ul>'; sub = null; }
        items.push(inline(m2[3]));
        i++;
      }
      if (sub && items.length) items[items.length - 1] += '<ul>' + sub.map((s) => '<li>' + s + '</li>').join('') + '</ul>';
      out.push('<' + tag + '>' + items.map((s) => '<li>' + s + '</li>').join('') + '</' + tag + '>');
      continue;
    }

    if (line.trim() === '') { i++; continue; }

    const buf = [];                                            // a paragraph
    while (i < lines.length && lines[i].trim() !== '' && !/^(#{1,4}\s|>|\s*\|.*\|\s*$|```|\s*([-*]|\d+\.)\s)/.test(lines[i])) buf.push(lines[i++].trim());
    paragraph(buf);
  }
  return out.join('\n');
}

export async function mount(root, ctx) {
  const body = qs('[data-body]', root);
  const list = qs('[data-chapters]', root);
  const search = qs('[data-search]', root);
  const printBtn = qs('[data-print]', root);

  if (!GUIDE) {
    try {
      const res = await fetch(new URL('help/guide.json', document.baseURI).href, { signal: ctx.signal, headers: { Accept: 'application/json' } });
      if (!res.ok) throw new Error('The guide is not on the server (' + res.status + ').');
      GUIDE = await res.json();
    } catch (err) {
      if (ctx.signal.aborted) return;
      body.innerHTML = emptyState('warning', 'The guide could not be loaded', err.message
        + ' An administrator can build it again with: node docs/build-guide.mjs');
      return;
    }
  }

  const want = String(ctx.params.chapter || '').replace(/\.md$/, '');
  const chapter = want
    ? GUIDE.chapters.find((c) => c.slug === want || c.slug.slice(3) === want || c.number === want)
    : null;

  const navHtml = (q) => {
    const hit = (c) => !q || (c.title + ' ' + c.summary + ' ' + c.body).toLowerCase().includes(q);
    const items = GUIDE.chapters.filter(hit);
    if (!items.length) return '<p class="small muted" style="padding:var(--s3)">Nothing in the guide matches that.</p>';
    return items.map((c) => '<a class="help-link' + (chapter && c.slug === chapter.slug ? ' is-on' : '') + '" href="help/' + c.slug + '">'
      + '<span class="help-no">' + esc(c.number) + '</span><span class="grow">' + esc(c.title) + '</span></a>'
      + (q ? '<div class="help-hits">' + matches(c, q).map((m) => '<div>' + m + '</div>').join('') + '</div>' : '')).join('');
  };

  const matches = (c, q) => {
    const out = [];
    for (const line of c.body.split('\n')) {
      const at = line.toLowerCase().indexOf(q);
      if (at < 0 || /^[#>|`-]/.test(line.trim())) continue;
      const from = Math.max(0, at - 30);
      out.push((from ? '…' : '') + esc(line.slice(from, at)) + '<mark>' + esc(line.substr(at, q.length)) + '</mark>' + esc(line.slice(at + q.length, at + q.length + 50)) + '…');
      if (out.length === 2) break;
    }
    return out;
  };

  const index = () => {
    ctx.setTitle('Help');
    qs('[data-title]', root).textContent = 'Help';
    qs('[data-sub]', root).textContent = 'The user\'s guide: ' + GUIDE.chapters.length + ' chapters, step by step. Built ' + GUIDE.built + '.';
    printBtn.hidden = true;
    body.innerHTML = '<div class="help-cards">' + GUIDE.chapters.map((c) => '<a class="help-card" href="help/' + c.slug + '">'
      + '<div class="hc-no">' + esc(c.number) + '</div><div><b>' + esc(c.title) + '</b>'
      + '<div class="small muted">' + esc(c.summary) + '</div></div></a>').join('') + '</div>';
  };

  const show = (c) => {
    ctx.setTitle('Help · ' + c.title);
    qs('[data-title]', root).textContent = c.title;
    qs('[data-sub]', root).textContent = c.summary;
    printBtn.hidden = false;
    const i = GUIDE.chapters.indexOf(c);
    const prev = GUIDE.chapters[i - 1];
    const next = GUIDE.chapters[i + 1];
    const toc = c.headings.filter((h) => h.level === 2);
    body.innerHTML = (toc.length > 2 ? '<nav class="help-toc no-print"><b>In this chapter</b><ul>'
        + toc.map((h) => '<li><a href="#' + esc(h.id) + '">' + esc(h.text) + '</a></li>').join('') + '</ul></nav>' : '')
      + mdToHtml(c.body)
      + '<nav class="help-move no-print">'
      + (prev ? '<a class="btn btn-secondary btn-sm" href="help/' + prev.slug + '"><span data-icon="arrow-left" data-icon-size="16"></span>' + esc(prev.title) + '</a>' : '<span></span>')
      + (next ? '<a class="btn btn-secondary btn-sm" href="help/' + next.slug + '">' + esc(next.title) + '<span data-icon="arrow-right" data-icon-size="16"></span></a>' : '<span></span>')
      + '</nav>';
    if (location.hash.length > 1) {
      const target = body.querySelector('[id="' + CSS.escape(location.hash.slice(1)) + '"]');
      if (target) setTimeout(() => target.scrollIntoView({ block: 'start' }), 30);
    } else {
      window.scrollTo(0, 0);
    }
  };

  list.innerHTML = navHtml('');
  if (want && !chapter) {
    body.innerHTML = emptyState('question', 'There is no such chapter', 'Choose one from the list.', '<a class="btn btn-secondary" href="help">All chapters</a>');
    printBtn.hidden = true;
  } else if (chapter) {
    show(chapter);
  } else {
    index();
  }

  let t = null;
  search.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(() => { list.innerHTML = navHtml(search.value.trim().toLowerCase()); }, 120);
  });
  printBtn.addEventListener('click', () => window.print());
}
