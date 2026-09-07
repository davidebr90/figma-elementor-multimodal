const { test } = require('node:test');
const assert = require('node:assert/strict');

test('typography extractor maps Figma weight names to Elementor numeric weights', async () => {
  const { weightOf } = await import('../src/extraction/typography.js');
  assert.equal(weightOf('Extra Bold Italic'), '800');
  assert.equal(weightOf('Regular'), '400');
  assert.equal(weightOf('unknown style'), '400');
});
