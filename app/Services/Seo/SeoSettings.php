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
        /*
         * ── THE BUSINESS TYPE, AND WHY IT IS `OnlineStore` — Lane S8 ───────
         *
         * This shipped as `Organization` and the owner has told us, in his own
         * words, that it is wrong:
         *
         *     "we are open 24/7, we don't have any physical shop, we operate
         *      only online"
         *
         * `OnlineStore` is the schema.org type for exactly that shop. It is a
         * subtype of Organization, so nothing that was valid stops being valid,
         * and it is a POSITIVE statement rather than the absence of one:
         * `Organization` says "a company", which a shop is, and stops there.
         * Google reconciles a brand's identity across the type, the address and
         * the Business Profile; a retailer publishing itself as a bare
         * Organization is giving the least specific true answer available.
         *
         * ── THIS IS A DEFAULT CHANGE AND IT IS DELIBERATE ──────────────────
         *
         * CLAUDE.md rule 1 says a new setting ships at the value the page
         * already has, and that the one exception is "a default the owner asked
         * for in as many words", called out rather than buried. This is that
         * exception and this note is the calling out. The observable effect on a
         * shop that has never saved the SEO screen is one property of one node:
         *
         *     "@type":"Organization"   ->   "@type":"OnlineStore"
         *
         * and nothing else on any page changes. Measured, not assumed --
         * SeoPreviewsTest prints the whole graph for six page shapes.
         *
         * ── WHAT IT DELIBERATELY DOES NOT DO ──────────────────────────────
         *
         * It does not publish an address, a phone number, an emirate or a set of
         * opening hours, because the owner has not given any of those and asked
         * to enter the address later. Support\BusinessAddress already refuses to
         * publish a partial address, so "later" is genuinely supported: every box
         * on Store -> Business Details -> Business stays blank and the node stays
         * silent about location. Nothing nags him for it either -- SeoAudit's
         * `business_type_mismatch` and the Overview screen's
         * `localbusiness_address` task both fire only on a PLACE type with no
         * address, and `OnlineStore` is not a place type. Under the old default
         * of `Organization` that was equally true, so this change does not switch
         * a nag on or off; it makes the published fact match the shop.
         *
         * ── AND 24/7 IS NOT PUBLISHED, ON PURPOSE ─────────────────────────
         *
         * "We are open 24/7" is true and is NOT expressible here.
         * `openingHoursSpecification` is a property of schema.org **Place**, and
         * an OnlineStore is not a Place -- so a 24-hour specification on this
         * node would be invalid markup, not a helpful extra. There is no
         * schema.org property meaning "orderable at any hour" for a shop with no
         * premises; the closest honest statement is the one this node already
         * makes by having no opening hours at all, which for an online retailer
         * reads as "not a place with a door". BusinessAddress::isPlaceType()
         * enforces that and SeoAudit now reports it if the hours box is filled in
         * anyway, so a value that cannot be published is never silently dropped.
         */
        'org_type' => 'OnlineStore',
        'sitemap_enabled' => '1',
        'llms_enabled' => '1',
        /*
         * FAQPage JSON-LD on a content page written as questions
         * (Services\Seo\FaqSchema). '0', so the package that carries the class
         * moves no page by a byte -- rule 1. Turning it on wants the key on
         * AdminController::SETTING_RULES and a card on Store -> SEO & Meta;
         * until then this default is what every reader sees and the node is
         * never built.
         */
        'faq_schema' => '0',
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
