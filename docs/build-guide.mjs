// build-guide.mjs — turn docs/guide/*.md into help/guide.json, which the Help screen reads.
//
//   node docs/build-guide.mjs
//
// The Markdown files are the source of truth and are NOT served (the web
// server denies docs/ and .md). This writes one JSON file the browser can
// fetch and cache with the rest of the app: each chapter's number, title,
// summary, its headings (for the contents list and for search) and its body.
//
// The subset the Help screen renders: front matter, headings, paragraphs,
// bold, italic, code, links, ordered and unordered lists, tables, fenced code
// blocks, and > blockquotes. Nothing else is used in the guide.

import { readdirSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const src = join(here, 'guide');
const out = join(here, '..', 'help');

const slugify = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

const files = readdirSync(src).filter((f) => /^\d\d-.*\.md$/.test(f)).sort();
if (!files.length) {
  console.error('No chapters found in ' + src);
  process.exit(1);
}

const chapters = files.map((file) => {
  const raw = readFileSync(join(src, file), 'utf8').replace(/\r\n?/g, '\n');
  const m = raw.match(/^---\n([\s\S]*?)\n---\n?/);
  const meta = {};
  if (m) {
    for (const line of m[1].split('\n')) {
      const i = line.indexOf(':');
      if (i > 0) meta[line.slice(0, i).trim()] = line.slice(i + 1).trim();
    }
  }
  const body = m ? raw.slice(m[0].length).trim() : raw.trim();
  const slug = file.replace(/\.md$/, '');

  const headings = [];
  let fenced = false;
  for (const line of body.split('\n')) {
    if (/^```/.test(line)) { fenced = !fenced; continue; }
    if (fenced) continue;
    const h = line.match(/^(#{2,4})\s+(.*)$/);
    if (h) headings.push({ level: h[1].length, text: h[2].replace(/[*`]/g, '').trim(), id: slugify(h[2]) });
  }

  return {
    slug,
    number: slug.slice(0, 2),
    title: meta.title || slug,
    summary: meta.summary || '',
    headings,
    body,
    words: body.split(/\s+/).length,
  };
});

mkdirSync(out, { recursive: true });
const json = {
  built: new Date().toISOString().slice(0, 10),
  chapters,
};
writeFileSync(join(out, 'guide.json'), JSON.stringify(json));

const words = chapters.reduce((n, c) => n + c.words, 0);
console.log(chapters.length + ' chapters, ' + words.toLocaleString('en') + ' words → help/guide.json ('
  + Math.round(JSON.stringify(json).length / 1024) + ' KB)');
for (const c of chapters) console.log('  ' + c.number + '  ' + c.title.padEnd(32) + c.headings.length + ' sections, ' + c.words + ' words');
