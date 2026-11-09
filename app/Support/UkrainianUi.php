<?php

declare(strict_types=1);

namespace FireflyIII\Support;

final class UkrainianUi
{
    /** One revision covers the reviewed PHP labels and actual browser translations. */
    public static function revision(null|string $root = null): string
    {
        $root   ??= base_path();
        $hashes = [];
        foreach ([
            'resources/lang-overrides/uk_UA/firefly.php',
            'resources/lang-overrides/uk_UA/form.php',
            'resources/lang-overrides/uk_UA/list.php',
            'resources/lang-overrides/uk_UA/interface.php',
            'public/v3/i18n/uk-UA.json',
            'public/v3/i18n/uk.json'
        ] as $relative) {
            $path              = $root . '/' . $relative;
            $hashes[$relative] = is_file($path) ? hash_file('sha256', $path) : null;
        }

        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }
}
