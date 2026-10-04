<?php
declare(strict_types=1);

/**
 * A external catalogue the admin can search when adding games.
 *
 * Implementations declare which credential fields they need; a provider is
 * only offered in the UI once those fields are filled in and it is enabled.
 * Steam needs none, so it works out of the box.
 */
abstract class Provider
{
    /** @param array<string,mixed> $settings row from api_settings */
    public function __construct(protected array $settings = [])
    {
    }

    /** Stable code, matching api_settings.provider and lang key api_provider.<code>. */
    abstract public function code(): string;

    /**
     * Credential columns this provider requires, e.g. ['api_key'].
     * An empty list means the provider works without configuration.
     *
     * @return list<string>
     */
    abstract public function requiredCredentials(): array;

    /**
     * Free-text search.
     *
     * @return list<array{ref:string,title:string,year:?string,image:?string}>
     */
    abstract public function search(string $term): array;

    /**
     * Full detail for one result.
     *
     * @return array{
     *     ref:string, title:string, description:?string, release_date:?string,
     *     publishers:list<string>, developers:list<string>, genres:list<string>,
     *     cover_url:?string, store_url:?string
     * }|null
     */
    abstract public function fetch(string $ref): ?array;

    /** True when every required credential has a value. */
    public function isConfigured(): bool
    {
        foreach ($this->requiredCredentials() as $field) {
            if (trim((string) ($this->settings[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->settings['is_enabled'] ?? false);
    }

    /** Usable right now: switched on and holding whatever credentials it needs. */
    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }

    /**
     * Live check against the provider.
     *
     * @return array{ok:bool, note:string}
     */
    public function test(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'note' => 'Missing credentials.'];
        }

        try {
            $results = $this->search('portal');
        } catch (Throwable $e) {
            return ['ok' => false, 'note' => $e->getMessage()];
        }

        return $results === []
            ? ['ok' => false, 'note' => 'Reachable, but the search returned nothing.']
            : ['ok' => true, 'note' => count($results) . ' result(s) for a sample search.'];
    }

    protected function setting(string $field): string
    {
        return trim((string) ($this->settings[$field] ?? ''));
    }

    /**
     * Parses a provider's release date, keeping track of how much of it is
     * actually known.
     *
     * Store fronts answer with anything from "17 Sep, 2020" to "Q1 2027"
     * to "Coming soon". Collapsing all of those into a date silently
     * invents precision, so the precision travels alongside the value and
     * the original string is kept for re-parsing when more is announced.
     *
     * @return array{date:?string, precision:string, raw:?string}
     */
    public static function parseRelease(?string $raw): array
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return ['date' => null, 'precision' => 'unknown', 'raw' => null];
        }

        $dayPatterns = [
            '/^\d{1,2}\s+[A-Za-z]{3,9},?\s+\d{4}$/',
            '/^[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4}$/',
            '/^\d{4}-\d{1,2}-\d{1,2}$/',
            '/^\d{1,2}[\/.-]\d{1,2}[\/.-]\d{4}$/',
        ];

        foreach ($dayPatterns as $pattern) {
            if (preg_match($pattern, $raw)) {
                $date = self::normaliseDate($raw);

                if ($date !== null) {
                    return ['date' => $date, 'precision' => 'day', 'raw' => $raw];
                }
            }
        }

        if (preg_match('/^([A-Za-z]{3,9})\s+(\d{4})$/', $raw, $m)) {
            $parsed = DateTimeImmutable::createFromFormat('!M Y', substr($m[1], 0, 3) . ' ' . $m[2]);

            if ($parsed instanceof DateTimeImmutable) {
                return ['date' => $parsed->format('Y-m-01'), 'precision' => 'month', 'raw' => $raw];
            }
        }

        if (preg_match('/(?<!\d)(\d{4})(?!\d)/', $raw, $m)) {
            return ['date' => $m[1] . '-01-01', 'precision' => 'year', 'raw' => $raw];
        }

        return ['date' => null, 'precision' => 'unknown', 'raw' => $raw];
    }

    /** Normalises assorted provider date formats to YYYY-MM-DD. */
    protected static function normaliseDate(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $formats = ['Y-m-d', 'd M, Y', 'M d, Y', 'd M Y', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd.m.Y', 'M Y', 'Y'];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $raw);
            $errors = DateTimeImmutable::getLastErrors();

            $clean = $date instanceof DateTimeImmutable
                && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0));

            if ($clean) {
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($raw);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
