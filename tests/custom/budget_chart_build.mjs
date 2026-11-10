import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const build = path.join(root, 'public/build');
const manifest = JSON.parse(fs.readFileSync(path.join(build, 'manifest.json'), 'utf8'));
const view = fs.readFileSync(path.join(root, 'resources/views/index.blade.php'), 'utf8');
const scripts = [...view.matchAll(/<script[^>]+src="v1\/js\/ff\/charts\.js[^>]+>/g)];
assert.equal(scripts.length, 1, 'The homepage must install its semantic palette exactly once.');
assert.doesNotMatch(scripts[0][0], /\s(?:async|defer|type=)/, 'The palette must parse before deferred dashboard modules.');
assert.match(scripts[0][0], /hash_file\('sha256'/, 'Semantic palette changes must revise the classic provider URL.');
const draw = Object.values(manifest).filter(entry => entry.name === 'draw-chart');
assert.equal(draw.length, 1);
assert.ok(draw[0].imports.some(key => manifest[key].name === 'format-money'));
let assets = 0;
for (const entry of Object.values(manifest)) {
    if (!entry.file.endsWith('.js')) continue;
    assets++;
    const source = fs.readFileSync(path.join(build, entry.file));
    assert.equal(entry.integrity, 'sha384-' + createHash('sha384').update(source).digest('base64'), 'Native JS must have matching SRI: ' + entry.file);
    for (const key of [...(entry.imports || []), ...(entry.dynamicImports || [])]) {
        assert.ok(manifest[key] && fs.existsSync(path.join(build, manifest[key].file)), 'Native imports must resolve: ' + key);
    }
}
execFileSync(process.execPath, [fileURLToPath(new URL('./budget_chart_colors.mjs', import.meta.url)), path.join(build, draw[0].file)], {stdio: 'inherit'});
console.log(`Passed homepage palette timing/cache wiring and ${assets} native JS SRI/import checks.`);
