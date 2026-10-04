<?php

declare(strict_types=1);

namespace App\Services\Seo\Keywords;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where SEO → Keywords keeps its switches and its one secret.
 *
 * TWO HOMES, CHOSEN BY WHO READS THEM.
 *
 *   `settings` — only what EVERY STOREFRONT PAGE reads: whether keywords are
 *   live (`seo_kw_live`, the stamp of the last applied sync), whether the
 *   <meta name="keywords"> tag is printed, whether the Popular searches block
 *   is shown. Those ride Setting::map(), which every page has already loaded,
 *   so reading them costs no query.
 *
 *   `seo_keyword_config` — everything only the admin reads: the source
 *   switches, the Search Console property, and the Search Console service
 *   account key. The key is Crypt-encrypted and is in this table and NOT in
 *   `settings` because GET /admin-api/settings returns `settings` wholesale
 *   (see MailCredentials' header for the same reasoning). There is no method
 *   here that returns the key to a caller other than SearchConsoleSource.
 */
final class KeywordConfig
{
    public const LIVE = 'seo_kw_live';

    public const META = 'seo_kw_meta';

    public const POPULAR = 'seo_kw_popular';

    public const DEFAULT_OPTIONS = [
        'autocomplete' => true,
        'arabic' => true,
        'seed_cap' => 300,
        'gsc_property' => '',
    ];

    public const MAX_SEEDS = 300;

    /** @return array{autocomplete: bool, arabic: bool, seed_cap: int, gsc_property: string} */
    public static function options(): array
    {
        $raw = self::read('options');
        $saved = is_string($raw) ? json_decode($raw, true) : null;
        $o = self::DEFAULT_OPTIONS;

        if (is_array($saved)) {
            $o['autocomplete'] = (bool) ($saved['autocomplete'] ?? $o['autocomplete']);
            $o['arabic'] = (bool) ($saved['arabic'] ?? $o['arabic']);
            $o['seed_cap'] = max(0, min(self::MAX_SEEDS, (int) ($saved['seed_cap'] ?? $o['seed_cap'])));
            $o['gsc_property'] = is_string($saved['gsc_property'] ?? null) ? $saved['gsc_property'] : '';
        }

        return $o;
    }

    public static function saveOptions(array $o): void
    {
        $merged = array_merge(self::options(), array_intersect_key($o, self::DEFAULT_OPTIONS));
        self::write('options', json_encode($merged));
    }

    /* ------------------------------------------------ storefront switches */

    public static function metaOn(array $s): bool
    {
        return ($s[self::META] ?? '1') !== '0';
    }

    public static function popularOn(array $s): bool
    {
        return ($s[self::POPULAR] ?? '0') === '1';
    }

    public static function setSwitch(string $key, bool $on): void
    {
        if (! in_array($key, [self::META, self::POPULAR], true)) {
            return;
        }
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $on ? '1' : '0']);
        Setting::flushMap();
    }

    /** A new stamp invalidates every cached page payload at once. Null switches keywords off the storefront. */
    public static function stampLive(?string $stamp = null): void
    {
        $stamp ??= (string) now()->format('YmdHis').'-'.substr(bin2hex(random_bytes(3)), 0, 6);
        Setting::query()->updateOrCreate(['key' => self::LIVE], ['value' => $stamp]);
        Setting::flushMap();
    }

    /* ------------------------------------------------ the Search Console key */

    public static function hasGscKey(): bool
    {
        return self::gscKey() !== null;
    }

    /**
     * Validate and store a service-account JSON key. Returns the client email
     * (safe to show) or throws InvalidArgumentException with a reason.
     */
    public static function saveGscKey(string $json): string
    {
        $key = json_decode(trim($json), true);

        if (! is_array($key)
            || ($key['type'] ?? '') !== 'service_account'
            || ! is_string($key['client_email'] ?? null)
            || ! filter_var($key['client_email'], FILTER_VALIDATE_EMAIL)
            || ! is_string($key['private_key'] ?? null)
            || openssl_pkey_get_private($key['private_key']) === false) {
            throw new \InvalidArgumentException('That is not a Google service-account JSON key (type, client_email and private_key are required).');
        }

        $keep = [
            'client_email' => $key['client_email'],
            'private_key' => $key['private_key'],
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ];

        self::write('gsc', Crypt::encryptString(json_encode($keep)));

        return $key['client_email'];
    }

    public static function forgetGscKey(): void
    {
        self::write('gsc', null);
    }

    /** The account email only — what the screen may show. */
    public static function gscEmail(): ?string
    {
        return self::gscKey()['client_email'] ?? null;
    }

    /** @internal SearchConsoleSource only. */
    public static function gscKey(): ?array
    {
        $raw = self::read('gsc');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $key = json_decode(Crypt::decryptString($raw), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($key) && isset($key['client_email'], $key['private_key']) ? $key : null;
    }

    /* ------------------------------------------------ storage */

    private static function read(string $name): ?string
    {
        if (! Schema::hasTable('seo_keyword_config')) {
            return null;
        }

        $v = DB::table('seo_keyword_config')->where('name', $name)->value('value');

        return is_string($v) ? $v : null;
    }

    private static function write(string $name, ?string $value): void
    {
        DB::table('seo_keyword_config')->updateOrInsert(
            ['name' => $name],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
