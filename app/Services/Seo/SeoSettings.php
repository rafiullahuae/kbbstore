<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * The settings the "SEO & Meta" admin screen writes, read back the way the
 * storefront needs them.
 *
 * Two things this does that a bare Setting::map() lookup does not:
 *
 *  - **Blank is absent.** The admin screen posts every field on every save, so
 *    a field the shop owner never filled in is stored as '' rather than left
 *    out of the payload. `$map['seo_title_template'] ?? $default` therefore
 *    returns '', not the default, and the page ends up with an empty <title>.
 *    Everything here goes through get(), which trims and treats '' as unset.
 *
 *  - **No process-level memo.** Setting::map() memoises into a `static` as well
 *    as the cache, so within one long-lived process (a queue worker, or the
 *    test suite, where every test shares one PHP process) it never sees a write
 *    made after the first call. This reads the same cache entry under the same
 *    key — so it costs nothing extra per request and Setting::flushMap() still
 *    invalidates it — but without the static, so a freshly written setting is
 *    visible immediately.
 */
final class SeoSettings
{
    /** Deliberately the key Setting::map() uses: one cached read serves both. */
    private const CACHE_KEY = 'kbb.settings.map';

    private const CACHE_TTL = 300;

    /** @var array<string, mixed> */
    private array $map;

    /** @param array<string, mixed>|null $map Pass a map to bypass the cache (tests, previews). */
    public function __construct(?array $map = null)
    {
        $this->map = $map ?? self::load();
    }

    /** @return array<string, mixed> */
    private static function load(): array
    {
        try {
            $rows = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, static function (): array {
                $out = [];

                foreach (Setting::query()->get(['key', 'value']) as $row) {
                    $out[(string) $row->key] = $row->value;
                }

                return $out;
            });
        } catch (\Throwable) {
            // The <head> must still render if the settings table is missing or
            // the database is briefly unreachable. Nothing is cached on this
            // path, so the next request tries again.
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * A setting as a non-empty trimmed string, or $default when it is unset,
     * blank, or not a scalar.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->map[$key] ?? null;

        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }

    /**
     * The first of $keys that is actually set, else $default.
     *
     * @param  array<int, string>  $keys
     */
    public function first(array $keys, ?string $default = null): ?string
    {
        foreach ($keys as $key) {
            $value = $this->get($key);

            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * A setting constrained to a known set of values — anything else falls back.
     *
     * @param  array<int, string>  $allowed
     */
    public function oneOf(string $key, array $allowed, string $default): string
    {
        $value = strtolower((string) $this->get($key, $default));

        return in_array($value, $allowed, true) ? $value : $default;
    }
}
