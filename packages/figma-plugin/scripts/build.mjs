import { build } from 'esbuild';
import { mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));
await mkdir(fileURLToPath(new URL('../dist/', import.meta.url)), { recursive: true });

await build({
  entryPoints: [`${root}/src/code.js`],
  outfile: `${root}/dist/code.js`,
  bundle: true,
  format: 'iife',
  platform: 'neutral',
  target: 'es2020',
  legalComments: 'none',
  sourcemap: false,
  minify: false,
});
