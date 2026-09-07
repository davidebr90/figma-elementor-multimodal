import {test} from 'node:test';
import assert from 'node:assert/strict';
import {normalizeSite, manifestFor} from '../src/core.js';
test('preserves subdirectory',()=>assert.equal(normalizeSite('https://example.com/wp/'), 'https://example.com/wp'));
for(const site of ['file:///a','https://user:secret@example.com','http://example.com','https://example.com/?token=a','https://example.com/#a']) test('rejects '+site,()=>assert.throws(()=>normalizeSite(site)));
for(const site of ['http://localhost:8096','http://127.0.0.1:8096','http://[::1]:8096']) test('loopback requires local mode '+site,()=>{assert.throws(()=>normalizeSite(site));assert.equal(normalizeSite(site,true),site)});
test('manifest restricts origins',()=>{const m=manifestFor('https://example.com/wp');assert.deepEqual(m.networkAccess.allowedDomains,['https://example.com']);assert.equal(m.enablePrivatePluginApi,undefined)});
