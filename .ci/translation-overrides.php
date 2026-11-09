<?php

declare(strict_types=1);

// The upstream JSON generator reads core locale files directly, before the
// application's additional FileLoader path can merge these reviewed labels.
$root      = dirname(__DIR__);
$directory = rtrim($argv[1] ?? $root . '/public/v3/i18n', '/');
if (!is_file($directory . '/uk-UA.json') && !is_file($directory . '/uk.json')) {
    echo "No generated Ukrainian assets; translation overrides skipped.\n";
    exit(0);
}
$overrides = [];
$selection = (require $root . '/config/translations.php')['json']['v3'];
foreach (['firefly', 'form', 'list', 'interface'] as $group) {
    $overrides[$group] = require $root . '/resources/lang-overrides/uk_UA/' . $group . '.php';
}
$files = [];
foreach (['uk-UA', 'uk', 'uk_UA'] as $locale) {
    $path = $directory . '/' . $locale . '.json';
    if ('uk_UA' === $locale && !is_file($path)) {
        continue;
    }
    if (!is_file($path)) {
        throw new RuntimeException('Generate the native Ukrainian JSON translations before applying overrides.');
    }
    $original     = file_get_contents($path);
    $translations = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($translations) || isset($translations['translation'])) {
        throw new RuntimeException('A generated Ukrainian translation asset must contain an object.');
    }
    $updates = 0;
    foreach ($overrides as $group => $lines) {
        foreach ($lines as $key => $value) {
            if (!in_array($key, $selection[$group] ?? [], true)) {
                continue;
            }
            if (!isset($translations[$group][$key]) || !is_string($translations[$group][$key])) {
                throw new RuntimeException('A selected generated Ukrainian translation key is missing: ' . $group . '.' . $key);
            }
            $translations[$group][$key] = preg_replace('/(?<![A-Za-z0-9_]):([A-Za-z][A-Za-z0-9_]*)/u', '{{$1}}', $value);
            ++$updates;
        }
    }
    if (0 === $updates) {
        throw new RuntimeException('The generated Ukrainian assets contain no reviewed translation keys.');
    }
    $encoded = json_encode($translations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    if ($translations !== json_decode($original, true, 512, JSON_THROW_ON_ERROR)) {
        $files[$path] = $encoded;
    }
}
foreach ($files as $path => $content) {
    if (false === file_put_contents($path, $content)) {
        throw new RuntimeException('Could not write a generated Ukrainian translation asset.');
    }
}
echo 'Applied reviewed overrides to ' . count($files) . " generated Ukrainian translation assets.\n";
