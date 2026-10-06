<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SpottedPost;
use App\Support\HomeSections;
use App\Support\Locale;
use App\Support\RichText;
use App\Support\Url;
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

    /** (Lane HS) How many pictures the homepage's static grid draws. */
    public const GRID = 6;

    /**
     * (Lane HS) The placeholder a grid card draws until the owner picks its
     * picture: a camera outline, the shop's deep pink. A constant, so printing
     * it raw is safe; no request is made for it.
     */
    /** The button's arrow, the same path the carousel's button draws. A constant: printed unescaped. */
    public const ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';

    public const CAMERA = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8.5A1.5 1.5 0 0 1 5.5 7h2.2l1.4-2h5.8l1.4 2h2.2A1.5 1.5 0 0 1 20 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 17.5z"/><circle cx="12" cy="13" r="3.4"/></svg>';

    private const PX = ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px'];

    public const SCHEMA = [
        /* ── Homepage section ─────────────────────────────────────────────── */
        'home_on' => ['type' => 'bool', 'label' => 'Show the section on the homepage', 'default' => true,
            'help' => 'It also stays hidden while no post is ticked “Homepage” in the list above.'],
        /* (Lane HS) The owner, 4 October: "for now homepage, there will be 6
           static images, and upon click on any image, it will take the user to
           the actual page". The grid is the default AT HIS REQUEST; the
           carousel stays one choice away. */
        'home_layout' => ['type' => 'select', 'label' => 'Homepage layout', 'default' => 'grid',
            'options' => ['grid' => 'Static grid — 6 pictures, each a link', 'carousel' => 'Carousel — the posts ticked “Homepage” above'],
            'help' => 'The grid’s pictures and links are on the Homepage grid tab.'],
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

        /* ── Homepage grid (Lane HS) ───────────────────────────────────── */
        'grid_cols_d' => ['type' => 'select', 'label' => 'Grid · laptop', 'default' => '3',
            'options' => ['3' => '3 per row — two rows of three', '6' => '6 in one row'],
            'help' => 'A phone always shows three per row.'],
        'grid_1_img' => ['type' => 'text', 'label' => 'Picture 1', 'default' => '', 'max' => 1000, 'options' => ['picker' => 'media'],
            'help' => 'Choose it from the Media Library. Empty: a soft pink placeholder.'],
        'grid_1_url' => ['type' => 'text', 'label' => 'Picture 1 · link', 'default' => '', 'max' => 1000,
            'help' => 'A path on this shop (/…) or a full https:// address. Empty or anything else: the Spotted page.'],
        'grid_1_alt' => ['type' => 'text', 'label' => 'Picture 1 · description (alt text)', 'default' => '',
            'help' => 'For Google and screen readers. Empty: “#KBeautyBliss Spotted photo 1”.'],
        'grid_2_img' => ['type' => 'text', 'label' => 'Picture 2', 'default' => '', 'max' => 1000, 'options' => ['picker' => 'media'],
            'help' => 'Choose it from the Media Library. Empty: a soft pink placeholder.'],
        'grid_2_url' => ['type' => 'text', 'label' => 'Picture 2 · link', 'default' => '', 'max' => 1000,
            'help' => 'A path on this shop (/…) or a full https:// address. Empty or anything else: the Spotted page.'],
        'grid_2_alt' => ['type' => 'text', 'label' => 'Picture 2 · description (alt text)', 'default' => '',
            'help' => 'For Google and screen readers. Empty: “#KBeautyBliss Spotted photo 2”.'],
        'grid_3_img' => ['type' => 'text', 'label' => 'Picture 3', 'default' => '', 'max' => 1000, 'options' => ['picker' => 'media'],
            'help' => 'Choose it from the Media Library. Empty: a soft pink placeholder.'],
        'grid_3_url' => ['type' => 'text', 'label' => 'Picture 3 · link', 'default' => '', 'max' => 1000,
            'help' => 'A path on this shop (/…) or a full https:// address. Empty or anything else: the Spotted page.'],
        'grid_3_alt' => ['type' => 'text', 'label' => 'Picture 3 · description (alt text)', 'default' => '',
            'help' => 'For Google and screen readers. Empty: “#KBeautyBliss Spotted photo 3”.'],
        'grid_4_img' => ['type' => 'text', 'label' => 'Picture 4', 'default' => '', 'max' => 1000, 'options' => ['picker' => 'media'],
            'help' => 'Choose it from the Media Library. Empty: a soft pink placeholder.'],
        'grid_4_url' => ['type' => 'text', 'label' => 'Picture 4 · link', 'default' => '', 'max' => 1000,
            'help' => 'A path on this shop (/…) or a full https:// address. Empty or anything else: the Spotted page.'],
        'grid_4_alt' => ['type' => 'text', 'label' => 'Picture 4 · description (alt text)', 'default' => '',
            'help' => 'For Google and screen readers. Empty: “#KBeautyBliss Spotted photo 4”.'],
        'grid_5_img' => ['type' => 'text', 'label' => 'Picture 5', 'default' => '', 'max' => 1000, 'options' => ['picker' => 'media'],
            'help' => 'Choose it from the Media Library. Empty: a soft pink placeholder.'],
        'grid_5_url' => ['type' => 'text', 'label' => 'Picture 5 · link', 'default' => '', 'max' => 1000,
            'help' => 'A path on this shop (/…) or a full https:// address. Empty or anything else: the Spotted page.'],
        'grid_5_alt' => ['type' => 'text', 'label' => 'Picture 5 · description (alt text)', 'default' => '',
            'help' => 'For Google and screen readers. Empty: “#KBeautyBliss Spotted photo 5”.'],
        'grid_6_img' => ['type' => 'text', 'label' => 'Picture 6', 'default' => '', 'max' => 1000, 'options' => ['picker' => 'media'],
            'help' => 'Choose it from the Media Library. Empty: a soft pink placeholder.'],
        'grid_6_url' => ['type' => 'text', 'label' => 'Picture 6 · link', 'default' => '', 'max' => 1000,
            'help' => 'A path on this shop (/…) or a full https:// address. Empty or anything else: the Spotted page.'],
        'grid_6_alt' => ['type' => 'text', 'label' => 'Picture 6 · description (alt text)', 'default' => '',
            'help' => 'For Google and screen readers. Empty: “#KBeautyBliss Spotted photo 6”.'],

        /* ── Carousel ─────────────────────────────────────────────────────── */
        'per_d' => ['type' => 'select', 'label' => 'Cards in view · laptop', 'default' => '5',
            'options' => ['3' => '3', '4' => '4', '5' => '5', '6' => '6'], 'help' => 'How many fit across before the arrows take over.'],
        /* (Lane PF) The owner, 4 October: 2.2 / 2.3 / 2.5 on a phone so a shopper
           sees there is more, and the phone arrows off. Both defaults moved AT
           HIS REQUEST; the old options stay. */
        'per_m' => ['type' => 'select', 'label' => 'Cards in view · phone', 'default' => '2.3',
            'options' => ['1' => '1', '1.5' => '1½ — the next one peeks', '2' => '2', '2.2' => '2.2 — a sliver of the next one', '2.3' => '2.3 — the next one peeks (recommended)', '2.5' => '2½ — the next one peeks'],
            'help' => 'A part-visible card at the screen edge tells a thumb there is more to swipe.'],
        'arrows_d' => ['type' => 'bool', 'label' => 'Arrows · laptop', 'default' => true, 'help' => 'Round arrows at either side.'],
        'arrows_m' => ['type' => 'bool', 'label' => 'Arrows · phone', 'default' => false, 'help' => 'Off: the peeking card shows there is more, and a swipe moves it. On: smaller arrows over the cards.'],
        'auto' => ['type' => 'select', 'label' => 'Move on its own', 'default' => '0',
            'options' => ['0' => 'Off', '3' => 'Every 3 seconds', '5' => 'Every 5 seconds', '7' => 'Every 7 seconds', '10' => 'Every 10 seconds'],
            'help' => 'Stops while the shopper hovers, touches or tabs into it, and never runs for a visitor who has asked for reduced motion.'],
        'tilt' => ['type' => 'select', 'label' => 'Card frames', 'default' => 'tilt',
            'options' => ['tilt' => 'Slightly tilted — the polaroid look', 'straight' => 'Straight'],
            'help' => 'Both the homepage and the Spotted page.'],

        /* ── Button ───────────────────────────────────────────────────────── */
        'btn_on' => ['type' => 'bool', 'label' => 'Button to the Spotted page', 'default' => true,
            'help' => 'Under the carousel, in the shop’s button style. On the 6-picture grid too (2.60.385).'],
        // (2.60.385) The owner, of the 6-picture grid: "i need the button also
        // on this section", arrowing the space right of the heading. A phone
        // has no room beside the heading, so there it always sits underneath.
        'btn_pos_d' => ['type' => 'select', 'label' => 'Button place · laptop', 'default' => 'top',
            'options' => ['top' => 'Right of the heading', 'bottom' => 'Under the pictures'],
            'help' => 'On a phone it sits under the pictures.'],
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
        /* (Lane SG) The owner, 6 October: the page should be OUR Instagram posts,
           picked from a list, "auto" — so the source ships at `instagram` AT HIS
           REQUEST. Manual stays one choice away, and while nothing is ticked in
           “From Instagram” the page shows the manual posts, so it is never blank. */
        'page_source' => ['type' => 'select', 'label' => 'What the Spotted page shows', 'default' => 'instagram',
            'options' => ['instagram' => 'Instagram — the posts ticked in “From Instagram” above', 'manual' => 'Manual uploads — the posts list above'],
            'help' => 'With Instagram chosen and nothing ticked yet, the manual posts show, so the page is never empty.'],
        /* (Lane SG) "if the post is video, it should play on our website directly
           by embed from the instagram" — ON at his request. Off: a video opens
           Instagram in a new tab, like a photo. */
        /* (Lane SG) The owner, 6 October: "show me the cards designs too" — four
           lettered card styles, every one filled from the synced Instagram data
           alone. A is the shipped one until he picks a letter. */
        'page_card' => ['type' => 'select', 'label' => 'Instagram card style', 'default' => 'a',
            'options' => ['a' => 'A — Instagram native: profile row, square picture, counts, caption', 'b' => 'B — Overlay: caption and counts over the picture',
                'c' => 'C — Soft pink frame: the picture framed, counts as pills', 'd' => 'D — Reel-first: tall tiles, counts down the side like Reels'],
            'help' => 'Only for the posts from Instagram. Everything on the card comes from Instagram itself.'],
        'page_play' => ['type' => 'bool', 'label' => 'Videos play on our page', 'default' => true,
            'help' => 'A tap on a reel or video opens Instagram’s own player over the page. Nothing loads from Instagram until the tap. Off: it opens the post on Instagram.'],
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
        'home' => ['Homepage section', 'The section between Brands and Trending Now: a static grid of six pictures, or the carousel — which draws nothing at all while no post is ticked “Homepage”.',
            ['home_on', 'home_layout', 'home_title', 'home_sub', 'home_max', 'bg']],
        'grid' => ['Homepage grid', 'The six pictures of the static grid, in order — each opens its link. Use ↑ ↓ to reorder, then Save settings.',
            ['grid_cols_d', 'grid_1_img', 'grid_1_url', 'grid_1_alt', 'grid_2_img', 'grid_2_url', 'grid_2_alt', 'grid_3_img', 'grid_3_url', 'grid_3_alt', 'grid_4_img', 'grid_4_url', 'grid_4_alt', 'grid_5_img', 'grid_5_url', 'grid_5_alt', 'grid_6_img', 'grid_6_url', 'grid_6_alt']],
        'carousel' => ['Carousel', 'Cards in view, arrows and autoplay — separately for a laptop and a phone (900px and narrower).',
            ['per_d', 'per_m', 'arrows_d', 'arrows_m', 'auto', 'tilt']],
        'button' => ['Button', 'The button that opens the Spotted page: under the carousel, and on the 6-picture grid beside the heading or under the pictures.',
            ['btn_on', 'btn_text', 'btn_pos_d']],
        'spacing' => ['Spacing', 'Each per device.',
            ['pad_top_d', 'pad_top_m', 'pad_bot_d', 'pad_bot_m', 'head_gap_d', 'head_gap_m', 'btn_gap_d', 'btn_gap_m']],
        'page' => ['Spotted page', 'The page at /kbeautybliss-spotted/: its heading, introduction, grid and what Google shows.',
            ['page_source', 'page_card', 'page_play', 'page_h1', 'page_intro', 'page_cols_d', 'page_cols_m', 'seo_title', 'seo_desc']],
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
     * @return array{classes:string, style:string, auto:int, title:string, sub:string, button:bool, label:string, show:bool, max:int, layout:string, g6:bool}
     */
    public function section(): array
    {
        $c = $this->all();

        $perM = self::pick($c, 'per_m', ['1', '1.5', '2', '2.2', '2.3', '2.5'], '2.3');

        $classes = [
            'spt',
            self::pick($c, 'bg', ['lilac', 'plain'], 'lilac') === 'lilac' ? 'spt-lilac' : '',
            self::pick($c, 'tilt', ['tilt', 'straight'], 'tilt') === 'tilt' ? 'spt-tilt' : '',
            ($c['arrows_d'] ?? true) ? '' : 'spt-noarr-d',
            ($c['arrows_m'] ?? false) ? '' : 'spt-noarr-m',
            // (Lane PF) A fractional count runs the phone track to the screen
            // edge, so the part card is cut by the screen, not the gutter.
            ctype_digit($perM) ? '' : 'spt-peek-m',
        ];

        $px = array_keys(self::PX);
        $px = array_map('strval', $px);

        $style = implode(';', [
            '--spt-per-d:'.self::pick($c, 'per_d', ['3', '4', '5', '6'], '5'),
            '--spt-per-m:'.$perM,
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
            // (2.60.385) The 6-picture grid's button: right of the heading on
            // a laptop, or under the pictures. A phone always has it under.
            'btn_top' => self::pick($c, 'btn_pos_d', ['top', 'bottom'], 'top') === 'top',
            'show' => (bool) ($c['home_on'] ?? true),
            'max' => (int) self::pick($c, 'home_max', ['4', '6', '8', '10', '12', '16', '20', '24'], '12'),
            // (Lane HS) Which of the two the homepage draws, and the grid's
            // laptop row — option keys only.
            'layout' => self::pick($c, 'home_layout', ['grid', 'carousel'], 'grid'),
            'g6' => self::pick($c, 'grid_cols_d', ['3', '6'], '3') === '6',
        ];
    }

    /**
     * (Lane HS) The homepage's static grid: six cards, in the owner's order.
     * Settings only — the map every storefront page has already loaded — so
     * it costs no query and no cache read. A card with no picture is drawn as
     * a placeholder; a link that is not a path on this shop or an http(s)
     * address falls back to the Spotted page.
     *
     * @return list<array{src:string, alt:string, href:string, external:bool}>
     */
    public function grid(): array
    {
        $c = $this->all();
        $page = Url::to(self::URL);
        $host = strtolower((string) request()->getHost());
        $out = [];

        for ($n = 1; $n <= self::GRID; $n++) {
            $href = HomeSections::url((string) ($c["grid_{$n}_url"] ?? ''), $page);
            $alt = self::text($c, "grid_{$n}_alt");

            $out[] = [
                'src' => SpottedPost::imageUrl((string) ($c["grid_{$n}_img"] ?? '')) ?? '',
                'alt' => $alt !== '' ? $alt : (string) __('store.spotted.grid_photo', ['n' => $n]),
                'href' => $href,
                'external' => preg_match('#^https?://#i', $href) === 1
                    && strtolower((string) parse_url($href, PHP_URL_HOST)) !== $host,
            ];
        }

        return $out;
    }

    /**
     * The page's wording and grid, reduced to literals the same way.
     *
     * @return array{classes:string, style:string, h1:string, intro:string, seo_title:string, seo_desc:string, source:string, play:bool, card:string, cols_d:int, cols_m:int}
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
            // (Lane SG) Option keys and a bool, never the stored string.
            'source' => self::pick($c, 'page_source', ['instagram', 'manual'], 'instagram'),
            'play' => (bool) ($c['page_play'] ?? true),
            'card' => self::pick($c, 'page_card', ['a', 'b', 'c', 'd'], 'a'),
            'cols_d' => (int) self::pick($c, 'page_cols_d', ['3', '4', '5'], '4'),
            'cols_m' => (int) self::pick($c, 'page_cols_m', ['1', '2'], '2'),
        ];
    }

    /**
     * (Lane SG) The Instagram posts ticked for the page, when the page's source
     * is Instagram; empty otherwise, or when nothing is ticked — and the caller
     * then draws the manual posts, so the page never goes blank.
     *
     * @return array{profile: array{handle: string, avatar: ?string}, cards: list<array<string, mixed>>}
     */
    public function instagramCards(?string $source = null): array
    {
        if (($source ?? $this->page()['source']) !== 'instagram') {
            return ['profile' => ['handle' => SpottedInstagram::HANDLE, 'avatar' => null], 'cards' => []];
        }

        return app(SpottedInstagram::class)->cards();
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
        return $this->instagramCards()['cards'] !== [] || $this->pageCards() !== [];
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
