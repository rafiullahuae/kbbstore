<?php

declare(strict_types=1);

use App\Services\CartPage;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * Lane QK12, chunk B (cart layout). The owner, 9 October, on phone
 * screenshots: "on cart floating Proceed to checkout row also give space below
 * around 50px and give controls on backend".
 *
 * Appearance -> Cart page -> Docked rows -> "Space under the checkout row"
 * moved its default from 0 to 50, but a moved default does not reach a shop
 * that saved that screen, so 50 is STORED here over whatever was saved -- a
 * newer instruction than any value saved before it. The page keeps the same
 * room at its end (--cpg-bars), so the last card is not hidden behind the bar.
 *
 * The other two controls of this chunk are new keys, so their defaults --
 * Summary & trust -> "Phone: Order total straight after Your Bag" ON, and
 * Appearance -> Cart panel -> Mobile -> "Space below the buttons" 50 -- apply
 * without a stored row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        SettingsService::forgetMemo();
        app(CartPage::class)->save(['bar_pad' => 50]);
        SettingsService::forgetMemo();
    }

    public function down(): void
    {
        //
    }
};
