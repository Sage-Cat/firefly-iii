<?php

declare(strict_types=1);

use FireflyIII\Support\UkrainianUi;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;

$root           = dirname(__DIR__, 2);
$dependencyRoot = getenv('FIREFLY_TEST_APP_ROOT') ?: $root;
require $dependencyRoot . '/vendor/autoload.php';
require_once $root . '/app/Support/UkrainianUi.php';

$checks    = 0;
$overrides = [];
foreach (['firefly', 'form', 'list', 'interface'] as $group) {
    $overrides[$group] = require $root . '/resources/lang-overrides/uk_UA/' . $group . '.php';
}
$interfaceFallbacks = require $root . '/resources/lang-overrides/en_US/interface.php';
$nativePath         = is_dir($root . '/resources/lang/uk_UA') ? $root . '/resources/lang' : $dependencyRoot . '/resources/lang';
$loader             = new FileLoader(new Filesystem(), [$nativePath, $root . '/resources/lang']);
$loader->addPath($root . '/resources/lang-overrides');

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$GLOBALS['checks'];
}

function placeholders(string $text): array
{
    preg_match_all('/(?<![A-Za-z0-9_]):[A-Za-z][A-Za-z0-9_]*|\{\{?[A-Za-z][A-Za-z0-9_]*\}?\}/u', $text, $matches);
    sort($matches[0]);

    return $matches[0];
}

check(36 === array_sum(array_map('count', $overrides)), 'The reviewed scope contains exactly 36 Ukrainian labels.');
foreach ($overrides as $group => $lines) {
    $native  = 'interface' === $group ? [] : (require $nativePath . '/uk_UA/' . $group . '.php');
    $english = 'interface' === $group ? $interfaceFallbacks : (require $root . '/resources/lang/en_US/' . $group . '.php');
    $actual  = $loader->load('uk_UA', $group);
    check($actual === array_replace_recursive($native, $lines), 'The native loader merges only the reviewed Ukrainian labels.');
    foreach ($lines as $key => $value) {
        check(isset($english[$key]) && is_string($english[$key]), 'Each override targets an existing English translation key.');
        check(is_string($value) && '' !== trim($value) && $value === trim($value) && 1 === preg_match('//u', $value), 'Labels are nonempty trimmed UTF-8.');
        check(placeholders($english[$key]) === placeholders($value), 'Every native interpolation placeholder is preserved.');
        check($actual[$key] === $value, 'The reviewed label is available to native translation consumers.');
    }
    check($loader->load('en_US', $group) === $english, 'English labels and interface fallbacks remain unchanged.');
}
check(
    $interfaceFallbacks === ['main_navigation' => 'Main navigation', 'payoff_by_date' => 'pay-off by date', 'content_placeholder' => 'Content'],
    'Static labels retain their original English fallback.'
);

$translator = new Translator($loader, 'uk_UA');
$translator->setFallback('en_US');
check($translator->get('firefly.total_available_budget_in_currency', ['currency' => 'Українська гривня'])
=== 'Загальний доступний бюджет · Українська гривня', 'The budget title preserves currency metadata.');
check($translator->get('firefly.spent_between_left', ['start' => '01.11', 'end' => '30.11', 'spent' => '100 грн', 'left' => '900 грн'])
=== 'Витрачено 100 грн за період 01.11 — 30.11. Залишок: 900 грн.', 'Amount and date placeholders remain available.');
check($translator->get('form.delete_account', ['name' => 'Sample account'])
=== 'Видалити рахунок «Sample account»', 'A stored account name is inserted unchanged.');
check($translator->get('firefly.release_notes_title') === 'Що нового у Firefly III {{version}}', 'JavaScript interpolation remains intact.');
check($translator->get('interface.main_navigation') === 'Основна навігація', 'The sidebar has a Ukrainian navigation label.');
check(
    $translator->get('interface.payoff_by_date') === 'Погашення до вказаної дати' && $translator->get('interface.content_placeholder') === 'Вміст',
    'The liability stub has translated labels.'
);
$englishTranslator = new Translator($loader, 'en_US');
check($englishTranslator->get('interface.main_navigation') === 'Main navigation', 'English interface labels are preserved.');
$nativeTranslator = new Translator(new FileLoader(new Filesystem(), $nativePath), 'uk_UA');
foreach ([0, 1, 2, 5, 21] as $count) {
    check(
        $translator->choice('firefly.budget_or_budgets', $count) === $nativeTranslator->choice('firefly.budget_or_budgets', $count),
        'Unrelated Ukrainian plurals remain unchanged.'
    );
}
$translator->addLines(['synthetic.fallback' => 'English fallback fixture'], 'en_US');
check($translator->get('synthetic.fallback') === 'English fallback fixture', 'Missing Ukrainian labels retain the English fallback.');

foreach ([
    'resources/views/components/layout/sidebar.blade.php' => ['interface.main_navigation'],
    'resources/views/budgets/index.blade.php'             => ['firefly.budgeted'],
    'resources/views/accounts/show.blade.php'             => ['interface.payoff_by_date', 'interface.content_placeholder']
] as $relative => $keys) {
    $source = file_get_contents($root . '/' . $relative);
    foreach ($keys as $key) {
        check(str_contains($source, "__('" . $key . "')"), 'Native static labels use the locale-aware translator.');
    }
}
$provider = file_get_contents($root . '/app/Providers/AppServiceProvider.php');
check(
    1 === substr_count($provider, "\$this->app['translation.loader']->addPath(resource_path('lang-overrides'));"),
    'The provider registers one override path.'
);
$script = file_get_contents($root . '/public/v1/js/ff/ukrainian-ui-cache.js');
check(
    str_contains($script, 'const revision = document.currentScript.dataset.revision;') && !str_contains($script, '__UKRAINIAN_UI_REVISION__'),
    'The helper uses the actual revision supplied by the synchronous native layout.'
);
foreach (['session', 'auth', 'blank', 'debug', 'flush', 'install'] as $layout) {
    $source = file_get_contents($root . '/resources/views/layout/v3/' . $layout . '.blade.php');
    check(1 === substr_count($source, '{{-- ukrainian-ui --}}'), 'Each native layout loads cache invalidation once.');
    check(1 === preg_match('/<script src="[^\n]+ukrainian-ui-cache\.js[^\n]+<\/script>/', $source, $matches), 'The native invalidation script is present.');
    $tag = $matches[0];
    check(
        strpos($source, $tag) < strpos($source, '<script nonce=') && (!str_contains($source, '@vite') || strpos($source, $tag) < strpos($source, '@vite')),
        'Invalidation precedes inline and Vite translation consumers.'
    );
    check(
        str_contains($tag, 'nonce="{{ $JS_NONCE ?? \'\' }}"') && str_contains($tag, "hash_file('sha384', public_path('v1/js/ff/ukrainian-ui-cache.js'), true)"),
        'The native script preserves CSP nonce and dynamic SRI.'
    );
    check(
        str_contains($tag, "substr(hash_file('sha256', public_path('v1/js/ff/ukrainian-ui-cache.js')), 0, 12)"),
        'The browser asset URL follows its checked content hash.'
    );
    check(
        str_contains($tag, 'data-revision="{{ \\FireflyIII\\Support\\UkrainianUi::revision() }}"'),
        'The synchronous script receives the actual dictionary and JSON revision.'
    );
    check(!str_contains($tag, ' defer') && !str_contains($tag, ' async'), 'Invalidation executes synchronously.');
}

$temporary = sys_get_temp_dir() . '/ukrainian-json-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
try {
    $assetRoot = is_file($root . '/public/v3/i18n/uk-UA.json') ? $root : $dependencyRoot;

    $generated = json_decode(file_get_contents($assetRoot . '/public/v3/i18n/uk-UA.json'), true, 512, JSON_THROW_ON_ERROR);

    $generated['synthetic_namespace']                           = ['untouched' => 'Unrelated translation'];
    $generated['firefly']['total_available_budget_in_currency'] = 'Budget in {{currency}}';

    $original = json_encode($generated, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    mkdir($temporary . '/resources/lang-overrides/uk_UA', 0700, true);
    mkdir($temporary . '/public/v3/i18n', 0700, true);
    foreach (array_keys($overrides) as $group) {
        copy($root . '/resources/lang-overrides/uk_UA/' . $group . '.php', $temporary . '/resources/lang-overrides/uk_UA/' . $group . '.php');
    }
    foreach (['uk-UA', 'uk', 'en-US'] as $locale) {
        file_put_contents($temporary . '/public/v3/i18n/' . $locale . '.json', $original);
    }
    $revision = UkrainianUi::revision($temporary);
    check(
        1 === preg_match('/^[a-f0-9]{64}$/', $revision) && UkrainianUi::revision($temporary) === $revision,
        'The installed dictionary and JSON revision is deterministic.'
    );
    $dictionaryPath = $temporary . '/resources/lang-overrides/uk_UA/firefly.php';
    $dictionary     = file_get_contents($dictionaryPath);
    file_put_contents($dictionaryPath, str_replace('Збереження транзакції.', 'Оновлення транзакції.', $dictionary));
    check(UkrainianUi::revision($temporary) !== $revision, 'An actual dictionary change updates the revision before browser cache lookup.');
    file_put_contents($dictionaryPath, $dictionary);
    check(UkrainianUi::revision($temporary) === $revision, 'Restoring a dictionary restores its original content revision.');
    foreach (['uk-UA', 'uk'] as $locale) {
        $path = $temporary . '/public/v3/i18n/' . $locale . '.json';
        file_put_contents($path, str_replace('Unrelated translation', 'Updated translation', $original));
        check(UkrainianUi::revision($temporary) !== $revision, 'Updating either actual Ukrainian JSON asset changes the browser revision.');
        file_put_contents($path, $original);
    }
    file_put_contents($temporary . '/public/v3/i18n/en-US.json', '{"unrelated":"updated"}');
    check(UkrainianUi::revision($temporary) === $revision, 'Other locale assets do not invalidate Ukrainian caches.');
    foreach (['uk-UA', 'uk', 'uk_UA', 'en-US'] as $locale) {
        file_put_contents($temporary . '/' . $locale . '.json', $original);
    }
    $runGenerationOverlay = static function (null|string $script = null) use ($root, $temporary): array {
        $process = proc_open([PHP_BINARY, $script ?? $root . '/.ci/translation-overrides.php', $temporary], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    };
    [$exit, $output] = $runGenerationOverlay();
    check(0 === $exit && str_contains($output, 'Applied reviewed overrides'), 'The native build overlay processes actual generated translation assets.');
    $changed   = 0;
    $selection = (require $root . '/config/translations.php')['json']['v3'];
    $expected  = $generated;
    foreach ($overrides as $group => $lines) {
        foreach ($lines as $key => $value) {
            if (in_array($key, $selection[$group] ?? [], true)) {
                $expected[$group][$key] = preg_replace('/(?<![A-Za-z0-9_]):([A-Za-z][A-Za-z0-9_]*)/u', '{{$1}}', $value);
                ++$changed;
            }
        }
    }
    check($changed > 0, 'The native generated JSON contains reviewed dynamic translation keys.');
    foreach (['uk-UA', 'uk', 'uk_UA'] as $locale) {
        $actual = json_decode(file_get_contents($temporary . '/' . $locale . '.json'), true, 512, JSON_THROW_ON_ERROR);
        check($actual === $expected, 'All Ukrainian aliases merge only existing reviewed keys and preserve every unrelated JSON value.');
        check(
            $actual['firefly']['save_transaction_working'] === $overrides['firefly']['save_transaction_working'],
            'The HTTP translation backend receives the corrected saving label.'
        );
        check($actual['firefly']['account_type_Cash account'] === 'Готівка', 'Dynamic cash-account labels use the reviewed Ukrainian noun.');
        check(!array_key_exists('interface', $actual), 'PHP-only interface groups are not added to generated JSON.');
    }
    check(file_get_contents($temporary . '/en-US.json') === $original, 'Other generated locales stay byte-for-byte unchanged.');
    $before = file_get_contents($temporary . '/uk-UA.json');
    [$exit, $output] = $runGenerationOverlay();
    check(
        0 === $exit && str_contains($output, '0 generated') && file_get_contents($temporary . '/uk-UA.json') === $before,
        'Repeated native generation overlays are idempotent.'
    );
    $missing = $expected;
    unset($missing['firefly']['save_transaction_working']);
    file_put_contents($temporary . '/uk.json', json_encode($missing, JSON_THROW_ON_ERROR));
    file_put_contents($temporary . '/uk-UA.json', $original);
    [$exit] = $runGenerationOverlay();
    check(0 !== $exit && file_get_contents($temporary . '/uk-UA.json') === $original, 'A missing selected native key is rejected before any alias is written.');
    mkdir($temporary . '/.ci', 0700);
    mkdir($temporary . '/config', 0700);
    copy($root . '/.ci/translation-overrides.php', $temporary . '/.ci/translation-overrides.php');
    $configuration                            = require $root . '/config/translations.php';
    $configuration['json']['v3']['firefly'][] = 'total_available_budget_in_currency';
    file_put_contents($temporary . '/config/translations.php', '<?php return ' . var_export($configuration, true) . ';');
    $colonFixture                                                  = $expected;
    $colonFixture['firefly']['total_available_budget_in_currency'] = 'Budget in {{currency}}';
    foreach (['uk-UA', 'uk', 'uk_UA'] as $locale) {
        file_put_contents($temporary . '/' . $locale . '.json', json_encode($colonFixture, JSON_THROW_ON_ERROR));
    }
    [$exit] = $runGenerationOverlay($temporary . '/.ci/translation-overrides.php');
    check(0 === $exit, 'A future native-selected PHP interpolation key is supported.');
    foreach (['uk-UA', 'uk'] as $locale) {
        $actual = json_decode(file_get_contents($temporary . '/' . $locale . '.json'), true, 512, JSON_THROW_ON_ERROR);
        check(
            $actual['firefly']['total_available_budget_in_currency'] === 'Загальний доступний бюджет · {{currency}}',
            'Selected PHP colon placeholders become native i18next placeholders.'
        );
    }
    file_put_contents($temporary . '/uk.json', '{malformed');
    file_put_contents($temporary . '/uk-UA.json', $original);
    [$exit] = $runGenerationOverlay();
    check(0 !== $exit && file_get_contents($temporary . '/uk-UA.json') === $original, 'A malformed generated alias is rejected before any writes.');
    unlink($temporary . '/uk.json');
    [$exit] = $runGenerationOverlay();
    check(0 !== $exit && file_get_contents($temporary . '/uk-UA.json') === $original, 'Generation must provide both browser-requested Ukrainian aliases.');
    unlink($temporary . '/uk-UA.json');
    [$exit, $output] = $runGenerationOverlay();
    check(0 === $exit && str_contains($output, 'translation overrides skipped'), 'English-only quick builds remain valid without generated Ukrainian assets.');
} finally {
    new Filesystem()->deleteDirectory($temporary);
}
$workflow = file_get_contents($root . '/.github/workflows/release.yml');
check(
    strpos($workflow, 'ff3:json-translations v3') < strpos($workflow, 'php .ci/translation-overrides.php')
    && strpos($workflow, 'php .ci/translation-overrides.php') < strpos($workflow, '- name: Build JS'),
    'The native release overlays reviewed labels after JSON generation and before frontend build.'
);

echo 'Passed ' . $checks . " Ukrainian UI translation checks.\n";
