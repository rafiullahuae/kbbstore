<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Translation;
use App\Support\Locale;
use App\Support\TranslationInput;
use Tests\Support\BrandAdminRoutes;
use Tests\Support\CatalogAdminRoutes;
use Tests\Support\ProductEditorRoutes;
use Tests\Support\TranslationAdminRoutes;

/**
 * T4b — an Arabic box beside every field, in every editor.   (Lane EX)
 *
 * The owner's requirement, in his own words:
 *
 *     "for addition of everything, like products, posts etc. we should must
 *      have arabic place for every field, so we can enter manually"
 *
 * ── WHAT THESE TESTS ASSERT, AND WHY THEY DRIVE HTTP ──────────────────────
 *
 * Every one of them goes through the REAL endpoint the real screen posts to.
 * A test that called $product->saveTranslations() directly would pass against
 * a console that never sends the field — which is the whole failure this lane
 * exists to prevent, and which the foundation lane could not test because it
 * built the storage and not the editors.
 *
 * So the questions asked here are the ones with answers that cannot be
 * satisfied by accident:
 *
 *   - does ONE POST create a product and its Arabic name together;
 *   - does clearing the box DELETE the row, so that "untranslated" and
 *     "deliberately identical" stay different states;
 *   - does a stale browser tab sending a field that no longer exists get its
 *     product saved anyway, rather than a 422 over a dropped key;
 *   - does the prefill show a DRAFT, so "Approve" is not approving something
 *     invisible;
 *   - does every manual path work with NO API KEY AT ALL;
 *   - and does the progress figure — the number the owner plans 55 hours of
 *     work around — actually move when a box is filled in.
 */

/* ------------------------------------------------------------------ fixtures */

function axAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'AX Owner',
        'email' => 'ax-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function asAxAdmin(): void
{
    test()->actingAs(axAdmin(), 'admin');
}

function axArabicName(): string
{
    // "Anua Heartleaf Toner", in Arabic. Real script, not Latin in an ar row:
    // an RTL string is what the column, the editor and the page have to carry.
    return 'تونر أنوا بأوراق القلب';
}

function axProduct(array $attributes = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'name' => 'AX Product '.$n,
        'slug' => 'ax-product-'.$n.'-'.uniqid(),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
    ], $attributes));
}

/** The rows this model actually has in the translations table. */
function axRows(object $model, ?string $field = null)
{
    $q = Translation::query()
        ->where('group', $model->getTable())
        ->where('item_id', (int) $model->getKey());

    if ($field !== null) {
        $q->where('field', $field);
    }

    return $q->get();
}

/* ═══════════════ 1. one request creates the product AND its Arabic ═══════════════ */

it('creates a product and its Arabic name in the same request', function () {
    ProductEditorRoutes::wire(app());
    asAxAdmin();

    /*
     * THE REQUIREMENT, IN ONE ASSERTION. The Arabic travels with the ordinary
     * create payload — not to a second endpoint, not on a screen visited
     * afterwards. A product created here has its Arabic name the moment it
     * exists, which is what "at the moment of creation" means.
     */
    $response = test()->postJson('/admin-api/product-editor-create', [
        'name' => 'Anua Heartleaf Toner',
        'slug' => 'ax-anua-heartleaf-'.uniqid(),
        'status' => 'draft',
        'translations' => ['ar' => [
            'name' => axArabicName(),
            'short_description' => 'تونر لطيف للاستخدام اليومي',
        ]],
    ]);

    $response->assertStatus(201);

    $product = Product::query()->findOrFail($response->json('product.id'));

    expect($product->t('name', 'ar'))->toBe(axArabicName())
        ->and($product->hasTranslation('name', 'ar'))->toBeTrue();

    // Manual entry is PUBLISHED, not drafted. The owner typed it; making him
    // approve his own typing is ceremony, and ceremony gets skipped.
    $row = axRows($product, 'name')->first();
    expect($row->status)->toBe(Translation::STATUS_PUBLISHED)
        ->and($row->source)->toBe(Translation::SOURCE_MANUAL);

    // And the response hands the boxes back filled, so the screen that stays
    // open after a save is not showing an empty Arabic box for a saved value.
    expect($response->json('product.translations.ar.name.value'))->toBe(axArabicName());
});

it('offers an Arabic box on a blank create form, not only on a saved row', function () {
    ProductEditorRoutes::wire(app());
    asAxAdmin();

    /*
     * The create form has no row to prefill from, so the SHAPE has to come from
     * somewhere — and if it does not, the console can only translate products
     * that already exist, i.e. on a second visit. That is precisely the
     * "somewhere else, afterwards" the plan rules out.
     */
    $boot = test()->getJson('/admin-api/product-editor-bootstrap');

    $boot->assertOk();

    foreach ((new Product)->translatable() as $field) {
        expect($boot->json('translations.ar.'.$field))
            ->not->toBeNull();
    }
});

/* ═══════════════ 2. blank deletes the row ═══════════════ */

it('deletes the row when the Arabic box is cleared, and reports the field untranslated', function () {
    ProductEditorRoutes::wire(app());
    asAxAdmin();

    $product = axProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'name' => $product->name,
        'translations' => ['ar' => ['name' => axArabicName()]],
    ])->assertOk();

    expect(axRows($product, 'name'))->toHaveCount(1);

    // Now clear the box, which is what the owner does when he decides the
    // Arabic was wrong and has not written the replacement yet.
    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'name' => $product->name,
        'translations' => ['ar' => ['name' => '']],
    ])->assertOk();

    /*
     * THE ROW IS GONE, not stored as ''. This is the single assertion the whole
     * progress figure rests on: "how much is left" is "how many fields have no
     * row", and an empty string left behind would make an untranslated field
     * indistinguishable from a translated one and the percentage a guess.
     */
    expect(axRows($product, 'name'))->toHaveCount(0);

    $product->refresh();

    expect($product->hasTranslation('name', 'ar'))->toBeFalse()
        // …and the shop falls back to the English, so it keeps selling.
        ->and($product->t('name', 'ar'))->toBe($product->name);
});

it('tells "deliberately identical" apart from "nobody has reached it"', function () {
    BrandAdminRoutes::wire(app());
    asAxAdmin();

    // Two brands. One is typed in as the same text in both languages — Anua is
    // Anua — and the other is simply not translated yet.
    //
    // Looked up by the id the endpoint RETURNS, never by name: this database
    // is the demo catalogue and already contains brands called Anua and Beauty
    // of Joseon, so a lookup by name reads a row this test never created.
    $typedId = test()->postJson('/admin-api/brands', [
        'name' => 'Anua', 'slug' => 'ax-anua-'.uniqid(),
        'translations' => ['ar' => ['name' => 'Anua']],
    ])->assertStatus(201)->json('brand.id');

    $neverId = test()->postJson('/admin-api/brands', [
        'name' => 'Beauty of Joseon', 'slug' => 'ax-boj-'.uniqid(),
        'translations' => ['ar' => ['name' => '']],
    ])->assertStatus(201)->json('brand.id');

    $typed = Brand::query()->findOrFail($typedId);
    $never = Brand::query()->findOrFail($neverId);

    /*
     * Both render "Anua"/"Beauty of Joseon" to an Arabic shopper. The shop can
     * still tell them apart, and that is what makes the progress screen count
     * rather than claim.
     */
    expect($typed->hasTranslation('name', 'ar'))->toBeTrue()
        ->and($never->hasTranslation('name', 'ar'))->toBeFalse();
});

/* ═══════════════ 3. an unknown field or locale is dropped, never a 422 ═══════════════ */

it('drops a field outside the allowlist and an unknown locale without refusing the save', function () {
    ProductEditorRoutes::wire(app());
    asAxAdmin();

    $product = axProduct();

    /*
     * A STALE BROWSER TAB. `sku` was never translatable and `subtitle` is a
     * column that does not exist; `fr` is a language this shop does not run.
     * All three are dropped in silence.
     *
     * The alternative — a 422 — would refuse to save a product somebody spent
     * ten minutes on because of a key they cannot see and did not type. The
     * allowlist is the guard that matters, and it is doing its job here: none
     * of the three is written.
     */
    $response = test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'name' => 'Renamed in the same request',
        'translations' => [
            'ar' => ['name' => axArabicName(), 'sku' => 'لا', 'subtitle' => 'لا'],
            'fr' => ['name' => 'Toner Anua'],
        ],
    ]);

    $response->assertOk();

    $product->refresh();

    // The save itself went through, English and all.
    expect($product->name)->toBe('Renamed in the same request');

    // The one legitimate field landed…
    expect($product->t('name', 'ar'))->toBe(axArabicName());

    // …and nothing else did. Not the identifier, not the invented column, not
    // the language the shop does not run.
    expect(axRows($product)->pluck('field')->all())->toBe(['name'])
        ->and(axRows($product)->pluck('locale')->unique()->all())->toBe(['ar']);
});

it('refuses on the Arabic side exactly what the English side refuses', function () {
    ProductEditorRoutes::wire(app());
    asAxAdmin();

    $product = axProduct();

    // `name` is max:200 in English. It has to be max:200 in Arabic too, or the
    // Arabic box quietly accepts a value the column cannot hold — a 500 at
    // write time instead of a message at type time.
    $tooLong = str_repeat('ا', 201);

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'name' => $product->name,
        'translations' => ['ar' => ['name' => $tooLong]],
    ])->assertStatus(422)->assertJsonValidationErrors(['translations.ar.name']);

    // …while a long DESCRIPTION is fine, because the English description is
    // max:200000. The bound follows the field, not a flat number.
    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'name' => $product->name,
        'translations' => ['ar' => ['description' => str_repeat('ا', 70_000)]],
    ])->assertOk();

    expect(mb_strlen((string) $product->fresh()->t('description', 'ar')))->toBe(70_000);
});

it('sanitises the Arabic rich text with the same rule as the English', function () {
    ProductEditorRoutes::wire(app());
    asAxAdmin();

    $product = axProduct();

    /*
     * The storefront prints a product description with {!! !!}. It prints the
     * ARABIC description through the same template. A sanitiser applied to one
     * language only would be a stored-XSS hole opened by the act of adding the
     * second language — reachable from the ordinary product editor.
     */
    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'name' => $product->name,
        'description' => '<p>English</p><script>alert(1)</script>',
        'translations' => ['ar' => [
            'description' => '<p>عربي</p><script>alert(1)</script>',
        ]],
    ])->assertOk();

    $product->refresh();

    expect((string) $product->description)->not->toContain('<script')
        ->and((string) $product->t('description', 'ar'))->not->toContain('<script')
        ->and((string) $product->t('description', 'ar'))->toContain('عربي');
});

/* ═══════════════ 4. the prefill shows drafts ═══════════════ */

it('prefills the boxes with drafts as well as published rows', function () {
    ProductEditorRoutes::wire(app());
    asAxAdmin();

    $product = axProduct();

    // What a machine run leaves behind: a draft, invisible to shoppers, waiting
    // to be read and approved.
    $product->saveTranslations(
        ['ar' => ['name' => 'مسودة آلية']],
        Translation::STATUS_DRAFT,
        Translation::SOURCE_MACHINE,
    );

    $payload = test()->getJson('/admin-api/product-editor-load/'.$product->id);

    $payload->assertOk();

    /*
     * A DRAFT MUST APPEAR IN THE BOX. If the editor only showed published rows,
     * "Approve" would mean approving something the owner cannot see — and the
     * next ordinary save, reading an empty box, would DELETE the draft it never
     * showed him.
     */
    expect($payload->json('product.translations.ar.name.value'))->toBe('مسودة آلية')
        ->and($payload->json('product.translations.ar.name.status'))->toBe(Translation::STATUS_DRAFT)
        ->and($payload->json('product.translations.ar.name.source'))->toBe(Translation::SOURCE_MACHINE);

    // …and the shopper still sees the English, because a draft is not published.
    expect($product->fresh()->t('name', 'ar'))->toBe($product->name);
});

it('prefills every editor list in one query rather than one per row', function () {
    BrandAdminRoutes::wire(app());
    asAxAdmin();

    for ($i = 0; $i < 12; $i++) {
        $brand = Brand::create(['name' => 'AX Brand '.$i, 'slug' => 'ax-b-'.$i.'-'.uniqid()]);
        $brand->saveTranslations(['ar' => ['name' => 'علامة '.$i]]);
    }

    /*
     * The brands screen is one grouped query on purpose — the live catalogue
     * has ninety-three brands and a count per brand would be ninety-four
     * queries. Prefilling the Arabic boxes per row would have put that back.
     */
    $queries = 0;
    \Illuminate\Support\Facades\DB::listen(function () use (&$queries) { $queries++; });

    $response = test()->getJson('/admin-api/brands');

    $response->assertOk();

    // A query per brand would be thirteen here and ninety-four on the live
    // catalogue. The bound is deliberately loose: what is being pinned is the
    // SHAPE — constant, not proportional to the number of rows.
    expect($queries)->toBeLessThan(8);
    expect($response->json('brands.0.translations.ar.name.value'))->not->toBeNull();
});

/* ═══════════════ 5. no API key: every manual path still works ═══════════════ */

it('keeps every manual path working with no API key, and offers no Translate button', function () {
    ProductEditorRoutes::wire(app());
    CatalogAdminRoutes::wire(app());
    BrandAdminRoutes::wire(app());
    TranslationAdminRoutes::mount();

    asAxAdmin();

    // Nothing configured. No Google account, no key, no bill — the state the
    // shop is in today and the state most of the translation will happen in.
    expect(\App\Services\Translation\TranslationCredentials::hasKey())->toBeFalse();

    // A product, a category and a brand, each with its Arabic typed in by hand.
    test()->postJson('/admin-api/product-editor-create', [
        'name' => 'No key product', 'slug' => 'ax-nokey-'.uniqid(), 'status' => 'draft',
        'translations' => ['ar' => ['name' => axArabicName()]],
    ])->assertStatus(201);

    $category = test()->postJson('/admin-api/categories', [
        'name' => 'No key category', 'slug' => 'ax-nokey-cat-'.uniqid(),
        'description' => 'Everything that washes.',
        'translations' => ['ar' => ['name' => 'فئة', 'description' => 'وصف الفئة']],
    ])->assertStatus(201);

    $brand = test()->postJson('/admin-api/brands', [
        'name' => 'No key brand', 'slug' => 'ax-nokey-brand-'.uniqid(),
        'translations' => ['ar' => ['name' => 'علامة تجارية']],
    ])->assertStatus(201);

    expect(Category::query()->findOrFail($category->json('category.id'))->t('description', 'ar'))
        ->toBe('وصف الفئة');

    expect(Brand::query()->findOrFail($brand->json('brand.id'))->t('name', 'ar'))
        ->toBe('علامة تجارية');

    /*
     * And the button. The screen asks /translations/settings once and draws the
     * button only when a provider is actually connected; with nothing
     * configured has_api_key is false, the button stays `hidden`, and the
     * endpoint behind it says so in words rather than failing silently.
     */
    $settings = test()->getJson('/admin-api/translations/settings');

    $settings->assertOk();

    expect($settings->json('has_api_key'))->toBeFalse()
        ->and($settings->json('provider_available'))->toBeFalse();

    $pressed = test()->postJson('/admin-api/translations/machine/field', [
        'text' => 'Anua Heartleaf Toner', 'locale' => 'ar',
    ]);

    $pressed->assertStatus(409);

    // The refusal names the free path, because the free path is the one the
    // catalogue is actually going to be translated through.
    expect($pressed->json('message'))->toContain('by hand');
});

it('draws the Translate button hidden, so a build with no key never shows one', function () {
    /*
     * The markup half of the same rule, read off the shared helper itself. The
     * button is rendered with `hidden` and only un-hidden once
     * /translations/settings has answered that a provider is connected — the
     * other way round would flash a button that cannot work, which is worse
     * than none.
     */
    $helper = file_get_contents(resource_path('views/admin/partials/arabic-boxes.blade.php'));

    expect($helper)->toContain('data-kbbar-translate')
        ->and($helper)->toMatch('/class="kbbar-btn" hidden/');

    // And the reveal is gated on BOTH a saved key and a working provider.
    expect($helper)->toContain('d.has_api_key && d.provider_available');
});

/* ═══════════════ 6. the progress figure moves ═══════════════ */

it('moves the progress endpoint\'s count when a box is filled in', function () {
    ProductEditorRoutes::wire(app());
    TranslationAdminRoutes::mount();

    asAxAdmin();

    $product = axProduct(['short_description' => 'A gentle daily toner.']);

    $before = test()->getJson('/admin-api/translations/progress?locale=ar');
    $before->assertOk();

    $was = (int) $before->json('areas.products.translated');

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'name' => $product->name,
        'translations' => ['ar' => ['name' => axArabicName()]],
    ])->assertOk();

    $after = test()->getJson('/admin-api/translations/progress?locale=ar');

    /*
     * THE END-TO-END PROOF. The box in the product editor and the figure on the
     * Translation screen are the same store. If they were not — if the editor
     * wrote somewhere of its own — the owner would be told he had translated
     * nothing after a day of typing, which is how a shop ends up half done and
     * nobody can say which half.
     */
    expect((int) $after->json('areas.products.translated'))->toBe($was + 1);

    // …and it goes back down when the box is cleared, for the same reason.
    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'name' => $product->name,
        'translations' => ['ar' => ['name' => '']],
    ])->assertOk();

    expect((int) test()->getJson('/admin-api/translations/progress?locale=ar')
        ->json('areas.products.translated'))->toBe($was);
});

/* ═══════════════ every editor, not only the product one ═══════════════ */

it('carries the Arabic through the category editor in the same request', function () {
    CatalogAdminRoutes::wire(app());
    asAxAdmin();

    $created = test()->postJson('/admin-api/categories', [
        'name' => 'Cleansers', 'slug' => 'ax-cleansers-'.uniqid(),
        'description' => 'Everything that washes.',
        'translations' => ['ar' => ['name' => 'منظفات', 'description' => 'كل ما ينظف.']],
    ]);

    $created->assertStatus(201);

    // By the id the endpoint returns. The demo catalogue this suite migrates
    // already has a category called Cleansers, and a lookup by name would read
    // a row this test never wrote.
    $category = Category::query()->findOrFail($created->json('category.id'));

    expect($category->t('name', 'ar'))->toBe('منظفات')
        ->and($category->t('description', 'ar'))->toBe('كل ما ينظف.');

    // Editing keeps them, and clearing one removes only that one.
    test()->putJson('/admin-api/categories/'.$category->id, [
        'name' => 'Cleansers', 'slug' => $category->slug,
        'description' => 'Everything that washes.',
        'translations' => ['ar' => ['name' => 'منظفات', 'description' => '']],
    ])->assertOk();

    $category->refresh();

    expect($category->hasTranslation('name', 'ar'))->toBeTrue()
        ->and($category->hasTranslation('description', 'ar'))->toBeFalse();

    // The screen gets its boxes back filled, and gets the empty shape for the
    // Add dialog in the same response.
    $list = test()->getJson('/admin-api/categories');
    $list->assertOk();

    expect($list->json('translatable.ar'))->toHaveKeys(['name', 'description']);

    $row = collect($list->json('categories'))->firstWhere('id', $category->id);
    expect($row['translations']['ar']['name']['value'])->toBe('منظفات');
});

it('carries the Arabic through the brand editor in the same request', function () {
    BrandAdminRoutes::wire(app());
    asAxAdmin();

    $created = test()->postJson('/admin-api/brands', [
        'name' => 'Round Lab', 'slug' => 'ax-roundlab-'.uniqid(),
        'description' => 'Dokdo cleanser and friends.',
        'translations' => ['ar' => ['name' => 'راوند لاب', 'description' => 'منظف دوكدو وأصدقاؤه.']],
    ])->assertStatus(201);

    $brand = Brand::query()->findOrFail($created->json('brand.id'));

    expect($brand->t('name', 'ar'))->toBe('راوند لاب')
        ->and($brand->t('description', 'ar'))->toBe('منظف دوكدو وأصدقاؤه.');

    test()->putJson('/admin-api/brands/'.$brand->id, [
        'name' => 'Round Lab', 'slug' => $brand->slug,
        'translations' => ['ar' => ['name' => 'راوند لاب المحدثة']],
    ])->assertOk();

    expect($brand->fresh()->t('name', 'ar'))->toBe('راوند لاب المحدثة');
});

it('carries the Arabic through the menu item editor in the same request', function () {
    asAxAdmin();

    $menu = Menu::create([
        'name' => 'AX Menu',
        'slug' => 'ax-menu-'.uniqid(),
        'location' => 'ax-'.uniqid(),
    ]);

    $created = test()->postJson('/admin-api/mega-menu', [
        'menu_id' => $menu->id,
        'label' => 'Skincare',
        'url' => '/product-category/skincare/',
        'translations' => ['ar' => ['label' => 'العناية بالبشرة']],
    ]);

    $created->assertOk();

    $item = MenuItem::query()->findOrFail($created->json('id'));

    expect($item->t('label', 'ar'))->toBe('العناية بالبشرة');

    // And the screen's own load carries the prefill back down the tree.
    $tree = test()->getJson('/admin-api/mega-menu?menu_id='.$menu->id);
    $tree->assertOk();

    expect($tree->json('tree.0.translations.ar.label.value'))->toBe('العناية بالبشرة');

    // `url` is deliberately not translatable: one address per item, with the
    // language carried by the /ar prefix. A stale tab sending one is dropped.
    test()->postJson('/admin-api/mega-menu/'.$item->id, [
        'label' => 'Skincare',
        'translations' => ['ar' => ['label' => 'العناية بالبشرة', 'url' => '/ar/skincare/']],
    ])->assertOk();

    expect(axRows($item)->pluck('field')->all())->toBe(['label']);
});

/* ═══════════════ the helper itself ═══════════════ */

it('mirrors the English shape rules onto the Arabic ones without restating them', function () {
    $english = [
        'name' => ['required', 'string', 'max:200'],
        'description' => ['sometimes', 'nullable', 'string', 'max:200000'],
        'slug' => ['required', 'string', 'max:200', \Illuminate\Validation\Rule::unique('products', 'slug')],
    ];

    $rules = TranslationInput::rules(new Product, $english);

    // The bound follows the English field…
    expect($rules['translations.ar.name'])->toBe(['nullable', 'string', 'max:200'])
        ->and($rules['translations.ar.description'])->toBe(['nullable', 'string', 'max:200000']);

    // …required-ness never carries, because blank is how the shop says
    // "not translated yet".
    expect($rules['translations.ar.name'])->not->toContain('required');

    // A field that is not on the model's allowlist gets no rule of its own, and
    // `slug` is not on any allowlist at all — one slug per row.
    expect($rules)->not->toHaveKey('translations.ar.slug');

    // And the catch-all is never TIGHTER than the real fields, or a legitimate
    // 200,000-character Arabic description would fail against a rule meant for
    // keys that are thrown away.
    expect($rules['translations.*.*'])->toContain('max:200000');
});

it('never lets the Arabic boxes reach a field the shop refuses to translate', function () {
    /*
     * The same guard BilingualFoundationTest puts on the allowlists, asserted
     * here against the editors' own rules: whatever a screen posts, the rules
     * this lane generates can never name an identifier.
     */
    foreach ([new Product, new Category, new Brand, new MenuItem] as $model) {
        $rules = TranslationInput::rules($model, []);

        foreach (['sku', 'slug', 'code', 'order_number', 'currency', 'price', 'url'] as $forbidden) {
            expect($rules)->not->toHaveKey('translations.ar.'.$forbidden);
        }
    }
});

it('leaves stored translations alone when an editor sends no bag at all', function () {
    ProductEditorRoutes::wire(app());
    asAxAdmin();

    $product = axProduct();

    // Typed through the EDITOR, not through the model — a guard that seeds its
    // own fixture with saveTranslations() passes against a console that never
    // sends the field, which is the whole thing this lane exists to prevent.
    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'name' => $product->name,
        'translations' => ['ar' => ['name' => axArabicName()]],
    ])->assertOk();

    expect($product->fresh()->t('name', 'ar'))->toBe(axArabicName());

    /*
     * A PARTIAL SAVE MUST NOT BE A DELETE. Several screens re-post a subset of
     * a row's fields — the brands screen's banner dialog PUTs a category with
     * only the fields it edits. If an absent bag meant "everything is blank",
     * one of those would silently wipe every translation on the row.
     */
    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'featured' => true,
    ])->assertOk();

    expect($product->fresh()->t('name', 'ar'))->toBe(axArabicName());
});

it('marks the Arabic boxes right-to-left and names the language', function () {
    $helper = file_get_contents(resource_path('views/admin/partials/arabic-boxes.blade.php'));

    /*
     * What the owner types has to LOOK the way it will appear. A right-to-left
     * sentence typed into a left-to-right box has its punctuation in the wrong
     * place on screen and correct in the database, which is the worst way round
     * — it teaches him to "fix" text that was never broken.
     */
    expect($helper)->toContain('dir="rtl"')
        ->and($helper)->toContain('lang="' . Locale::LOCALES['ar']['segment'] . '"');

    foreach ([
        'views/admin/partials/product-editor-screen.blade.php',
        'views/admin/partials/category-tree-screen.blade.php',
        'views/admin/partials/brands-editor-screen.blade.php',
    ] as $screen) {
        expect(file_get_contents(resource_path($screen)))
            // Every editor screen this lane owns calls the shared helper. A
            // screen that stopped would draw no Arabic boxes at all, silently.
            ->toContain('KBBArabic');
    }
});
