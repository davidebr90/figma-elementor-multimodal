export function layoutOf(node) {
  const layout = { width: Math.round(node.width || 0), height: Math.round(node.height || 0) };
  if (node.layoutMode && node.layoutMode !== 'NONE') {
    layout.mode = node.layoutMode === 'GRID' ? 'grid' : 'flex';
    layout.direction = node.layoutMode === 'HORIZONTAL' ? 'row' : 'column';
    layout.gap = typeof node.itemSpacing === 'number' ? Math.round(node.itemSpacing) : 0;
    if (typeof node.counterAxisSpacing === 'number') layout.rowGap = Math.round(node.counterAxisSpacing);
    layout.wrap = node.layoutWrap === 'WRAP';
    layout.padding = { top: Math.round(node.paddingTop || 0), right: Math.round(node.paddingRight || 0), bottom: Math.round(node.paddingBottom || 0), left: Math.round(node.paddingLeft || 0) };
    layout.justify = node.primaryAxisAlignItems || 'MIN';
    layout.align = node.counterAxisAlignItems || 'MIN';
    if (node.layoutMode === 'GRID') {
      layout.columns = Array.isArray(node.gridColumnSizes) ? node.gridColumnSizes.length : (node.gridColumnCount || null);
      layout.rows = Array.isArray(node.gridRowSizes) ? node.gridRowSizes.length : (node.gridRowCount || null);
    }
  }
  if (node.layoutSizingHorizontal) layout.sizingH = node.layoutSizingHorizontal;
  if (node.layoutSizingVertical) layout.sizingV = node.layoutSizingVertical;
  return layout;
}
