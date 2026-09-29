<?php

declare(strict_types=1);

use App\Models\Translation;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1,018 Arabic interface strings, offered as DRAFTS. (Lane AR)
 *
 * ── WHAT THIS CHANGES ON THE SHOP: NOTHING ─────────────────────────────────
 *
 * Every row written here is `status = draft`, and TranslationStore's fallback
 * chain never reaches a draft — uiMap() filters on `published` before the map
 * is built. So /ar renders exactly the English it rendered before this package,
 * /  is untouched in every particular, and the only thing that moves is a
 * number on Translation -> Progress: `Drafts awaiting approval` goes from 0 to
 * 1,018 while `Published in Arabic` stays where it is.
 *
 * That is the point. The owner reads them, corrects what he wants, and presses
 * the console's existing "Publish all". Nothing a shopper can see changes until
 * he does.
 *
 * ── WHY IT NEVER OVERWRITES ────────────────────────────────────────────────
 *
 * The insert is guarded on the row not already existing, keyed by the same
 * (locale, group, item_id, field) identity the table is unique on. So:
 *
 *   - a string the owner has already typed is LEFT ALONE, whatever its status.
 *     His work outranks this file in every case, and it has to: he reads
 *     Arabic and this file was written by a model.
 *   - a string he has already published and then edited is left alone too.
 *   - running the migration twice writes nothing the second time, which matters
 *     because an update package may be applied more than once and because this
 *     shop's migrations are not all one-way.
 *
 * ── AND WHY `source = machine` IS THE HONEST LABEL ─────────────────────────
 *
 * resources/views/admin/partials/arabic-boxes.blade.php: "Manual entry is
 * PUBLISHED IMMEDIATELY. Only a machine drafts." These were written by a model,
 * not typed by a person who reads Arabic, so they are a machine's output and
 * they are labelled as one. It is also the column the console filters on when
 * the owner asks to re-read "everything a machine wrote" — which, for 1,018
 * strings he did not type, is exactly the question he will want to ask.
 *
 * `source_hash` is the sha1 of the English these were made FROM, so the console
 * can mark a row stale the moment somebody edits the English underneath it.
 * Writing it is what makes that work; leaving it null would make every one of
 * these permanently un-checkable.
 *
 * ▲ THE `migrations` FLAG IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT
 *   ALL — UpdateRunner::hasMigrations() never looks at the files. Build the
 *   package with `php artisan kbb:package <version> --since=<ref>`, never a
 *   hand-rolled script.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $rows = ArabicInterfaceDrafts::all();

        // One query for what is already there, rather than one existence check
        // per key: 1,018 SELECTs on a shared host during an update is minutes,
        // and an update that looks hung is an update somebody interrupts.
        $existing = DB::table('translations')
            ->where('locale', 'ar')
            ->where('group', Translation::GROUP_UI)
            ->where('item_id', 0)
            ->pluck('field')
            ->all();

        $existing = array_flip(array_map(
            static fn ($f): string => TranslationStore::normaliseKey((string) $f),
            $existing
        ));

        $now = now();
        $insert = [];

        foreach ($rows as $key => $value) {
            $field = TranslationStore::normaliseKey($key);

            if (isset($existing[$field])) {
                continue;
            }

            $english = \App\Services\Translation\InterfaceStrings::english($field);

            $insert[] = [
                'locale' => 'ar',
                'group' => Translation::GROUP_UI,
                'item_id' => 0,
                'field' => $field,
                'value' => $value,
                'status' => Translation::STATUS_DRAFT,
                'source' => Translation::SOURCE_MACHINE,
                'source_hash' => $english === null ? null : sha1($english),
                'reviewed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Chunked: a single insert of a thousand rows each carrying a few
        // hundred bytes of utf8mb4 is a packet some shared-host MySQL
        // configurations refuse outright (max_allowed_packet), and the failure
        // is a dead update rather than a slow one.
        foreach (array_chunk($insert, 100) as $chunk) {
            DB::table('translations')->insert($chunk);
        }

        /*
         * THE COMPILED FILES, because this release changes CLASSES and not only
         * rows. TranslationEstimate gains three models, a NEVER_MACHINE list and
         * three labels; MachineTranslationRunner learns to read the second one;
         * ArabicInterfaceDrafts is new. Under OPcache the server goes on running
         * the old definitions until something resets it, and the symptom is the
         * worst kind: the rows are in the table, the console looks right, and
         * Translation -> Strings still cannot open a product tab.
         *
         * Named globs and opcache_reset only -- never Cache::flush(), which on
         * some drivers holds the sessions and would sign every customer out.
         */
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
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

        try {
            TranslationStore::flush();
        } catch (\Throwable) {
            // A cache that cannot be reached during an update is a nuisance;
            // an update that dies half-applied because of one is the shape
            // CLAUDE.md records against UpdateRunner::recordManifest(). Drafts
            // are not served, so a stale map here cannot even be wrong.
        }

        if (app()->runningInConsole()) {
            $n = count($insert);
            echo "Cleared {$cleared} compiled files. Wrote {$n} Arabic interface strings as DRAFTS.\n"
                ."\n"
                ."NOTHING ON YOUR SHOP HAS CHANGED. A draft is never shown to a shopper,\n"
                ."so /ar still reads exactly as it did before you applied this, and your\n"
                ."English shop is untouched.\n"
                ."\n"
                ."Read them at Translation -> Strings, where each one sits beside its\n"
                ."English, and correct anything you would say differently. When you are\n"
                ."happy, Translation -> Progress has one button -- 'Approve all 1018\n"
                ."drafts' -- and pressing it is the moment the Arabic shop starts\n"
                ."speaking Arabic.\n"
                ."\n"
                ."Anything you had already typed yourself was left exactly as it was.\n"
                ."Eight long paragraphs -- the intros on the eight concern pages -- were\n"
                ."deliberately NOT translated and still show their English; they are\n"
                ."landing-page copy and want a person who writes Arabic, not a machine.\n";
        }
    }

    /**
     * Remove exactly the rows this wrote, and nothing the owner has touched.
     *
     * A draft that is still a draft, still marked as a machine's, and whose key
     * this file knows, is one of ours. The moment he edits or publishes one it
     * stops matching and `down()` leaves it alone — which is the behaviour you
     * want from a rollback that runs a month later.
     */
    public function down(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $fields = array_map(
            static fn (string $k): string => TranslationStore::normaliseKey($k),
            array_keys(ArabicInterfaceDrafts::all())
        );

        foreach (array_chunk($fields, 200) as $chunk) {
            DB::table('translations')
                ->where('locale', 'ar')
                ->where('group', Translation::GROUP_UI)
                ->where('item_id', 0)
                ->where('status', Translation::STATUS_DRAFT)
                ->where('source', Translation::SOURCE_MACHINE)
                ->whereIn('field', $chunk)
                ->delete();
        }

        try {
            TranslationStore::flush();
        } catch (\Throwable) {
            // See up().
        }
    }
};
