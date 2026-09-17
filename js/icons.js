/**
 * icons.js — the icon set, and the painter that fills every [data-icon] slot
 *
 * GenericPOS · ES module
 *
 *   <span data-icon="shopping-bag"></span>
 *   <span data-icon="x" data-icon-size="16"></span>        size override (px)
 *
 *   iconSvg(slug, size?)     a complete <svg> string ('' for an unknown slug)
 *   startAutoPaint(root?)    paint now and keep painting whatever is added later
 *
 * Artwork: most shapes follow Phosphor Icons (https://phosphoricons.com, MIT,
 * © Phosphor Icons); the rest are drawn on the same 256 grid with the same
 * round caps and joins so the families sit together.
 *
 * ─── ONE STROKE WEIGHT, SET ONCE ──────────────────────────────────────────
 * Every glyph is bare geometry. Stroke colour, width, caps and joins are
 * applied by ONE wrapping <g>, so the weight of the whole app is the STROKE
 * constant below — never an attribute repeated on a hundred paths. Solid dots
 * (the wallet clasp, the "i" of info) opt out with their own fill.
 *
 * ─── WHY AN OBSERVER ──────────────────────────────────────────────────────
 * Every screen rebuilds lists with innerHTML. "Remember to repaint after each
 * render" fails silently and partially — the one empty state nobody clicked
 * shows blank squares. So the painter watches the tree: markup declares WHICH
 * icon, and anything added later, or any slot whose data-icon changes (a
 * show/hide password eye), is painted as it appears.
 *
 * NOTE: display:inline-block on the <svg>, never block — many slots sit
 * inside a run of text, and a block child in an inline span breaks the line.
 */

export const VIEWBOX = '0 0 256 256';
const STROKE = 20;
const DEFAULT_SIZE = 20;

const dot = (cx, cy, r = 12) => `<circle cx="${cx}" cy="${cy}" r="${r}" fill="currentColor" stroke="none"/>`;

const ICONS = {
  // ── arrows & carets ───────────────────────────────────────────────────────
  'arrow-left': '<line x1="216" y1="128" x2="40" y2="128"/><polyline points="112 56 40 128 112 200"/>',
  'arrow-right': '<line x1="40" y1="128" x2="216" y2="128"/><polyline points="144 56 216 128 144 200"/>',
  'arrow-up': '<line x1="128" y1="216" x2="128" y2="40"/><polyline points="56 112 128 40 200 112"/>',
  'arrow-down': '<line x1="128" y1="40" x2="128" y2="216"/><polyline points="56 144 128 216 200 144"/>',
  'arrow-line-down': '<line x1="128" y1="32" x2="128" y2="184"/><polyline points="56 112 128 184 200 112"/><line x1="40" y1="224" x2="216" y2="224"/>',
  'arrow-line-up': '<line x1="128" y1="224" x2="128" y2="72"/><polyline points="56 144 128 72 200 144"/><line x1="40" y1="32" x2="216" y2="32"/>',
  'arrow-square-out': '<polyline points="216 104 216 40 152 40"/><line x1="144" y1="112" x2="216" y2="40"/><path d="M184,144v64a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V80a8,8,0,0,1,8-8h64"/>',
  'arrow-bend-up-left': '<polyline points="80 152 32 104 80 56"/><path d="M224,200a96,96,0,0,0-96-96H32"/>',
  'arrow-counter-clockwise': '<polyline points="80 104 32 104 32 56"/><path d="M65.8,190.2a88,88,0,1,0,0-124.4L32,104"/>',
  'arrows-clockwise': '<polyline points="176 104 224 104 224 56"/><polyline points="80 152 32 152 32 200"/><path d="M190.2,190.2a88,88,0,0,1-124.4,0L32,152"/><path d="M65.8,65.8a88,88,0,0,1,124.4,0L224,104"/>',
  'repeat': '<polyline points="200 88 224 64 200 40"/><path d="M32,128A64,64,0,0,1,96,64H224"/><polyline points="56 168 32 192 56 216"/><path d="M224,128a64,64,0,0,1-64,64H32"/>',
  'caret-right': '<polyline points="96 48 176 128 96 208"/>',
  'caret-left': '<polyline points="160 208 80 128 160 48"/>',
  'caret-down': '<polyline points="208 96 128 176 48 96"/>',
  'caret-up': '<polyline points="48 160 128 80 208 160"/>',
  'sign-in': '<polyline points="94 170 136 128 94 86"/><line x1="24" y1="128" x2="136" y2="128"/><path d="M136,40h56a8,8,0,0,1,8,8V208a8,8,0,0,1-8,8H136"/>',
  'sign-out': '<polyline points="112 40 48 40 48 216 112 216"/><line x1="112" y1="128" x2="224" y2="128"/><polyline points="184 88 224 128 184 168"/>',
  'download-simple': '<line x1="128" y1="144" x2="128" y2="32"/><polyline points="216 144 216 208 40 208 40 144"/><polyline points="168 104 128 144 88 104"/>',
  'upload-simple': '<line x1="128" y1="144" x2="128" y2="32"/><polyline points="216 144 216 208 40 208 40 144"/><polyline points="88 72 128 32 168 72"/>',

  // ── actions ───────────────────────────────────────────────────────────────
  'x': '<line x1="200" y1="56" x2="56" y2="200"/><line x1="200" y1="200" x2="56" y2="56"/>',
  'check': '<polyline points="40 144 96 200 224 72"/>',
  'plus': '<line x1="40" y1="128" x2="216" y2="128"/><line x1="128" y1="40" x2="128" y2="216"/>',
  'minus': '<line x1="40" y1="128" x2="216" y2="128"/>',
  'plus-circle': '<circle cx="128" cy="128" r="96"/><line x1="88" y1="128" x2="168" y2="128"/><line x1="128" y1="88" x2="128" y2="168"/>',
  'minus-circle': '<circle cx="128" cy="128" r="96"/><line x1="88" y1="128" x2="168" y2="128"/>',
  'check-circle': '<polyline points="88 136 112 160 168 104"/><circle cx="128" cy="128" r="96"/>',
  'x-circle': '<circle cx="128" cy="128" r="96"/><line x1="160" y1="96" x2="96" y2="160"/><line x1="160" y1="160" x2="96" y2="96"/>',
  'prohibit': '<line x1="195.88" y1="195.88" x2="60.12" y2="60.12"/><circle cx="128" cy="128" r="96"/>',
  'pencil-simple': '<path d="M92.69,216H48a8,8,0,0,1-8-8V163.31a8,8,0,0,1,2.34-5.65L165.66,34.34a8,8,0,0,1,11.31,0L221.66,79a8,8,0,0,1,0,11.31L98.34,213.66A8,8,0,0,1,92.69,216Z"/><line x1="136" y1="64" x2="192" y2="120"/>',
  'trash': '<line x1="216" y1="60" x2="40" y2="60"/><line x1="104" y1="104" x2="104" y2="168"/><line x1="152" y1="104" x2="152" y2="168"/><path d="M200,60V208a8,8,0,0,1-8,8H64a8,8,0,0,1-8-8V60"/><path d="M168,60V36a16,16,0,0,0-16-16H104A16,16,0,0,0,88,36V60"/>',
  'copy': '<polyline points="168 168 216 168 216 40 88 40 88 88"/><rect x="40" y="88" width="128" height="128" rx="8"/>',
  'link': '<path d="M122.34,71.43l19.8-19.8a44,44,0,0,1,62.23,62.23l-28.29,28.28a44,44,0,0,1-62.22,0"/><path d="M133.66,184.57l-19.8,19.8a44,44,0,0,1-62.23-62.23l28.29-28.28a44,44,0,0,1,62.22,0"/>',
  'magnifying-glass': '<circle cx="112" cy="112" r="80"/><line x1="168.57" y1="168.57" x2="224" y2="224"/>',
  'funnel': '<path d="M40,48H216a8,8,0,0,1,5.92,13.38l-67.84,74.62a8,8,0,0,0-2.08,5.38V198.1a8,8,0,0,1-3.56,6.66l-32,21.33A8,8,0,0,1,104,219.43V141.38a8,8,0,0,0-2.08-5.38L34.08,61.38A8,8,0,0,1,40,48Z"/>',
  'sliders': '<line x1="40" y1="80" x2="100" y2="80"/><line x1="156" y1="80" x2="216" y2="80"/><circle cx="128" cy="80" r="22"/><line x1="40" y1="176" x2="148" y2="176"/><line x1="204" y1="176" x2="216" y2="176"/><circle cx="176" cy="176" r="22"/>',
  'dots-three': dot(60, 128, 16) + dot(128, 128, 16) + dot(196, 128, 16),
  'dots-six-vertical': dot(96, 64, 14) + dot(96, 128, 14) + dot(96, 192, 14) + dot(160, 64, 14) + dot(160, 128, 14) + dot(160, 192, 14),
  'list': '<line x1="40" y1="128" x2="216" y2="128"/><line x1="40" y1="64" x2="216" y2="64"/><line x1="40" y1="192" x2="216" y2="192"/>',
  'squares-four': '<rect x="48" y="48" width="64" height="64" rx="8"/><rect x="144" y="48" width="64" height="64" rx="8"/><rect x="48" y="144" width="64" height="64" rx="8"/><rect x="144" y="144" width="64" height="64" rx="8"/>',
  'rows': '<rect x="40" y="48" width="176" height="64" rx="8"/><rect x="40" y="144" width="176" height="64" rx="8"/>',
  'eye': '<path d="M128,56C48,56,16,128,16,128s32,72,112,72,112-72,112-72S208,56,128,56Z"/><circle cx="128" cy="128" r="32"/>',
  'eye-slash': '<line x1="48" y1="40" x2="208" y2="216"/><path d="M74,68.6C33.23,89.24,16,128,16,128s32,72,112,72a118.05,118.05,0,0,0,54-12.6"/><path d="M214.41,163.59C232.12,145.73,240,128,240,128S208,56,128,56c-3.76,0-7.42.16-11,.46"/>',
  'paper-plane-tilt': '<line x1="108" y1="148" x2="160" y2="96"/><path d="M223.69,42.18a8,8,0,0,0-9.87-9.87l-192,58.22a8,8,0,0,0-1.25,14.93L108,148l42.54,87.42a8,8,0,0,0,14.93-1.25Z"/>',
  'printer': '<polyline points="64 80 64 40 192 40 192 80"/><rect x="64" y="152" width="128" height="64" rx="4"/><path d="M64,176H32V96a16,16,0,0,1,16-16H208a16,16,0,0,1,16,16v80H192"/>' + dot(188, 116),
  'backspace': '<path d="M61.67,204.12,16,128,61.67,51.88A8,8,0,0,1,68.53,48H216a8,8,0,0,1,8,8V200a8,8,0,0,1-8,8H68.53A8,8,0,0,1,61.67,204.12Z"/><line x1="160" y1="104" x2="112" y2="152"/><line x1="160" y1="152" x2="112" y2="104"/>',

  // ── commerce ──────────────────────────────────────────────────────────────
  'storefront': '<path d="M48,139.59V208a8,8,0,0,0,8,8H200a8,8,0,0,0,8-8V139.59"/><path d="M54,40H202a8,8,0,0,1,7.69,5.8L224,96H32L46.34,45.8A8,8,0,0,1,54,40Z"/><path d="M96,96v16a32,32,0,0,1-64,0V96"/><path d="M160,96v16a32,32,0,0,1-64,0V96"/><path d="M224,96v16a32,32,0,0,1-64,0V96"/>',
  'shopping-bag': '<rect x="40" y="48" width="176" height="160" rx="8"/><line x1="40" y1="80" x2="216" y2="80"/><path d="M168,112a40,40,0,0,1-80,0"/>',
  'shopping-cart': '<path d="M184,184H69.81L41.92,30.57A8,8,0,0,0,34.05,24H16"/><circle cx="80" cy="204" r="20"/><circle cx="184" cy="204" r="20"/><path d="M62.55,144H188.1a16,16,0,0,0,15.74-13.14L216,64H48"/>',
  'package': '<polygon points="128 24 224 76 224 180 128 232 32 180 32 76 128 24"/><polyline points="32 76 128 128 224 76"/><line x1="128" y1="128" x2="128" y2="232"/><line x1="80" y1="50" x2="176" y2="102"/>',
  'cube': '<path d="M32,172.5V83.5a8,8,0,0,1,4.14-7l88-48.12a8,8,0,0,1,7.72,0l88,48.12a8,8,0,0,1,4.14,7v89a8,8,0,0,1-4.14,7l-88,48.12a8,8,0,0,1-7.72,0l-88-48.12A8,8,0,0,1,32,172.5Z"/><polyline points="33.1 79.2 128 131.2 222.9 79.2"/><line x1="128" y1="131.2" x2="128" y2="231.9"/>',
  'stack': '<polyline points="32 176 128 232 224 176"/><polyline points="32 128 128 184 224 128"/><polygon points="32 80 128 136 224 80 128 24 32 80"/>',
  'archive': '<rect x="24" y="56" width="208" height="40" rx="8"/><path d="M216,96v96a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V96"/><line x1="104" y1="136" x2="152" y2="136"/>',
  'truck': '<path d="M48,176H24V72H160V176"/><line x1="96" y1="176" x2="168" y2="176"/><path d="M160,104h48l24,32v40H216"/><circle cx="72" cy="176" r="24"/><circle cx="192" cy="176" r="24"/>',
  'receipt': '<line x1="80" y1="104" x2="176" y2="104"/><line x1="80" y1="136" x2="176" y2="136"/><path d="M32,208V56a8,8,0,0,1,8-8H216a8,8,0,0,1,8,8V208l-32-16-32,16-32-16-32,16-32-16-32,16Z"/>',
  'tag': '<path d="M42.34,138.34A8,8,0,0,1,40,132.69V40h92.69a8,8,0,0,1,5.65,2.34l99.32,99.32a8,8,0,0,1,0,11.31L153,237.66a8,8,0,0,1-11.31,0Z"/>' + dot(84, 84),
  'ticket': '<path d="M32,104V72a8,8,0,0,1,8-8H216a8,8,0,0,1,8,8v32a24,24,0,0,0,0,48v32a8,8,0,0,1-8,8H40a8,8,0,0,1-8-8V152a24,24,0,0,0,0-48Z"/><line x1="96" y1="96" x2="96" y2="160"/>',
  'percent': '<line x1="200" y1="56" x2="56" y2="200"/><circle cx="76" cy="76" r="24"/><circle cx="180" cy="180" r="24"/>',
  'gift': '<rect x="32" y="80" width="192" height="48" rx="8"/><path d="M208,128v72a8,8,0,0,1-8,8H56a8,8,0,0,1-8-8V128"/><line x1="128" y1="80" x2="128" y2="208"/><path d="M176.79,31.21c9.34,9.34,9.89,25.06,0,33.82C159.88,80,128,80,128,80s0-31.88,15-48.79C151.73,21.32,167.45,21.87,176.79,31.21Z"/><path d="M79.21,31.21c-9.34,9.34-9.89,25.06,0,33.82C96.12,80,128,80,128,80s0-31.88-15-48.79C104.27,21.32,88.55,21.87,79.21,31.21Z"/>',
  'barcode': '<polyline points="184 48 224 48 224 88"/><polyline points="72 208 32 208 32 168"/><polyline points="224 168 224 208 184 208"/><polyline points="32 88 32 48 72 48"/><line x1="80" y1="88" x2="80" y2="168"/><line x1="176" y1="88" x2="176" y2="168"/><line x1="112" y1="88" x2="112" y2="168"/><line x1="144" y1="88" x2="144" y2="168"/>',
  'qr-code': '<rect x="48" y="48" width="64" height="64" rx="8"/><rect x="48" y="144" width="64" height="64" rx="8"/><rect x="144" y="48" width="64" height="64" rx="8"/><line x1="144" y1="144" x2="144" y2="176"/><polyline points="144 208 176 208 176 144"/><line x1="176" y1="160" x2="208" y2="160"/><line x1="208" y1="192" x2="208" y2="208"/>',
  'hash': '<line x1="40" y1="96" x2="224" y2="96"/><line x1="176" y1="40" x2="144" y2="216"/><line x1="112" y1="40" x2="80" y2="216"/><line x1="32" y1="160" x2="216" y2="160"/>',
  'cash-register': '<rect x="96" y="32" width="96" height="40" rx="6"/><line x1="144" y1="72" x2="144" y2="96"/><path d="M48,152,64,96H192l16,56"/><rect x="32" y="152" width="192" height="64" rx="8"/><line x1="104" y1="184" x2="152" y2="184"/>' + dot(100, 124, 9) + dot(128, 124, 9) + dot(156, 124, 9),
  'calculator': '<rect x="48" y="24" width="160" height="208" rx="16"/><rect x="80" y="56" width="96" height="40" rx="4"/>' + dot(92, 140) + dot(128, 140) + dot(164, 140) + dot(92, 184) + dot(128, 184) + dot(164, 184),

  // ── money ─────────────────────────────────────────────────────────────────
  'money': '<rect x="16" y="64" width="224" height="128" rx="8"/><circle cx="128" cy="128" r="32"/><line x1="56" y1="100" x2="56" y2="156"/><line x1="200" y1="100" x2="200" y2="156"/>',
  'credit-card': '<rect x="24" y="56" width="208" height="144" rx="8"/><line x1="168" y1="168" x2="200" y2="168"/><line x1="120" y1="168" x2="136" y2="168"/><line x1="24" y1="96" x2="232" y2="96"/>',
  'coins': '<ellipse cx="104" cy="80" rx="72" ry="32"/><path d="M32,80v40c0,17.67,32.24,32,72,32s72-14.33,72-32V80"/><path d="M176,118c28,4,48,15,48,30,0,17.67-32.24,32-72,32-19,0-36.3-3.28-49.1-8.64"/><path d="M80,160v16c0,17.67,32.24,32,72,32s72-14.33,72-32V148"/>',
  'currency-circle-dollar': '<circle cx="128" cy="128" r="96"/><line x1="128" y1="64" x2="128" y2="80"/><line x1="128" y1="176" x2="128" y2="192"/><path d="M104,168h36a20,20,0,0,0,0-40H116a20,20,0,0,1,0-40h36"/>',
  'wallet': '<path d="M40,72V184a16,16,0,0,0,16,16H216a8,8,0,0,0,8-8V96a8,8,0,0,0-8-8H56A16,16,0,0,1,40,72h0A16,16,0,0,1,56,56H200"/>' + dot(180, 144),
  'bank': '<polygon points="24 96 232 96 128 32 24 96"/><line x1="56" y1="96" x2="56" y2="168"/><line x1="104" y1="96" x2="104" y2="168"/><line x1="152" y1="96" x2="152" y2="168"/><line x1="200" y1="96" x2="200" y2="168"/><line x1="32" y1="168" x2="224" y2="168"/><line x1="16" y1="208" x2="240" y2="208"/>',
  'chart-bar': '<polyline points="48 208 48 136 96 136"/><line x1="224" y1="208" x2="32" y2="208"/><polyline points="96 208 96 88 152 88"/><polyline points="152 208 152 40 208 40 208 208"/>',
  'chart-line': '<polyline points="224 208 32 208 32 48"/><polyline points="224 96 160 152 96 104 32 160"/>',

  // ── people & places ───────────────────────────────────────────────────────
  'user': '<circle cx="128" cy="96" r="64"/><path d="M32,216c19.37-33.47,54.55-56,96-56s76.63,22.53,96,56"/>',
  'user-circle': '<circle cx="128" cy="128" r="96"/><circle cx="128" cy="120" r="40"/><path d="M63.8,199.37a72,72,0,0,1,128.4,0"/>',
  'user-plus': '<circle cx="100" cy="100" r="56"/><path d="M20,200c20.55-24.45,47.56-40,80-40s59.45,15.55,80,40"/><line x1="192" y1="104" x2="240" y2="104"/><line x1="216" y1="80" x2="216" y2="128"/>',
  'users': '<circle cx="84" cy="108" r="52"/><path d="M10.23,200a88,88,0,0,1,147.54,0"/><path d="M172,160a87.93,87.93,0,0,1,73.77,40"/><path d="M152.69,59.7A52,52,0,1,1,172,160"/>',
  'identification-card': '<rect x="24" y="48" width="208" height="160" rx="8"/><line x1="152" y1="112" x2="200" y2="112"/><line x1="152" y1="144" x2="200" y2="144"/><circle cx="96" cy="116" r="24"/><path d="M60,168c3.55-13.8,17.09-24,36-24s32.45,10.2,36,24"/>',
  'house-line': '<line x1="16" y1="216" x2="240" y2="216"/><polyline points="152 216 152 152 104 152 104 216"/><line x1="40" y1="116.69" x2="40" y2="216"/><line x1="216" y1="216" x2="216" y2="116.69"/><path d="M24,132.69l98.34-98.35a8,8,0,0,1,11.32,0L232,132.69"/>',
  'map-pin': '<circle cx="128" cy="104" r="32"/><path d="M208,104c0,72-80,128-80,128S48,176,48,104a80,80,0,0,1,160,0Z"/>',
  'globe': '<circle cx="128" cy="128" r="96"/><line x1="32" y1="128" x2="224" y2="128"/><path d="M128,32c-24,24-36,56-36,96s12,72,36,96"/><path d="M128,32c24,24,36,56,36,96s-12,72-36,96"/>',
  'phone': '<path d="M164.39,145.34a8,8,0,0,1,7.59-.69l47.16,21.13a8,8,0,0,1,4.8,8.3A48.33,48.33,0,0,1,176,216,136,136,0,0,1,40,80,48.33,48.33,0,0,1,81.92,32.06a8,8,0,0,1,8.3,4.8l21.13,47.2a8,8,0,0,1-.66,7.53L89.32,117a79.06,79.06,0,0,0,38.3,38.28Z"/>',
  'device-mobile': '<rect x="64" y="24" width="128" height="208" rx="16"/><line x1="64" y1="64" x2="192" y2="64"/><line x1="64" y1="192" x2="192" y2="192"/>',
  'monitor': '<rect x="32" y="48" width="192" height="144" rx="16"/><line x1="160" y1="224" x2="96" y2="224"/>',
  'envelope': '<polyline points="224 56 128 144 32 56"/><path d="M32,56H224V192a8,8,0,0,1-8,8H40a8,8,0,0,1-8-8V56Z"/><line x1="110.55" y1="128" x2="34.47" y2="197.74"/><line x1="221.53" y1="197.74" x2="145.45" y2="128"/>',
  'envelope-simple': '<path d="M32,56H224V192a8,8,0,0,1-8,8H40a8,8,0,0,1-8-8V56Z"/><polyline points="224 56 128 144 32 56"/>',
  'chat-circle-text': '<line x1="96" y1="112" x2="160" y2="112"/><line x1="96" y1="144" x2="160" y2="144"/><path d="M45.4,177A95.9,95.9,0,1,1,79,210.6h0L45.8,220a7.9,7.9,0,0,1-9.8-9.8L45.4,177Z"/>',
  'tray': '<rect x="40" y="40" width="176" height="176" rx="8"/><path d="M40,152H76.69a8,8,0,0,1,5.65,2.34l19.32,19.32a8,8,0,0,0,5.65,2.34h41.38a8,8,0,0,0,5.65-2.34l19.32-19.32a8,8,0,0,1,5.65-2.34H216"/>',
  'bell': '<path d="M96,192a32,32,0,0,0,64,0"/><path d="M56,104a72,72,0,0,1,144,0c0,35.82,8.3,64.6,14.9,76A8,8,0,0,1,208,192H48a8,8,0,0,1-6.9-12C47.71,168.6,56,139.81,56,104Z"/>',
  'heart': '<path d="M128,216S28,160,28,92A52,52,0,0,1,128,72h0A52,52,0,0,1,228,92C228,160,128,216,128,216Z"/>',
  'star': '<path d="M128,189.09l54.72,33.65a8.4,8.4,0,0,0,12.52-9.17l-14.88-62.79,48.7-42A8.46,8.46,0,0,0,224.27,94L160.36,88.8,135.74,29.2a8.36,8.36,0,0,0-15.48,0L95.64,88.8,31.73,94a8.46,8.46,0,0,0-4.79,14.83l48.7,42L60.76,213.57a8.4,8.4,0,0,0,12.52,9.17Z"/>',

  // ── status & system ───────────────────────────────────────────────────────
  'info': '<circle cx="128" cy="128" r="96"/><line x1="128" y1="120" x2="128" y2="176"/>' + dot(128, 84, 14),
  'question': '<circle cx="128" cy="128" r="96"/><path d="M128,144v-8c17.67,0,32-12.54,32-28s-14.33-28-32-28S96,92.54,96,108v4"/>' + dot(128, 180, 14),
  'warning': '<path d="M142.41,40.22l87.46,151.87C236,202.79,228.08,216,215.46,216H40.54C27.92,216,20,202.79,26.13,192.09L113.59,40.22C119.89,29.26,136.11,29.26,142.41,40.22Z"/><line x1="128" y1="136" x2="128" y2="104"/>' + dot(128, 176, 14),
  'shield-check': '<path d="M216,112V56a8,8,0,0,0-8-8H48a8,8,0,0,0-8,8v56c0,96,88,120,88,120S216,208,216,112Z"/><polyline points="88 136 112 160 168 104"/>',
  'lock-key': '<rect x="40" y="88" width="176" height="128" rx="8"/><path d="M88,88V56a40,40,0,0,1,80,0V88"/><circle cx="128" cy="144" r="20"/><line x1="128" y1="164" x2="128" y2="180"/>',
  'lock-open': '<rect x="40" y="88" width="176" height="128" rx="8"/><path d="M88,88V56a40,40,0,0,1,76.25-16.9"/>' + dot(128, 152, 14),
  'key': '<circle cx="84" cy="172" r="44"/><line x1="115.11" y1="140.89" x2="208" y2="48"/><line x1="176" y1="80" x2="208" y2="112"/><line x1="148" y1="108" x2="172" y2="132"/>',
  'gear': '<circle cx="128" cy="128" r="40"/><path d="M41.43,178.09A99.14,99.14,0,0,1,31.36,153.8l16.78-21a81.59,81.59,0,0,1,0-9.64l-16.77-21a99.43,99.43,0,0,1,10.05-24.3l26.71-3a81,81,0,0,1,6.81-6.81l3-26.7A99.14,99.14,0,0,1,102.2,31.36l21,16.78a81.59,81.59,0,0,1,9.64,0l21-16.77a99.43,99.43,0,0,1,24.3,10.05l3,26.71a81,81,0,0,1,6.81,6.81l26.7,3a99.14,99.14,0,0,1,10.07,24.29l-16.78,21a81.59,81.59,0,0,1,0,9.64l16.77,21a99.43,99.43,0,0,1-10,24.3l-26.71,3a81,81,0,0,1-6.81,6.81l-3,26.7a99.14,99.14,0,0,1-24.29,10.07l-21-16.78a81.59,81.59,0,0,1-9.64,0l-21,16.77a99.43,99.43,0,0,1-24.3-10l-3-26.71a81,81,0,0,1-6.81-6.81Z"/>',
  'clock': '<circle cx="128" cy="128" r="96"/><polyline points="128 72 128 128 184 128"/>',
  'clock-counter-clockwise': '<polyline points="128 80 128 128 168 152"/><polyline points="72 104 32 104 32 64"/><path d="M67.6,192a88,88,0,1,0-1.83-126.23C54,77.69,44.28,88.93,32,104"/>',
  'lightning': '<polygon points="96 240 112 160 48 136 160 16 144 96 208 120 96 240"/>',
  'wifi-slash': '<line x1="48" y1="40" x2="208" y2="216"/><path d="M25.11,94.5a152.13,152.13,0,0,1,53.44-29"/><path d="M59,128.46a104.05,104.05,0,0,1,54.82-24.14"/><path d="M152.54,106.47A103.63,103.63,0,0,1,197,128.46"/><path d="M93,162.41a56,56,0,0,1,70.06,0"/><path d="M128,60a152,152,0,0,1,102.89,34.5"/>' + dot(128, 200, 14),
  'cloud-arrow-up': '<path d="M80,200H72A56,56,0,1,1,85.92,89.74"/><polyline points="120 136 152 104 184 136"/><line x1="152" y1="184" x2="152" y2="104"/><path d="M80,128a80,80,0,1,1,144,48"/>',
  'sun': '<circle cx="128" cy="128" r="52"/><line x1="128" y1="36" x2="128" y2="28"/><line x1="62.95" y1="62.95" x2="57.3" y2="57.3"/><line x1="36" y1="128" x2="28" y2="128"/><line x1="62.95" y1="193.05" x2="57.3" y2="198.7"/><line x1="128" y1="220" x2="128" y2="228"/><line x1="193.05" y1="193.05" x2="198.7" y2="198.7"/><line x1="220" y1="128" x2="228" y2="128"/><line x1="193.05" y1="62.95" x2="198.7" y2="57.3"/>',
  'moon': '<path d="M108.11,28.11A96.09,96.09,0,0,0,227.89,147.89,96,96,0,1,1,108.11,28.11Z"/>',
  'palette': '<path d="M128,32a96,96,0,0,0,0,192c13,0,20-8,20-20s8-20,20-20h24a32,32,0,0,0,32-32C224,82,181,32,128,32Z"/>' + dot(84, 112) + dot(128, 84) + dot(172, 112),
  'camera': '<path d="M208,208H48a16,16,0,0,1-16-16V80A16,16,0,0,1,48,64H80L96,40h64l16,24h32a16,16,0,0,1,16,16V192A16,16,0,0,1,208,208Z"/><circle cx="128" cy="132" r="36"/>',
  'image': '<rect x="32" y="48" width="192" height="160" rx="8"/><path d="M147.31,164,173,138.34a8,8,0,0,1,11.31,0L224,178.06"/><path d="M32,168.69l54.34-54.35a8,8,0,0,1,11.32,0L191.31,208"/>' + dot(156, 100),
  'article': '<rect x="32" y="48" width="192" height="160" rx="8"/><line x1="80" y1="96" x2="176" y2="96"/><line x1="80" y1="128" x2="176" y2="128"/><line x1="80" y1="160" x2="176" y2="160"/>',
  'clipboard-text': '<line x1="96" y1="164" x2="160" y2="164"/><line x1="96" y1="124" x2="160" y2="124"/><path d="M160,40h40a8,8,0,0,1,8,8V216a8,8,0,0,1-8,8H56a8,8,0,0,1-8-8V48a8,8,0,0,1,8-8H96"/><path d="M88,72V64a40,40,0,0,1,80,0v8Z"/>',

  // ── the ledger ────────────────────────────────────────────────────────────
  'book-open': '<path d="M128,88a32,32,0,0,1,32-32h72V200H160a32,32,0,0,0-32,32"/><path d="M24,200H96a32,32,0,0,1,32,32V88A32,32,0,0,0,96,56H24Z"/>',
  'list-numbers': '<line x1="112" y1="64" x2="216" y2="64"/><line x1="112" y1="128" x2="216" y2="128"/><line x1="112" y1="192" x2="216" y2="192"/><polyline points="44 60 60 52 60 108"/><path d="M46,150a16,16,0,1,1,26,17L44,200H76"/>',
  'calendar': '<rect x="40" y="40" width="176" height="176" rx="8"/><line x1="176" y1="24" x2="176" y2="56"/><line x1="80" y1="24" x2="80" y2="56"/><line x1="40" y1="88" x2="216" y2="88"/>',
  'scales': '<line x1="128" y1="40" x2="128" y2="216"/><line x1="104" y1="216" x2="152" y2="216"/><line x1="56" y1="88" x2="200" y2="56"/><path d="M24,168c0,17.67,20,24,32,24s32-6.33,32-24L56,88Z"/><path d="M168,136c0,17.67,20,24,32,24s32-6.33,32-24L200,56Z"/>',
  'buildings': '<line x1="16" y1="216" x2="240" y2="216"/><path d="M144,216V40a8,8,0,0,0-8-8H40a8,8,0,0,0-8,8V216"/><path d="M224,216V104a8,8,0,0,0-8-8H144"/><line x1="64" y1="72" x2="96" y2="72"/><line x1="80" y1="136" x2="112" y2="136"/><line x1="64" y1="176" x2="96" y2="176"/><line x1="176" y1="176" x2="192" y2="176"/><line x1="176" y1="136" x2="192" y2="136"/>',
  'users-three': '<circle cx="128" cy="140" r="40"/><path d="M196,116a39.84,39.84,0,0,1,32,16"/><path d="M28,132a39.84,39.84,0,0,1,32-16"/><path d="M70.4,216a64.07,64.07,0,0,1,115.2,0"/><path d="M60,116a32,32,0,1,1,31.5-37.51"/><path d="M164.5,78.49A32,32,0,1,1,196,116"/>',
  'check-square': '<rect x="40" y="40" width="176" height="176" rx="8"/><polyline points="88 136 112 160 168 104"/>',
  'arrow-u-up-left': '<polyline points="80 136 32 88 80 40"/><path d="M80,200h88a56,56,0,0,0,56-56h0a56,56,0,0,0-56-56H32"/>',
  'hourglass': '<path d="M59.31,40H196.69a8,8,0,0,1,5.65,13.66L128,128,53.66,53.66A8,8,0,0,1,59.31,40Z"/><path d="M59.31,216H196.69a8,8,0,0,0,5.65-13.66L128,128,53.66,202.34A8,8,0,0,0,59.31,216Z"/>',
  'paperclip': '<path d="M160,80,76.69,164.69a16,16,0,0,0,22.63,22.62L198.63,86.63a32,32,0,0,0-45.26-45.26L54.06,142.06a48,48,0,0,0,67.88,67.88L204,128"/>',
  'file-text': '<path d="M200,224H56a8,8,0,0,1-8-8V40a8,8,0,0,1,8-8h96l56,56V216A8,8,0,0,1,200,224Z"/><polyline points="152 32 152 88 208 88"/><line x1="96" y1="136" x2="160" y2="136"/><line x1="96" y1="168" x2="160" y2="168"/>',
  'table': '<path d="M32,56H224V192a8,8,0,0,1-8,8H40a8,8,0,0,1-8-8V56Z"/><line x1="32" y1="104" x2="224" y2="104"/><line x1="32" y1="152" x2="224" y2="152"/><line x1="88" y1="104" x2="88" y2="200"/>',
  'folder': '<path d="M216,208H40a8,8,0,0,1-8-8V64a8,8,0,0,1,8-8H93.33a8,8,0,0,1,4.8,1.6l27.74,20.8a8,8,0,0,0,4.8,1.6H216a8,8,0,0,1,8,8V200A8,8,0,0,1,216,208Z"/>',
  'arrows-left-right': '<polyline points="176 144 208 176 176 208"/><line x1="48" y1="176" x2="208" y2="176"/><polyline points="80 112 48 80 80 48"/><line x1="208" y1="80" x2="48" y2="80"/>',
  'calendar-check': '<rect x="40" y="40" width="176" height="176" rx="8"/><line x1="176" y1="24" x2="176" y2="56"/><line x1="80" y1="24" x2="80" y2="56"/><line x1="40" y1="88" x2="216" y2="88"/><polyline points="96 152 116 172 160 128"/>',
  'target': '<circle cx="128" cy="128" r="96"/><circle cx="128" cy="128" r="56"/>' + dot(128, 128, 16),
  'flag': '<line x1="48" y1="224" x2="48" y2="48"/><path d="M48,168c64-48,96,32,160-16V48c-64,48-96-32-160,16"/>',
  'trend-up': '<polyline points="232 56 136 152 96 112 24 184"/><polyline points="232 120 232 56 168 56"/>',
};

/** An SVG string for `slug`, or '' when the slug is unknown. Colour is
    inherited (currentColor) so hover and disabled states carry the icon. */
export function iconSvg(slug, size = DEFAULT_SIZE) {
  const p = ICONS[slug];
  if (!p) return '';
  const s = Math.max(8, Math.min(256, Number(size) || DEFAULT_SIZE));
  return '<svg viewBox="' + VIEWBOX + '" width="' + s + '" height="' + s + '" aria-hidden="true" focusable="false">'
    + '<g fill="none" stroke="currentColor" stroke-width="' + STROKE + '" stroke-linecap="round" stroke-linejoin="round">'
    + p + '</g></svg>';
}

export function hasIcon(slug) { return Object.prototype.hasOwnProperty.call(ICONS, slug); }
export const ICON_SLUGS = Object.keys(ICONS);

// ─── Painter ────────────────────────────────────────────────────────────────

const SEL = '[data-icon]';
const _warned = new Set();

function paintOne(el) {
  const slug = el.getAttribute('data-icon');
  if (!slug) return;
  const size = Number(el.getAttribute('data-icon-size')) || DEFAULT_SIZE;
  const key = slug + '@' + size;

  /* Already painted with THIS glyph: re-running must be free, because the
     observer sees its own writes come back as added nodes. */
  if (el.__gpIcon === key && el.firstElementChild && el.firstElementChild.localName === 'svg') return;

  const svg = iconSvg(slug, size);
  if (!svg) {
    if (!_warned.has(slug)) { _warned.add(slug); console.warn('[icons] unknown icon slug:', slug); }
    return;
  }
  el.innerHTML = svg;
  el.__gpIcon = key;
}

/** Paint everything under `root` (inclusive). Safe to call repeatedly. */
export function paintIcons(root) {
  const scope = root || document;
  if (scope.matches && scope.matches(SEL)) paintOne(scope);
  if (scope.querySelectorAll) scope.querySelectorAll(SEL).forEach(paintOne);
}

const _observers = new WeakMap();

/**
 * Paint now, then keep painting nodes added under `root` and slots whose
 * data-icon changes. One observer per root — calling again replaces it.
 * @returns {function(): void} stop
 */
export function startAutoPaint(root) {
  const target = root || document.body;
  paintIcons(target);
  if (typeof MutationObserver !== 'function') return () => {};

  const prev = _observers.get(target);
  if (prev) prev.disconnect();

  const mo = new MutationObserver((muts) => {
    for (const m of muts) {
      if (m.type === 'attributes') {
        if (m.target.nodeType === 1 && m.target.hasAttribute('data-icon')) {
          m.target.__gpIcon = null;
          paintOne(m.target);
        }
        continue;
      }
      for (const node of m.addedNodes) {
        if (node.nodeType !== 1) continue;
        if (node.matches(SEL)) paintOne(node);
        if (node.firstElementChild) node.querySelectorAll(SEL).forEach(paintOne);
      }
    }
  });
  mo.observe(target, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-icon', 'data-icon-size'] });
  _observers.set(target, mo);

  return () => {
    mo.disconnect();
    if (_observers.get(target) === mo) _observers.delete(target);
  };
}
