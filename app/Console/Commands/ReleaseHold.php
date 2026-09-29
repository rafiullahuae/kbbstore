<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Payments\PaymentVoider;
use Illuminate\Console\Command;

/**
 * Release the authorisation an order is holding, from a shell.
 *
 *     php artisan payments:release-hold 1042 --dry-run   # what would happen
 *     php artisan payments:release-hold 1042             # asks, then releases
 *     php artisan payments:release-hold KBB-1042
 *
 * ── WHY THIS EXISTS WHEN THERE IS A BUTTON ─────────────────────────────────
 *
 * Because the button is the thing most likely to be broken on the day this is
 * needed, and this one is newer than everything around it. The release panel is
 * appended to the order screen by a partial; a package applied without its
 * clear_caches migration leaves the compiled route table not knowing
 * `/admin-api/orders/{id}/void` at all, and then the panel renders perfectly
 * and answers 404 — which is the exact failure routes/payments-void.php's own
 * header says has shipped twice on this project already.
 *
 * The cost of having no second way in is not hypothetical either: this endpoint
 * existed, fully tested and capability-mapped, for a whole round with NO caller
 * of any kind, and every cancelled Tamara order in that time left the buyer's
 * instalment plan live for up to 180 days. Cloudways gives this project a
 * shell and CLAUDE.md records that using it is the normal case, not the
 * fallback. `payments:tamara-webhook` made this argument first; this makes it
 * for the one act in the family that touches a buyer's credit.
 *
 * ── IT IS THE SAME CODE THE PANEL RUNS ─────────────────────────────────────
 *
 * PaymentVoider::void(), called directly — not a second implementation. Two
 * copies of a provider call drift, and the direction they drift in is a command
 * that reports a release the screen would have refused. The idempotency mutex,
 * the ledger row, the order note and the release-on-failure are all that one
 * class's, so a release from here is indistinguishable from a release from the
 * console except in the name on the note.
 *
 * ── THERE IS NO --force, AND THAT IS THE DESIGN ────────────────────────────
 *
 * `payments:tamara-webhook --remove --force` has one, because a webhook can be
 * registered again in the next breath. A released authorisation cannot: nothing
 * recreates it, the shopper would have to go through the provider's checkout
 * again, and they have gone. So a run with nobody to ask is REFUSED rather than
 * defaulted.
 *
 * Symfony's confirm() already returns its default with --no-interaction, and
 * the default here would be false — but relying on that is invisible, and the
 * next reader who changes a default turns every scripted run of this command
 * into a release. The absence of a person is therefore checked out loud, first,
 * and there is no flag that answers for one.
 *
 * ── EXIT CODES ─────────────────────────────────────────────────────────────
 *
 * 0 when there is no hold on that order afterwards — a release that worked, or
 * an order whose hold was already released before this ran. 1 for everything
 * else, including a refusal, because the caller asked for a release and none
 * happened. --dry-run exits 0 whatever it finds: it is a report.
 *
 * NOTHING IS PRINTED THAT IS NOT ALREADY ON THE ORDER SCREEN. No credential, no
 * API body, no buyer field beyond the order number the operator typed.
 */
class ReleaseHold extends Command
{
    protected $signature = 'payments:release-hold
                            {order : The order id, or the order number}
                            {--dry-run : Say what would happen and change nothing}';

    protected $description = 'Release the uncaptured authorisation a cancelled order is still holding';

    public function handle(PaymentVoider $voider): int
    {
        $order = $this->resolve((string) $this->argument('order'));

        if ($order === null) {
            return self::FAILURE;
        }

        $status = $voider->status($order);
        $facts = $voider->confirmation($order);
        $money = $facts['currency'] . ' ' . $facts['amount'];

        $this->line('Order ' . $facts['order_number'] . '  (id ' . $facts['order_id'] . ')');
        $this->line('  status      ' . (string) $order->status);
        $this->line('  holder      ' . ($facts['holder'] ?? 'none — this payment method holds no authorisation'));
        $this->line('  amount      ' . $money);
        $this->line('  released    ' . ($status['voided']
            ? 'yes' . ($status['voided_at'] !== null ? ', on ' . $status['voided_at'] : '')
                . ($status['void_ref'] !== null ? ', reference ' . $status['void_ref'] : '')
            : 'no'));
        $this->line('  releasable  ' . ($status['voidable'] ? 'yes' : 'no — ' . (string) $status['why_not']));

        /*
         * Already released is the state the caller asked for, so it is a
         * success and the provider is not called a second time. PaymentVoider
         * would answer `already_voided` anyway; not calling at all is cheaper
         * and says the same thing.
         */
        if ($status['voided']) {
            $this->info('Nothing to do: this order\'s authorisation has already been released.');

            return self::SUCCESS;
        }

        if ($facts['consequence'] !== null && $status['voidable']) {
            $this->newLine();
            $this->warn($facts['consequence']);
        }

        if ((bool) $this->option('dry-run')) {
            $this->newLine();
            $this->line($status['voidable']
                ? 'Dry run. Nothing was released. Run it again without --dry-run to release ' . $money . '.'
                : 'Dry run. Nothing was released, and nothing could be.');

            return self::SUCCESS;
        }

        if (! $status['voidable']) {
            $this->error('Nothing was released: ' . (string) $status['why_not']);

            return self::FAILURE;
        }

        /*
         * A NON-INTERACTIVE RUN IS NOT A YES. With --no-interaction, or no TTY
         * at all — a cron line, a deploy script, a CI step — there is nobody to
         * ask, and this is not a question to answer by default in either
         * direction. Refused out loud, and there is deliberately no flag that
         * says "I meant it": see the header.
         */
        if (! $this->input->isInteractive()) {
            $this->error(
                'Refusing to release a hold in a non-interactive run. Nothing was changed. '
                . 'This is irreversible and there is no --force: run it from a terminal, '
                . 'or use the button on Orders → (this order) → Items → Release the hold.'
            );

            return self::FAILURE;
        }

        /*
         * Typed, not y/N. The order number is on screen three lines above, so
         * typing it costs a moment and proves the operator is releasing the
         * order they think they are — which is the one mistake here that cannot
         * be undone afterwards.
         */
        $typed = (string) $this->ask('Type the order number to release ' . $money . ', or press enter to leave it alone');

        if (trim($typed) !== $facts['order_number']) {
            $this->line('Left alone. Nothing was released.');

            return self::FAILURE;
        }

        $result = $voider->void($order, 'shell (php artisan payments:release-hold)');

        $order->refresh();

        if (! $result->ok) {
            $this->error($result->message ?: 'The hold was not released.');
            $this->line('  code        ' . $result->code);
            $this->line('The authorisation is still live. The failure is on the order\'s own notes.');

            return self::FAILURE;
        }

        $this->info($result->message ?: 'The authorisation has been released.');
        $this->line('  code        ' . $result->code);

        if ($result->reference !== null) {
            $this->line('  reference   ' . $result->reference);
        }

        return self::SUCCESS;
    }

    /**
     * The order this argument names, or null with the reason already printed.
     *
     * WHY BOTH, AND WHY AMBIGUITY IS REFUSED RATHER THAN RESOLVED. The id is
     * what the endpoint takes and what an operator reading a log has; the order
     * number is what is printed on the invoice and what the owner will type.
     * Imported WooCommerce orders carry NUMERIC order numbers, so "1042" can
     * legitimately be both — and picking one would mean this command releasing
     * the hold on an order nobody named. Two matches is a question, not a
     * guess.
     *
     * withTrashed(), for the reason PaymentVoidController gives: a trashed order
     * is the order most likely to be sitting on a hold nobody meant to leave
     * open, and "no such order" would be the wrong answer to give about it.
     */
    private function resolve(string $argument): ?Order
    {
        $argument = trim($argument);

        $byNumber = Order::withTrashed()->where('order_number', $argument)->first();

        $byId = ctype_digit($argument)
            ? Order::withTrashed()->whereKey((int) $argument)->first()
            : null;

        if ($byId !== null && $byNumber !== null && $byId->getKey() !== $byNumber->getKey()) {
            $this->error(sprintf(
                '"%s" is both the id of order %s and the number of order #%d. Nothing was released. '
                . 'Say which one with the other\'s identifier.',
                $argument,
                (string) $byId->order_number,
                (int) $byNumber->getKey(),
            ));

            return null;
        }

        $order = $byId ?? $byNumber;

        if ($order === null) {
            $this->error('No order with that id or order number. Nothing was released.');
        }

        return $order;
    }
}
