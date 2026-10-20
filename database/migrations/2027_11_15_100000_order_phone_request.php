<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.562 -- "Ask for UAE number" beside the phone on the order screen.
 *
 * phone_request_sent_at: when the email last went. The owner: "the system will
 * also show that the missing contact number email is already sent, with date
 * and time ... so i can be sure by not sending the emails twice." It is also
 * the claim that stops two presses (two tabs, a double click) sending two
 * emails: OrderPhoneRequestController updates it conditionally BEFORE sending.
 *
 * phone_updated_at: when the customer added a UAE number on the signed page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'phone_request_sent_at')) {
                $table->timestamp('phone_request_sent_at')->nullable();
            }

            if (! Schema::hasColumn('orders', 'phone_updated_at')) {
                $table->timestamp('phone_updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            foreach (['phone_request_sent_at', 'phone_updated_at'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
