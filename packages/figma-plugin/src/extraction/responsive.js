export function responsiveOf(node) {
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
  } catch {
    return null;
  }
}

export function responsiveKey(node, index) {
  return `${index}:${String(node.name || '').trim().toLowerCase()}`;
}

export function findResponsiveMatch(captured, path, expectedType) {
  let match = captured?.tree;
  for (const name of path) match = match?.children?.find((child) => String(child.name || '').trim().toLowerCase() === name);
  return match && match.type === expectedType ? match : null;
}
