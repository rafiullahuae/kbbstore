<?php

declare(strict_types=1);

use App\Models\Coupon;
use App\Services\CartPanel;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Lane QK3: Appearance → Cart panel → Coupon hint advertises GLOW out of the box.
 *
 * The owner: "by default, coupon: glow should be there." So, ONCE, when the
 * choice has never been made (no `cartpanel_coupon_id` row at all), the coupon
 * whose code is GLOW — matched case-insensitively, as the checkout matches a
 * code (Coupon::scopeCode) — is chosen, and CartPanel::save() takes the
 * snapshot the panel reads. The line then prints the coupon's own stored code.
 *
 * A shop that has already chosen — including choosing "none" — is left alone,
 * and so is a shop with no GLOW coupon: the line stays hidden until the owner
 * picks one, and one line says so. Every visibility rule still applies after
 * this: if GLOW is expired, not started or used up, the line hides by itself.
 *
 * No down(): a rollback should not take away a choice the owner may since have
 * confirmed by saving the screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'cartpanel_coupon_id')->exists()) {
            $this->say('Cart panel coupon hint: a coupon choice is already stored; left as it is.');

            return;
        }

        $glow = Coupon::query()->code('GLOW')->orderBy('id')->first(['id', 'code']);

        if ($glow === null) {
            $this->say('Cart panel coupon hint: no coupon with code GLOW on this shop, so none is chosen and the line stays hidden.');

            return;
        }

        SettingsService::forgetMemo();
        app(CartPanel::class)->save(['coupon_id' => (string) $glow->id]);

        $this->say('Cart panel coupon hint: chose coupon '.$glow->code.' (id '.$glow->id.').');
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
