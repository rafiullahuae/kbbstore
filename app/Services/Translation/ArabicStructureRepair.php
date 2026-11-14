<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\Translation;
use App\Support\BlockStructure;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Line the STORED Arabic product copy up with its English: blank lines and list
 * boundaries only, never a word.                           (Lane ARDX, 14 Nov 2026)
 *
 * The owner: "check first the most recent 50 products added and then go to all
 * arabic versions in all products and fix this permanently". The page already
 * draws every translation lined up (ProductTabs -> BlockStructure::forDisplay());
 * this makes the stored rows say the same, so the editor, the feed and any later
 * screen see the right shape too.
 *
 * Rows covered: locale `ar`, group `products`, the four rich fields
 * (TranslationStore::RICH_FIELDS) against the product's own column, and group
 * `product_tabs` / `body` against the tab's English body.
 *
 * SAFETY, in order:
 *   - scan() only reads. Two queries for the rows and their English, whatever
 *     the catalogue's size (chunked by id).
 *   - A row is changed only when BlockStructure::align() lines it up with
 *     certainty (same order of lines and lists, words byte-identical after the
 *     repair -- align() checks that itself). Everything else is listed with the
 *     reason and left alone.
 *   - Copy laid out by bare newlines (classic-editor text, RichText::needsAutop())
 *     is listed, never touched: its lines are drawn by wpautop at render time.
 *   - apply() writes every changed row's previous value to
 *     `ar_structure_backups` under one batch id BEFORE it changes it, in the
 *     same transaction, and undo($batch) puts back exactly the rows that still
 *     hold what the repair wrote (an edit made since is the owner's and stays).
 *   - Saved through the model, so Translation's saved hook flushes the caches.
 *     status, source and source_hash are not touched.
 */
final class ArabicStructureRepair
{
    public const LOCALE = 'ar';

    public const BACKUPS = 'ar_structure_backups';

    /**
     * @param  list<int>|null  $productIds  null = every product
     * @return array{rows: int, fixable: list<array>, human: list<array>, aligned: int, untranslated: int}
     */
    public static function scan(?array $productIds = null): array
    {
        $out = ['rows' => 0, 'fixable' => [], 'human' => [], 'aligned' => 0, 'untranslated' => 0];

        foreach (self::candidates($productIds) as $c) {
            $out['rows']++;
            $english = (string) $c['english'];
            $arabic = (string) $c['value'];

            if (trim($english) === '' || $english === $arabic || preg_match('/\p{Arabic}/u', strip_tags($arabic)) !== 1) {
                $out['untranslated']++;
                continue;
            }

            if (RichText::needsAutop($english) || RichText::needsAutop($arabic)) {
                if (BlockStructure::shape($english) !== BlockStructure::shape($arabic)) {
                    $out['human'][] = self::row($c) + ['why' => 'laid out by plain line breaks (no HTML paragraphs); check it by eye'];
                } else {
                    $out['aligned']++;
                }
                continue;
            }

            $a = BlockStructure::align($english, $arabic);

            if (! $a['ok']) {
                $out['human'][] = self::row($c) + ['why' => (string) $a['problem'], 'en' => BlockStructure::shape($english), 'ar' => BlockStructure::shape($arabic)];
                continue;
            }

            if (! $a['changed']) {
                $out['aligned']++;
                continue;
            }

            $out['fixable'][] = self::row($c) + [
                'changes' => $a['changes'],
                'before' => BlockStructure::shape($arabic),
                'after' => BlockStructure::shape($a['html']),
                'en' => BlockStructure::shape($english),
                'html' => $a['html'],
            ];
        }

        return $out;
    }

    /**
     * Write the repairs scan() found, with a backup of each row first.
     *
     * @param  list<int>|null  $productIds
     * @return array{batch: ?string, changed: int, human: int}
     */
    public static function apply(?array $productIds = null): array
    {
        $scan = self::scan($productIds);

        if ($scan['fixable'] === []) {
            return ['batch' => null, 'changed' => 0, 'human' => count($scan['human'])];
        }

        self::ensureTable();
        $batch = now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $changed = 0;

        DB::transaction(function () use ($scan, $batch, &$changed) {
            foreach ($scan['fixable'] as $fix) {
                $row = Translation::query()->find($fix['translation_id']);
                if ($row === null || (string) $row->value !== $fix['old']) {
                    continue; // changed under us: leave it
                }

                DB::table(self::BACKUPS)->insert([
                    'batch' => $batch,
                    'translation_id' => $row->id,
                    'group' => $row->group,
                    'item_id' => $row->item_id,
                    'field' => $row->field,
                    'old_value' => $fix['old'],
                    'new_value' => $fix['html'],
                    'created_at' => now(),
                ]);

                $row->value = $fix['html'];
                $row->save();
                $changed++;
            }
        });

        TranslationStore::flush();

        return ['batch' => $batch, 'changed' => $changed, 'human' => count($scan['human'])];
    }

    /**
     * Put a batch back. Only rows still holding what the repair wrote.
     *
     * @return array{restored: int, kept: int}
     */
    public static function undo(string $batch): array
    {
        if (! Schema::hasTable(self::BACKUPS)) {
            return ['restored' => 0, 'kept' => 0];
        }

        $restored = 0;
        $kept = 0;

        DB::transaction(function () use ($batch, &$restored, &$kept) {
            foreach (DB::table(self::BACKUPS)->where('batch', $batch)->orderBy('id')->get() as $b) {
                $row = Translation::query()->find($b->translation_id);
                if ($row === null || (string) $row->value !== (string) $b->new_value) {
                    $kept++;
                    continue;
                }
                $row->value = (string) $b->old_value;
                $row->save();
                $restored++;
            }
            DB::table(self::BACKUPS)->where('batch', $batch)->update(['undone_at' => now()]);
        });

        TranslationStore::flush();

        return ['restored' => $restored, 'kept' => $kept];
    }

    /** @return list<array{batch: string, rows: int, at: string, undone: bool}> */
    public static function batches(int $limit = 10): array
    {
        if (! Schema::hasTable(self::BACKUPS)) {
            return [];
        }

        // A handful of batches ever (one per Apply): grouped in SQL, newest
        // first by the batch's own last row id, which cannot tie.
        return DB::table(self::BACKUPS)
            ->selectRaw('batch, count(*) as n, min(created_at) as at, max(undone_at) as undone, max(id) as last_id')
            ->groupBy('batch')->get()
            ->sortByDesc('last_id')->take($limit)->values()
            ->map(static fn ($r) => ['batch' => (string) $r->batch, 'rows' => (int) $r->n, 'at' => (string) $r->at, 'undone' => $r->undone !== null])
            ->all();
    }

    /** The newest N products by date added, as the shop's "newest" sort has them. */
    public static function recentIds(int $count): array
    {
        return DB::table('products')->orderByDesc('created_at')->orderByDesc('id')->limit(max(1, $count))->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }


    /**
     * Every Arabic rich row with its English, 200 at a time so the catalogue's
     * size never sits in memory at once: two queries per page (the rows, then
     * their English in one whereIn), whatever the page holds.
     *
     * @return \Generator<array<string, mixed>>
     */
    private static function candidates(?array $productIds): \Generator
    {
        yield from self::pages(
            static fn () => DB::table('translations')
                ->where('locale', self::LOCALE)->where('group', TranslationStore::RICH_GROUP)
                ->whereIn('field', TranslationStore::RICH_FIELDS)
                ->when($productIds !== null, static fn ($q) => $q->whereIn('item_id', $productIds ?: [0])),
            'products',
        );

        if (! Schema::hasTable('product_tabs')) {
            return;
        }

        $tabIds = $productIds === null ? null : DB::table('product_tabs')->whereIn('product_id', $productIds ?: [0])->pluck('id')->all();

        yield from self::pages(
            static fn () => DB::table('translations')
                ->where('locale', self::LOCALE)->where('group', 'product_tabs')->where('field', 'body')
                ->when($tabIds !== null, static fn ($q) => $q->whereIn('item_id', $tabIds ?: [0])),
            'product_tabs',
        );
    }

    private static function pages(\Closure $query, string $group): \Generator
    {
        $columns = $group === 'products'
            ? array_merge(['id', 'slug'], TranslationStore::RICH_FIELDS)
            : ['id', 'product_id', 'body'];
        $last = 0;

        while (true) {
            $rows = $query()->where('id', '>', $last)->orderBy('id')->limit(200)->get(['id', 'item_id', 'field', 'value']);
            if ($rows->isEmpty()) {
                return;
            }
            $last = (int) $rows->last()->id;

            $sources = DB::table($group)->whereIn('id', $rows->pluck('item_id')->unique()->values()->all())->get($columns)->keyBy('id');

            foreach ($rows as $r) {
                $source = (array) ($sources[$r->item_id] ?? []);
                $field = $group === 'products' ? (string) $r->field : 'body';

                yield [
                    'translation_id' => (int) $r->id,
                    'group' => $group,
                    'item_id' => (int) $r->item_id,
                    'product_id' => $group === 'products' ? (int) $r->item_id : (isset($source['product_id']) ? (int) $source['product_id'] : null),
                    'slug' => (string) ($source['slug'] ?? ''),
                    'field' => (string) $r->field,
                    'value' => (string) $r->value,
                    'english' => (string) ($source[$field] ?? ''),
                ];
            }
        }
    }

    private static function row(array $c): array
    {
        return [
            'translation_id' => $c['translation_id'],
            'group' => $c['group'],
            'item_id' => $c['item_id'],
            'product_id' => $c['product_id'],
            'slug' => $c['slug'],
            'field' => $c['field'],
            'old' => $c['value'],
        ];
    }

    private static function ensureTable(): void
    {
        if (! Schema::hasTable(self::BACKUPS)) {
            throw new \RuntimeException('The backup table '.self::BACKUPS.' is missing: run php artisan migrate --force first. Nothing was changed.');
        }
    }
}
