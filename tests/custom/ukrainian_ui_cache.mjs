import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/v1/js/ff/ukrainian-ui-cache.js', import.meta.url), 'utf8');
const revisionKey = 'firefly-ukrainian-ui-revision';
const keys = ['i18next_res_uk-UA-translation', 'i18next_res_uk-translation'];
let checks = 0;
const check = (condition, message) => {assert.ok(condition, message); ++checks;};

function fixture() {
    const values = new Map([
        [keys[0], 'old Ukrainian translations'], [keys[1], 'old Ukrainian fallback'],
        ['i18next_res_en-US-translation', 'English translations'],
        ['i18next_res_uk-UA-another-namespace', 'Other namespace'],
        ['i18next_res_ukrainian-translation', 'Lookalike locale'],
        ['i18next_res_uk-UA-translation-personal', 'Lookalike key'],
        ['firefly-preferences', 'Original preferences'], ['ds_boxes_data', 'Financial cache'],
        ['session', 'Original session fixture'],
    ]);
    const removed = [];
    const storage = {
        getItem(key) {return values.get(key) ?? null;},
        removeItem(key) {removed.push(key); values.delete(key);},
        setItem(key, value) {values.set(key, value);},
    };
    const window = {localStorage: storage, store: Object.freeze({sentinel: 'Untouched store'})};
    return {values, removed, storage, window};
}

function run(window, revision = 'a'.repeat(64)) {
    vm.runInNewContext(source, {window, document: {currentScript: {dataset: {revision}}}});
}

check(source.includes('const revision = document.currentScript.dataset.revision;') && !source.includes('__UKRAINIAN_UI_REVISION__'), 'The native helper reads the actual synchronous-script content revision.');
const state = fixture();
const original = new Map(state.values);
run(state.window);
for (const key of keys) check(!state.values.has(key), 'The native Ukrainian translation cache is invalidated.');
for (const [key, value] of original) {
    if (!keys.includes(key)) check(state.values.get(key) === value, 'Preferences, sessions, financial caches and other translation keys are preserved.');
}
check(state.values.get(revisionKey) === 'a'.repeat(64), 'The current dictionary revision is recorded.');
check(state.removed.length === 2, 'Only the two verified native cache keys are removed.');
check(state.window.store.sentinel === 'Untouched store', 'The native state store is not modified.');
for (const language of ['uk-UA', 'uk']) {
    check(state.window.fireflyUkrainianUi.loadPath(language) === './v3/i18n/{{lng}}.json?revision=' + 'a'.repeat(64), 'A Ukrainian HTTP translation request gets the exact dictionary revision.');
    check(state.window.fireflyUkrainianUi.loadPath([language]) === state.window.fireflyUkrainianUi.loadPath(language), 'The native HttpBackend array signature is supported.');
}
for (const language of ['en-US', 'pl-PL', 'uk_UA', 'uk-UA-other', ['uk-UA', 'en-US'], [], undefined, null]) {
    check(state.window.fireflyUkrainianUi.loadPath(language) === './v3/i18n/{{lng}}.json', 'Other native URLs remain unchanged.');
}

for (const key of keys) state.values.set(key, 'Fresh Ukrainian translations');
run(state.window);
check(keys.every(key => state.values.get(key) === 'Fresh Ukrainian translations'), 'Reloading the same revision retains fresh translations.');
check(state.removed.length === 2, 'Invalidation happens only once for each revision.');
check(state.window.fireflyUkrainianUi.loadPath('uk') === './v3/i18n/{{lng}}.json?revision=' + 'a'.repeat(64), 'The URL helper is available even when storage invalidation returns early.');
run(state.window, 'b'.repeat(64));
check(keys.every(key => !state.values.has(key)), 'A changed dictionary invalidates Ukrainian translations again.');
check(state.values.get(revisionKey) === 'b'.repeat(64), 'The new revision is recorded.');

const unavailable = {};
Object.defineProperty(unavailable, 'localStorage', {get() {throw new Error('Storage is disabled.');}});
assert.doesNotThrow(() => run(unavailable)); ++checks;
check(unavailable.fireflyUkrainianUi.loadPath('uk-UA').includes('?revision='), 'The HTTP cache revision is available when localStorage is disabled.');
for (const method of ['getItem', 'removeItem', 'setItem']) {
    const failed = fixture();
    failed.storage[method] = () => {throw new Error('Storage operation failed.');};
    assert.doesNotThrow(() => run(failed.window)); ++checks;
    check(failed.values.get('firefly-preferences') === 'Original preferences', 'Storage failures do not alter preferences.');
    if (method !== 'setItem') check(!failed.values.has(revisionKey), 'An incomplete invalidation is retried on the next page.');
}
console.log(`Passed ${checks} Ukrainian translation cache checks.`);
