<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Services\Mail\EmailBranding;
use App\Services\Mail\OrderEmailPresenter;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;

/**
 * What the four order emails have in common.
 *
 * NONE OF THEM IMPLEMENTS ShouldQueue, AND THAT IS DELIBERATE. There is no queue
 * worker on this host — it is shared hosting with no shell access, which is the
 * same reason `abandoned_cart` and `back_in_stock` are still marked `todo` in the
 * module registry. A queued Mailable here would be accepted, written to the jobs
 * table, and never run: the exact shape of failure CLAUDE.md records twice, a
 * feature whose signup half works and whose sending half does not. These send
 * inline, and App\Services\Mail\OrderMailer is what makes inline sending safe by
 * never letting a failure reach the caller.
 *
 * EVERY ONE CARRIES A TEXT PART. Mail clients that render no HTML are not a
 * hypothetical for a receipt — they are watch faces, notification previews,
 * accessibility tools and the spam filters that score a message lower for having
 * no alternative. The text part is not a stripped copy of the HTML; it is its own
 * template rendering the same presenter array, so the two cannot drift.
 *
 * THE ORDER IS PASSED, THE PRESENTER IS CALLED ONCE. Subclasses never read
 * columns off the model in a template. See OrderEmailPresenter for why the line
 * items are the snapshot and why money is rendered at full precision.
 */
abstract class OrderMail extends Mailable
{
    /**
     * Does this message go to a shopper, or to the person who packs the boxes?
     *
     * It decides two things and only two: whether the support block is printed
     * (WhatsApp, email, Instagram — the store telling a customer how to reach
     * it, which the store does not need to tell itself) and whether the email is
     * signed off. NewOrderAlert is the one that says no.
     */
    protected const CUSTOMER_FACING = true;

    /** @var array<string, mixed> */
    public array $order;

    /**
     * The store's own face: logo, colours, support channels and signature.
     *
     * A public property, so it reaches the Blade view the same way $order does
     * and appears in buildViewData() for the text part — the previews render
     * the text half through exactly that array, and a value the previews cannot
     * see is a value nobody reviews.
     *
     * @var array<string, mixed>
     */
    public array $brand;

    public function __construct(Order $order)
    {
        $this->order = (new OrderEmailPresenter)->present($order);

        /*
         * Resolved from the container rather than newed up: EmailBranding reads
         * SettingsService, MailSettings and HeaderSettings, all of which are
         * bound scoped so that one request shares one decrypted credential row
         * and one settings snapshot. Newing it here would build a second set.
         *
         * Guarded, because this runs inside OrderMailer's try — but only just:
         * the whole point of that guard is that a receipt which cannot be built
         * costs the checkout nothing. Branding is decoration, and decoration
         * that throws must not be the reason a customer hears silence about an
         * order they paid for. An empty array renders as an email with no logo
         * and no support block, which is exactly what shipped before this
         * release and is a long way from nothing.
         */
        try {
            $this->brand = app(EmailBranding::class)->present(static::CUSTOMER_FACING);
        } catch (\Throwable $e) {
            /*
             * Logged, not just swallowed. A silent catch here is how a broken
             * support block becomes an email that goes out for months looking
             * slightly wrong with nobody able to say why -- during this lane's
             * own work a bad regex delimiter did exactly that, and only a
             * preview assertion caught it. One line, no body, no recipient.
             */
            Log::warning('order email branding failed; falling back to the plain header', [
                'mailable' => class_basename(static::class),
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);

            $this->brand = [
                'customerFacing' => static::CUSTOMER_FACING,
                'storeName' => (string) config('app.name', 'K Beauty Bliss'),
                'wordmark' => [(string) config('app.name', 'K Beauty Bliss'), ''],
                'logoUrl' => null,
                'support' => [],
                'hasSupport' => false,
                'signature' => [],
                'colours' => EmailBranding::PALETTE,
            ];
        }
    }

    /** The order number, for tests and for subject lines. */
    public function orderNumber(): string
    {
        return (string) $this->order['number'];
    }
}
