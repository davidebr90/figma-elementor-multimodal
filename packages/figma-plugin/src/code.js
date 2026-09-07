figma.showUI(__html__, { width: 420, height: 520 });

const STORAGE_KEY = 'fem.connection';
const NETWORK_TIMEOUT_MS = 20000;
let renewalPromise = null;
const scopes = ['import:write', 'asset:write', 'changes:read', 'changes:ack'];
const responsiveDraft = { desktop: null, tablet: null, mobile: null };
const nodeTypes = { FRAME: 'frame', SECTION: 'section', GROUP: 'group', TEXT: 'text', RECTANGLE: 'rectangle', ELLIPSE: 'ellipse', LINE: 'line', VECTOR: 'vector', BOOLEAN_OPERATION: 'boolean_operation', COMPONENT: 'component', COMPONENT_SET: 'component_set', INSTANCE: 'instance', IMAGE: 'image' };

const post = (message, extra = {}) => figma.ui.postMessage({ type: 'status', message, ...extra });

/**
 * The plugin panel scrolls, so a status line at the top is easy to miss. Figma's
 * own toast appears over the canvas and is seen regardless of where the panel is
 * scrolled, so every outcome that ends an operation is announced there too.
 */
const announce = (message, isError = false) => {
  post(message, { done: true, error: isError });
  try { figma.notify(message, { error: isError, timeout: isError ? 6000 : 4000 }); } catch (ignored) { /* notify is unavailable in some contexts */ }
};
// The Figma plugin main-thread sandbox does not expose a global `crypto`
// object (no crypto.randomUUID, no crypto.subtle), even with networkAccess
// declared, so uuid() and digest() below avoid it entirely.
const uuid = () => `${Date.now()}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`;
const jsonResponse = async (response) => {
  let body;
  try { body = await response.json(); } catch (error) { throw new Error(`WordPress returned a non-JSON response (${response.status}).`); }
  if (!response.ok || !body || body.success !== true || body.data === undefined) throw new Error(body?.errors?.[0]?.message || `WordPress request failed (${response.status}).`);
  return body.data;
};
const request = async (baseUrl, credential, path, init = {}) => {
  const { timeoutMs = NETWORK_TIMEOUT_MS, ...fetchInit } = init;
  const canAbort = typeof AbortController === 'function' && typeof setTimeout === 'function' && !fetchInit.signal;
  const controller = canAbort ? new AbortController() : null;
  let timedOut = false;
  const timer = controller ? setTimeout(() => { timedOut = true; controller.abort(); }, timeoutMs) : null;
  let response;
  try {
    response = await fetch(`${baseUrl.replace(/\/$/, '')}${path}`, {
      ...fetchInit,
      ...(controller ? { signal: controller.signal } : {}),
      headers: { ...(fetchInit.headers || {}), ...(credential ? { Authorization: `Bearer ${credential}` } : {}) },
    });
  } catch (error) {
    const detail = error && error.message ? `: ${error.message}` : '';
    throw new Error(timedOut ? `WordPress request timed out after ${timeoutMs}ms.` : `Network request failed${detail}`);
  } finally {
    if (timer !== null) clearTimeout(timer);
  }
  return jsonResponse(response);
};

/** UTF-8 encode a string to Uint8Array; the sandbox has no TextEncoder. */
const utf8Bytes = (text) => {
  const out = [];
  for (let i = 0; i < text.length; i += 1) {
    let code = text.charCodeAt(i);
    if (code >= 0xd800 && code <= 0xdbff && i + 1 < text.length) {
      const low = text.charCodeAt(i + 1);
      if (low >= 0xdc00 && low <= 0xdfff) {
        code = 0x10000 + ((code - 0xd800) << 10) + (low - 0xdc00);
        i += 1;
      }
    }
    if (code < 0x80) {
      out.push(code);
    } else if (code < 0x800) {
      out.push(0xc0 | (code >> 6), 0x80 | (code & 0x3f));
    } else if (code < 0x10000) {
      out.push(0xe0 | (code >> 12), 0x80 | ((code >> 6) & 0x3f), 0x80 | (code & 0x3f));
    } else {
      out.push(0xf0 | (code >> 18), 0x80 | ((code >> 12) & 0x3f), 0x80 | ((code >> 6) & 0x3f), 0x80 | (code & 0x3f));
    }
  }
  return new Uint8Array(out);
};

/** Pure-JS SHA-256 (FIPS 180-4) over a Uint8Array/ArrayBuffer; no Web Crypto needed. */
const digest = async (input) => {
  const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
  const k = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
  ];
  let h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a;
  let h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;
  const bitLen = bytes.length * 8;
  const padded = new Uint8Array((((bytes.length + 8) >> 6) + 1) << 6);
  padded.set(bytes);
  padded[bytes.length] = 0x80;
  const view = new DataView(padded.buffer);
  view.setUint32(padded.length - 4, bitLen >>> 0);
  view.setUint32(padded.length - 8, Math.floor(bitLen / 0x100000000));
  const w = new Uint32Array(64);
  const rotr = (x, n) => (x >>> n) | (x << (32 - n));
  for (let offset = 0; offset < padded.length; offset += 64) {
    for (let i = 0; i < 16; i += 1) w[i] = view.getUint32(offset + i * 4);
    for (let i = 16; i < 64; i += 1) {
      const s0 = rotr(w[i - 15], 7) ^ rotr(w[i - 15], 18) ^ (w[i - 15] >>> 3);
      const s1 = rotr(w[i - 2], 17) ^ rotr(w[i - 2], 19) ^ (w[i - 2] >>> 10);
      w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
    }
    let [a, b, c, d, e, f, g, h] = [h0, h1, h2, h3, h4, h5, h6, h7];
    for (let i = 0; i < 64; i += 1) {
      const s1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25);
      const ch = (e & f) ^ (~e & g);
      const t1 = (h + s1 + ch + k[i] + w[i]) | 0;
      const s0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22);
      const maj = (a & b) ^ (a & c) ^ (b & c);
      const t2 = (s0 + maj) | 0;
      h = g; g = f; f = e; e = (d + t1) | 0;
      d = c; c = b; b = a; a = (t1 + t2) | 0;
    }
    h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
    h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + h) | 0;
  }
  return [h0, h1, h2, h3, h4, h5, h6, h7].map((n) => (n >>> 0).toString(16).padStart(8, '0')).join('');
};
const stable = (value) => {
  if (Array.isArray(value)) return `[${value.map(stable).join(',')}]`;
  if (value && typeof value === 'object') return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stable(value[key])}`).join(',')}}`;
  return JSON.stringify(value);
};
const femId = (fileKey, id) => `urn:fem:figma:${fileKey}:${id}`;

/** Explicit per-layer widget choice, stored on the Figma node so it survives re-imports. */
const WIDGET_KEY = 'fem-widget';
const NAMED_WIDGETS = ['button', 'heading', 'text-editor', 'image', 'image-gallery', 'image-carousel', 'nested-accordion', 'icon', 'divider', 'spacer', 'container'];
const WIDGET_CHOICES = [...NAMED_WIDGETS, 'skip'];

function widgetFor(node) {
  const explicit = typeof node.getPluginData === 'function' ? node.getPluginData(WIDGET_KEY) : '';
  if (WIDGET_CHOICES.includes(explicit)) return { widget: explicit, source: 'override' };
  return classify(node);
}

function classify(node) {

  // A naming convention beats guessing, and designers write it many ways:
  // "button/primary", "cta-button", "btn_main". Every token is a candidate.
  const alias = { btn: 'button', cta: 'button', title: 'heading', titolo: 'heading', text: 'text-editor', testo: 'text-editor', img: 'image', immagine: 'image', gallery: 'image-gallery', galleria: 'image-gallery', carousel: 'image-carousel', accordion: 'nested-accordion', icona: 'icon' };
  const tokens = String(node.name || '').toLowerCase().split(/[\/:\-_\s]+/).filter(Boolean);
  for (const token of tokens) {
    const named = NAMED_WIDGETS.includes(token) ? token : alias[token];
    // A convention must still fit the layer: a frame named "titolo" is a wrapper,
    // and turning it into a heading would emit an empty widget and drop its children.
    if (named && namedFits(node, named)) return { widget: named, source: 'name' };
  }

  if (node.type === 'TEXT') {
    const characters = String(node.characters || '').trim();
    // An empty text layer would become an empty heading; drop it instead.
    if (characters === '') return { widget: 'skip', source: 'heuristic' };
    const size = typeof node.fontSize === 'number' ? node.fontSize : 16;
    // Long copy is a paragraph even when it is set large.
    if (characters.length > 120) return { widget: 'text-editor', source: 'heuristic' };
    return { widget: size >= 20 ? 'heading' : 'text-editor', source: 'heuristic' };
  }
  // An image fill on a node with children is a background, not an image widget:
  // treating it as one would flatten the whole section into a single picture.
  if (hasImageFill(node) && visibleChildren(node).length === 0) return { widget: 'image', source: 'heuristic' };
  if (isDivider(node)) return { widget: 'divider', source: 'heuristic' };
  if (isGallery(node)) return { widget: 'image-gallery', source: 'heuristic' };
  if (isButton(node)) return { widget: 'button', source: 'heuristic' };
  if (isSpacer(node)) return { widget: 'spacer', source: 'heuristic' };
  return { widget: 'container', source: 'heuristic' };
}

const visibleChildren = (node) => (node.children || []).filter((child) => child.visible !== false);

/** Guards a naming convention against layers that cannot carry that widget. */
function namedFits(node, widget) {
  if (widget === 'heading' || widget === 'text-editor') return node.type === 'TEXT';
  if (widget === 'image') return hasImageFill(node) || node.type === 'RECTANGLE';
  if (widget === 'image-gallery' || widget === 'image-carousel') return visibleChildren(node).length > 0;
  if (widget === 'button') return node.type !== 'TEXT' ? visibleChildren(node).some((child) => child.type === 'TEXT') : true;
  return true;
}

/** A Figma prototype link is the strongest signal that something is a button. */
function linkOf(node) {
  const reactions = Array.isArray(node.reactions) ? node.reactions : [];
  for (const reaction of reactions) {
    const action = reaction.action || (Array.isArray(reaction.actions) ? reaction.actions[0] : null);
    if (action && action.type === 'URL' && action.url) return String(action.url);
  }
  return null;
}

/** Every TEXT under a node, however deeply a designer wrapped it. */
function textDescendants(node, depth = 0) {
  if (depth > 4) return [];
  const found = [];
  for (const child of visibleChildren(node)) {
    if (child.type === 'TEXT') found.push(child);
    else found.push(...textDescendants(child, depth + 1));
  }
  return found;
}

/**
 * A button wraps a single label and is wider than it is tall. The width test is
 * what separates it from a round badge: a circle holding "1" is not a button.
 * The label is often nested one frame deeper, while the fill and padding sit on
 * the outer frame, so the search goes through descendants and the outermost
 * qualifying node wins.
 */
function isButton(node) {
  const texts = textDescendants(node);
  if (texts.length !== 1) return false;
  const label = String(texts[0].characters || '').trim();
  // A short label is a call to action; a long one is a paragraph in a coloured box.
  if (label.length < 3 || label.length > 40) return false;
  const width = Number(node.width) || 0;
  const height = Number(node.height) || 0;
  if (height > 80) return false;
  const hasLink = linkOf(node) !== null;
  if (!hasLink && width < height * 1.6) return false;
  // Square-cornered bars are buttons too, so a solid fill alone is enough.
  return hasLink || hasSolidFill(node) || Number(node.cornerRadius) > 0;
}

/** A hairline rule: a line, or a rectangle thin in one axis and long in the other. */
function isDivider(node) {
  if (visibleChildren(node).length > 0) return false;
  if (node.type === 'LINE') return true;
  if (node.type !== 'RECTANGLE') return false;
  const width = Number(node.width) || 0;
  const height = Number(node.height) || 0;
  return (height <= 4 && width >= 40) || (width <= 4 && height >= 40);
}

/** Sibling images with no captions of their own read as a gallery. */
function isGallery(node) {
  const kids = visibleChildren(node);
  if (kids.length < 2) return false;
  return kids.every((child) => hasImageFill(child) && visibleChildren(child).length === 0);
}

/** An empty frame with no paint is only there to create space. */
function isSpacer(node) {
  return visibleChildren(node).length === 0 && !hasSolidFill(node) && !hasImageFill(node) && node.type !== 'TEXT';
}

const hasImageFill = (node) => Array.isArray(node.fills) && node.fills.some((fill) => fill.type === 'IMAGE' && fill.visible !== false);
const hasSolidFill = (node) => Array.isArray(node.fills) && node.fills.some((fill) => fill.type === 'SOLID' && fill.visible !== false);

function legacySolidColor(paints) {
  if (!Array.isArray(paints)) return null;
  // Figma paints the array bottom-up, so the visible colour is the last one.
  const paint = [...paints].reverse().find((item) => item.type === 'SOLID' && item.visible !== false);
  if (!paint) return null;
  const alpha = paint.opacity === undefined ? 1 : paint.opacity;
  if (alpha >= 1) return hex(paint.color);
  const channel = (key) => Math.round(Math.max(0, Math.min(1, paint.color[key])) * 255);
  return `rgba(${channel('r')}, ${channel('g')}, ${channel('b')}, ${Math.round(alpha * 100) / 100})`;
}
const solidColor = globalThis.__femSolidColor || legacySolidColor;

/** Auto-layout is what maps onto an Elementor flex container; everything else is a warning. */
function legacyLayoutOf(node) {
  const layout = { width: Math.round(node.width || 0), height: Math.round(node.height || 0) };
  if (node.layoutMode && node.layoutMode !== 'NONE') {
    layout.mode = node.layoutMode === 'GRID' ? 'grid' : 'flex';
    layout.direction = node.layoutMode === 'HORIZONTAL' ? 'row' : 'column';
    layout.gap = typeof node.itemSpacing === 'number' ? Math.round(node.itemSpacing) : 0;
    if (typeof node.counterAxisSpacing === 'number') layout.rowGap = Math.round(node.counterAxisSpacing);
    layout.wrap = node.layoutWrap === 'WRAP';
    layout.padding = { top: Math.round(node.paddingTop || 0), right: Math.round(node.paddingRight || 0), bottom: Math.round(node.paddingBottom || 0), left: Math.round(node.paddingLeft || 0) };
    layout.justify = node.primaryAxisAlignItems || 'MIN';
    layout.align = node.counterAxisAlignItems || 'MIN';
    if (node.layoutMode === 'GRID') {
      layout.columns = Array.isArray(node.gridColumnSizes) ? node.gridColumnSizes.length : (node.gridColumnCount || null);
      layout.rows = Array.isArray(node.gridRowSizes) ? node.gridRowSizes.length : (node.gridRowCount || null);
    }
  }
  if (node.layoutSizingHorizontal) layout.sizingH = node.layoutSizingHorizontal;
  if (node.layoutSizingVertical) layout.sizingV = node.layoutSizingVertical;
  return layout;
}
const layoutOf = globalThis.__femLayoutOf || legacyLayoutOf;

/** Reads explicit viewport overrides written by FEM or a companion Figma workflow. */
function legacyResponsiveOf(node) {
  if (typeof node.getPluginData !== 'function') return null;
  const raw = node.getPluginData('fem-responsive');
  if (!raw) return null;
  try {
    const value = JSON.parse(raw);
    if (!value || typeof value !== 'object') return null;
    const clean = {};
    for (const viewport of ['desktop', 'tablet', 'mobile']) {
      if (value[viewport] && typeof value[viewport] === 'object') clean[viewport] = value[viewport];
    }
    return Object.keys(clean).length ? clean : null;
  } catch (error) {
    return null;
  }
}
const responsiveOf = globalThis.__femResponsiveOf || legacyResponsiveOf;

function legacyResponsiveKey(node, index) {
  return `${index}:${String(node.name || '').trim().toLowerCase()}`;
}
const responsiveKey = globalThis.__femResponsiveKey || legacyResponsiveKey;

function captureResponsiveSelection(viewport) {
  if (!['desktop', 'tablet', 'mobile'].includes(viewport)) throw new Error('Unsupported responsive viewport.');
  const selection = figma.currentPage.selection;
  if (selection.length !== 1) throw new Error(`Select exactly one ${viewport} frame or component.`);
  const capture = (node, path = []) => {
    const children = (node.children || []).filter(child => child.visible !== false);
    const names = children.map(child => String(child.name || '').trim().toLowerCase());
    if (new Set(names).size !== names.length) throw new Error(`Rename duplicate sibling layers inside "${node.name}" before capturing responsive variants.`);
    return {
      nodeId: node.id, name: node.name, type: node.type, path: JSON.stringify(path),
      layout: layoutOf(node),
      style: { radius: typeof node.cornerRadius === 'number' ? node.cornerRadius : undefined },
      text: node.type === 'TEXT' ? { fontSize: typeof node.fontSize === 'number' ? node.fontSize : undefined } : undefined,
      children: children.map((child, i) => capture(child, [...path, names[i]])),
    };
  };
  const tree = capture(selection[0]);
  responsiveDraft[viewport] = selection.map((node, index) => ({
    key: responsiveKey(node, index),
    layout: layoutOf(node),
    style: { background: solidColor(node.fills), radius: typeof node.cornerRadius === 'number' ? Math.round(node.cornerRadius) : undefined, borderColor: solidColor(node.strokes), borderWidth: typeof node.strokeWeight === 'number' ? Math.round(node.strokeWeight) : undefined },
    text: node.type === 'TEXT' ? { fontSize: typeof node.fontSize === 'number' ? node.fontSize : undefined } : undefined,
  }));
  responsiveDraft[viewport][0].tree = tree;
  figma.ui.postMessage({ type: 'responsive-captured', viewport, count: responsiveDraft[viewport].length });
}

function responsiveOverridesFor(node, index, path = [], warnings = []) {
  const result = {};
  const key = responsiveKey(node, index);
  for (const viewport of ['desktop', 'tablet', 'mobile']) {
    const captured = (responsiveDraft[viewport] || []).find((item) => item.key === key) || (responsiveDraft[viewport] || [])[index];
    if (captured) {
      let match = globalThis.__femFindResponsiveMatch ? globalThis.__femFindResponsiveMatch(captured, path, node.type) : captured.tree;
      if (!globalThis.__femFindResponsiveMatch) for (const name of path) match = match?.children.find(child => String(child.name || '').trim().toLowerCase() === name);
      if (!match || match.type !== node.type) {
        warnings.push({ code: 'responsive-unmatched', path: node.id, message: `${viewport}: no matching layer for "${node.name}"; base values retained.` });
        continue;
      }
      const values = path.length ? match : captured;
      result[viewport] = { ...values.layout, ...values.style, ...(values.text || {}) };
    }
  }
  return Object.keys(result).length ? result : null;
}

async function textOf(node) {
  const bound = await resolveBoundVariables(node);
  const text = { characters: String(node.characters || '') };
  const size = bound.fontSize ?? node.fontSize;
  if (typeof size === 'number') text.fontSize = size;
  if (node.fontName && node.fontName !== figma.mixed) {
    text.fontFamily = node.fontName.family;
    text.fontWeight = weightOf(node.fontName.style);
  }
  if (typeof bound.fontFamily === 'string' && bound.fontFamily) text.fontFamily = bound.fontFamily;
  if (bound.fontWeight !== undefined) text.fontWeight = String(Math.round(Number(bound.fontWeight)));
  if (bound.lineHeight !== undefined) text.lineHeight = Number(bound.lineHeight);
  else if (node.lineHeight && node.lineHeight.unit === 'PIXELS') text.lineHeight = node.lineHeight.value;
  if (node.letterSpacing && node.letterSpacing.unit === 'PIXELS') text.letterSpacing = node.letterSpacing.value;
  text.align = String(node.textAlignHorizontal || 'LEFT').toLowerCase();
  const color = solidColor(node.fills);
  if (color) text.color = color;
  if (node.textCase === 'UPPER') text.transform = 'uppercase';
  return text;
}

async function extractSelection(selection) {
  const fileKey = figma.fileKey || 'local-file';
  const nodes = {};
  const capabilities = [];
  const warnings = [];
  const assets = {};
  const assetPayloads = [];

  const visit = async (node, insideButton = false, siblingIndex = 0, isRoot = false, path = []) => {
    const choice = widgetFor(node);
    // Skipped layers are dropped entirely: the schema forbids dangling references.
    if (choice.widget === 'skip') return null;
    // The outermost button carries the fill and padding and renders its own label,
    // so anything nested inside it would only duplicate that label.
    if (insideButton) return null;
    const id = femId(fileKey, node.id);
    const children = [];
    for (const child of node.children || []) {
      if (child.visible === false) continue;
      const childId = await visit(child, choice.widget === 'button', siblingIndex, false, [...path, String(child.name || '').trim().toLowerCase()]);
      if (childId !== null) children.push(childId);
    }
    const type = nodeTypes[node.type] || 'unsupported';
    const entry = {
      id,
      sourceNodeId: node.id,
      type,
      children,
      widget: choice.widget,
      widgetSource: choice.source,
      layout: layoutOf(node),
      responsive: responsiveOf(node) || responsiveOverridesFor(node, siblingIndex, path, warnings),
      style: {},
      content: { name: node.name || '' },
      provenance: { node: { origin: 'figma', method: 'dynamic-page-selection' } },
    };

    const background = solidColor(node.fills);
    if (background && node.type !== 'TEXT') entry.style.background = background;
    const radius = typeof node.cornerRadius === 'number' ? node.cornerRadius : null;
    if (radius) entry.style.radius = Math.round(radius);
    if (typeof node.opacity === 'number' && node.opacity < 1) entry.style.opacity = Math.round(node.opacity * 100) / 100;
    const border = solidColor(node.strokes);
    if (border && typeof node.strokeWeight === 'number' && node.strokeWeight > 0) {
      entry.style.borderColor = border;
      entry.style.borderWidth = Math.round(node.strokeWeight);
    }

    if (node.type === 'TEXT') {
      entry.text = await textOf(node);
      entry.content.characters = entry.text.characters;
    }
    const link = linkOf(node);
    if (link) entry.link = link;
    if (choice.widget === 'button') {
      // The label and its styling live in the child text that identified the button.
      const label = textDescendants(node)[0];
      if (label) {
        entry.text = await textOf(label);
        entry.content.characters = entry.text.characters;
      }
    }
    if (hasImageFill(node) && typeof node.exportAsync === 'function') {
      assetPayloads.push({ node, id });
    }
    if (node.layoutMode === 'NONE' && (node.children || []).length > 0) {
      warnings.push({ code: 'absolute-layout', path: `/nodes/${id}`, message: `"${node.name}" has no auto-layout; Elementor cannot reproduce absolute positioning.` });
    }

    nodes[id] = entry;
    const status = type === 'unsupported' ? 'unsupported' : 'native';
    capabilities.push({ nodeId: id, propertyPath: 'node', status, reason: status === 'native' ? 'supported' : 'node type is unsupported' });
    return id;
  };

  const roots = [];
  for (const [index, node] of selection.entries()) {
    const root = await visit(node, false, index, true);
    if (root !== null) roots.push(root);
  }
  if (!roots.length) throw new Error('All selected layers are skipped. Choose at least one importable layer.');

  for (const item of assetPayloads) {
    const bytes = await item.node.exportAsync({ format: 'PNG', constraint: { type: 'SCALE', value: 2 } });
    const sha256 = await digest(bytes);
    const assetId = `image-${item.node.id}`;
    assets[assetId] = { assetId, kind: 'image', sha256, mime: 'image/png', byteLength: bytes.byteLength };
    // A container keeps its picture as a background; a leaf becomes an image widget.
    if (nodes[item.id].widget === 'image') nodes[item.id].image = { sha256 };
    else nodes[item.id].style.backgroundImage = sha256;
    item.payload = { sha256, mime: 'image/png', bytes };
  }
  const revision = uuid();
  const document = { kind: 'fem.document', schemaVersion: '1.0.0', source: { provider: 'figma', identity: `figma:${fileKey}:${selection[0].id}`, rootNodeId: selection[0].id, fileKey }, roots, nodes, assets, tokens: {}, editables: [], capabilities, warnings, unsupported: [], revisions: { figmaRevision: revision, wordpressRevision: 0, commonBaseRevision: revision }, integrity: { algorithm: 'sha256-jcs', contentHash: '0'.repeat(64) } };
  document.integrity.contentHash = await digest(utf8Bytes(stable(document)));
  return { document, assetPayloads: assetPayloads.map((item) => item.payload).filter(Boolean) };
}

async function connect(config) {
  const baseUrl = normalizeBaseUrl(config.baseUrl);
  if (!config.pairingId || !config.code) throw new Error('Pairing ID and pairing code are required.');
  const data = await request(baseUrl, '', '/index.php?rest_route=/figma-elementor-multimodal/v1/pairings/exchange', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pairingId: config.pairingId, code: config.code, deviceId: `figma-${uuid()}`, challenge: uuid(), requestedScopes: scopes }) });
  await figma.clientStorage.setAsync(STORAGE_KEY, { baseUrl, credential: data.deviceCredential, expiresAt: data.expiresAt });
  figma.ui.postMessage({ type: 'connected', baseUrl, expiresAt: data.expiresAt });
  announce('Connected to WordPress. Choose a page, select a frame, then Import selection.');
  await loadPages();
}

function normalizeBaseUrl(value) {
  const candidate = String(value || '').trim().replace(/\/$/, '');
  const match = /^(https:|http:)\/\/([^/?#@]+)(\/[^?#]*)?$/i.exec(candidate);
  if (!match) throw new Error('Use an HTTPS WordPress URL, or a loopback address for development. Do not include query strings or credentials.');
  const protocol = match[1].toLowerCase();
  const authority = match[2];
  const host = authority.replace(/:\d+$/, '').toLowerCase();
  const loopback = host === 'localhost' || host === '127.0.0.1' || host === '[::1]';
  if (!(protocol === 'https:' || (protocol === 'http:' && loopback))) throw new Error('Use an HTTPS WordPress URL, or a loopback address for development. Do not include query strings or credentials.');
  return `${protocol}//${authority}${(match[3] || '').replace(/\/$/, '')}`;
}

const hex = (color) => ['#', ...['r', 'g', 'b'].map((key) => Math.round(Math.max(0, Math.min(1, color[key])) * 255).toString(16).padStart(2, '0'))].join('');

/** Reads local colour variables and text styles, the two things Elementor keeps as globals. */
async function collectDesignSystem() {
  const colors = [];
  if (figma.variables && figma.variables.getLocalVariablesAsync) {
    const collections = await figma.variables.getLocalVariableCollectionsAsync();
    const defaultMode = {};
    for (const collection of collections) defaultMode[collection.id] = collection.defaultModeId || (collection.modes[0] || {}).modeId;
    for (const variable of await figma.variables.getLocalVariablesAsync('COLOR')) {
      const value = variable.valuesByMode[defaultMode[variable.variableCollectionId]];
      // Aliases point at another variable; only concrete colours can become globals.
      if (!value || typeof value !== 'object' || value.type === 'VARIABLE_ALIAS' || typeof value.r !== 'number') continue;
      colors.push({ name: variable.name, value: hex(value) });
    }
  }

  const typography = [];
  const textStyles = figma.getLocalTextStylesAsync ? await figma.getLocalTextStylesAsync() : (figma.getLocalTextStyles ? figma.getLocalTextStyles() : []);
  for (const style of textStyles) {
    // Token-based files leave the raw properties at their defaults and bind them
    // to variables instead, so the bound value is the authoritative one.
    const bound = await resolveBoundVariables(style);
    const entry = { name: style.name, fontSize: bound.fontSize ?? style.fontSize };
    if (style.fontName) {
      entry.fontFamily = style.fontName.family;
      entry.fontWeight = weightOf(style.fontName.style);
    }
    if (typeof bound.fontFamily === 'string' && bound.fontFamily) entry.fontFamily = bound.fontFamily;
    if (bound.fontWeight !== undefined) entry.fontWeight = String(Math.round(Number(bound.fontWeight)));
    if (bound.lineHeight !== undefined) { entry.lineHeight = Number(bound.lineHeight); entry.lineHeightUnit = 'px'; }
    if (bound.letterSpacing !== undefined) entry.letterSpacing = Number(bound.letterSpacing);
    if (style.lineHeight && style.lineHeight.unit !== 'AUTO') {
      entry.lineHeight = style.lineHeight.value;
      entry.lineHeightUnit = style.lineHeight.unit === 'PERCENT' ? 'em' : 'px';
      if (style.lineHeight.unit === 'PERCENT') entry.lineHeight = style.lineHeight.value / 100;
    }
    if (style.letterSpacing && typeof style.letterSpacing.value === 'number' && style.letterSpacing.unit === 'PIXELS') entry.letterSpacing = style.letterSpacing.value;
    if (style.textCase === 'UPPER') entry.textTransform = 'uppercase';
    if (style.textCase === 'LOWER') entry.textTransform = 'lowercase';
    if (style.textCase === 'TITLE') entry.textTransform = 'capitalize';
    typography.push(entry);
  }
  return { colors, typography };
}

/** Resolves a style's bound variables to concrete values in each collection's default mode. */
async function resolveBoundVariables(style) {
  const resolved = {};
  const bindings = style.boundVariables || {};
  if (!figma.variables || !figma.variables.getVariableByIdAsync) return resolved;
  for (const property of ['fontSize', 'fontWeight', 'fontFamily', 'lineHeight', 'letterSpacing']) {
    const binding = bindings[property];
    if (!binding || !binding.id) continue;
    let variable = await figma.variables.getVariableByIdAsync(binding.id);
    let value;
    // A variable may alias another one; follow a bounded chain rather than loop.
    for (let hop = 0; hop < 5 && variable; hop += 1) {
      const collection = await figma.variables.getVariableCollectionByIdAsync(variable.variableCollectionId);
      const modeId = collection ? (collection.defaultModeId || (collection.modes[0] || {}).modeId) : null;
      value = variable.valuesByMode[modeId];
      if (value && typeof value === 'object' && value.type === 'VARIABLE_ALIAS') {
        variable = await figma.variables.getVariableByIdAsync(value.id);
        continue;
      }
      break;
    }
    if (value !== undefined && (typeof value === 'number' || typeof value === 'string')) resolved[property] = value;
  }
  return resolved;
}

/** Figma names weights ("Bold", "ExtraBold"); Elementor wants the numeric value. */
function legacyWeightOf(styleName) {
  const map = { thin: '100', extralight: '200', ultralight: '200', light: '300', regular: '400', normal: '400', book: '400', medium: '500', semibold: '600', demibold: '600', bold: '700', extrabold: '800', ultrabold: '800', black: '900', heavy: '900' };
  const key = String(styleName || '').toLowerCase().replace(/\s|italic|oblique/g, '');
  return map[key] || '400';
}
const weightOf = globalThis.__femWeightOf || legacyWeightOf;

async function syncDesignSystem() {
  await ensureConnection();
  const connection = await figma.clientStorage.getAsync(STORAGE_KEY);
  if (!connection?.baseUrl || !connection?.credential) throw new Error('Connect to WordPress first.');
  post('Reading Figma variables and text styles…');
  const { colors, typography } = await collectDesignSystem();
  if (!colors.length && !typography.length) throw new Error('This file has no local colour variables or text styles.');
  post(`Sending ${colors.length} colours and ${typography.length} text styles…`);
  const report = await request(connection.baseUrl, connection.credential, '/index.php?rest_route=/figma-elementor-multimodal/v1/design-system', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ colors, typography }) });
  const summary = (label, part) => `${label}: ${part.created.length} new, ${part.updated.length} updated`;
  announce(`Design system synced. ${summary('Colours', report.colors)}. ${summary('Text styles', report.typography)}.`);
}

/** Flat, indented view of the selection so the UI can show and change each mapping. */
function outlineOf(selection) {
  const items = [];
  const walk = (node, depth) => {
    if (node.visible === false) return;
    const choice = widgetFor(node);
    items.push({ nodeId: node.id, name: String(node.name || '').slice(0, 60), type: node.type, depth, widget: choice.widget, source: choice.source });
    if (items.length > 200) return;
    for (const child of node.children || []) walk(child, depth + 1);
  };
  for (const node of selection) walk(node, 0);
  return items;
}

function postOutline() {
  const selection = figma.currentPage.selection;
  figma.ui.postMessage({ type: 'outline', items: selection.length ? outlineOf(selection) : [], selection: selection.map((n) => n.name) });
}

async function setWidgetOverride(nodeId, widget) {
  const node = figma.getNodeByIdAsync ? await figma.getNodeByIdAsync(nodeId) : figma.getNodeById(nodeId);
  if (!node || typeof node.setPluginData !== 'function') throw new Error('That layer can no longer be found.');
  // An empty value clears the override and hands the layer back to the heuristics.
  if (widget && !WIDGET_CHOICES.includes(widget)) throw new Error('Unsupported widget mapping.');
  node.setPluginData(WIDGET_KEY, widget || '');
  postOutline();
}

/** Best-effort page list; the import still works when it fails or is unavailable. */
async function loadPages() {
  await ensureConnection();
  const connection = await figma.clientStorage.getAsync(STORAGE_KEY);
  if (!connection?.baseUrl || !connection?.credential) return;
  try {
    const data = await request(connection.baseUrl, connection.credential, '/index.php?rest_route=/figma-elementor-multimodal/v1/pages');
    figma.ui.postMessage({ type: 'pages', pages: data.pages || [] });
  } catch (error) {
    figma.ui.postMessage({ type: 'pages', pages: [] });
  }
}

async function importSelection(pageId, target = 'elementor') {
  if (!/^[1-9][0-9]*$/.test(String(pageId || ''))) throw new Error('Choose the WordPress page to import into.');
  if (!['elementor', 'wordpress-blocks'].includes(target)) throw new Error('Choose a supported WordPress output editor.');
  await ensureConnection();
  const connection = await figma.clientStorage.getAsync(STORAGE_KEY);
  if (!connection?.baseUrl || !connection?.credential) throw new Error('Connect to WordPress first.');
  if (connection.expiresAt && Date.parse(connection.expiresAt) <= Date.now()) throw new Error('The WordPress connection expired. Pair again.');
  let selection = figma.currentPage.selection;
  const desktopId = responsiveDraft.desktop?.[0]?.tree.nodeId;
  if (desktopId) {
    const desktop = await figma.getNodeByIdAsync(desktopId);
    if (!desktop || desktop.removed) throw new Error('The captured desktop was deleted. Capture desktop again.');
    selection = [desktop];
  }
  if (!selection.length) throw new Error('Select a frame or component first.');
  post('Extracting selection…');
  const { document, assetPayloads } = await extractSelection(selection);
  post('Staging design…');
  const staged = await request(connection.baseUrl, connection.credential, '/index.php?rest_route=/figma-elementor-multimodal/v1/imports', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': uuid() }, body: JSON.stringify({ manifest: { assets: Object.values(document.assets) }, document }) });
  for (const asset of assetPayloads) {
    post(`Uploading asset ${asset.sha256.slice(0, 8)}…`);
    await request(connection.baseUrl, connection.credential, `/index.php?rest_route=/figma-elementor-multimodal/v1/imports/${staged.importId}/assets/${asset.sha256}`, { method: 'PUT', headers: { 'Content-Type': asset.mime, 'Idempotency-Key': asset.sha256 }, body: asset.bytes });
  }
  post('Committing snapshot…');
  const snapshot = await request(connection.baseUrl, connection.credential, `/index.php?rest_route=/figma-elementor-multimodal/v1/imports/${staged.importId}/commit`, { method: 'POST', headers: { 'Idempotency-Key': uuid(), 'If-Match': document.revisions.figmaRevision } });
  post('Linking the design to the page…');
  await request(connection.baseUrl, connection.credential, `/index.php?rest_route=/figma-elementor-multimodal/v1/pages/${pageId}/design`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ designId: snapshot.designId }) });

  post('Building native Elementor widgets…');
  if (!['elementor', 'wordpress-blocks'].includes(target)) throw new Error('Choose a supported WordPress output editor.');
  const built = await request(connection.baseUrl, connection.credential, `/index.php?rest_route=/figma-elementor-multimodal/v1/pages/${pageId}/elementor`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ designId: snapshot.designId, mode: 'append', target }) });
  const notes = (built.notes || []).length ? ` Notes: ${built.notes.join(' ')}` : '';
  announce(`Imported ${built.added} section(s) into the page. Open it in Elementor to see them.${notes}`);
  await loadPages();
}

/** Renews a still-valid bearer shortly before expiry; expired credentials require pairing again. */
async function ensureConnection() {
  if (renewalPromise) return renewalPromise;
  renewalPromise = renewConnection();
  try { return await renewalPromise; } finally { renewalPromise = null; }
}

async function renewConnection() {
  const connection = await figma.clientStorage.getAsync(STORAGE_KEY);
  if (!connection?.baseUrl || !connection?.credential || !connection.expiresAt) return connection;
  const expiresAt = Date.parse(connection.expiresAt);
  if (!Number.isFinite(expiresAt) || expiresAt - Date.now() > 30 * 60 * 1000) return connection;
  try {
    const data = await request(connection.baseUrl, connection.credential, '/index.php?rest_route=/figma-elementor-multimodal/v1/pairings/renew', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
    const renewed = { baseUrl: connection.baseUrl, credential: data.deviceCredential, expiresAt: data.expiresAt };
    await figma.clientStorage.setAsync(STORAGE_KEY, renewed);
    figma.ui.postMessage({ type: 'saved-connection', baseUrl: renewed.baseUrl, expiresAt: renewed.expiresAt });
    return renewed;
  } catch (error) {
    if (Date.now() >= expiresAt) throw new Error('The WordPress connection expired. Pair again.');
    return connection;
  }
}

// Keep the mapping list in step with whatever the designer selects on the canvas.
figma.on('selectionchange', postOutline);
postOutline();

figma.clientStorage.getAsync(STORAGE_KEY).then((connection) => {
  if (!connection?.baseUrl) return;
  figma.ui.postMessage({ type: 'saved-connection', baseUrl: connection.baseUrl, expiresAt: connection.expiresAt });
  loadPages();
});
const LONG_RUNNING = ['connect', 'import-selection', 'sync-design-system'];

figma.ui.onmessage = async (message) => {
  const tracked = LONG_RUNNING.includes(message.type);
  if (tracked) figma.ui.postMessage({ type: 'busy', busy: true });
  try {
    if (message.type === 'connect') await connect(message.config);
    if (message.type === 'import-selection') await importSelection(message.pageId, message.target);
    if (message.type === 'sync-design-system') await syncDesignSystem();
    if (message.type === 'get-outline') postOutline();
    if (message.type === 'set-widget') await setWidgetOverride(message.nodeId, message.widget);
    if (message.type === 'capture-responsive') captureResponsiveSelection(message.viewport);
    if (message.type === 'clear-responsive') {
      for (const viewport of ['desktop', 'tablet', 'mobile']) responsiveDraft[viewport] = null;
      figma.ui.postMessage({ type: 'responsive-cleared' });
    }
  } catch (error) {
    // Cross-realm rejections (e.g. a blocked fetch) can fail `instanceof Error`
    // in the plugin sandbox, so fall back to any message-like property instead
    // of collapsing everything to a generic "Operation failed."
    const raw = (error && (error.message || error.toString?.())) || String(error) || 'Operation failed.';
    announce(raw.replace(/Bearer\s+\S+/i, 'Bearer [redacted]'), true);
  } finally {
    if (tracked) figma.ui.postMessage({ type: 'busy', busy: false });
  }
};
