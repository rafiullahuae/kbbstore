<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Container\Container;
use Illuminate\Http\Request;

/**
 * The one place that decides what analytics markup the storefront emits.
 *
 * WHAT THIS REPLACES. Three independent Google Analytics loaders and two
 * independent Meta loaders had grown up in this tree, each reading a DIFFERENT
 * setting:
 *
 *   - App\Support\Seo::render() emitted gtag/js + gtag('config') from the SEO
 *     setting `ga`. That block lands in the <head> of every storefront page.
 *   - App\Services\MarketingPixels::baseTags() emitted the same two tags from
 *     the module setting marketing_pixels.ga4_id, and the Meta loader from
 *     marketing_pixels.meta_id. layouts/store.blade.php calls it, in the same
 *     <head>, fifty lines below the Seo call.
 *   - a third, client-side loader reading `ga4_id` and `meta_pixel` off an
 *     /api/settings payload, in a design mock under store/ that was rendered by
 *     no controller and reachable at no URL. The loader went in 2.60.193 and
 *     the mock itself was deleted by Lane DZ; DeadCategoryViewTest establishes
 *     that nothing named it and guards the name against coming back.
 *
 * So any shop that had filled in BOTH Google boxes — and there are two boxes
 * for one ID, which is exactly how that happens — loaded Google's tag twice
 * and called gtag('config', …) twice on every single page. Two configs is two
 * page_view hits: sessions and pageviews roughly double, conversion rate
 * halves, and every per-session metric in the account is wrong.
 *
 * TWO THINGS FIX IT, AND BOTH ARE STRUCTURAL.
 *
 * 1. ONE ID PER NETWORK. The canonical store is the Marketing Pixels module's
 *    own keys, because those are the ones the e-commerce events already hang
 *    off (view_item, begin_checkout, purchase). The SEO screen's `ga` and
 *    `meta_pixel` are now aliases: AdminController reads them back through
 *    this class and writes them through setId(), so the two boxes are two
 *    views of ONE value and cannot disagree. A data migration moved any value
 *    the old rows held across and deleted them.
 *
 * 2. ONE EMIT PER REQUEST. headTags() marks the request the first time it
 *    runs and returns '' for every later call in that same request. The flag
 *    lives on the Request object's own attribute bag, not in a static and not
 *    in a container singleton, so it is scoped to exactly one HTTP request by
 *    construction — a second call cannot be reached by adding a view, an
 *    include, a layout or a partial, and there is no reset for a caller to
 *    forget. Both call sites (Seo has none now; the layout and the five
 *    standalone documents each call it once) go through the same door.
 */
final class Analytics
{
    /** The module toggle that switches the whole thing on. */
    public const MODULE = 'marketing_pixels';

    /**
     * network => [canonical module-setting key, the legacy `settings` row it
     * replaced]. The legacy row is read ONLY when the canonical key is blank,
     * which after the migration means "a row that predates it" — a package
     * applied out of order, or a value typed straight into the database. It
     * can never produce a SECOND live ID, because the canonical key wins
     * whenever it holds anything at all.
     */
    public const KEYS = [
        'ga4' => ['ga4_id', 'ga'],
        'meta' => ['meta_id', 'meta_pixel'],
        'tiktok' => ['tiktok_id', null],
    ];

    /** The legacy `settings` keys, for the migration and for AdminController. */
    public const LEGACY_KEYS = ['ga' => 'ga4', 'meta_pixel' => 'meta'];

    private const REQUEST_FLAG = 'kbb.analytics.head_emitted';

    public function __construct(private SettingsService $settings) {}

    /**
     * The module name is SPELLED OUT here rather than passed as self::MODULE,
     * and that is deliberate.
     *
     * ModuleRegistry marks a module `live` to mean "something on the storefront
     * reads moduleEnabled() for this key", and Phase3ModuleSwitchesTest holds
     * that claim honest by searching app/ and resources/views/ for the literal
     * `moduleEnabled('<key>'`. A constant satisfies PHP and not that search, so
     * moving this read behind self::MODULE would have made the registry's
     * `live` row for Marketing Pixels read as a lie — the exact fault that test
     * exists to catch. One literal, in the one place that reads the switch.
     */
    public function enabled(): bool
    {
        return $this->settings->moduleEnabled('marketing_pixels', false);
    }

    /**
     * The configured ID for a network, canonical key first, legacy row second.
     *
     * Returns '' rather than null so every caller can compare with '' the way
     * MarketingPixels always did. Not shape-checked — that is validId()'s job,
     * and the admin screens want to show back what the owner typed even when
     * it is wrong.
     */
    public function id(string $network): string
    {
        [$moduleKey, $legacyKey] = self::KEYS[$network] ?? [null, null];

        if ($moduleKey === null) {
            return '';
        }

        $value = trim((string) $this->settings->moduleSetting(self::MODULE, $moduleKey, ''));

        if ($value !== '' || $legacyKey === null) {
            return $value;
        }

        return trim((string) $this->settings->get($legacyKey, ''));
    }

    /** @return array<string, string> network => id, blanks included. */
    public function ids(): array
    {
        $out = [];

        foreach (array_keys(self::KEYS) as $network) {
            $out[$network] = $this->id($network);
        }

        return $out;
    }

    /**
     * The ID a tag may actually be built from, or null.
     *
     * A measurement ID is interpolated into a script body and into a URL, and
     * htmlspecialchars() is no defence there — the browser HTML-decodes a
     * script's contents before the JS parser sees them. The defence has to be
     * the value's own shape. This is the check App\Support\Seo already applied
     * to `ga`; MarketingPixels applied none to `ga4_id`, so junk in that box
     * produced a broken script tag on every page.
     */
    public function validId(string $network): ?string
    {
        $value = trim($this->id($network));

        if ($value === '') {
            return null;
        }

        return match ($network) {
            'ga4' => preg_match('/^(?:G-[A-Z0-9]{4,24}|GT-[A-Z0-9]{4,24}|AW-[0-9]{6,20}|UA-[0-9]{4,12}-[0-9]{1,4})$/', strtoupper($value)) === 1
                ? strtoupper($value)
                : null,
            default => $value,
        };
    }

    /** Is this network live on the storefront right now? */
    public function active(string $network): bool
    {
        return $this->enabled() && $this->validId($network) !== null;
    }

    /** Any network at all. */
    public function anyActive(): bool
    {
        foreach (array_keys(self::KEYS) as $network) {
            if ($this->active($network)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write an ID, from either of the two screens that can set it.
     *
     * Three things happen together on purpose:
     *
     *   - the canonical module key is written;
     *   - the legacy `settings` row is deleted, so no second copy survives a
     *     save to be found later by someone wondering which one is live;
     *   - a non-blank ID switches the module on if it was off.
     *
     * The last one keeps the promise the SEO screen has always made — "saving
     * an ID here loads Google's tag on every storefront page". Before this
     * change `ga` bypassed the module entirely; now it routes through it, and
     * a module left off would have made that sentence false. Switching the
     * module on with only a GA4 ID filled in fires nothing but GA4: a pixel
     * with no ID is not an event source, only a gate.
     */
    public function setId(string $network, string $value): void
    {
        [$moduleKey, $legacyKey] = self::KEYS[$network] ?? [null, null];

        if ($moduleKey === null) {
            return;
        }

        $value = mb_substr(trim($value), 0, 60);

        $this->settings->setModuleSetting(self::MODULE, $moduleKey, $value);

        if ($legacyKey !== null) {
            self::forgetLegacy($legacyKey);
        }

        if ($value !== '' && ! $this->enabled()) {
            $this->settings->setModule(self::MODULE, true);
        }
    }

    /** Drop a superseded `settings` row and every cache that held it. */
    public static function forgetLegacy(string $key): void
    {
        Setting::query()->where('key', $key)->delete();

        Setting::flushMap();
        SettingsService::forgetMemo($key);
        app(SettingsService::class)->flush();
    }

    /**
     * Loader tags plus the page-view event, for every configured network —
     * ONCE per request, whoever asks and however many times.
     *
     * The flag is an attribute on the Request. A Request object is created per
     * HTTP request and thrown away with it, so the scope is right without a
     * reset anybody has to remember to call, and two renders inside one
     * request (a layout plus a partial, a view that includes another full
     * document) cannot both emit. With no request bound — console, a queue
     * worker — there is nothing to guard and nothing that renders a page, so
     * the markup is simply returned.
     */
    public function headTags(): string
    {
        if (! $this->enabled()) {
            return '';
        }

        $out = '';
        $meta = $this->validId('meta');
        $ga4 = $this->validId('ga4');
        $tiktok = $this->validId('tiktok');

        if ($meta !== null) {
            $id = json_encode($meta);
            $out .= "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init',{$id});fbq('track','PageView');</script>\n";
        }

        if ($ga4 !== null) {
            $id = json_encode($ga4);
            $src = rawurlencode($ga4);
            $out .= "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$src}\"></script>\n";
            $out .= "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',{$id});</script>\n";
        }

        if ($tiktok !== null) {
            $id = json_encode($tiktok);
            $out .= "<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=i;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};var o=d.createElement('script');o.type='text/javascript';o.async=!0;o.src=i+'?sdkid='+e+'&lib='+t;var a=d.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};ttq.load({$id});ttq.page();}(window,document,'ttq');</script>\n";
        }

        // Claimed only once there is something to claim it FOR, so a shop with
        // the module on and no IDs filled in does not mark the request as
        // "loader emitted" and then report a gtag that is not on the page.
        if ($out === '') {
            return '';
        }

        return $this->claimRequest() ? $out : '';
    }

    /** Has the loader already gone out on this request? Marks it if not. */
    private function claimRequest(): bool
    {
        $container = Container::getInstance();

        if (! $container->bound('request')) {
            return true;
        }

        $request = $container->make('request');

        if (! $request instanceof Request) {
            return true;
        }

        if ($request->attributes->get(self::REQUEST_FLAG) === true) {
            return false;
        }

        $request->attributes->set(self::REQUEST_FLAG, true);

        return true;
    }

    /**
     * Has the loader gone out on this request? Read-only — the event helpers
     * in MarketingPixels use it to answer "is there a gtag/fbq on this page to
     * hang an event on", without claiming the emit for themselves.
     */
    public function headEmitted(): bool
    {
        $container = Container::getInstance();

        if (! $container->bound('request')) {
            return false;
        }

        $request = $container->make('request');

        return $request instanceof Request
            && $request->attributes->get(self::REQUEST_FLAG) === true;
    }
}
