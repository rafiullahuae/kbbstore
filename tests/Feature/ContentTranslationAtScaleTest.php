<?php

declare(strict_types=1);

/*
 * T4 and T5 at the owner's real volume (Lane FN).
 *
 * The foundation measured a 24-product grid. The owner's shop is 671 products,
 * and the two questions this file pins are the ones 24 rows cannot answer:
 *
 *   1. What does the cached map COST when the whole catalogue is translated,
 *      and does "no extra queries" survive the fields the catalogue really
 *      carries? (The measurement itself is docs/fn-translation-at-scale.md —
 *      MySQL, real volume, one process per page. What is HERE is the part that
 *      can be asserted on a fixture.)
 *
 *   2. What does the machine-translation runner do on a run of hundreds of
 *      fields, in batches, when the provider answers badly or stops answering
 *      halfway?
 *
 * NOTHING HERE TOUCHES THE NETWORK. Where a provider is needed it is either a
 * recording fake or the real GoogleProvider driven through Http::fake(), which
 * is the stronger of the two: it exercises the provider's own null-placeholder
 * ordering rather than trusting a fake to reproduce it. A test that spends
 * money is a test nobody runs.
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\Translation\GoogleProvider;
use App\Services\Translation\MachineTranslationRunner;
use App\Services\Translation\TranslationEstimate;
use App\Services\Translation\TranslationProvider;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function fnArabicOn(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

function fnAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'FN owner',
        'email' => 'fn-'.uniqid().'@example.com',
        'password' => bcrypt('secret'),
        'role' => 'owner',
    ]);
}

/**
 * $count products with plain, machine-safe names and short descriptions.
 *
 * Written through the query builder: this file needs hundreds of them and the
 * point is the runner's batching, not Eloquent's write path.
 *
 * @return list<int>
 */
function fnProducts(int $count): array
{
    DB::table('products')->delete();

    $now = now();
    $rows = [];

    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [
            'slug' => 'fn-product-'.$i,
            'name' => 'Hydrating Serum No. '.$i,
            'sku' => 'FN-'.$i,
            'type' => 'simple',
            'status' => 'publish',
            'is_visible' => 1,
            'price' => 1000 + $i,
            'short_description' => 'A gentle daily serum, number '.$i.'.',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('products')->insert($chunk);
    }

    return DB::table('products')->orderBy('id')->pluck('id')->all();
}

/** A provider that records every batch it is handed and answers in order. */
function fnRecordingProvider(?callable $answer = null): object
{
    return new class($answer) implements TranslationProvider
    {
        /** @var list<list<string>> */
        public array $batches = [];

        public int $characters = 0;

        public function __construct(private $answer) {}

        public function name(): string
        {
            return 'Recording fake';
        }

        public function available(): bool
        {
            return true;
        }

        public function translate(array $texts, string $from, string $to): array
        {
            $this->batches[] = array_values($texts);

            foreach ($texts as $text) {
                // What a provider bills for: every character it was SENT,
                // whatever it chooses to answer with.
                $this->characters += mb_strlen((string) $text, 'UTF-8');
            }

            if ($this->answer !== null) {
                return ($this->answer)(array_values($texts), count($this->batches));
            }

            return array_map(static fn (string $t): string => 'AR::'.$t, array_values($texts));
        }
    };
}

/* ══════════════ 1. ingredients and how_to_use are prose, and are translatable ══════════════ */

it('translates the ingredients and how-to-use tabs, not just the description', function () {
    /*
     * Store\ProductController::tabs() builds three tabs — Description,
     * Ingredients, How to use — from three columns. Two of them were off
     * Product::$translatable, so an Arabic shopper read two of the three tabs
     * in English with nothing on the progress screen calling it outstanding:
     * a field that is not translatable is not counted as work, so the bar said
     * done while the page was not.
     *
     * Before this change saveTranslations() dropped both fields on the floor —
     * silently, by design, because an unknown key is a stale browser tab.
     */
    fnArabicOn();

    $product = Product::create([
        'name' => 'Heartleaf Toner', 'slug' => 'fn-tabs-'.uniqid(),
        'sku' => 'FN-TABS', 'price' => 9900, 'status' => 'publish', 'is_visible' => true,
        'ingredients' => '<p>Water, Glycerin, Houttuynia Cordata Extract.</p>',
        'how_to_use' => '<p>Sweep over the face with a cotton pad.</p>',
    ]);

    $written = $product->saveTranslations(['ar' => [
        'ingredients' => '<p>ماء، جليسرين، مستخلص الهوتونيا.</p>',
        'how_to_use' => '<p>يمسح على الوجه بقطنة.</p>',
    ]]);

    expect($written)->toBe(2);

    TranslationStore::flush();
    app()->setLocale('ar');

    expect($product->t('ingredients'))->toContain('جليسرين')
        ->and($product->t('how_to_use'))->toContain('بقطنة');
});

it('counts the two new tabs as work on the progress screen', function () {
    /*
     * The other half, and the half that decides whether the owner ever finds
     * out. TranslationEstimate::CONTENT is a second copy of the allowlist —
     * BilingualFoundationTest pins the two against each other — and the
     * estimate is what the progress bar and the character count are built
     * from. A column added to the model alone would be translatable and
     * invisible.
     */
    fnArabicOn();

    // The migration set seeds a demo catalogue; emptied so this row is the
    // whole denominator and the arithmetic below is readable.
    DB::table('products')->delete();

    Product::create([
        'name' => 'Counted', 'slug' => 'fn-counted-'.uniqid(),
        'sku' => 'FN-COUNT', 'price' => 100, 'status' => 'publish', 'is_visible' => true,
        'ingredients' => 'Water, Glycerin.',
        'how_to_use' => 'Apply daily.',
    ]);

    expect(TranslationEstimate::CONTENT[Product::class])
        ->toContain('ingredients')
        ->toContain('how_to_use');

    $products = TranslationEstimate::forLocale('ar')['groups']['products'];

    // name + short_description(absent) + ingredients + how_to_use = 3 fields
    // with text on this row, and their characters are quoted as outstanding.
    expect($products['fields'])->toBe(3)
        ->and($products['characters'])->toBe(
            mb_strlen('Counted') + mb_strlen('Water, Glycerin.') + mb_strlen('Apply daily.')
        );
});

it('sanitises the Arabic of every field the storefront prints unescaped', function () {
    /*
     * THE HOLE THAT ADDING A COLUMN TO A LIST WOULD HAVE OPENED.
     *
     * partials/product-tabs.blade.php prints every tab body with {!! !!},
     * whichever language it holds. The English side of the editor runs all four
     * rich columns through RichText::clean(); the Arabic side was handed a
     * literal pair, `['short_description', 'description']`, under a comment
     * that said "the four rich fields". While ingredients and how_to_use were
     * off the allowlist that pair was complete and the comment was merely
     * wrong. The moment they went ON it, the Arabic halves of two {!! !!} tabs
     * went to the database unsanitised — a stored-XSS hole opened not by
     * writing any new code but by adding two strings to a list in another file.
     *
     * Both lists now read one constant, so they cannot drift apart again.
     */
    fnArabicOn();

    \Tests\Support\ProductEditorRoutes::wire(app());

    $response = test()->actingAs(fnAdmin(), 'admin')->postJson('/admin-api/product-editor-create', [
        'name' => 'XSS probe',
        'slug' => 'fn-xss-'.uniqid(),
        'sku' => 'FN-XSS',
        'status' => 'draft',
        'ingredients' => '<p>Water</p>',
        'how_to_use' => '<p>Apply</p>',
        'translations' => ['ar' => [
            'ingredients' => '<p>ماء<script>alert(1)</script></p>',
            'how_to_use' => '<p>يوضع<img src=x onerror=alert(1)></p>',
        ]],
    ]);

    $response->assertSuccessful();

    $product = Product::query()->where('sku', 'FN-XSS')->firstOrFail();

    TranslationStore::flush();

    $ingredients = (string) $product->t('ingredients', 'ar');
    $howToUse = (string) $product->t('how_to_use', 'ar');

    expect($ingredients)->toContain('ماء')
        ->and($ingredients)->not->toContain('<script')
        ->and($ingredients)->not->toContain('alert(1)')
        ->and($howToUse)->toContain('يوضع')
        ->and($howToUse)->not->toContain('onerror');
});

/* ══════════════ 2. the map at volume ══════════════ */

it('serves a translated catalogue with no queries per row, at catalogue scale', function () {
    /*
     * The foundation drove 24 products and asserted zero queries. 24 rows
     * cannot tell an array lookup from a cache that happens to be big enough,
     * so this drives 400 and reads two fields off each — and it reads them
     * AFTER the map is warm, which is the state every page render is in by the
     * time it reaches a product card.
     *
     * This is the fixture half of the measurement. The numbers that decide the
     * design — 3,121 rows, +4.0 MB resident, +20–40 ms of wall clock on every
     * Arabic page — are in docs/fn-translation-at-scale.md, taken on MySQL at
     * 696 products with one operating-system process per page, because a
     * SQLite fixture cannot answer a memory question about the live host.
     */
    fnArabicOn();

    $ids = fnProducts(400);

    $now = now();
    $rows = [];

    foreach ($ids as $id) {
        foreach (['name' => 'اسم', 'short_description' => 'وصف'] as $field => $value) {
            $rows[] = [
                'locale' => 'ar', 'group' => 'products', 'item_id' => $id, 'field' => $field,
                'value' => $value.' '.$id, 'status' => Translation::STATUS_PUBLISHED,
                'source' => Translation::SOURCE_MANUAL, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('translations')->insert($chunk);
    }

    TranslationStore::flush();
    app()->setLocale('ar');

    $products = Product::query()->orderBy('id')->get();

    TranslationStore::map('ar');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $seen = 0;

    foreach ($products as $product) {
        expect($product->t('name'))->toStartWith('اسم');
        expect($product->t('short_description'))->toStartWith('وصف');
        $seen++;
    }

    expect($seen)->toBe(400)
        ->and(DB::getQueryLog())->toHaveCount(0);

    DB::disableQueryLog();
});

/* ══════════════ 3. the accelerator at volume ══════════════ */

it('batches a run of hundreds of fields at the provider\'s own limit', function () {
    fnArabicOn();

    // 120 products × 2 machine-safe fields = 240 outstanding fields, which is
    // three batches at Google's 100-per-request limit.
    fnProducts(120);

    $provider = fnRecordingProvider();
    $result = (new MachineTranslationRunner($provider))->run('ar', 240, 'products');

    expect($result['requested'])->toBe(240)
        ->and($result['translated'])->toBe(240)
        ->and(array_map('count', $provider->batches))->toBe([
            GoogleProvider::MAX_PER_REQUEST,
            GoogleProvider::MAX_PER_REQUEST,
            40,
        ]);

    // Every row a draft, every row marked machine. Nothing is published by a
    // run, whatever its size.
    expect(Translation::query()->where('status', Translation::STATUS_DRAFT)->count())->toBe(240)
        ->and(Translation::query()->where('status', Translation::STATUS_PUBLISHED)->count())->toBe(0)
        ->and(Translation::query()->where('source', Translation::SOURCE_MACHINE)->count())->toBe(240);
});

it('keeps 240 machine drafts away from every shopper', function () {
    fnArabicOn();
    fnProducts(120);

    (new MachineTranslationRunner(fnRecordingProvider()))->run('ar', 240, 'products');

    TranslationStore::flush();
    app()->setLocale('ar');

    expect(TranslationStore::map('ar'))->toBe([]);

    foreach (Product::query()->orderBy('id')->take(20)->get() as $product) {
        expect($product->t('name'))->toBe($product->name)
            ->and($product->hasTranslation('name'))->toBeFalse();
    }
});

it('counts a run\'s drafts separately from work that is actually done', function () {
    /*
     * The progress screen's whole job is answering "is the Arabic finished?".
     * A machine run writes 240 rows; none of them is finished, because none of
     * them has been read. A screen that counted them as translated would report
     * a shop as done the moment it was paid for.
     */
    fnArabicOn();
    fnProducts(120);

    (new MachineTranslationRunner(fnRecordingProvider()))->run('ar', 240, 'products');

    $products = TranslationEstimate::progress('ar')['areas']['products'];

    expect($products['drafts'])->toBe(240)
        ->and($products['translated'])->toBe(0)
        ->and($products['percent'])->toBe(0);
});

it('resumes where an interrupted run stopped, and re-sends nothing it already bought', function () {
    fnArabicOn();
    fnProducts(120);

    // The provider dies on its second batch — a timeout, a quota, a 500. The
    // first batch is already written and already paid for and must survive.
    $failing = fnRecordingProvider(function (array $texts, int $call): array {
        if ($call === 2) {
            throw new \RuntimeException('Google Cloud Translation refused the request (503)');
        }

        return array_map(static fn (string $t): string => 'AR::'.$t, $texts);
    });

    $first = (new MachineTranslationRunner($failing))->run('ar', 240, 'products');

    expect($first['errors'])->toHaveCount(1)
        ->and($first['translated'])->toBe(140)
        ->and($first['skipped'])->toBe(100);

    // The resume. Only the hundred that were lost are outstanding, and the
    // hundred and forty already bought are never sent a second time.
    $second = fnRecordingProvider();
    $result = (new MachineTranslationRunner($second))->run('ar', 240, 'products');

    expect($result['requested'])->toBe(100)
        ->and($result['translated'])->toBe(100)
        ->and(Translation::query()->count())->toBe(240);

    $resent = array_merge(...$second->batches);

    expect($resent)->toHaveCount(100);

    foreach ($resent as $text) {
        expect(Translation::query()->where('source_hash', sha1($text))->where('value', 'like', 'AR::%')->count())
            ->toBe(1, 'a field was translated twice: '.$text);
    }
});

/* ══════════════ 4. the short batch ══════════════ */

it('refuses a batch the provider answered with the wrong number of rows', function () {
    /*
     * THE FAILURE THE FOUNDATION NAMED AND NOBODY HAD DRIVEN.
     *
     * The runner pairs answers to requests BY INDEX: $results[$i] against
     * $batch[$i]. GoogleProvider upholds that contract by padding the gaps with
     * null in place, and its header says why in capitals. Nothing enforced it
     * on the other side — so a provider that returned a COMPACTED array, which
     * is what a naive array_filter or a second implementation of this interface
     * produces, slid every answer one place up the batch and wrote each
     * product's Arabic copy onto the next product's row.
     *
     * Nothing about the result would have looked wrong: 99 drafts written, no
     * errors, the money spent, and 99 products carrying somebody else's name in
     * Arabic — published one Approve later.
     *
     * A count that does not match is a broken contract, and a broken contract
     * means NO row in that batch can be trusted, not just the missing one. So
     * the whole batch is dropped and reported as an error.
     */
    fnArabicOn();
    fnProducts(50);

    $shifting = fnRecordingProvider(static function (array $texts): array {
        // One answer missing, and the array closed up over the gap — exactly
        // what the runner cannot see and must not trust.
        $out = array_map(static fn (string $t): string => 'AR::'.$t, $texts);
        array_splice($out, 3, 1);

        return $out;
    });

    $result = (new MachineTranslationRunner($shifting))->run('ar', 100, 'products');

    expect($result['translated'])->toBe(0)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toContain('99')
        ->and(Translation::query()->count())->toBe(0);
});

it('accepts a batch whose gaps the provider left in place', function () {
    /*
     * The other side of the same rule, or it would be a rule against a provider
     * doing the right thing. Google answers an index it could not translate
     * with null AT THAT INDEX. Count preserved, order preserved: the gap is
     * skipped and every other row is written against the right product.
     */
    fnArabicOn();
    $ids = fnProducts(20);

    $gapped = fnRecordingProvider(static function (array $texts): array {
        $out = array_map(static fn (string $t): string => 'AR::'.$t, $texts);
        $out[3] = null;

        return $out;
    });

    $result = (new MachineTranslationRunner($gapped))->run('ar', 40, 'products');

    expect($result['requested'])->toBe(40)
        ->and($result['translated'])->toBe(39)
        ->and($result['errors'])->toBe([]);

    foreach (Translation::query()->get() as $row) {
        $english = Product::query()->findOrFail($row->item_id)->getAttribute($row->field);

        expect($row->value)->toBe('AR::'.$english, 'row '.$row->item_id.'.'.$row->field.' carries another row\'s copy')
            ->and($row->source_hash)->toBe(sha1((string) $english));
    }

    expect($ids)->toHaveCount(20);
});

it('pads a short answer from the real provider rather than closing the gap', function () {
    /*
     * Driven through the REAL GoogleProvider with the HTTP layer faked, because
     * the padding is the provider's promise and a fake that reproduced it would
     * be testing itself. No key of anybody's is used and no request leaves the
     * process.
     */
    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'data' => ['translations' => [
                ['translatedText' => 'واحد'],
                ['translatedText' => 'اثنان'],
            ]],
        ]),
    ]);

    $out = (new GoogleProvider('fn-not-a-real-key'))->translate(['One', 'Two', 'Three', 'Four'], 'en', 'ar');

    expect($out)->toBe(['واحد', 'اثنان', null, null]);

    Http::assertSentCount(1);
});

/* ══════════════ 5. the estimate and the spend ══════════════ */

it('quotes the run the number of characters the provider is actually handed', function () {
    /*
     * "Nothing is ever sent until you have seen a number" is only true if the
     * number is the one that gets spent. The estimate endpoint counts the
     * pending set with mb_strlen; the provider bills for the source text it is
     * handed. These are the same characters or the screen is fiction.
     */
    fnArabicOn();
    fnProducts(60);

    $runner = new MachineTranslationRunner(fnRecordingProvider());

    $quoted = array_sum(array_map(
        static fn (array $slot): int => mb_strlen($slot['english'], 'UTF-8'),
        $runner->pending('ar', 120, 'products'),
    ));

    $provider = fnRecordingProvider();
    $result = (new MachineTranslationRunner($provider))->run('ar', 120, 'products');

    expect($quoted)->toBeGreaterThan(0)
        ->and($provider->characters)->toBe($quoted)
        ->and($result['characters'])->toBe($quoted);
});

it('reports what the run spent, not only what it managed to store', function () {
    /*
     * THE RECEIPT HAS TO BE THE BILL.
     *
     * `characters` was accumulated inside the write loop, so a row the provider
     * answered badly — or a whole batch it refused after reading the request —
     * left its characters out of the figure. The owner was shown a run that had
     * "used" 4,000 characters of his monthly allowance while Google had
     * counted 12,000, and the next estimate he read was wrong by the
     * difference. Google bills for text it was SENT.
     */
    fnArabicOn();
    fnProducts(75);

    // 150 fields: the first batch answered, the second refused outright.
    $provider = fnRecordingProvider(function (array $texts, int $call): array {
        if ($call === 2) {
            throw new \RuntimeException('quota exceeded');
        }

        return array_map(static fn (string $t): string => 'AR::'.$t, $texts);
    });

    $result = (new MachineTranslationRunner($provider))->run('ar', 150, 'products');

    expect($result['translated'])->toBe(100)
        ->and($result['characters_sent'])->toBe($provider->characters)
        ->and($result['characters_sent'])->toBeGreaterThan($result['characters']);
});
