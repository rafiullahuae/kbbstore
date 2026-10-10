<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Services\Mail\Kit\KitProducts;
use App\Services\Reviews\ReviewRequests;
use App\Services\Reviews\ReviewRequestSettings;
use App\Support\Url;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * "⭐ Aisha, what did you think of Heartleaf Toner?" — the review request,
 * N days after the order was completed (Lane RV). When it goes is
 * App\Services\Reviews\ReviewRequests' business; this draws it.
 *
 * ONE CARD PER PRODUCT, FIVE STARS EACH, AND EACH STAR IS ITS OWN LINK. There is
 * no script in an email, so a star cannot be "selected" — it is a link to the
 * review page with that product and that rating already chosen
 * (?p=<product>&r=<1..5>), which the page reads and preselects without any
 * script either (a checked radio). That is the difference from the feedback
 * request's stars, which can only open the product page: this email has a page
 * of its own that understands the parameter.
 *
 * THE LINK IS A BEARER TOKEN for this one order's review page and nothing
 * else: it opens a form, shows nothing the email did not already show, and
 * expires (Schedule & rules → Days the review link keeps working). A blank
 * token — the template editor's preview and "Send test to me" — gives links to
 * a page that answers "not valid", never a working form for a real order.
 *
 * List-Unsubscribe with List-Unsubscribe-Post (RFC 8058): the stop link is a
 * POST-able address that acts on its own token with no session, so the mail
 * client's own button works in one click.
 */
class ReviewRequestMail extends OrderMail
{
    use BrandedSubject;

    /** @var list<array{productId:int, name:string, brand:string, variant:string, img:?string, url:string, stars:list<string>}> */
    public array $products = [];

    public string $firstName = '';

    public string $pageUrl;

    public string $stopUrl;

    public string $starHex;

    public int $linkDays;

    /** When the order was placed, ISO 8601 — for "your purchase … 5 days ago". */
    public string $placedAt = '';

    /**
     * @param  list<int>|null  $productIds  the products the sweep chose; null (a
     *                                       preview) asks the same chooser, without
     *                                       leaving out ones already reviewed
     */
    public function __construct(Order $order, private string $token = '', ?array $productIds = null)
    {
        parent::__construct($order);

        $requests = app(ReviewRequests::class);
        $settings = app(ReviewRequestSettings::class);

        $ids = $productIds ?? $requests->productIdsFor($order, false);
        $this->pageUrl = ReviewRequests::pageUrl($token);
        $this->stopUrl = ReviewRequests::stopUrl($token);
        $this->starHex = $settings->starHex();
        $this->linkDays = $settings->int('review_request_link_days');

        $order->loadMissing('items.product.brand');
        $images = KitProducts::imagesForIds($ids);
        $wanted = array_flip($ids);
        $cards = [];

        foreach ($order->items as $item) {
            $id = (int) ($item->product_id ?? 0);

            if (! isset($wanted[$id]) || isset($cards[$id])) {
                continue;
            }

            $attributes = is_array($item->variant_attributes) ? $item->variant_attributes : [];
            $variant = implode(', ', array_filter(array_map(static fn ($v) => trim((string) $v), $attributes), static fn (string $v) => $v !== ''));
            $localised = trim((string) ($item->name_localised ?? ''));
            $brand = trim((string) ($item->brand ?? ''));

            if ($brand === '' && $item->product !== null) {
                $brand = trim((string) ($item->product->brand?->name ?? ''));
            }

            $stars = [];

            foreach ([1, 2, 3, 4, 5] as $n) {
                $stars[] = ReviewRequests::pageUrl($token, ['p' => $id, 'r' => $n]) . '#rr-' . $id;
            }

            $cards[$id] = [
                'productId' => $id,
                'name' => $localised !== '' ? $localised : (trim((string) $item->name) !== '' ? trim((string) $item->name) : 'Item'),
                'brand' => $brand,
                'variant' => $variant,
                'img' => $images[$id] ?? null,
                'url' => $item->product !== null ? Url::external('/product/' . $item->product->slug . '/') : $this->pageUrl,
                'stars' => $stars,
            ];
        }

        // In the order the sweep chose them, which is the order they were bought.
        foreach ($ids as $id) {
            if (isset($cards[$id])) {
                $this->products[] = $cards[$id];
            }
        }

        $billing = is_array($order->billing_address) ? $order->billing_address : [];
        $this->firstName = trim((string) ($billing['first_name'] ?? ''));
        $this->placedAt = (string) optional($order->created_at)->toIso8601String();
    }

    /** "today", "yesterday", "5 days ago" — from when the order was placed. */
    public function ago(): string
    {
        $days = 0;

        try {
            $days = (int) floor(\Illuminate\Support\Carbon::parse($this->placedAt !== '' ? $this->placedAt : 'now')->startOfDay()->diffInDays(now()->startOfDay(), true));
        } catch (\Throwable) {
        }

        return match (true) {
            $days <= 0 => __('email.review_request.ago_today'),
            $days === 1 => __('email.review_request.ago_yesterday'),
            default => __('email.review_request.ago_days', ['count' => $days]),
        };
    }

    public function envelope(): Envelope
    {
        $product = $this->products[0]['name'] ?? $this->brandName();

        return new Envelope(subject: $this->firstName !== ''
            ? __('email.review_request.subject_named', ['name' => $this->firstName, 'product' => $product])
            : __('email.review_request.subject', ['product' => $product]));
    }

    public function content(): Content
    {
        $name = $this->firstName !== '' ? $this->firstName : __('email.review_request.there');

        return new Content(
            view: 'emails.review-request',
            text: 'emails.review-request-text',
            with: [
                'kitTitle' => (string) $this->envelope()->subject,
                'heading' => __('email.review_request.heading', ['name' => $name]),
                'body' => __('email.review_request.body', ['name' => $name, 'store' => $this->brandName(), 'ago' => $this->ago()]),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<' . $this->stopUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
