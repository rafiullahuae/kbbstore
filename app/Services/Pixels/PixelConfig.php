<?php

declare(strict_types=1);

namespace App\Services\Pixels;

use App\Services\Analytics;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Crypt;

/**
 * Everything the Connect wizards store, beside the three pixel IDs that
 * App\Services\Analytics already owns. (Lane MP)
 *
 * ── WHERE ───────────────────────────────────────────────────────────────────
 *
 * Module settings of `marketing_pixels`, the same rows Analytics reads the IDs
 * from, so a shop page that already loaded that map for the pixel loader pays
 * nothing more for these. Every key ships BLANK, and a blank key changes no
 * byte of any page: applying the package moves nothing until the owner pastes
 * something (CLAUDE.md, rule 1).
 *
 * ── SECRETS ─────────────────────────────────────────────────────────────────
 *
 * The four credentials (SECRET_KEYS) are stored as Crypt::encryptString()
 * ciphertext — readable only with this server's APP_KEY — and leave this class
 * in exactly two ways: secret() hands the plaintext to the outbound HTTP call
 * that needs it, and masked() hands the admin screen "••••1a2b". Nothing
 * prints a secret into a page, and nothing in /api reads this class.
 *
 * ── SHAPES ──────────────────────────────────────────────────────────────────
 *
 * Every value is checked for shape on the way IN (SHAPES), not only on the
 * way out: a select stores one of its own options, an ID stores its own
 * pattern, and a refusal names the field.
 */
final class PixelConfig
{
    public const MODULE = Analytics::MODULE;

    /** Stored encrypted; shown back as the last four characters only. */
    public const SECRET_KEYS = ['meta_capi_token', 'meta_app_secret', 'ga4_api_secret', 'tiktok_token'];

    /**
     * key => [regex the value must match when not blank, label].
     * Tokens are long opaque strings; the pattern bars whitespace, quotes and
     * angle brackets, which is all that matters for a value only ever sent in
     * a request body or header.
     */
    public const SHAPES = [
        'meta_capi_token' => ['/^[A-Za-z0-9_\-\.|]{20,512}$/', 'Conversions API access token'],
        'meta_test_code' => ['/^TEST[A-Za-z0-9]{2,20}$/', 'Meta test event code'],
        'meta_capi' => ['/^[01]$/', 'Send Meta server events'],
        'meta_app_id' => ['/^[0-9]{5,20}$/', 'Meta App ID'],
        'meta_app_secret' => ['/^[a-f0-9]{32}$/i', 'Meta App Secret'],
        'ga4_api_secret' => ['/^[A-Za-z0-9_\-]{8,100}$/', 'GA4 Measurement Protocol API secret'],
        'ga4_mp' => ['/^[01]$/', 'Send GA4 purchase from the server'],
        'ads_id' => ['/^AW-[0-9]{6,20}$/', 'Google Ads conversion ID'],
        'ads_label' => ['/^[A-Za-z0-9_\-]{4,64}$/', 'Google Ads purchase conversion label'],
        'ads_ec' => ['/^[01]$/', 'Enhanced conversions'],
        'consent_mode' => ['/^(off|eea)$/', 'Google consent mode'],
        'tiktok_token' => ['/^[A-Za-z0-9_\-\.]{20,512}$/', 'TikTok Events API access token'],
        'tiktok_test_code' => ['/^TEST[A-Za-z0-9]{2,20}$/', 'TikTok test event code'],
        'tiktok_eapi' => ['/^[01]$/', 'Send TikTok server events'],
    ];

    /** Switches that read as ON when never set: a pasted token means "use it". */
    private const DEFAULT_ON = ['meta_capi', 'ga4_mp', 'tiktok_eapi'];

    /**
     * The two verification codes live in the main `settings` map, not the
     * module's: that map is already read once on every page, so printing the
     * tags costs no extra read even with the pixels module switched off. The
     * Google key is the SEO screen's own, shared rather than duplicated.
     */
    public const GOOGLE_VERIFY_KEY = 'google_site_verification';

    public const META_VERIFY_KEY = 'facebook_domain_verification';

    public const VERIFY_KEYS = [self::GOOGLE_VERIFY_KEY => 'Google site verification code', self::META_VERIFY_KEY => 'Meta domain verification code'];

    public const GOOGLE_VERIFY_SHAPE = '/^[A-Za-z0-9_\-]{10,100}$/';

    public function __construct(private SettingsService $settings) {}

    /** The stored value as typed (secrets decrypted), '' when unset or unreadable. */
    public function get(string $key): string
    {
        $raw = trim((string) $this->settings->moduleSetting(self::MODULE, $key, ''));

        if ($raw === '' && in_array($key, self::DEFAULT_ON, true)) {
            return '1';
        }

        if ($raw === '' || ! in_array($key, self::SECRET_KEYS, true)) {
            return $raw;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            // A value written under another APP_KEY, or by hand: unusable, so
            // it is treated as absent rather than sent to a platform.
            return '';
        }
    }

    /** A credential, for the outbound call that needs it. Null when unset. */
    public function secret(string $key): ?string
    {
        $value = $this->get($key);

        return $value === '' ? null : $value;
    }

    public function on(string $key): bool
    {
        return $this->get($key) === '1';
    }

    /** "••••1a2b" for a stored secret, '' for none. */
    public function masked(string $key): string
    {
        $value = $this->get($key);

        return $value === '' ? '' : '••••' . substr($value, -4);
    }

    /** The Google verification token from the SEO setting, shape-checked. */
    public function googleVerification(): ?string
    {
        $value = trim((string) $this->settings->get(self::GOOGLE_VERIFY_KEY, ''));

        return preg_match(self::GOOGLE_VERIFY_SHAPE, $value) === 1 ? $value : null;
    }

    public function metaVerification(): ?string
    {
        $value = trim((string) $this->settings->get(self::META_VERIFY_KEY, ''));

        return preg_match(self::GOOGLE_VERIFY_SHAPE, $value) === 1 ? $value : null;
    }

    /**
     * Write a set of values. A secret posted as '' is left alone (the screen
     * never has the plaintext to send back); `clear: [keys]` removes one.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $clear
     * @return array<string, string> refused fields, label by key
     */
    public function save(array $values, array $clear = []): array
    {
        $refused = [];
        $writes = [];

        foreach ($values as $key => $value) {
            if (isset(self::VERIFY_KEYS[$key])) {
                $value = self::verificationToken($value);

                if ($value === null) {
                    $refused[$key] = self::VERIFY_KEYS[$key];

                    continue;
                }

                $writes[$key] = $value;

                continue;
            }

            if (! isset(self::SHAPES[$key])) {
                $refused[$key] = $key;

                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                $refused[$key] = self::SHAPES[$key][1];

                continue;
            }

            $value = trim((string) $value);

            if (in_array($key, ['ads_id'], true)) {
                $value = strtoupper($value);
            }

            if ($value === '' && in_array($key, self::SECRET_KEYS, true)) {
                continue;
            }

            if ($value !== '' && preg_match(self::SHAPES[$key][0], $value) !== 1) {
                $refused[$key] = self::SHAPES[$key][1];

                continue;
            }

            $writes[$key] = $value;
        }

        if ($refused !== []) {
            return $refused;
        }

        foreach ($writes as $key => $value) {
            if (isset(self::VERIFY_KEYS[$key])) {
                $this->settings->set($key, $value);

                continue;
            }

            $stored = ($value !== '' && in_array($key, self::SECRET_KEYS, true)) ? Crypt::encryptString($value) : $value;
            $this->settings->setModuleSetting(self::MODULE, $key, $stored);
        }

        foreach ($clear as $key) {
            if (in_array($key, self::SECRET_KEYS, true)) {
                $this->settings->setModuleSetting(self::MODULE, $key, '');
            }
        }

        return [];
    }

    /**
     * The token out of whatever the owner pasted: the bare code, or the whole
     * `<meta name="…" content="CODE">` tag the platform shows. Only the code
     * is ever stored, and only if it is the right shape; the tag itself is
     * rebuilt from constants when it is printed.
     */
    public static function verificationToken(mixed $pasted): ?string
    {
        if (! is_scalar($pasted) && $pasted !== null) {
            return null;
        }

        $value = trim((string) $pasted);

        if ($value === '') {
            return '';
        }

        if (preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $value, $m) === 1) {
            $value = trim($m[1]);
        }

        return preg_match(self::GOOGLE_VERIFY_SHAPE, $value) === 1 ? $value : null;
    }
}
