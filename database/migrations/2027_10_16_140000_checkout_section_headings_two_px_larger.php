<?php

declare(strict_types=1);

use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The owner, 9 October: "the headings of the sections like shipping details
 * etc, make 2 font size increase. for all." The four numbered bars (Contact,
 * Shipping Details, Delivery, Payment) are 13px; 115% of the role's size is
 * ~15px, on the phone and on a computer. Appearance -> Checkout page ->
 * Mobile / Desktop · Text sizes -> Section heading size. Only where the owner
 * has not set it himself (absent, or still the shipped 100).
 */
return new class extends Migration
{
    private const KEYS = ['d_t_h2', 'm_t_h2'];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        SettingsService::forgetMemo();

        $save = [];
        foreach (self::KEYS as $key) {
            $stored = DB::table('settings')->where('key', 'checkoutpage_'.$key)->value('value');
            if ($stored === null || (string) $stored === '100') {
                $save[$key] = 115;
            }
        }

        if ($save !== []) {
            app(CheckoutPage::class)->save($save);
        }

        SettingsService::forgetMemo();
    }

    public function down(): void
    {
        //
    }
};
