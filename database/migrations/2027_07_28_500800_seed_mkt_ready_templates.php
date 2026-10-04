<?php

declare(strict_types=1);

use App\Services\Marketing\TemplateLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The twelve READY marketing templates (Lane MK) — Marketing Emails →
 * Templates. Read-only presets (preset = 1): Use makes a campaign from a copy,
 * Duplicate makes an editable copy under "My templates". Seeded by key, so a
 * second run inserts nothing and never touches a row that is already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mkt_templates')) {
            return;
        }

        $have = DB::table('mkt_templates')->whereNotNull('key')->pluck('key')->all();
        $now = now();
        $sort = 0;

        foreach (TemplateLibrary::templates() as $key => $t) {
            $sort += 10;

            if (in_array($key, $have, true)) {
                continue;
            }

            DB::table('mkt_templates')->insert([
                'key' => $key,
                'name' => $t['name'],
                'category' => $t['category'],
                'description' => $t['description'],
                'subject' => $t['subject'],
                'preheader' => $t['preheader'],
                'blocks' => json_encode($t['blocks']),
                'preset' => true,
                'sort' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mkt_templates')) {
            DB::table('mkt_templates')->where('preset', true)->whereIn('key', array_keys(TemplateLibrary::templates()))->delete();
        }
    }
};
