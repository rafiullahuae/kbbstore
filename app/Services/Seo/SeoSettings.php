<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * The settings map as the SEO layer needs to read it.
 *
 * Two things this fixes over calling Setting::map() directly.
 *
 * 1. Blank is not absent. The SEO & Meta screen posts every field on every
 *    save, so a field the admin never touched is stored as ''. `$map['k'] ?? $d`
 *    then yields '' rather than $d -- which is how an empty <title>, an empty
 *    separator and a sitemap full of relative URLs all became possible. Every
 *    blank value is dropped here, so the `?? default` idiom every caller
 *    already uses means what it reads like. '0' is a value, not a blank, and
 *    survives (sitemap_enabled, merchant_ship_cost, ...).
 *
 * 2. Setting::map() memoises in a process-level static as well as in the
 *    cache, so within one long-lived process (a test, a queue worker) it
 *    cannot see a write made after the first call -- Setting::flushMap()
 *    clears the cache but not the static. This reads the same cache entry
 *    directly under the same key and TTL, so flushMap() still invalidates it
 *    and a fresh write is visible immediately.
 */
final class SeoSettings
{
    /** Same entry Setting::map() fills, so Setting::flushMap() invalidates both. */
    private const CACHE_KEY = 'kbb.settings.map';

    private const CACHE_TTL = 300;

    /** Settings that have a meaningful default when unset or left blank. */
    private const DEFAULTS = [
        'seo_separator' => '|',
        'seo_title_template' => '{title} {sep} {sitename}',
        'robots_index' => 'index',
        'robots_follow' => 'follow',
        'org_type' => 'Organization',
        'sitemap_enabled' => '1',
        'llms_enabled' => '1',
        'merchant_condition' => 'NewCondition',
        'merchant_ship_country' => 'AE',
    ];

    /**
     * key => value, with every blank value removed.
     *
     * Deliberately returns a plain array: Seo::render() and SeoFilesController
     * already read it with `$s['k'] ?? $default`, and that idiom is correct
     * once the blanks are gone.
     */
    public static function map(): array
    {
        $raw = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, static function (): array {
            $out = [];

            foreach (Setting::query()->get(['key', 'value']) as $row) {
                $out[(string) $row->key] = $row->value;
            }

            return $out;
        });

        if (! is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach ($raw as $key => $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }

            $value = is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);

            if ($value === '') {
                continue;
            }

            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    /**
     * One setting, blank-aware, never null.
     *
     * $default wins over a documented default; pass none and the DEFAULTS
     * table above applies, so callers do not each carry their own copy of
     * "the separator is a pipe unless told otherwise".
     */
    public static function get(string $key, ?string $default = null): string
    {
        return self::from(self::map(), $key, $default);
    }

    /** The same lookup against a map a caller already holds. */
    public static function from(array $map, string $key, ?string $default = null): string
    {
        $value = isset($map[$key]) && is_scalar($map[$key]) ? trim((string) $map[$key]) : '';

        if ($value !== '') {
            return $value;
        }

        return $default ?? (self::DEFAULTS[$key] ?? '');
    }

    /**
     * The first of $candidates that is not blank.
     *
     * Every "site_url, else APP_URL, else url('/')" chain in the SEO layer was
     * written as `?? config(...) ?? url('/')`, which stops at the first value
     * that exists -- including ''. This stops at the first one that is usable.
     */
    public static function firstFilled(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}
