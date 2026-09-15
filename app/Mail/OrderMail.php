<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Services\Mail\OrderEmailPresenter;
use Illuminate\Mail\Mailable;

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
    /** @var array<string, mixed> */
    public array $order;

    public function __construct(Order $order)
    {
        $this->order = (new OrderEmailPresenter)->present($order);
    }

    /** The order number, for tests and for subject lines. */
    public function orderNumber(): string
    {
        return (string) $this->order['number'];
    }
}
