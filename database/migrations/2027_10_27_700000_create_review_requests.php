<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The review request email (Lane RV) — two small tables, both guarded.
 *
 * review_requests: ONE ROW PER ORDER, written when an order turns Completed
 * AFTER this package is applied (App\Services\Reviews\ReviewRequests::record()).
 * No row is back-filled, which is the whole of "applying the package does not
 * mail the shop's history": an order completed before today has no row, and
 * the sweep only ever reads rows. unique(order_id) is the once-per-order rule,
 * decided by the database rather than by a prior read.
 *
 * The link in the email is a random 256-bit token; only its SHA-256 is stored
 * (token_hash), so a database dump does not hand out working review links, and
 * the order id never appears in the URL.
 *
 * review_request_optouts: the addresses that pressed "Unsubscribe from review
 * request emails". Separate from outbound_optouts on purpose: that list stops
 * stock alerts and basket reminders too, and the footer promises to stop THIS
 * email, not every email the shop sends.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_requests')) {
            Schema::create('review_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('order_id')->unique();
                $t->string('email', 190)->default('');
                $t->timestamp('completed_at')->nullable();
                // scheduled | sending | sent | skipped | failed
                $t->string('status', 20)->default('scheduled');
                $t->string('reason', 40)->nullable();
                $t->char('token_hash', 64)->nullable()->unique();
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->json('products')->nullable();
                $t->timestamp('opened_at')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->unsignedSmallInteger('reviews_count')->default(0);
                $t->timestamps();

                $t->index(['status', 'completed_at'], 'review_requests_status_completed_index');
                $t->index('email', 'review_requests_email_index');
            });
        }

        if (! Schema::hasTable('review_request_optouts')) {
            Schema::create('review_request_optouts', function (Blueprint $t) {
                $t->id();
                $t->string('email', 190)->unique();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('review_request_optouts');
        Schema::dropIfExists('review_requests');
    }
};
