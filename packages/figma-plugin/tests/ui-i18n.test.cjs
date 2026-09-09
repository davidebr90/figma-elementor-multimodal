const { test } = require('node:test');
const assert = require('node:assert/strict');
const { pathToFileURL } = require('node:url');
const path = require('node:path');

const i18nModule = pathToFileURL(path.join(__dirname, '../src/ui/i18n.js')).href;

test('the Figma panel falls back to English for an unknown locale', async () => {
  const { createTranslator } = await import(i18nModule);

  const translate = createTranslator('fr-FR');

  assert.equal(translate('connect'), 'Connect to WordPress');
  assert.equal(translate('missing.key'), 'missing.key');
});

test('the Figma panel provides Italian labels', async () => {
  const { createTranslator } = await import(i18nModule);

  const translate = createTranslator('it-IT');

  assert.equal(translate('connect'), 'Connetti a WordPress');
  assert.equal(translate('importSelection'), 'Importa selezione');
  assert.equal(translate('noEditablePage'), 'Nessuna pagina modificabile trovata');
});
