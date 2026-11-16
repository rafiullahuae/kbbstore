<?php

declare(strict_types=1);

use App\Services\GiftOffers\GiftOffers;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * The owner, 16 Nov 2026, with a screenshot of the exit popup (words 3), the "full-size" in
 * its text and its "FULL SIZE" chip circled: "i need to change the wording ... keep these
 * wordings: Grab a Bonus gift 🎁 'product_name' ( Qnty or Qntys x pairs ) is yours, free
 * with this bag."
 *
 * A saved copy of the OLD default wins over the new one, so this moves words 3's text only
 * where the saved value is still exactly the old default; anything typed in the admin stays.
 * The popup's chip is a new text (pop_chip_full, "-" = none), so it needs no move. Then the
 * compiled views and opcache are cleared like every clear_caches_*.
 */
return new class extends Migration
{
    private const MOVES = [
        'w3_p_en' => ['A full-size {p} is yours, free with this bag.', 'Grab a Bonus gift 🎁 {p} ({q}) is yours, free with this bag.'],
        'w3_p_ar' => ['{p} بالحجم الكامل لكِ، مجاناً مع هذه الحقيبة.', 'احصلي على هدية إضافية 🎁 {p} ({q}) لكِ، مجاناً مع هذه الحقيبة.'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            try {
                $settings = app(SettingsService::class);
                $saved = $settings->get(GiftOffers::KEY);

                if (is_array($saved) && is_array($saved['texts'] ?? null)) {
                    $changed = false;

                    foreach (self::MOVES as $k => [$old, $new]) {
                        if (($saved['texts'][$k] ?? null) === $old) {
                            $saved['texts'][$k] = $new;
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        $settings->set(GiftOffers::KEY, $saved);
                    }
                }
            } catch (\Throwable) {
                // A wording move is never worth failing the update.
            }
        }

        foreach ([storage_path('framework/views/*.php'), base_path('bootstrap/cache/routes-*.php')] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    public function down(): void {}
};
