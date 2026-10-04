<?php
declare(strict_types=1);

/**
 * Verifies that the localization files and the database agree.
 *
 *     php bin/check_lang.php
 *
 * Two checks:
 *   1. Parity — every key present in the default locale exists in the others.
 *   2. Coverage — every code seeded in a lookup table has a label.
 *
 * Exits non-zero when something is missing, so it can gate a commit.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

const OK   = "  \033[32m✔\033[0m ";
const BAD  = "  \033[31m✗\033[0m ";
const INFO = "  \033[34m·\033[0m ";

/** Flattens nested arrays into dotted keys. */
function flatten(array $data, string $prefix = ''): array
{
    $out = [];

    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $out += flatten($value, $path);
        } else {
            $out[$path] = $value;
        }
    }

    return $out;
}

$locales = Lang::available();
sort($locales);

echo "\nStreamOrg localization check\n";
echo INFO . 'locales: ' . implode(', ', $locales) . "\n\n";

$problems = 0;

$base = flatten(require dirname(__DIR__) . '/lang/en.php');

foreach ($locales as $locale) {
    if ($locale === 'en') {
        continue;
    }

    $other   = flatten(require dirname(__DIR__) . "/lang/{$locale}.php");
    $missing = array_diff_key($base, $other);
    $extra   = array_diff_key($other, $base);

    foreach ($missing as $key => $_) {
        echo BAD . "{$locale}: missing {$key}\n";
        $problems++;
    }

    foreach ($extra as $key => $_) {
        echo BAD . "{$locale}: has {$key}, absent from en\n";
        $problems++;
    }

    if ($missing === [] && $extra === []) {
        echo OK . "{$locale}: key set matches en (" . count($base) . " keys)\n";
    }
}

$lookups = [
    'genres'              => 'genre',
    'game_platforms'      => 'game_platform',
    'key_platforms'       => 'key_platform',
    'streaming_platforms' => 'streaming_platform',
];

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    echo BAD . 'cannot reach the database: ' . $e->getMessage() . "\n\n";
    exit(1);
}

$derived = [
    'platform_family' => 'SELECT DISTINCT family FROM game_platforms WHERE family IS NOT NULL',
];

foreach ($derived as $group => $sql) {
    $codes = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

    foreach ($locales as $locale) {
        $labels = Lang::group($group, $locale);

        foreach (array_diff($codes, array_keys($labels)) as $code) {
            echo BAD . "{$locale}: no label for {$group}.{$code}\n";
            $problems++;
        }
    }

    echo OK . "{$group}: " . count($codes) . " codes covered in all locales\n";
}

foreach ($lookups as $table => $group) {
    $codes = $pdo->query("SELECT code FROM {$table} ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($locales as $locale) {
        $labels  = Lang::group($group, $locale);
        $missing = array_diff($codes, array_keys($labels));

        foreach ($missing as $code) {
            echo BAD . "{$locale}: no label for {$group}.{$code}\n";
            $problems++;
        }
    }

    echo OK . "{$table}: " . count($codes) . " codes covered in all locales\n";
}

echo "\n";

if ($problems > 0) {
    echo "\033[31m{$problems} problem(s).\033[0m\n\n";
    exit(1);
}

echo "\033[32mAll labels present.\033[0m\n\n";
