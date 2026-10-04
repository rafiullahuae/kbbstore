<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Services\Mail\Kit\KitSections;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emails → Customer emails (e3): one row per message the shop sends, with its
 * switch — Lane EK.
 *
 * NO SECOND COPY OF ANY SETTING. Every switch on this screen IS an existing
 * module toggle — the rows Store → Modules and Emails → All mail settings have
 * always written (OrderStatusMailPolicy::MODULE_KEYS and OrderMailer's own
 * keys, read by name there). A row with no toggle behind it says so: "Manual"
 * (a button somebody presses), or "Always on" (password reset — security —
 * and the two that have no off switch anywhere in the shop). Showing a switch
 * that stores nothing is the defect this project has paid for three times.
 *
 * The rows are the approved mock's, in its order, plus the three emails the
 * shop sends that the mock predates (Order processing, Order marked refunded,
 * Feedback request) — the brief is "the list of every email".
 */
final class CustomerEmails
{
    /** module key => default when the toggle row does not exist (the readers' own defaults). */
    public const SWITCHES = [
        'email_order_confirmation' => true,
        'email_order_reminder_1' => true,
        'email_order_reminder_2' => true,
        'email_order_processing' => true,
        'email_order_onhold' => false,
        'email_order_shipped' => true,
        'email_order_completed' => true,
        'email_order_cancelled' => true,
        'email_order_marked_refunded' => true,
        'email_order_refunded' => true,
        'email_order_failed' => true,
        'email_order_feedback' => true,
        'email_merchant_new_order' => true,
        'back_in_stock' => false,
        'abandoned_cart' => false,
    ];

    /**
     * [group, template, name, sub, when, state, module|null, mail kinds for "Last sent"]
     *
     * state: 'switch' (a toggle above), 'manual' (shown as Manual while its
     * module is off), 'always', 'follows' (on/off follows a feature switch
     * that is not this email's to flip).
     */
    private const ROWS = [
        ['Orders — to the customer', 'order_confirmation', 'Order confirmed', 'Never before payment', 'Paid (card, Tabby, Tamara) or placed with cash on delivery → Processing', 'switch', 'email_order_confirmation', ['order.confirmation']],
        ['Orders — to the customer', 'order_reminder_1', 'Complete your order · 1', 'NEW · status <code>pending</code>', 'Still unpaid 30 minutes after it was started, any payment method', 'switch', 'email_order_reminder_1', ['order.reminder_1']],
        ['Orders — to the customer', 'order_reminder_2', 'Complete your order · 2', 'NEW · status <code>pending</code>', 'Still unpaid after 24 hours — the last reminder', 'switch', 'email_order_reminder_2', ['order.reminder_2']],
        ['Orders — to the customer', 'order_status_processing', 'Order processing', 'Status <code>processing</code> · not after payment — the confirmation covers that', 'Status → Processing, moved by hand', 'switch', 'email_order_processing', ['order.status_processing']],
        ['Orders — to the customer', 'order_status_onhold', 'Order on hold', 'NEW · status <code>onhold</code> · you type the reason', '“Send on-hold email” button on the order', 'manual', 'email_order_onhold', ['order.status_onhold']],
        ['Orders — to the customer', 'order_status_shipped', 'Order shipped', 'Order number = tracking number · Track your order link', 'Status → Shipped', 'switch', 'email_order_shipped', ['order.status_shipped']],
        ['Orders — to the customer', 'order_status_completed', 'Order delivered', 'NEW · status <code>completed</code>', 'Status → Completed', 'switch', 'email_order_completed', ['order.status_completed']],
        ['Orders — to the customer', 'order_feedback', 'Feedback request', 'NEW · 3 hours after the Delivered email', 'Once per order, after Delivered', 'switch', 'email_order_feedback', ['order.feedback']],
        ['Orders — to the customer', 'order_status_cancelled', 'Order cancelled', 'Status <code>cancelled</code>', 'Status → Cancelled', 'switch', 'email_order_cancelled', ['order.status_cancelled']],
        ['Orders — to the customer', 'order_refunded', 'Refund sent', 'Follows the money, not the <code>refunded</code> word', 'A refund is sent (full or part)', 'switch', 'email_order_refunded', ['order.refund']],
        ['Orders — to the customer', 'order_status_refunded', 'Order marked refunded', 'Status <code>refunded</code>, set by hand', 'Status → Refunded, with no refund made on the order screen', 'switch', 'email_order_marked_refunded', ['order.status_refunded']],
        ['Orders — to the customer', 'order_status_failed', 'Payment failed', 'NEW · status <code>failed</code>', 'Status → Failed', 'switch', 'email_order_failed', ['order.status_failed']],
        ['Orders — to the customer', 'order_invoice', 'Invoice', 'The invoice is the email itself', '“Email invoice” button on the order', 'manual', null, ['order.invoice']],
        ['To you', 'new_order_alert', 'New order alert', '', 'Order paid, or placed with cash on delivery', 'switch', 'email_merchant_new_order', ['order.merchant_alert']],
        ['Account & sign-up', 'password_reset', 'Password reset', 'Always on — security', 'Customer asks for one', 'always', null, ['account.password_reset']],
        ['Account & sign-up', 'verify_email', 'Confirm email address', 'Always on — it is how an account proves its address', 'Account created', 'always', null, ['account.verify_email']],
        ['Account & sign-up', 'customer_invite', 'Account invite', '', 'You send one from Customers', 'manual', null, ['customer.invite']],
        ['Account & sign-up', 'newsletter_confirm', 'Newsletter: confirm subscription', 'Goes with the sign-up form (Store → Modules → Email Capture)', 'Website sign-up (double opt-in)', 'follows', 'newsletter', ['newsletter.confirm']],
        ['Account & sign-up', 'quiz_plan', 'Skin quiz plan', 'Always on — the shopper asked for it', 'Quiz finished with an email', 'always', null, ['quiz.plan']],
        ['Shopping reminders', 'back_in_stock', 'Back in stock', '', 'A product they asked about returns', 'switch', 'back_in_stock', ['stock.back']],
        ['Shopping reminders', 'cart_recovery', 'Basket reminder', '', 'Basket left with an email', 'switch', 'abandoned_cart', ['cart.recovery']],
    ];

    /**
     * template => "Group · Name", for Emails → Sending & delivery → Send a test
     * → "Which email". The owner, 4 October: "i need here all the emails,
     * pending order, and in failed order" — the list was five hand-picked rows;
     * it is now every email this screen knows, from the same rows. (2.60.376)
     *
     * @return array<string, string>
     */
    public static function testChoices(): array
    {
        $out = [];

        foreach (self::ROWS as [$group, $template, $name]) {
            $out[$template] = $group . ' · ' . $name;
        }

        return $out;
    }

    public function __construct(private SettingsService $settings) {}

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $last = $this->lastSent();
        $merchant = '';

        try {
            $merchant = app(OrderMailer::class)->merchantAddress();
        } catch (\Throwable) {
            // The row still shows; it just does not name the address.
        }

        $out = [];

        foreach (self::ROWS as [$group, $template, $name, $sub, $when, $state, $module, $kinds]) {
            $on = match ($state) {
                'always' => true,
                'switch', 'manual', 'follows' => $module !== null && $this->settings->moduleEnabled($module, self::SWITCHES[$module] ?? false),
                default => false,
            };

            if ($template === 'new_order_alert' && $merchant !== '') {
                $sub = 'To ' . e($merchant);
            }

            if (in_array($template, ['back_in_stock', 'cart_recovery'], true) && ! $on) {
                $sub = 'Module off today';
            }

            $at = null;

            foreach ($kinds as $kind) {
                if (isset($last[$kind]) && ($at === null || $last[$kind] > $at)) {
                    $at = $last[$kind];
                }
            }

            $out[] = [
                'group' => $group,
                'template' => $template,
                'name' => $name,
                // Constant HTML from this file (a <code> around a status word),
                // never a setting — the merchant address is escaped above.
                'sub_html' => $sub,
                'when' => $when,
                'state' => $state,
                'on' => $on,
                // A switch the screen may flip: only a row whose toggle is
                // this email's own.
                'switchable' => $state === 'switch',
                'last_sent' => $at,
                'customised' => KitSections::customised($template) || \App\Services\Mail\Kit\EmailWording::updatedAt($template) !== null,
                'edited_at' => \App\Services\Mail\Kit\EmailWording::updatedAt($template),
                // The subject this email goes out with, in the owner's words if
                // he wrote one ({tags} as he typed them), else the built-in.
                'subject' => self::subject($template),
            ];
        }

        return $out;
    }

    private static function subject(string $template): string
    {
        $key = KitSections::TEMPLATES[$template]['words']['subject'] ?? null;

        if ($key === null) {
            return '';
        }

        $own = \App\Services\Mail\Kit\EmailWording::value($template, 'subject', 'en');
        $english = (string) (\App\Services\Translation\InterfaceStrings::english($key) ?? '');

        return $own ?? \App\Services\Mail\Kit\EmailWording::toTags($english, \App\Services\Mail\Kit\EmailWording::tags($english));
    }

    /** Flip one email's own switch. False when the row has no switch of its own. */
    public function set(string $template, bool $on): bool
    {
        foreach (self::ROWS as $row) {
            if ($row[1] === $template && $row[5] === 'switch' && $row[6] !== null) {
                $this->settings->setModule($row[6], $on);

                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> mail kind => newest accepted send (ISO 8601) */
    private function lastSent(): array
    {
        try {
            if (! Schema::hasTable('mail_deliveries')) {
                return [];
            }

            // One grouped query for the whole screen, not one per row.
            return DB::table('mail_deliveries')
                ->where('status', 'sent')
                ->groupBy('kind')
                ->selectRaw('kind, MAX(created_at) AS at')
                ->pluck('at', 'kind')
                ->map(static fn ($at) => \Illuminate\Support\Carbon::parse((string) $at)->toIso8601String())
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
