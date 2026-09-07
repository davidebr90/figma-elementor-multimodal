const { test } = require('node:test');
const assert = require('node:assert/strict');

test('layout extractor preserves the current flex and padding contract', async () => {
  const { layoutOf } = await import('../src/extraction/layout.js');
  assert.deepEqual(layoutOf({ width: 640, height: 240, layoutMode: 'HORIZONTAL', itemSpacing: 16, paddingTop: 12, paddingRight: 8, paddingBottom: 12, paddingLeft: 8 }), {
    width: 640,
    height: 240,
    mode: 'flex',
    direction: 'row',
    gap: 16,
    wrap: false,
    padding: { top: 12, right: 8, bottom: 12, left: 8 },
    justify: 'MIN',
    align: 'MIN',
  });
});

test('paint extractor uses the visible top paint and preserves alpha', async () => {
  const { solidColor } = await import('../src/extraction/paint.js');
  const color = (value) => `#${Math.round(value.r * 255).toString(16).padStart(2, '0')}${Math.round(value.g * 255).toString(16).padStart(2, '0')}${Math.round(value.b * 255).toString(16).padStart(2, '0')}`;
  assert.equal(solidColor([
    { type: 'SOLID', visible: true, color: { r: 1, g: 0, b: 0 } },
    { type: 'SOLID', visible: true, opacity: 0.5, color: { r: 0, g: 0.5, b: 1 } },
  ], color), 'rgba(0, 128, 255, 0.5)');
});
