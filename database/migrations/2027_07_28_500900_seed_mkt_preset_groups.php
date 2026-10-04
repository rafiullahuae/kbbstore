<?php

declare(strict_types=1);

use App\Services\Marketing\TemplateLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The preset customer groups (Lane MK, docs/EMAILS-PLAN.md §3): Never
 * ordered, One order only, Repeat buyers, VIP (AED 1,000+, editable), Lapsed
 * 90 days, All customers and All confirmed subscribers. Seeded by key;
 * idempotent; a group the owner has edited is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mkt_segments')) {
            return;
        }

        $have = DB::table('mkt_segments')->whereNotNull('key')->pluck('key')->all();
        $now = now();

        foreach (TemplateLibrary::groups() as $key => $g) {
            if (in_array($key, $have, true)) {
                continue;
            }

            DB::table('mkt_segments')->insert([
                'key' => $key,
                'name' => $g['name'],
                'audience' => $g['audience'],
                'match' => $g['match'],
                'rules' => json_encode($g['rules']),
                'preset' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mkt_segments')) {
            DB::table('mkt_segments')->where('preset', true)->whereIn('key', array_keys(TemplateLibrary::groups()))->delete();
        }
    }
};
