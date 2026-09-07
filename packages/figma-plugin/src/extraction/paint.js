export function solidColor(paints, hex) {
  if (!Array.isArray(paints)) return null;
  const paint = [...paints].reverse().find((item) => item.type === 'SOLID' && item.visible !== false);
  if (!paint || !paint.color) return null;
  const alpha = paint.opacity === undefined ? 1 : paint.opacity;
  if (alpha >= 1) return hex(paint.color);
  const channel = (key) => Math.round(Math.max(0, Math.min(1, Number(paint.color[key]) || 0)) * 255);
  return `rgba(${channel('r')}, ${channel('g')}, ${channel('b')}, ${Math.round(alpha * 100) / 100})`;
}
