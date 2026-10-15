<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Store -> Customers -> (a customer) -> Edit email / phone (Lane CE).
 *
 * One row per correction or re-sent confirmation: who, when, old -> new, and
 * which orders followed. The OLD values are kept so a mistake can be put back
 * from the same dialog.
 *
 * Literals and framework calls only: the updater runs this in a request where
 * opcache may still be serving the previous build's classes (2.60.512 rolled
 * back on live for exactly that), so nothing this lane adds is referenced here.
 * CustomerContactEditTest pins it with token_get_all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_contact_changes')) {
            return;
        }

        Schema::create('customer_contact_changes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->index();
            // 'edit' or 'resend'.
            $t->string('kind', 16)->default('edit');
            $t->string('old_email', 191)->nullable();
            $t->string('new_email', 191)->nullable();
            $t->string('old_phone', 64)->nullable();
            $t->string('new_phone', 64)->nullable();
            $t->unsignedInteger('orders_updated')->default(0);
            $t->text('order_ids')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 191)->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contact_changes');
    }
};
