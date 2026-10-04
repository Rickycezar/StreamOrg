<?php
declare(strict_types=1);

/**
 * Reads and rewrites the files in lang/.
 *
 * Writing executable PHP from form input is the risk here, so three things
 * hold it together: values are only ever emitted through var_export, so a
 * quote or backslash cannot break out of its literal; the result is parsed
 * and sanity-checked in a temporary file before it can replace anything;
 * and the previous version is kept.
 *
 * The leading docblock is preserved verbatim. The array body is
 * regenerated, which does cost the section-divider comments that were
 * inside it — the group keys say the same thing.
 */
final class LangFile
{
    private const DIR = __DIR__ . '/../lang';

    /** A locale is a file name, never a path. */
    public static function isValidLocale(string $locale): bool
    {
        return (bool) preg_match('/^[A-Za-z]{2}(-[A-Za-z0-9]{2,8})?$/', $locale)
            && in_array($locale, Lang::available(), true);
    }

    public static function path(string $locale): string
    {
        return self::DIR . '/' . $locale . '.php';
    }

    /** @return array<string, mixed> */
    public static function load(string $locale): array
    {
        if (!self::isValidLocale($locale)) {
            throw new RuntimeException("Unknown locale '{$locale}'.");
        }

        $data = require self::path($locale);

        return is_array($data) ? $data : [];
    }

    /**
     * Every editable group, as dotted paths.
     *
     * A node can hold leaves and sub-arrays at once — 'ui' has app_name
     * beside nav, action, field and the rest — so it contributes both its
     * own group and one per child. An earlier version required a node to
     * be uniformly nested, which left every ui.* string unreachable.
     *
     * @return list<string>
     */
    public static function groups(array $data, string $prefix = ''): array
    {
        $groups  = [];
        $hasLeaf = false;

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $groups = array_merge(
                    $groups,
                    self::groups($value, $prefix === '' ? (string) $key : $prefix . '.' . $key)
                );

                continue;
            }

            $hasLeaf = true;
        }

        if ($hasLeaf && $prefix !== '') {
            $groups[] = $prefix;
        }

        if ($prefix === '') {
            sort($groups, SORT_NATURAL);
        }

        return $groups;
    }

    /** @return array<string|int, string> the leaves of one group */
    public static function group(array $data, string $group): array
    {
        $node = $data;

        foreach (explode('.', $group) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return [];
            }

            $node = $node[$segment];
        }

        return is_array($node) ? array_filter($node, static fn ($v): bool => !is_array($v)) : [];
    }

    /**
     * Replaces one group's leaves and writes the file.
     *
     * @param array<string|int, string> $values
     * @return array{backup:string, count:int}
     */
    public static function saveGroup(string $locale, string $group, array $values): array
    {
        $data = self::load($locale);
        $path = explode('.', $group);

        $node = &$data;

        foreach ($path as $i => $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                throw new RuntimeException("Group '{$group}' does not exist in {$locale}.");
            }

            if ($i === count($path) - 1) {
                $node[$segment] = $values;
                break;
            }

            $node = &$node[$segment];
        }

        unset($node);

        return self::write($locale, $data);
    }

    /**
     * Renders and installs a new version of the file.
     *
     * @return array{backup:string, count:int}
     */
    private static function write(string $locale, array $data): array
    {
        $target = self::path($locale);
        $source = (string) file_get_contents($target);

        $pos    = strpos($source, "\nreturn [");
        $header = $pos === false
            ? "<?php\n"
            : substr($source, 0, $pos + 1);

        $php = $header . "return [\n" . self::renderArray($data, 1) . "];\n";

        $tmp = $target . '.tmp' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the temporary file.');
        }

        try {
            $parsed = include $tmp;
        } catch (Throwable $e) {
            @unlink($tmp);
            throw new RuntimeException('Generated file did not parse: ' . $e->getMessage());
        }

        if (!is_array($parsed) || count($parsed) !== count($data)) {
            @unlink($tmp);
            throw new RuntimeException('Generated file did not round-trip.');
        }

        $backup = $target . '.bak';
        @copy($target, $backup);

        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Could not replace the language file.');
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }

        return ['backup' => basename($backup), 'count' => count($parsed, COUNT_RECURSIVE)];
    }

    /** Recursive renderer. Every scalar goes through var_export. */
    private static function renderArray(array $data, int $depth): string
    {
        $pad = str_repeat('    ', $depth);
        $out = '';

        foreach ($data as $key => $value) {
            $renderedKey = is_int($key) ? (string) $key : var_export((string) $key, true);

            if (is_array($value)) {
                $out .= "{$pad}{$renderedKey} => [\n"
                      . self::renderArray($value, $depth + 1)
                      . "{$pad}],\n\n";
                continue;
            }

            $out .= "{$pad}{$renderedKey} => " . var_export((string) $value, true) . ",\n";
        }

        return $out;
    }
}
