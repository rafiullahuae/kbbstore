<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Coupon;
use App\Support\Locale;

/**
 * The cart panel — Desktop and Mobile, and what each line shows.
 *
 * Everything here is emitted as CSS custom properties on the panel itself, or as
 * classes on it, so the stylesheet stays static and cacheable and a shop that
 * never opens this screen renders exactly what it does today. Every default
 * below is the value that was hard-coded before.
 *
 * There is no on/off switch for the panel. The cart is not optional.
 *
 * ── AN INLINE style ATTRIBUTE BEATS EVERY MEDIA QUERY ────────────────────────
 *
 * partials/drawers.blade.php renders the panel as
 *
 *     <aside class="drawer [bodyClass()]" style="[cssVariables()]">
 *
 * so every property below arrives in a STYLE ATTRIBUTE, which outranks every
 * rule in every media query in the stylesheet. A phone value written as
 * `--cp-rowpad` therefore saves and MOVES NOTHING, and reads as a broken save
 * rather than as the specificity problem it is.
 *
 * So cssVariables() emits `--cp-rowpad` AND `--cp-rowpad-m` side by side and the
 * STYLESHEET chooses between them inside its media query. panel_width /
 * panel_width_m have always worked that way; every `_m` key added here follows
 * the same shape, and CartPanelDeviceSetsTest proves each one is emitted as its
 * own property rather than overwriting its desktop twin.
 *
 * ── TWO BREAKPOINTS, AND WHY THIS CLASS DOES NOT PICK ONE ────────────────────
 *
 * The shop reads the `_m` properties at two widths, because it always has:
 *
 *   max-width:680px   the panel becomes the phone panel — its width, padding,
 *                     rows, type and footer buttons;
 *   max-width:900px   the four TOUCH TARGETS are raised to 44px — the per-line
 *                     ✕, the panel's close button, the footer buttons and the
 *                     tab strip.
 *
 * Every default below is the value its own rule renders TODAY at its own width,
 * so applying this moves nothing anywhere in either band. Collapsing the two
 * into one breakpoint would have been tidier and would have changed the panel
 * between 681px and 900px, which rule 1 does not allow.
 *
 * ── THE 44px VALUES ARE TAP TARGETS AND THE OWNER ASKED TO SQUEEZE THEM ──────
 *
 * TOUCH_TARGETS names the four. Their defaults stay 44 because that is what the
 * shop renders today; their `min` is deliberately below 44 because the owner
 * asked for it and it is his shop; and nothing here clamps them at 44 — a
 * slider that stops where nobody asked it to stop reads as a bug and gets
 * reported as one. The screen says what the cost is under the slider instead.
 */
class CartPanel
{
    /**
     * The four values on the Mobile tab that are TOUCH TARGETS rather than
     * spacing, with the shop rule each one comes from.
     *
     * 44px is the smallest box a finger hits reliably, and kbb.css's 900px blocks
     * exist solely to raise these four to it. The owner asked to squeeze exactly
     * these, so each one's `min` is below 44 and none of them clamps. The screen
     * turns the help text under the slider warm below 44 and says that taps get
     * less reliable; that is the whole of the guard, on purpose.
     */
    public const TOUCH_TARGETS = ['rm_tap_m', 'x_size_m', 'btn_h_m', 'tab_h_m'];

    /**
     * Every key's default is the value the panel renders TODAY, at that key's own
     * breakpoint. A shop that applies this and opens nothing renders the panel
     * byte for byte as before. The right-hand comments say which rule in
     * resources/css/kbb/kbb.css each number was hard-coded in.
     */
    public const SCHEMA = [
        // ══ DESKTOP ══ wider than 680px, and wider than 900px for the boxes
        //               that are tap targets on a phone.
        'panel_width'      => ['range',  'Panel width', 380, '', ['min' => 300, 'max' => 520, 'step' => 10, 'unit' => 'px']],
        'list_pad'         => ['range',  'Padding around the list', 16, 'The gap between the panel edge and the product lines.', ['min' => 6, 'max' => 24, 'step' => 2, 'unit' => 'px']],
        'row_pad'          => ['range',  'Space above and below each line', 9, '', ['min' => 4, 'max' => 18, 'step' => 1, 'unit' => 'px']],
        'thumb_size'       => ['range',  'Thumbnail', 42, '', ['min' => 30, 'max' => 64, 'step' => 2, 'unit' => 'px']],
        'name_size'        => ['range',  'Product name size', 13, '', ['min' => 11, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'name_lines'       => ['range',  'Product name · maximum lines', 2, 'Longer names are cut off, so every line is the same height.', ['min' => 1, 'max' => 3, 'step' => 1, 'unit' => '']],
        // A SHARE OF THE SHOP'S OWN SIZE, NOT A PIXEL COUNT, and that is not a
        // preference: .kc-pr is 12.5px and a range stores whole numbers only
        // (ModuleSchema::TYPES maps `range` to int), so a px slider could not
        // express the value the shop already renders and 100% is the only default
        // that leaves the price where it is. Same argument as the six type
        // factors on the checkout screen.
        'price_size'       => ['range',  'Line price size', 100, '100% is the 12.5px the panel uses today.', ['min' => 70, 'max' => 160, 'step' => 5, 'unit' => '%']],
        'stepper_size'     => ['range',  'Quantity buttons', 22, 'Sizes the − and + boxes and the number between them together, which is what the panel does.', ['min' => 18, 'max' => 32, 'step' => 2, 'unit' => 'px']],
        'rm_size'          => ['range',  'Remove ✕ on each line', 13, '', ['min' => 9, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'x_size'           => ['range',  'Close button', 28, 'The ✕ at the top of the panel, which shuts it.', ['min' => 20, 'max' => 48, 'step' => 2, 'unit' => 'px']],
        'x_glyph'          => ['range',  'Close button ✕', 13, '', ['min' => 9, 'max' => 22, 'step' => 1, 'unit' => 'px']],
        'btn_gap'          => ['range',  'Gap between the two buttons', 8, '', ['min' => 0, 'max' => 18, 'step' => 1, 'unit' => 'px']],
        'btn_pad'          => ['range',  'Button height · padding', 12, 'Above and below the label.', ['min' => 4, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'btn_radius'       => ['range',  'Button corners', 99, '0 is square, 99 is a pill.', ['min' => 0, 'max' => 99, 'step' => 1, 'unit' => 'px']],
        'btn_size'         => ['range',  'Button label size', 100, '100% is the 13.5px the panel uses today.', ['min' => 70, 'max' => 150, 'step' => 5, 'unit' => '%']],

        // ══ MOBILE ══ its own stored value for every one of the above, because
        //             16px of padding on a 380px panel and 16px on a 300px one
        //             are not the same decision — which is the owner's whole
        //             complaint: squeezing the phone squeezed the desktop too.
        'panel_width_m'    => ['range',  'Panel width', 77, 'Share of the screen the panel covers.', ['min' => 60, 'max' => 100, 'step' => 1, 'unit' => '%']],
        // ELEVEN, NOT SIXTEEN. The phone's rule was
        // `padding:9px calc(var(--cp-pad,16px) - 5px)`, so the padding the phone
        // actually rendered is 11px and 16 would have widened it on every shop
        // that applied this. The −5px arithmetic is gone: the phone now has its
        // own number and it says what it is.
        'list_pad_m'       => ['range',  'Padding around the list', 11, 'The gap between the panel edge and the product lines.', ['min' => 2, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'row_pad_m'        => ['range',  'Space above and below each line', 9, '', ['min' => 2, 'max' => 18, 'step' => 1, 'unit' => 'px']],
        // min 28, NOT 24. thumb_size_m already had a phone twin and already
        // worked; the owner's list of things to squeeze does not mention the
        // thumbnail, and widening a working slider's range is a change to
        // something nobody asked about. ModuleSchemaEquivalenceTest is what
        // caught it — the pin recorded 28 as this field's floor.
        'thumb_size_m'     => ['range',  'Thumbnail', 38, '', ['min' => 28, 'max' => 56, 'step' => 2, 'unit' => 'px']],
        'name_size_m'      => ['range',  'Product name size', 13, '', ['min' => 9, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'name_lines_m'     => ['range',  'Product name · maximum lines', 2, 'One line is the shortest row a product can have.', ['min' => 1, 'max' => 3, 'step' => 1, 'unit' => '']],
        'price_size_m'     => ['range',  'Line price size', 100, '100% is the 12.5px the panel uses today.', ['min' => 70, 'max' => 160, 'step' => 5, 'unit' => '%']],
        'stepper_size_m'   => ['range',  'Quantity buttons', 22, 'Sizes the − and + boxes and the number between them together.', ['min' => 16, 'max' => 32, 'step' => 2, 'unit' => 'px']],
        'rm_size_m'        => ['range',  'Remove ✕ on each line', 15, '', ['min' => 9, 'max' => 22, 'step' => 1, 'unit' => 'px']],
        // ── the four tap targets ──
        'rm_tap_m'         => ['range',  'Remove ✕ · tap target', 44, '', ['min' => 24, 'max' => 56, 'step' => 1, 'unit' => 'px']],
        'x_size_m'         => ['range',  'Close button', 44, 'The ✕ at the top of the panel, which shuts it.', ['min' => 24, 'max' => 56, 'step' => 1, 'unit' => 'px']],
        'x_glyph_m'        => ['range',  'Close button ✕', 13, '', ['min' => 9, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'tab_h_m'          => ['range',  'Cart / Browsed tab height', 44, '', ['min' => 24, 'max' => 56, 'step' => 1, 'unit' => 'px']],
        // ── the footer buttons ──
        'btn_layout_m'     => ['select', 'Cart and Checkout buttons', 'side',
                               'Side by side is what the panel does today. One above the other gives each button the full width of the panel.',
                               ['side' => 'Side by side', 'stack' => 'One above the other']],
        'btn_gap_m'        => ['range',  'Gap between the two buttons', 6, '', ['min' => 0, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'btn_pad_m'        => ['range',  'Button padding', 10, 'Above and below the label. The tap target below sets the least a button can be.', ['min' => 2, 'max' => 18, 'step' => 1, 'unit' => 'px']],
        'btn_h_m'          => ['range',  'Button height · tap target', 44, '', ['min' => 24, 'max' => 60, 'step' => 1, 'unit' => 'px']],
        'btn_radius_m'     => ['range',  'Button corners', 99, '0 is square, 99 is a pill.', ['min' => 0, 'max' => 99, 'step' => 1, 'unit' => 'px']],
        'btn_size_m'       => ['range',  'Button label size', 100, '100% is the 12px the panel uses today.', ['min' => 70, 'max' => 150, 'step' => 5, 'unit' => '%']],

        // ══ THE FOUR DEVICE-INDEPENDENT TABS ══ unchanged, and not what was
        //    asked for. Every value and every default below is as it shipped.

        // ── What each line shows ──
        'show_thumb'       => ['bool',   'Thumbnail', true, ''],
        'show_qty'         => ['bool',   'Quantity buttons', true, 'Off leaves the line read-only; quantities are then changed on the cart page.'],
        'show_remove'      => ['bool',   'Remove button', true, ''],
        'show_price'       => ['bool',   'Line price', true, ''],
        'show_ship_bar'    => ['bool',   'Free-delivery progress bar', true, ''],
        'show_promo'       => ['bool',   'Promotion line', true, ''],
        'show_browsed'     => ['bool',   'Browsed tab', true, 'Recently viewed products, with one-tap add.'],

        // ── Behaviour ──
        'open_on_add'      => ['bool',   'Open the panel when something is added', true, ''],
        'added_note'       => ['bool',   'Show “Added” beside a product added from Browsed', true, ''],
        'note_ms'          => ['range',  'How long that note stays', 1400, '', ['min' => 400, 'max' => 4000, 'step' => 100, 'unit' => 'ms']],
        /*
         * ── WHAT PRESSING "ADD TO CART" SHOWS ──────────────────── Lane PI-B ──
         *
         * It opened the panel AND put a dark "Added to bag" pill over the top
         * of it — on a phone, across the panel's own tabs (measured at 390:
         * the pill at x 134–255, y 14–60, the panel from x 90 and its tab row
         * y 0–45). The owner asked for a choice, and for one in particular:
         * an animated tick going grey to green, super fast, gone at once, with
         * the panel still opening exactly as now.
         *
         * ▲ SHIPS AT `tick`, WHICH IS A MOVED DEFAULT AND HIS: CLAUDE.md's
         *   30-September reversal. `pill` is the old behaviour, one press away.
         *
         * Printed to the page inside the panel's data-cp JSON (jsConfig()),
         * which Blade escapes; cast() holds it to these three keys or the
         * default, so cart.js can only ever read one of them.
         */
        'add_feedback'     => ['select', 'When something is added', 'tick',
                               'Animated tick: a small grey tick turns green beside the opening panel and is gone in under half a second. Text pill: the "Added to bag" message, as before. None: just the panel. Messages that matter — a set that took the last one, a sold-out product — always show as text.',
                               ['tick' => 'Animated tick', 'pill' => 'Text pill (“Added to bag”)', 'none' => 'None']],

        // ── Wording ──
        // Every string the panel prints, so none of it needs a code change.
        'txt_tab_cart'     => ['text', 'Cart tab', 'Cart', ''],
        'txt_tab_browsed'  => ['text', 'Browsed tab', 'Browsed', ''],
        'txt_ship_away'    => ['text', 'Free delivery · still to go', "You're {amount} away from free delivery", 'Use {amount} where the figure should appear.'],
        'txt_ship_done'    => ['text', 'Free delivery · reached', "🎉 You've unlocked free delivery!", ''],
        'txt_subtotal'     => ['text', 'Subtotal label', 'Subtotal', ''],
        'txt_btn_cart'     => ['text', 'Left button', 'Cart', ''],
        'txt_btn_checkout' => ['text', 'Right button', 'Checkout', ''],
        'txt_empty'        => ['text', 'Empty bag · line one', 'Your bag is empty.', ''],
        'txt_empty_sub'    => ['text', 'Empty bag · line two', 'Add something glowy ✨', ''],
        'txt_browsed_none' => ['text', 'Nothing browsed yet', 'Nothing browsed yet.', ''],

        // ── Colour ──
        'accent'           => ['colour', 'Prices and the active tab', '#C13E63', ''],
        'checkout_bg'      => ['colour', 'Checkout button', '#C13E63', ''],
        'checkout_fg'      => ['colour', 'Checkout button text', '#FFFFFF', ''],

        // ══ COUPON HINT ══ (Lane QK3) The owner, on a screenshot of this panel
        //    with a red line above "Subtotal": "I also need a small text line,
        //    Need Discount? Use coupon code {coupon-code} on checkout. can be
        //    editable and coupon can be selectable by me on backend. keep this
        //    in cart panel settings. also same for mobile."
        //
        //    ▲ SHIPS ON, which is his: CLAUDE.md's 30-September reversal. With
        //    no coupon chosen it draws nothing, so "on" alone moves no byte of
        //    the shop. The data migration 2027_10_15_170000 then chooses GLOW,
        //    which he also asked for by name ("by default, coupon: glow should
        //    be there"), when the shop has a coupon with that code.
        //
        //    `coupon_id` is a select whose stored value is a coupon id. Its
        //    declared option is only "none": the real list is the shop's own
        //    coupons, sent beside the tabs as `coupons` by the controller, and
        //    save() refuses any id the coupons table does not hold. The panel
        //    never reads the coupons table — see couponLine().
        'coupon_on'        => ['bool',   'Coupon hint', true,
                               'Ships on. The line appears above Subtotal on both devices as soon as a coupon is chosen below, and only while that coupon can actually be used.'],
        'coupon_id'        => ['select', 'Coupon', '0',
                               'Usable coupons first. An expired, not-yet-started or used-up coupon hides the line by itself until it can be used again.',
                               ['0' => 'None — the line stays hidden']],
        'txt_coupon'       => ['text',   'Text', self::COUPON_TEXT,
                               'Use {coupon-code} where the code should appear; without it the code is added at the end. Up to 120 characters.'],
        'txt_coupon_ar'    => ['text',   'Text — Arabic', self::COUPON_TEXT_AR,
                               'Shown on the Arabic shop (/ar/). Leave empty to use the English text there too.'],
    ];

    /** The coupon hint's shipped wording, the owner's own sentence. (Lane QK3) */
    public const COUPON_TEXT = 'Need Discount? Use coupon code {coupon-code} on checkout';

    public const COUPON_TEXT_AR = 'تحتاج خصمًا؟ استخدم كود الخصم {coupon-code} عند الدفع';

    /** Where the code goes in the sentence. */
    public const COUPON_TOKEN = '{coupon-code}';

    /**
     * The chosen coupon's dates and state, written whenever the choice or that
     * coupon changes, so the panel — which is on every shop page — checks the
     * clock and never the coupons table. NOT in SCHEMA: nobody edits it.
     */
    public const COUPON_SNAPSHOT = 'cartpanel_coupon_snapshot';

    /**
     * BY DEVICE, NOT BY CATEGORY — which is the change the owner asked for:
     * "i need all those controls on backend on Appearance > Cart Panel >
     * Desktop / Mobile, the same way you did for checkout page".
     *
     * The Size and Density tabs are gone, and every control that was on them is
     * on Desktop or on Mobile with a stored value of its own. Content,
     * Behaviour, Wording and Colour are device-independent, were not what was
     * asked for, and are untouched.
     *
     * The screen matches `^mobile` on the tab key to decide where the preview
     * goes, so the phone tab must keep a key that starts with it.
     */
    public const TABS = [
        'desktop'  => ['Desktop', 'The panel as it opens on a laptop — wider than 680px. Every value here has a twin on the Mobile tab, so squeezing the phone no longer squeezes this.',
                       ['panel_width', 'list_pad', 'row_pad', 'thumb_size', 'name_size', 'name_lines',
                        'price_size', 'stepper_size', 'rm_size', 'x_size', 'x_glyph',
                        'btn_gap', 'btn_pad', 'btn_radius', 'btn_size']],
        'mobile'   => ['Mobile', 'The panel on a phone. Width, padding, rows and type take effect at 680px and below; the four tap targets at 900px and below, which is where the panel raises them today.',
                       ['panel_width_m', 'list_pad_m', 'row_pad_m', 'thumb_size_m', 'name_size_m', 'name_lines_m',
                        'price_size_m', 'stepper_size_m', 'rm_size_m', 'rm_tap_m', 'x_size_m', 'x_glyph_m',
                        'tab_h_m', 'btn_layout_m', 'btn_gap_m', 'btn_pad_m', 'btn_h_m', 'btn_radius_m', 'btn_size_m']],
        'content'  => ['Content', 'What each line and the panel show. The same on both devices.',
                       ['show_thumb', 'show_qty', 'show_remove', 'show_price', 'show_ship_bar', 'show_promo', 'show_browsed']],
        'behaviour'=> ['Behaviour', 'What happens when something is added. The same on both devices.',
                       ['open_on_add', 'add_feedback', 'added_note', 'note_ms']],
        'wording'  => ['Wording', 'Every word the panel prints. The same on both devices.',
                       ['txt_tab_cart', 'txt_tab_browsed', 'txt_ship_away', 'txt_ship_done',
                        'txt_subtotal', 'txt_btn_cart', 'txt_btn_checkout',
                        'txt_empty', 'txt_empty_sub', 'txt_browsed_none']],
        'colour'   => ['Colour', 'Prices, tabs and the checkout button. The same on both devices.',
                       ['accent', 'checkout_bg', 'checkout_fg']],
        // LAST, so every tab recorded before it keeps its index — the payload
        // test compares tabs by position. (Lane QK3)
        'coupon'   => ['Coupon hint', 'A small line just above Subtotal: “Need Discount? Use coupon code GLOW on checkout”. The code is tap-to-copy. The same on both devices.',
                       ['coupon_on', 'coupon_id', 'txt_coupon', 'txt_coupon_ar']],
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get('cartpanel_' . $key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! isset(self::SCHEMA[$key])) {
                continue;
            }

            $value = $this->cast($key, $value);

            // ONLY A COUPON THAT EXISTS. The select lists the shop's coupons,
            // but a crafted POST can send any number; one that names no row
            // is stored as "none", which the policy's `invalid => default`
            // already says. One query, at save, never at render. (Lane QK3)
            if ($key === 'coupon_id' && $value !== '0' && ! Coupon::query()->whereKey((int) $value)->exists()) {
                $value = '0';
            }

            $this->settings->set('cartpanel_' . $key, $value);
        }

        if (array_key_exists('coupon_id', $values)) {
            $this->refreshCouponSnapshot();
        }
    }

    /*
     * ── THE COUPON HINT: A SNAPSHOT, SO THE PANEL COSTS NO QUERY ─────────────
     *
     * The panel is rendered on every shop page (partials/drawers.blade.php), and
     * CLAUDE.md's speed freeze forbids a query per page for it. So the chosen
     * coupon's code, dates and "used up" state are copied into a setting when
     * they can change — this screen's save, any save or delete of that coupon
     * (Coupon::booted()), and a redemption moving its count (CouponService) —
     * and couponLine() checks only the clock against the copy. The settings map
     * is already read once per request, so the copy rides in it for nothing.
     */

    /** Re-read the chosen coupon and store what couponLine() needs from it. */
    public function refreshCouponSnapshot(): void
    {
        $id = (int) $this->get('coupon_id');

        $this->settings->set(self::COUPON_SNAPSHOT, self::snapshotOf($id > 0 ? Coupon::query()->find($id) : null));
    }

    /**
     * The snapshot of one coupon, as stored: code, dates and "used up", or ''
     * for no coupon. Public and static because Appearance → Checkout page's
     * coupon line keeps a copy of ITS chosen coupon the same way, through this
     * one writer, rather than a second one that could drift. (Lane QK6)
     */
    public static function snapshotOf(?Coupon $coupon): string
    {
        return $coupon === null ? '' : (string) json_encode([
            'id' => (int) $coupon->id,
            'code' => (string) $coupon->code,
            'starts_at' => $coupon->starts_at?->getTimestamp(),
            'expires_at' => $coupon->expires_at?->getTimestamp(),
            // The one condition that is not a date. CouponService::validate()
            // refuses a code whose count has reached its limit, so it is not
            // advertised either.
            'active' => $coupon->usage_limit === null || (int) $coupon->usage_count < (int) $coupon->usage_limit,
        ]);
    }

    /**
     * The code a stored snapshot advertises right now, or null.
     *
     * Null unless the snapshot is of coupon $id, carries a code, is not used
     * up, has started and has not expired — the same three conditions
     * CouponService::validate() checks first. Only the clock is read: no query.
     * Shared with Appearance → Checkout page's coupon line. (Lane QK6)
     */
    public static function usableCode(mixed $snap, int $id): ?string
    {
        $snap = is_string($snap) ? json_decode($snap, true) : $snap;

        if ($id <= 0 || ! is_array($snap) || (int) ($snap['id'] ?? 0) !== $id
            || trim((string) ($snap['code'] ?? '')) === '' || empty($snap['active'])) {
            return null;
        }

        $now = now()->getTimestamp();

        if (($snap['starts_at'] ?? null) !== null && $now < (int) $snap['starts_at']) {
            return null;
        }

        if (($snap['expires_at'] ?? null) !== null && $now > (int) $snap['expires_at']) {
            return null;
        }

        return (string) $snap['code'];
    }

    /**
     * The coupon this panel advertises now, as its code, or null — whether or
     * not the panel's own line is switched on. Appearance → Checkout page's
     * coupon line defaults to "Same as the cart panel" and asks here.
     */
    public function chosenUsableCode(): ?string
    {
        return self::usableCode($this->settings->get(self::COUPON_SNAPSHOT), (int) $this->get('coupon_id'));
    }

    /**
     * Called when a coupon row changed. Re-snapshots only when it is the one
     * the panel advertises, so editing any other coupon costs nothing extra.
     */
    public static function couponChanged(int $couponId): void
    {
        $panel = app(self::class);

        if ($couponId > 0 && (int) $panel->get('coupon_id') === $couponId) {
            $panel->refreshCouponSnapshot();
        }

        // The checkout's coupon line may advertise a coupon of its own, kept
        // the same way; every caller of this method is a moment it can change.
        CheckoutPage::couponChanged($couponId);
    }

    /**
     * The line, as HTML, or '' when nothing should be advertised.
     *
     * Shown only while the switch is on, a coupon is chosen, the snapshot is of
     * THAT coupon, and the coupon is usable now — started, not expired, not used
     * up: the same three conditions CouponService::validate() checks first, so
     * the panel never offers a code the checkout would refuse for those reasons.
     *
     * The wording is the owner's and is escaped; the code is escaped into the
     * pill. Nothing printed unescaped comes from a setting.
     */
    public function couponLine(): string
    {
        $c = $this->all();

        if (! $c['coupon_on'] || (int) $c['coupon_id'] <= 0) {
            return '';
        }

        $code = $this->chosenUsableCode();

        if ($code === null) {
            return '';
        }

        $ar = ! Locale::isDefault() && Locale::current() === 'ar';
        $text = trim((string) ($ar ? $c['txt_coupon_ar'] : ''));

        if ($text === '') {
            $text = trim((string) $c['txt_coupon']);
        }

        if ($text === '') {
            $text = self::COUPON_TEXT;
        }

        // The pill is a real button, so a tap answers on the first try; cart.js
        // copies data-kccopy and shows data-done above it without moving a
        // pixel of the line (an absolutely placed ::after).
        $pill = '<button type="button" class="kc-cc" data-kccopy="' . e($code) . '" data-done="'
            . e((string) __('store.cart_drawer.code_copied')) . '">' . e($code) . '</button>';

        return str_contains($text, self::COUPON_TOKEN)
            ? str_replace(self::COUPON_TOKEN, $pill, e($text))
            : e($text) . ' ' . $pill;
    }

    /**
     * This screen's point on ModuleSchema's four policy axes.
     *
     * A cleared wording box stores the empty string here (`blank => keep`) —
     * the panel renders without it. A slider outside its bounds is pulled back
     * rather than refused, and anything else unusable falls back to the shipped
     * default, which is what this screen has always done.
     */
    public const POLICY = [
        'max' => 120,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'cast',
    ];

    /**
     * Cast and clamp on the way in, so a bad value is rejected once at save
     * rather than defended against on every page render.
     *
     * ONE LINE, AND THE REASON IT IS ONE LINE. This was three arms that agreed
     * with thirteen other copies of the same three arms in this app until they
     * did not: the `colour` arm tested with Color::isValidHex(), which accepts
     * a hex with OR without a leading `#`, and then stored what arrived. A
     * value posted as `e23a4e` was kept as `E23A4E` and written into
     * `background:` by cssVariables(), where it is not a colour and the
     * declaration is dropped — the accent moved in the admin and not on the
     * shop. ProductLabels found and fixed this in its own copy; the fix never
     * reached this one, because there was no one place to put it.
     * ModuleSchema::cast() is now that place.
     */
    private function cast(string $key, mixed $value): mixed
    {
        // A coupon id: digits or "none". Whether the row exists is save()'s
        // question, asked once, so a render never touches the coupons table.
        // (Lane QK3)
        if ($key === 'coupon_id') {
            $value = is_scalar($value) ? trim((string) $value) : '';

            return preg_match('/^[1-9][0-9]{0,18}$/', $value) === 1 ? $value : '0';
        }

        return ModuleSchema::cast(
            ModuleSchema::field($key, self::SCHEMA[$key], self::POLICY),
            $value,
        );
    }

    /**
     * Inline custom properties for the panel element.
     *
     * ── BOTH SETS, SIDE BY SIDE, ALWAYS ─────────────────────────────────────
     *
     * This is the one thing about this class that cannot be got wrong quietly.
     * The string below lands in a STYLE ATTRIBUTE (drawers.blade.php), and an
     * inline declaration outranks every rule in every media query, whatever that
     * rule's specificity. So a phone value CANNOT be written as `--cp-rowpad`: it
     * would win at every width, the desktop panel would move with the phone
     * slider, and the Mobile tab would read as a broken save.
     *
     * Every pair is therefore emitted as `--cp-x` and `--cp-x-m` TOGETHER, and
     * the stylesheet picks between them inside its own media query. Nothing here
     * knows what a breakpoint is, which is the property that makes it right.
     *
     * The three `_m`-only properties are the phone's tap targets that the desktop
     * has no equivalent for: `--cp-rmbox-m`, because .kc-rm has no box at all on
     * a laptop; `--cp-btnh-m`, because the footer buttons have no minimum height
     * there; and `--cp-tabh-m`, for the same reason on the tab strip.
     *
     * The two factors — `--cp-price` and `--cp-btnf` — are percentages divided by
     * 100 rather than pixel counts, for the reason SCHEMA's own comment gives:
     * .kc-pr is 12.5px and .kc-btns a is 13.5px, and a range stores whole
     * numbers, so no px slider could hold the value the shop already renders. The
     * stylesheet multiplies: calc(12.5px * var(--cp-price,1)).
     */
    public function cssVariables(): string
    {
        $c = $this->all();

        // 1 at 100%, to three decimals — a percentage that does not divide evenly
        // must not arrive as 0.93333333333333 in a style attribute on every page.
        $factor = static function (int $pct): string {
            $out = rtrim(rtrim(number_format($pct / 100, 3, '.', ''), '0'), '.');

            return $out === '' ? '0' : $out;
        };

        return implode(';', [
            // ── size ──
            '--cp-w:' . $c['panel_width'] . 'px',
            '--cp-w-m:' . $c['panel_width_m'] . 'vw',
            '--cp-pad:' . $c['list_pad'] . 'px',
            '--cp-pad-m:' . $c['list_pad_m'] . 'px',

            // ── the product lines ──
            '--cp-rowpad:' . $c['row_pad'] . 'px',
            '--cp-rowpad-m:' . $c['row_pad_m'] . 'px',
            '--cp-thumb:' . $c['thumb_size'] . 'px',
            '--cp-thumb-m:' . $c['thumb_size_m'] . 'px',
            '--cp-name:' . $c['name_size'] . 'px',
            '--cp-name-m:' . $c['name_size_m'] . 'px',
            '--cp-lines:' . $c['name_lines'],
            '--cp-lines-m:' . $c['name_lines_m'],
            '--cp-price:' . $factor($c['price_size']),
            '--cp-price-m:' . $factor($c['price_size_m']),
            '--cp-step:' . $c['stepper_size'] . 'px',
            '--cp-step-m:' . $c['stepper_size_m'] . 'px',

            // ── the two crosses and the tab strip ──
            '--cp-rm:' . $c['rm_size'] . 'px',
            '--cp-rm-m:' . $c['rm_size_m'] . 'px',
            '--cp-rmbox-m:' . $c['rm_tap_m'] . 'px',
            '--cp-x:' . $c['x_size'] . 'px',
            '--cp-x-m:' . $c['x_size_m'] . 'px',
            '--cp-xg:' . $c['x_glyph'] . 'px',
            '--cp-xg-m:' . $c['x_glyph_m'] . 'px',
            '--cp-tabh-m:' . $c['tab_h_m'] . 'px',

            // ── the footer buttons ──
            '--cp-btngap:' . $c['btn_gap'] . 'px',
            '--cp-btngap-m:' . $c['btn_gap_m'] . 'px',
            '--cp-btnpad:' . $c['btn_pad'] . 'px',
            '--cp-btnpad-m:' . $c['btn_pad_m'] . 'px',
            '--cp-btnh-m:' . $c['btn_h_m'] . 'px',
            '--cp-btnr:' . $c['btn_radius'] . 'px',
            '--cp-btnr-m:' . $c['btn_radius_m'] . 'px',
            '--cp-btnf:' . $factor($c['btn_size']),
            '--cp-btnf-m:' . $factor($c['btn_size_m']),

            // ── colour ──
            '--cp-accent:' . $c['accent'],
            '--cp-cta-bg:' . $c['checkout_bg'],
            '--cp-cta-fg:' . $c['checkout_fg'],
        ]);
    }

    /**
     * Structural switches, as classes rather than variables.
     *
     * The footer buttons' arrangement is here rather than in cssVariables()
     * because it is a grid-template and not a measurement, and because a CLASS is
     * what can beat the phone rule it has to beat: `.cp-btnstack .kc-btns`
     * outranks `.kc-btns` inside the 680px block on specificity, which is the
     * only thing that decides it — a media query has none of its own.
     *
     * `side` writes no class at all, so a shop that never opens this screen
     * carries exactly the class attribute it carries today.
     */
    public function bodyClass(): string
    {
        $c = $this->all();

        return trim(implode(' ', array_filter([
            $c['btn_layout_m'] === 'stack' ? 'cp-btnstack' : '',
            $c['show_thumb'] ? '' : 'cp-nothumb',
            $c['show_qty'] ? '' : 'cp-noqty',
            $c['show_remove'] ? '' : 'cp-norm',
            $c['show_price'] ? '' : 'cp-noprice',
            $c['show_ship_bar'] ? '' : 'cp-noship',
            $c['show_promo'] ? '' : 'cp-nopromo',
            $c['show_browsed'] ? '' : 'cp-nobrowsed',
        ])));
    }

    /** The handful of values the front-end script needs. */
    public function jsConfig(): array
    {
        $c = $this->all();

        return ['openOnAdd' => $c['open_on_add'], 'note' => $c['added_note'], 'noteMs' => $c['note_ms'],
            // One of add_feedback's three option keys, never the stored string
            // itself — all() has already cast it. (Lane PI-B)
            'feedback' => $c['add_feedback']];
    }
}
