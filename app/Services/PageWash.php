<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Color;
use Illuminate\Http\Request;

/**
 * Appearance → Page background — the soft multi-colour wash behind the shop.
 *
 * ── WHAT THE OWNER ASKED FOR ────────────────────────────────────────────────
 *
 * "ALSO i need the whole site background like this multi colors and color
 *  changing time to time, but keep it light same as in screenshot, i mean the
 *  whole background. preview me first how it will look on our site, i need the
 *  live preview please."
 *
 * Two halves, and the second is the deliverable: the preview comes first and
 * NOTHING SWITCHES ON until he has seen it on his own pages and said yes. So
 * `on` ships FALSE, every other field ships at a value that is inert while it
 * is, and `css()` returns the empty string — not a block of defaults — for a
 * shop that has applied the package and touched nothing. See css().
 *
 * ── WHAT THE SHOP ALREADY HAS, MEASURED BEFORE ANYTHING WAS DESIGNED ────────
 *
 * It already has a page wash, and the first correction is that it is not white.
 * There are FOUR `body` rules in resources/css/kbb/kbb.css — lines 181, 874,
 * 1198 and 1599 — and the LAST one wins:
 *
 *     body{background-color:#FDEFF3;
 *          background-image:var(--bg-botanical),
 *            linear-gradient(180deg,#FCE7EE 0,#FDF2F5 26%,#FFF7F4 55%,#FBEAF0 100%);
 *          background-attachment:fixed,fixed}
 *
 * so line 874's `background:#fff` has never reached a shopper. A lane that
 * "adds a background" by overriding the white one would have been overriding a
 * rule two thousand lines dead. What the owner is asking for is that static
 * pink wash made multi-colour and made to drift.
 *
 * `background-attachment:fixed` in that rule is also the reason this class does
 * NOT add a fifth background to `body`: a fixed attachment repaints the whole
 * viewport on every scroll frame on a phone, which is the exact cost the brief
 * says to avoid. The wash is drawn by three FIXED PSEUDO-ELEMENT LAYERS
 * instead, and only their `opacity` moves — see css().
 *
 * ── HOW IT DRIFTS, AND WHY NOT THE OBVIOUS WAY ──────────────────────────────
 *
 * The obvious ways are both the expensive ways:
 *
 *   - animating `background-position` on a full-page gradient repaints the
 *     whole viewport every frame on the main thread;
 *   - `@property`-registered colour custom properties interpolated inside a
 *     `linear-gradient()` re-rasterise the gradient every frame, same cost.
 *
 * So there are three STATIC gradient layers and the only animated property is
 * `opacity`, which the compositor interpolates without touching the main
 * thread or re-rastering anything:
 *
 *     html::before   phase 3, always opaque, z-index -3   (the floor)
 *     body::before   phase 1, opacity 1 → 0 → 0 → 1,  -2
 *     body::after    phase 2, opacity 0 → 1 → 0 → 0,  -1
 *
 * At t=0 you see phase 1. At 1/3 phase 2. At 2/3 BOTH upper layers are
 * transparent and the floor shows through, which is phase 3 — three colour
 * arrangements out of two animated layers and no extra DOM. `body`'s own
 * `background-color` is a flat opaque tint below all three so there is never a
 * frame with nothing behind the text.
 *
 * `html::before`, `html::after`, `body::before` and `body::after` were each
 * checked against every stylesheet and every Blade in this repository before
 * three of them were claimed: none of the four is declared anywhere else.
 *
 * ── prefers-reduced-motion ──────────────────────────────────────────────────
 *
 * The two `animation` declarations are the ONLY thing inside
 * `@media (prefers-reduced-motion: no-preference)`. Stated that way round on
 * purpose: under `reduce`, and under any engine that does not understand the
 * query at all, the layers keep their static opacities and the page is a single
 * still gradient. Motion is added where it is welcome rather than removed where
 * it is not, so the failure mode of a typo is "it never moves", not "it moves
 * for someone who asked it not to".
 *
 * ── RULE 5, ON A STYLESHEET ─────────────────────────────────────────────────
 *
 * Every selector, property, unit and piece of punctuation in css() is a literal
 * in this file. The only things a saved value can influence are integers that
 * have been clamped to their own slider's range and colours that have been
 * through Color::isValidHex() — and an invalid colour reaches no declaration at
 * all: it is replaced by the shipped default for that slot before any string is
 * built. A palette name is looked up in PALETTES, so a name that is not one of
 * this file's own keys selects the shipped palette rather than reaching CSS.
 */
class PageWash
{
    /**
     * ── THE PALETTES ────────────────────────────────────────────────────────
     *
     * Three colours each, in the order they are laid down. Every one of them is
     * already light — the owner's words were "keep it light same as in
     * screenshot" — and `intensity` only ever mixes them FURTHER toward white,
     * never toward saturation, so no slider position on this screen can produce
     * a background darker than the hexes below. That bound is what makes the
     * contrast table in docs/BG-PAGE-BACKGROUND.md a worst case rather than a
     * sample.
     *
     * @var array<string, array{0:string, 1:string, 2:string}>
     */
    public const PALETTES = [
        'cream_blush_lilac' => ['#FFF7EE', '#FDECF1', '#F1EAFA'],
        'mint_cream_blush' => ['#E7F4EC', '#FFF7EC', '#FDEAF0'],
        'lilac_sky_pearl' => ['#EFECFB', '#EAF3FC', '#F7F4FC'],
        'peach_rose_pearl' => ['#FFF1E7', '#FDE9ED', '#F8F5F9'],
    ];

    /**
     * ── THE ONE INVARIANT EVERY PALETTE ABOVE HAS TO HOLD ───────────────────
     *
     * The shop's background today is not white. kbb.css:1599 paints
     * `background-color:#FDEFF3` under a four-stop gradient whose DARKEST stop
     * is `#FCE7EE`, and that is the darkest flat background any storefront page
     * renders. Its contrast against the three text tokens is:
     *
     *     --ink   #2A2228   13.11     --muted #8C828A   3.14
     *     --ink-2 #5E545A    6.16     --pink  #E0567B   3.08
     *
     * Two of those are BELOW 4.5 on the shop as it stands today, which is a
     * finding this lane reports and does not fix — muted body copy and the pink
     * accent have never met AA against this background, and moving them is a
     * decision about the brand, not about a wash.
     *
     * What this lane CAN guarantee, and does, is that the wash never makes any
     * of them worse. Every colour in PALETTES is at or above `#FCE7EE`'s
     * relative luminance, `drift` only ever interpolates between a palette
     * colour and the palette's mean, and `intensity` only ever mixes towards
     * white — so no combination of the two sliders can produce a stop darker
     * than the palette's own darkest member, and therefore none can produce a
     * page darker than the one the shop renders today.
     *
     * PageWashContrastTest walks the ENTIRE reachable set — every drift and
     * every intensity a slider can be on, nine stops per moment — and asserts
     * it against this number. It is not a sample.
     */
    public const CONTRAST_FLOOR = '#FCE7EE';

    /** The palette a shop that has never opened this screen would be on. */
    public const DEFAULT_PALETTE = 'cream_blush_lilac';

    /**
     * ── THE FOUR TREATMENTS THE PREVIEW OFFERS ──────────────────────────────
     *
     * Not four palettes with the same motion — the owner has twice sent options
     * back for being too alike, in those words. Each of these varies on a
     * DIFFERENT axis from the one before it, so they separate at thumbnail
     * size:
     *
     *   a  warm ivory into blush into lilac, seven minutes a cycle, and the
     *      smallest colour travel of the four. If he wants "nobody catches it
     *      happening", this is the one.
     *   b  a different family AND the opposite motion: peach and rose, full
     *      travel, two minutes. Each moment of it leads with a different
     *      colour, so two frames of (b) taken a minute apart are obviously two
     *      different pictures where two frames of (a) are nearly one.
     *   c  green enters the palette, which is the one change no slider can
     *      imitate — mint, cream, blush, four minutes, most of the travel.
     *   d  a different SHAPE. Cool lilac and sky, and the wash only sits behind
     *      the header and fades out by mid-screen, so the rest of the page is a
     *      near-white pearl. It is the one that is distinguishable from the
     *      other three even in greyscale, and the one to choose if the answer
     *      to "how much colour" turns out to be "less than all of it".
     *
     * These are preview presets, not stored rows. Nothing here is written to
     * `settings` by looking at a treatment; the screen's four buttons move the
     * ordinary sliders, and the sliders are the only thing that is saved.
     *
     * @var array<string, array{name:string, palette:string, intensity:int, drift:int, cycle:int, spread:string}>
     */
    public const TREATMENTS = [
        'a' => ['name' => 'Cream drift', 'palette' => 'cream_blush_lilac',
            'intensity' => 100, 'drift' => 40, 'cycle' => 420, 'spread' => 'page'],
        'b' => ['name' => 'Blossom', 'palette' => 'peach_rose_pearl',
            'intensity' => 100, 'drift' => 100, 'cycle' => 120, 'spread' => 'page'],
        'c' => ['name' => 'Mint morning', 'palette' => 'mint_cream_blush',
            'intensity' => 100, 'drift' => 75, 'cycle' => 240, 'spread' => 'page'],
        'd' => ['name' => 'Cool header', 'palette' => 'lilac_sky_pearl',
            'intensity' => 100, 'drift' => 60, 'cycle' => 180, 'spread' => 'top'],
    ];

    /**
     * ── THE SCHEMA ──────────────────────────────────────────────────────────
     *
     * Positional `[type, label, default, help, options]`, the form every schema
     * in this app is written in.
     *
     * `on` IS FALSE AND THAT IS THE WHOLE OF RULE 1 HERE. Every other default
     * below is treatment (a) — the gentlest of the four — so that the first
     * thing the owner sees when he does switch it on is the quietest of them,
     * not the loudest. None of it renders a byte while `on` is false.
     */
    public const SCHEMA = [
        'on' => ['bool', 'Colour wash on', false,
            'Off is how this ships. Nothing about the shop changes until this is switched on — the page keeps the background it has today.'],

        'palette' => ['select', 'Palette', self::DEFAULT_PALETTE,
            'Three colours, blended into one another. Every one of them is already pale; the strength slider below can only take them further towards white.',
            [
                'cream_blush_lilac' => 'Cream → blush → lilac',
                'mint_cream_blush' => 'Mint → cream → blush',
                'lilac_sky_pearl' => 'Lilac → sky → pearl (cool)',
                'peach_rose_pearl' => 'Peach → rose → pearl',
                'custom' => 'Your own three colours',
            ]],

        'c1' => ['colour', 'Your colour 1', '#FFF7EE',
            'Used only when the palette above is "Your own three colours". Keep it pale — text sits on this.'],
        'c2' => ['colour', 'Your colour 2', '#FDECF1',
            'Used only when the palette above is "Your own three colours".'],
        'c3' => ['colour', 'Your colour 3', '#F1EAFA',
            'Used only when the palette above is "Your own three colours".'],

        'intensity' => ['range', 'Strength', 100,
            'How much of the colour reaches the page. 0 is white. 100 is the palette exactly as it is listed, which is already light.',
            ['min' => 0, 'max' => 100, 'step' => 5, 'unit' => '%']],

        'drift' => ['range', 'How far it travels', 40,
            'How different the three moments of the cycle are from each other. At 0 they are identical and the page never appears to change; at 100 each moment leads with a different colour.',
            ['min' => 0, 'max' => 100, 'step' => 5, 'unit' => '%']],

        'cycle' => ['range', 'One full cycle', 420,
            'Seconds for the background to travel through all three moments and back. Long is the point — "time to time" means a drift nobody catches happening. The shortest this allows is a minute.',
            ['min' => 60, 'max' => 900, 'step' => 30, 'unit' => 's']],

        'spread' => ['select', 'Where on the page', 'page',
            'Whether the wash covers the whole page or only sits behind the header and fades out.',
            [
                'page' => 'The whole page',
                'top' => 'Behind the header, fading out',
            ]],

        'where' => ['select', 'Which pages', 'all',
            'The money pages can be left plain. Nothing else on them changes either way.',
            [
                'all' => 'Every page of the shop',
                'content' => 'Everywhere except checkout and the account area',
            ]],
    ];

    public const TABS = [
        'colour' => ['Colour',
            'Three pale colours and how much of them reaches the page. The strength slider can only ever mix towards white, so no position on it produces a background darker than the palette it is listed with.',
            ['on', 'palette', 'c1', 'c2', 'c3', 'intensity']],
        'motion' => ['Motion',
            'Only opacity moves, and only where the visitor has not asked for less motion. Somebody browsing with "reduce motion" on sees one still gradient and no animation at all.',
            ['drift', 'cycle']],
        'reach' => ['Where it applies',
            'Printed documents — the invoice, the packing slip, the delivery note — never carry any of this: they are rendered from invoices/document.blade.php, which loads no site stylesheet at all.',
            ['spread', 'where']],
    ];

    /** Every key lives in `settings`, written by this module's own endpoint. */
    private const STORE = ModuleSchema::STORE_SETTING;

    private const PREFIX = 'wash_';

    /**
     * `invalid => reject` and `clamp => true`: a slider cannot emit a value
     * outside its own range, so a POST that does is a mistake or an attack and
     * neither deserves a stored value. `hex => strict` because these three
     * colours are printed into gradient stops — six digits and a hash or
     * nothing. `blank => default` because there is no wording on this screen an
     * empty box could mean to clear.
     */
    public const POLICY = [
        'max' => 40,
        'blank' => 'default',
        'invalid' => 'reject',
        'clamp' => true,
        'hex' => 'strict',
        'bool' => 'cast',
        'markup' => 'strip',
    ];

    /**
     * The query parameter that asks a storefront page to render a treatment.
     *
     * ── WHY THE PREVIEW IS THE REAL PAGE AND NOT A MOCK-UP ──────────────────
     *
     * The owner asked to see it "on our site". A preview route that rebuilt the
     * homepage would be showing him a copy, and the two things this wash can
     * actually break — a white card going translucent, text losing its
     * background — live on the real pages, in other lanes' stylesheets, behind
     * the real catalogue. So the preview is /, /shop/, /product/{slug}/, /cart/
     * and /skincare-guide/ themselves, with one parameter on them.
     *
     * ── HOW IT IS GATED ─────────────────────────────────────────────────────
     *
     * Three conditions, cheapest first, and the last is the one that matters:
     *
     *   1. the parameter is present at all — a string comparison on a query
     *      value, which is what keeps this free on the 99.99% of requests that
     *      do not carry it (rule 4: no query cost — nothing below runs);
     *   2. its value is a key of TREATMENTS, so only this file's own constants
     *      can ever select a preset;
     *   3. an ADMIN SESSION, checked on the `admin` guard specifically.
     *
     * A signed-out visitor to extrabeauty.ae/?kbbwash=b gets the shop exactly
     * as it is today: the parameter is inert, no style block is emitted, and
     * the page is byte-identical to the one without it. PageWashTest asserts
     * that over HTTP rather than describing it.
     *
     * NOT a signed URL, which was the first draft. A signed URL is a bearer
     * token in an address bar: the owner would paste one to show somebody and
     * hand out a working preview of an unreleased design, and it would keep
     * working after he was signed out. The admin session is the narrower answer
     * and it is the one the rest of this console already uses.
     */
    public const PREVIEW_PARAM = 'kbbwash';

    /**
     * The first path segments `where => content` leaves plain.
     *
     * Checkout and the account area, and nothing else. NOT App\Support\
     * Indexability::isPrivate(), which was the first draft and which also
     * carries /cart — the cart is one of the five pages the owner explicitly
     * asked to see the wash behind, so borrowing that list would have silently
     * excluded a page he named. A literal list of three, stated here, where
     * somebody changing it can see what it is for.
     */
    private const PLAIN_PREFIXES = ['checkout', 'my-account', 'order-received'];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, array<string, mixed>> */
    public static function normalised(): array
    {
        return ModuleSchema::normalised('page_wash', self::SCHEMA, self::POLICY, self::overrides());
    }

    /** @return array<string, array<string, mixed>> */
    public static function overrides(): array
    {
        $out = [];

        foreach (array_keys(self::SCHEMA) as $key) {
            $out[$key] = ['store' => self::STORE, 'alias' => self::PREFIX.$key];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (self::normalised() as $key => $field) {
            $saved = $this->settings->get($field['alias'], null);

            $out[$key] = $saved === null
                ? $field['default']
                : (ModuleSchema::cast($field, $saved) ?? $field['default']);
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Save, returning the keys it refused so the caller can report them.
     *
     * @param  array<string, mixed>  $values
     * @return array{written: list<string>, rejected: array<string, string>}
     */
    public function save(array $values): array
    {
        $written = [];
        $rejected = [];

        foreach (self::normalised() as $key => $field) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $cast = ModuleSchema::cast($field, $values[$key]);

            if ($cast === null) {
                $rejected[$key] = $field['label'];

                continue;
            }

            $this->settings->set($field['alias'], is_bool($cast) ? ($cast ? '1' : '0') : (string) $cast);
            $written[] = $key;
        }

        return ['written' => $written, 'rejected' => $rejected];
    }

    /** True while every field is still at its shipped value — which includes off. */
    public function isDefault(): bool
    {
        $values = $this->all();

        foreach (self::normalised() as $key => $field) {
            if ($values[$key] !== $field['default']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The treatment this request is asking to preview, or null.
     *
     * See PREVIEW_PARAM for the three conditions and the order they are in.
     */
    public function previewTreatment(?Request $request = null): ?string
    {
        $request ??= request();

        $key = $request?->query(self::PREVIEW_PARAM);

        if (! is_string($key) || ! array_key_exists($key, self::TREATMENTS)) {
            return null;
        }

        return auth()->guard('admin')->check() ? $key : null;
    }

    /**
     * The values the page should render with: the saved ones, or a treatment.
     *
     * @return array<string, mixed>
     */
    public function effective(?Request $request = null): array
    {
        $values = $this->all();
        $treatment = $this->previewTreatment($request);

        if ($treatment === null) {
            return $values;
        }

        /*
         * A preview forces `on`, because the whole point of it is to see a
         * thing that is switched off. It overrides only the six keys a
         * treatment names; `where`, and the three custom colours, stay as the
         * shop has them, so a preview of the shop's own reach is what he sees.
         */
        return array_merge($values, self::TREATMENTS[$treatment], ['on' => true]);
    }

    /**
     * The three colours this shop's palette resolves to, before intensity.
     *
     * @param  array<string, mixed>  $values
     * @return array{0:string, 1:string, 2:string}
     */
    public static function paletteOf(array $values): array
    {
        $name = is_string($values['palette'] ?? null) ? $values['palette'] : self::DEFAULT_PALETTE;

        if ($name !== 'custom') {
            return self::PALETTES[$name] ?? self::PALETTES[self::DEFAULT_PALETTE];
        }

        $out = [];

        foreach (['c1', 'c2', 'c3'] as $i => $key) {
            $raw = $values[$key] ?? null;

            /*
             * AN INVALID COLOUR REACHES NO DECLARATION. Not repaired, not
             * partially emitted — replaced, here, before any string is built,
             * by the shipped default for that same slot. A gradient with a stop
             * missing is not "safer", it is a broken declaration; a gradient
             * with a garbage stop is rule 5 broken outright. The only way a
             * value can be in this array at all is to be six hex digits and a
             * hash, which is also what the save policy enforces, so this is the
             * second of two locks rather than the only one.
             */
            $out[$i] = (is_string($raw) && Color::isValidHex($raw) && preg_match('/^#[0-9a-fA-F]{6}$/', $raw) === 1)
                ? strtoupper($raw)
                : (string) self::SCHEMA[$key][2];
        }

        return [$out[0], $out[1], $out[2]];
    }

    /** @return array{0:int, 1:int, 2:int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');

        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }

        return [
            (int) hexdec(substr($h, 0, 2)),
            (int) hexdec(substr($h, 2, 2)),
            (int) hexdec(substr($h, 4, 2)),
        ];
    }

    /**
     * The three moments of the cycle, each as three `[r,g,b]` triples.
     *
     * ── WHAT `drift` AND `intensity` ACTUALLY DO, AND WHY IT IS ARITHMETIC ───
     *
     * Both are mixes, computed here in PHP, so the colour that lands in a
     * gradient stop is known exactly at build time rather than being something
     * a browser works out. That is what lets docs/BG-PAGE-BACKGROUND.md carry a
     * real contrast table instead of a sample of screenshots: for any slider
     * position, the darkest colour any of the nine stops can take is computable
     * and is checked by PageWashTest.
     *
     *   moment 1  (c1, c2, c3)     the palette as listed
     *   moment 2  (c2, c3, c1)     rotated once
     *   moment 3  (c3, c1, c2)     rotated twice
     *
     * `drift` pulls each moment back towards the palette's MEAN colour by
     * (100 − drift)%. At drift 0 all three moments are the same flat mean and
     * the page genuinely never appears to change — the animation still runs,
     * and shows nothing, which is the honest behaviour for a "how far it
     * travels" control at zero. At 100 the rotation is undiluted.
     *
     * `intensity` then mixes every one of the nine results towards white by
     * (100 − intensity)%. Applied LAST and to every stop equally, so it cannot
     * change the RELATIVE arrangement — turning the strength down cannot make
     * one corner of the page darker than another by accident.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, array<int, array{0:int, 1:int, 2:int}>>
     */
    public static function moments(array $values): array
    {
        $palette = self::paletteOf($values);
        $drift = max(0, min(100, (int) ($values['drift'] ?? 0)));
        $intensity = max(0, min(100, (int) ($values['intensity'] ?? 0)));

        $rgb = array_map(self::rgb(...), $palette);

        $mean = [
            (int) round(($rgb[0][0] + $rgb[1][0] + $rgb[2][0]) / 3),
            (int) round(($rgb[0][1] + $rgb[1][1] + $rgb[2][1]) / 3),
            (int) round(($rgb[0][2] + $rgb[1][2] + $rgb[2][2]) / 3),
        ];

        $d = $drift / 100;
        $w = (100 - $intensity) / 100;

        $out = [];

        foreach ([[0, 1, 2], [1, 2, 0], [2, 0, 1]] as $moment => $order) {
            foreach ($order as $slot => $index) {
                $c = $rgb[$index];

                $out[$moment][$slot] = [
                    self::mixToWhite((int) round($mean[0] + ($c[0] - $mean[0]) * $d), $w),
                    self::mixToWhite((int) round($mean[1] + ($c[1] - $mean[1]) * $d), $w),
                    self::mixToWhite((int) round($mean[2] + ($c[2] - $mean[2]) * $d), $w),
                ];
            }
        }

        return $out;
    }

    private static function mixToWhite(int $channel, float $towards): int
    {
        return (int) round($channel + (255 - $channel) * $towards);
    }

    /**
     * The darkest of the nine stops these values produce.
     *
     * The whole contrast story of this screen is one number, and this is where
     * it comes from. Dark text on a light background gets WORSE as the
     * background darkens, monotonically, so the worst moment of the cycle is
     * whichever stop has the lowest relative luminance — there is no need to
     * sample the animation, because the set of colours it passes through is
     * exactly the set of colours between these nine and they are all lighter
     * than this one.
     *
     * @param  array<string, mixed>  $values
     * @return array{0:int, 1:int, 2:int}
     */
    public static function darkestStop(array $values): array
    {
        $worst = [255, 255, 255];
        $worstLum = 2.0;

        foreach (self::moments($values) as $moment) {
            foreach ($moment as $c) {
                $l = self::luminance($c);

                if ($l < $worstLum) {
                    $worstLum = $l;
                    $worst = $c;
                }
            }
        }

        return $worst;
    }

    /**
     * WCAG relative luminance of an `[r, g, b]` triple.
     *
     * @param  array{0:int, 1:int, 2:int}  $c
     */
    public static function luminance(array $c): float
    {
        $f = static function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
    }

    /**
     * The WCAG contrast ratio between two `[r, g, b]` triples.
     *
     * @param  array{0:int, 1:int, 2:int}  $a
     * @param  array{0:int, 1:int, 2:int}  $b
     */
    public static function contrast(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * The storefront text colours this wash sits behind, from kbb.css's :root.
     *
     * Named here rather than read out of the stylesheet because these five are
     * what the contrast table in docs/BG-PAGE-BACKGROUND.md is computed
     * against, and a table whose inputs can move without anybody noticing is
     * not evidence. PageWashContrastTest reads kbb.css and fails if any of the
     * five has changed there, so the copy and the original cannot drift.
     *
     * @var array<string, string>
     */
    public const TEXT_TOKENS = [
        '--ink' => '#2A2228',
        '--ink-2' => '#5E545A',
        '--muted' => '#8C828A',
        '--pink' => '#E0567B',
        '--pink-deep' => '#C13E63',
    ];

    /**
     * The worst-case contrast of each text token against these values.
     *
     * Shown on the screen beside the sliders, so that somebody choosing three
     * colours of their own can see what they cost before they save them — which
     * is the only place a custom palette can be checked at all, since the four
     * built-in ones are checked by a test.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, float>
     */
    public static function contrastReport(array $values): array
    {
        $bg = self::darkestStop($values);
        $out = [];

        foreach (self::TEXT_TOKENS as $token => $hex) {
            $out[$token] = round(self::contrast(self::rgb($hex), $bg), 2);
        }

        return $out;
    }

    /**
     * The flat colour painted under every layer — the palest of the nine stops.
     *
     * There is never a frame with nothing behind the text: the two animated
     * layers can both be transparent at once (that is how the third moment is
     * drawn out of two elements), and this is what is underneath when they are.
     * It is `body`'s own `background-color`, so it propagates to the canvas and
     * fills the over-scroll area too.
     *
     * @param  array<string, mixed>  $values
     */
    public static function floorColour(array $values): string
    {
        $best = null;
        $bestSum = -1;

        foreach (self::moments($values) as $moment) {
            foreach ($moment as $c) {
                $sum = $c[0] + $c[1] + $c[2];

                if ($sum > $bestSum) {
                    $bestSum = $sum;
                    $best = $c;
                }
            }
        }

        $best ??= [255, 255, 255];

        return sprintf('#%02X%02X%02X', $best[0], $best[1], $best[2]);
    }

    /**
     * The whole stylesheet this shop needs, or '' when it needs none.
     *
     * ── WHY EMPTY RATHER THAN A BLOCK OF DEFAULTS ───────────────────────────
     *
     * Rule 1, and the same argument SiteLayout::cssVariables() makes two lines
     * away in the layout: restating the shipped state here would be correct in
     * pixels and wrong in bytes — a new `<style>` element in the `<head>` of
     * every storefront page at once, which is exactly what
     * StorefrontEnglishUnchangedTest is pinning, for a change that renders
     * identically. So a shop that applies this package and touches nothing
     * gains not one byte on any page.
     *
     * ── THE FOUR THINGS THIS DELIBERATELY DOES NOT TOUCH ────────────────────
     *
     * 1. It declares nothing on any card, panel, header, drawer or modal. Every
     *    white surface on the shop keeps the background and the border it has;
     *    the wash is three layers at NEGATIVE z-index, behind all of them.
     * 2. It does not add a `background-attachment:fixed` image. kbb.css's
     *    `body` rule has two, and that is the property that makes a phone
     *    repaint the viewport on every scroll frame. The layers here are
     *    `position:fixed` elements, which the compositor moves for free.
     * 3. It sets `pointer-events:none` on all three, so nothing on the page
     *    becomes unclickable if a layer ever ends up in front of something.
     * 4. It ends with a `@media print` rule that hides all three and puts the
     *    page back to white paper. The printed documents — invoice, packing
     *    slip, delivery note, shipping label — cannot reach this block at all
     *    (they extend invoices/document.blade.php, which loads no site
     *    stylesheet), and PageWashTest asserts that rather than assuming it;
     *    the print rule is for a shopper printing a product page.
     */
    public function css(?Request $request = null): string
    {
        $values = $this->effective($request);

        if (empty($values['on'])) {
            return '';
        }

        if (($values['where'] ?? 'all') === 'content' && $this->isPlainPath($request)) {
            return '';
        }

        $moments = self::moments($values);
        $floor = self::floorColour($values);

        // Seconds, clamped to this field's own range by the schema, then again
        // here: the only number that reaches an `animation` shorthand.
        $cycle = max(60, min(900, (int) ($values['cycle'] ?? 300)));

        $top = ($values['spread'] ?? 'page') === 'top';

        /*
         * THE BOX. `inset:0` and `position:fixed` for the whole-page form;
         * `position:absolute` with a logical inset and a height for the
         * fade-out form, so that one scrolls away with the page while the other
         * stays put.
         *
         * `inset-inline:0` rather than `left:0;right:0` — the two are identical
         * at zero, and the logical form keeps this out of the 57 physical
         * direction declarations Lane G is still working through.
         *
         * The absolute form cannot widen the page: its containing block is the
         * initial containing block, so it is exactly the viewport's width, and
         * `document.documentElement.scrollWidth` is unchanged at both widths.
         * Measured, not assumed — see docs/BG-PAGE-BACKGROUND.md.
         */
        $box = $top
            ? 'position:absolute;top:0;inset-inline:0;height:78vh;'
                .'-webkit-mask-image:linear-gradient(to bottom,#000 0,#000 34%,rgba(0,0,0,0) 100%);'
                .'mask-image:linear-gradient(to bottom,#000 0,#000 34%,rgba(0,0,0,0) 100%);'
            : 'position:fixed;inset:0;';

        $common = "content:'';display:block;{$box}pointer-events:none;";

        $css = 'body{background-color:'.$floor.';background-image:none}';

        $css .= 'html::before{'.$common.'z-index:-3;background:'.self::gradient($moments[2], 2).'}';
        $css .= 'body::before{'.$common.'z-index:-2;opacity:1;background:'.self::gradient($moments[0], 0).'}';
        $css .= 'body::after{'.$common.'z-index:-1;opacity:0;background:'.self::gradient($moments[1], 1).'}';

        /*
         * Three moments out of two animated layers. ::before is the only thing
         * visible at 0% and again at 100%; ::after takes over at 33%; at 66%
         * both are transparent and html::before — the floor — is what shows.
         *
         * `ease-in-out` rather than `linear` so each moment DWELLS instead of
         * sliding straight through. `alternate` is deliberately not used: the
         * cycle has to come back to where it started for the loop to be
         * seamless, and these keyframes already do.
         */
        $css .= '@keyframes kbb-wash-a{0%{opacity:1}33%{opacity:0}66%{opacity:0}100%{opacity:1}}';
        $css .= '@keyframes kbb-wash-b{0%{opacity:0}33%{opacity:1}66%{opacity:0}100%{opacity:0}}';

        $css .= '@media(prefers-reduced-motion:no-preference){'
            .'body::before{animation:kbb-wash-a '.$cycle.'s ease-in-out infinite}'
            .'body::after{animation:kbb-wash-b '.$cycle.'s ease-in-out infinite}'
            .'}';

        $css .= '@media print{html::before,body::before,body::after{display:none}body{background-color:#fff}}';

        return $css;
    }

    /**
     * One moment, as a CSS `background` shorthand.
     *
     * Three soft radial blobs over a linear base. The blobs fade to
     * `rgba(r,g,b,0)` — THE SAME COLOUR AT ZERO ALPHA, never the keyword
     * `transparent`, which is `rgba(0,0,0,0)` and drags a grey bruise through
     * the middle of every gradient it appears in. That is the one thing about
     * this shorthand that is not cosmetic.
     *
     * The geometry is three fixed arrangements, one per moment, and every
     * number in them is a literal here. `drift` moves the COLOURS between
     * moments; it does not move the blobs, because a blob that travels needs a
     * transform to do it smoothly and a transformed full-width layer is how a
     * page grows a horizontal scrollbar.
     *
     * @param  array<int, array{0:int, 1:int, 2:int}>  $moment
     */
    private static function gradient(array $moment, int $index): string
    {
        $g = static fn (array $c): string => sprintf('rgb(%d,%d,%d)', $c[0], $c[1], $c[2]);
        $t = static fn (array $c): string => sprintf('rgba(%d,%d,%d,0)', $c[0], $c[1], $c[2]);

        $shapes = [
            ['120% 92% at 14% 6%', '112% 86% at 88% 20%', '136% 104% at 46% 104%', '168deg'],
            ['128% 96% at 84% 4%', '118% 90% at 10% 26%', '130% 100% at 58% 100%', '198deg'],
            ['116% 88% at 50% 0%', '124% 94% at 6% 62%', '128% 98% at 96% 88%', '146deg'],
        ];

        [$s1, $s2, $s3, $angle] = $shapes[$index];

        return 'radial-gradient('.$s1.','.$g($moment[0]).' 0%,'.$t($moment[0]).' 62%),'
            .'radial-gradient('.$s2.','.$g($moment[1]).' 0%,'.$t($moment[1]).' 58%),'
            .'radial-gradient('.$s3.','.$g($moment[2]).' 0%,'.$t($moment[2]).' 66%),'
            .'linear-gradient('.$angle.','.$g($moment[0]).' 0%,'.$g($moment[1]).' 52%,'.$g($moment[2]).' 100%)';
    }

    private function isPlainPath(?Request $request = null): bool
    {
        $request ??= request();

        $path = trim((string) ($request?->getPathInfo() ?? '/'), '/');

        if ($path === '') {
            return false;
        }

        /*
         * The LANGUAGE SEGMENT IS STRIPPED FIRST, and it is not a nicety: with
         * Arabic on, the checkout is /ar/checkout/, so a first-segment compare
         * that did not know about the prefix would leave the Arabic checkout
         * washed while the English one was plain. App\Support\Locale owns the
         * list of codes; nothing here invents one.
         */
        $segments = explode('/', $path);

        if (in_array($segments[0], \App\Support\Locale::codes(), true)) {
            array_shift($segments);
        }

        return in_array($segments[0] ?? '', self::PLAIN_PREFIXES, true);
    }
}
