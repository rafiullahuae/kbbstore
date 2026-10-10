<?php

declare(strict_types=1);

use App\Services\Marketing\TemplateLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "New look, less prices" in design C, English and Arabic (Lane EC) —
 * Marketing Emails → Templates → Ready templates. Read-only presets like the
 * twelve before them (Use makes a campaign from a copy). Seeded by key, so a
 * second run inserts nothing and never touches a row that is already there.
 */
return new class extends Migration
{
    private const KEYS = ['new-look', 'new-look-ar'];

    public function up(): void
    {
        if (! Schema::hasTable('mkt_templates') || ! Schema::hasColumn('mkt_templates', 'theme')) {
            return;
        }

        $have = DB::table('mkt_templates')->whereIn('key', self::KEYS)->pluck('key')->all();
        $sort = (int) DB::table('mkt_templates')->where('preset', true)->max('sort');
        $all = TemplateLibrary::templates();
        $now = now();

        foreach (self::KEYS as $key) {
            $sort += 10;

            if (! isset($all[$key])) {
                continue;
            }

            $t = $all[$key];

            if (in_array($key, $have, true)) {
                // On a fresh install the first ready-template seed (500800)
                // reads the same library and inserts these before the theme
                // column exists. A preset is read-only, so its look is ours
                // to set.
                DB::table('mkt_templates')->where('key', $key)->where('preset', true)
                    ->update(['theme' => $t['theme'], 'locale' => $t['locale']]);

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
                'theme' => $t['theme'],
                'locale' => $t['locale'],
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
            DB::table('mkt_templates')->where('preset', true)->whereIn('key', self::KEYS)->delete();
        }
    }
};
