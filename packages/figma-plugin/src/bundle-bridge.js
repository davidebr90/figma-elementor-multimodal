import { layoutOf } from './extraction/layout.js';
import { solidColor } from './extraction/paint.js';

globalThis.__femLayoutOf = layoutOf;
globalThis.__femSolidColor = (paints) => solidColor(paints, (color) => {
  const channel = (key) => Math.round(Math.max(0, Math.min(1, Number(color[key]) || 0)) * 255);
  return `#${channel('r').toString(16).padStart(2, '0')}${channel('g').toString(16).padStart(2, '0')}${channel('b').toString(16).padStart(2, '0')}`;
});
