<?php

declare(strict_types=1);

namespace App\Services\GiftOffers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use App\Support\ImageVariants;
use App\Support\Locale;
use App\Support\Money;
use App\Support\StoreTime;
use App\Support\Url;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Marketing -> Gift offers (Lane GO). The owner, 1-3 November 2026.
 *
 * ONE extra full-size product at AED 0, chosen by cart-value range, offered in
 * three places that ship ON once a gift is configured -- the abandoned-cart
 * email and both pending-order reminders -- and one that ships OFF, the exit
 * popup. Spec: docs/plans/GIFT-OFFERS.md.
 *
 * ── WHICH PRODUCT ───────────────────────────────────────────────────────────
 *
 * Each range lists one primary and any number of backups, in order, each with
 * its own allocation for this offer. resolve() drops every product whose
 * allocation is spent and hands the rest to FreeGifts::linesFor() as a range
 * that asks for ONE full-size gift: Free gifts' own main/backup walk (status,
 * variable products, a chosen variation's stock, real shelf stock) picks the
 * first that can be given. Nothing available: the range offers NOTHING, and
 * every place (both emails, the popup) draws nothing at all.
 *
 * ── NOT A CART LINE ─────────────────────────────────────────────────────────
 *
 * Exactly as Free gifts (FreeGifts' header): nothing a browser posts can add
 * or price the gift. A claim is a row here and the basket points at it
 * (carts.gift_offer_claim_id, read with the cart row every page already
 * fetches -- a basket without one costs NO query to know it). The cart panel,
 * cart page and checkout summary draw the row from it; Store\Checkout
 * Controller::place() asks forPlace() and writes an AED 0 `is_gift` line,
 * which Order::items() leaves out, so the payment gateways are handed exactly
 * what they were handed before.
 *
 * ── BEARER LINKS ────────────────────────────────────────────────────────────
 *
 * A claim token is 32 random bytes; only its SHA-256 is stored. A claim link
 * ONLY claims the gift: it never signs anybody in and never restores a basket
 * (CartRecoveryReminder's header) -- it attaches the gift to whatever basket
 * the browser that opened it already has, creating an empty one if it has
 * none.
 *
 * ── COST ───────────────────────────────────────────────────────────────────
 *
 * The configuration and the compiled ranges are two autoloaded settings rows
 * (SettingsRequestMemo holds them): no query to know there is nothing to do.
 */
final class GiftOffers
{
    public const KEY = 'gift_offers';

    public const COMPILED = 'gift_offers_compiled';

    /** Where an offer is made. `test` is a test send's claim: never allocation, never reports. */
    public const PLACES = ['cart', 'reminder_1', 'reminder_2', 'popup'];

    /**
     * Who sees gift offers (the owner, 4 November: "i need the same TEST / LIVE
     * / OFF function" as Free gifts). off: nobody. testing: only the IPs on this
     * module's own `testers` list -- on the shop by the request's address, in
     * the emails by the address the cart (carts.ct_ip) or the order
     * (orders.ip_address) was made from. live: everyone. A test send is a test
     * send in every mode. Ships in Testing: nothing reaches a customer until
     * the owner picks Live.
     */
    public const MODES = ['off', 'testing', 'live'];

    /** At most this many IPs on the Testing list (Free gifts' number). */
    public const MAX_TESTERS = 50;

    /** The owner: emails ON (approved 3 November), the popup built but OFF. */
    public const PLACE_DEFAULTS = ['cart' => true, 'reminder_1' => true, 'reminder_2' => true, 'popup' => false];

    public const TIMERS = ['live', 'fixed'];

    /** Pending reminder 2: a fresh window of its own, or the first reminder's. */
    public const WINDOWS = ['each', 'first'];

    public const DESIGNS = ['A', 'B', 'C', 'D'];

    public const STYLES = ['S1', 'S2', 'S3', 'S4', 'S5'];

    public const WORDS = ['1', '2', '3', '4', '5'];

    public const AUDIENCES = ['bag', 'checkout'];

    public const HOLD = [8, 1, 72];

    public const MINUTES = [15, 5, 120];

    public const MAX_ALLOCATION = 100000;

    /**
     * Gives per order (Lane GQ; the owner, 10 November 2026: "i need x2 in gift
     * offers ... we need to give 2 pcs for this product"). How many units ONE
     * claim of an item gives. The admin's select offers exactly these; anything
     * else stores the default, 1. Allocation and "given / left" count UNITS, so
     * a ×2 item spends 2 per order.
     */
    public const QTYS = [1, 2, 3, 4, 5];

    /** One of QTYS, or 1. Accepts the select's string ("2") as well as an int. */
    public static function qtyOf(mixed $v): int
    {
        $n = is_int($v) ? $v : (is_string($v) && preg_match('/^[0-9]{1,2}$/', $v) ? (int) $v : 0);

        return in_array($n, self::QTYS, true) ? $n : 1;
    }

    /** The quantity a claim row was issued with (1 for a row from before the column). */
    public static function claimQty(object $claim): int
    {
        return self::qtyOf(isset($claim->qty) ? (int) $claim->qty : 1);
    }

    public const MAX_RANGES = 30;

    public const MAX_ITEMS = 12;

    public const TEXT_MAX = 200;

    /**
     * Every word the shopper reads, [English, Arabic]. Settings, printed
     * escaped. Arabic addresses a woman, as the shop's other texts do.
     */
    public const TEXTS = [
        // The email's Golden ticket (design A of abandoned-email-designs.html).
        // The start of the subject line whenever the email carries the gift
        // (the owner, 4 November: "mention Free Gift in the title ... very
        // nicely and short"). Blank in the admin = the default.
        'email_subject' => ['🎁 A free gift inside', '🎁 هدية مجانية بانتظاركِ'],
        'email_eyebrow' => ['ADDITIONAL GIFT FOR YOU', 'هدية إضافية لكِ'],
        'email_title' => ['Wait… this is for you', 'لحظة… هذه لكِ'],
        'email_body_cart' => ["Your bag is still here, and we've added a full-size gift to go with it. Free, just for you.", 'حقيبتكِ ما زالت هنا، وأضفنا إليها هدية بالحجم الكامل. مجاناً، لكِ وحدكِ.'],
        'email_body_order' => ["Complete your order and we'll add a full-size gift to it. Free, just for you.", 'أكملي طلبكِ وسنضيف إليه هدية بالحجم الكامل. مجاناً، لكِ وحدكِ.'],
        'email_button_cart' => ['Claim my free gift', 'احصلي على هديتي المجانية'],
        'email_button_order' => ['Pay now & claim my gift', 'ادفعي الآن واحصلي على هديتكِ'],
        'email_worth' => ['Worth :price · one per order', 'قيمتها :price · هدية واحدة لكل طلب'],
        'clock_label' => ['Complete your order in', 'أكملي طلبكِ خلال'],
        'held' => ['Held until :time', 'محجوزة لكِ حتى :time'],
        'today' => ['today', 'اليوم'],
        'tomorrow' => ['tomorrow', 'غداً'],
        'chip_free' => ['FREE', 'مجاناً'],
        'chip_full' => ['FULL SIZE', 'حجم كامل'],
        // The cart row (R2).
        'row_free' => ['Free', 'مجاناً'],
        'row_full' => ['Full size', 'حجم كامل'],
        'row_below' => ['Add :amount more to keep this gift', 'أضيفي :amount لتحتفظي بهذه الهدية'],
        'row_over' => ['Your time is up, so the gift has left your bag.', 'انتهى الوقت، فغادرت الهدية حقيبتكِ.'],
        'ended' => ['This gift has ended.', 'انتهت هذه الهدية.'],
        'pay_note' => ['Pay within the time and your free gift is added to this order.', 'ادفعي خلال الوقت المحدد وستُضاف هديتكِ المجانية إلى هذا الطلب.'],
        // The popup.
        'wait' => ['WAIT…', 'لحظة…'],
        'w1_h' => ['Beautiful, this one is on us', 'جميلتي، هذه علينا'],
        'w1_p' => ["Before you go, take a full-size {p} free with your order. We're holding it in your bag for {m} minutes.", 'قبل أن تغادري، خذي {p} بالحجم الكامل مجاناً مع طلبكِ. نحتفظ بها في حقيبتكِ لمدة {m} دقيقة.'],
        'w1_c' => ['Claim my free gift', 'احصلي على هديتي المجانية'],
        'w1_l' => ['Maybe later', 'ربما لاحقاً'],
        'w2_h' => ['Your skin deserves a little extra', 'بشرتكِ تستحق القليل من الدلال'],
        'w2_p' => ['Finish your order in the next {m} minutes and a full-size {p} comes home with you, free.', 'أكملي طلبكِ خلال {m} دقيقة وستصلكِ {p} بالحجم الكامل مجاناً.'],
        'w2_c' => ['Yes, add my gift', 'نعم، أضيفي هديتي'],
        'w2_l' => ['Not today', 'ليس اليوم'],
        'w3_h' => ['Psst… a secret gift, just for you', 'سرّ صغير… هدية لكِ وحدكِ'],
        'w3_p' => ['A full-size {p} is yours, free with this bag.', '{p} بالحجم الكامل لكِ، مجاناً مع هذه الحقيبة.'],
        'w3_c' => ['Claim it now', 'احصلي عليها الآن'],
        'w3_l' => ['Maybe later', 'ربما لاحقاً'],
        'w4_h' => ["Don't leave your glow behind", 'لا تتركي إشراقتكِ خلفكِ'],
        'w4_p' => ["Your bag is ready, and we've added a treat: a full-size {p}, free if you order within {m} minutes.", 'حقيبتكِ جاهزة، وأضفنا لكِ مفاجأة: {p} بالحجم الكامل، مجاناً إذا طلبتِ خلال {m} دقيقة.'],
        'w4_c' => ['Add my free gift', 'أضيفي هديتي المجانية'],
        'w4_l' => ["I'll pass for now", 'سأتركها الآن'],
        'w5_h' => ['A full-size gift is waiting for you', 'هدية بالحجم الكامل بانتظاركِ'],
        'w5_p' => ['No code, no catch. Tap below and a full-size {p} joins your bag for free, held for {m} minutes.', 'بلا كود وبلا شروط. اضغطي أدناه وستنضم {p} بالحجم الكامل إلى حقيبتكِ مجاناً، محجوزة لمدة {m} دقيقة.'],
        'w5_c' => ["Claim now, it's free", 'احصلي عليها الآن، مجاناً'],
        'w5_l' => ['Maybe later', 'ربما لاحقاً'],
        'pop_full' => ['full size', 'حجم كامل'],
        'pop_held' => ['Held for you', 'محجوزة لكِ'],
        'pop_held_for' => ['Your gift is held for', 'هديتكِ محجوزة لمدة'],
        'pop_minutes' => ['MINUTES', 'دقائق'],
        'pop_seconds' => ['SECONDS', 'ثوانٍ'],
        'pop_gold' => ['GIFT HELD FOR', 'الهدية محجوزة لمدة'],
        'pop_box' => ["There's a little something in this box for you.", 'في هذا الصندوق شيء صغير لكِ.'],
        'pop_tap' => ['Tap the box to open', 'اضغطي على الصندوق لفتحه'],
        'pop_hold_chip' => ['Held for {m} minutes after you claim', 'محجوزة {m} دقيقة بعد أن تطلبيها'],
        'pop_added' => ['Added to your bag!', 'أُضيفت إلى حقيبتكِ!'],
        'pop_opening' => ['Opening your cart…', 'نفتح حقيبتكِ…'],
        'pop_close' => ['Close', 'إغلاق'],
    ];

    private ?array $config = null;

    public function __construct(private SettingsService $settings) {}

    /* ------------------------------------------------------------ settings */

    public function config(): array
    {
        return $this->config ??= self::normalise($this->settings->get(self::KEY));
    }

    public function freshConfig(): array
    {
        $this->config = null;

        return $this->config();
    }

    /** Use a draft configuration for the rest of this request (the admin's live preview). */
    public function useConfig(array $config): void
    {
        $this->config = self::normalise($config);
    }

    public function saveConfig(array $config): array
    {
        $clean = self::normalise($config);
        $this->settings->set(self::KEY, $clean);
        $this->config = $clean;

        return $clean;
    }

    /**
     * Every key present, every select one of its own options or the default,
     * every number clamped, every text trimmed to TEXT_MAX (blank = the
     * default). The admin's save and the live preview both pass through here,
     * so nothing else ever reaches the settings row.
     */
    public static function normalise(mixed $saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $places = [];
        foreach (self::PLACE_DEFAULTS as $place => $default) {
            $places[$place] = (bool) ($saved['places'][$place] ?? $default);
        }

        $texts = [];
        foreach (self::TEXTS as $key => [$en, $ar]) {
            foreach (['en' => $en, 'ar' => $ar] as $lang => $default) {
                $v = $saved['texts'][$key . '_' . $lang] ?? null;
                $v = is_string($v) ? trim(mb_substr(str_replace(["\r", "\n"], ' ', $v), 0, self::TEXT_MAX)) : '';
                $texts[$key . '_' . $lang] = $v === '' ? $default : $v;
            }
        }

        $pop = is_array($saved['popup'] ?? null) ? $saved['popup'] : [];
        $pick = static fn (mixed $v, array $opts, string $d): string => in_array($v, $opts, true) ? $v : $d;

        return [
            'mode' => self::modeOf($saved),
            'testers' => self::testersOf($saved),
            'places' => $places,
            'hold_hours' => self::clamp($saved['hold_hours'] ?? null, self::HOLD),
            'popup_minutes' => self::clamp($saved['popup_minutes'] ?? null, self::MINUTES),
            'timer' => $pick($saved['timer'] ?? null, self::TIMERS, 'live'),
            'window' => $pick($saved['window'] ?? null, self::WINDOWS, 'each'),
            'popup' => [
                'design' => $pick($pop['design'] ?? null, self::DESIGNS, 'A'),
                'style' => $pick($pop['style'] ?? null, self::STYLES, 'S3'),
                'words' => $pick(is_int($pop['words'] ?? null) ? (string) $pop['words'] : ($pop['words'] ?? null), self::WORDS, '3'),
                'audience' => $pick($pop['audience'] ?? null, self::AUDIENCES, 'bag'),
            ],
            'texts' => $texts,
        ];
    }

    private static function clamp(mixed $v, array $spec): int
    {
        [$default, $min, $max] = $spec;

        return is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
    }

    /**
     * The saved mode. A row from before modes (2.60.639) carried `on`: false
     * stays Off; anything else becomes Testing, never Live by itself.
     */
    public static function modeOf(array $saved): string
    {
        if (in_array($saved['mode'] ?? null, self::MODES, true)) {
            return $saved['mode'];
        }

        return array_key_exists('on', $saved) && $saved['on'] === false ? 'off' : 'testing';
    }

    /** The Testing list: valid addresses only, no repeats, at most MAX_TESTERS, labels trimmed. */
    public static function testersOf(array $saved): array
    {
        $out = [];
        $seen = [];

        foreach (is_array($saved['testers'] ?? null) ? $saved['testers'] : [] as $t) {
            $ip = is_array($t) ? trim((string) ($t['ip'] ?? '')) : '';
            $bin = $ip === '' ? null : \App\Support\IpRange::pack($ip);

            if ($bin === null || isset($seen[$bin]) || count($out) >= self::MAX_TESTERS) {
                continue;
            }

            $seen[$bin] = true;
            $label = is_string($t['label'] ?? null) ? trim(mb_substr(str_replace(["\r", "\n"], ' ', $t['label']), 0, 60)) : '';
            $out[] = ['ip' => $ip, 'label' => $label];
        }

        return $out;
    }

    /** Is this address on the Testing list? Compared packed, so "::1" and "0:0:0:0:0:0:0:1" are one. */
    public function testerIp(?string $ip): bool
    {
        $bin = $ip === null || trim($ip) === '' ? null : \App\Support\IpRange::pack(trim($ip));

        if ($bin === null) {
            return false;
        }

        foreach ($this->config()['testers'] as $t) {
            if (\App\Support\IpRange::pack($t['ip']) === $bin) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this shop request see gift offers at all? Live: yes. Testing: only
     * from an address on the list ($request->ip(), the firewall's own answer;
     * no proxy header is read). Off: no. No query: settings only.
     */
    public function visibleTo(?\Illuminate\Http\Request $request): bool
    {
        $mode = $this->config()['mode'];

        if ($mode !== 'testing') {
            return $mode === 'live';
        }

        return $request !== null && $this->testerIp((string) $request->ip());
    }

    /** Is this place switched on (not Off, AND its own switch)? Who sees it is visibleTo()'s. */
    public function placeOn(string $place): bool
    {
        $c = $this->config();

        return $c['mode'] !== 'off' && (bool) ($c['places'][$place] ?? false);
    }

    /** A text in the language being drawn (or the one named), placeholders filled. */
    public function text(string $key, array $replace = [], ?string $locale = null): string
    {
        $lang = ($locale ?? (Locale::isDefault() ? 'en' : 'ar')) === 'ar' ? 'ar' : 'en';
        $t = (string) ($this->config()['texts'][$key . '_' . $lang] ?? (self::TEXTS[$key][$lang === 'ar' ? 1 : 0] ?? ''));

        foreach ($replace as $k => $v) {
            $t = str_replace(':' . $k, (string) $v, $t);
        }

        return $t;
    }

    /* -------------------------------------------------------------- ranges */

    /** The enabled ranges with their giveable items, lowest first. No query once compiled. */
    public function ranges(): array
    {
        $compiled = $this->settings->get(self::COMPILED);

        if (! is_array($compiled) || ($compiled['v'] ?? 0) !== 1 || ! is_array($compiled['ranges'] ?? null)) {
            $compiled = $this->compile();
        }

        return $compiled['ranges'];
    }

    /**
     * Rebuild the compiled ranges from the two tables: two queries, on a save
     * in the admin and when an order spends an allocation. Each item is
     * [item id, product id, variant id (0 = none), allocation left, gives per
     * order], primary first then the backups, each group in the owner's order.
     * (A row compiled before Lane GQ has no fifth value: it reads as 1.)
     */
    public function compile(): array
    {
        try {
            $ranges = DB::table('gift_offer_ranges')->where('enabled', true)->orderBy('min_fils')->orderBy('sort')->orderBy('id')->get();
            $items = $ranges->isEmpty() ? collect() : DB::table('gift_offer_items')
                ->whereIn('range_id', $ranges->pluck('id')->all())
                ->orderByRaw("CASE WHEN role = 'primary' THEN 0 ELSE 1 END")->orderBy('sort')->orderBy('id')
                // Every column (Lane GQ): a server whose qty migration has not run yet
                // still compiles, each item reading as ×1.
                ->get()
                ->groupBy('range_id');
        } catch (\Throwable) {
            // The package landed without its migration: nothing to offer, and nothing stored.
            return ['v' => 1, 'ranges' => []];
        }

        $out = [];
        foreach ($ranges as $r) {
            $out[] = [
                'id' => (int) $r->id,
                'min' => (int) $r->min_fils,
                'max' => $r->max_fils === null ? null : (int) $r->max_fils,
                'items' => array_values(array_map(static fn ($i) => [
                    (int) $i->id, (int) $i->product_id, (int) ($i->variant_id ?? 0), max(0, (int) $i->allocation - (int) $i->used), self::qtyOf((int) ($i->qty ?? 1)),
                ], ($items[$r->id] ?? collect())->all())),
            ];
        }

        $compiled = ['v' => 1, 'ranges' => $out];
        $this->settings->set(self::COMPILED, $compiled);

        return $compiled;
    }

    /** The range an amount falls in; overlapping ranges give the higher one (Free gifts' rule). */
    public function rangeFor(int $fils): ?array
    {
        $best = null;

        foreach ($this->ranges() as $range) {
            if (FreeGifts::covers($range['min'], $range['max'], $fils)
                && ($best === null || $range['min'] > $best['min'])) {
                $best = $range;
            }
        }

        return $best;
    }

    public function rangeById(int $id): ?array
    {
        foreach ($this->ranges() as $range) {
            if ($range['id'] === $id) {
                return $range;
            }
        }

        return null;
    }

    /** The amount a basket is measured on: Free gifts' own measure. */
    public function basis(array $totals): int
    {
        return app(FreeGifts::class)->basis($totals);
    }

    /**
     * THE PRODUCT A RANGE GIVES NOW, or null when it gives nothing.
     *
     * Items that cannot give their FULL quantity from what is left of their
     * allocation are dropped first; what is left goes to FreeGifts::linesFor()
     * as main gifts of their own quantity, in the owner's order, and the
     * first whose shelf can give ALL of its units is the gift -- so the
     * primary when it can be given, else the first backup that can.
     *
     * NEVER A SHORT GIFT (Lane GQ). A ×2 item with one unit of allocation or
     * one on the shelf is skipped for the next backup that can give its own
     * full quantity: the owner promised two, and one is not what he promised.
     * With every item at ×1 this picks exactly what it picked before (the
     * first item with allocation left whose shelf has a unit).
     *
     * One query for the products (memoised per request by FreeGifts), none
     * for a variation unless one is named.
     *
     * @return array{range:array,item_id:int,product:Product,variant:mixed,product_id:int,variant_id:?int,value:int,qty:int}|null
     */
    public function resolve(array $range): ?array
    {
        $items = array_values(array_filter($range['items'] ?? [], static fn (array $i) => $i[3] > 0 && $i[3] >= self::qtyOf($i[4] ?? 1)));

        if ($items === []) {
            return null;
        }

        $lines = app(FreeGifts::class)->linesFor([
            'id' => $range['id'],
            'min' => $range['min'],
            'max' => $range['max'],
            'needs' => null,
            'gifts' => array_map(static fn (array $i) => array_merge([$i[1], self::qtyOf($i[4] ?? 1), 'full', 'primary'], $i[2] > 0 ? [$i[2]] : []), $items),
        ]);

        foreach ($items as $i) {
            $qty = self::qtyOf($i[4] ?? 1);

            foreach ($lines as $line) {
                $variantId = (int) ($line['variant_id'] ?? 0);

                if ($i[1] !== (int) $line['product_id'] || $i[2] !== $variantId) {
                    continue;
                }

                if ((int) $line['quantity'] < $qty) {
                    break;
                }

                return [
                    'range' => $range,
                    'item_id' => $i[0],
                    'product' => $line['product'],
                    'variant' => $line['variant'] ?? null,
                    'product_id' => (int) $line['product_id'],
                    'variant_id' => $variantId > 0 ? $variantId : null,
                    // What the gift is worth: one unit's price x the units given.
                    'value' => (int) $line['value'],
                    'qty' => $qty,
                ];
            }
        }

        return null;
    }

    /** The range and product for an amount, or null. */
    public function offerFor(int $fils): ?array
    {
        $range = $this->rangeFor($fils);

        return $range === null ? null : $this->resolve($range);
    }

    /* -------------------------------------------------------------- claims */

    /** A fresh claim token: 32 random bytes, base64url, 43 characters. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function validToken(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    public function claimByToken(string $token): ?object
    {
        if (! self::validToken($token)) {
            return null;
        }

        try {
            return DB::table('gift_offer_claims')->where('token_hash', self::hash($token))->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * MAKE AN OFFER FOR AN EMAIL: resolve the range for this amount, store a
     * claim (hash only) with its window, and return what the email draws.
     * Null -- and no row -- when the place is off or nothing can be given:
     * then the email carries no block at all.
     *
     * $ctx: order (pending reminders), cart_id, test (a test send), locale.
     */
    public function issue(string $place, int $fils, array $ctx = []): ?array
    {
        $test = (bool) ($ctx['test'] ?? false);

        if (! $test && ! $this->placeOn($place)) {
            return null;
        }

        // Testing: an email carries the gift only for a cart or an order made
        // from an address on the Testing list. One query, in Testing only.
        if (! $test && $this->config()['mode'] === 'testing' && ! $this->testerIp($this->emailIp($ctx))) {
            return null;
        }

        $offer = isset($ctx['range_id']) ? (($r = $this->rangeById((int) $ctx['range_id'])) ? $this->resolve($r) : null) : $this->offerFor($fils);

        if ($offer === null) {
            return null;
        }

        $order = $ctx['order'] ?? null;
        $expires = $this->expiryFor($place, $order instanceof Order ? $order : null);

        if ($expires === null || $expires->lessThanOrEqualTo(now())) {
            return null;
        }

        $token = self::newToken();
        $locale = Locale::isDefault() ? 'en' : 'ar';

        try {
            DB::table('gift_offer_claims')->insert([
                'token_hash' => self::hash($token),
                'place' => $place,
                'range_id' => $offer['range']['id'],
                'item_id' => $offer['item_id'],
                'product_id' => $offer['product_id'],
                'variant_id' => $offer['variant_id'],
                'value_fils' => max(0, $offer['value']),
                'cart_id' => $ctx['cart_id'] ?? null,
            ] + (($offer['qty'] ?? 1) > 1 ? ['qty' => (int) $offer['qty']] : []) + [
                'order_id' => $order instanceof Order ? $order->id : null,
                'status' => 'issued',
                'is_test' => $test,
                'locale' => $locale,
                'expires_at' => $expires,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('gift offer could not be issued', ['place' => $place, 'exception' => class_basename($e)]);

            return null;
        }

        return $this->emailView($offer, $token, $place, $expires);
    }

    /** The address an email's cart or order was made from (Testing's check), or null. */
    private function emailIp(array $ctx): ?string
    {
        $order = $ctx['order'] ?? null;

        if ($order instanceof Order) {
            return $order->ip_address !== null ? (string) $order->ip_address : null;
        }

        if (! empty($ctx['cart_id'])) {
            try {
                $ip = DB::table('carts')->where('id', (int) $ctx['cart_id'])->value('ct_ip');
            } catch (\Throwable) {
                $ip = null;
            }

            return $ip !== null ? (string) $ip : null;
        }

        return null;
    }

    /**
     * The abandoned-cart email's offer for a basket as CartRecovery::basket()
     * reads it (unit price x quantity, the email's own sum). Never throws: a
     * gift must never stop a reminder going.
     */
    public static function forBasket(array $items, ?int $cartId, bool $test = false): ?array
    {
        try {
            $sub = array_sum(array_map(static fn (array $i) => (int) ($i['unit_price'] ?? 0) * (int) ($i['quantity'] ?? 0), $items));

            return $items === [] ? null : app(self::class)->issue('cart', $sub, ['cart_id' => $cartId, 'test' => $test]);
        } catch (\Throwable $e) {
            Log::error('gift offer skipped', ['place' => 'cart', 'exception' => class_basename($e)]);

            return null;
        }
    }

    /** A pending reminder's offer, measured on the order's own products after discounts. Never throws. */
    public static function forOrder(Order $order, int $stage, bool $test = false): ?array
    {
        try {
            $self = app(self::class);
            $bundle = (int) ($order->bundle_discount ?? 0);
            $fils = $self->basis(['subtotal' => (int) $order->subtotal, 'bundle_discount' => $bundle, 'discount' => max(0, (int) $order->discount_total - $bundle)]);

            return $self->issue($stage === 2 ? 'reminder_2' : 'reminder_1', $fils, ['order' => $order, 'test' => $test]);
        } catch (\Throwable $e) {
            Log::error('gift offer skipped', ['place' => 'reminder', 'exception' => class_basename($e)]);

            return null;
        }
    }

    /** The window for an offer made now: hold hours, or reminder 1's when the owner chose one window. */
    private function expiryFor(string $place, ?Order $order): ?CarbonImmutable
    {
        $hours = $this->config()['hold_hours'];

        if ($place === 'reminder_2' && $order !== null && $this->config()['window'] === 'first') {
            $first = DB::table('gift_offer_claims')->where('order_id', $order->id)->where('place', 'reminder_1')->where('is_test', false)->max('expires_at');

            return $first === null ? null : CarbonImmutable::parse($first);
        }

        return CarbonImmutable::now()->addHours($hours);
    }

    /**
     * What the Golden ticket draws, every value plain text the view escapes
     * (or a URL this method built). Shared by the real send, the test send
     * and the admin's preview, so all three draw the same block.
     */
    public function emailView(array $offer, string $token, string $place, CarbonImmutable $expires): array
    {
        $p = $offer['product'];
        $v = $offer['variant'] ?? null;
        $image = (string) (($v !== null && trim((string) $v->image) !== '' ? $v->image : null) ?? $p->image ?? '');
        $name = FreeGifts::giftName((string) ($p->t('name') ?: $p->name), $v);
        $order = $place !== 'cart';
        $held = $this->text('held', ['time' => $this->endText($expires)]);
        $live = $this->config()['timer'] === 'live' && GiftClock::available();

        return [
            'href' => Url::external('/gift-offer/c/' . $token),
            'clock' => $live ? Url::external('/gift-offer/t/' . $token . '.gif') : null,
            'clock_w' => GiftClock::WIDTH,
            'clock_h' => GiftClock::HEIGHT,
            'img' => \App\Services\Mail\Kit\MailKit::image($image !== '' ? $image : null, 200, false),
            'name' => $name,
            'brand' => (string) ($p->brand?->t('name') ?? $p->brand?->name ?? ''),
            'subject_tag' => $this->text('email_subject'),
            'eyebrow' => $this->text('email_eyebrow'),
            'title' => $this->text('email_title'),
            'body' => $this->text($order ? 'email_body_order' : 'email_body_cart'),
            'button' => $this->text($order ? 'email_button_order' : 'email_button_cart'),
            'clock_label' => $this->text('clock_label'),
            'held' => $held,
            'worth' => $this->text('email_worth', ['price' => Money::plain(max(0, (int) $offer['value']))]),
            'chips' => [$this->text('chip_free'), $this->text('chip_full'), '×' . self::qtyOf($offer['qty'] ?? 1)],
            'expires' => $expires,
            'range_id' => (int) $offer['range']['id'],
            'place' => $place,
        ];
    }

    /**
     * The subject with the gift's tag in front ("🎁 A free gift inside · Your
     * basket is waiting"), only when the email carries the gift; otherwise the
     * subject exactly as it was. The tag was resolved in the email's language
     * when the offer was issued.
     */
    public static function subjectWith(string $subject, ?array $offer): string
    {
        $tag = trim((string) ($offer['subject_tag'] ?? ''));

        return $offer === null || $tag === '' ? $subject : $tag . ' · ' . $subject;
    }

    /** "4:15 AM tomorrow", in the shop's time, in the language being drawn. */
    public function endText(CarbonImmutable $at): string
    {
        $local = $at->setTimezone(StoreTime::timezone());
        $today = StoreTime::now()->startOfDay();
        $ar = ! Locale::isDefault();
        $time = $ar ? $local->format('g:i') . ' ' . ($local->format('A') === 'AM' ? 'ص' : 'م') : $local->format('g:i A');
        $days = (int) $today->diffInDays($local->startOfDay(), false);

        return $time . ' ' . match (true) {
            $days <= 0 => $this->text('today'),
            $days === 1 => $this->text('tomorrow'),
            default => $local->format('j/n'),
        };
    }

    /* -------------------------------------------------- the claim, the cart */

    /**
     * The claim link was opened. Returns [where to send the browser, what
     * happened]. NEVER signs anybody in and never restores a basket: a basket
     * claim attaches the gift to the basket this browser already has
     * (creating an empty one if it has none).
     */
    public function claim(string $token, \Illuminate\Http\Request $request): array
    {
        $claim = $this->claimByToken($token);
        $locale = $claim->locale ?? 'en';
        $cartUrl = Url::to(Locale::withSegment('/cart/', $locale));

        if ($claim === null) {
            return [$cartUrl . '?gift=ended', 'unknown'];
        }

        $orderPlace = in_array($claim->place, ['reminder_1', 'reminder_2'], true);
        $order = $orderPlace && $claim->order_id ? Order::query()->find($claim->order_id) : null;
        $endedUrl = $order !== null ? $this->payUrl($order, 'ended') : $cartUrl . '?gift=ended';

        $over = $claim->status === 'used' || $claim->status === 'ended' || now()->greaterThanOrEqualTo(CarbonImmutable::parse($claim->expires_at))
            // Off, or Testing and this browser is not on the list: the same
            // "This gift has ended" as any other link that gives nothing.
            || (! $claim->is_test && ! $this->visibleTo($request));

        if ($over || ($orderPlace && $order === null)) {
            return [$endedUrl, 'ended'];
        }

        if (! $this->stillGiveable($claim)) {
            return [$endedUrl, 'unavailable'];
        }

        $first = $claim->claimed_at === null;

        if ($orderPlace) {
            DB::table('gift_offer_claims')->where('id', $claim->id)->update(['status' => 'claimed', 'claimed_at' => $claim->claimed_at ?? now(), 'updated_at' => now()]);

            if ($first && ! $claim->is_test) {
                GiftOfferStats::bump($claim->place, (int) $claim->range_id, 'click');
            }

            return [$this->payUrl($order, '1'), 'claimed'];
        }

        $cart = app(\App\Services\CartService::class)->current($request, true);
        DB::table('gift_offer_claims')->where('id', $claim->id)->update(['status' => 'claimed', 'cart_id' => $cart->id, 'claimed_at' => $claim->claimed_at ?? now(), 'updated_at' => now()]);
        $cart->forceFill(['gift_offer_claim_id' => $claim->id])->save();

        if ($first && ! $claim->is_test) {
            GiftOfferStats::bump($claim->place, (int) $claim->range_id, 'click');
            GiftOfferStats::bump($claim->place, (int) $claim->range_id, 'added');
        }

        return [$cartUrl, 'claimed'];
    }

    private function payUrl(Order $order, string $gift): string
    {
        try {
            $url = \App\Support\OrderLinks::payUrl($order);
        } catch (\Throwable) {
            return Url::external('/');
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'gift=' . $gift;
    }

    /**
     * The claimed product is published, in stock for ALL the claim's units, and
     * its allocation has that many left (tests skip allocation). A ×2 claim
     * with one unit left is not giveable: it is never cut down to one.
     */
    public function stillGiveable(object $claim): bool
    {
        $qty = self::claimQty($claim);

        if (! $claim->is_test) {
            $left = DB::table('gift_offer_items')->where('id', $claim->item_id)->selectRaw('allocation - used as l')->value('l');

            if ($left === null || (int) $left < $qty) {
                return false;
            }
        }

        $p = Product::query()->find($claim->product_id);

        if ($p === null) {
            return false;
        }

        if ($claim->variant_id) {
            return FreeGifts::variantAvailable($p, \App\Models\ProductVariant::query()->find($claim->variant_id)) >= $qty;
        }

        return $p->status === 'publish' && $p->type !== 'variable' && $p->stock_status === 'instock' && (! $p->manage_stock || (int) $p->stock >= $qty);
    }

    /**
     * The claim this basket holds, if it is still live: one query, and none
     * at all for a basket that holds none (the column is on the cart row).
     */
    public function liveClaim(?Cart $cart): ?object
    {
        if ($cart === null || ! $cart->gift_offer_claim_id) {
            return null;
        }

        $memo = 'kbb.gift_offer.claim.' . $cart->id;
        $request = app()->bound('request') ? request() : null;

        if ($request !== null && $request->attributes->has($memo)) {
            return $request->attributes->get($memo);
        }

        try {
            $claim = DB::table('gift_offer_claims')->where('id', $cart->gift_offer_claim_id)->first();
        } catch (\Throwable) {
            $claim = null;
        }

        if ($claim !== null && ($claim->status !== 'claimed' || (int) $claim->cart_id !== (int) $cart->id || now()->greaterThanOrEqualTo(CarbonImmutable::parse($claim->expires_at)))) {
            $claim = null;
        }

        // Off, or Testing and this request is not on the list: no row, and
        // Place order leaves the gift out.
        if ($claim !== null && ! $claim->is_test && ! $this->visibleTo($request)) {
            $claim = null;
        }

        $request?->attributes->set($memo, $claim);

        return $claim;
    }

    /**
     * THE R2 ROW for the cart panel, cart page and checkout summary, or [] --
     * and then the partial prints NOTHING, not a byte. No query for a basket
     * without a claim. With one: the claim and its product (two queries,
     * memoised for the request, however many surfaces draw it).
     */
    public function row(array $totals): array
    {
        $request = app()->bound('request') ? request() : null;
        $ended = $request !== null && $request->query('gift') === 'ended';
        $cart = app(\App\Services\CartService::class)->resolved();
        $claim = $this->liveClaim($cart);

        if ($claim === null) {
            return $ended ? ['goEnded' => $this->text('ended')] : [];
        }

        $range = $claim->range_id ? $this->rangeById((int) $claim->range_id) : null;
        $basis = $this->basis($totals);
        $short = $range !== null && $basis < $range['min'] ? $range['min'] - $basis : 0;

        return $this->rowView($claim, $short > 0 ? $this->text('row_below', ['amount' => Money::plain($short)]) : '');
    }

    /**
     * The order-pay page (an email's "Pay now & claim my gift" lands there
     * with ?gift=1, an ended one with ?gift=ended): the same row while the
     * order's claim is live. No query without the parameter.
     */
    public function payNote(Order $order): array
    {
        $gift = app()->bound('request') ? request()->query('gift') : null;

        if ($gift === 'ended') {
            return ['goEnded' => $this->text('ended')];
        }

        if ($gift !== '1') {
            return [];
        }

        try {
            $claim = DB::table('gift_offer_claims')->where('order_id', $order->id)->whereIn('place', ['reminder_1', 'reminder_2'])
                ->where('status', 'claimed')->where('expires_at', '>', now())->orderByDesc('expires_at')->first();
        } catch (\Throwable) {
            $claim = null;
        }

        return $claim === null ? ['goEnded' => $this->text('ended')] : $this->rowView($claim, $this->text('pay_note'));
    }

    private function rowView(object $claim, string $below): array
    {
        $p = $this->productFor($claim);

        if ($p === null) {
            return [];
        }

        $total = max(60, (int) CarbonImmutable::parse($claim->created_at)->diffInSeconds(CarbonImmutable::parse($claim->expires_at)));
        $left = max(0, (int) now()->diffInSeconds(CarbonImmutable::parse($claim->expires_at), false));
        $image = (string) ($p->image ?? '');

        return ['goRow' => [
            'name' => (string) ($p->t('name') ?: $p->name),
            'qty' => self::claimQty($claim),
            'img' => $image !== '' ? ImageVariants::variantUrl($image, 200) : '',
            'left' => $left,
            'total' => $claim->place === 'popup' ? $total : max($total, $left),
            'below' => $below,
            'label' => $this->text('clock_label'),
            'free' => $this->text('row_free'),
            'full' => $this->text('row_full'),
            'over' => $this->text('row_over'),
        ]];
    }

    private function productFor(object $claim): ?Product
    {
        $memo = 'kbb.gift_offer.product.' . $claim->product_id;
        $request = app()->bound('request') ? request() : null;

        if ($request !== null && $request->attributes->has($memo)) {
            return $request->attributes->get($memo);
        }

        $p = Product::query()->find($claim->product_id, ['id', 'name', 'slug', 'image', 'brand_id', 'status', 'type', 'stock', 'stock_status', 'manage_stock']);
        $request?->attributes->set($memo, $p);

        return $p;
    }

    /**
     * PLACE ORDER (cart and popup claims): the claim this basket holds, if it
     * is live, in range (the basket is not below the range's minimum) and the
     * gift can still be given. Null otherwise -- the gift is left out
     * silently and the order goes through.
     */
    public function forPlace(Cart $cart, array $totals): ?object
    {
        $claim = $this->liveClaim($cart);

        if ($claim === null || ! in_array($claim->place, ['cart', 'popup'], true)) {
            return null;
        }

        $range = $claim->range_id ? $this->rangeById((int) $claim->range_id) : null;

        if ($range === null || $this->basis($totals) < $range['min'] || ! $this->stillGiveable($claim)) {
            return null;
        }

        return $claim;
    }

    /**
     * Write the gift onto an order: its stock through StockClaim with the
     * order's id (a cancel puts it back the way every line goes back), the
     * AED 0 `is_gift` line marked with this offer, the allocation spent ONCE
     * (a conditional UPDATE that cannot go below zero) and the claim closed.
     * Inside a savepoint: a shelf or allocation that ran dry since the claim
     * leaves the gift out and nothing else. Returns the units written (0, or
     * the claim's quantity: a ×2 claim writes ONE line of quantity 2 and
     * spends 2 of the allocation, all or nothing).
     * MUST run inside the caller's transaction.
     */
    public function attach(Order $order, object $claim): int
    {
        $qty = self::claimQty($claim);

        try {
            return DB::transaction(function () use ($order, $claim, $qty): int {
                if (! $claim->is_test) {
                    $spent = DB::table('gift_offer_items')->where('id', $claim->item_id)->whereRaw('used + ? <= allocation', [$qty])->increment('used', $qty);

                    if ($spent !== 1) {
                        throw new \App\Services\StockUnavailable('gift offer allocation spent');
                    }
                }

                $p = Product::query()->with('brand:id,name')->findOrFail($claim->product_id);
                $v = $claim->variant_id ? \App\Models\ProductVariant::query()->find($claim->variant_id) : null;
                $name = FreeGifts::giftName((string) $p->name, $v, Locale::DEFAULT);

                app(\App\Services\StockClaim::class)->claim([[
                    'product_id' => (int) $p->id,
                    'variant_id' => $v?->id,
                    'quantity' => $qty,
                    'label' => $name,
                ]], (int) $order->id);

                $order->gifts()->create([
                    'product_id' => (int) $p->id,
                ] + ($v === null ? [] : ['product_variant_id' => (int) $v->id])
                  + ($claim->locale === 'ar' ? ['name_localised' => FreeGifts::giftName((string) ($p->t('name', 'ar') ?: $p->name), $v, 'ar')] : []) + [
                    'name' => $name,
                    'brand' => $p->brand?->name,
                    'sku' => $v?->sku ?: $p->sku,
                    'quantity' => $qty,
                    'unit_price' => 0,
                    'subtotal' => 0,
                    'total' => 0,
                    'is_gift' => true,
                    'gift_range_label' => self::LINE_LABEL,
                ]);

                DB::table('gift_offer_claims')->where('id', $claim->id)->update([
                    'status' => 'used', 'order_id' => $order->id, 'used_at' => now(), 'updated_at' => now(),
                ]);

                if (! $claim->is_test) {
                    GiftOfferStats::bump($claim->place, (int) $claim->range_id, 'orders');
                    GiftOfferStats::bump($claim->place, (int) $claim->range_id, 'revenue', max(0, (int) $order->total));
                    DB::afterCommit(fn () => $this->compile());
                }

                return $qty;
            });
        } catch (\App\Services\StockUnavailable) {
            return 0;
        } catch (\Throwable $e) {
            Log::error('gift offer could not be added to the order', ['order' => $order->order_number, 'exception' => class_basename($e)]);

            return 0;
        }
    }

    /** What the gift line says it came from: the admin's order screen and the emails read it. */
    public const LINE_LABEL = 'Gift offer';

    /**
     * A PENDING ORDER WAS PAID (OrderStatus::moveTo(), out of pending/failed
     * into a placed status, inside its transaction). If a reminder's claim for
     * this order was claimed and the payment landed inside its window, the
     * gift is written onto the order. Never gateway code; cannot throw.
     */
    public function orderPaid(Order $order): void
    {
        // Off gives nothing; Testing only to an order made from a listed address.
        // The request here may be a gateway's callback, so it is never asked.
        $mode = $this->config()['mode'];

        if ($mode === 'off' || ($mode === 'testing' && ! $this->testerIp($order->ip_address !== null ? (string) $order->ip_address : null))) {
            return;
        }

        try {
            $claims = DB::table('gift_offer_claims')
                ->where('order_id', $order->id)
                ->whereIn('place', ['reminder_1', 'reminder_2'])
                ->where('status', 'claimed')
                ->where('is_test', false)
                ->orderByDesc('expires_at')
                ->get();
        } catch (\Throwable) {
            return;
        }

        if ($claims->isEmpty()) {
            return;
        }

        try {
            $at = CarbonImmutable::parse($order->paid_at ?? now());
            $units = 0;

            foreach ($claims as $claim) {
                if ($units === 0 && $at->lessThan(CarbonImmutable::parse($claim->expires_at)) && $this->stillGiveable($claim)) {
                    $units = $this->attach($order, $claim);

                    if ($units > 0) {
                        continue;
                    }
                }

                DB::table('gift_offer_claims')->where('id', $claim->id)->where('status', 'claimed')->update(['status' => 'ended', 'updated_at' => now()]);
            }

            if ($units > 0) {
                $order->forceFill(['free_gift_count' => (int) $order->free_gift_count + $units])->saveQuietly();
            }
        } catch (\Throwable $e) {
            Log::error('gift offer could not be checked on payment', ['order' => $order->order_number, 'exception' => class_basename($e)]);
        }
    }

    /* --------------------------------------------------------------- popup */

    /**
     * What the exit popup needs, or null -- and then NOTHING is printed and no
     * script is loaded. Null while the popup is off (no query: settings only),
     * for an admin, for an empty basket, for a basket already holding a gift,
     * and when the basket's range gives nothing.
     */
    /**
     * Should this page carry the popup's small watcher even with no gift to
     * show yet? (The owner, 6 Nov: "i can't see any popups on my mobile, even
     * the settings are on".) The watcher used to arrive only with a page that
     * loaded with something in the bag; a product added on the page itself
     * redraws the cart panel without running scripts, so on THAT page leaving
     * and coming back did nothing. Now, whenever the popup is on and this
     * visitor may see it, the ~600-byte watcher is on the page from the start
     * and finds the gift's <template> when the panel brings one. No query:
     * settings and the request only.
     */
    public function popupArmed(): bool
    {
        if (! $this->placeOn('popup')) {
            return false;
        }

        $request = app()->bound('request') ? request() : null;

        if (! $this->visibleTo($request)) {
            return false;
        }

        return ! ($this->config()['mode'] === 'live' && $request !== null && $request->hasSession()
            && $request->session()->has(\Illuminate\Support\Facades\Auth::guard('admin')->getName()));
    }

    public function popup(array $totals): ?array
    {
        if (! $this->placeOn('popup') || (int) ($totals['item_count'] ?? 0) < 1) {
            return null;
        }

        $request = app()->bound('request') ? request() : null;

        if (! $this->visibleTo($request)) {
            return null;
        }

        // Signed in to the console: never in Live. In Testing the owner is the
        // one testing, so a listed address sees it signed in or not.
        if ($this->config()['mode'] === 'live' && $request !== null && $request->hasSession() && $request->session()->has(\Illuminate\Support\Facades\Auth::guard('admin')->getName())) {
            return null;
        }

        if ($this->liveClaim(app(\App\Services\CartService::class)->resolved()) !== null) {
            return null;
        }

        $offer = $this->offerFor($this->basis($totals));

        if ($offer === null) {
            return null;
        }

        // The script's built address. A build without it (the manifest not
        // uploaded yet) means no popup -- never a page that cannot render.
        try {
            $js = \Illuminate\Support\Facades\Vite::asset('resources/js/kbb/gift-offer-popup.js');
        } catch (\Throwable) {
            return null;
        }

        return $this->popupView($offer) + ['js' => $js];
    }

    /** The popup's words and product, plain text (the view escapes), for the shop and the admin preview. */
    public function popupView(array $offer): array
    {
        $c = $this->config();
        $p = $offer['product'];
        $v = $offer['variant'] ?? null;
        $image = (string) (($v !== null && trim((string) $v->image) !== '' ? $v->image : null) ?? $p->image ?? '');
        $w = $c['popup']['words'];
        $minutes = $c['popup_minutes'];

        return [
            'design' => $c['popup']['design'],
            'style' => $c['popup']['style'],
            'audience' => $c['popup']['audience'],
            'minutes' => $minutes,
            'name' => FreeGifts::giftName((string) ($p->t('name') ?: $p->name), $v),
            'qty' => self::qtyOf($offer['qty'] ?? 1),
            'brand' => (string) ($p->brand?->name ?? ''),
            'img' => $image !== '' ? ImageVariants::variantUrl($image, 400) : '',
            'srcset' => $image !== '' ? ImageVariants::srcsetFor($image) : '',
            'worth' => Money::plain(max(0, (int) $offer['value'])),
            'wait' => $this->text('wait'),
            'h' => $this->text('w' . $w . '_h'),
            // {p} is the product's name (printed bold by the view), {m} the minutes.
            'p' => str_replace('{m}', (string) $minutes, $this->text('w' . $w . '_p')),
            'c' => $this->text('w' . $w . '_c'),
            'l' => $this->text('w' . $w . '_l'),
            't' => array_map(fn (string $k) => $this->text($k), [
                'free' => 'chip_free', 'full' => 'pop_full', 'chip_full' => 'chip_full', 'held' => 'pop_held', 'held_for' => 'pop_held_for',
                'clock' => 'clock_label', 'minutes' => 'pop_minutes', 'seconds' => 'pop_seconds', 'gold' => 'pop_gold',
                'box' => 'pop_box', 'tap' => 'pop_tap', 'added' => 'pop_added', 'opening' => 'pop_opening', 'close' => 'pop_close',
            ]) + ['hold_chip' => str_replace('{m}', (string) $minutes, $this->text('pop_hold_chip'))],
            'range_id' => (int) $offer['range']['id'],
        ];
    }

    /**
     * "Claim it now" on the popup: the basket's range is looked up again on
     * the server (never from anything the browser sent), a claim with the
     * popup's minutes is attached to this basket, and the cart page opens.
     */
    public function claimFromPopup(\Illuminate\Http\Request $request): ?string
    {
        if (! $this->placeOn('popup') || ! $this->visibleTo($request)) {
            return null;
        }

        $carts = app(\App\Services\CartService::class);
        $cart = $carts->current($request, false);

        if ($cart === null || $cart->itemCount() < 1 || $this->liveClaim($cart) !== null) {
            return null;
        }

        $offer = $this->offerFor($this->basis($carts->totals($cart)));

        if ($offer === null) {
            return null;
        }

        $id = DB::table('gift_offer_claims')->insertGetId([
            'token_hash' => self::hash(self::newToken()),
            'place' => 'popup',
            'range_id' => $offer['range']['id'],
            'item_id' => $offer['item_id'],
            'product_id' => $offer['product_id'],
            'variant_id' => $offer['variant_id'],
            'value_fils' => max(0, $offer['value']),
            'cart_id' => $cart->id,
        ] + (($offer['qty'] ?? 1) > 1 ? ['qty' => (int) $offer['qty']] : []) + [
            'status' => 'claimed',
            'is_test' => false,
            'locale' => Locale::isDefault() ? 'en' : 'ar',
            'expires_at' => now()->addMinutes($this->config()['popup_minutes']),
            'claimed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cart->forceFill(['gift_offer_claim_id' => $id])->save();
        GiftOfferStats::bump('popup', (int) $offer['range']['id'], 'claimed');
        GiftOfferStats::bump('popup', (int) $offer['range']['id'], 'added');

        return Url::to(Locale::withSegment('/cart/'));
    }

    /** The sample a preview or a test draws when no basket is given: the range asked for, else the first that gives something. */
    public function sampleOffer(?int $rangeId, ?int $fils): ?array
    {
        if ($rangeId !== null && ($r = $this->rangeById($rangeId)) !== null) {
            return $this->resolve($r);
        }

        if ($fils !== null) {
            return $this->offerFor($fils);
        }

        foreach ($this->ranges() as $r) {
            if (($o = $this->resolve($r)) !== null) {
                return $o;
            }
        }

        return null;
    }

    /** A draft token for the admin's preview: never stored, so it claims nothing. */
    public static function previewToken(): string
    {
        return str_repeat('P', 43);
    }

    public static function strLimit(string $s, int $n): string
    {
        return Str::limit($s, $n, '…');
    }
}
