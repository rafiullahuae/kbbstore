<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Product;

/**
 * Marketing Pixels — ported from KBB Modules v2.39.0, the `wp_head` and
 * `woocommerce_thankyou` hooks around lines 815–869.
 *
 * Three IDs, each independent — filling in one does not require the others.
 * Off by default, and even switched on, a pixel with no ID set fires nothing
 * (`if ('' !== $meta) { ... }` in the plugin, carried across exactly): turning
 * the module on is not itself an event source, only a gate in front of
 * whichever IDs are actually filled in.
 *
 * WHERE THE IDS NOW LIVE. This class no longer reads module_settings for an
 * ID and no longer builds a loader tag. Both are App\Services\Analytics' job:
 * it is the single decider of which ID is live for each network (the SEO
 * screen's `ga` and `meta_pixel` boxes are aliases of the same two values, not
 * rival ones) and the single emitter of the loader markup, once per request.
 * What is left here is the EVENTS, which is what this class was always for.
 *
 * Four render points, matching the plugin's four hook sites:
 *   - baseTags()      — every page, in <head>. PageView / page_view / page+load.
 *   - viewContent()    — a product page. ViewContent / view_item.
 *   - beginCheckout()  — the checkout page, never the success page.
 *   - purchase()       — the success page, exactly once per order.
 */
class MarketingPixels
{
    public const SCHEMA = [
        'meta_id'   => ['text', 'Meta Pixel ID', '', 'Fires PageView, ViewContent, InitiateCheckout and Purchase.'],
        'ga4_id'    => ['text', 'Google (GA4) Measurement ID', '', 'Fires page_view, view_item, begin_checkout and purchase.'],
        'tiktok_id' => ['text', 'TikTok Pixel ID', '', 'Fires page browse and CompletePayment.'],
    ];

    public const TABS = [
        'pixels' => ['Pixels', 'Add an ID to activate that pixel. Leave any of them blank to skip it.', ['meta_id', 'ga4_id', 'tiktok_id']],
    ];

    /** SCHEMA key => the network Analytics knows it by. */
    private const NETWORK = ['meta_id' => 'meta', 'ga4_id' => 'ga4', 'tiktok_id' => 'tiktok'];

    public function __construct(private Analytics $analytics) {}

    public function enabled(): bool
    {
        return $this->analytics->enabled();
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $out[$key] = $this->analytics->id(self::NETWORK[$key]);
        }

        return $out;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach (self::SCHEMA as $key => $def) {
            if (array_key_exists($key, $values)) {
                $this->analytics->setId(self::NETWORK[$key], (string) $values[$key]);
            }
        }
    }

    private function active(): bool
    {
        return $this->analytics->anyActive();
    }

    /**
     * The loader tags. Delegated in full: Analytics emits them once per
     * request, so this staying as a call site costs nothing and a page that
     * reaches the loader only through this method keeps working.
     */
    public function baseTags(): string
    {
        return $this->analytics->headTags();
    }

    /**
     * Product-page view event.
     *
     * EVERY interpolated value is json_encode()d, numbers included. A bare
     * `{$price}` is a PHP float rendered by string conversion, and that is not
     * always JavaScript: a large or tiny value prints as `1.0E+25`, which is a
     * syntax error inside an object literal and takes the whole tag down with
     * it, and a float that happens to be integral prints without its decimals.
     * json_encode() is the only conversion in this file that is guaranteed to
     * produce a JavaScript literal for whatever it is handed.
     *
     * Nothing here is customer data. A product id, a product name and a price
     * are on the page the shopper is already looking at.
     */
    public function viewContent(Product $product): string
    {
        $out = '';
        $price = json_encode((float) $product->effectivePrice() / 100);
        $currency = json_encode($this->currency());
        $pid = json_encode((string) $product->id);

        if ($this->analytics->active('meta')) {
            $out .= "<script>fbq('track','ViewContent',{content_ids:[{$pid}],content_type:'product',value:{$price},currency:{$currency}});</script>\n";
        }

        if ($this->analytics->active('ga4')) {
            $name = json_encode((string) $product->name);
            $out .= "<script>gtag('event','view_item',{currency:{$currency},value:{$price},items:[{item_id:{$pid},item_name:{$name},price:{$price}}]});</script>\n";
        }

        return $out;
    }

    /**
     * AddToCart, wired to the buttons rather than to a page.
     *
     * Meta's WooCommerce plugin fires this and every lookalike audience and
     * add-to-cart optimisation depends on it; without it the funnel jumps
     * PageView -> InitiateCheckout and the middle is invisible.
     *
     * Emitted as one delegated listener rather than per button, because the
     * cart panel, quick view and grids all rebuild their markup after load and
     * per-element handlers would be lost. Delegation on document catches
     * whatever exists at click time.
     *
     * Price and name come from the button's own data attributes, written
     * server-side from effectivePrice(), so a sale price is reported as the
     * price actually charged. If a button carries no price the event still
     * fires with the id, which is enough to build an audience -- a missing
     * attribute must not cost the event.
     *
     * Fires on click, not on cart success. The cart request is what the click
     * starts, and there is no event to hang it on; over-reporting a failed add
     * is a smaller loss than under-reporting every successful one.
     */
    public function addToCart(): string
    {
        if (! $this->active()) {
            return '';
        }

        $currency = json_encode($this->currency());

        $calls = [];

        if ($this->analytics->active('meta')) {
            $calls[] = "if(window.fbq)fbq('track','AddToCart',{content_ids:[id],content_type:'product',value:v,currency:{$currency}});";
        }

        /*
         * This read `ga_id` -- a key that is not in SCHEMA, was never written
         * by any screen and could therefore never be non-empty. GA4 has
         * recorded no add_to_cart from this shop since the method was written,
         * even with a GA4 ID filled in and the module on: the funnel simply had
         * a hole in it between view_item and begin_checkout, and because the
         * other two calls were built correctly nothing looked broken. The
         * network name is now the one Analytics knows, so there is no second
         * spelling of it left to get wrong.
         */
        if ($this->analytics->active('ga4')) {
            $calls[] = "if(window.gtag)gtag('event','add_to_cart',{currency:{$currency},value:v,items:[{item_id:id,item_name:n,price:v,quantity:q}]});";
        }

        if ($this->analytics->active('tiktok')) {
            $calls[] = "if(window.ttq)ttq.track('AddToCart',{content_id:String(id),content_type:'product',value:v,currency:{$currency}});";
        }

        if ($calls === []) {
            return '';
        }

        $body = implode("\n    ", $calls);

        return <<<HTML
<script>
document.addEventListener('click', function (e) {
  var el = e.target.closest('[data-kbb-add]');
  if (!el) return;
  var id = el.getAttribute('data-kbb-add');
  if (!id) return;
  var v = parseFloat(el.getAttribute('data-price') || '0') || 0;
  var n = el.getAttribute('data-name') || '';
  var q = parseInt(el.getAttribute('data-quantity') || '1', 10) || 1;
  try {
    {$body}
  } catch (err) {}
}, true);
</script>

HTML;
    }

    /**
     * Checkout-page event. The caller is responsible for never calling this on
     * the success page.
     *
     * A basket total and a currency, and nothing else — no email, no address,
     * no customer id. The basket's line items are deliberately not sent: what
     * a shop needs from begin_checkout is the value, and the items would add
     * nothing the purchase event does not already carry.
     */
    public function beginCheckout(int $totalFils): string
    {
        $value = json_encode($totalFils / 100);
        $currency = json_encode($this->currency());
        $out = '';

        if ($this->analytics->active('meta')) {
            $out .= "<script>fbq('track','InitiateCheckout',{value:{$value},currency:{$currency}});</script>\n";
        }

        if ($this->analytics->active('ga4')) {
            $out .= "<script>gtag('event','begin_checkout',{currency:{$currency},value:{$value}});</script>\n";
        }

        return $out;
    }

    /**
     * Success-page event — exactly once per order. The plugin flags the order
     * itself so a refresh of the thank-you page cannot double-count a sale;
     * `pixels_fired_at` on the order does the same job here.
     */
    public function purchase(Order $order): string
    {
        if (! $this->active() || $order->pixels_fired_at !== null) {
            return '';
        }

        // Check-then-set has a real race window: two near-simultaneous
        // requests for the same success page (a double-tap, two open tabs,
        // a retried request) could both pass the null check above before
        // either had saved, firing Purchase twice for one sale. This claims
        // the flag atomically — only the request that actually flips
        // pixels_fired_at from null gets to render the tags; a second,
        // near-simultaneous request sees 0 rows affected and returns quietly.
        $claimed = Order::whereKey($order->id)
            ->whereNull('pixels_fired_at')
            ->update(['pixels_fired_at' => now()]);

        if ($claimed === 0) {
            return '';
        }

        /*
         * WHAT LEAVES THE SHOP HERE, checked field by field, because this is
         * the one tag that sees an order.
         *
         *   transaction_id  the ORDER NUMBER, not the row id. The number is
         *                   printed on the page the customer is looking at and
         *                   on their receipt; the row id is an internal
         *                   sequence and is not sent.
         *   value, currency the order total. Already on the page.
         *   items           product id, product name, quantity, unit price —
         *                   the catalogue, which is public.
         *
         * NOT SENT, and there is no version of this tag that should send them:
         * the customer's email, name, phone, billing or delivery address, the
         * customer id, the order id, the coupon code, or anything from the
         * payment. Meta in particular will happily accept hashed personal data
         * in an `em`/`ph` parameter; nothing here builds one.
         *
         * Every interpolated value is json_encode()d, the numbers included. A
         * bare `. $total .` is a PHP float rendered by string conversion, and
         * that is not always JavaScript — a large or small enough total prints
         * as `1.0E+25`, which is a syntax error inside an object literal and
         * takes the whole tag, and therefore the sale, down with it.
         */
        $total = json_encode((float) $order->total / 100);
        $currency = json_encode((string) ($order->currency ?: $this->currency()));
        $ids = [];
        $items = [];

        foreach ($order->items as $item) {
            $ids[] = (string) $item->product_id;
            $items[] = [
                'item_id' => (string) $item->product_id,
                'item_name' => (string) $item->name,
                'quantity' => (int) $item->quantity,
                'price' => (float) $item->unit_price / 100,
            ];
        }

        $out = '';

        if ($this->analytics->active('meta')) {
            $out .= '<script>fbq(\'track\',\'Purchase\',{value:' . $total . ',currency:' . $currency
                . ',content_type:\'product\',content_ids:' . json_encode($ids) . '});</script>' . "\n";
        }

        if ($this->analytics->active('ga4')) {
            $orderNumber = json_encode((string) $order->order_number);
            $out .= '<script>gtag(\'event\',\'purchase\',{transaction_id:' . $orderNumber . ',value:' . $total
                . ',currency:' . $currency . ',items:' . json_encode($items) . '});</script>' . "\n";
        }

        if ($this->analytics->active('tiktok')) {
            $out .= '<script>ttq.track(\'CompletePayment\',{value:' . $total . ',currency:' . $currency . '});</script>' . "\n";
        }

        // pixels_fired_at was already claimed atomically above, before any
        // tag was built — nothing left to save here.
        return $out;
    }

    /**
     * No admin screen anywhere in this app sets an ISO currency code — only
     * a display symbol exists (Money::SYMBOL), and the shop is AED-only by
     * design. A settings key nobody can change is not a setting; this says
     * what is actually true rather than reading a phantom control.
     */
    private function currency(): string
    {
        return 'AED';
    }
}
