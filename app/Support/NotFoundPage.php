<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Store\CollectionController;
use App\Models\Product;
use App\Services\OwnerApp\OwnerAppPath;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shop's 404 page.                                                (Lane NF)
 *
 * The owner: "i want 404 page for my site so nothing can give not found error
 * page … must be related to beauty with broken face type design … aah you
 * shouldn't be here. or you are so beautiful let me take you to the right
 * place". He picked design B (the slipping sheet mask) from
 * docs/nf-preview/, and asked for the other three to stay selectable "on the
 * backend" with full controls under Safety → 404 page → Desktop / Mobile.
 *
 * ── WHO GETS IT ──────────────────────────────────────────────────────────────
 * respond() is called from the NotFoundHttpException renderable in
 * AppServiceProvider, AFTER the stored-redirect check and AFTER
 * NotFoundLogger::record(), so a redirect still wins and the log still
 * records. It answers only a shopper's page request: GET/HEAD, not JSON, not
 * under admin/, admin-api/ or api/, not an owner-app route and not on the
 * owner app's own host. Everything else gets null, and Laravel's default 404
 * runs exactly as before. That is why this is NOT resources/views/errors/404:
 * Laravel renders that file for every HTML 404 in the application, the
 * admin's included.
 *
 * The secret admin path and the owner app's path are deliberately NOT carved
 * out by prefix. A mistyped /<secret>/x answering with a different 404 from
 * /anything-else would be an oracle that spells the secret.
 *
 * ── REAL 404, NEVER A SOFT 200 ───────────────────────────────────────────────
 * A 200 tells a crawler the address is a real page: every dead link becomes an
 * indexed thin duplicate ("soft 404" in Search Console) and spends crawl budget.
 * The status stays 404 and the page also carries noindex.
 *
 * ── SETTINGS ─────────────────────────────────────────────────────────────────
 * One row, `not_found_page`, holding the array defaults() describes. config()
 * re-validates every field on the way out, so a row written behind the
 * screen's back cannot reach the page: an unknown or out-of-range value falls
 * back to its default. Text the owner leaves empty follows the chosen design's
 * own copy, so switching designs never strands another design's words.
 */
final class NotFoundPage
{
    public const KEY = 'not_found_page';

    public const DESIGNS = ['a', 'b', 'c', 'd'];

    /** The owner's choice, 5 October: B. */
    public const DEFAULT_DESIGN = 'b';

    public const LISTS = ['best', 'new', 'sale'];

    public const COUNTS = [4, 6, 8];

    public const LINKS = ['shop', 'sale', 'best', 'wa'];

    public const ALIGNS = ['design', 'start', 'center'];

    /** Plain-text limits, in characters. */
    public const MAX = ['h' => 80, 'em' => 80, 's' => 240, 'label' => 40, 'url' => 300];

    /** [min, max] per device. `art` is a percentage of the design's own size. */
    public const RANGES = [
        'm' => ['art' => [50, 150], 'pt' => [0, 160], 'pb' => [0, 160], 'h1' => [20, 48], 'cols' => [1, 3]],
        'd' => ['art' => [50, 150], 'pt' => [0, 200], 'pb' => [0, 200], 'h1' => [28, 80], 'cols' => [2, 8]],
    ];

    /** The background the headline accent sits on, per design, for the contrast check. */
    public const HERO_BG = ['a' => '#2E1D29', 'b' => '#FFF0F4', 'c' => '#FDE4EE', 'd' => '#FFFFFF'];

    /** Each design's own accent first, then three from its palette. Every one passes MIN_CONTRAST. */
    public const PALETTE = [
        'a' => ['#F2C9A6', '#F7B6C8', '#E8C1E6', '#FFD9A8'],
        'b' => ['#E0567B', '#C13E63', '#A82F53', '#7C5AC7'],
        'c' => ['#D93F69', '#C13E63', '#7C5AC7', '#B5446E'],
        'd' => ['#E0567B', '#C13E63', '#2E8A5E', '#A86A2E'],
    ];

    /** WCAG's large/bold-text floor: the accent is a 29px+ headline line and a bold button. */
    public const MIN_CONTRAST = 3.0;

    public const NAMES = [
        'a' => 'The cracked compact',
        'b' => 'The slipping sheet mask',
        'c' => 'The lipstick smudge',
        'd' => 'The shelf with one product missing',
    ];

    public const COPY = [
        'a' => [
            'en' => ['h' => 'Oops, this page cracked', 'em' => 'under pressure.', 's' => 'Mirror, mirror — the page you wanted has slipped out of view. Your reflection, though? Still flawless. Let’s find you something worth the glow.'],
            'ar' => ['h' => 'عذرًا، هذه الصفحة تشقّقت', 'em' => 'تحت الضغط.', 's' => 'مرآتي يا مرآتي… الصفحة التي تبحثين عنها اختفت. أمّا انعكاسك؟ فما زال مثاليًا. دعينا نجد لكِ ما يليق بتألّقك.'],
        ],
        'b' => [
            'en' => ['h' => 'Aah, you shouldn’t be here…', 'em' => 'but you look gorgeous.', 's' => 'This page slipped off like a sheet mask after twenty minutes. You’re far too beautiful to be lost — let us take you to the right place.'],
            'ar' => ['h' => 'آه، لا يُفترض أن تكوني هنا…', 'em' => 'لكنكِ تبدين رائعة.', 's' => 'انزلقت هذه الصفحة مثل قناع الوجه بعد عشرين دقيقة. أنتِ أجمل من أن تضيعي — دعينا نأخذكِ إلى المكان الصحيح.'],
        ],
        'c' => [
            'en' => ['h' => 'Oh no —', 'em' => 'a little smudge.', 's' => 'This page wiped right off, but your look didn’t. Touch up with a quick search, or let us take you somewhere gorgeous.'],
            'ar' => ['h' => 'أوه —', 'em' => 'لطخة صغيرة!', 's' => 'هذه الصفحة انمسحت تمامًا، لكن إطلالتك لم تتأثر. ابحثي بسرعة عمّا تريدين، أو دعينا نأخذكِ إلى مكان رائع.'],
        ],
        'd' => [
            'en' => ['h' => 'You’re too beautiful', 'em' => 'to be lost.', 's' => 'The page you’re looking for isn’t on our shelf anymore. Let us take you home — everything you love is right this way.'],
            'ar' => ['h' => 'أنتِ أجمل', 'em' => 'من أن تضيعي.', 's' => 'الصفحة التي تبحثين عنها لم تعد على رفّنا. دعينا نعيدكِ إلى الرئيسية — كل ما تحبينه من هنا.'],
        ],
    ];

    /** The page's fixed words. The editable ones (home, the four links) are defaults here. */
    public const LABELS = [
        'en' => [
            'title' => 'Page not found', 'kicker' => 'Error 404 · page not found',
            'home' => 'Take me home', 'search' => 'Search serums, brands…', 'go' => 'Search',
            'shop' => 'Shop all', 'sale' => 'Super Sale', 'best' => 'Best sellers', 'wa' => 'WhatsApp us',
            'links' => 'Helpful links', 'see_all' => 'See all',
            'trend_best' => 'Trending now', 'trend_new' => 'Just landed', 'trend_sale' => 'On sale now',
        ],
        'ar' => [
            'title' => 'الصفحة غير موجودة', 'kicker' => 'خطأ 404 · الصفحة غير موجودة',
            'home' => 'خذيني إلى الرئيسية', 'search' => 'ابحثي عن منتج أو ماركة…', 'go' => 'بحث',
            'shop' => 'تسوّقي الكل', 'sale' => 'التخفيضات الكبرى', 'best' => 'الأكثر مبيعًا', 'wa' => 'راسلينا على واتساب',
            'links' => 'روابط مفيدة', 'see_all' => 'عرض الكل',
            'trend_best' => 'الأكثر رواجًا الآن', 'trend_new' => 'وصل حديثًا', 'trend_sale' => 'تخفيضات الآن',
        ],
    ];

    /** The illustrations' words: each one's label for screen readers, and B's speech bubble. */
    public const ART_TEXT = [
        'en' => ['a' => 'A cracked compact mirror', 'b' => 'A sheet mask slipping off a cute face', 'c' => 'A fallen lipstick that has smeared the number 404', 'd' => 'A skincare shelf with one product missing', 'bubble' => 'aah!'],
        'ar' => ['a' => 'مرآة مكياج متشققة', 'b' => 'قناع ورقي ينزلق عن وجه لطيف', 'c' => 'أحمر شفاه سقط ورسم 404', 'd' => 'رف عناية بالبشرة ينقصه منتج', 'bubble' => 'آه!'],
    ];

    /** Where each quick link and each trending list goes. Constant paths. */
    public const PATHS = ['shop' => '/shop/', 'sale' => '/super-sale/', 'best' => '/best-sellers/', 'new' => '/new-in/'];

    /** The quick links' icons. Constant markup, printed raw. */
    public const ICONS = [
        'shop' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1 12H6z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>',
        'sale' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/></svg>',
        'best' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>',
        'wa' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l1.3-3.9A8 8 0 1 1 8 19z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 .8a4 4 0 0 1-2-2l.8-1-1-2z"/></svg>',
    ];

    private const TREND_TTL = 3600;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $link = ['on' => true, 'en' => '', 'ar' => ''];

        return [
            'design' => self::DEFAULT_DESIGN,
            'text' => ['en' => ['h' => '', 'em' => '', 's' => ''], 'ar' => ['h' => '', 'em' => '', 's' => '']],
            'home' => ['on' => true, 'en' => '', 'ar' => '', 'url' => '/'],
            'search' => true,
            'links' => array_fill_keys(self::LINKS, $link),
            'trend' => ['on' => true, 'list' => 'best', 'count' => 4],
            'motion' => true,
            'accent' => '',
            'd' => ['art_on' => true, 'art' => 100, 'align' => 'design', 'pt' => 56, 'pb' => 64, 'h1' => 50, 'cols' => 4],
            'm' => ['art_on' => true, 'art' => 100, 'align' => 'design', 'pt' => 28, 'pb' => 36, 'h1' => 29, 'cols' => 2],
        ];
    }

    /**
     * The stored settings, every field re-checked: anything unknown, mistyped
     * or out of range is its default. Never throws.
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        try {
            $raw = app(SettingsService::class)->get(self::KEY, []);
        } catch (\Throwable) {
            $raw = [];
        }

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return self::clean(is_array($raw) ? $raw : [], false)[0];
    }

    /**
     * Validate a whole settings array. In strict mode (the admin save) every
     * bad field is an error and nothing is kept; otherwise (reading the row)
     * a bad field quietly takes its default.
     *
     * @param  array<string, mixed>  $in
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function clean(array $in, bool $strict = true): array
    {
        $d = self::defaults();
        $out = $d;
        $errors = [];

        $fail = function (string $field, string $msg) use (&$errors, $strict): void {
            if ($strict) {
                $errors[$field] = $msg;
            }
        };

        $pick = function (array $src, string $key, array $allowed, mixed $default, string $field, string $msg) use ($fail): mixed {
            if (! array_key_exists($key, $src)) {
                return $default;
            }
            if (in_array($src[$key], $allowed, true)) {
                return $src[$key];
            }
            $fail($field, $msg);

            return $default;
        };

        $bool = function (array $src, string $key, bool $default, string $field) use ($fail): bool {
            if (! array_key_exists($key, $src)) {
                return $default;
            }
            if (is_bool($src[$key])) {
                return $src[$key];
            }
            $fail($field, 'Expected on or off.');

            return $default;
        };

        $text = function (array $src, string $key, int $max, string $field) use ($fail): string {
            if (! array_key_exists($key, $src) || $src[$key] === null) {
                return '';
            }
            if (! is_string($src[$key])) {
                $fail($field, 'Expected text.');

                return '';
            }
            $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $src[$key]));
            if (mb_strlen($v) > $max) {
                $fail($field, "At most {$max} characters.");

                return '';
            }

            return $v;
        };

        $out['design'] = $pick($in, 'design', self::DESIGNS, $d['design'], 'design', 'Choose design A, B, C or D.');

        foreach (['en', 'ar'] as $lang) {
            $src = is_array($in['text'][$lang] ?? null) ? $in['text'][$lang] : [];
            foreach (['h', 'em', 's'] as $k) {
                $out['text'][$lang][$k] = $text($src, $k, self::MAX[$k], "text.{$lang}.{$k}");
            }
        }

        $home = is_array($in['home'] ?? null) ? $in['home'] : [];
        $out['home']['on'] = $bool($home, 'on', true, 'home.on');
        $out['home']['en'] = $text($home, 'en', self::MAX['label'], 'home.en');
        $out['home']['ar'] = $text($home, 'ar', self::MAX['label'], 'home.ar');
        if (array_key_exists('url', $home)) {
            $url = is_string($home['url']) ? trim($home['url']) : '';
            if (self::validLink($url)) {
                $out['home']['url'] = $url;
            } else {
                $fail('home.url', 'Use a path on this shop (starting with /) or a full http:// or https:// address.');
            }
        }

        $out['search'] = $bool($in, 'search', true, 'search');

        $links = is_array($in['links'] ?? null) ? $in['links'] : [];
        foreach (self::LINKS as $k) {
            $src = is_array($links[$k] ?? null) ? $links[$k] : [];
            $out['links'][$k] = [
                'on' => $bool($src, 'on', true, "links.{$k}.on"),
                'en' => $text($src, 'en', self::MAX['label'], "links.{$k}.en"),
                'ar' => $text($src, 'ar', self::MAX['label'], "links.{$k}.ar"),
            ];
        }

        $trend = is_array($in['trend'] ?? null) ? $in['trend'] : [];
        $out['trend'] = [
            'on' => $bool($trend, 'on', true, 'trend.on'),
            'list' => $pick($trend, 'list', self::LISTS, 'best', 'trend.list', 'Choose best sellers, new in or Super Sale.'),
            'count' => $pick($trend, 'count', self::COUNTS, 4, 'trend.count', 'Choose 4, 6 or 8.'),
        ];

        $out['motion'] = $bool($in, 'motion', true, 'motion');

        if (array_key_exists('accent', $in) && $in['accent'] !== '' && $in['accent'] !== null) {
            $hex = is_string($in['accent']) ? strtoupper(trim($in['accent'])) : '';
            if (preg_match('/^#[0-9A-F]{6}$/', $hex) !== 1) {
                $fail('accent', 'Use a colour like #E0567B.');
            } else {
                $ratio = self::contrast($hex, self::HERO_BG[$out['design']]);
                if ($ratio < self::MIN_CONTRAST) {
                    $fail('accent', sprintf('Too faint on this design’s background (%.1f:1; it needs %.0f:1 to read).', $ratio, self::MIN_CONTRAST));
                } else {
                    $out['accent'] = $hex;
                }
            }
        }

        foreach (['d', 'm'] as $dev) {
            $src = is_array($in[$dev] ?? null) ? $in[$dev] : [];
            $out[$dev]['art_on'] = $bool($src, 'art_on', true, "{$dev}.art_on");
            $out[$dev]['align'] = $pick($src, 'align', self::ALIGNS, 'design', "{$dev}.align", 'Choose design, start or centre.');
            foreach (self::RANGES[$dev] as $k => [$min, $max]) {
                if (! array_key_exists($k, $src)) {
                    continue;
                }
                $v = $src[$k];
                if (is_int($v) && $v >= $min && $v <= $max) {
                    $out[$dev][$k] = $v;
                } else {
                    $fail("{$dev}.{$k}", "A whole number from {$min} to {$max}.");
                }
            }
        }

        return [$strict && $errors !== [] ? $d : $out, $errors];
    }

    /**
     * Save from the admin. Returns the errors; on any error nothing is written.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, string>
     */
    public static function save(array $in): array
    {
        [$clean, $errors] = self::clean($in, true);

        if ($errors !== []) {
            return $errors;
        }

        app(SettingsService::class)->set(self::KEY, $clean);
        self::forgetTrending();

        return [];
    }

    /** A path on this shop (one leading slash, no spaces) or an absolute http(s) URL. */
    public static function validLink(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > self::MAX['url'] || preg_match('/[\s\x00-\x1F\x7F<>"\'`\\\\]/u', $url) === 1) {
            return false;
        }

        if (preg_match('#^/(?![/\\\\])#', $url) === 1) {
            return true;
        }

        return preg_match('#^https?://[^/?\#]+#i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && SafeUrl::href($url, '') === $url;
    }

    /** WCAG 2 contrast ratio of two #RRGGBB colours. */
    public static function contrast(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $c = [];
            foreach ([1, 3, 5] as $i) {
                $v = hexdec(substr($hex, $i, 2)) / 255;
                $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };

        $x = $lum($a);
        $y = $lum($b);

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /**
     * The sheet. A constant file, never a setting, so it may be printed raw.
     * Not a Vite source: it is inlined on a 404 only, and the admin preview
     * draws from the same text. Read per call (8 KB, from the OS cache) rather
     * than memoised in a static that would outlive a test.
     */
    public static function css(): string
    {
        return (string) @file_get_contents(resource_path('css/inline/kbb-404.css'));
    }

    /**
     * One design's illustration, in the reader's language. The two words in it
     * (its label for screen readers, and B's "aah!") come from ART_TEXT and are
     * escaped by the partial; the rest of the partial is constant markup.
     */
    public static function art(string $design, bool $ar): string
    {
        if (! in_array($design, self::DESIGNS, true)) {
            return '';
        }
        $t = self::ART_TEXT[$ar ? 'ar' : 'en'];

        return view('store.not-found.art-'.$design, ['ar' => $ar, 'alt' => $t[$design], 'bubble' => $t['bubble']])->render();
    }

    /**
     * Answer a 404 with the shop's page, or null to leave Laravel's default
     * response in place. Never throws.
     */
    public static function respond(Request $request): ?Response
    {
        if (! self::isShopperPage($request)) {
            return null;
        }

        try {
            $html = view('store.not-found', self::viewData())->render();
        } catch (\Throwable $e) {
            // The layout could not be drawn (no session, a database outage, a
            // view that is not deployed yet): the plain 404 is the right
            // answer, still with its 404 status. Nothing was written, so
            // there is no half-state to clean up.
            report($e);

            return null;
        }

        return response($html, 404, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function isShopperPage(Request $request): bool
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true) || $request->expectsJson()) {
            return false;
        }

        if ($request->is('admin', 'admin/*', 'admin-api', 'admin-api/*', 'api', 'api/*')) {
            return false;
        }

        $name = (string) optional($request->route())->getName();
        if (str_starts_with($name, 'owner-app.')) {
            return false;
        }

        try {
            $host = OwnerAppPath::host();
        } catch (\Throwable) {
            $host = null;
        }

        return $host === null || strtolower($request->getHost()) !== $host;
    }

    /** @return array<string, mixed> */
    public static function viewData(): array
    {
        $cfg = self::config();
        $lang = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $design = $cfg['design'];
        $copy = self::COPY[$design][$lang];
        $labels = self::LABELS[$lang];

        // The owner's words where he wrote some, the design's own where he did not.
        $text = [];
        foreach (['h', 'em', 's'] as $k) {
            $own = $cfg['text'][$lang][$k];
            $text[$k] = $own !== '' ? $own : $copy[$k];
        }
        // A headline the owner wrote without an accent line keeps no accent line.
        if ($cfg['text'][$lang]['h'] !== '' && $cfg['text'][$lang]['em'] === '') {
            $text['em'] = '';
        }

        $links = [];
        foreach (self::LINKS as $k) {
            if (! $cfg['links'][$k]['on']) {
                continue;
            }
            if ($k === 'wa') {
                $digits = SupportContact::whatsappDigits();
                if ($digits === '') {
                    continue;
                }
                $href = 'https://wa.me/'.$digits;
            } else {
                $href = Url::to(self::PATHS[$k]);
            }
            $label = $cfg['links'][$k][$lang];
            $links[] = ['key' => $k, 'href' => $href, 'label' => $label !== '' ? $label : $labels[$k]];
        }

        $homeUrl = $cfg['home']['url'];
        $home = $cfg['home']['on'] ? [
            'href' => str_starts_with($homeUrl, '/') ? Url::to($homeUrl) : SafeUrl::href($homeUrl, Url::to('/')),
            'label' => $cfg['home'][$lang] !== '' ? $cfg['home'][$lang] : $labels['home'],
        ] : null;

        $trend = null;
        if ($cfg['trend']['on']) {
            $items = self::trending($cfg['trend']['list'], $cfg['trend']['count']);
            if ($items->isNotEmpty()) {
                $list = $cfg['trend']['list'];
                $trend = [
                    'items' => $items,
                    'title' => $labels['trend_'.$list],
                    'href' => Url::to(self::PATHS[$list === 'sale' ? 'sale' : $list]),
                ];
            }
        }

        $style = [];
        foreach (['m', 'd'] as $dev) {
            $v = $cfg[$dev];
            $style[] = "--nf-art-{$dev}:".number_format($v['art'] / 100, 2, '.', '');
            $style[] = "--nf-pt-{$dev}:{$v['pt']}px";
            $style[] = "--nf-pb-{$dev}:{$v['pb']}px";
            $style[] = "--nf-h1-{$dev}:{$v['h1']}px";
            $style[] = "--nf-cols-{$dev}:{$v['cols']}";
        }
        if ($cfg['accent'] !== '') {
            $style[] = '--nf-accent:'.$cfg['accent'];
        }

        $classes = ['nf', 'nf-'.$design];
        foreach (['m', 'd'] as $dev) {
            if (! $cfg[$dev]['art_on']) {
                $classes[] = "nf-noart-{$dev}";
            }
            if ($cfg[$dev]['align'] !== 'design') {
                $classes[] = "nf-al-{$dev}-{$cfg[$dev]['align']}";
            }
        }
        if (! $cfg['motion']) {
            $classes[] = 'nf-still';
        }

        return [
            'nf' => [
                'design' => $design,
                'ar' => $lang === 'ar',
                'labels' => $labels,
                'text' => $text,
                'home' => $home,
                'search' => $cfg['search'],
                'links' => $links,
                'trend' => $trend,
                'class' => implode(' ', $classes),
                'style' => implode(';', $style),
            ],
            'seoCtx' => ['noindex' => true],
        ];
    }

    /**
     * The trending strip: one query, cached for an hour, the same card columns
     * and visibility rule as the listing it links to.
     *
     * @return Collection<int, Product>
     */
    public static function trending(string $list, int $count): Collection
    {
        if (! in_array($list, self::LISTS, true) || ! in_array($count, self::COUNTS, true)) {
            return collect();
        }

        try {
            return Cache::remember(self::trendKey($list, $count), self::TREND_TTL, static function () use ($list, $count): Collection {
                $query = Product::query()
                    ->select(CollectionController::CARD_COLUMNS)
                    ->visible()
                    ->with('brand:id,name,slug');

                match ($list) {
                    'new' => $query->orderByDesc('created_at')->orderByDesc('id'),
                    'best' => RepeatPurchase::applyTo($query),
                    // The Super Sale page's own list when a campaign is running,
                    // so the strip and the page it links to agree.
                    'sale' => ($campaign = SuperSale::campaign(app(SettingsService::class))) !== null
                        ? SuperSale::apply($query, $campaign, SuperSaleOrder::ids(app(SettingsService::class)))
                        : $query->whereNotNull('sale_price')
                        ->where('sale_price', '>', 0)
                        ->whereColumn('sale_price', '<', 'price')
                        ->orderByRaw('(price - sale_price) / price DESC')
                        ->orderByDesc('id'),
                };

                return $query->limit($count)->get();
            });
        } catch (\Throwable) {
            return collect();
        }
    }

    public static function forgetTrending(): void
    {
        foreach (self::LISTS as $list) {
            foreach (self::COUNTS as $count) {
                Cache::forget(self::trendKey($list, $count));
            }
        }
    }

    private static function trendKey(string $list, int $count): string
    {
        return "kbb.notfound.trend.{$list}.{$count}";
    }
}
