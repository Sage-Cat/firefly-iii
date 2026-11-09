(function () {
    'use strict';

    // Native i18next uses its default translation namespace and cache prefix.
    const revision = document.currentScript.dataset.revision;
    const revisionKey = 'firefly-ukrainian-ui-revision';
    window.fireflyUkrainianUi = Object.freeze({
        loadPath(languages) {
            const language = Array.isArray(languages) ? (languages.length === 1 ? languages[0] : null) : languages;
            const original = './v3/i18n/{{lng}}.json';
            return language === 'uk-UA' || language === 'uk' ? original + '?revision=' + revision : original;
        },
    });
    try {
        const storage = window.localStorage;
        if (storage.getItem(revisionKey) === revision) {
            return;
        }
        storage.removeItem('i18next_res_uk-UA-translation');
        storage.removeItem('i18next_res_uk-translation');
        storage.setItem(revisionKey, revision);
    } catch (_) {
        // Translation loading still works when browser storage is unavailable.
    }
})();
