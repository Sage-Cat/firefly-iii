import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import vm from 'node:vm';

const root = path.resolve(fileURLToPath(new URL('../..', import.meta.url)));
const assetRoot = process.argv[2] || root;
const revisionFile = path.join(assetRoot, 'revision.txt');
const revision = fs.existsSync(revisionFile) ? fs.readFileSync(revisionFile, 'utf8').trim() : 'a'.repeat(64);
assert.match(revision, /^[a-f0-9]{64}$/);
const i18next = (await import('i18next')).default;
const HttpBackend = (await import('i18next-http-backend')).default;
let checks = 0;
const check = (condition, message) => {assert.ok(condition, message); ++checks;};

for (const language of ['uk-UA', 'uk']) {
    const data = JSON.parse(fs.readFileSync(path.join(assetRoot, 'public/v3/i18n', language + '.json'), 'utf8'));
    check(Object.hasOwn(data, 'firefly') && Object.hasOwn(data, 'form') && Object.hasOwn(data, 'list') && !Object.hasOwn(data, 'translation'), 'The fetched static asset has the native default-namespace shape.');
    const translator = i18next.createInstance();
    await translator.init({lng: language, fallbackLng: false, resources: {[language]: {translation: data}}});
    check(translator.t('firefly.save_transaction_working') === 'Збереження транзакції. Зачекайте.', 'The actual runtime save-state label comes from the corrected static asset.');
    check(translator.t('firefly.save_links_working') === 'Збереження зв’язків між транзакціями. Зачекайте.', 'The actual runtime link-save label is Ukrainian.');
    check(translator.t('firefly.account_type_Cash account') === 'Готівка', 'The account-type field uses the corrected runtime translation.');
    check(translator.t('firefly.release_notes_title', {version: '6.7.9'}) === 'Що нового у Firefly III 6.7.9', 'Version interpolation works in the real i18next runtime.');
    check(translator.t('firefly.filter_placeholder_name') === 'Пошук за назвою…', 'The filter placeholder uses the corrected static asset.');
    check(translator.t('form.title') === 'Назва' && translator.t('list.name') === 'Назва', 'Existing form and list field labels remain available.');
    check(translator.t('form.from_currency_to_currency', {from: '€', to: 'грн'}) === '€ → грн', 'Unrelated native currency placeholders still render.');
    check(translator.t('firefly.stored_journal_js', {description: 'Sample payment'}).includes('Sample payment'), 'Existing stored-payment interpolation remains available.');
}
const context = {window: {}, document: {currentScript: {dataset: {revision}}}};
const helperSource = fs.readFileSync(new URL('../../public/v1/js/ff/ukrainian-ui-cache.js', import.meta.url), 'utf8');
vm.runInNewContext(helperSource, context);
let nativeOptions;
context.i18next = {use() {return {init(options) {nativeOptions = options; return Promise.resolve();}};}};
context.ChainedBackend = class {};
context.LocalStorageBackend = class {};
context.HttpBackend = HttpBackend;
const nativeLoader = fs.readFileSync(path.join(root, 'resources/assets/v3/js/support/load-translations.js'), 'utf8')
    .replace(/^import .*;$/gm, '').replace(/^export .*;$/gm, '');
vm.runInNewContext(nativeLoader + "\nloadTranslations('uk-UA', 'uk_UA');", context);
check(nativeOptions.backend.backends[0] === context.LocalStorageBackend && nativeOptions.backend.backends[1] === HttpBackend,
    'The native chained backend order remains LocalStorage then HTTP.');
check(nativeOptions.backend.backendOptions[0].loadPath === './v3/i18n/{{lng}}.json', 'The LocalStorage backend path stays unchanged.');
for (const language of ['uk-UA', 'uk', 'en-US', 'uk_UA']) {
    const translator = i18next.createInstance();
    await translator.init({lng: language, fallbackLng: false});
    let requestedUrl;
    let nativeArguments;
    const backend = new HttpBackend({interpolator: translator.services.interpolator}, {
        loadPath(languages, namespaces) {
            nativeArguments = [languages, namespaces];
            return nativeOptions.backend.backendOptions[1].loadPath(languages);
        },
        request(_options, url, _payload, callback) {
            requestedUrl = url;
            callback(null, {status: 200, data: '{"fixture":"translated"}'});
        },
    });
    await new Promise((resolve, reject) => backend.read(language, 'translation', (error, data) => error ? reject(error) : resolve(data)));
    check(Array.isArray(nativeArguments[0]) && nativeArguments[0][0] === language && nativeArguments[1][0] === 'translation', 'The actual HttpBackend invokes the native array signature.');
    const expected = './v3/i18n/' + language + '.json' + (['uk-UA', 'uk'].includes(language) ? '?revision=' + revision : '');
    check(requestedUrl === expected, 'The actual HttpBackend versions only the two Ukrainian static resource URLs.');
}
console.log(`Passed ${checks} native static Ukrainian i18next runtime checks.`);
