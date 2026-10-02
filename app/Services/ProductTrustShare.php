<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SafeUrl;

/**
 * Appearance → Product page → Trust & share: the delivery box, the
 * "Authenticity Guaranteed" line and the share bar.                (Lane PW)
 *
 * The owner, 2 October, pointing at screenshots of his old WordPress page:
 *
 *   "on product page i want two things further, one is the same yellowish
 *    delivery box. the image i'm attaching. and another under add to cart
 *    button, Authenticity guaranteed line with info icon and upon open green
 *    yes tick icon with same description text as attached. and it should
 *    nicely open with slide, and will have cross small cornerd redish circled
 *    icon to close back. need this in desktop + mobile. also give controls to
 *    controls the spacing above and bottom etc. under this section, i want a
 *    nice bar of share it: but more nicely and colorful."
 *
 * and, for the share bar: "each share icon must carry proper url, short
 * description, image etc. and other things if you recommend any."
 *
 * ── ▲ EVERY BLOCK SHIPS ON, BECAUSE HE ASKED FOR EACH OF THEM ──────────────
 *
 * CLAUDE.md, the 30 September reversal: a thing he asked for is the shop's new
 * state, not a switch he has to go and find. So `del_on`, `auth_on` and
 * `share_on` default to TRUE, the wording defaults to the words on his
 * screenshots, and the spacing defaults to what those screenshots measure. The
 * controls exist so he can take any of it back. `share_utm` — tagging shared
 * links for analytics — was recommended to him rather than asked for, and he
 * asked for recommendations in the same sentence; it touches only the link a
 * visitor carries away, never the canonical or og:url, so it ships on too.
 *
 * ── WHY THIS IS NOT MORE FIELDS ON App\Services\ProductLayout ───────────────
 *
 * ProductLayout prints every one of its values into a <style> element and its
 * header says, in capitals, that it has no text field and must not grow one.
 * This screen needs text (the delivery lines, the authenticity paragraphs), an
 * image address and colours. Those are printed through Blade's escaper into
 * markup, or as validated custom-property values in a `style` attribute — the
 * escaper is the right tool there — so they belong to a class whose output
 * goes through `{{ }}`. Same console screen, same endpoint, one more half.
 *
 * ── HOW A VALUE REACHES THE PAGE ────────────────────────────────────────────
 *
 *   text      `{{ }}`, escaped, always. Never `{!! !!}` — rule 5.
 *   image     SafeUrl::src() at save AND at render: http, https or a path.
 *             `javascript:`, `data:` and `//other-host` are refused at both ends.
 *   colour    ModuleSchema's `repair` cast stores `#` + hex, and vars() checks
 *             the shape again before it is printed into a style attribute.
 *   range     an integer clamped into the control's own bounds.
 *   select    one of its own keys, or the default.
 *
 * ── AND IT COSTS NO QUERY ───────────────────────────────────────────────────
 *
 * Every value is a row of `settings`, read through SettingsService's one
 * snapshot that the product page has already taken (ProductLayout reads the
 * same way). all() is memoised for the request on the instance the container
 * hands out, so three partials asking cost one pass over the schema.
 */
class ProductTrustShare
{
    /** Every key is stored as `settings.key` = PREFIX . <schema key>. */
    public const PREFIX = 'pdpts_';

    /** The default wording of the two authenticity paragraphs, verbatim off his screenshot. */
    public const AUTH_TEXT = "We understand the importance of authenticity when it comes to skincare. That is why we go extra mile to verify the authenticity of every product by rigorous quality checks and buying directly from trusted suppliers.\n\nWhen you choose K-Beauty Bliss, you can shop with confidence knowing that you will be getting only the best, 100% authentic skincare and beauty.";

    /**
     * The share networks, in the order the bar draws them.
     *
     * key => [label, brand colour]. The label is the network's own name, which
     * is a proper noun and is not translated; the sentence around it ("Share on
     * :network") is keyed. The colours are each company's published brand
     * colour, and are CONSTANTS — no setting reaches them.
     */
    public const NETWORKS = [
        'whatsapp' => ['WhatsApp', '#25D366'],
        'facebook' => ['Facebook', '#1877F2'],
        'x' => ['X', '#000000'],
        'pinterest' => ['Pinterest', '#E60023'],
        'linkedin' => ['LinkedIn', '#0A66C2'],
        'telegram' => ['Telegram', '#229ED9'],
        'email' => ['Email', '#F2994A'],
        'copy' => ['Copy link', '#8B5CF6'],
    ];

    /**
     * Spacing fields, each [label, default, help]. Expanded into four controls
     * per block below — above and below, phone and laptop.
     */
    private const SPACING = [
        // His delivery box sits right under the payment box with about the same
        // air as above the button row.
        'del' => ['Delivery box', 16, 16],
        // "Authenticity Guaranteed" hugs the button row: ~12px under it.
        'auth' => ['Authenticity line', 12, 0],
        // The share bar sits ~16px under the authenticity line.
        'share' => ['Share bar', 16, 4],
    ];

    /** key => [type, label, default, help, options] */
    public const SCHEMA = [

        /* ═══════════ the delivery box ═════════════════════════════════════ */

        'del_on' => ['bool', 'Show the delivery box', true,
            'The tinted box with the delivery picture and two lines of text, above the quantity and Add to cart.'],
        'del_image' => ['text', 'Delivery picture', '',
            'Your own “FAST DELIVERY” picture. Leave empty to use the drawn truck that ships with the shop. Only an http(s) address or a path on this site is accepted.'],
        'del_line1' => ['text', 'First line', 'Express 1-3 Days Delivery All over UAE',
            'Plain text. The upper line beside the picture.'],
        'del_line2' => ['text', 'Second line', 'Free Delivery over {free_from}',
            '{free_from} is replaced with the free-delivery figure set in Store → Delivery & Shipping for the shopper’s own country, so this line cannot disagree with the checkout. Where that country has no free delivery, the line is left out.'],
        'del_bg' => ['colour', 'Box colour', '#FFF7E6', 'The soft yellow-cream ground of the box.'],
        'del_border' => ['colour', 'Box edge colour', '#F6E2B8', 'A hairline one shade deeper than the box.'],
        'del_ink' => ['colour', 'Text colour', '#2A2228', ''],
        'del_text_s' => ['range', 'Text size', 135, '',
            ['min' => 110, 'max' => 180, 'step' => 5, 'unit' => 'px', 'scale' => 10]],
        'del_logo_m' => ['range', 'Picture width · phone', 90, '',
            ['min' => 50, 'max' => 160, 'step' => 2, 'unit' => 'px']],
        'del_logo_d' => ['range', 'Picture width · laptop', 110, '',
            ['min' => 50, 'max' => 200, 'step' => 2, 'unit' => 'px']],

        /* ═══════════ authenticity ═════════════════════════════════════════ */

        'auth_on' => ['bool', 'Show “Authenticity Guaranteed”', true,
            'The line under Add to cart with a green tick and an ⓘ. Pressing it slides the explanation open; the small red × closes it.'],
        'auth_label' => ['text', 'Line label', 'Authenticity Guaranteed', 'Plain text.'],
        'auth_text' => ['textarea', 'What it opens to', self::AUTH_TEXT,
            'Plain text. Leave a blank line between paragraphs.'],
        'auth_colour' => ['colour', 'Tick colour', '#2E9E6B', 'The green of the check-square and the “yes” tick.'],

        /* ═══════════ share bar ════════════════════════════════════════════ */

        'share_on' => ['bool', 'Show the share bar', true,
            'A row of round buttons in each network’s own colour under the authenticity line.'],
        'share_label' => ['text', 'Label', 'Share', 'The word before the buttons. Plain text.'],
        'share_whatsapp' => ['bool', 'WhatsApp', true, 'Sends the name, the price, the short description and the link.'],
        'share_facebook' => ['bool', 'Facebook', true, 'Facebook reads the picture and description from the page itself.'],
        'share_x' => ['bool', 'X (Twitter)', true, ''],
        'share_pinterest' => ['bool', 'Pinterest', true, 'Pins the main product photograph with the name and description.'],
        'share_linkedin' => ['bool', 'LinkedIn', true, ''],
        'share_telegram' => ['bool', 'Telegram', true, ''],
        'share_email' => ['bool', 'Email', true, ''],
        'share_copy' => ['bool', 'Copy link', true, 'Copies the link and says “Link copied”.'],
        'share_native' => ['bool', '“More” button on phones', true,
            'Opens the phone’s own share sheet (Instagram, Snapchat, Messages…). Only drawn on a phone that has one.'],
        'share_style' => ['select', 'Colours', 'brand', '',
            ['brand' => 'Each network’s own colour', 'mono' => 'One dark colour for all']],
        'share_shape' => ['select', 'Button shape', 'circle', '',
            ['circle' => 'Round', 'rounded' => 'Rounded square']],
        'share_size' => ['range', 'Button size', 34, '',
            ['min' => 26, 'max' => 48, 'step' => 1, 'unit' => 'px']],
        'share_utm' => ['bool', 'Tag shared links for analytics', true,
            'Adds utm_source=whatsapp (and so on) to the link a visitor shares, so visits from shares show up by network in your analytics. The page’s canonical address and its og:url stay clean, so search engines are unaffected.'],
    ];

    /**
     * Four tabs. Same `tab => [label, description, [keys]]` shape as
     * ProductLayout::TABS, so the console's existing tab strip draws them.
     */
    public const TABS = [
        'ts_delivery' => ['Trust · Delivery box',
            'The tinted box above Add to cart: its picture, its two lines and its colours.',
            ['del_on', 'del_image', 'del_line1', 'del_line2', 'del_bg', 'del_border', 'del_ink',
                'del_text_s', 'del_logo_m', 'del_logo_d']],
        'ts_auth' => ['Trust · Authenticity',
            'The “Authenticity Guaranteed” line under Add to cart, and the explanation it slides open.',
            ['auth_on', 'auth_label', 'auth_text', 'auth_colour']],
        'ts_share' => ['Trust · Share bar',
            'Which networks the share bar offers, and how its buttons look.',
            ['share_on', 'share_label', 'share_whatsapp', 'share_facebook', 'share_x', 'share_pinterest',
                'share_linkedin', 'share_telegram', 'share_email', 'share_copy', 'share_native',
                'share_style', 'share_shape', 'share_size', 'share_utm']],
        'ts_space' => ['Trust · Spacing',
            'The air above and below each of the three blocks — separately for a phone (880px and narrower, the page’s own turning point) and a laptop.',
            [
                'del_above_m', 'del_above_d', 'del_below_m', 'del_below_d',
                'auth_above_m', 'auth_above_d', 'auth_below_m', 'auth_below_d',
                'share_above_m', 'share_above_d', 'share_below_m', 'share_below_d',
            ]],
    ];

    /**
     * `invalid => default` and `clamp => true`, ProductLayout's point: every
     * number is a slider, so an out-of-range POST is answered with the shipped
     * value rather than a 422. `blank => default` because every text here is
     * a label the block cannot render without — an emptied box puts his
     * wording back. `markup => strip` because these are plain sentences and a
     * tag in one is furniture, not wording (Blade's escaper is still what makes
     * them safe; see ModuleSchema::castText()).
     */
    public const POLICY = [
        'max' => 600,
        'blank' => 'default',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'words',
        'markup' => 'strip',
    ];

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(private SettingsService $settings) {}

    /**
     * The full schema, with the twelve spacing fields expanded from SPACING.
     *
     * @return array<string, array<int|string, mixed>>
     */
    public static function schema(): array
    {
        $out = self::SCHEMA;

        foreach (self::SPACING as $block => [$label, $above, $below]) {
            foreach (['above' => $above, 'below' => $below] as $side => $default) {
                foreach (['m' => 'phone', 'd' => 'laptop'] as $dev => $devLabel) {
                    $out["{$block}_{$side}_{$dev}"] = ['range', "{$label} · space {$side} · {$devLabel}", $default, '',
                        ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'px']];
                }
            }
        }

        // The image address is stored only when SafeUrl would draw it. A rule
        // replaces the text arm, so it trims and caps here itself.
        $out['del_image'] = [
            'type' => 'text', 'label' => 'Delivery picture', 'default' => '',
            'help' => self::SCHEMA['del_image'][3],
            'rule' => [self::class, 'cleanImage'],
        ];

        return $out;
    }

    /**
     * The delivery picture's own rule: an address SafeUrl would draw, or ''.
     *
     * `''` is a real answer and means "use the drawn truck". A refused address
     * becomes '' rather than the previous value, so a `javascript:` URL typed
     * into the box can never be the thing that is stored.
     */
    public static function cleanImage(mixed $raw, array $field): string
    {
        $value = is_scalar($raw) ? trim((string) $raw) : '';
        $value = mb_substr($value, 0, 500);

        return SafeUrl::src($value);
    }

    /**
     * Every value, saved or shipped.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $out = [];

        foreach (self::fields() as $key => $field) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $field['default'] : $this->cast($key, $saved);
        }

        return $this->memo = $out;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_map(static fn (array $f) => $f['default'], self::fields());
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        $fields = self::fields();

        foreach ($values as $key => $value) {
            if (isset($fields[$key])) {
                $this->settings->set(self::PREFIX.$key, $this->cast($key, $value));
            }
        }

        $this->memo = null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function fields(): array
    {
        return ModuleSchema::normalised(self::class, self::schema(), self::POLICY);
    }

    /**
     * `bool => words`, so a switch stored as the string "0" or "false" — which
     * is how a row comes back out of `settings` — reads as off.
     */
    private function cast(string $key, mixed $value): mixed
    {
        return ModuleSchema::cast(self::fields()[$key], $value);
    }

    /* ═══════════════════════ what reaches the storefront ═══════════════════ */

    /**
     * A plain-text setting, or its keyed default in the page's own language.
     *
     * While the value is still exactly the shipped English, the shop prints the
     * InterfaceStrings key instead — the same English on the English shop,
     * byte for byte, and the reviewed Arabic on /ar. The moment he types his
     * own words, his words are what prints, in every language.
     */
    public function text(string $key): string
    {
        $value = (string) ($this->all()[$key] ?? '');
        $keyed = [
            'del_line1' => 'store.product.pts_del_line1',
            'del_line2' => 'store.product.pts_del_line2',
            'auth_label' => 'store.product.pts_auth_label',
            'auth_text' => 'store.product.pts_auth_text',
            'share_label' => 'store.product.pts_share_label',
        ];

        if (isset($keyed[$key]) && $value === (string) self::fields()[$key]['default']) {
            return (string) __($keyed[$key]);
        }

        return $value;
    }

    /** The authenticity paragraphs, split on a blank line, empties dropped. @return list<string> */
    public function paragraphs(): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $this->text('auth_text'));

        return array_values(array_filter(
            array_map('trim', preg_split('/\n\s*\n/', $text) ?: []),
            static fn (string $p) => $p !== ''
        ));
    }

    /**
     * The second delivery line with {free_from} filled in, or '' to omit it.
     *
     * The figure is ShippingService::thresholdHere() — memoised on the Request,
     * and the same call StoreComposer makes for the layout on this very
     * request, so asking here costs nothing the page was not already paying.
     * Null means the shopper's country has no free delivery, and a sentence
     * promising some is dropped rather than printed with a hole in it.
     */
    public function deliveryLine2(): string
    {
        $line = $this->text('del_line2');

        if (! str_contains($line, '{free_from}')) {
            return $line;
        }

        $free = app(ShippingService::class)->thresholdHere();

        return $free === null ? '' : str_replace('{free_from}', \App\Support\Money::plain($free, 0), $line);
    }

    /** The owner's picture, scheme-checked again at render, or '' for the drawn default. */
    public function deliveryImage(): string
    {
        return SafeUrl::src((string) ($this->all()['del_image'] ?? ''));
    }

    /** A colour as `#RRGGBB`, or the field's default. The second lock, at print time. */
    private function colour(string $key): string
    {
        $v = (string) ($this->all()[$key] ?? '');

        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $v) === 1) {
            return $v;
        }

        if (preg_match('/^#([0-9A-Fa-f])([0-9A-Fa-f])([0-9A-Fa-f])$/', $v, $m) === 1) {
            return '#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        }

        return (string) self::fields()[$key]['default'];
    }

    private function int(string $key): int
    {
        $f = self::fields()[$key];
        $o = is_array($f['options']) ? $f['options'] : [];
        $v = (int) ($this->all()[$key] ?? $f['default']);

        return max((int) ($o['min'] ?? $v), min((int) ($o['max'] ?? $v), $v));
    }

    /**
     * The custom properties one block's root element carries, as one string for
     * its `style` attribute.
     *
     * Only integers and validated six-digit colours ever reach it, so the
     * string cannot contain anything but `--pts-…:<digits>px` and `#hex`; Blade
     * still escapes it on the way into the attribute. The admin preview writes
     * the same property names onto the same elements, from PROPS below.
     */
    public function vars(string $block): string
    {
        $out = [];

        foreach (self::props()[$block] ?? [] as $key => $prop) {
            $f = self::fields()[$key];

            $out[] = $prop.':'.match ($f['type']) {
                'colour' => $this->colour($key),
                'range' => ($f['options']['scale'] ?? 1) > 1
                    ? rtrim(rtrim(number_format($this->int($key) / $f['options']['scale'], 2, '.', ''), '0'), '.').'px'
                    : $this->int($key).'px',
                default => '0',
            };
        }

        return implode(';', $out);
    }

    /**
     * block => [schema key => custom property]. One table, read by the page
     * (vars()) and by the console's live preview, so the two cannot spell a
     * property differently.
     *
     * @return array<string, array<string, string>>
     */
    public static function props(): array
    {
        $space = static fn (string $b): array => [
            "{$b}_above_m" => '--pts-above-m', "{$b}_above_d" => '--pts-above-d',
            "{$b}_below_m" => '--pts-below-m', "{$b}_below_d" => '--pts-below-d',
        ];

        return [
            'del' => $space('del') + [
                'del_bg' => '--pts-bg', 'del_border' => '--pts-edge', 'del_ink' => '--pts-ink',
                'del_text_s' => '--pts-text', 'del_logo_m' => '--pts-logo-m', 'del_logo_d' => '--pts-logo-d',
            ],
            'auth' => $space('auth') + ['auth_colour' => '--pts-tick'],
            'share' => $space('share') + ['share_size' => '--pts-size'],
        ];
    }

    /** One of the select's own keys, or its default. Rule 5's second lock. */
    public function choice(string $key): string
    {
        $f = self::fields()[$key];
        $v = (string) ($this->all()[$key] ?? '');

        return is_array($f['options']) && array_key_exists($v, $f['options']) ? $v : (string) $f['default'];
    }

    public function on(string $key): bool
    {
        return (bool) ($this->all()[$key] ?? false);
    }
}
