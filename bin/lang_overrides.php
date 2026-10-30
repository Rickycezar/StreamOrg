<?php
declare(strict_types=1);

/**
 * Makes the texts edited in Administration → Languages the defaults in
 * the code.
 *
 *     php bin/lang_overrides.php apply streamorg-lang-edits.json
 *
 * The JSON is what "Download edits" gives on that page
 * ({"edits": {locale: {path: text}}}). Each edit is written into
 * lang/<locale>.php (through LangFile, which validates and keeps a backup).
 * An edit for a key the file no longer has is listed and skipped. Once the
 * files are released, those edits equal the defaults and can be cleared in
 * the tool (saving a text equal to its default clears it).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

[$command, $file] = [$argv[1] ?? '', $argv[2] ?? ''];

if ($command !== 'apply' || $file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/lang_overrides.php apply <edits.json>\n");
    exit(1);
}

$edits = json_decode((string) file_get_contents($file), true)['edits'] ?? null;

if (!is_array($edits)) {
    fwrite(STDERR, "Not an edits file.\n");
    exit(1);
}

$written = 0;
$skipped = [];

foreach ($edits as $locale => $paths) {
    if (!LangFile::isValidLocale((string) $locale) || !is_array($paths)) {
        $skipped[] = "{$locale} (unknown language)";
        continue;
    }

    $byGroup = [];

    foreach ($paths as $path => $text) {
        $cut = strrpos((string) $path, '.');

        if ($cut === false || !is_string($text)) {
            $skipped[] = "{$locale}: {$path}";
            continue;
        }

        $byGroup[substr((string) $path, 0, $cut)][substr((string) $path, $cut + 1)] = $text;
    }

    foreach ($byGroup as $group => $texts) {
        $current = LangFile::group(LangFile::load((string) $locale), (string) $group);

        foreach (array_keys($texts) as $key) {
            if (!array_key_exists($key, $current)) {
                $skipped[] = "{$locale}: {$group}.{$key}";
                unset($texts[$key]);
            }
        }

        if ($texts === []) {
            continue;
        }

        LangFile::saveGroup((string) $locale, (string) $group, array_replace($current, $texts));
        $written += count($texts);
    }
}

echo "Written into lang/: {$written} text(s).\n";

foreach ($skipped as $line) {
    echo "Skipped (no such key in the file): {$line}\n";
}
