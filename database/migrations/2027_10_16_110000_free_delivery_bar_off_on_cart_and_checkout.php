<?php

declare(strict_types=1);

use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane QK7. The owner, twice: "this delivery bar function, complete OFF" on
 * the cart and checkout pages, then, with two screenshots of the live
 * checkout: "the unlocked / free delivery green bar still showing in summary
 * section and also above the place order button on checkout, turn off from
 * the cart and checkout page. please."
 *
 * Appearance → Checkout page → Delivery labels → "Free-delivery bar on the
 * cart and checkout pages" is new and its default is already off. It is
 * WRITTEN off here as well, so the shop is off the moment the package is
 * applied whatever the schema default is later moved to, and so the screen
 * reads a stored choice rather than an untouched default. A value already
 * stored (the package applied twice, or the owner switched it back on) is
 * left exactly as it is.
 *
 * The cart panel's own bar is not this switch and is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if (DB::table('settings')->where('key', 'checkoutpage_fs_bar_on')->exists()) {
            $this->say('Free-delivery bar on the cart and checkout pages: already set; left as it is.');

            return;
        }

        SettingsService::forgetMemo();
        app(CheckoutPage::class)->save(['fs_bar_on' => false]);

        $this->say('Free-delivery bar on the cart and checkout pages: off, as asked.');
    }

    public function down(): void
    {
        //
    }

    /** One line, and only on a console — never into an updater's HTTP response. */
    private function say(string $line): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo $line."\n";
        }
    }
};
