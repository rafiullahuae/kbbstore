<?php

declare(strict_types=1);

use App\Services\SettingsService;
use App\Services\SlimFooter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Lane QK8. The owner: "remove the Returns information from the checkout
 * footer, as we don't offer returns, keep the Privacy Policy there."
 *
 * The slim footer under the cart and the checkout (Appearance -> Footer ->
 * Second link / Second link goes to) shipped "Returns Information" ->
 * /refund_returns/, and 2027_09_06_100000 WROTE those values to any shop that
 * had saved the older pair. Its new default is "Privacy policy" ->
 * /privacy-policy/, the page the main footer links to.
 *
 * A saved row moves only while it still holds the old shipped value, so a
 * link the owner typed himself is left alone. Rows never saved follow the new
 * default with no write at all. The main site footer is not this setting.
 */
return new class extends Migration
{
    private const MOVES = [
        'l2_text' => ['Returns Information', 'Privacy policy'],
        'l2_url'  => ['/refund_returns/', '/privacy-policy/'],
    ];

    public function up(): void
    {
        $settings = app(SettingsService::class);
        $moved = 0;

        foreach (self::MOVES as $key => [$old, $new]) {
            $full = SlimFooter::PREFIX.$key;

            if (DB::table('settings')->where('key', $full)->value('value') === $old) {
                $settings->set($full, $new);
                $moved++;
            }
        }

        if ($moved > 0) {
            $settings->flush();
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Cart & checkout footer: Returns Information -> Privacy policy ({$moved} saved setting(s) moved).\n";
        }
    }

    public function down(): void {}
};
