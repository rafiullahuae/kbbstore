<?php

declare(strict_types=1);

namespace App\Services\Payments\Reconciliation;

use Carbon\CarbonImmutable;

/**
 * One line out of a provider's books, reduced to the four facts that decide
 * whether it matches one of ours.
 *
 * WHAT IS DELIBERATELY NOT HERE: the buyer. Every one of these three APIs
 * returns a name, an email and usually a phone number on a transaction, and
 * the whole point of a reconciliation table is that it is dumped with every
 * database backup and read on a screen. Nothing personal is carried out of the
 * gateway source, so nothing personal can be written down by mistake further
 * along. The same rule RemoteGateway applies to its logs.
 *
 * MONEY IS INTEGER FILS, converted inside the gateway source and nowhere else,
 * exactly as it is on the way out. `(int) (10.10 * 100)` is 1009 on a binary
 * float; a reconciliation built on that would report a one-fil disagreement on
 * every correctly-settled order in the window, which is the failure mode that
 * makes a report worthless — not because it is wrong occasionally, but because
 * it is wrong so often that the owner stops reading it.
 */
final class RemoteTxn
{
    public const PAYMENT = 'payment';

    public const REFUND = 'refund';

    /** The provider says money moved and is keeping it. */
    public const SETTLED = 'settled';

    /** Committed but not yet money — a Tabby AUTHORIZED, an uncaptured intent. */
    public const AUTHORISED = 'authorised';

    /** Declined, expired, voided, cancelled. Money that did not move. */
    public const DEAD = 'dead';

    /**
     * @param  string        $kind        PAYMENT | REFUND
     * @param  string        $remoteId    the provider's own id for this transaction
     * @param  array<int,string> $matchKeys  every id this could be known by on our
     *                                    side, most specific first. See match().
     * @param  string|null   $reference   our `orders.order_number`, when the
     *                                    provider carries it. Null is normal:
     *                                    a Stripe charge does not carry the
     *                                    Checkout session's client_reference_id.
     * @param  int           $amountFils  integer fils, already converted
     * @param  string        $currency    upper case, three letters
     * @param  string        $state       SETTLED | AUTHORISED | DEAD
     * @param  string        $rawState    the provider's own word for it, kept
     *                                    so a finding can say "Tabby calls this
     *                                    CLOSED" rather than our paraphrase
     * @param  array<int,string> $parentKeys  for a REFUND, the ids of the payment
     *                                    it reverses. Used only to name the
     *                                    order a finding is about, and never to
     *                                    match the refund itself — see below.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $kind,
        public readonly string $remoteId,
        public readonly array $matchKeys,
        public readonly ?string $reference,
        public readonly int $amountFils,
        public readonly string $currency,
        public readonly string $state,
        public readonly string $rawState,
        public readonly ?CarbonImmutable $createdAt = null,
        public readonly array $parentKeys = [],
    ) {}

    /**
     * The payment ids this refund reverses, for finding the ORDER only.
     *
     * Kept apart from matchKeys rather than merged into it, and the separation
     * is load bearing. matchKeys is what a local row is looked up BY and what
     * gets written into `reconciliation_sightings`; a Stripe refund's
     * `payment_intent` in there would record a sighting of kind `refund` under
     * the payment's id, and the next phase — "did the provider list this refund
     * of ours?" — would then answer yes for any local refund that happened to
     * carry the intent. A refund the provider never made would be reported as
     * confirmed, which is the one direction this report must never get wrong.
     *
     * A Stripe refund carries no order reference at all, so without this a
     * perfectly real finding reads "order —" and the owner has nothing to open.
     *
     * @return array<int, string>
     */
    public function parents(): array
    {
        $keys = array_map('trim', $this->parentKeys);

        return array_values(array_unique(array_filter($keys, fn ($k) => $k !== '')));
    }

    /**
     * Is this money the provider is actually holding for us?
     *
     * AUTHORISED is not. A Tabby authorisation that nobody captured is money
     * the provider will hand back to the shopper, and reporting it as "the
     * provider has money we have no record of" would send the owner looking
     * for funds that are not there. It gets its own, quieter finding instead —
     * see Reconciler::UNCAPTURED.
     */
    public function isMoney(): bool
    {
        return $this->state === self::SETTLED;
    }

    /**
     * The keys, de-duplicated and trimmed, that a local `provider_ref` may
     * equal.
     *
     * More than one because the three providers hand us different ids at
     * different moments and our own columns are not consistent about which
     * they kept. Stripe is the clearest case: `start()` writes the Checkout
     * session id (`cs_…`) to `orders.transaction_id`, the webhook replaces it
     * with the PaymentIntent (`pi_…`), and `payments.provider_ref` holds the
     * PaymentIntent — so a charge has to be matchable by its intent, by its
     * own `ch_…` id, and by the session that made it. Matching on one of them
     * and calling the rest missing is how a reconciliation invents a crisis.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        $keys = array_map('trim', array_merge([$this->remoteId], $this->matchKeys));

        return array_values(array_unique(array_filter($keys, fn ($k) => $k !== '')));
    }

    /** The one key a sighting row is stored under. Stable across re-fetches. */
    public function primaryKey(): string
    {
        return trim($this->remoteId);
    }
}
