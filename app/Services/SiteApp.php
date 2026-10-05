<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Locale;
use App\Support\Url;

/**
 * The shop as a Home Screen app (Lane PW): App → Site App.
 *
 * The owner, 5 October: "just build the app for the site ... give me just one
 * icon to test it that add to my homescreen". So this is the installable core
 * and nothing that offers it: a web app manifest, a service worker, an offline
 * page and the iOS head tags. A shopper installs it from the browser's own
 * menu. How the shop OFFERS the install is decided later
 * (docs/pw-preview/PLAN.md, "Decided later"); head() is the one place that
 * will grow a flag for it.
 *
 * ONE SETTING, AUTOLOADED. `site_app` holds {on, name}. It rides the
 * settings snapshot every page already loads, so the head tags cost no query.
 *
 * ON BY DEFAULT: he asked to test it, so applying the package makes the shop
 * installable (CLAUDE.md rule 1, the 30 September reversal). OFF removes the
 * manifest link and the registration script from every page, and /sw.js then
 * answers a worker that deletes its caches and unregisters itself, so a phone
 * that installed it is left with a plain shortcut and no worker.
 */
final class SiteApp
{
    public const SETTING = 'site_app';

    public const DEFAULTS = ['on' => true, 'name' => 'K-Beauty Bliss'];

    public const NAME_MAX = 30;

    /** The shop's own colours: the header is white, the page cream (--cream). */
    public const THEME = '#FFFFFF';

    public const BACKGROUND = '#FFF8F5';

    /**
     * Icon option 1, the owner's pick for testing: white "KB" on the shop's
     * pink gradient, drawn by tools/pwa-icons.cjs in the shop's Outfit 800.
     * name => [size, manifest purpose or null for the apple-touch-icon].
     */
    public const ICONS = [
        'icon-192' => [192, 'any'],
        'icon-512' => [512, 'any'],
        'maskable-512' => [512, 'maskable'],
        'apple-180' => [180, null],
    ];

    /**
     * Navigations the worker never touches, as public path prefixes after the
     * base path and the locale segment. The shopper's own pages, every
     * payment return (all under /checkout/) and the APIs: the browser handles
     * them exactly as if there were no worker.
     *
     * PUBLIC PATHS ONLY. The admin address and the owner app's address are
     * secrets and /sw.js is a public file, so neither may ever appear here or
     * anywhere in the worker. The worker does not need them: it stores no
     * HTML at all, so a navigation it does see is passed to the network as is.
     */
    public const BYPASS = [
        '/cart', '/cart-panel', '/checkout', '/my-account', '/account-panel', '/orders',
        '/track-my-order', '/wishlist', '/my-wishlist', '/api', '/admin-api', '/payments', '/.well-known',
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array{on: bool, name: string} */
    public function all(): array
    {
        $raw = $this->settings->get(self::SETTING);
        $raw = is_array($raw) ? $raw : [];

        $name = self::cleanName($raw['name'] ?? null);

        return [
            'on' => array_key_exists('on', $raw) ? (bool) $raw['on'] : self::DEFAULTS['on'],
            'name' => $name ?? self::DEFAULTS['name'],
        ];
    }

    public function on(): bool
    {
        return $this->all()['on'];
    }

    /**
     * Validate and store. Unknown keys and a bad name are refused, never
     * coerced: the name is printed into the head of every page and into the
     * manifest, so it is plain text, one line, 1 to 30 characters.
     *
     * @param  array<string,mixed>  $in
     * @return array{ok: bool, error?: string, values: array{on: bool, name: string}}
     */
    public function save(array $in): array
    {
        $unknown = array_diff(array_keys($in), array_keys(self::DEFAULTS));
        if ($unknown !== []) {
            return ['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown), 'values' => $this->all()];
        }

        $next = $this->all();

        if (array_key_exists('on', $in)) {
            if (! is_bool($in['on'])) {
                return ['ok' => false, 'error' => 'On/off must be true or false.', 'values' => $next];
            }
            $next['on'] = $in['on'];
        }

        if (array_key_exists('name', $in)) {
            $name = self::cleanName($in['name']);
            if ($name === null) {
                return ['ok' => false, 'error' => 'The app name must be 1 to '.self::NAME_MAX.' characters of plain text.', 'values' => $this->all()];
            }
            $next['name'] = $name;
        }

        $this->settings->set(self::SETTING, $next);

        return ['ok' => true, 'values' => $next];
    }

    public static function cleanName(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        if ($v === '' || mb_strlen($v) > self::NAME_MAX || $v !== strip_tags($v) || preg_match('/[\x00-\x1F\x7F<>"]/u', $v)) {
            return null;
        }

        return $v;
    }

    /**
     * What the storefront head prints, or null when the app is off. Every
     * value is printed through Blade's escaping echo (partials/site-app-head).
     *
     * @return array{manifest: string, apple: string, name: string, js: string, sw: string, scope: string}|null
     */
    public function head(): ?array
    {
        $v = $this->all();
        if (! $v['on']) {
            return null;
        }

        return [
            // A file name carries no locale segment (Locale::localisable), so
            // the Arabic page names its manifest by query: one address each.
            'manifest' => Url::raw('/manifest.webmanifest').(Locale::isDefault() ? '' : '?lang='.Locale::current()),
            'apple' => self::iconUrl('apple-180'),
            'name' => $v['name'],
            'js' => Url::raw('/site-app.js').'?v='.self::fileHash(self::scriptPath()),
            'sw' => Url::raw('/sw.js'),
            'scope' => Url::raw('/'),
        ];
    }

    /**
     * The manifest in one of the shop's enabled languages. Anything else asked
     * for (an unknown or disabled code) is the default language: the choice
     * is from the allowlist, never the request.
     *
     * @return array<string,mixed>
     */
    public function manifest(?string $locale = null): array
    {
        $locale = ($locale !== null && in_array($locale, Locale::enabledCodes(), true)) ? $locale : Locale::DEFAULT;
        $v = $this->all();
        $icons = [];
        foreach (self::ICONS as $key => [$size, $purpose]) {
            if ($purpose !== null) {
                $icons[] = ['src' => self::iconUrl($key), 'sizes' => $size.'x'.$size, 'type' => 'image/png', 'purpose' => $purpose];
            }
        }

        return [
            'id' => Url::raw('/'),
            'name' => $v['name'],
            'short_name' => $v['name'],
            'lang' => Locale::htmlLang($locale),
            'dir' => Locale::direction($locale),
            'start_url' => Url::raw(ltrim(Locale::withSegment('/', $locale), '/')).'?utm_source=homescreen&utm_medium=app',
            'scope' => Url::raw('/'),
            'display' => 'standalone',
            'theme_color' => self::THEME,
            'background_color' => self::BACKGROUND,
            'categories' => ['shopping', 'beauty'],
            'icons' => $icons,
        ];
    }

    /** The worker, or the self-removing worker when the app is off. */
    public function worker(): string
    {
        if (! $this->on()) {
            return (string) file_get_contents(resource_path('site-app/sw-off.js'));
        }

        $offline = [];
        foreach (Locale::enabledCodes() as $code) {
            $offline[Locale::segment($code)] = Url::raw(ltrim(Locale::withSegment('/offline', $code), '/'));
        }

        $src = (string) file_get_contents(resource_path('site-app/sw.js'));

        return str_replace(
            ['__SA_BASE__', '__SA_VERSION__', '__SA_OFFLINE__', '__SA_BYPASS__', '__SA_ICON__'],
            [
                json_encode(rtrim(Url::raw('/'), '/'), JSON_UNESCAPED_SLASHES),
                json_encode(self::version()),
                json_encode($offline, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
                json_encode(self::BYPASS, JSON_UNESCAPED_SLASHES),
                json_encode(self::iconUrl('icon-192'), JSON_UNESCAPED_SLASHES),
            ],
            $src,
        );
    }

    /**
     * Changes whenever anything the worker precaches or runs changes, so a
     * package that alters any of them makes every installed copy update.
     */
    public static function version(): string
    {
        $parts = [self::fileHash(resource_path('site-app/sw.js')), self::fileHash(self::scriptPath()),
            self::fileHash(resource_path('views/site-app/offline.blade.php'))];
        foreach (array_keys(self::ICONS) as $k) {
            $parts[] = self::fileHash(self::iconPath($k));
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 12);
    }

    /**
     * The owner's uploaded icon when there is one (App → Site App → App icon,
     * Lane IC), else the shipped KB icon. Every reader goes through here: the
     * manifest, the apple-touch-icon, the worker's version and notification
     * icon, the offline page and the icon route itself, so an upload moves all
     * of them at once and "Back to the shipped icon" moves them back.
     */
    public static function iconPath(string $key): string
    {
        return AppIcons::file('site', $key) ?? resource_path('site-app/icons/'.$key.'.png');
    }

    /** A favicon file, or null: there is no shipped favicon (Lane IC). */
    public static function faviconPath(string $key): ?string
    {
        return isset(AppIcons::FAVICON_SET[$key]) ? AppIcons::file('site', $key) : null;
    }

    /**
     * The favicon tags for every storefront page (Lane IC), or [] when nothing
     * has been uploaded -- and then the page is byte for byte what it was.
     * Independent of the app's own switch: a shop with the app off still has
     * a tab icon. The apple-touch-icon rides here only when the app's own head
     * block is off, so a page never names it twice.
     *
     * @return list<array{rel: string, sizes: ?string, href: string}>
     */
    public function favicon(): array
    {
        $out = [];
        foreach (AppIcons::FAVICON_SET as $key => [$size]) {
            $path = self::faviconPath($key);
            if ($path === null) {
                return [];
            }
            $out[] = ['rel' => 'icon', 'sizes' => $size.'x'.$size, 'href' => Url::raw('/site-app/icons/'.$key.'.png').'?v='.self::fileHash($path)];
        }
        if (AppIcons::state('site')['app'] !== null && ! $this->on()) {
            $out[] = ['rel' => 'apple-touch-icon', 'sizes' => null, 'href' => self::iconUrl('apple-180')];
        }

        return $out;
    }

    public static function iconUrl(string $key): string
    {
        return Url::raw('/site-app/icons/'.$key.'.png').'?v='.self::fileHash(self::iconPath($key));
    }

    public static function scriptPath(): string
    {
        return resource_path('site-app/site-app.js');
    }

    /** @var array<string,string> path => first 10 hex of its sha1, per process. */
    private static array $hashes = [];

    /** Forget the file hashes (tests that change a shipped file; StaticMemos). */
    public static function forgetHashes(): void
    {
        self::$hashes = [];
    }

    public static function fileHash(string $path): string
    {
        return self::$hashes[$path] ??= is_file($path) ? substr(sha1_file($path) ?: '', 0, 10) : '0';
    }
}
