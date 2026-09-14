/**
 * make-icons.mjs — the app's icons and splash screens, drawn in the store's colour.
 *
 * GenericPOS · run from capacitor/:
 *   node scripts/make-icons.mjs                     (the default teal, #0f766e)
 *   node scripts/make-icons.mjs --color "#b91c1c"
 *
 * Draws a plain white shopping bag on the colour and writes:
 *   ../icons/                favicon, icon-192, icon-512, icon-maskable-512, badge-72 (the web app)
 *   android/…/res/mipmap-*   launcher icons at every density (adaptive foreground, round, legacy)
 *   android/…/res/drawable*  splash screens, each at the size it already has
 *   android/…/res/values/ic_launcher_background.xml   the adaptive icon's background colour
 *
 * A store with its own artwork replaces these files instead: the web icons keep
 * their names and sizes, and Android Studio's Image Asset tool writes the rest.
 *
 * No dependencies: shapes are sampled 4×4 per pixel and written as PNG with
 * node:zlib, so it runs on a fresh clone without npm install.
 */

import zlib from 'node:zlib';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');   // capacitor/
const WEB  = path.resolve(ROOT, '..', 'icons');
const RES  = path.join(ROOT, 'android', 'app', 'src', 'main', 'res');

const at  = process.argv.indexOf('--color');
const HEX = at > 0 ? String(process.argv[at + 1] || '') : '#0f766e';
if (!/^#[0-9a-fA-F]{6}$/.test(HEX)) {
  console.error('--color takes a #rrggbb colour, e.g. --color "#0f766e"');
  process.exit(1);
}
const BRAND = [1, 3, 5].map((i) => parseInt(HEX.slice(i, i + 2), 16));
const WHITE = [255, 255, 255];

// ─── PNG ────────────────────────────────────────────────────────────────────

const CRC = new Uint32Array(256).map((_, n) => {
  let c = n;
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  return c >>> 0;
});

function crc32(buf) {
  let c = 0xffffffff;
  for (const b of buf) c = CRC[(c ^ b) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}

function encode(w, h, rgba) {
  const stride = w * 4 + 1;
  const raw = Buffer.alloc(stride * h);                // each row starts with filter 0 (none)
  for (let y = 0; y < h; y++) rgba.copy(raw, y * stride + 1, y * w * 4, (y + 1) * w * 4);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8;                                         // 8 bits per channel
  ihdr[9] = 6;                                         // RGBA
  return Buffer.concat([
    Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
    chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

// ─── Drawing ────────────────────────────────────────────────────────────────

function inRound(x, y, x0, y0, x1, y1, r) {
  if (x < x0 || x > x1 || y < y0 || y > y1) return false;
  const cx = Math.min(Math.max(x, x0 + r), x1 - r);
  const cy = Math.min(Math.max(y, y0 + r), y1 - r);
  return (x - cx) ** 2 + (y - cy) ** 2 <= r * r;
}

/** The bag in a unit square: a rounded body with a handle arc above it. */
function bag(x, y) {
  if (inRound(x, y, 0.27, 0.37, 0.73, 0.79, 0.055)) return true;
  const d = Math.hypot(x - 0.5, y - 0.37);
  return y <= 0.375 && d <= 0.135 && d >= 0.085;
}

/**
 * RGBA pixels of one square image.
 *   any       the bag on a rounded square       round  …on a circle
 *   maskable  full bleed, the bag inside the safe zone
 *   fg        Android adaptive foreground: the bag alone, safe-zone sized
 *   badge     the bag alone, large (a notification badge is shown as a silhouette)
 */
function pixels(size, kind) {
  const S = 4;
  const out = Buffer.alloc(size * size * 4);
  const scale = kind === 'maskable' || kind === 'fg' ? 0.78 : kind === 'badge' ? 1.18 : 1;
  const lift = kind === 'badge' ? 0.04 : 0;
  for (let py = 0; py < size; py++) {
    for (let px = 0; px < size; px++) {
      let r = 0, g = 0, b = 0, a = 0;
      for (let sy = 0; sy < S; sy++) {
        for (let sx = 0; sx < S; sx++) {
          const u = (px + (sx + 0.5) / S) / size;
          const v = (py + (sy + 0.5) / S) / size;
          let col = null;
          if (kind === 'maskable') col = BRAND;
          else if (kind === 'round') col = (u - 0.5) ** 2 + (v - 0.5) ** 2 <= 0.25 ? BRAND : null;
          else if (kind === 'any') col = inRound(u, v, 0, 0, 1, 1, 0.22) ? BRAND : null;
          if (bag(0.5 + (u - 0.5) / scale, 0.5 + (v - 0.5) / scale + lift)) col = WHITE;
          if (col) { r += col[0]; g += col[1]; b += col[2]; a += 1; }
        }
      }
      const i = (py * size + px) * 4;
      out[i]     = a ? Math.round(r / a) : 0;
      out[i + 1] = a ? Math.round(g / a) : 0;
      out[i + 2] = a ? Math.round(b / a) : 0;
      out[i + 3] = Math.round((a / (S * S)) * 255);
    }
  }
  return out;
}

/** A white splash screen with the icon in the middle. */
function splash(w, h) {
  const out = Buffer.alloc(w * h * 4, 255);
  const s = Math.max(48, Math.round(Math.min(w, h) * 0.3));
  const icon = pixels(s, 'any');
  const ox = (w - s) >> 1;
  const oy = (h - s) >> 1;
  for (let y = 0; y < s; y++) {
    for (let x = 0; x < s; x++) {
      const i = (y * s + x) * 4;
      const a = icon[i + 3] / 255;
      const j = ((oy + y) * w + ox + x) * 4;
      for (let c = 0; c < 3; c++) out[j + c] = Math.round(icon[i + c] * a + 255 * (1 - a));
    }
  }
  return encode(w, h, out);
}

async function exists(p) {
  try { await fs.access(p); return true; } catch { return false; }
}

async function main() {
  let n = 0;

  const web = [['favicon.png', 64, 'any'], ['icon-192.png', 192, 'any'], ['icon-512.png', 512, 'any'],
               ['icon-maskable-512.png', 512, 'maskable'], ['badge-72.png', 72, 'badge']];
  for (const [name, px, kind] of web) {
    await fs.writeFile(path.join(WEB, name), encode(px, px, pixels(px, kind)));
    n++;
  }

  /* Launcher icons. Adaptive foreground layers are 108dp, of which a 72dp
     window shows and the middle 66dp is safe from every mask shape. */
  const dens = { mdpi: 48, hdpi: 72, xhdpi: 96, xxhdpi: 144, xxxhdpi: 192 };
  for (const [d, px] of Object.entries(dens)) {
    const dir = path.join(RES, 'mipmap-' + d);
    if (!(await exists(dir))) continue;
    for (const f of await fs.readdir(dir)) {
      if (!/^ic_launcher.*\.png$/.test(f)) continue;
      const fg = f.includes('foreground');
      const size = fg ? Math.round((px * 108) / 48) : px;
      await fs.writeFile(path.join(dir, f), encode(size, size, pixels(size, fg ? 'fg' : f.includes('round') ? 'round' : 'any')));
      n++;
    }
  }

  /* Splash screens: redrawn at whatever size each one already is. */
  for (const d of await fs.readdir(RES)) {
    if (!d.startsWith('drawable')) continue;
    const file = path.join(RES, d, 'splash.png');
    if (!(await exists(file))) continue;
    const head = await fs.readFile(file);
    await fs.writeFile(file, splash(head.readUInt32BE(16), head.readUInt32BE(20)));
    n++;
  }

  await fs.writeFile(path.join(RES, 'values', 'ic_launcher_background.xml'),
    '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n    <color name="ic_launcher_background">'
    + HEX.toUpperCase() + '</color>\n</resources>\n');

  console.log(`${n} images drawn in ${HEX}; the adaptive icon background is ${HEX.toUpperCase()}.`);
}

main().catch((err) => {
  console.error(err.message);
  process.exit(1);
});
