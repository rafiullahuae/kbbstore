<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Appearance → Product page: the top of the page, per device.     (Lane PV)
 *
 * Two jobs, and both exist so that a value the owner had ALREADY SAVED on
 * that screen stays where he put it. On a shop where nobody has moved a
 * slider there are no rows to read and this writes nothing.
 *
 *  1. THE SHARED CONTROLS WERE SPLIT INTO A PHONE AND A LAPTOP HALF. The
 *     existing key keeps its job on the phone and a new `_d` / `_m` key takes
 *     the other device, so a saved `head_gap` of 4 must also become the
 *     laptop's 4 -- otherwise the first save of ANY other control would emit
 *     the new key at its default and move his laptop page back.
 *
 *       head_gap -> head_gap_d        rate_gap -> rate_gap_d
 *       rule_pad -> rule_pad_d        rule_gap -> desc_gap_m, desc_gap_d,
 *                                                 opt_gap_m,  opt_gap_d
 *
 *     A destination row that already exists is never overwritten.
 *
 *  2. "Photo → brand · phone" (buybox_gap) NOW MEANS THE WHOLE GAP. It was the
 *     padding under the grid's 26px row gap, so 22 drew 48; the row gap is now
 *     0 on a phone and folded into this number. A saved value gains the 26 it
 *     used to get from the grid, so the page does not move.
 *
 * It also clears the compiled views, because the product page and the gallery
 * partial changed (the CLAUDE.md clear_caches convention).
 */
return new class extends Migration
{
    private const PREFIX = 'pdplay_';

    private const COPIES = [
        'head_gap' => ['head_gap_d'],
        'rate_gap' => ['rate_gap_d'],
        'rule_pad' => ['rule_pad_d'],
        'rule_gap' => ['desc_gap_m', 'desc_gap_d', 'opt_gap_m', 'opt_gap_d'],
    ];

    public function up(): void
    {
        $copied = 0;

        foreach (self::COPIES as $from => $targets) {
            $row = DB::table('settings')->where('key', self::PREFIX.$from)->first();

            if ($row === null) {
                continue;
            }

            foreach ($targets as $to) {
                if (DB::table('settings')->where('key', self::PREFIX.$to)->exists()) {
                    continue;
                }

                DB::table('settings')->insert([
                    'key' => self::PREFIX.$to,
                    'value' => $row->value,
                    'autoload' => $row->autoload ?? true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $copied++;
            }
        }

        $settings = app(SettingsService::class);
        $settings->flush();

        $buybox = DB::table('settings')->where('key', self::PREFIX.'buybox_gap')->exists()
            ? $settings->get(self::PREFIX.'buybox_gap')
            : null;

        if ($buybox !== null && is_numeric($buybox)) {
            $settings->set(self::PREFIX.'buybox_gap', min(90, max(0, (int) $buybox + 26)));
        }

        $settings->flush();

        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Product page: {$copied} saved spacing value(s) carried to their laptop/phone halves"
                .($buybox !== null ? ', and the saved photo-to-brand gap widened by the 26px it used to borrow' : '')
                .". Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
