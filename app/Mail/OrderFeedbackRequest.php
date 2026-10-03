<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Support\Url;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "How is your glow? 💌" — the feedback request, 3 hours after the Delivered
 * email (Lane RL). When it goes is OrderReminders::feedbackDue()'s business.
 *
 * ONE ROW PER PRODUCT, EACH LINKING TO THAT PRODUCT'S REVIEWS. The product
 * page's review section is `#sr` (partials/reviews.blade.php) with its own
 * "Write a review" button; that is where the link lands. Lane RJ's preview
 * drew five stars per product that "open the product page with your stars
 * already chosen" — the review form has no such parameter (the rating is set
 * by clicking stars inside the sheet, resources/js/kbb/reviews.js), so no
 * ?rating= is invented and the email does not promise a preselection.
 *
 * A line whose product has gone, or is no longer on sale, gets no button: a
 * review link to a page that 404s is worse than no link.
 */
class OrderFeedbackRequest extends OrderMail
{
    use BrandedSubject;

    /** @var list<array{name: string, brand: string, url: string}> */
    public array $products = [];

    public string $firstName = '';

    public function __construct(Order $order)
    {
        parent::__construct($order);

        $order->loadMissing('items.product');
        $seen = [];

        foreach ($order->items as $item) {
            $product = $item->product;

            if ($product === null || isset($seen[$product->id]) || (string) $product->status !== 'publish') {
                continue;
            }

            $seen[$product->id] = true;
            $this->products[] = [
                // For the row's picture (Lane EM, KitProducts::imagesForIds).
                'productId' => (int) $product->id,
                'name' => (string) $item->name,
                'brand' => (string) ($item->brand ?? ''),
                'url' => Url::external('/product/' . $product->slug . '/') . '#sr',
            ];
        }

        $billing = is_array($order->billing_address) ? $order->billing_address : [];
        $this->firstName = trim((string) ($billing['first_name'] ?? ''));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->firstName !== ''
            ? __('email.feedback.subject_named', ['name' => $this->firstName])
            : __('email.feedback.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-feedback',
            text: 'emails.order-feedback-text',
            with: [
                'kitTitle' => (string) $this->envelope()->subject,
                'heading' => $this->firstName !== ''
                    ? __('email.feedback.heading_named', ['name' => $this->firstName])
                    : __('email.feedback.heading'),
                'body' => __('email.feedback.body', ['number' => $this->orderNumber()]),
            ],
        );
    }
}
