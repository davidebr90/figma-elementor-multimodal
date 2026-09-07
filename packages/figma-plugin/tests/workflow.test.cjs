const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

test('source manifest uses only public APIs and keeps wildcard access explicit for development', () => {
  const manifest = JSON.parse(readFileSync(require('node:path').join(__dirname, '../manifest.json'), 'utf8'));
  assert.deepEqual(manifest.networkAccess.allowedDomains, ['*']);
  assert.equal(manifest.enablePrivatePluginApi, undefined);
  assert.ok(manifest.networkAccess.reasoning.includes('WordPress'));
  assert.ok(manifest.networkAccess.devAllowedDomains.includes('http://127.0.0.1:8096'));
});

test('Figma canonical hashing matches the PHP import-boundary fixture', async () => {
  const { context } = harness(undefined, null);
  const document = JSON.parse(readFileSync(require('node:path').join(__dirname, '../../wordpress-plugin/tests/fixtures/valid-minimal.json'), 'utf8'));
  const expected = document.integrity.contentHash;
  document.integrity.contentHash = '0'.repeat(64);
  context.femHashFixture = document;

  const actual = await vm.runInContext('digest(utf8Bytes(stable(femHashFixture)))', context);

  assert.equal(actual, expected);
});

function harness(fetchImpl, initialConnection = { baseUrl: 'https://example.test', credential: 'test' }) {
  const calls = [];
  const messages = [];
  const figma = {
    showUI() {}, on() {}, notify() {},
    currentPage: { selection: [] },
    clientStorage: { getAsync: async () => initialConnection, setAsync: async () => {} },
    ui: { postMessage: message => messages.push(message) },
    variables: {
      getLocalVariableCollectionsAsync: async () => [{ id: 'colors', defaultModeId: 'day' }],
      getLocalVariablesAsync: async () => [{ name: 'Brand', variableCollectionId: 'colors', valuesByMode: { day: { r: 1, g: 0, b: 0 } } }],
    },
  };
  const fetch = fetchImpl || (async (url, init) => {
    calls.push({ url, init });
    return { ok: true, json: async () => ({ success: true, data: {
      pages: [], colors: { created: ['Brand'], updated: [] }, typography: { created: [], updated: [] },
    } }) };
  });
  const context = vm.createContext({ figma, __html__: '', fetch, AbortController, setTimeout, clearTimeout });
  vm.runInContext(readFileSync(require('node:path').join(__dirname, '../src/code.js'), 'utf8'), context);
  return { context, calls, messages };
}

test('network errors are normalized for a recoverable Figma UI message', async () => {
  const { context } = harness(async () => { throw new Error('socket closed'); });

  await assert.rejects(vm.runInContext("request('https://example.test', 'credential', '/imports')", context), /Network request failed/);
});

test('supported runtimes abort a hung network request', async () => {
  const { context } = harness((url, init) => new Promise((resolve, reject) => {
    init.signal.addEventListener('abort', () => reject(new Error('aborted')));
  }), null);

  await assert.rejects(vm.runInContext("request('https://example.test', 'credential', '/imports', { timeoutMs: 10 })", context), /timed out/);
});

test('captured mobile root reaches the renderer contract during extraction', async () => {
  const { context } = harness();
  const frame = (id, padding) => ({ id, name: 'Hero', type: 'FRAME', children: [], layoutMode: 'VERTICAL', paddingTop: padding, width: 390, height: 200 });
  context.figma.currentPage.selection = [frame('mobile', 12)];
  vm.runInContext("captureResponsiveSelection('mobile')", context);
  context.figma.currentPage.selection = [frame('desktop', 48)];
  const result = await vm.runInContext('extractSelection(figma.currentPage.selection)', context);
  const root = result.document.nodes[result.document.roots[0]];
  assert.equal(root.responsive.mobile.padding.top, 12);
  assert.equal(root.responsive.mobile.direction, 'column');
  assert.equal(root.layout.padding.top, 48);
});

test('v1 extraction preserves source identity, node identity and responsive deltas', async () => {
  const { context } = harness();
  const frame = {
    id: 'desktop-root', name: 'Hero', type: 'FRAME', children: [], layoutMode: 'VERTICAL',
    width: 1200, height: 500, paddingTop: 48, paddingRight: 24, paddingBottom: 48, paddingLeft: 24,
  };
  context.figma.currentPage.selection = [frame];
  const result = await vm.runInContext('extractSelection(figma.currentPage.selection)', context);

  assert.equal(result.document.schemaVersion, '1.0.0');
  assert.equal(result.document.source.provider, 'figma');
  assert.equal(result.document.roots.length, 1);
  assert.equal(result.document.nodes[result.document.roots[0]].layout.padding.top, 48);
  assert.match(result.document.nodes[result.document.roots[0]].id, /^urn:fem:figma:/);
});

test('site-wide token sync succeeds without a selected WordPress page', async () => {
  const { context, calls } = harness();
  await vm.runInContext('syncDesignSystem()', context);
  assert.equal(calls.filter(call => call.url.endsWith('/design-system')).length, 1);
});

test('responsive children match by hierarchy when sibling order changes', async () => {
  const { context } = harness();
  const child = (id, name, padding) => ({ id, name, type: 'FRAME', children: [], layoutMode: 'VERTICAL', paddingTop: padding });
  const root = (id, children) => ({ id, name: 'Hero', type: 'FRAME', layoutMode: 'VERTICAL', children });
  context.figma.currentPage.selection = [root('m', [child('mb', 'Body', 9), child('ma', 'Title', 17)])];
  vm.runInContext("captureResponsiveSelection('mobile')", context);
  context.figma.currentPage.selection = [root('d', [child('da', 'Title', 40), child('db', 'Body', 30)])];
  const { document } = await vm.runInContext('extractSelection(figma.currentPage.selection)', context);
  assert.equal(document.nodes['urn:fem:figma:local-file:da'].responsive.mobile.padding.top, 17);
  assert.equal(document.nodes['urn:fem:figma:local-file:db'].responsive.mobile.padding.top, 9);
});

test('ambiguous sibling names prevent capture', () => {
  const { context } = harness();
  context.figma.currentPage.selection = [{ id: 'r', name: 'Hero', type: 'FRAME', children: [
    { id: 'a', name: 'Card', type: 'FRAME' }, { id: 'b', name: 'Card', type: 'FRAME' },
  ] }];
  assert.throws(() => vm.runInContext("captureResponsiveSelection('mobile')", context), /duplicate sibling/);
});

test('all skipped roots are rejected before staging', async () => {
  const { context } = harness();
  context.figma.currentPage.selection = [{ id: 'skip', type: 'FRAME', getPluginData: key => key === 'fem-widget' ? 'skip' : '' }];
  await assert.rejects(vm.runInContext('extractSelection(figma.currentPage.selection)', context), /All selected layers are skipped/);
});

test('a malformed stored widget override cannot create an unsupported server widget', () => {
  const { context } = harness();
  context.malformedOverrideNode = { type: 'FRAME', name: 'Section', children: [], getPluginData: () => 'arbitrary-script' };

  const choice = vm.runInContext('widgetFor(malformedOverrideNode)', context);

  assert.notEqual(choice.widget, 'arbitrary-script');
  assert.equal(choice.source, 'heuristic');
});

test('long text is preserved without silent truncation', async () => {
  const { context } = harness();
  context.textNode = { characters: 'a'.repeat(1500), fills: [] };
  const result = await vm.runInContext('textOf(textNode)', context);
  assert.equal(result.characters.length, 1500);
});

test('missing destination is rejected before extraction or staging', async () => {
  const { context, calls } = harness();
  await assert.rejects(vm.runInContext('importSelection(null)', context), /Choose the WordPress page/);
  assert.equal(calls.filter(call => call.url.endsWith('/imports')).length, 0);
});

for (const baseUrl of ['http://localhost:8096', 'http://127.0.0.1:8096', 'http://[::1]:8096', 'http://localhost:8096/wp']) {
  test('pairing reaches the configured loopback site: ' + baseUrl, async () => {
    const {context,calls}=harness();
    context.connectionConfig={baseUrl,pairingId:'test-id',code:'test-code'};
    await vm.runInContext('connect(connectionConfig)',context);
    assert.ok(calls.some(call=>call.url===baseUrl+'/index.php?rest_route=/figma-elementor-multimodal/v1/pairings/exchange'));
  });
}
