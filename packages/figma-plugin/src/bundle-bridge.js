import { layoutOf } from './extraction/layout.js';
import { solidColor } from './extraction/paint.js';
import { weightOf } from './extraction/typography.js';
import { responsiveOf, responsiveKey, findResponsiveMatch } from './extraction/responsive.js';
import { assetIdFor, imageDescriptor, imagePayload } from './extraction/assets.js';

globalThis.__femLayoutOf = layoutOf;
globalThis.__femSolidColor = (paints) => solidColor(paints, (color) => {
  const channel = (key) => Math.round(Math.max(0, Math.min(1, Number(color[key]) || 0)) * 255);
  return `#${channel('r').toString(16).padStart(2, '0')}${channel('g').toString(16).padStart(2, '0')}${channel('b').toString(16).padStart(2, '0')}`;
});
globalThis.__femWeightOf = weightOf;
globalThis.__femResponsiveOf = responsiveOf;
globalThis.__femResponsiveKey = responsiveKey;
globalThis.__femFindResponsiveMatch = findResponsiveMatch;
globalThis.__femAssetIdFor = assetIdFor;
globalThis.__femImageDescriptor = imageDescriptor;
globalThis.__femImagePayload = imagePayload;
