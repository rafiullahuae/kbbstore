<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Store -> Payments -> Stripe -> Payment log. (Lane SR.)
 *
 * A bounded troubleshooting log: gateway events, API errors and webhook
 * outcomes. App\Services\Payments\PaymentLog is the only writer, it keeps the
 * newest PaymentLog::KEEP rows and deletes the rest on every write, and it
 * never stores a card number, a key, a signing secret or a client secret —
 * see that class for how each is kept out.
 *
 * Its own table rather than `payment_events`: that one is the per-order money
 * ledger, read by refunds and reconciliation, and a row that says "Stripe
 * answered 401 to a GET" has no order and must never be counted as money.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_logs')) {
            return;
        }

        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);
            $table->string('mode', 8)->nullable();
            $table->string('level', 8);
            $table->string('event', 60);
            $table->string('message', 255);
            $table->text('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['gateway', 'event', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
    }
};
