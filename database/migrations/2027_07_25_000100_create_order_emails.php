<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the shop has already emailed about one order, once (Lane RL).
 *
 * ONE ROW PER (order, kind), AND THE UNIQUE KEY IS THE WHOLE POINT. A send that
 * must happen at most once — the receipt when a payment confirms, the "complete
 * your order" reminder at 30 minutes and again at 24 hours — CLAIMS its row
 * with an insert-or-ignore before it sends. A Stripe webhook and the browser's
 * own confirmation can both arrive for one payment; two page requests can both
 * run the reminder sweep; the scheduler and the heartbeat can overlap. Whoever
 * inserts the row sends, everybody else finds it there and does nothing, and
 * that is decided by the database rather than by whoever read first.
 *
 * kinds: `confirmation`, `reminder_1`, `reminder_2`, `status_completed`,
 * `feedback` (closed list in App\Services\Mail\OrderEmailLog).
 *
 * THE BACKFILL. Before this package every order placed through the checkout
 * was receipted the moment it was placed, paid or not (audit B1). An order
 * still `pending` or `failed` today therefore already holds a receipt, and when
 * its payment confirms after this package lands it must not get a second one.
 * So those rows are written as already sent. Orders in any other status cannot
 * reach the receipt path again (it only fires on the way OUT of pending/failed)
 * and need no row. Idempotent: run twice, it inserts nothing the second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_emails')) {
            Schema::create('order_emails', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('order_id');
                $t->string('kind', 40);
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
                $t->unique(['order_id', 'kind']);
            });
        }

        $now = now();

        DB::table('orders')
            ->whereIn('status', ['pending', 'failed'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('order_emails')
                    ->whereColumn('order_emails.order_id', 'orders.id')
                    ->where('order_emails.kind', 'confirmation');
            })
            ->orderBy('id')
            ->select(['id', 'created_at'])
            ->chunk(500, function ($rows) use ($now) {
                DB::table('order_emails')->insertOrIgnore($rows->map(fn ($r) => [
                    'order_id' => $r->id,
                    'kind' => 'confirmation',
                    'sent_at' => $r->created_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_emails');
    }
};
