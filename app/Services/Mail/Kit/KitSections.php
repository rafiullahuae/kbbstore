<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The order of each email's sections — Lane EK, the template editor (e4).
 *
 * The owner, 3 October 2026: "give facility in edit any template, to
 * re-position any section by drag n drop." Emails → Customer emails →
 * (an email) → Edit lists an email's sections; the owner drags them, switches
 * them off, adds a block of his own, and this class makes the kit draw them in
 * that order.
 *
 * ── HOW, WITHOUT TOUCHING A SINGLE BYTE UNTIL HE MOVES SOMETHING ───────────
 *
 * Every email in the kit ends in emails/kit/doc.blade.php, and doc wraps its
 * `@yield('kit')` in @kitsections … @endkitsections. Each reorderable block in
 * the kit's views is wrapped in @kitsec('key') … @endkitsec, which prints a
 * MARKER (a NUL byte, the key, a NUL byte … NUL KE NUL) around the block's own
 * HTML. @endkitsections takes everything doc rendered, cuts it at the markers
 * and puts it back together:
 *
 *     glue₀ · section · glue₁ · section · … · glueₙ
 *
 * With no stored layout the sections go back in the order they came, the
 * markers go, and the bytes are exactly the bytes the view rendered before this
 * class existed — MailKitParityTest compares all nineteen emails with golden
 * files written from the tree before the editor. With a layout the sections
 * PERMUTE among the same slots and the glue stays where it was, which is why
 * the header stays at the top and the footer at the bottom ("Header and footer
 * stay at the top and bottom" — the approved mock).
 *
 * Markers, and not output buffering per section, because a child view's
 * @section runs BEFORE its parent layout: in the simple emails the sections are
 * rendered when the child runs and only reach doc as a string. A marker travels
 * inside that string; a buffer opened in the parent would never see them. NUL
 * cannot occur in HTML a template produces, so a marker cannot be forged by
 * anything a customer typed (and every string the kit prints is escaped anyway).
 *
 * HIDING: a section switched off renders as ''. SHOWING one the code leaves out
 * for that email (the Totals on "Order shipped"): the view's own condition asks
 * on($template, 'totals', default) instead of reading its default directly, so
 * a stored "on" wins. No stored value = the default = today's email.
 *
 * ADDED SECTIONS: "+ Add a section" puts one of the kit's own blocks (text,
 * image, button, coupon, product row, divider, spacer) into the list. They are
 * drawn by KitBlocks — escaped text, scheme-checked URLs, never HTML the admin
 * typed.
 *
 * WHAT THIS CLASS DOES NOT HOLD: the words. Subject, preview line, headline and
 * message are interface strings in `translations`, the rows Translation →
 * Strings already edits; the editor writes those rows. See words().
 */
final class KitSections
{
    public const TABLE = 'email_template_layouts';

    private const OPEN = "\x00KS:";
    private const CLOSE = "\x00KE\x00";

    /** At most this many sections of his own per email. */
    public const MAX_ADDED = 12;

    /*
     * The section vocabulary of the order layout (emails/kit/order.blade.php),
     * in the order the layout draws them.
     */
    private const ORDER_SECTIONS = [
        'hero' => 'Status headline + emoji',
        'tracker' => 'Progress tracker',
        'chip' => 'Order number box',
        'before' => 'Note under the order number',
        'items' => 'Items with pictures',
        'totals' => 'Totals',
        'promises' => 'Why shop with us (3 promises)',
        'info' => 'Delivery address & payment',
        'button' => 'Button',
        'after' => 'Closing line',
        'help' => 'Help box (WhatsApp · email · Instagram)',
        'signoff' => 'Signature',
    ];

    /**
     * Every email the kit draws: its name, whether it has the header row, its
     * sections [key => [label, on by default]] in the order the view draws
     * them, and its words [label => translation key].
     *
     * The defaults are what the view renders TODAY for that email — the table
     * at the top of emails/order-status.blade.php, transcribed. KitSectionsTest
     * renders each email and checks this list against the sections that
     * actually came out, so the editor can never show a switch "on" for a
     * section the email does not have.
     *
     * @var array<string, array{name:string, header:bool, sections:array<string, array{0:string,1:bool}>, words:array<string,string>, note?:string}>
     */
    public const TEMPLATES = [
        'order_confirmation' => [
            'name' => 'Order confirmed', 'header' => true,
            'sections' => [
                'hero' => ['Headline + emoji', true], 'tracker' => ['Progress tracker', true], 'chip' => ['Order number box', true],
                'items' => ['Items with pictures', true], 'totals' => ['Totals', true], 'promises' => ['Why shop with us (3 promises)', false],
                'info' => ['Delivery address & payment', true], 'button' => ['Button · Track your order', true],
                'help' => ['Help box (WhatsApp · email · Instagram)', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Subject' => 'email.confirmation.subject',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_confirmed',
                'Headline' => 'email.confirmation.greeting_named',
                'Headline (no first name known)' => 'email.confirmation.greeting',
                'Message (paid)' => 'email.confirmation.lead_paid',
                'Message (cash on delivery)' => 'email.confirmation.lead',
            ],
        ],
        'order_reminder_1' => [
            'name' => 'Complete your order · 1', 'header' => true,
            'sections' => [
                'hero' => ['Headline + emoji', true], 'tracker' => ['Progress tracker', true], 'chip' => ['Order number box', true],
                'items' => ['Items with pictures', true], 'totals' => ['Totals', true], 'promises' => ['Why shop with us (3 promises)', true],
                'info' => ['Delivery address & payment', false], 'button' => ['Button · Complete your order', true],
                'after' => ['Closing line', true], 'help' => ['Help box (WhatsApp · email · Instagram)', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Subject' => 'email.reminder.first_subject',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_reminder_first',
                'Headline' => 'email.reminder.first_heading',
                'Message' => 'email.reminder.first_body',
                'Closing line' => 'email.reminder.first_closing',
            ],
        ],
        'order_reminder_2' => [
            'name' => 'Complete your order · 2', 'header' => true,
            'sections' => [
                'hero' => ['Headline + emoji', true], 'tracker' => ['Progress tracker', true], 'chip' => ['Order number box', true],
                'items' => ['Items with pictures', true], 'totals' => ['Totals', true], 'promises' => ['Why shop with us (3 promises)', true],
                'info' => ['Delivery address & payment', false], 'button' => ['Button · Complete your order', true],
                'after' => ['Closing line', true], 'help' => ['Help box (WhatsApp · email · Instagram)', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Subject' => 'email.reminder.second_subject',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_reminder_second',
                'Headline' => 'email.reminder.second_heading',
                'Message' => 'email.reminder.second_body',
                'Closing line' => 'email.reminder.second_closing',
            ],
        ],
        'order_status_processing' => [
            'name' => 'Order processing', 'header' => true,
            'sections' => [
                'hero' => ['Status headline + emoji', true], 'tracker' => ['Progress tracker', true], 'chip' => ['Order number box', true],
                'items' => ['Items with pictures', true], 'totals' => ['Totals', true], 'promises' => ['Why shop with us (3 promises)', false],
                'info' => ['Delivery address & payment', true], 'button' => ['Button · Track your order', true],
                'help' => ['Help box (WhatsApp · email · Instagram)', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Subject' => 'email.order_status.processing_subject',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_status',
                'Headline' => 'email.order_status.processing_heading',
                'Message' => 'email.order_status.processing_body',
            ],
        ],
        'order_status_onhold' => [
            'name' => 'Order on hold', 'header' => true,
            'sections' => [
                'hero' => ['Status headline + emoji', true], 'tracker' => ['Progress tracker', true], 'chip' => ['Order number box', true],
                'before' => ['What we need from you', true], 'items' => ['Items with pictures', true], 'totals' => ['Totals', false],
                'promises' => ['Why shop with us (3 promises)', false], 'info' => ['Delivery address & payment', true],
                'button' => ['Button · Reply on WhatsApp', true],
                'help' => ['Help box (WhatsApp · email · Instagram)', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Subject' => 'email.order_status.onhold_subject',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_status',
                'Headline' => 'email.order_status.onhold_heading',
                'Message' => 'email.order_status.onhold_body',
                'Line after your reason' => 'email.order_status.onhold_reply',
            ],
        ],
        'order_status_shipped' => [
            'name' => 'Order shipped', 'header' => true,
            'sections' => [
                'hero' => ['Status headline + emoji 🚚💨', true], 'tracker' => ['Progress tracker', true], 'chip' => ['Order number box', true],
                'before' => ['Tracking number note', true], 'items' => ['Items with pictures', true], 'totals' => ['Totals', false],
                'promises' => ['Why shop with us (3 promises)', false], 'info' => ['Delivery address & payment', true],
                'button' => ['Button · Track your order', true],
                'help' => ['Help box (WhatsApp · email · Instagram)', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Subject' => 'email.order_status.shipped_subject',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_status',
                'Headline' => 'email.order_status.shipped_heading',
                'Message' => 'email.order_status.shipped_body',
                'Tracking number note' => 'email.order_status.tracking_number',
            ],
        ],
        'order_status_completed' => [
            'name' => 'Order delivered', 'header' => true,
            'sections' => [
                'hero' => ['Status headline + emoji ✨', true], 'tracker' => ['Progress tracker', true], 'chip' => ['Order number box', true],
                'before' => ['Tracking number note', true], 'items' => ['Items with pictures', true], 'totals' => ['Totals', false],
                'promises' => ['Why shop with us (3 promises)', false], 'info' => ['Delivery address & payment', false],
                'after' => ['How to use them together', true],
                'help' => ['Help box (WhatsApp · email · Instagram)', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Subject' => 'email.order_status.completed_subject',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_status',
                'Headline' => 'email.order_status.completed_heading',
                'Message' => 'email.order_status.completed_body',
            ],
        ],
        'order_status_cancelled' => [
            'name' => 'Order cancelled', 'header' => true,
            'sections' => [
                'hero' => ['Status headline', true], 'tracker' => ['Progress tracker', true], 'chip' => ['Order number box', true],
                'before' => ['What happens to the money', true], 'items' => ['Items with pictures', true], 'totals' => ['Totals', true],
                'promises' => ['Why shop with us (3 promises)', false], 'info' => ['Delivery address & payment', false],
                'button' => ['Button · Shop again', true],
                'help' => ['Help box (WhatsApp · email · Instagram)', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Subject' => 'email.order_status.cancelled_subject',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_status',
                'Headline' => 'email.order_status.cancelled_heading',
                'Message' => 'email.order_status.cancelled_body',
            ],
        ],
        'order_status_refunded' => [
            'name' => 'Order marked refunded', 'header' => true,
            'sections' => [
                'hero' => ['Status headline', true], 'chip' => ['Order number box', true],
                'items' => ['Items with pictures', true], 'totals' => ['Totals', true],
                'promises' => ['Why shop with us (3 promises)', false], 'info' => ['Delivery address & payment', false],
                'button' => ['Button · View your order', true],
                'help' => ['Help box (WhatsApp · email · Instagram)', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Subject' => 'email.order_status.refunded_subject',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_status',
                'Headline' => 'email.order_status.refunded_heading',
                'Message' => 'email.order_status.refunded_body',
            ],
        ],
        'order_status_failed' => [
            'name' => 'Payment failed', 'header' => true,
            'sections' => [
                'hero' => ['Status headline + emoji 😔', true], 'tracker' => ['Progress tracker', true], 'chip' => ['Order number box', true],
                'items' => ['Items with pictures', true], 'totals' => ['Total to pay', true],
                'promises' => ['Why shop with us (3 promises)', false], 'info' => ['Delivery address & payment', false],
                'button' => ['Button · Complete your order', true],
                'help' => ['Help box (WhatsApp · email · Instagram)', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Subject' => 'email.order_status.failed_subject',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_status',
                'Headline' => 'email.order_status.failed_heading',
                'Message' => 'email.order_status.failed_body',
            ],
        ],
        'order_refunded' => [
            'name' => 'Refund sent', 'header' => true,
            'sections' => [
                'hero' => ['Headline', true], 'chip' => ['Order number box', true], 'before' => ['Refund amounts', true],
                'items' => ['Items with pictures', false], 'totals' => ['Totals', false], 'promises' => ['Why shop with us (3 promises)', false],
                'info' => ['Delivery address & payment', false],
                'help' => ['Help box (WhatsApp · email · Instagram)', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_refunded',
                'Headline' => 'email.refunded.heading_sent',
                'Message' => 'email.refunded.sent_body',
                'Statement note' => 'email.refunded.statement_note',
            ],
        ],
        'order_feedback' => [
            'name' => 'Feedback request', 'header' => true,
            'sections' => [
                'hero' => ['Headline + emoji 💌', true], 'title' => ['Section title', true], 'rates' => ['Rate each product', true],
                'stars' => ['Stars note', true], 'share' => ['Share your routine (Instagram)', true], 'button' => ['Button · Write a review', true],
                'closing' => ['Closing line', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Subject' => 'email.feedback.subject_named',
                'Subject (no first name known)' => 'email.feedback.subject',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_feedback',
                'Headline' => 'email.feedback.heading_named',
                'Message' => 'email.feedback.body',
                'Closing line' => 'email.feedback.closing',
            ],
        ],
        'order_invoice' => [
            'name' => 'Invoice', 'header' => true,
            'sections' => [
                'hero' => ['Headline', true], 'chip' => ['Order number box', true], 'items' => ['Items with pictures', true],
                'totals' => ['Totals', true], 'info' => ['Bill to · Deliver to', true], 'seller' => ['Seller and TRN', true],
                'help' => ['Help box (WhatsApp · email · Instagram)', true], 'signoff' => ['Signature', true],
            ],
            'words' => [
                'Headline' => 'email.kit.invoice_title',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.invoice_lead',
            ],
        ],
        'new_order_alert' => [
            'name' => 'New order alert', 'header' => false,
            'sections' => [
                'alert' => ['Order number + total', true], 'next' => ['What to do next', true], 'items' => ['Items to pick', true],
                'totals' => ['Totals', true], 'info' => ['Ship to · Customer', true],
            ],
            'words' => [
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_alert',
                'What to do next' => 'email.alert.next_step',
            ],
        ],
        'password_reset' => [
            'name' => 'Password reset', 'header' => true,
            'sections' => [
                'hero' => ['Headline', true], 'button' => ['Button · Set a new password', true], 'expiry' => ['How long the link works', true],
                'notice' => ['“Not you?” box', true],
            ],
            'words' => [
                'Headline' => 'email.kit.reset_title',
                'Message' => 'email.reset.lead',
                'Button' => 'email.reset.button',
                '“Not you?” box' => 'email.reset.not_you',
            ],
        ],
        'verify_email' => [
            'name' => 'Confirm email address', 'header' => true,
            'sections' => [
                'hero' => ['Headline', true], 'button' => ['Button · Confirm my email', true],
            ],
            'words' => [
                'Headline' => 'email.kit.verify_title',
                'Message' => 'email.verify.lead',
                'Button' => 'email.verify.button',
            ],
        ],
        'customer_invite' => [
            'name' => 'Account invite', 'header' => true,
            'sections' => [
                'hero' => ['Headline + your message', true], 'button' => ['Button · Set your password', true], 'note' => ['Message after the button', true],
            ],
            'words' => [
                'Headline' => 'email.kit.invite_title',
                'Button' => 'email.customer_invite.button',
            ],
            'note' => 'The subject and the message are written under Store → Customers → Send account invite.',
        ],
        'newsletter_confirm' => [
            'name' => 'Newsletter: confirm subscription', 'header' => true,
            'sections' => [
                'hero' => ['Headline', true], 'button' => ['Button · Confirm', true], 'note' => ['Small print', true],
            ],
            'words' => [
                'Headline' => 'email.kit.newsletter_title',
                'Preview line (shown after the subject in the inbox)' => 'email.kit.pre_newsletter',
                'Small print' => 'email.newsletter.do_nothing',
            ],
        ],
        'quiz_plan' => [
            'name' => 'Skin quiz plan', 'header' => true,
            'sections' => [
                'hero' => ['Headline', true], 'routines' => ['Morning and evening routines', true], 'button' => ['Button', true], 'note' => ['Small print', true],
            ],
            'words' => [
                'Subject' => 'email.quiz_plan.subject',
                'Headline' => 'email.quiz_plan.greeting_named',
                'Message' => 'email.quiz_plan.lead',
                'Small print' => 'email.quiz_plan.steps_note',
            ],
        ],
        'back_in_stock' => [
            'name' => 'Back in stock', 'header' => true,
            'sections' => [
                'hero' => ['Headline + your message', true], 'product' => ['The product', true], 'note' => ['Small print', true],
            ],
            'words' => [
                'Headline' => 'email.kit.stock_title',
                'Button' => 'email.kit.stock_cta',
                'Small print' => 'email.kit.stock_once',
            ],
            'note' => 'The subject and the message are written under Store → Ecommerce → Product page → Back in stock.',
        ],
        'cart_recovery' => [
            'name' => 'Basket reminder', 'header' => true,
            'sections' => [
                'hero' => ['Headline + your message', true], 'items' => ['Items with pictures', true], 'button' => ['Button · Return to my basket', true], 'note' => ['Small print', true],
            ],
            'words' => [
                'Headline' => 'email.kit.basket_title',
                'Button' => 'email.kit.basket_button',
                'Small print' => 'email.cart_recovery.why',
            ],
            'note' => 'The subject and the message are written under Store → Ecommerce → Cart → Basket reminders.',
        ],
    ];

    /** @var array<string, array>|null template => cleaned layout, read once per process */
    private static ?array $stored = null;

    /** @var array<string, array> template => a draft shown by the preview, never stored */
    private static array $drafts = [];

    /** @var list<array{0:?string,1:array}> open regions */
    private static array $stack = [];

    /** @var array<string, list<string>> template => the keys the last render captured with content */
    private static array $seen = [];

    /* ------------------------------------------------------------- rendering */

    /** @kitsec('key') */
    public static function start(string $key): string
    {
        return self::OPEN . $key . "\x00";
    }

    /** @endkitsec */
    public static function end(): string
    {
        return self::CLOSE;
    }

    /** @kitsections($kitTemplate ?? null, $k) */
    public static function open(?string $template, array $k = []): void
    {
        self::$stack[] = [$template, $k];
        ob_start();
    }

    /** @endkitsections */
    public static function close(): string
    {
        $html = (string) ob_get_clean();
        [$template, $k] = array_pop(self::$stack) ?? [null, []];

        return self::arrange($template, $html, $k);
    }

    /**
     * Is $section of $template drawn? The stored switch when there is one, the
     * view's own default otherwise.
     */
    public static function on(?string $template, string $section, bool $default): bool
    {
        if ($template === null) {
            return $default;
        }

        foreach (self::layout($template)['sections'] ?? [] as $row) {
            if (($row['key'] ?? null) === $section && array_key_exists('on', $row)) {
                return (bool) $row['on'];
            }
        }

        return $default;
    }

    /**
     * Cut $html at the markers and put the sections back in the stored order.
     *
     * Public for KitSectionsTest; the views reach it through close().
     */
    public static function arrange(?string $template, string $html, array $k = []): string
    {
        if (! str_contains($html, self::OPEN)) {
            return $html;
        }

        [$glue, $sections] = self::cut($html);

        if ($template !== null) {
            self::$seen[$template] = array_values(array_map(
                static fn (array $s) => $s[0],
                array_filter($sections, static fn (array $s) => trim($s[1]) !== ''),
            ));
        }

        $layout = $template === null ? [] : self::layout($template);

        if (($layout['sections'] ?? []) === []) {
            return self::join($glue, array_map(static fn (array $s) => $s[1], $sections));
        }

        $byKey = [];

        foreach ($sections as $i => [$key]) {
            $byKey[$key] ??= $i;
        }

        $placed = [];
        $items = [];

        foreach ($layout['sections'] as $row) {
            $key = (string) ($row['key'] ?? '');

            if (isset($row['block'])) {
                $items[] = ['block', $row['block']];

                continue;
            }

            if (! isset($byKey[$key]) || isset($placed[$byKey[$key]])) {
                continue;
            }

            $placed[$byKey[$key]] = true;
            $items[] = ['section', $byKey[$key], ($row['on'] ?? true) !== false];
        }

        // A section the code draws that the stored list does not name (a
        // section added to the kit after the owner saved) goes back beside
        // its neighbour from the source, never silently missing.
        foreach ($sections as $i => $s) {
            if (isset($placed[$i])) {
                continue;
            }

            $at = 0;

            for ($p = $i - 1; $p >= 0; $p--) {
                $found = self::indexOfSection($items, $p);

                if ($found !== null) {
                    $at = $found + 1;
                    break;
                }
            }

            array_splice($items, $at, 0, [['section', $i, true]]);
            $placed[$i] = true;
        }

        $parts = [];

        foreach ($items as $item) {
            if ($item[0] === 'block') {
                $parts[] = KitBlocks::render([$item[1]], $k);

                continue;
            }

            $parts[] = $item[2] ? $sections[$item[1]][1] : '';
        }

        return self::join($glue, $parts);
    }

    /**
     * Render with a layout that is not stored — the editor's live preview.
     *
     * @template T
     *
     * @param  callable(): T  $render
     * @return T
     */
    public static function withDraft(string $template, array $layout, callable $render): mixed
    {
        $had = array_key_exists($template, self::$drafts);
        $before = self::$drafts[$template] ?? null;
        self::$drafts[$template] = self::clean($template, $layout);

        try {
            return $render();
        } finally {
            if ($had) {
                self::$drafts[$template] = $before;
            } else {
                unset(self::$drafts[$template]);
            }
        }
    }

    /** The keys the last render of $template drew with something in them. */
    public static function seen(string $template): array
    {
        return self::$seen[$template] ?? [];
    }

    /* --------------------------------------------------------------- storage */

    /** The stored layout of one email, cleaned; [] when it has none. */
    public static function layout(string $template): array
    {
        if (array_key_exists($template, self::$drafts)) {
            return self::$drafts[$template];
        }

        return self::all()[$template] ?? [];
    }

    /** Whether the owner has changed this email's sections. */
    public static function customised(string $template): bool
    {
        return isset(self::all()[$template]);
    }

    /**
     * Store a layout. Only a template in the catalogue, only its own section
     * keys, each at most once, and blocks KitBlocks::clean() accepts. Returns
     * the layout as stored.
     */
    public static function save(string $template, array $layout, ?string $by = null): array
    {
        if (! isset(self::TEMPLATES[$template])) {
            throw new \InvalidArgumentException('Unknown email.');
        }

        $clean = self::clean($template, $layout);

        DB::table(self::TABLE)->updateOrInsert(
            ['template' => $template],
            ['layout' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_by' => $by, 'updated_at' => now(), 'created_at' => now()],
        );

        self::forget();

        return $clean;
    }

    /** "Reset to default": the row goes, and the email is the code's again. */
    public static function reset(string $template): void
    {
        DB::table(self::TABLE)->where('template', $template)->delete();
        self::forget();
    }

    /**
     * A layout from the outside world, made safe.
     *
     * Section rows: a key of this email's catalogue, once each, with an `on`
     * kept only where it differs from the default — so a section whose default
     * is changed in code later follows the code unless the owner moved it.
     * Block rows: an id the editor made up plus a block KitBlocks accepts.
     */
    public static function clean(string $template, array $layout): array
    {
        $catalogue = self::TEMPLATES[$template]['sections'] ?? [];
        $rows = is_array($layout['sections'] ?? null) ? $layout['sections'] : [];
        $out = [];
        $seen = [];
        $added = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = is_string($row['key'] ?? null) ? $row['key'] : '';

            if (isset($row['block'])) {
                $block = is_array($row['block']) ? KitBlocks::clean([$row['block']]) : [];

                if ($block === [] || $added >= self::MAX_ADDED || preg_match('/^b[0-9a-z]{1,12}$/', $key) !== 1 || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $added++;
                $out[] = ['key' => $key, 'block' => $block[0]];

                continue;
            }

            if (! isset($catalogue[$key]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $entry = ['key' => $key];

            if (array_key_exists('on', $row) && (bool) $row['on'] !== $catalogue[$key][1]) {
                $entry['on'] = (bool) $row['on'];
            }

            $out[] = $entry;
        }

        // Every catalogue section is in a stored list. One the request left
        // out goes back after its neighbour from the catalogue — and after any
        // block the owner added straight after that neighbour, so a block
        // stays with the section it was put under.
        $keys = array_keys($catalogue);

        foreach ($keys as $i => $key) {
            if (isset($seen[$key])) {
                continue;
            }

            $at = 0;

            for ($p = $i - 1; $p >= 0; $p--) {
                $found = array_search($keys[$p], array_column($out, 'key'), true);

                if ($found !== false) {
                    $at = $found + 1;

                    while (isset($out[$at]['block'])) {
                        $at++;
                    }

                    break;
                }
            }

            array_splice($out, $at, 0, [['key' => $key]]);
            $seen[$key] = true;
        }

        return ['sections' => $out];
    }

    /** The editor's view of one email: every section with its switch. */
    public static function forEditor(string $template): array
    {
        $catalogue = self::TEMPLATES[$template]['sections'];
        $layout = self::layout($template);
        $rows = $layout['sections'] ?? array_map(static fn (string $k) => ['key' => $k], array_keys($catalogue));
        $out = [];

        foreach ($rows as $row) {
            if (isset($row['block'])) {
                $out[] = ['key' => $row['key'], 'label' => KitBlocks::label($row['block']), 'on' => true, 'block' => $row['block']];

                continue;
            }

            $key = $row['key'];
            $out[] = [
                'key' => $key,
                'label' => $catalogue[$key][0],
                'on' => array_key_exists('on', $row) ? (bool) $row['on'] : $catalogue[$key][1],
                'default' => $catalogue[$key][1],
            ];
        }

        return $out;
    }

    public static function forget(): void
    {
        self::$stored = null;
        self::$drafts = [];
        self::$seen = [];
        self::$stack = [];
    }

    /* ------------------------------------------------------------- internals */

    /** @return array<string, array> */
    private static function all(): array
    {
        if (self::$stored !== null) {
            return self::$stored;
        }

        self::$stored = [];

        try {
            if (! Schema::hasTable(self::TABLE)) {
                return self::$stored;
            }

            foreach (DB::table(self::TABLE)->get(['template', 'layout']) as $row) {
                $template = (string) $row->template;

                if (! isset(self::TEMPLATES[$template])) {
                    continue;
                }

                $decoded = json_decode((string) $row->layout, true);
                self::$stored[$template] = self::clean($template, is_array($decoded) ? $decoded : []);
            }
        } catch (\Throwable $e) {
            // An email must never fail to send because its layout could not be
            // read: it goes out exactly as the code draws it.
            Log::warning('email layouts not read', ['exception' => class_basename($e)]);
        }

        return self::$stored;
    }

    /** @return array{0: list<string>, 1: list<array{0:string,1:string}>} */
    private static function cut(string $html): array
    {
        $glue = [];
        $sections = [];
        $pos = 0;
        $openLen = strlen(self::OPEN);

        while (($start = strpos($html, self::OPEN, $pos)) !== false) {
            $keyEnd = strpos($html, "\x00", $start + $openLen);
            $end = $keyEnd === false ? false : strpos($html, self::CLOSE, $keyEnd + 1);

            if ($keyEnd === false || $end === false) {
                break;
            }

            $glue[] = substr($html, $pos, $start - $pos);
            $sections[] = [substr($html, $start + $openLen, $keyEnd - $start - $openLen), substr($html, $keyEnd + 1, $end - $keyEnd - 1)];
            $pos = $end + strlen(self::CLOSE);
        }

        $glue[] = substr($html, $pos);

        return [$glue, $sections];
    }

    /**
     * glue₀ · part · glue₁ · part · … · glueₙ — the inner glue keeps its slot,
     * so with the parts in source order this is the HTML that came in.
     *
     * @param  list<string>  $glue
     * @param  list<string>  $parts
     */
    private static function join(array $glue, array $parts): string
    {
        $last = count($glue) - 1;
        $out = $glue[0];

        foreach ($parts as $i => $part) {
            $out .= $part;

            if ($i < count($parts) - 1) {
                $out .= ($i + 1 < $last) ? $glue[$i + 1] : '';
            }
        }

        // Inner glue the parts did not reach (fewer parts than slots).
        for ($j = count($parts); $j < $last; $j++) {
            $out .= $glue[$j];
        }

        return $out . ($last > 0 ? $glue[$last] : '');
    }

    private static function indexOfSection(array $items, int $source): ?int
    {
        foreach ($items as $i => $item) {
            if ($item[0] === 'section' && $item[1] === $source) {
                return $i;
            }
        }

        return null;
    }
}
