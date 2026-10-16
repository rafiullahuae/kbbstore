<?php

declare(strict_types=1);

/*
 * Lane AL -- the Arabic of the LONG text (2027_11_08_1000xx_lane_al_arabic_long_text_partN).
 *
 * The defect on the live /ar: every product's name and blurb were Arabic (ARC,
 * AT) while the Description tab under them printed its English, so Google saw
 * half-English Arabic pages. The parts write that tab -- and, in part 0, the
 * category and brand descriptions, content pages and blog posts ARC left in
 * English -- as published rows, the way ARC and AT do.
 */

use App\Models\Product;
use App\Models\Translation;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

/** @return list<string> every part's path */
function alParts(): array
{
    $files = glob(database_path('migrations/2027_11_08_1000*_lane_al_arabic_long_text_part*.php')) ?: [];
    sort($files);

    return $files;
}

function alMigration(string $file): object
{
    return require $file;
}

/** @return array<string, array<string, array<string, string>>> group => field => slug => Arabic */
function alData(string $file): array
{
    $json = (new ReflectionClassConstant(alMigration($file), 'DATA'))->getValue();

    return (array) (json_decode($json, true)['groups'] ?? []);
}

/** The first product part and one product in it. */
function alFirstProduct(): array
{
    foreach (alParts() as $file) {
        $products = alData($file)['products']['description'] ?? [];

        if ($products !== []) {
            return [$file, (string) array_key_first($products), reset($products)];
        }
    }

    throw new RuntimeException('no product part');
}

function alProduct(string $slug, string $description = '<p><b>Benefits:</b></p><ul><li>Hydrates.</li></ul>'): Product
{
    return Product::create([
        'name' => 'Lane AL product',
        'slug' => $slug,
        'sku' => 'AL-'.substr(md5($slug), 0, 8),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'short_description' => '<p>Short.</p>',
        'description' => $description,
    ]);
}

function alAr(string $group, int $id, string $field): ?object
{
    return DB::table('translations')->where('locale', 'ar')->where('group', $group)
        ->where('item_id', $id)->where('field', $field)->first();
}

it('ships its parts, each small enough for the updater', function () {
    expect(count(alParts()))->toBeGreaterThanOrEqual(2);

    foreach (alParts() as $file) {
        expect(filesize($file))->toBeLessThan(3 * 1024 * 1024, basename($file));
    }
});

it('names no class of this shop in any part, so a run against last release\'s opcache cannot fatal', function () {
    /*
     * MUTATION: put `\App\Services\Translation\TranslationStore::flush();` in
     * any part's forgetCaches() and this is red on that line.
     */
    foreach (alParts() as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (! is_array($t) || ! in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }

            $qualified = ltrim($t[1], '\\');

            if (str_contains($qualified, '\\')) {
                expect($qualified)->toStartWith('Illuminate\\', basename($file)." line {$t[2]} names {$t[1]}");
            }
        }

        expect((string) file_get_contents($file))->not->toContain('App\\');
    }
});

it('writes a matching product\'s Description tab as a published row, and the /ar product page prints it', function () {
    /*
     * MUTATION: key the lookup by anything but slug, or skip the insert, and
     * the Description tab is still the English on /ar.
     */
    [$file, $slug, $arabic] = alFirstProduct();
    $p = alProduct($slug);
    Cache::put('kbb.translations.ar', ['stale' => 'x'], 300);

    alMigration($file)->up();

    $row = alAr('products', $p->id, 'description');
    expect($row)->not->toBeNull()
        ->and($row->value)->toBe(trim($arabic))
        ->and($row->status)->toBe('published')
        ->and($row->source)->toBe('manual')
        ->and($row->source_hash)->toBe(sha1((string) $p->getRawOriginal('description')))
        ->and(Cache::has('kbb.translations.ar'))->toBeFalse();

    ArabicShop::on();
    $html = $this->get('/ar/product/'.$slug.'/')->assertOk()->getContent();

    // A sentence of the Arabic tab, as the page prints it.
    preg_match('/>([^<>]*\p{Arabic}[^<>]{20,})</u', $arabic, $m);
    expect($m)->not->toBeEmpty()
        ->and($html)->toContain(trim($m[1]))
        ->and($html)->not->toContain('Hydrates.');
});

it('never overwrites an Arabic row that exists, the owner\'s or a draft', function () {
    /*
     * MUTATION: drop the `$taken` check and the owner's description is
     * replaced -- or, on MySQL, duplicated. Red either way.
     */
    [$file, $slug] = alFirstProduct();
    $p = alProduct($slug);
    TranslationStore::put('ar', 'products', $p->id, 'description', '<p>مسودة المالك</p>', Translation::STATUS_DRAFT);

    alMigration($file)->up();

    expect(alAr('products', $p->id, 'description')->value)->toBe('<p>مسودة المالك</p>')
        ->and(alAr('products', $p->id, 'description')->status)->toBe('draft')
        ->and(DB::table('translations')->where('group', 'products')->where('item_id', $p->id)->count())->toBe(1);

    DB::table('translations')->where('group', 'products')->where('item_id', $p->id)->update(['status' => 'published', 'value' => '<p>كتبها المالك</p>']);
    alMigration($file)->up();

    expect(alAr('products', $p->id, 'description')->value)->toBe('<p>كتبها المالك</p>');
});

it('writes nothing more the second time, nothing for a slug that is not there, and nothing over an empty English field', function () {
    [$file, $slug] = alFirstProduct();
    $before = DB::table('translations')->count();

    foreach (alParts() as $part) {
        alMigration($part)->up();
    }
    expect(DB::table('translations')->count())->toBe($before);

    // An empty English description has nothing to translate.
    $empty = alProduct($slug, '');
    alMigration($file)->up();
    expect(alAr('products', $empty->id, 'description'))->toBeNull();

    $empty->forceDelete();
    alProduct($slug);
    alMigration($file)->up();
    $once = DB::table('translations')->count();
    alMigration($file)->up();

    expect($once)->toBe($before + 1)
        ->and(DB::table('translations')->count())->toBe($once);
});

it('gives back only what it wrote when rolled back', function () {
    [$file, $slug] = alFirstProduct();
    $data = alData($file)['products']['description'];
    $second = (string) array_keys($data)[1];
    $a = alProduct($slug);
    $b = alProduct($second);

    alMigration($file)->up();
    DB::table('translations')->where('group', 'products')->where('item_id', $b->id)->update(['value' => '<p>عدّلها المالك</p>']);

    alMigration($file)->down();

    expect(alAr('products', $a->id, 'description'))->toBeNull()
        ->and(alAr('products', $b->id, 'description')->value)->toBe('<p>عدّلها المالك</p>');
});

it('keeps the English markup tag for tag, every tag allowed and closed', function () {
    /*
     * These rows bypass TranslationStore's RichText::clean(), so the data is
     * held to the English it replaces: the same tags, attributes and order
     * (tests/Fixtures/lane-al-english-tags.json holds the md5 of each English
     * item's tag sequence, taken from the live page), from the allowlist only.
     *
     * MUTATION: drop a </li> or a class from any item and this is red on it.
     */
    $english = json_decode((string) file_get_contents(base_path('tests/Fixtures/lane-al-english-tags.json')), true);
    $allowed = ['p', 'br', 'b', 'strong', 'em', 'i', 'u', 'span', 'a', 'ul', 'ol', 'li', 'div', 'section',
        'h2', 'h3', 'h4', 'h5', 'figure', 'figcaption', 'img', 'video', 'source', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'blockquote', 'hr', 'sup', 'sub'];
    $void = ['br', 'img', 'source', 'hr'];
    $seen = 0;

    foreach (alParts() as $file) {
        foreach (alData($file) as $group => $fields) {
            foreach ($fields as $field => $bySlug) {
                foreach ($bySlug as $slug => $value) {
                    $key = "{$group}/{$field}/{$slug}";
                    preg_match_all('/<[^>]+>/', $value, $all);

                    expect($english)->toHaveKey($key)
                        ->and(md5(implode('', $all[0])))->toBe($english[$key], "{$key}: tags differ from the English");

                    $depth = [];
                    foreach ($all[0] as $tag) {
                        preg_match('#^<(/?)([a-z0-9]+)#i', $tag, $m);
                        $name = strtolower($m[2] ?? '');
                        expect(in_array($name, $allowed, true))->toBeTrue("{$key}: {$tag}")
                            ->and($tag)->not->toMatch('/\son[a-z]+\s*=|javascript:/i');

                        if (in_array($name, $void, true)) {
                            continue;
                        }
                        if ($m[1] === '/') {
                            expect(array_pop($depth))->toBe($name, "{$key}: </{$name}> closes the wrong tag");
                        } else {
                            $depth[] = $name;
                        }
                    }

                    expect($depth)->toBe([], "{$key}: unclosed tags");
                    $seen++;
                }
            }
        }
    }

    expect($seen)->toBeGreaterThan(40);
});

it('is Arabic: Latin only for units, ratings, INCI and technical acronyms', function () {
    /*
     * The owner, on /ar: "i don't need anything in english in arabic
     * version". Brands are spelled in Arabic script as ARC spells them
     * (كوسركس, أنوا); what stays Latin is what a shopper reads as written on
     * the box.
     *
     * MUTATION: leave "Made in Korea" or "About brand: COSRX" in any item and
     * this is red on it.
     */
    $latinOk = array_flip(preg_split('/\s+/', trim(LANE_AL_LATIN_OK)));

    foreach (alParts() as $file) {
        foreach (alData($file) as $group => $fields) {
            foreach ($fields as $field => $bySlug) {
                foreach ($bySlug as $slug => $value) {
                    preg_match_all('/[A-Za-z]+/', html_entity_decode(strip_tags($value)), $m);

                    foreach ($m[0] as $word) {
                        expect(isset($latinOk[$word]))->toBeTrue("{$group}/{$field}/{$slug}: Latin \"{$word}\"");
                    }

                    expect(preg_match('/\p{Arabic}/u', $value))->toBe(1, "{$group}/{$field}/{$slug}: no Arabic at all");
                }
            }
        }
    }
});

const LANE_AL_LATIN_OK = <<<'TXT'
A AC ACE AHA AP Acetyle B BB BHA BP C D E EGF EMS EU EWG EX F HA HEVIS Hexapeptide K LED LHA MC MCT
NAD NC NMF NP O Oligopeptide P PA PCA PDRN PEG PHA PPM Polypeptide Q SH SPF T TECA TX TXA U USB UV
UVA UVB V X cm g gm kg mAh mL mg ml mm pH ppm sh
TXT;
