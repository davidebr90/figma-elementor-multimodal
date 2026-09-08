const { test } = require('node:test');
const assert = require('node:assert/strict');

test('responsive extractor ignores malformed plugin data and unknown viewports', async () => {
  const { responsiveOf } = await import('../src/extraction/responsive.js');
  assert.equal(responsiveOf({ getPluginData: () => '{broken' }), null);
  assert.deepEqual(responsiveOf({ getPluginData: () => JSON.stringify({ desktop: { gap: 8 }, watch: { gap: 1 } }) }), { desktop: { gap: 8 } });
});

test('responsive extractor flattens legacy layout style and text groups for renderers', async () => {
  const { responsiveOf } = await import('../src/extraction/responsive.js');
  const responsive = responsiveOf({ getPluginData: () => JSON.stringify({
    mobile: {
      layout: { padding: { top: 12, right: 16, bottom: 12, left: 16 }, direction: 'column' },
      style: { background: '#112233', radius: 8 },
      text: { fontSize: 18 },
      gap: 10,
    },
  }) });

  assert.deepEqual(responsive, {
    mobile: {
      padding: { top: 12, right: 16, bottom: 12, left: 16 },
      direction: 'column',
      background: '#112233',
      radius: 8,
      fontSize: 18,
      gap: 10,
    },
  });
});

test('responsive matcher follows normalized hierarchy and rejects a type change', async () => {
  const { findResponsiveMatch } = await import('../src/extraction/responsive.js');
  const captured = { tree: { type: 'FRAME', children: [{ name: 'Body', type: 'TEXT', children: [] }] } };
  assert.equal(findResponsiveMatch(captured, ['body'], 'TEXT').name, 'Body');
  assert.equal(findResponsiveMatch(captured, ['body'], 'FRAME'), null);
});
