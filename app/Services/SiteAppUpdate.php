<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Locale;

/**
 * App → Site App → App update (Lane UA).
 *
 * The owner, 6 October: "also if any user already installed, then the row will
 * not show, but if we published any updates in the site app, then we will have
 * option to enable to display again on the existing users device too with
 * Update App button."
 *
 * WHAT "UPDATING" A WEB APP MEANS, plainly. Pages, products and prices are
 * always live in the installed app: it loads them from the shop like a browser
 * does. What an installed app can be behind on is
 *   (a) its service worker and the shell it keeps (the offline page, the app's
 *       own files) -- fixed by activating the new worker and reloading, which
 *       is what the "Update App" button does; and
 *   (b) the Home Screen icon, name and top colour -- Android Chrome refreshes
 *       those itself within about a day; an iPhone only when the app is
 *       removed and added again. So when an update carries a new icon or name
 *       the row adds a one-line tip, on iPhone only.
 *
 * ONE SETTING, AUTOLOADED, ABSENT UNTIL THE FIRST PUBLISH. `site_app_update`
 * holds {v, at, by, en, ar, show, look, look_v}. It rides the settings snapshot
 * every page already loads, so the row costs no query; and while it is absent
 * page(), SiteApp::version() and so every page and the worker are byte for byte
 * what they were.
 */
final class SiteAppUpdate
{
    public const SETTING = 'site_app_update';

    public const MSG_MAX = 80;

    public const DEFAULT_EN = 'A fresh new look is ready';

    public const DEFAULT_AR = 'مظهر جديد ومنعش بانتظاركِ';

    public function __construct(private SettingsService $settings) {}

    /**
     * @return array{v: int, at: ?string, by: ?string, en: string, ar: string, show: bool, look: ?string, look_v: int}
     */
    public function state(): array
    {
        $raw = $this->settings->get(self::SETTING);
        $raw = is_array($raw) ? $raw : [];
        $int = static fn (mixed $x): int => is_int($x) || (is_string($x) && ctype_digit($x)) ? max(0, min((int) $x, 1_000_000)) : 0;
        $str = static fn (mixed $x): ?string => is_string($x) && $x !== '' ? mb_substr($x, 0, 60) : null;

        return [
            'v' => $int($raw['v'] ?? 0),
            'at' => $str($raw['at'] ?? null),
            'by' => $str($raw['by'] ?? null),
            'en' => self::cleanMessage($raw['en'] ?? null) ?? self::DEFAULT_EN,
            'ar' => self::cleanMessage($raw['ar'] ?? null) ?? self::DEFAULT_AR,
            'show' => (bool) ($raw['show'] ?? false),
            'look' => is_string($raw['look'] ?? null) && preg_match('/\A[0-9a-f]{12}\z/', $raw['look']) ? $raw['look'] : null,
            'look_v' => $int($raw['look_v'] ?? 0),
        ];
    }

    /** The published update number, 0 while nothing was ever published. */
    public function number(): int
    {
        return $this->state()['v'];
    }

    /**
     * What an iPhone keeps from the day it added the app: the name under the
     * icon and the apple-touch-icon. (The top colour is read live from the
     * page's theme-color on iPhone, and Android refreshes all three itself.)
     */
    public static function look(): string
    {
        return substr(hash('sha256', app(SiteApp::class)->all()['name'].'|'.SiteApp::fileHash(SiteApp::iconPath('apple-180'))), 0, 12);
    }

    /** The look a phone that added the app before anything was published has: the shipped name and icon. */
    public static function shippedLook(): string
    {
        return substr(hash('sha256', SiteApp::DEFAULTS['name'].'|'.SiteApp::fileHash(resource_path('site-app/icons/apple-180.png'))), 0, 12);
    }

    /** Has the icon or name moved since the last publish (or since shipping, before the first)? */
    public function lookChanged(): bool
    {
        return self::look() !== ($this->state()['look'] ?? self::shippedLook());
    }

    /**
     * Publish: the next update number, the message, who and when, and the row
     * switched on. Refused, never coerced: a message is plain text, one line,
     * 1 to MSG_MAX characters (empty means the default).
     *
     * @param  array<string,mixed>  $in  {en?: string, ar?: string, icon?: bool}
     * @return array{ok: bool, error?: string}
     */
    public function publish(array $in, string $by): array
    {
        $unknown = array_diff(array_keys($in), ['en', 'ar', 'icon']);
        if ($unknown !== []) {
            return ['ok' => false, 'error' => 'Unknown field: '.implode(', ', $unknown)];
        }

        $s = $this->state();
        $msg = [];
        foreach (['en' => self::DEFAULT_EN, 'ar' => self::DEFAULT_AR] as $k => $default) {
            $v = $in[$k] ?? '';
            if (! is_string($v)) {
                return ['ok' => false, 'error' => 'The message must be text.'];
            }
            if (trim($v) === '') {
                $msg[$k] = $default;

                continue;
            }
            $clean = self::cleanMessage($v);
            if ($clean === null) {
                return ['ok' => false, 'error' => 'The message must be 1 to '.self::MSG_MAX.' characters of plain text, one line.'];
            }
            $msg[$k] = $clean;
        }

        $icon = $in['icon'] ?? $this->lookChanged();
        if (! is_bool($icon)) {
            return ['ok' => false, 'error' => 'New icon or name must be true or false.'];
        }

        $v = $s['v'] + 1;
        $this->settings->set(self::SETTING, [
            'v' => $v,
            'at' => now()->toIso8601String(),
            'by' => self::cleanMessage($by) ?? 'Admin',
            'en' => $msg['en'],
            'ar' => $msg['ar'],
            'show' => true,
            'look' => self::look(),
            'look_v' => $icon ? $v : $s['look_v'],
        ]);

        return ['ok' => true];
    }

    /** The "Show the Update App row" switch. Only once something was published. */
    public function setShow(bool $show): array
    {
        $s = $this->state();
        if ($s['v'] === 0) {
            return ['ok' => false, 'error' => 'Publish an update first.'];
        }
        $raw = $this->settings->get(self::SETTING);
        $raw = is_array($raw) ? $raw : [];
        $raw['show'] = $show;
        $this->settings->set(self::SETTING, $raw);

        return ['ok' => true];
    }

    /**
     * The JSON the footer row carries as data-kfa-up, or '' -- and then the
     * page is byte for byte what it was. Printed through Blade's escaping echo.
     * With the switch off it carries the number alone, so a phone that adds
     * the app meanwhile still starts level and never sees an old update.
     */
    public function page(): string
    {
        $s = $this->state();
        if ($s['v'] === 0) {
            return '';
        }
        if (! $s['show']) {
            return (string) json_encode(['v' => $s['v']]);
        }

        $out = [
            'v' => $s['v'],
            'k' => $s['look_v'],
            't' => Locale::current() === 'ar' ? $s['ar'] : $s['en'],
            'l' => __('store.footer.app_update_line'),
            'b' => __('store.footer.app_update_button'),
            'x' => __('store.footer.app_close'),
        ];
        if ($s['look_v'] > 0) {
            $out['h'] = __('store.footer.app_update_ios');
        }

        return (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function cleanMessage(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        if ($v === '' || mb_strlen($v) > self::MSG_MAX || $v !== strip_tags($v) || preg_match('/[\x00-\x1F\x7F<>]/u', $v)) {
            return null;
        }

        return $v;
    }
}
