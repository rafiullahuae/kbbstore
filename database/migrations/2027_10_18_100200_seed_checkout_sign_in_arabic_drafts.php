<?php

declare(strict_types=1);

use App\Models\Translation;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane CO: the Arabic drafts for the checkout's "Express checkout" OR line and
 * its "Sign in" window (store.checkout.express_or and store.checkout.signin_*),
 * seeded as DRAFTS the way every other new interface string is, so the owner
 * reviews them on Content -> Translations before /ar/ shows them. A row that
 * already exists is left exactly as it is.
 */
return new class extends Migration
{
    /** The keys this migration is for, so it cannot drift into seeding the whole file. */
    private const KEYS = [
        'store.checkout.express_or',
        'store.checkout.signin_link',
        'store.checkout.signin_title',
        'store.checkout.signin_lead',
        'store.checkout.signin_email',
        'store.checkout.signin_password',
        'store.checkout.signin_submit',
        'store.checkout.signin_forgot',
        'store.checkout.signin_forgot_title',
        'store.checkout.signin_forgot_lead',
        'store.checkout.signin_forgot_submit',
        'store.checkout.signin_back',
        'store.checkout.signin_close',
        'store.checkout.signin_done',
        'store.checkout.signin_failed',
        'store.checkout.signin_full_page',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $drafts = ArabicInterfaceDrafts::all();

        $existing = array_flip(array_map(
            static fn ($f): string => TranslationStore::normaliseKey((string) $f),
            DB::table('translations')
                ->where('locale', 'ar')
                ->where('group', Translation::GROUP_UI)
                ->where('item_id', 0)
                ->whereIn('field', self::KEYS)
                ->pluck('field')
                ->all()
        ));

        $now = now();
        $insert = [];

        foreach (self::KEYS as $key) {
            $field = TranslationStore::normaliseKey($key);

            if (isset($existing[$field]) || ! isset($drafts[$key])) {
                continue;
            }

            $english = InterfaceStrings::english($field);

            $insert[] = [
                'locale' => 'ar',
                'group' => Translation::GROUP_UI,
                'item_id' => 0,
                'field' => $field,
                'value' => $drafts[$key],
                'status' => Translation::STATUS_DRAFT,
                'source' => Translation::SOURCE_MACHINE,
                'source_hash' => $english === null ? null : sha1($english),
                'reviewed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($insert !== []) {
            DB::table('translations')->insert($insert);
        }

        try {
            TranslationStore::flush();
        } catch (\Throwable) {
            // Drafts are not served, so a stale map here cannot even be wrong —
            // and an update that dies half-applied over a cache is the shape
            // CLAUDE.md records against UpdateRunner::recordManifest().
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        // Only rows this migration could have written: a draft from a machine.
        DB::table('translations')
            ->where('locale', 'ar')
            ->where('group', Translation::GROUP_UI)
            ->where('item_id', 0)
            ->whereIn('field', self::KEYS)
            ->where('status', Translation::STATUS_DRAFT)
            ->where('source', Translation::SOURCE_MACHINE)
            ->delete();
    }
};
