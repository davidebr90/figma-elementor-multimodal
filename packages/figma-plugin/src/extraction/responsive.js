export function responsiveOf(node) {
  if (typeof node.getPluginData !== 'function') return null;
  const raw = node.getPluginData('fem-responsive');
  if (!raw) return null;
  try {
    const value = JSON.parse(raw);
    if (!value || typeof value !== 'object') return null;
    const clean = {};
    for (const viewport of ['desktop', 'tablet', 'mobile']) {
      if (value[viewport] && typeof value[viewport] === 'object') clean[viewport] = normalizeViewport(value[viewport]);
    }
    return Object.keys(clean).length ? clean : null;
  } catch {
    return null;
  }
}

function normalizeViewport(values) {
  const layout = isObject(values.layout) ? values.layout : {};
  const style = isObject(values.style) ? values.style : {};
  const text = isObject(values.text) ? values.text : {};
  const direct = { ...values };
  delete direct.layout;
  delete direct.style;
  delete direct.text;

  // The capture workflow emits one flat contract. Flatten the original grouped
  // plugin-data shape as well, so both authoring paths reach the renderers.
  // Direct keys deliberately win to let a newer explicit override supersede a
  // legacy grouped value without ambiguity.
  return { ...layout, ...style, ...text, ...direct };
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export function responsiveKey(node, index) {
  return `${index}:${String(node.name || '').trim().toLowerCase()}`;
}

export function findResponsiveMatch(captured, path, expectedType) {
  let match = captured?.tree;
  for (const name of path) match = match?.children?.find((child) => String(child.name || '').trim().toLowerCase() === name);
  return match && match.type === expectedType ? match : null;
}
