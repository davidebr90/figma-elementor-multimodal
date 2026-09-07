const { test } = require('node:test');
const assert = require('node:assert/strict');

test('responsive extractor ignores malformed plugin data and unknown viewports', async () => {
  const { responsiveOf } = await import('../src/extraction/responsive.js');
  assert.equal(responsiveOf({ getPluginData: () => '{broken' }), null);
  assert.deepEqual(responsiveOf({ getPluginData: () => JSON.stringify({ desktop: { gap: 8 }, watch: { gap: 1 } }) }), { desktop: { gap: 8 } });
});

test('responsive matcher follows normalized hierarchy and rejects a type change', async () => {
  const { findResponsiveMatch } = await import('../src/extraction/responsive.js');
  const captured = { tree: { type: 'FRAME', children: [{ name: 'Body', type: 'TEXT', children: [] }] } };
  assert.equal(findResponsiveMatch(captured, ['body'], 'TEXT').name, 'Body');
  assert.equal(findResponsiveMatch(captured, ['body'], 'FRAME'), null);
});
