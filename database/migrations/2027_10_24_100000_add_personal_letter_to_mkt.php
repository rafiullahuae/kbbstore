<?php

declare(strict_types=1);

use App\Services\Marketing\TemplateLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane EP — "Personal letter style (best chance for the Primary tab)".
 *
 * The owner, 10 October: "The marketing emails are going to promotion folder,
 * we want to send to inbox to our existing customers."
 *
 * On mkt_campaigns:
 *   letter_signer  the first name the letter is from: the From reads
 *                  "<signer> from <shop>" and the letter is signed with it.
 *                  Blank keeps the campaign's From name.
 *   letter_opens   count opens on a letter (the invisible pixel). OFF by
 *                  default: a letter carries no picture it does not show.
 *
 * And the ready template "New look, less prices — personal letter"
 * (TemplateLibrary 'new-look-letter'), seeded by key the way the New Look
 * templates were: a second run inserts nothing.
 *
 * No ->after() (MigrationConventionTest).
 */
return new class extends Migration
{
    private const KEY = 'new-look-letter';

    public function up(): void
    {
        if (Schema::hasTable('mkt_campaigns')) {
            Schema::table('mkt_campaigns', function (Blueprint $t) {
                if (! Schema::hasColumn('mkt_campaigns', 'letter_signer')) {
                    $t->string('letter_signer', 60)->nullable();
                }
                if (! Schema::hasColumn('mkt_campaigns', 'letter_opens')) {
                    $t->boolean('letter_opens')->default(false);
                }
            });
        }

        if (! Schema::hasTable('mkt_templates') || ! Schema::hasColumn('mkt_templates', 'theme')) {
            return;
        }

        $t = TemplateLibrary::templates()[self::KEY] ?? null;

        if ($t === null) {
            return;
        }

        if (DB::table('mkt_templates')->where('key', self::KEY)->exists()) {
            // The first ready-template seed reads the same library on a fresh
            // install, before the theme column exists. A preset is read-only,
            // so its look is ours to set.
            DB::table('mkt_templates')->where('key', self::KEY)->where('preset', true)
                ->update(['theme' => $t['theme'], 'locale' => $t['locale']]);

            return;
        }

        DB::table('mkt_templates')->insert([
            'key' => self::KEY,
            'name' => $t['name'],
            'category' => $t['category'],
            'description' => $t['description'],
            'subject' => $t['subject'],
            'preheader' => $t['preheader'],
            'blocks' => json_encode($t['blocks']),
            'theme' => $t['theme'],
            'locale' => $t['locale'],
            'preset' => true,
            'sort' => (int) DB::table('mkt_templates')->where('preset', true)->max('sort') + 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('mkt_templates')) {
            DB::table('mkt_templates')->where('preset', true)->where('key', self::KEY)->delete();
        }

        foreach (['letter_signer', 'letter_opens'] as $column) {
            if (Schema::hasTable('mkt_campaigns') && Schema::hasColumn('mkt_campaigns', $column)) {
                Schema::table('mkt_campaigns', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
