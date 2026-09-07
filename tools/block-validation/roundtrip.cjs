const { JSDOM, VirtualConsole } = require('jsdom');
const virtualConsole = new VirtualConsole();
virtualConsole.on('jsdomError', error => { if (error.type !== 'css parsing') throw error; });
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test', virtualConsole });
for (const key of ['window','document','navigator','HTMLElement','Element','Node','MutationObserver','File','Blob','DOMParser']) Object.defineProperty(globalThis, key, {value:dom.window[key], configurable:true});
globalThis.requestAnimationFrame = callback => setTimeout(callback, 0);
globalThis.cancelAnimationFrame = clearTimeout;
window.matchMedia = () => ({ matches:false, addListener(){},removeListener(){},addEventListener(){},removeEventListener(){} });
globalThis.ResizeObserver = class { observe(){} unobserve(){} disconnect(){} };
const blocks = require('@wordpress/blocks');
const path = require('node:path');
const library = path.dirname(require.resolve('@wordpress/block-library/package.json'));
for (const name of ['group','heading','paragraph','buttons','button','separator','spacer']) {
  const metadata = require(path.join(library,'build',name,'block.json'));
  const save = require(path.join(library,'build',name,'save.cjs')).default;
  blocks.registerBlockType(metadata, {save});
}
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const html = execFileSync('php', [require('node:path').join(__dirname,'fixture.php')], {encoding:'utf8'});
const parsed = blocks.parse(html);
function check(list) { for(const block of list) { assert.equal(block.isValid,true,`${block.name} invalid`); check(block.innerBlocks); } }
check(parsed);
const saved = blocks.serialize(parsed);
const again = blocks.parse(saved);
check(again);
assert.equal(blocks.serialize(again),saved,'Second save must be stable');
function flatten(list) { return list.flatMap(block => [block, ...flatten(block.innerBlocks)]); }
const all = flatten(again);
assert.equal(all.length,7,'All six FEM nodes plus the buttons wrapper must survive');
for (const block of all.filter(block => block.name !== 'core/buttons')) {
  assert.ok(block.attributes.metadata.fem.nodeId, 'FEM identity must survive save');
  assert.ok(block.attributes.style['@mobile'], 'Responsive overrides must survive save');
}
const paragraph = all.find(block => block.name === 'core/paragraph');
assert.ok(String(paragraph.attributes.content).includes('\\ path'), 'Literal backslash survives');
paragraph.attributes.content = 'Edited in WordPress &amp; preserved';
const edited = blocks.parse(blocks.serialize(again));
check(edited);
assert.equal(String(flatten(edited).find(block => block.name === 'core/paragraph').attributes.content), paragraph.attributes.content);
assert.equal(all.find(block => block.name === 'core/button').attributes.url,'https://example.com/?a=1&b=2');
console.log('Gutenberg: 7 blocks valid; repeated save, text edit, links, FEM identity and responsive overrides preserved');
process.exit(0);
