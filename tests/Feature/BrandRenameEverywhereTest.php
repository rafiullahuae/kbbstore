<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\Seo\BrandRename;
use Illuminate\Support\Facades\DB;

/*
 * Lane EB — the owner, 6 October: "remove the Extra Beauty word from whole site
 * from everywhere. please apply this auto in the next patch ... replace the
 * Extra Beauty with K-Beauty Bliss everywhere."
 *
 * DEFECT: Lane BR's rename (2027_08_09) covered settings and authored content,
 * and deliberately left product names and descriptions, image alt text,
 * category and brand copy, reviews and the checkout's labels — so the live
 * shop still said "Extra Beauty" on product, category and brand pages after
 * the package was applied. MUTATION: remove any one table from
 * BrandRename::TARGETS and its expectation below is red; drop index 4 from the
 * products target and the image_alts KEY (an image URL with a raw space)
 * becomes ".../K-Beauty Bliss Box.jpg" and the alt is orphaned.
 */

function ebMigration(): object
{
    return require base_path('database/migrations/2027_08_30_100000_rename_brand_everywhere.php');
}

function ebVal(string $table, int $id, string $col): ?string
{
    $v = DB::table($table)->where('id', $id)->value($col);

    return $v === null ? null : (string) $v;
}

/** One row in every NEW target, each saying the old name in a different spelling. */
function ebSeedTargets(): array
{
    $img = 'https://extrabeauty.ae/wp-content/uploads/Gift Extra Beauty Box.jpg';
    $ids = [];
    $ids['product'] = DB::table('products')->insertGetId([
        'slug' => 'eb-serum', 'name' => 'Extra Beauty Glow Serum', 'status' => 'publish', 'price' => 1000,
        'short_description' => 'Loved at ExtraBeauty',
        'description' => '<p>Only at Extra-Beauty. Visit <a href="https://extrabeauty.ae/shop">extrabeauty.ae</a> or write to info@extrabeauty.ae. Sold by Extra Beauty Trading LLC.</p>',
        'ingredients' => 'Water, EXTRA BEAUTY complex',
        'how_to_use' => 'Use as Extrabeauty recommends',
        'custom_tabs' => json_encode([['title' => 'Why Extra Beauty', 'content' => '<p>Extra Beauty picks</p>']]),
        'image_alts' => json_encode([$img => 'Extra Beauty serum bottle']),
    ]);
    $ids['variant'] = DB::table('product_variants')->insertGetId(['product_id' => $ids['product'], 'sku' => 'EB-1', 'price' => 1000, 'tag' => 'Extra Beauty pick']);
    $ids['tab'] = DB::table('product_tabs')->insertGetId(['title' => 'Extra Beauty promise', 'body' => '<p>From Extra Beauty</p>']);
    $ids['attribute'] = DB::table('attributes')->insertGetId(['slug' => 'eb-edition', 'name' => 'Extra Beauty edition']);
    $ids['attribute_value'] = DB::table('attribute_values')->insertGetId(['attribute_id' => $ids['attribute'], 'slug' => 'eb-set', 'name' => 'Extra Beauty set']);
    $ids['category'] = DB::table('categories')->insertGetId([
        'slug' => 'eb-cat', 'name' => 'Extra Beauty Picks', 'description' => '<p>Chosen by Extra Beauty</p>',
        'header_title' => 'Extra Beauty favourites', 'header_subtitle' => 'by Extra Beauty', 'header_description' => 'Extra Beauty says hi',
        'banner' => json_encode(['image' => '/uploads/Gift Extra Beauty Box.jpg', 'heading' => 'Extra Beauty edit', 'url' => '/c/Our Extra Beauty sale']),
    ]);
    $ids['brand'] = DB::table('brands')->insertGetId([
        'slug' => 'eb-brand', 'name' => 'Extra Beauty Lab', 'description' => '<p>Extra Beauty own label</p>',
        'header_title' => 'Extra Beauty Lab', 'header_layout' => json_encode(['subtitle' => 'Made for Extra Beauty', 'logo' => '/uploads/Our Extra Beauty Lab.png']),
    ]);
    $ids['media'] = DB::table('media')->insertGetId(['filename' => 'eb.jpg', 'path' => 'uploads/eb.jpg', 'alt' => 'Extra Beauty logo']);
    $ids['review'] = DB::table('reviews')->insertGetId(['product_id' => $ids['product'], 'author_name' => 'Extra Beauty Team', 'title' => 'Love Extra Beauty',
        'content' => 'Best purchase from Extra Beauty', 'reply' => 'Thank you — Extra Beauty', 'rating' => 5, 'status' => 'approved']);
    $ids['routine'] = DB::table('routines')->insertGetId(['concern' => 'eb', 'title' => 'The Extra Beauty routine', 'blurb' => 'Extra Beauty AM',
        'steps' => json_encode([['label' => 'Cleanse the Extra Beauty way', 'image' => '/uploads/Step Extra Beauty one.jpg']])]);
    $ids['spotted'] = DB::table('spotted_posts')->insertGetId(['image' => '/uploads/s.jpg', 'image_alt' => 'Extra Beauty haul', 'caption' => 'Spotted with Extra Beauty', 'handle' => '@extrabeauty']);
    $ids['instagram'] = DB::table('instagram_posts')->insertGetId(['remote_id' => 'eb1', 'caption' => 'New at Extra Beauty #extrabeauty']);
    $ids['clip'] = DB::table('ugc_videos')->insertGetId(['slug' => 'eb-clip', 'title' => 'Extra Beauty unboxing', 'caption' => 'My Extra Beauty order', 'status' => 'draft']);
    $ids['shipping'] = DB::table('shipping_methods')->insertGetId(['shipping_zone_id' => DB::table('shipping_zones')->insertGetId(['name' => 'Extra Beauty UAE']), 'type' => 'flat_rate',
        'title' => 'Extra Beauty Express', 'settings' => json_encode(['description' => 'Delivered by Extra Beauty'])]);
    $ids['zone'] = (int) DB::table('shipping_zones')->where('name', 'Extra Beauty UAE')->value('id');
    DB::table('payment_providers')->insert(['id' => 'eb_cod', 'title' => 'Pay Extra Beauty on delivery']);
    $ids['tax'] = DB::table('tax_rates')->insertGetId(['name' => 'Extra Beauty VAT', 'country' => 'AE', 'rate' => 5]);
    $ids['coupon'] = DB::table('coupons')->insertGetId(['code' => 'EB10', 'type' => 'percent', 'description' => 'Extra Beauty welcome']);
    $ids['push'] = DB::table('push_campaigns')->insertGetId(['title' => 'Extra Beauty sale', 'body' => 'Only at Extra Beauty', 'link_label' => 'Shop Extra Beauty', 'status' => 'draft']);
    $ids['push_sent'] = DB::table('push_campaigns')->insertGetId(['title' => 'Extra Beauty sale', 'body' => 'Sent from Extra Beauty', 'status' => 'sent']);
    $ids['grid'] = DB::table('grid_sections')->insertGetId(['name' => 'Extra Beauty grid', 'slug' => 'eb-grid', 'heading' => 'x', 'card_label' => 'Extra Beauty pick', 'view_all_label' => 'All Extra Beauty']);
    $ids['menu'] = DB::table('menus')->insertGetId(['slug' => 'eb-menu', 'name' => 'Main']);
    $ids['menu_item'] = DB::table('menu_items')->insertGetId(['menu_id' => $ids['menu'], 'label' => 'Shop', 'badge' => 'Extra Beauty', 'position' => 1]);
    $ids['post'] = DB::table('posts')->insertGetId(['slug' => 'eb-post', 'title' => 'Hello', 'body' => 'x', 'author' => 'Extra Beauty Team', 'status' => 'published']);
    $ids['tr_product'] = DB::table('translations')->insertGetId(['locale' => 'ar', 'group' => 'products', 'item_id' => $ids['product'], 'field' => 'name',
        'value' => 'سيروم إكسترا بيوتي', 'status' => 'published', 'source_hash' => sha1('Extra Beauty Glow Serum')]);
    $ids['tr_category'] = DB::table('translations')->insertGetId(['locale' => 'ar', 'group' => 'categories', 'item_id' => $ids['category'], 'field' => 'name',
        'value' => 'اختيارات اكسترا بيوتي', 'status' => 'published']);
    $ids['tr_brand'] = DB::table('translations')->insertGetId(['locale' => 'ar', 'group' => 'brands', 'item_id' => $ids['brand'], 'field' => 'description',
        'value' => 'علامة ايكسترا بيوتى', 'status' => 'published']);

    return $ids + ['img' => $img];
}

/** Records: every one of these must be byte-identical afterwards. */
function ebSeedRecords(): void
{
    $customer = DB::table('customers')->insertGetId(['email' => 'eb@example.test', 'name' => 'Extra Beauty Fan', 'notes' => 'Extra Beauty VIP']);
    $order = DB::table('orders')->insertGetId(['order_number' => 'EB-1', 'email' => 'eb@example.test', 'customer_id' => $customer,
        'customer_note' => 'Gift from Extra Beauty', 'payment_method_title' => 'Pay Extra Beauty on delivery', 'shipping_method' => 'Extra Beauty Express', 'status' => 'completed']);
    DB::table('order_items')->insert(['order_id' => $order, 'name' => 'Extra Beauty Glow Serum', 'brand' => 'Extra Beauty Lab', 'quantity' => 1, 'unit_price' => 1000, 'total' => 1000]);
    DB::table('mail_deliveries')->insert(['kind' => 'order', 'recipient' => 'eb@example.test', 'subject' => 'Your Extra Beauty order', 'status' => 'sent']);
    DB::table('import_history')->insert(['run_uid' => 'eb-r1', 'entity' => 'products', 'notes' => 'Extra Beauty export']);
    DB::table('search_terms')->insert(['term' => 'extra beauty', 'day' => '2026-10-01']);
}

function ebRecords(): string
{
    $out = [];
    foreach (['customers' => ['name', 'notes'], 'orders' => ['customer_note', 'payment_method_title', 'shipping_method'], 'order_items' => ['name', 'brand'],
        'mail_deliveries' => ['subject'], 'import_history' => ['notes'], 'search_terms' => ['term']] as $t => $cols) {
        $out[$t] = DB::table($t)->get($cols)->toArray();
    }
    $out['push_sent'] = DB::table('push_campaigns')->where('status', 'sent')->get(['title', 'body'])->toArray();

    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

it('replaces the old name in every new target, keeps addresses, handles and legal names, and leaves records alone', function () {
    $ids = ebSeedTargets();
    ebSeedRecords();
    $records = ebRecords();

    ob_start();
    ebMigration()->up();
    $printed = (string) ob_get_clean();

    $p = $ids['product'];
    $alts = json_decode((string) ebVal('products', $p, 'image_alts'), true);
    $banner = json_decode((string) ebVal('categories', $ids['category'], 'banner'), true);
    $layout = json_decode((string) ebVal('brands', $ids['brand'], 'header_layout'), true);
    $steps = json_decode((string) ebVal('routines', $ids['routine'], 'steps'), true);

    expect(ebVal('products', $p, 'name'))->toBe('K-Beauty Bliss Glow Serum')
        ->and(ebVal('products', $p, 'short_description'))->toBe('Loved at K-Beauty Bliss')
        ->and(ebVal('products', $p, 'ingredients'))->toBe('Water, K-BEAUTY BLISS complex')
        ->and(ebVal('products', $p, 'how_to_use'))->toBe('Use as K-Beauty Bliss recommends')
        // The name goes; the domain, the email and the legal entity stay.
        ->and(ebVal('products', $p, 'description'))->toBe('<p>Only at K-Beauty Bliss. Visit <a href="https://extrabeauty.ae/shop">extrabeauty.ae</a> or write to info@extrabeauty.ae. Sold by Extra Beauty Trading LLC.</p>')
        ->and(json_decode((string) ebVal('products', $p, 'custom_tabs'), true))->toBe([['title' => 'Why K-Beauty Bliss', 'content' => '<p>K-Beauty Bliss picks</p>']])
        // The KEY is an image URL with a raw space in it: it must not move.
        ->and($alts)->toBe([$ids['img'] => 'K-Beauty Bliss serum bottle'])
        ->and(ebVal('product_variants', $ids['variant'], 'tag'))->toBe('K-Beauty Bliss pick')
        ->and(ebVal('product_tabs', $ids['tab'], 'title'))->toBe('K-Beauty Bliss promise')
        ->and(ebVal('product_tabs', $ids['tab'], 'body'))->toBe('<p>From K-Beauty Bliss</p>')
        ->and(ebVal('attributes', $ids['attribute'], 'name'))->toBe('K-Beauty Bliss edition')
        ->and(ebVal('attribute_values', $ids['attribute_value'], 'name'))->toBe('K-Beauty Bliss set')
        ->and(ebVal('categories', $ids['category'], 'name'))->toBe('K-Beauty Bliss Picks')
        ->and(ebVal('categories', $ids['category'], 'description'))->toBe('<p>Chosen by K-Beauty Bliss</p>')
        ->and(ebVal('categories', $ids['category'], 'header_title'))->toBe('K-Beauty Bliss favourites')
        ->and(ebVal('categories', $ids['category'], 'header_subtitle'))->toBe('by K-Beauty Bliss')
        ->and(ebVal('categories', $ids['category'], 'header_description'))->toBe('K-Beauty Bliss says hi')
        ->and($banner)->toBe(['image' => '/uploads/Gift Extra Beauty Box.jpg', 'heading' => 'K-Beauty Bliss edit', 'url' => '/c/Our Extra Beauty sale'])
        ->and(ebVal('brands', $ids['brand'], 'name'))->toBe('K-Beauty Bliss Lab')
        ->and(ebVal('brands', $ids['brand'], 'description'))->toBe('<p>K-Beauty Bliss own label</p>')
        ->and(ebVal('brands', $ids['brand'], 'header_title'))->toBe('K-Beauty Bliss Lab')
        ->and($layout)->toBe(['subtitle' => 'Made for K-Beauty Bliss', 'logo' => '/uploads/Our Extra Beauty Lab.png'])
        ->and(ebVal('media', $ids['media'], 'alt'))->toBe('K-Beauty Bliss logo')
        ->and(ebVal('reviews', $ids['review'], 'author_name'))->toBe('K-Beauty Bliss Team')
        ->and(ebVal('reviews', $ids['review'], 'title'))->toBe('Love K-Beauty Bliss')
        ->and(ebVal('reviews', $ids['review'], 'content'))->toBe('Best purchase from K-Beauty Bliss')
        ->and(ebVal('reviews', $ids['review'], 'reply'))->toBe('Thank you — K-Beauty Bliss')
        ->and(ebVal('routines', $ids['routine'], 'title'))->toBe('The K-Beauty Bliss routine')
        ->and(ebVal('routines', $ids['routine'], 'blurb'))->toBe('K-Beauty Bliss AM')
        ->and($steps)->toBe([['label' => 'Cleanse the K-Beauty Bliss way', 'image' => '/uploads/Step Extra Beauty one.jpg']])
        ->and(ebVal('spotted_posts', $ids['spotted'], 'image_alt'))->toBe('K-Beauty Bliss haul')
        ->and(ebVal('spotted_posts', $ids['spotted'], 'caption'))->toBe('Spotted with K-Beauty Bliss')
        ->and(ebVal('spotted_posts', $ids['spotted'], 'handle'))->toBe('@extrabeauty')
        ->and(ebVal('instagram_posts', $ids['instagram'], 'caption'))->toBe('New at K-Beauty Bliss #extrabeauty')
        ->and(ebVal('ugc_videos', $ids['clip'], 'title'))->toBe('K-Beauty Bliss unboxing')
        ->and(ebVal('ugc_videos', $ids['clip'], 'caption'))->toBe('My K-Beauty Bliss order')
        ->and(ebVal('shipping_methods', $ids['shipping'], 'title'))->toBe('K-Beauty Bliss Express')
        ->and(json_decode((string) ebVal('shipping_methods', $ids['shipping'], 'settings'), true))->toBe(['description' => 'Delivered by K-Beauty Bliss'])
        ->and(ebVal('shipping_zones', $ids['zone'], 'name'))->toBe('K-Beauty Bliss UAE')
        ->and(DB::table('payment_providers')->where('id', 'eb_cod')->value('title'))->toBe('Pay K-Beauty Bliss on delivery')
        ->and(ebVal('tax_rates', $ids['tax'], 'name'))->toBe('K-Beauty Bliss VAT')
        ->and(ebVal('coupons', $ids['coupon'], 'description'))->toBe('K-Beauty Bliss welcome')
        ->and(ebVal('push_campaigns', $ids['push'], 'title'))->toBe('K-Beauty Bliss sale')
        ->and(ebVal('push_campaigns', $ids['push'], 'link_label'))->toBe('Shop K-Beauty Bliss')
        ->and(ebVal('grid_sections', $ids['grid'], 'name'))->toBe('K-Beauty Bliss grid')
        ->and(ebVal('grid_sections', $ids['grid'], 'card_label'))->toBe('K-Beauty Bliss pick')
        ->and(ebVal('grid_sections', $ids['grid'], 'view_all_label'))->toBe('All K-Beauty Bliss')
        ->and(ebVal('menu_items', $ids['menu_item'], 'badge'))->toBe('K-Beauty Bliss')
        ->and(ebVal('posts', $ids['post'], 'author'))->toBe('K-Beauty Bliss Team')
        // Arabic, every spelling (إكسترا / اكسترا / ايكسترا, بيوتي / بيوتى), in the catalogue's translations.
        ->and(ebVal('translations', $ids['tr_product'], 'value'))->toBe('سيروم K-Beauty Bliss')
        ->and(ebVal('translations', $ids['tr_category'], 'value'))->toBe('اختيارات K-Beauty Bliss')
        ->and(ebVal('translations', $ids['tr_brand'], 'value'))->toBe('علامة K-Beauty Bliss')
        // The Arabic name was current against the old English and still is.
        ->and(ebVal('translations', $ids['tr_product'], 'source_hash'))->toBe(sha1('K-Beauty Bliss Glow Serum'))
        // Records: orders, order items, customers, mail log, import history,
        // search history and a push already sent.
        ->and(ebRecords())->toBe($records)
        ->and($records)->toContain('Gift from Extra Beauty')->toContain('Your Extra Beauty order')->toContain('Sent from Extra Beauty')
        ->and($printed)->toContain('Extra Beauty -> K-Beauty Bliss everywhere:')->toContain(' in products')->toContain(' in reviews');
});

it('replaces nothing the second time, and never fails the update on a missing table or column', function () {
    // MUTATION: make BrandName::replace() emit "K-Beauty Bliss (Extra Beauty)"
    // and the second run counts again; drop the per-table try/catch in walk()
    // and the missing table throws out of the migration.
    ebSeedTargets();
    $first = BrandRename::apply();

    BrandRename::$targets = ['no_such_table' => ['id', ['name']], 'products' => ['id', ['no_such_column']]] + BrandRename::TARGETS;
    try {
        ob_start();
        ebMigration()->up();
        ob_end_clean();
        $second = BrandRename::apply();
    } finally {
        BrandRename::$targets = null;
    }

    expect($first['total'])->toBeGreaterThan(50)
        ->and($second['total'])->toBe(0)
        ->and($second['errors'])->toBe([])
        ->and(BrandRename::scan()['total'])->toBe(0);
});

it('lists every target on the check screen with its count, and shows 0 to replace after applying', function () {
    // MUTATION: drop 'areas' from BrandNameApiController::state() and the
    // screen has nothing to say "0" with.
    \Tests\Support\SeoKeywordsRoutes::wire(app());
    ebSeedTargets();
    $owner = AdminUser::create(['name' => 'EB owner', 'email' => 'eb-owner@example.test', 'password' => 'secret-pass-123', 'role' => 'owner']);

    $before = $this->actingAs($owner, 'admin')->getJson('/admin-api/seo-brand')->assertOk()->json('scan');
    $areas = collect($before['areas'])->keyBy('table');

    expect($areas->keys()->all())->toBe(array_keys(BrandRename::TARGETS))
        ->and($areas['products']['found'])->toBe(8)
        ->and($areas['reviews']['found'])->toBe(4)
        ->and($areas['products']['left'])->toBeGreaterThan(0)   // the domain, the email, the legal name
        ->and($areas['products']['label'])->toBe(BrandRename::AREAS['products']);

    $this->actingAs($owner, 'admin')->postJson('/admin-api/seo-brand/replace', ['expect' => $before['total']])->assertOk();

    $after = $this->actingAs($owner, 'admin')->getJson('/admin-api/seo-brand')->assertOk()->json('scan');
    expect($after['total'])->toBe(0)
        ->and(collect($after['areas'])->sum('found'))->toBe(0);

    $src = (string) file_get_contents(resource_path('views/admin/partials/seo-keywords-screen.blade.php'));
    expect(substr_count($src, 'Every area checked'))->toBe(1)
        ->and($src)->toContain("esc(a.label)");
});

it('names a label for every target and keeps records off the list', function () {
    expect(array_diff(array_keys(BrandRename::TARGETS), array_keys(BrandRename::AREAS)))->toBe([])
        ->and(array_intersect(array_keys(BrandRename::TARGETS), ['orders', 'order_items', 'customers', 'payments', 'refunds', 'mail_deliveries',
            'mail_web_copies', 'import_history', 'import_runs', 'search_terms', 'seo_keywords', 'push_sends', 'audit_events', 'quiz_submissions']))->toBe([]);
});

it('costs the same number of queries to check with 3 products as with 40', function () {
    // MUTATION: look a product's translations up per row inside walk()'s scan
    // path and the 40-product count is higher. (A write restamps per changed
    // row on purpose — once, at apply time — never on the check.)
    $seed = static function (int $from, int $to): void {
        foreach (range($from, $to) as $i) {
            DB::table('products')->insert(['slug' => 'eb-flat-'.$i, 'name' => 'Extra Beauty pick '.$i, 'description' => '<p>Extra Beauty</p>', 'status' => 'publish', 'price' => 100]);
        }
    };
    $count = static function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        BrandRename::scan();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $seed(1, 3);
    $three = $count();
    $seed(4, 40);
    $forty = $count();

    expect($forty)->toBe($three)->and(BrandRename::scan()['total'])->toBe(80);
});
