<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane EH: the Arabic draft for checkout's "Did you mean :email?" -- the line
 * under the email field when Emails -> Sending & delivery -> At checkout is on.
 * A DRAFT: /ar shows it once the owner approves it (Translation -> Strings).
 *
 * LITERALS ONLY. The updater can run a migration while the previous release's
 * classes are still in opcache -- 2.60.512 broke live exactly so -- so this
 * file names no class of the shop: field => [English, Arabic], in the shape of
 * 2027_11_02_100000_seed_lane_ar2_wording_arabic_drafts.php.
 * CheckoutEmailTypoHintTest pins that it names no App\ class and that its two
 * literals equal InterfaceStrings and ArabicInterfaceDrafts. A row that already
 * exists, in any state, is left alone.
 */
return new class extends Migration
{
    private const ROWS = [
        'store.checkout.email_fix' => ['Did you mean :email?', 'هل تقصدين :email؟'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $existing = array_flip(DB::table('translations')
            ->where('locale', 'ar')
            ->where('group', 'ui')
            ->where('item_id', 0)
            ->whereIn('field', array_keys(self::ROWS))
            ->pluck('field')
            ->all());

        $now = now();
        $insert = [];

        foreach (self::ROWS as $field => [$english, $arabic]) {
            if (isset($existing[$field])) {
                continue;
            }

            $insert[] = [
                'locale' => 'ar',
                'group' => 'ui',
                'item_id' => 0,
                'field' => $field,
                'value' => $arabic,
                'status' => 'draft',
                'source' => 'machine',
                'source_hash' => sha1($english),
                'reviewed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($insert !== []) {
            DB::table('translations')->insert($insert);
        }

        try {
            Cache::forget('kbb.translations.en');
            Cache::forget('kbb.translations.ar');
        } catch (\Throwable) {
            // Drafts are not served, so a stale map cannot even be wrong.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        // Only a row this migration could have written: a machine draft.
        DB::table('translations')
            ->where('locale', 'ar')
            ->where('group', 'ui')
            ->where('item_id', 0)
            ->whereIn('field', array_keys(self::ROWS))
            ->where('status', 'draft')
            ->where('source', 'machine')
            ->delete();
    }
};
