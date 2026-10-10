<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\Pixels\UserData;
use App\Services\SettingsService;
use App\Support\BrandName;
use App\Support\OrderLinks;
use App\Support\OrderPaymentPanel;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;

/**
 * "Send order link" (Lane OL): a failed or unpaid order, handed back to the
 * customer as one link that opens it on the existing pay page.
 *
 * The owner: "if any order failed, i need a proper button (send order link) so
 * the app will generate a link and the customer can continue with the same
 * cart etc and select the payment to complete the order."
 *
 * NOTHING HERE PAYS FOR ANYTHING. The link is OrderLinks::payUrl() — the
 * signed /checkout/order-pay link the reminder emails already carry — and the
 * page it opens is OrderPayController, which starts the payment through the
 * same PaymentGateway::start() the checkout uses, on the same order. This class
 * only decides which orders get the button, builds the link with the owner's
 * expiry, words the WhatsApp message, and writes down that it was sent.
 *
 * WHERE "LAST SENT" LIVES: in the order's own notes. Every send writes one
 * note starting NOTE_PREFIX, which the order screen already shows, and the
 * panel reads the newest such note off the notes both order screens have
 * already loaded — no column, no table, no extra query.
 */
final class OrderPayLink
{
    public const NOTE_PREFIX = 'Payment link ';

    public const VIA = ['copy', 'whatsapp', 'email'];

    public const SETTING_DAYS = 'order_paylink_days';

    public const SETTING_WA_EN = 'order_paylink_whatsapp_en';

    public const SETTING_WA_AR = 'order_paylink_whatsapp_ar';

    public const SETTING_SUBJECT = 'order_paylink_email_subject';

    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 30;

    public const MAX_TEXT = 600;

    public const DEFAULT_WA_EN = 'Hi {name}, your {shop} order #{order} is saved. Complete it here: {link}';

    public const DEFAULT_WA_AR = 'مرحباً {name}، طلبك رقم #{order} من {shop} محفوظ. أكملي طلبك من هنا: {link}';

    /** Calling codes for the delivery countries the checkout sells to; anything else is read as the UAE's. */
    private const CALLING = ['AE' => '971', 'SA' => '966', 'KW' => '965', 'QA' => '974', 'BH' => '973', 'OM' => '968'];

    public function __construct(private SettingsService $settings) {}

    /* ------------------------------------------------------------ eligibility */

    /**
     * Failed, or pending with no payment. Not a sample, not in the trash.
     * Exactly the orders OrderPayController will take a payment for, so the
     * button never offers a link the page would then refuse.
     */
    public static function offered(Order $order): bool
    {
        $status = (string) $order->status;

        return ($status === 'failed' || $status === 'pending')
            && $order->paid_at === null
            && ! $order->trashed()
            && ! SampleOrder::is($order);
    }

    /* --------------------------------------------------------------- settings */

    /** @return array{days: int, whatsapp_en: string, whatsapp_ar: string, email_subject: string} */
    public function settings(): array
    {
        $all = $this->settings->all();

        $days = (int) ($all[self::SETTING_DAYS] ?? self::DEFAULT_DAYS);

        return [
            'days' => $days >= 1 && $days <= self::MAX_DAYS ? $days : self::DEFAULT_DAYS,
            'whatsapp_en' => self::text($all[self::SETTING_WA_EN] ?? '') ?: self::DEFAULT_WA_EN,
            'whatsapp_ar' => self::text($all[self::SETTING_WA_AR] ?? '') ?: self::DEFAULT_WA_AR,
            'email_subject' => self::line($all[self::SETTING_SUBJECT] ?? ''),
        ];
    }

    /**
     * Validate and store. Returns the error sentence, or null when saved.
     *
     * @param  array<string, mixed>  $in
     */
    public function save(array $in): ?string
    {
        $days = filter_var($in['days'] ?? null, FILTER_VALIDATE_INT);

        if ($days === false || $days < 1 || $days > self::MAX_DAYS) {
            return 'The link must last between 1 and ' . self::MAX_DAYS . ' days.';
        }

        $en = self::text($in['whatsapp_en'] ?? '');
        $ar = self::text($in['whatsapp_ar'] ?? '');

        foreach (['English' => $en, 'Arabic' => $ar] as $which => $t) {
            if ($t !== '' && ! str_contains($t, '{link}')) {
                return 'The ' . $which . ' WhatsApp message needs {link} in it, or the customer gets no link.';
            }
        }

        $this->settings->set(self::SETTING_DAYS, (string) $days);
        // Stored empty when it matches the default, so a later change to the
        // default wording reaches a shop that never customised it.
        $this->settings->set(self::SETTING_WA_EN, $en === self::DEFAULT_WA_EN ? '' : $en);
        $this->settings->set(self::SETTING_WA_AR, $ar === self::DEFAULT_WA_AR ? '' : $ar);
        $this->settings->set(self::SETTING_SUBJECT, self::line($in['email_subject'] ?? ''));

        return null;
    }

    /* ------------------------------------------------------------------- link */

    public function url(Order $order): string
    {
        return OrderLinks::payUrl($order, $this->settings()['days'] * 86400);
    }

    /** "17 Oct 2026", in shop time. */
    public static function expiresLabel(string $url): string
    {
        $at = OrderLinks::expiresAt($url);

        return $at === null ? '' : (StoreTime::display(\Carbon\CarbonImmutable::createFromTimestampUTC($at))?->format('j M Y') ?? '');
    }

    /** The number on the order, as stored: shipping, then billing, then the order's own column. */
    public static function phone(Order $order): string
    {
        foreach ([$order->shipping_address, $order->billing_address] as $addr) {
            $p = is_array($addr) ? trim((string) ($addr['phone'] ?? '')) : '';

            if ($p !== '') {
                return $p;
            }
        }

        return trim((string) ($order->phone ?? ''));
    }

    /** International digits for wa.me (971508883841), or null when the number cannot be read. */
    public static function whatsappDigits(Order $order): ?string
    {
        $country = '';

        foreach ([$order->shipping_address, $order->billing_address] as $addr) {
            if (is_array($addr) && trim((string) ($addr['country'] ?? '')) !== '') {
                $country = strtoupper(trim((string) $addr['country']));
                break;
            }
        }

        return UserData::phoneDigits(self::phone($order), self::CALLING[$country] ?? '971');
    }

    public static function firstName(Order $order): string
    {
        foreach ([$order->shipping_address, $order->billing_address] as $addr) {
            $n = is_array($addr) ? trim((string) ($addr['first_name'] ?? '')) : '';

            if ($n !== '') {
                return $n;
            }
        }

        $name = trim((string) ($order->relationLoaded('customer') ? ($order->customer?->name ?? '') : ''));

        return $name === '' ? '' : (string) preg_split('/\s+/u', $name)[0];
    }

    /** The prefilled message: English, plus Arabic under it for an order placed in Arabic. */
    public function message(Order $order, string $url): string
    {
        $s = $this->settings();
        $name = self::firstName($order);
        // The name the order emails sign with (EmailBranding::storeName()).
        $shop = trim((string) ($this->settings->all()['store_name'] ?? '')) ?: BrandName::appName();
        $fill = fn (string $t, string $fallbackName) => strtr($t, [
            '{name}' => $name !== '' ? $name : $fallbackName,
            '{order}' => (string) $order->order_number,
            '{link}' => $url,
            '{shop}' => $shop,
        ]);

        // "Hi there" reads better than "Hi ," when the order has no name on it.
        $text = $fill($s['whatsapp_en'], 'there');

        if ((string) $order->locale === 'ar') {
            $text .= "\n\n" . $fill($s['whatsapp_ar'], '');
            $text = (string) preg_replace('/ ?،/u', '،', $text);
        }

        return $text;
    }

    public function whatsappUrl(Order $order, string $url): string
    {
        $digits = self::whatsappDigits($order);

        return 'https://wa.me/' . ($digits ?? '') . '?text=' . rawurlencode($this->message($order, $url));
    }

    /* ---------------------------------------------------------------- history */

    /** Write the send onto the order's notes. */
    public function record(Order $order, string $via, string $by, string $url, string $to = ''): void
    {
        if (! in_array($via, self::VIA, true)) {
            return;
        }

        $how = match ($via) {
            'whatsapp' => 'sent by WhatsApp' . ($to !== '' ? ' to ' . $to : ''),
            'email' => 'emailed' . ($to !== '' ? ' to ' . $to : ''),
            default => 'copied to send by hand',
        };

        $expires = self::expiresLabel($url);

        $order->notes()->create([
            'content' => self::NOTE_PREFIX . $how . '. The customer can open the order and choose how to pay'
                . ($expires !== '' ? '; the link works until ' . $expires : '') . '.',
            'author' => $by !== '' ? $by : 'Admin',
            'is_customer_note' => false,
        ]);
    }

    /**
     * The newest send, read off notes the screen has already loaded.
     *
     * @return array{via: string, label: string, at: ?string, at_label: string, by: string}|null
     */
    public static function last(Order $order): ?array
    {
        $notes = $order->relationLoaded('notes') ? $order->notes : $order->notes()->get();

        $note = $notes->filter(fn ($n) => str_starts_with((string) $n->content, self::NOTE_PREFIX))
            ->sortByDesc('id')->first();

        if ($note === null) {
            return null;
        }

        $text = (string) $note->content;
        $via = str_contains($text, 'by WhatsApp') ? 'whatsapp' : (str_starts_with($text, self::NOTE_PREFIX . 'emailed') ? 'email' : 'copy');
        $at = StoreTime::display($note->created_at);

        return [
            'via' => $via,
            'label' => ['whatsapp' => 'WhatsApp', 'email' => 'Email', 'copy' => 'Copied link'][$via],
            'at' => $at?->toIso8601String(),
            'at_label' => $at?->format('j M Y, g:i A') ?? '',
            'by' => (string) ($note->author ?? ''),
        ];
    }

    /**
     * What the order screens need to draw the button. ALLOWLISTED: no link, no
     * token, no address — the link is minted only when someone presses the
     * button, behind its own capability.
     *
     * @return array{offered: bool, has_phone: bool, has_email: bool, last: ?array}
     */
    public static function panel(Order $order): array
    {
        $offered = self::offered($order);

        return [
            'offered' => $offered,
            'has_phone' => $offered && self::whatsappDigits($order) !== null,
            'has_email' => $offered && str_contains((string) $order->email, '@'),
            'last' => $offered ? self::last($order) : null,
        ];
    }

    /* ----------------------------------------------------------- availability */

    /**
     * The order's lines that cannot be paid for now, item id => 'unavailable'
     * or 'out_of_stock'. The pay page shows each one and takes no payment
     * while any is listed.
     *
     *   - UNAVAILABLE: the product is binned or no longer published. A line
     *     with no product id at all is not judged (nothing to ask).
     *   - OUT OF STOCK, only for an order that has GIVEN ITS UNITS BACK (a
     *     failed one): the units its failure released are no longer on the
     *     shelf, read off the same ledger OrderStatus will reclaim from, so
     *     this says no exactly when the revive would. A failed order whose
     *     line took nothing from a counted shelf (imported, or a product that
     *     counts no stock) is out of stock when the product says so. A PENDING
     *     order still holds its units, so a product showing "out of stock"
     *     because this very order took the last one is not a problem.
     *
     * Flat cost: one read of the products, and for a failed order one of the
     * ledger and one per shelf table — whatever the number of lines.
     *
     * @return array<int, string>
     */
    public static function problems(Order $order): array
    {
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();

        if ($items->isEmpty()) {
            return [];
        }

        $ids = $items->pluck('product_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $products = $ids === [] ? collect() : DB::table('products')->whereIn('id', $ids)
            ->get(['id', 'status', 'stock_status', 'deleted_at'])->keyBy('id');

        $released = [];
        $shelves = [];

        if ((string) $order->status === 'failed') {
            $rows = DB::table('order_stock_claims')->where('order_id', $order->getKey())
                ->whereNotNull('released_at')->get(['shelf_table', 'shelf_id', 'product_id', 'product_variant_id', 'quantity']);

            foreach ($rows as $r) {
                $key = (int) $r->product_id . ':' . (int) $r->product_variant_id;
                $released[$key][] = $r;
                $shelves[(string) $r->shelf_table][(int) $r->shelf_id] = true;
            }

            foreach ($shelves as $table => $set) {
                if (! in_array($table, \App\Services\StockClaim::SHELVES, true)) {
                    unset($shelves[$table]);
                    continue;
                }
                $shelves[$table] = DB::table($table)->whereIn('id', array_keys($set))->pluck('stock', 'id')->all();
            }
        }

        $out = [];

        foreach ($items as $item) {
            // A line with no product id (an imported or hand-made line) names no
            // shelf to ask, and the existing pay page has always taken it.
            if ($item->product_id === null) {
                continue;
            }

            $p = $products->get((int) $item->product_id);

            if ($p === null || $p->deleted_at !== null || (string) $p->status !== 'publish') {
                $out[(int) $item->id] = 'unavailable';
                continue;
            }

            if ((string) $order->status !== 'failed') {
                continue;
            }

            $key = (int) $item->product_id . ':' . (int) $item->product_variant_id;

            if (isset($released[$key])) {
                foreach ($released[$key] as $r) {
                    $have = $shelves[(string) $r->shelf_table][(int) $r->shelf_id] ?? null;

                    if ($have === null || (int) $have < (int) $r->quantity) {
                        $out[(int) $item->id] = 'out_of_stock';
                    }
                }
            } elseif ((string) $p->stock_status === 'outofstock') {
                $out[(int) $item->id] = 'out_of_stock';
            }
        }

        return $out;
    }

    /* ---------------------------------------------------------------- helpers */

    /** Plain text a message may carry: no control characters but newlines, bounded. */
    private static function text(mixed $v): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        $t = str_replace("\r\n", "\n", (string) $v);
        $t = (string) preg_replace('/[^\P{C}\n]+/u', '', $t);

        return trim(mb_substr($t, 0, self::MAX_TEXT));
    }

    /** One line: a subject. */
    private static function line(mixed $v): string
    {
        return trim(mb_substr((string) preg_replace('/\s+/u', ' ', self::text($v)), 0, 150));
    }

    /** Whether a status could ever take this link (for the pay page's wording). */
    public static function paidState(Order $order): bool
    {
        return $order->paid_at !== null || in_array((string) $order->status, OrderPaymentPanel::PAID_STATUSES, true);
    }
}
