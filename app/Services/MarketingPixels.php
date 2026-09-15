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

    private ?array $cache = null;

    public function __construct(private SettingsService $settings) {}

    public function enabled(): bool
    {
        return $this->settings->moduleEnabled('marketing_pixels', false);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $out[$key] = trim((string) $this->settings->moduleSetting('marketing_pixels', $key, $def[2]));
        }

        return $this->cache = $out;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach (self::SCHEMA as $key => $def) {
            if (array_key_exists($key, $values)) {
                $this->settings->setModuleSetting('marketing_pixels', $key, mb_substr(trim((string) $values[$key]), 0, 60));
            }
        }

        $this->cache = null;
    }

    private function active(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $c = $this->all();

        return $c['meta_id'] !== '' || $c['ga4_id'] !== '' || $c['tiktok_id'] !== '';
    }

    /** Base loader tags plus the page-view event for each configured pixel. */
    public function baseTags(): string
    {
        if (! $this->active()) {
            return '';
        }

        $c = $this->all();
        $out = '';

        if ($c['meta_id'] !== '') {
            $id = json_encode($c['meta_id']);
            $out .= "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init',{$id});fbq('track','PageView');</script>\n";
        }

        if ($c['ga4_id'] !== '') {
            $id = json_encode($c['ga4_id']);
            $src = rawurlencode($c['ga4_id']);
            $out .= "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$src}\"></script>\n";
            $out .= "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',{$id});</script>\n";
        }

        if ($c['tiktok_id'] !== '') {
            $id = json_encode($c['tiktok_id']);
            $out .= "<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=i;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};var o=d.createElement('script');o.type='text/javascript';o.async=!0;o.src=i+'?sdkid='+e+'&lib='+t;var a=d.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};ttq.load({$id});ttq.page();}(window,document,'ttq');</script>\n";
        }

        return $out;
    }

    /** Product-page view event. */
    public function viewContent(Product $product): string
    {
        if (! $this->active()) {
            return '';
        }

        $c = $this->all();
        $price = (float) $product->effectivePrice() / 100;
        $currency = json_encode($this->currency());
        $out = '';

        if ($c['meta_id'] !== '') {
            $pid = json_encode((string) $product->id);
            $out .= "<script>fbq('track','ViewContent',{content_ids:[{$pid}],content_type:'product',value:{$price},currency:{$currency}});</script>\n";
        }

        if ($c['ga4_id'] !== '') {
            $pid = json_encode((string) $product->id);
            $name = json_encode($product->name);
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

        $c = $this->all();
        $currency = json_encode($this->currency());

        $calls = [];

        if (! empty($c['meta_id'])) {
            $calls[] = "if(window.fbq)fbq('track','AddToCart',{content_ids:[id],content_type:'product',value:v,currency:{$currency}});";
        }

        if (! empty($c['ga_id'])) {
            $calls[] = "if(window.gtag)gtag('event','add_to_cart',{currency:{$currency},value:v,items:[{item_id:id,item_name:n,price:v,quantity:q}]});";
        }

        if (! empty($c['tiktok_id'])) {
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

    /** Checkout-page event. The caller is responsible for never calling this on the success page. */
    public function beginCheckout(int $totalFils): string
    {
        if (! $this->active()) {
            return '';
        }

        $c = $this->all();
        $value = $totalFils / 100;
        $currency = json_encode($this->currency());
        $out = '';

        if ($c['meta_id'] !== '') {
            $out .= "<script>fbq('track','InitiateCheckout',{value:{$value},currency:{$currency}});</script>\n";
        }

        if ($c['ga4_id'] !== '') {
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

        $c = $this->all();
        $total = (float) $order->total / 100;
        $currency = json_encode($order->currency ?: $this->currency());
        $ids = [];
        $items = [];

        foreach ($order->items as $item) {
            $ids[] = (string) $item->product_id;
            $items[] = [
                'item_id' => (string) $item->product_id,
                'item_name' => $item->name,
                'quantity' => $item->quantity,
                'price' => (float) $item->unit_price / 100,
            ];
        }

        $out = '';

        if ($c['meta_id'] !== '') {
            $out .= '<script>fbq(\'track\',\'Purchase\',{value:' . $total . ',currency:' . $currency
                . ',content_type:\'product\',content_ids:' . json_encode($ids) . '});</script>' . "\n";
        }

        if ($c['ga4_id'] !== '') {
            $orderNumber = json_encode($order->order_number);
            $out .= '<script>gtag(\'event\',\'purchase\',{transaction_id:' . $orderNumber . ',value:' . $total
                . ',currency:' . $currency . ',items:' . json_encode($items) . '});</script>' . "\n";
        }

        if ($c['tiktok_id'] !== '') {
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
