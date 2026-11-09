import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const manifest = JSON.parse(read('public/build/manifest.json'));
const entry = manifest['js/pages/dashboard/dashboard.js'];
const compiledFile = path.join(root, 'public/build', entry.file);
const compiled = fs.readFileSync(compiledFile, 'utf8');
assert.equal(entry.integrity, 'sha384-' + crypto.createHash('sha384').update(compiled).digest('base64'));
const source = read('resources/assets/v3/js/pages/dashboard/dashboard.js');
const legacy = read('public/v1/js/ff/index.js');
const view = read('resources/views/index.blade.php');
const scripts = read('resources/views/reports/dashboard-chart-period-scripts.blade.php');
const capital = read('resources/views/reports/dashboard-capital-note.blade.php');
for (const body of [compiled, source]) {
    execFileSync(process.execPath, ['--input-type=module', '--check'], {input: body, encoding: 'utf8'});
    assert.equal((body.match(/window\.fireflyDashboardPeriods\.url\(/g) || []).length, 2);
}
assert.equal((legacy.match(/window\.fireflyDashboardPeriods\.url\(/g) || []).length, 3);
assert.equal((view.match(/@include\('reports.dashboard-chart-period',/g) || []).length, 4);
assert.equal((capital.match(/@include\('reports.dashboard-chart-period',/g) || []).length, 1);
assert.equal((view.match(/class="dashboard-period-chart card-body p-0"/g) || []).length, 4);
assert.equal((view.match(/class="dashboard-period-canvas"/g) || []).length, 4);
for (const chart of ['budget', 'category', 'expense', 'revenue']) {
    assert.ok(view.includes("'chart' => '" + chart + "'"));
}
assert.ok(view.indexOf("@include('reports.dashboard-chart-period-scripts')") < view.indexOf('@vite'));
assert.equal((scripts.match(/nonce="\{\{ \$JS_NONCE \}\}"/g) || []).length, 3);
assert.ok(scripts.includes('DashboardChartPeriods::all()'));
assert.ok(scripts.includes('height: 400px') && scripts.includes('height: 280px'));
assert.ok(scripts.includes('.dashboard-period-canvas > canvas { position: absolute;'));
for (const [body, asset] of [[scripts, 'dashboard-chart-periods'], [view, 'index']]) {
    assert.ok(body.includes("asset('v1/js/ff/" + asset + ".js')"));
    assert.ok(body.includes("hash_file('sha256', public_path('v1/js/ff/" + asset + ".js'))"));
    assert.ok(body.includes("hash_file('sha384', public_path('v1/js/ff/" + asset + ".js'), true)"));
    assert.ok(body.includes('crossorigin="anonymous"'));
}
assert.ok(!scripts.includes('__DASHBOARD_') && !scripts.includes(' defer') && !scripts.includes(' async'));
execFileSync(process.execPath, [path.join(root, 'tests/custom/dashboard_history_url.mjs'), compiledFile,
    path.join(root, 'public/v1/js/ff/index.js')], {stdio: 'inherit'});
console.log('Passed native dashboard-period wiring: five chart URL callers, five selectors, bounded wrappers and native source asset SRI.');
