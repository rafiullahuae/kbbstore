<?php

declare(strict_types=1);

namespace App\Services\Payments\Reconciliation;

/**
 * A gateway whose books can be READ BACK.
 *
 * The fourth capability, alongside PaymentGateway (take money),
 * SettlesPayments (capture and refund it) and HandlesWebhooks (be told about
 * it). Every one of those is a statement we make or a statement the provider
 * pushes at us. This is the only one that asks the provider a question we did
 * not start, which is what makes it the only one that can find a transaction
 * neither of the others ever told us about.
 *
 * Cash on delivery does not implement this and never will: there is no
 * provider holding a Tabby-shaped set of books for an order paid to a courier
 * in cash. See CashOnDeliveryPosition for what is available instead and why it
 * is a different report rather than a fourth column in this one.
 *
 * THREE RULES EVERY IMPLEMENTATION FOLLOWS:
 *
 *   1. **Never throw.** A provider having a bad morning must not 500 the admin
 *      panel, and — more importantly — must not be indistinguishable from "the
 *      provider has nothing". Return RemotePage::failed(); the Reconciler
 *      knows what to do with it and what NOT to conclude from it.
 *   2. **Integer fils out.** Conversion from whatever decimal shape the
 *      provider uses happens here, through RemoteGateway::toFils(), and
 *      nowhere else.
 *   3. **Nothing personal out.** RemoteTxn carries ids, an amount, a currency
 *      and a state. The buyer block every one of these endpoints returns is
 *      dropped at this boundary, not filtered further downstream.
 *
 * ON PAGINATION. The cursor is an opaque string this gateway invented and only
 * this gateway reads — a Stripe `starting_after` id, a numeric offset, a page
 * number. It is checkpointed verbatim and handed back unchanged, so an
 * interrupted run continues from the provider's own idea of where it was
 * rather than from a row count that would drift.
 */
interface ListsTransactions
{
    /**
     * Money the provider took, one page at a time.
     *
     * @param  string|null  $cursor  null for the first page; otherwise exactly
     *                               what the previous RemotePage returned
     * @param  int  $limit  a hint. A provider with a lower cap wins.
     */
    public function listRemotePayments(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage;

    /**
     * Money the provider gave back, one page at a time.
     */
    public function listRemoteRefunds(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage;

    /**
     * Where these lists come from, in one sentence, for the screen.
     *
     * The owner is being shown a report whose worth depends entirely on
     * whether it read the right books — sandbox versus live is the commonest
     * way for it to be confidently wrong — so the screen says which endpoint
     * on which host answered. No credential, ever: the host, and the path.
     */
    public function remoteSourceLabel(): string;
}
