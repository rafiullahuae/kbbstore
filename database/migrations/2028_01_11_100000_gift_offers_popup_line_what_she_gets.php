<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * 2.60.648 -- the owner, 6 November 2026, on the gift-offer popup: "remove
 * only shoper who leaves... this line. just focus on what she's getting."
 *
 * The default line (GiftOffers::TEXTS['w3_p']) changed in the code. The admin's
 * Save writes every text into the gift_offers setting, so a saved copy of the
 * OLD line would still win over the new default. This swaps exactly that old
 * line, English and Arabic, for the new one -- and nothing else. A line the
 * owner wrote himself is not the old line and is left alone.
 *
 * Literals only. Running it twice changes nothing the second time.
 */
return new class extends Migration
{
    private const SWAP = [
        'w3_p_en' => [
            'Only shoppers who almost leave get this one: a full-size {p}, free with this bag.',
            'A full-size {p} is yours, free with this bag.',
        ],
        'w3_p_ar' => [
            'هذه الهدية فقط لمن كادت تغادر: {p} بالحجم الكامل، مجاناً مع هذه الحقيبة.',
            '{p} بالحجم الكامل لكِ، مجاناً مع هذه الحقيبة.',
        ],
    ];

    public function up(): void
    {
        $this->swap(0, 1);
    }

    public function down(): void
    {
        $this->swap(1, 0);
    }

    private function swap(int $from, int $to): void
    {
        if (! DB::getSchemaBuilder()->hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')->where('key', 'gift_offers')->first();
        $cfg = $row !== null ? json_decode((string) $row->value, true) : null;
        if (! is_array($cfg) || ! is_array($cfg['texts'] ?? null)) {
            return;
        }

        $changed = false;
        foreach (self::SWAP as $key => $pair) {
            if (($cfg['texts'][$key] ?? null) === $pair[$from]) {
                $cfg['texts'][$key] = $pair[$to];
                $changed = true;
            }
        }

        if ($changed) {
            DB::table('settings')->where('key', 'gift_offers')->update([
                'value' => json_encode($cfg, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }

        // The settings map is cached, and the popup's and the cart row's views
        // changed: clear both so the next page shows them.
        Cache::forget('kbb.settings');
        Cache::forget('kbb.settings.map');
        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $f) {
            @unlink($f);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
};
