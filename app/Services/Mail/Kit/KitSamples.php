<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use App\Mail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Services\CartRecovery;
use App\Services\CustomerInvites\InviteTemplate;
use App\Services\SettingsService;
use App\Services\StockAlerts;
use App\Support\Url;
use Illuminate\Mail\Mailable;

/**
 * Every kit email, filled with this shop's own latest data, for the template
 * editor's preview and its "Send test to me" — Lane EK.
 *
 * Nothing here sends and nothing here writes: an order email is the latest
 * order as it is (Emails → Design & branding's preview does the same), a
 * back-in-stock alert is the newest product a shopper can buy, a password
 * reset carries a token that resets nothing. The links in a sample are
 * therefore real-looking but inert where they would act (a reset token that
 * was never issued, a confirm link for subscriber 0).
 */
final class KitSamples
{
    /** The email as a Mailable, or null when the shop has nothing to fill it with (no order yet). */
    public static function mailable(string $template): ?Mailable
    {
        $needsOrder = str_starts_with($template, 'order_') || $template === 'new_order_alert';
        $order = $needsOrder ? Order::query()->with('items')->orderByDesc('id')->first() : null;

        if ($needsOrder && $order === null) {
            return null;
        }

        $url = static fn (string $path) => Url::external($path);

        return match (true) {
            $template === 'order_confirmation' => new Mail\OrderConfirmation($order),
            $template === 'order_reminder_1' => new Mail\OrderPaymentReminder($order, 1),
            $template === 'order_reminder_2' => new Mail\OrderPaymentReminder($order, 2),
            $template === 'order_status_onhold' => new Mail\OrderStatusChanged($order, 'onhold', 'Please confirm the building name for delivery.'),
            str_starts_with($template, 'order_status_') => new Mail\OrderStatusChanged($order, substr($template, 13)),
            $template === 'order_refunded' => new Mail\OrderRefunded($order, $order->refunds()->latest('id')->first()
                ?? new Refund(['amount' => max(1, intdiv((int) $order->total, 2)), 'status' => 'succeeded', 'provider_ref' => 'preview'])),
            $template === 'order_feedback' => new Mail\OrderFeedbackRequest($order),
            $template === 'order_invoice' => new Mail\OrderInvoice($order),
            $template === 'new_order_alert' => new Mail\NewOrderAlert($order),
            $template === 'back_in_stock' => self::stock($url),
            $template === 'cart_recovery' => self::cart($url),
            $template === 'customer_invite' => self::invite($url),
            $template === 'newsletter_confirm' => new Mail\NewsletterConfirmation($url('/newsletter/confirm/0/'), $url('/newsletter/unsubscribe/0/'), 14),
            $template === 'quiz_plan' => new Mail\QuizPlanEmail('Aisha', 'Combination', ['Dullness', 'Dryness'], [
                ['name' => 'Morning', 'steps' => ['Cleanse', 'Tone', 'Serum', 'Moisturise', 'Sunscreen']],
                ['name' => 'Evening', 'steps' => ['Oil cleanse', 'Cleanse', 'Tone', 'Serum', 'Moisturise']],
            ], $url('/shop/'), null),
            in_array($template, ['password_reset', 'verify_email'], true) => self::account($template),
            default => null,
        };
    }

    /** The rendered HTML of the sample, or a one-line explanation when there is none. */
    public static function html(string $template): string
    {
        try {
            $mail = self::mailable($template);
        } catch (\Throwable $e) {
            return self::explain('This email could not be filled for the preview: ' . class_basename($e) . '.');
        }

        if ($mail === null) {
            return self::explain('The preview fills itself from the shop\'s latest order, and there is no order yet.');
        }

        return (string) $mail->render();
    }

    public static function explain(string $text): string
    {
        return '<p style="font-family:sans-serif;color:#626c80;padding:24px">' . e($text) . '</p>';
    }

    /* ------------------------------------------------------------- internals */

    private static function stock(\Closure $url): Mailable
    {
        $settings = app(SettingsService::class);
        $product = Product::query()->visible()->inStock()->orderByDesc('id')->first();

        return new Mail\BackInStockAlert(
            trim((string) $settings->get(StockAlerts::KEY_SUBJECT, '')) ?: 'It is back in stock',
            trim((string) $settings->get(StockAlerts::KEY_BODY, '')) ?: 'Your message from Store → Ecommerce → Product page → Back in stock goes here.',
            (string) ($product?->name ?? 'Your product'),
            $url('/product/' . ($product?->slug ?? 'sample') . '/'),
            $url('/mail-preferences/'),
        );
    }

    private static function cart(\Closure $url): Mailable
    {
        $settings = app(SettingsService::class);
        $items = Product::query()->visible()->inStock()->orderByDesc('id')->limit(2)->get()
            ->map(static fn (Product $p) => ['name' => (string) $p->name, 'slug' => (string) $p->slug, 'quantity' => 1, 'unit_price' => (int) $p->effectivePrice()])
            ->all();

        return new Mail\CartRecoveryReminder(
            trim((string) $settings->get(CartRecovery::KEY_SUBJECT, '')) ?: 'Your basket is saved',
            trim((string) $settings->get(CartRecovery::KEY_BODY, '')) ?: 'Your message from Store → Ecommerce → Cart → Basket reminders goes here.',
            $items,
            $url('/cart/'),
            $url('/mail-preferences/'),
        );
    }

    private static function invite(\Closure $url): Mailable
    {
        $current = app(InviteTemplate::class)->current();
        $shop = (string) (app(SettingsService::class)->get('store_name', '') ?: config('app.name', 'K Beauty Bliss'));
        $link = $url('/account/invite/preview/');
        $values = ['first_name' => 'Aisha', 'name' => 'Aisha Khan', 'email' => 'aisha@example.com', 'shop_name' => $shop,
            'set_password_link' => $link, 'link_expires' => now()->addDays((int) ($current['expiry_days'] ?? 7))->format('j F Y')];

        return new Mail\CustomerAccountInvite(
            InviteTemplate::subject((string) ($current['subject'] ?? InviteTemplate::DEFAULT_SUBJECT), $values),
            InviteTemplate::segments((string) ($current['body'] ?? InviteTemplate::DEFAULT_BODY), $values),
            InviteTemplate::text((string) ($current['body'] ?? InviteTemplate::DEFAULT_BODY), $values),
            $link,
            $shop,
        );
    }

    /** The two account emails are notifications; their message is wrapped as a Mailable. */
    private static function account(string $template): Mailable
    {
        $customer = Customer::query()->orderByDesc('id')->first() ?? new Customer(['email' => 'customer@example.com', 'name' => 'Aisha']);

        if ($customer->getKey() === null) {
            $customer->id = 0;
        }

        $message = $template === 'password_reset'
            ? (new \App\Notifications\CustomerPasswordReset('preview-not-a-real-token', (int) $customer->id))->toMail($customer)
            : (new \App\Notifications\CustomerEmailVerification($customer))->toMail($customer);

        $view = is_array($message->view) ? $message->view[0] : $message->view;
        $html = (string) view($view, $message->viewData)->render();

        return new Mail\KitPreviewMail((string) $message->subject, $html);
    }
}
