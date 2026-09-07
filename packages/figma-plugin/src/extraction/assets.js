const SUPPORTED_EXPORTS = new Set(['image/png']);

export function assetIdFor(nodeId) {
  return `image-${String(nodeId)}`;
}

export function imageDescriptor(assetId, sha256, byteLength, mime = 'image/png') {
  if (!SUPPORTED_EXPORTS.has(mime)) throw new Error(`Unsupported exported asset format: ${mime}`);
  if (!/^[a-f0-9]{64}$/.test(String(sha256))) throw new Error('Exported asset hash is invalid.');
  if (!Number.isSafeInteger(byteLength) || byteLength < 0) throw new Error('Exported asset size is invalid.');
  return { assetId, kind: 'image', sha256, mime, byteLength };
}

export function imagePayload(sha256, bytes, mime = 'image/png') {
  if (!SUPPORTED_EXPORTS.has(mime)) throw new Error(`Unsupported exported asset format: ${mime}`);
  return { sha256, mime, bytes };
}
