<?php

declare(strict_types=1);

/*
 * Lane AT -- the Arabic of the eight products added to the live shop after
 * Lane ARC's snapshot (2027_11_07_100000).
 *
 * The defect on the live /ar: the lilyeve steam brush, three Purito SEOUL
 * serums and four SUNGBOON EDITOR products printed their name, blurb and
 * Description tab wholly in English, because no Arabic row existed for them.
 */

use App\Models\Product;
use App\Models\Translation;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

const AT_MIGRATION = '2027_11_07_100000_lane_at_arabic_recent_products';
const AT_SLUG = 'purito-seoul-azelaic-acid-10-kojic-tea-tree-serum';

function atMigration(): object
{
    return require database_path('migrations/'.AT_MIGRATION.'.php');
}

/** @return array<string, array<string, array<string, string>>> the migration's own data */
function atData(): array
{
    return (new ReflectionClassConstant(atMigration(), 'DATA'))->getValue();
}

function atProduct(string $slug = AT_SLUG): Product
{
    return Product::create([
        'name' => 'Purito SEOUL - Azelaic Acid 10 Kojic Tea Tree Serum',
        'slug' => $slug,
        'sku' => 'AT-0001',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'short_description' => '<div>Purito SEOUL Azelaic Acid 10 Kojic Tea Tree Serum combines 10% azelaic acid.</div>',
        'description' => '<div><b>Key Benefits:</b></div><ul><li>Azelaic acid helps.</li></ul>',
    ]);
}

function atAr(string $group, int $id, string $field): ?object
{
    return DB::table('translations')->where('locale', 'ar')->where('group', $group)
        ->where('item_id', $id)->where('field', $field)->first();
}

it('names no class of this shop, so a migration run against last release\'s opcache cannot fatal', function () {
    /*
     * MUTATION: put `\App\Services\Translation\TranslationStore::flush();` in
     * forgetCaches() and this is red on that line.
     */
    $file = database_path('migrations/'.AT_MIGRATION.'.php');

    foreach (token_get_all((string) file_get_contents($file)) as $t) {
        if (! is_array($t) || ! in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            continue;
        }

        $qualified = ltrim($t[1], '\\');

        if (str_contains($qualified, '\\')) {
            expect($qualified)->toStartWith('Illuminate\\', "line {$t[2]} names {$t[1]}");
        }
    }

    expect((string) file_get_contents($file))->not->toContain('App\\');
});

it('writes the Arabic of a matching product as published rows, and the product page prints it', function () {
    /*
     * MUTATION: key the lookup by anything but slug, or skip the insert, and
     * the name is still the English on /ar.
     */
    $p = atProduct();
    Cache::put('kbb.translations.ar', ['stale' => 'x'], 300);

    atMigration()->up();

    $data = atData()['products'][AT_SLUG];

    foreach (['name', 'short_description', 'description'] as $field) {
        $row = atAr('products', $p->id, $field);
        expect($row)->not->toBeNull($field)
            ->and($row->value)->toBe($data[$field])
            ->and($row->status)->toBe('published')
            ->and($row->source)->toBe('manual')
            ->and($row->source_hash)->toBe(sha1((string) $p->getRawOriginal($field)));
    }

    expect(DB::table('search_texts_ar')->where('product_id', $p->id)->value('text'))->not->toBeEmpty()
        ->and(Cache::has('kbb.translations.ar'))->toBeFalse();

    ArabicShop::on();
    $html = $this->get('/ar/product/'.AT_SLUG.'/')->assertOk()->getContent();

    expect($html)->toContain('سيروم Azelaic Acid 10 Kojic Tea Tree')
        ->and($html)->toContain('الفوائد الرئيسية')
        ->and($html)->toContain('يجمع Azelaic Acid 10 Kojic Tea Tree Serum من Purito SEOUL');
});

it('never overwrites an Arabic row that exists, the owner\'s or a draft', function () {
    /*
     * MUTATION: drop the `exists()` check and the owner's name is replaced --
     * or, on MySQL, duplicated. Red either way.
     */
    $p = atProduct();
    TranslationStore::put('ar', 'products', $p->id, 'name', 'اسم كتبه المالك', Translation::STATUS_PUBLISHED);
    TranslationStore::put('ar', 'products', $p->id, 'description', '<p>مسودة</p>', Translation::STATUS_DRAFT);

    atMigration()->up();

    expect(atAr('products', $p->id, 'name')->value)->toBe('اسم كتبه المالك')
        ->and(atAr('products', $p->id, 'description')->value)->toBe('<p>مسودة</p>')
        ->and(atAr('products', $p->id, 'description')->status)->toBe('draft')
        ->and(atAr('products', $p->id, 'short_description'))->not->toBeNull()
        ->and(DB::table('translations')->where('group', 'products')->where('item_id', $p->id)->count())->toBe(3);
});

it('writes nothing more the second time, and nothing at all for a slug that is not there', function () {
    $before = DB::table('translations')->count();
    atMigration()->up();
    expect(DB::table('translations')->count())->toBe($before);

    atProduct();
    atMigration()->up();
    $once = DB::table('translations')->count();
    atMigration()->up();

    expect($once)->toBe($before + 3)
        ->and(DB::table('translations')->count())->toBe($once);
});

it('gives back only what it wrote when rolled back', function () {
    $p = atProduct();
    atMigration()->up();
    DB::table('translations')->where('group', 'products')->where('item_id', $p->id)->where('field', 'name')->update(['value' => 'عدّلها المالك']);

    atMigration()->down();

    expect(atAr('products', $p->id, 'name')->value)->toBe('عدّلها المالك')
        ->and(DB::table('translations')->where('group', 'products')->where('item_id', $p->id)->count())->toBe(1);
});

it('writes the SUNGBOON EDITOR brand description', function () {
    $id = DB::table('brands')->insertGetId(['name' => 'SUNGBOON EDITOR', 'slug' => 'sungboon-editor', 'description' => 'Inspired by the Korean word sungboon.', 'created_at' => now(), 'updated_at' => now()]);

    atMigration()->up();

    expect(atAr('brands', $id, 'description')->value)->toBe(atData()['brands']['sungboon-editor']['description'])
        ->and(atAr('brands', $id, 'name'))->toBeNull();
});

it('ships the eight products as markup the product page may print raw, with the Latin kept', function () {
    /*
     * These rows bypass TranslationStore's RichText::clean(), so the data is
     * held to the allowlist itself: the short description to ARC's (no
     * attribute at all), the Description tab to the English tab's own tags,
     * whose only attribute is the blank-line class.
     */
    $products = atData()['products'];

    expect(array_keys($products))->toHaveCount(8);

    foreach ($products as $slug => $fields) {
        expect(array_keys($fields))->each->toBeIn(['name', 'short_description', 'description'])
            ->and(strip_tags($fields['name']))->toBe($fields['name'])
            ->and($fields['description'])->toContain('صُنع في كوريا');

        foreach (['short_description' => ['p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'h3', 'h4', 'span'],
            'description' => ['div', 'ul', 'li', 'b', 'span', 'p', 'br']] as $field => $allowed) {
            preg_match_all('/<\/?([a-z0-9]+)([^>]*)>/i', $fields[$field] ?? '', $m, PREG_SET_ORDER);

            foreach ($m as [$all, $tag, $attrs]) {
                expect(in_array(strtolower($tag), $allowed, true))->toBeTrue("{$slug} {$field}: <{$tag}>")
                    ->and(in_array(trim($attrs, ' /'), ['', 'class="kbb-bl"'], true) && ($field === 'description' || trim($attrs, ' /') === ''))
                    ->toBeTrue("{$slug} {$field}: attributes on <{$tag}>");
            }

            // Every opened tag closes: a stray one would swallow the next tab.
            foreach (['div', 'ul', 'li', 'b', 'span', 'p'] as $tag) {
                expect(substr_count($fields[$field] ?? '', "<{$tag}>") + substr_count($fields[$field] ?? '', "<{$tag} "))
                    ->toBe(substr_count($fields[$field] ?? '', "</{$tag}>"), "{$slug} {$field}: <{$tag}> unbalanced");
            }
        }
    }

    // Sizes, ratings and brand names stay exactly as the English writes them.
    expect($products['sungboon-editor-silk-peptide-egf-intensive-gold-mask-set']['description'])->toContain('37 g × 4')
        ->and($products[AT_SLUG]['description'])->toContain('SPF 30')->toContain('30 ml')->toContain('PURITO SEOUL')
        ->and($products['sungboon-editor-deep-collagen-retinol-power-boosting-capsule-cream']['description'])->toContain('50 ml');
});
