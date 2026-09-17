<?php

declare(strict_types=1);

use App\Mail\BackInStockAlert;
use App\Mail\CartRecoveryReminder;
use App\Mail\NewOrderAlert;
use App\Mail\NewsletterConfirmation;
use App\Mail\OrderConfirmation;
use App\Mail\OrderInvoice;
use App\Mail\OrderRefunded;
use App\Mail\OrderStatusChanged;
use App\Models\AdminUser;
use App\Models\Refund;
use Tests\Support\EnglishRenderWalk;
use Tests\Support\InvoiceAdminRoutes;

/**
 * The same acceptance bar as tests/Feature/StorefrontEnglishUnchangedTest.php,
 * for the documents a shopper receives rather than the pages they visit.
 *
 * WHY THIS IS A SEPARATE FILE. The route walk cannot reach any of these. Eight
 * of them are Mailables — rendered by a queue worker, never by a request — and
 * four are printed documents served from the admin API, which the storefront
 * walk skips by prefix. They are also where an untranslated string is worst:
 * an email arrives weeks after the checkout, from a process with no memory of
 * the shopper's language, and an Arabic customer with an English invoice has
 * nothing to press to fix it.
 *
 * The method is identical and the reasoning is in the other file's header: the
 * BEFORE side is resources/views materialised out of git at BASE_COMMIT, both
 * sides render in one process against one fixture, and a control pass proves
 * the comparison is measuring words rather than noise.
 *
 * BOTH PARTS OF EVERY EMAIL. An email is two documents — text/html and
 * text/plain — and the plain-text twin is the half nobody looks at, which is
 * exactly why it is the half that rots. Each one is rendered and compared
 * separately here.
 */

/** Render every mailable and printed document, with whatever view root is current. */
function printedRenderAll(\Tests\TestCase $test, array $fixture): array
{
    $order = $fixture['order'];
    $out = [];

    $refund = new Refund(['amount' => 19900, 'status' => 'succeeded']);
    $refund->order_id = $order->id;

    $mailables = [
        'order-confirmation' => new OrderConfirmation($order),
        'new-order-alert' => new NewOrderAlert($order),
        'order-shipped' => new OrderStatusChanged($order, 'shipped'),
        'order-cancelled' => new OrderStatusChanged($order, 'cancelled'),
        'order-refunded' => new OrderRefunded($order, $refund),
        'order-invoice' => new OrderInvoice($order),
        'back-in-stock' => new BackInStockAlert(
            'Back in stock: Centella Ampoule',
            "It is back.\nWe kept one for you.",
            'Centella Ampoule',
            'https://kbeautybliss.test/product/centella-ampoule/',
            'https://kbeautybliss.test/mail-preferences/stock/1',
        ),
        'cart-recovery' => new CartRecoveryReminder(
            'Your basket is waiting',
            "You left something behind.\nIt is still here.",
            [[
                'name' => 'Rice Daily Moisturizing Toner 150ml',
                'slug' => 'rice-toner',
                'quantity' => 2,
                'unit_price' => 19900,
            ]],
            'https://kbeautybliss.test/cart/',
            'https://kbeautybliss.test/mail-preferences/cart/1',
        ),
        'newsletter-confirm' => new NewsletterConfirmation(
            'https://kbeautybliss.test/newsletter/confirm/1',
            'https://kbeautybliss.test/newsletter/unsubscribe/1',
            7,
        ),
    ];

    foreach ($mailables as $name => $mailable) {
        $out[$name . ' (html)'] = (string) $mailable->render();

        $content = $mailable->content();

        if ($content->text !== null) {
            $out[$name . ' (text)'] = (string) view($content->text, array_merge(
                $mailable->buildViewData(),
                $content->with,
            ))->render();
        }
    }

    /*
     * The two account emails, rendered as views rather than through their
     * Notifications: both go out through MailMessage::view(), so the template is
     * the whole of what a reader sees and the Notification adds nothing to
     * compare.
     */
    $out['account mail: verify-email'] = (string) view('store.account.mail.verify-email', [
        'name' => 'Aisha',
        'url' => 'https://kbeautybliss.test/my-account/verify/1/abc',
        'hours' => 24,
    ])->render();

    $out['account mail: password-reset'] = (string) view('store.account.mail.password-reset', [
        'name' => 'Aisha',
        'url' => 'https://kbeautybliss.test/my-account/reset/1/abc',
        'minutes' => 60,
        'retiresOldPassword' => true,
    ])->render();

    foreach (['invoice', 'packing-slip', 'delivery-note', 'shipping-label'] as $doc) {
        $out['printed: ' . $doc] = $test->actingAs($fixture['admin'], 'admin')
            ->get('/admin-api/orders/' . $order->id . '/' . $doc)
            ->getContent();
    }

    return $out;
}

it('renders byte-identical English in every email and printed document', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-09-15 11:20:00'));

    EnglishRenderWalk::documentSettings();
    InvoiceAdminRoutes::wire(app());

    $fixture = [
        'order' => EnglishRenderWalk::documentOrder(),
        'admin' => AdminUser::create([
            'name' => 'Preview Owner',
            'email' => 'printed-preview@example.test',
            'password' => 'secret-secret',
            'role' => 'owner',
        ]),
    ];

    $current = config('view.paths');
    $base = EnglishRenderWalk::baseViews();

    try {
        EnglishRenderWalk::useViewPath($base);
        $before = printedRenderAll($this, $fixture);
        $control = printedRenderAll($this, $fixture);

        EnglishRenderWalk::useViewPath($current[0]);
        $after = printedRenderAll($this, $fixture);
    } finally {
        EnglishRenderWalk::useViewPath($current[0]);
    }

    // Twenty-two documents: nine emails, eight of them with a plain-text twin,
    // and four printed pages. A drop here means a document stopped rendering.
    expect(count($before))->toBeGreaterThan(20);

    /*
     * A document that failed to render is a short string, and two short strings
     * compare equal. The three plain-text opt-in twins really are only a few
     * hundred bytes — they are a paragraph and a link, by design — so they get a
     * lower floor and a marker of their own rather than an exemption.
     */
    $thin = array_keys(array_filter($before, fn (string $body): bool => strlen($body) < 250));
    expect($thin)->toBe([], 'These documents rendered almost nothing: ' . implode(', ', $thin));

    expect($before['back-in-stock (text)'])->toContain('Centella Ampoule')
        ->and($before['cart-recovery (text)'])->toContain('Rice Daily Moisturizing Toner')
        ->and($before['newsletter-confirm (text)'])->toContain('newsletter/confirm/1');

    // The fixture has to be the awkward order, or the discount, gift and
    // surcharge rows are never drawn and never compared.
    expect($before['order-confirmation (html)'])->toContain('KBB-10427')
        ->and($before['order-confirmation (html)'])->toContain('GLOW10')
        ->and($before['order-confirmation (text)'])->toContain('KBB-10427')
        ->and($before['printed: invoice'])->toContain('KBB-10427');

    $unstable = [];
    foreach ($before as $name => $body) {
        if (($control[$name] ?? null) !== $body) {
            $unstable[] = $name . ' — ' . EnglishRenderWalk::firstDifference($body, $control[$name] ?? '');
        }
    }
    expect($unstable)->toBe([], "These documents differ between two renders of the SAME template:\n  "
        . implode("\n  ", $unstable));

    /*
     * The approved differences, applied to the BEFORE side and counted. See
     * EnglishRenderWalk::approvedDocumentDifferences() for what each one is and
     * why it could not be kept byte-identical.
     */
    $fired = EnglishRenderWalk::applyApproved(EnglishRenderWalk::approvedDocumentDifferences(), $before);
    $wrong = array_keys(array_filter($fired, fn (array $r): bool => $r['expected'] !== $r['actual']));
    expect($wrong)->toBe([], "These approved differences no longer match what they were written for, so\n"
        . "they are excusing nothing: " . json_encode($fired));


    $changed = [];
    foreach ($before as $name => $body) {
        if (($after[$name] ?? null) !== $body) {
            $changed[] = $name . "\n      " . EnglishRenderWalk::firstDifference($body, $after[$name] ?? '');
        }
    }

    expect($changed)->toBe([], "The English output of these documents changed:\n  " . implode("\n  ", $changed));
});
