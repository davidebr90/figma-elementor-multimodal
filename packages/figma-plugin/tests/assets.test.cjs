const { test } = require('node:test');
const assert = require('node:assert/strict');

test('asset extractor creates deterministic PNG descriptors and payloads', async () => {
  const { assetIdFor, imageDescriptor, imagePayload } = await import('../src/extraction/assets.js');
  const hash = 'a'.repeat(64);
  assert.equal(assetIdFor('node/42'), 'image-node/42');
  assert.deepEqual(imageDescriptor('image-node/42', hash, 12), { assetId: 'image-node/42', kind: 'image', sha256: hash, mime: 'image/png', byteLength: 12 });
  assert.deepEqual(imagePayload(hash, new Uint8Array([1, 2])), { sha256: hash, mime: 'image/png', bytes: new Uint8Array([1, 2]) });
});

test('asset extractor rejects unsupported formats, malformed hashes and sizes', async () => {
  const { imageDescriptor } = await import('../src/extraction/assets.js');
  assert.throws(() => imageDescriptor('image-x', 'bad', 1), /hash is invalid/);
  assert.throws(() => imageDescriptor('image-x', 'a'.repeat(64), -1), /size is invalid/);
  assert.throws(() => imageDescriptor('image-x', 'a'.repeat(64), 1, 'image/svg+xml'), /Unsupported/);
});
