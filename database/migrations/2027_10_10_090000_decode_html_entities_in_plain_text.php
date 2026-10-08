<?php

declare(strict_types=1);

use App\Support\PlainText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * "SKIN&amp;LAB - Vitamin C Brightening Serum" on the owner's grid. (Lane AMP)
 *
 * WordPress stores a title HTML-encoded and the importer copied it as it came,
 * so a plain-text column held "&amp;", "&#8211;", "&#8217;" beside rows typed
 * plain in the admin, and the shop -- which escapes what it prints, once --
 * drew the entity. The importers now store plain text (App\Support\PlainText);
 * this brings the rows already imported into line.
 *
 * ── WHAT IS TOUCHED, AND WHAT IS NOT ───────────────────────────────────────
 *
 * Only the columns in COLUMNS / JSON_KEYS / TRANSLATED: each is plain text that
 * every template prints ESCAPED. Never a rich-HTML column -- descriptions,
 * short descriptions, post bodies and excerpts, `pages.title` (printed raw by
 * store/page.blade.php) -- where an entity is correct HTML. Never a slug, so
 * no URL moves: "skin-amp-lab" stays "skin-amp-lab" if that is what it is.
 * Never `order_items`: an order line is a snapshot and stays byte for byte;
 * OrderItem decodes it when it is read.
 *
 * ── ONCE, AND REVERSIBLE ───────────────────────────────────────────────────
 *
 * Every value written is recorded, old and new, in `entity_decode_undo`, one
 * row per (table, column, id). A row already recorded is never decoded again,
 * so re-running this cannot turn a deliberate "&amp;amp;" into "&" -- it is
 * decoded ONCE. down() puts back every value nobody has edited since.
 *
 * Only rows whose text contains "&" are read (a LIKE the database answers),
 * and only rows that hold a well-formed reference are written, in chunks of
 * CHUNK. A name typed in the admin ("Bath & Body") has no reference and is
 * left alone. updated_at is not touched: nothing about the product changed but
 * how its name was spelled.
 *
 * ▲ Decoding makes "&lt;" a real "<" in these columns. That is safe ONLY
 *   because nothing prints them unescaped -- EntityDecodeEscapesTest renders a
 *   decoded "<script>" on every surface and asserts it stays text.
 */
return new class extends Migration
{
    public const UNDO = 'entity_decode_undo';

    public const CHUNK = 500;

    /** table => plain-text columns. */
    public const COLUMNS = [
        'products' => ['name'],
        'categories' => ['name'],
        'brands' => ['name'],
        'tags' => ['name'],
        'attributes' => ['name'],
        'attribute_values' => ['name'],
        'posts' => ['title', 'author'],
        'menus' => ['name'],
        'menu_items' => ['label'],
        'reviews' => ['author_name', 'title', 'content', 'reply'],
        'product_tabs' => ['title'],
        'customers' => ['name'],
        'banner_cards' => [
            'eyebrow', 'heading', 'body', 'button_label', 'sticker', 'sticker_ring',
            'eyebrow_ar', 'heading_ar', 'body_ar', 'button_label_ar', 'sticker_ar', 'sticker_ring_ar',
        ],
    ];

    /** table => [json column, the text keys inside it]. URLs and flags in the same bag are left alone. */
    public const JSON_KEYS = [
        'products' => ['seo', ['title', 'desc', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description']],
        'categories' => ['seo', ['title', 'desc', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description']],
        'brands' => ['seo', ['title', 'desc', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description']],
        'posts' => ['seo', ['title', 'desc', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description']],
        'pages' => ['seo', ['title', 'desc', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description']],
    ];

    /** The Arabic (and any other language's) copy of the same plain-text fields: translations.group => fields. */
    public const TRANSLATED = [
        'products' => ['name'],
        'categories' => ['name'],
        'brands' => ['name'],
        'tags' => ['name'],
        'attributes' => ['name'],
        'attribute_values' => ['name'],
        'posts' => ['title'],
        'menu_items' => ['label'],
        'product_tabs' => ['title'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::UNDO)) {
            Schema::create(self::UNDO, function (Blueprint $table): void {
                $table->id();
                $table->string('table_name', 64);
                $table->string('column_name', 64);
                $table->unsignedBigInteger('row_id');
                $table->longText('old_value')->nullable();
                $table->longText('new_value')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['table_name', 'column_name', 'row_id'], 'entity_decode_undo_row');
            });
        }

        $fixed = [];

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $fixed[$table.'.'.$column] = $this->decodeColumn($table, $column, null, static fn (string $v): ?string => PlainText::decode($v));
            }
        }

        foreach (self::JSON_KEYS as $table => [$column, $keys]) {
            $fixed[$table.'.'.$column] = $this->decodeColumn($table, $column, null, static function (string $v) use ($keys): ?string {
                $bag = json_decode($v, true);

                if (! is_array($bag)) {
                    return $v;
                }

                $changed = false;

                foreach ($keys as $key) {
                    if (is_string($bag[$key] ?? null) && PlainText::encoded($bag[$key])) {
                        $bag[$key] = PlainText::decode($bag[$key]);
                        $changed = true;
                    }
                }

                // The shape the model's `array` cast writes, so the next save is a no-op.
                return $changed ? (json_encode($bag) ?: $v) : $v;
            });
        }

        if (Schema::hasTable('translations')) {
            $fixed['translations.value'] = $this->decodeColumn('translations', 'value', static function ($query): void {
                $query->where(static function ($q): void {
                    foreach (self::TRANSLATED as $group => $fields) {
                        $q->orWhere(static fn ($w) => $w->where('group', $group)->whereIn('field', $fields));
                    }
                });
            }, static fn (string $v): ?string => PlainText::decode($v));
        }

        $fixed = array_filter($fixed);

        if (app()->runningInConsole()) {
            echo 'Plain-text columns decoded once: '.($fixed === [] ? 'nothing to do' : implode(', ', array_map(
                static fn (string $k, int $n): string => "{$k} {$n}", array_keys($fixed), $fixed
            ))).". Old values kept in ".self::UNDO.".\n";
        }
    }

    /** Every value nobody has edited since goes back; the record of anything else stays. */
    public function down(): void
    {
        if (! Schema::hasTable(self::UNDO)) {
            return;
        }

        DB::table(self::UNDO)->orderBy('id')->chunkById(self::CHUNK, function ($rows): void {
            DB::transaction(function () use ($rows): void {
                foreach ($rows as $row) {
                    if (! Schema::hasColumn($row->table_name, $row->column_name)) {
                        continue;
                    }

                    $current = DB::table($row->table_name)->where('id', $row->row_id)->first([$row->column_name]);

                    if ($current !== null && ! $this->same($row->column_name, $current->{$row->column_name}, $row->new_value)) {
                        continue; // edited since: the edit wins, and its record stays
                    }

                    if ($current !== null) {
                        DB::table($row->table_name)->where('id', $row->row_id)->update([$row->column_name => $row->old_value]);
                    }

                    DB::table(self::UNDO)->where('id', $row->id)->delete();
                }
            });
        });

        if (! DB::table(self::UNDO)->exists()) {
            Schema::drop(self::UNDO);
        }
    }

    /** Still the value this migration wrote? JSON by meaning, since MySQL reformats a `json` column. */
    private function same(string $column, mixed $current, ?string $written): bool
    {
        if ($column === 'seo' && is_string($current) && is_string($written)) {
            return json_decode($current, true) == json_decode($written, true); // == : MySQL reorders keys
        }

        return $current === $written;
    }

    /**
     * Decode one column in chunks; returns how many rows were written.
     *
     * @param  (callable(\Illuminate\Database\Query\Builder): void)|null  $scope
     * @param  callable(string): ?string  $decode
     */
    private function decodeColumn(string $table, string $column, ?callable $scope, callable $decode): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $query = DB::table($table)->select('id', $column)->where($column, 'like', '%&%');

        if ($scope !== null) {
            $scope($query);
        }

        $written = 0;

        $query->chunkById(self::CHUNK, function ($rows) use ($table, $column, $decode, &$written): void {
            $done = DB::table(self::UNDO)->where('table_name', $table)->where('column_name', $column)
                ->whereIn('row_id', $rows->pluck('id')->all())->pluck('row_id')->map(static fn ($id) => (int) $id)->flip();

            $now = now();
            $undo = [];

            DB::transaction(function () use ($rows, $table, $column, $decode, $done, $now, &$undo, &$written): void {
                foreach ($rows as $row) {
                    $old = $row->{$column};

                    if (! is_string($old) || isset($done[(int) $row->id])) {
                        continue;
                    }

                    $new = $decode($old);

                    if ($new === null || $new === $old) {
                        continue;
                    }

                    // By id: a `json` column on MySQL does not compare equal to a string,
                    // so the value cannot be the guard. The site is in maintenance mode.
                    DB::table($table)->where('id', $row->id)->update([$column => $new]);
                    $undo[] = ['table_name' => $table, 'column_name' => $column, 'row_id' => (int) $row->id,
                        'old_value' => $old, 'new_value' => $new, 'created_at' => $now];
                    $written++;
                }

                if ($undo !== []) {
                    DB::table(self::UNDO)->insert($undo);
                }
            });
        });

        return $written;
    }
};
