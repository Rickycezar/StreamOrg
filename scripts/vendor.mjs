// Copies the built files of the browser libraries into public/assets/vendor/.
//
// There is no bundler: app.js and app.css are served as written, and these
// files sit next to them. The copies are kept in the project, so running
// the app never needs Node — only updating a library does
// (`npm update && npm run vendor`).

import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out  = join(root, 'public', 'assets', 'vendor');

const files = [
    ['@hotwired/turbo/dist/turbo.es2017-umd.js', 'turbo.js'],
    ['tom-select/dist/js/tom-select.complete.min.js', 'tom-select.js'],
    ['tom-select/dist/css/tom-select.min.css', 'tom-select.css'],
    ['fullcalendar/index.global.min.js', 'fullcalendar.js'],
    ['@fullcalendar/core/locales/pt-br.global.min.js', 'fullcalendar-pt-br.js'],
];

mkdirSync(out, { recursive: true });

const versions = {};

for (const [from, to] of files) {
    copyFileSync(join(root, 'node_modules', from), join(out, to));

    const pkg = from.startsWith('@') ? from.split('/').slice(0, 2).join('/') : from.split('/')[0];
    versions[pkg] = JSON.parse(readFileSync(join(root, 'node_modules', pkg, 'package.json'), 'utf8')).version;

    console.log(`  ${from} -> public/assets/vendor/${to}`);
}

writeFileSync(join(out, 'VERSIONS.json'), JSON.stringify(versions, null, 2) + '\n');
