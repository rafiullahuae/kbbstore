<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SpottedPost;
use App\Support\Locale;
use App\Support\RichText;
use Illuminate\Support\Facades\Cache;

/**
 * #KBeautyBliss Spotted: the homepage carousel and the /kbeautybliss-spotted
 * page, their wording, their look, and the posts on them.          (Lane HB)
 *
 * Admin: Appearance → #KBeautyBliss Spotted (resources/views/admin/partials/
 * spotted-screen.blade.php, endpoints in routes/spotted-admin.php).
 *
 * THE OWNER, 3 October 2026 (master plan row 55, item 4): "with instagram feed
 * make it carousel, but with manual selection … button to a new page
 * /kbeautybliss-spotted with a manually selected IG grid … more super beautiful
 * … super responsive. without any bugs, hanging, or broken stuff." His card pick
 * is design B in docs/home-preview/cards.html: a white polaroid frame, slightly
 * tilted, a 4:5 photo with the handle on a dark top scrim, and a caption line.
 *
 * ── WHAT IS PRINTED, AND WHY IT IS SAFE TO PRINT ────────────────────────────
 *
 * section() builds the classes and the inline style from a select's OWN option
 * keys and booleans — never the stored string — so a hand-made POST that stores
 * `per_d = "4;background:url(//x)"` cannot reach the page: cast() puts the
 * default back, and even past cast() pick() only answers one of its literals.
 * Wording goes through {{ }} in the Blade. Links come from SpottedPost::toCard().
 *
 * ── NOTHING SHOWS UNTIL A POST IS PICKED ────────────────────────────────────
 *
 * The section renders nothing at all when no post is ticked "show on the
 * homepage" — no heading over an empty row. That is also how the package ships:
 * the table is created empty.
 */
final class SpottedSettings
{
    /**
     * Stored in `settings` under this prefix, NOT in `module_settings`: the
     * settings map is already loaded on every storefront page, and reading the
     * module map as well cost /shop and the product page one more query each
     * (PageCostBudgetTest measured 19 → 20 and 22 → 23). SlimFooter stores the
     * same way for the same reason.
     */
    public const PREFIX = 'spotted_';

    public const MODULE = 'kbb_spotted';

    public const URL = '/kbeautybliss-spotted/';

    private const CACHE = 'kbb.spotted.cards.';

    private const TTL = 3600;

    private const PX = ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px'];

    public const SCHEMA = [
        /* ── Homepage section ─────────────────────────────────────────────── */
        'home_on' => ['type' => 'bool', 'label' => 'Show the section on the homepage', 'default' => true,
            'help' => 'It also stays hidden while no post is ticked “Homepage” in the list above.'],
        'home_title' => ['type' => 'text', 'label' => 'Heading', 'default' => '',
            'help' => 'Empty: “#KBeautyBliss — Seen on Instagram”.'],
        'home_sub' => ['type' => 'text', 'label' => 'Line under the heading', 'default' => '',
            'help' => 'Empty: “Real unboxings, shelfies and glow-ups from our community across the UAE — tag @kbeauty.bliss to be featured.”'],
        'home_max' => ['type' => 'select', 'label' => 'Posts in the carousel', 'default' => '12',
            'options' => ['4' => '4', '6' => '6', '8' => '8', '10' => '10', '12' => '12', '16' => '16', '20' => '20', '24' => '24'],
            'help' => 'The first ones in the list above that are ticked “Homepage”.'],
        'bg' => ['type' => 'select', 'label' => 'Background', 'default' => 'lilac',
            'options' => ['lilac' => 'Lilac — blush into lavender', 'plain' => 'None — the page shows through'],
            'help' => 'Lilac is the colour from the approved homepage preview.'],

        /* ── Carousel ─────────────────────────────────────────────────────── */
        'per_d' => ['type' => 'select', 'label' => 'Cards in view · laptop', 'default' => '5',
            'options' => ['3' => '3', '4' => '4', '5' => '5', '6' => '6'], 'help' => 'How many fit across before the arrows take over.'],
        'per_m' => ['type' => 'select', 'label' => 'Cards in view · phone', 'default' => '1.5',
            'options' => ['1' => '1', '1.5' => '1½ — the next one peeks', '2' => '2', '2.5' => '2½ — the next one peeks'],
            'help' => 'A peeking card tells a thumb there is more to swipe.'],
        'arrows_d' => ['type' => 'bool', 'label' => 'Arrows · laptop', 'default' => true, 'help' => 'Round arrows at either side.'],
        'arrows_m' => ['type' => 'bool', 'label' => 'Arrows · phone', 'default' => true, 'help' => 'Smaller arrows over the cards; a swipe always works too.'],
        'auto' => ['type' => 'select', 'label' => 'Move on its own', 'default' => '0',
            'options' => ['0' => 'Off', '3' => 'Every 3 seconds', '5' => 'Every 5 seconds', '7' => 'Every 7 seconds', '10' => 'Every 10 seconds'],
            'help' => 'Stops while the shopper hovers, touches or tabs into it, and never runs for a visitor who has asked for reduced motion.'],
        'tilt' => ['type' => 'select', 'label' => 'Card frames', 'default' => 'tilt',
            'options' => ['tilt' => 'Slightly tilted — the polaroid look', 'straight' => 'Straight'],
            'help' => 'Both the homepage and the Spotted page.'],

        /* ── Button ───────────────────────────────────────────────────────── */
        'btn_on' => ['type' => 'bool', 'label' => 'Button to the Spotted page', 'default' => true,
            'help' => 'Under the carousel, in the shop’s button style.'],
        'btn_text' => ['type' => 'text', 'label' => 'Button text', 'default' => '',
            'help' => 'Empty: “See every #KBeautyBliss look”.'],

        /* ── Spacing ──────────────────────────────────────────────────────── */
        'pad_top_d' => ['type' => 'select', 'label' => 'Space above the section · laptop', 'default' => '8', 'options' => self::PX, 'help' => ''],
        'pad_top_m' => ['type' => 'select', 'label' => 'Space above the section · phone', 'default' => '8', 'options' => self::PX, 'help' => ''],
        'pad_bot_d' => ['type' => 'select', 'label' => 'Space below the section · laptop', 'default' => '8', 'options' => self::PX, 'help' => ''],
        'pad_bot_m' => ['type' => 'select', 'label' => 'Space below the section · phone', 'default' => '8', 'options' => self::PX, 'help' => ''],
        'head_gap_d' => ['type' => 'select', 'label' => 'Space under the heading · laptop', 'default' => '24', 'options' => self::PX, 'help' => ''],
        'head_gap_m' => ['type' => 'select', 'label' => 'Space under the heading · phone', 'default' => '16', 'options' => self::PX, 'help' => ''],
        'btn_gap_d' => ['type' => 'select', 'label' => 'Space above the button · laptop', 'default' => '24', 'options' => self::PX, 'help' => ''],
        'btn_gap_m' => ['type' => 'select', 'label' => 'Space above the button · phone', 'default' => '16', 'options' => self::PX, 'help' => ''],

        /* ── The page ─────────────────────────────────────────────────────── */
        'page_h1' => ['type' => 'text', 'label' => 'Page heading (H1)', 'default' => '',
            'help' => 'Empty: “#KBeautyBliss Spotted”.'],
        'page_intro' => ['type' => 'textarea', 'label' => 'Introduction', 'default' => '',
            'help' => 'Empty: a short paragraph about the community and how to be featured.'],
        'page_cols_d' => ['type' => 'select', 'label' => 'Columns · laptop', 'default' => '4',
            'options' => ['3' => '3', '4' => '4', '5' => '5'], 'help' => ''],
        'page_cols_m' => ['type' => 'select', 'label' => 'Columns · phone', 'default' => '2',
            'options' => ['1' => '1', '2' => '2'], 'help' => ''],
        'seo_title' => ['type' => 'text', 'label' => 'Title in Google', 'default' => '',
            'help' => 'Empty: “#KBeautyBliss Spotted — K-Beauty Routines from Our UAE Community”. Under 60 characters reads whole in a result.'],
        'seo_desc' => ['type' => 'textarea', 'label' => 'Description in Google', 'default' => '',
            'help' => 'Empty: a description built from the introduction. 150–160 characters.'],
    ];

    public const TABS = [
        'home' => ['Homepage section', 'The carousel between Brands and Trending Now. It draws nothing at all while no post is ticked “Homepage”.',
            ['home_on', 'home_title', 'home_sub', 'home_max', 'bg']],
        'carousel' => ['Carousel', 'Cards in view, arrows and autoplay — separately for a laptop and a phone (900px and narrower).',
            ['per_d', 'per_m', 'arrows_d', 'arrows_m', 'auto', 'tilt']],
        'button' => ['Button', 'The button under the carousel that opens the Spotted page.',
            ['btn_on', 'btn_text']],
        'spacing' => ['Spacing', 'Each per device.',
            ['pad_top_d', 'pad_top_m', 'pad_bot_d', 'pad_bot_m', 'head_gap_d', 'head_gap_m', 'btn_gap_d', 'btn_gap_m']],
        'page' => ['Spotted page', 'The page at /kbeautybliss-spotted/: its heading, introduction, grid and what Google shows.',
            ['page_h1', 'page_intro', 'page_cols_d', 'page_cols_m', 'seo_title', 'seo_desc']],
    ];

    /**
     * `invalid => default` and `clamp => true`: a select posted with something
     * that is not one of its options stores the default rather than coming back
     * as an error — rule 5, "a select stores one of its own options or the
     * default". `blank => keep`, because an emptied wording box means "use the
     * shipped wording", which the Blade supplies through __() so Arabic gets its
     * own.
     */
    public const POLICY = [
        'max' => 320,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'bool' => 'cast',
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (ModuleSchema::normalise(self::SCHEMA, self::POLICY) as $key => $field) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $field['default'] : ModuleSchema::cast($field, $saved);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{written: list<string>, rejected: array<string, string>}
     */
    public function save(array $values): array
    {
        $fields = ModuleSchema::normalise(self::SCHEMA, self::POLICY);
        $written = [];
        $rejected = [];

        foreach ($values as $key => $value) {
            if (! isset($fields[$key])) {
                continue;
            }

            $cast = ModuleSchema::cast($fields[$key], $value);

            if ($cast === null) {
                $rejected[$key] = (string) $fields[$key]['label'];

                continue;
            }

            $this->settings->set(self::PREFIX.$key, $cast);
            $written[] = $key;
        }

        self::flush();

        return ['written' => $written, 'rejected' => $rejected];
    }

    /**
     * Everything the homepage partial prints, already reduced to literals.
     *
     * @return array{classes:string, style:string, auto:int, title:string, sub:string, button:bool, label:string, show:bool, max:int}
     */
    public function section(): array
    {
        $c = $this->all();

        $classes = [
            'spt',
            self::pick($c, 'bg', ['lilac', 'plain'], 'lilac') === 'lilac' ? 'spt-lilac' : '',
            self::pick($c, 'tilt', ['tilt', 'straight'], 'tilt') === 'tilt' ? 'spt-tilt' : '',
            ($c['arrows_d'] ?? true) ? '' : 'spt-noarr-d',
            ($c['arrows_m'] ?? true) ? '' : 'spt-noarr-m',
        ];

        $px = array_keys(self::PX);
        $px = array_map('strval', $px);

        $style = implode(';', [
            '--spt-per-d:'.self::pick($c, 'per_d', ['3', '4', '5', '6'], '5'),
            '--spt-per-m:'.self::pick($c, 'per_m', ['1', '1.5', '2', '2.5'], '1.5'),
            '--spt-pt-d:'.self::pick($c, 'pad_top_d', $px, '8').'px',
            '--spt-pt-m:'.self::pick($c, 'pad_top_m', $px, '8').'px',
            '--spt-pb-d:'.self::pick($c, 'pad_bot_d', $px, '8').'px',
            '--spt-pb-m:'.self::pick($c, 'pad_bot_m', $px, '8').'px',
            '--spt-hg-d:'.self::pick($c, 'head_gap_d', $px, '24').'px',
            '--spt-hg-m:'.self::pick($c, 'head_gap_m', $px, '16').'px',
            '--spt-bg-d:'.self::pick($c, 'btn_gap_d', $px, '24').'px',
            '--spt-bg-m:'.self::pick($c, 'btn_gap_m', $px, '16').'px',
        ]);

        return [
            'classes' => implode(' ', array_filter($classes)),
            'style' => $style,
            'auto' => (int) self::pick($c, 'auto', ['0', '3', '5', '7', '10'], '0'),
            'title' => self::text($c, 'home_title'),
            'sub' => self::text($c, 'home_sub'),
            'button' => (bool) ($c['btn_on'] ?? true),
            'label' => self::text($c, 'btn_text'),
            'show' => (bool) ($c['home_on'] ?? true),
            'max' => (int) self::pick($c, 'home_max', ['4', '6', '8', '10', '12', '16', '20', '24'], '12'),
        ];
    }

    /**
     * The page's wording and grid, reduced to literals the same way.
     *
     * @return array{classes:string, style:string, h1:string, intro:string, seo_title:string, seo_desc:string}
     */
    public function page(): array
    {
        $c = $this->all();

        return [
            'classes' => implode(' ', array_filter([
                'spt-page',
                self::pick($c, 'tilt', ['tilt', 'straight'], 'tilt') === 'tilt' ? 'spt-tilt' : '',
            ])),
            'style' => '--spt-cols-d:'.self::pick($c, 'page_cols_d', ['3', '4', '5'], '4')
                .';--spt-cols-m:'.self::pick($c, 'page_cols_m', ['1', '2'], '2'),
            'h1' => self::text($c, 'page_h1'),
            'intro' => self::text($c, 'page_intro'),
            'seo_title' => self::text($c, 'seo_title'),
            'seo_desc' => self::text($c, 'seo_desc'),
        ];
    }

    /**
     * The homepage's cards: ticked "Homepage", in the owner's order, at most
     * `home_max`, each through SpottedPost::toCard(). Cached per language,
     * because a linked product's address carries the /ar/ prefix on the Arabic
     * shop; flushed by every admin write, so a save is visible at once.
     *
     * @return list<array<string, mixed>>
     */
    public function homeCards(): array
    {
        $max = $this->section()['max'];

        return array_slice($this->cards('home'), 0, $max);
    }

    /**
     * Whether /kbeautybliss-spotted/ has anything on it. The page asks not to
     * be indexed while it is empty, and the sitemap submits it only when this
     * is true — one question, asked in both places.             (2.60.372)
     */
    public function pageIsLive(): bool
    {
        return $this->pageCards() !== [];
    }

    /** @return list<array<string, mixed>> */
    public function pageCards(): array
    {
        return $this->cards('page');
    }

    /** @return list<array<string, mixed>> */
    private function cards(string $where): array
    {
        $key = self::CACHE.$where.'.'.Locale::current();

        try {
            $cards = Cache::remember($key, self::TTL, fn (): array => self::build($where));
        } catch (\Throwable) {
            // A storefront section must never take the page down: an
            // unreadable table (a migration not yet run) is "no posts".
            return [];
        }

        return is_array($cards) ? array_values($cards) : [];
    }

    /** @return list<array<string, mixed>> */
    private static function build(string $where): array
    {
        $rows = SpottedPost::query()
            ->where($where === 'home' ? 'on_home' : 'on_page', true)
            ->with('product:id,slug,status,is_visible,published_at')
            ->ordered()
            ->limit(200)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $card = $row->toCard();

            if ($card !== null) {
                $out[] = $card;
            }
        }

        return $out;
    }

    public static function flush(): void
    {
        foreach (array_keys(Locale::LOCALES) as $loc) {
            foreach (['home', 'page'] as $where) {
                try {
                    Cache::forget(self::CACHE.$where.'.'.$loc);
                } catch (\Throwable) {
                    // A cold or missing store has nothing to forget.
                }
            }
        }
    }

    /** One of $allowed, never the stored string itself. */
    private static function pick(array $c, string $key, array $allowed, string $default): string
    {
        $v = (string) ($c[$key] ?? $default);

        return in_array($v, $allowed, true) ? $v : $default;
    }

    private static function text(array $c, string $key): string
    {
        return trim(RichText::toText((string) ($c[$key] ?? '')));
    }
}
