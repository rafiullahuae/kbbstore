<?php

declare(strict_types=1);

/**
 * "SKIN&amp;LAB - Vitamin C Brightening Serum" ON A PRODUCT CARD. Lane AMP.
 *
 * The owner's grid: one card read "SKIN&amp;LAB - Vitamin C Brightening Serum",
 * the card beside it "SKIN&LAB - Barrierderm Intensive Cream 50ml". The page
 * source said `SKIN&amp;amp;LAB`. "The '&' mostly coming like this in most of
 * the places."
 *
 * ROOT CAUSE, TWO OF THEM:
 *
 *   1. DATA. WordPress stores post_title (and comment_author, display_name,
 *      term names) HTML-encoded; the exporter hands it over as stored and
 *      ProductImporter / PostImporter / ReviewImporter / TagImporter / ...
 *      copied it, so `products.name` held "SKIN&amp;LAB". Every template
 *      escapes once, as it should, and the stored entity became a visible one.
 *      The second card had been saved in the admin, which stores plain text.
 *
 *   2. THE <title>. `@section('title', $x)` escapes $x inside Blade and
 *      layouts/store.blade.php handed that HTML to Support\Seo, which escapes
 *      it again -- so the brand page of a brand stored PLAIN ("SKIN&LAB")
 *      still published <title>SKIN&amp;amp;LAB</title>, and so did every
 *      category and content page with an "&" or an apostrophe in its title.
 *
 * FIXED AT THE SOURCE: the importers store plain text (App\Support\PlainText),
 * 2027_10_10_090000 decodes the rows already imported -- once, allowlisted
 * plain-text columns only, with an undo record -- and the layout reads its
 * title section as text. Escaping is untouched everywhere: a decoded `<` is
 * printed `&lt;`, never raw.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Review;
use App\Models\Tag;
use App\Models\Translation;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Support\PlainText;
use Illuminate\Support\Facades\DB;

function ampMigration(): object
{
    return require base_path('database/migrations/2027_10_10_090000_decode_html_entities_in_plain_text.php');
}

/** A brand stored plain (Lane FP already decoded brands) and the owner's two products, as imported. */
function ampShop(string $encodedName = 'SKIN&amp;LAB - Vitamin C Brightening Serum'): array
{
    $brand = Brand::create(['name' => 'SKIN&LAB', 'slug' => 'amp-skinlab']);
    $cat = Category::create(['name' => 'Serums', 'slug' => 'amp-serums', 'depth' => 0, 'position' => 0, 'path' => 'amp-serums']);

    $make = function (string $name, string $slug) use ($brand, $cat): Product {
        $p = Product::create([
            'name' => $name, 'slug' => $slug, 'brand_id' => $brand->id, 'category_id' => $cat->id,
            'price' => 7900, 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
            'type' => 'simple', 'short_description' => '<p>Brightens &amp; evens tone.</p>',
            'description' => '<p>Oil &amp; water, &lt;b&gt; as text.</p>',
        ]);
        $p->categories()->syncWithoutDetaching([$cat->id]);

        return $p;
    };

    $encoded = $make($encodedName, 'skin-amp-lab-vitamin-c-brightening-serum');
    $plain = $make('SKIN&LAB - Barrierderm Intensive Cream 50ml', 'skin-lab-barrierderm-intensive-cream-50ml');
    \App\Http\Controllers\Store\ShopController::flushSidebarCache();

    return [$brand, $encoded, $plain, $cat];
}

function ampBetween(string $html, string $open, string $close): string
{
    $at = strpos($html, $open);
    expect($at)->not->toBeFalse("no {$open} on the page");

    return substr($html, $at, strpos($html, $close, $at) - $at);
}

/** Every JSON-LD block on the page, joined. */
function ampLd(string $html): string
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return implode("\n", $m[1]);
}

it('shows "SKIN&LAB" on the card, the product page h1, breadcrumb, <title>, og:title and JSON-LD', function () {
    /*
     * The defect, pinned first, exactly as the owner saw it.
     * MUTATION, RUN: make PlainText::decode() return $text unchanged -> red:
     * the migration fixes nothing and every assertion below reads &amp;amp;.
     */
    [$brand, $encoded] = ampShop();

    $before = (string) $this->get('/brands/amp-skinlab/')->assertOk()->getContent();
    expect($before)->toContain('SKIN&amp;amp;LAB - Vitamin C Brightening Serum');

    ampMigration()->up();

    // The card, on the brand page and on the category page.
    foreach (['/brands/amp-skinlab/', '/product-category/amp-serums/'] as $url) {
        $html = (string) $this->followingRedirects()->get($url)->assertOk()->getContent();
        expect($html)->toContain('SKIN&amp;LAB - Vitamin C Brightening Serum')
            ->and($html)->toContain('SKIN&amp;LAB - Barrierderm Intensive Cream 50ml')
            ->and($html)->not->toContain('&amp;amp;')
            ->and(ampLd($html))->not->toContain('\\u0026amp;');
    }

    // The brand page's own <title> and og:title -- the layout half of the fix.
    // MUTATION, RUN: take html_entity_decode() back out of $kbbRawTitle in
    // layouts/store.blade.php -> red here: <title>SKIN&amp;amp;LAB.
    $brandHtml = (string) $this->get('/brands/amp-skinlab/')->getContent();
    expect(ampBetween($brandHtml, '<title>', '</title>'))->toStartWith('<title>SKIN&amp;LAB')
        ->and($brandHtml)->toMatch('#<meta property="og:title" content="SKIN&amp;LAB[^"]*">#');

    // The product page.
    $html = (string) $this->get('/product/skin-amp-lab-vitamin-c-brightening-serum/')->assertOk()->getContent();
    expect(ampBetween($html, '<h1', '</h1>'))->toContain('SKIN&amp;LAB - Vitamin C Brightening Serum')
        ->and(ampBetween($html, '<div class="crumb">', '</div>'))->toContain('SKIN&amp;LAB - Vitamin C Brightening Serum')
        ->and(ampBetween($html, '<title>', '</title>'))->toContain('SKIN&amp;LAB - Vitamin C Brightening Serum')
        ->and($html)->toMatch('#<meta property="og:title" content="SKIN&amp;LAB - Vitamin C Brightening Serum#')
        ->and(ampLd($html))->toContain('SKIN\\u0026LAB - Vitamin C Brightening Serum')
        ->and($html)->not->toContain('&amp;amp;')
        ->and(ampLd($html))->not->toContain('\\u0026amp;');

    // The URL did not move.
    expect($encoded->fresh()->slug)->toBe('skin-amp-lab-vitamin-c-brightening-serum');
});

it('shows "SKIN&LAB" in the order emails and invoice, from an order line stored as Woo stored it', function () {
    /*
     * The order line keeps its imported bytes -- history is not rewritten --
     * and reads as text. MUTATION, RUN: delete OrderItem::name() -> red: the
     * HTML part reads SKIN&amp;amp;LAB and the text part SKIN&amp;LAB.
     */
    $order = Order::create([
        'order_number' => 'KBB-AMP-1', 'email' => 'amp@kbb.test', 'status' => 'processing', 'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => 'Marina', 'city' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 7900, 'shipping_total' => 0, 'tax_total' => 0, 'total' => 7900,
        'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    ]);
    $order->items()->create([
        'name' => 'SKIN&amp;LAB - Vitamin C Brightening Serum', 'brand' => 'SKIN&amp;LAB', 'sku' => 'SL-VC',
        'quantity' => 1, 'unit_price' => 7900, 'subtotal' => 7900, 'total' => 7900,
    ]);

    $item = $order->fresh('items')->items->first();
    expect(DB::table('order_items')->where('id', $item->id)->value('name'))->toBe('SKIN&amp;LAB - Vitamin C Brightening Serum')
        ->and($item->name)->toBe('SKIN&LAB - Vitamin C Brightening Serum')
        ->and($item->brand)->toBe('SKIN&LAB')
        ->and($item->isDirty())->toBeFalse();

    $mail = new \App\Mail\OrderConfirmation($order->fresh('items'));
    $html = (string) $mail->render();
    $content = $mail->content();
    $text = (string) view($content->text, array_merge($mail->buildViewData(), $content->with))->render();

    expect($html)->toContain('SKIN&amp;LAB - Vitamin C Brightening Serum')
        ->and($html)->not->toContain('&amp;amp;')
        ->and($text)->toContain('SKIN&LAB - Vitamin C Brightening Serum')
        ->and($text)->not->toContain('&amp;')
        ->and((string) $mail->envelope()->subject)->not->toContain('&amp;');

    $invoice = (string) view('invoices.invoice', [
        'doc' => app(\App\Services\Invoices\InvoiceDocument::class)->present($order->fresh('items')),
        'packingSlipUrl' => '#', 'deliveryNoteUrl' => '#', 'labelUrl' => '#', 'toolbarHint' => '', 'toolbarButton' => '',
    ])->render();
    expect($invoice)->toContain('SKIN&amp;LAB - Vitamin C Brightening Serum')->and($invoice)->not->toContain('&amp;amp;');
});

it('keeps a decoded <script> escaped on every surface', function () {
    /*
     * Decoding puts a real `<` in the column, which is only safe because nothing
     * prints a name unescaped. This is that promise, kept.
     * MUTATION, RUN: print $name with {!! !!} in the product page h1 -> red.
     */
    ampShop('&lt;script&gt;alert(1)&lt;/script&gt; SKIN&amp;LAB Serum');
    ampMigration()->up();

    expect(Product::where('slug', 'skin-amp-lab-vitamin-c-brightening-serum')->value('name'))
        ->toBe('<script>alert(1)</script> SKIN&LAB Serum');

    foreach (['/product/skin-amp-lab-vitamin-c-brightening-serum/', '/brands/amp-skinlab/', '/product-category/amp-serums/'] as $url) {
        $html = (string) $this->followingRedirects()->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('<script>alert(1)')
            ->and($html)->not->toContain('<\/script> SKIN')
            ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; SKIN&amp;LAB Serum');
    }

    // The search API is JSON for search.js, which builds its rows with
    // escapeHtml(); the response is JSON, nosniff, and carries the text as text.
    $search = $this->getJson('/api/search?q=SKIN')->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(json_encode($search->json()))->toContain('alert(1)');
    expect((string) file_get_contents(resource_path('js/kbb/search.js')))->toContain('${escapeHtml(it.label)}');
});

it('decodes the allowlisted plain-text columns once, leaves rich HTML and slugs alone, and can be undone', function () {
    /*
     * MUTATION, RUN: add 'description' to COLUMNS['products'] -> red: the rich
     * column's `&lt;b&gt;` becomes a live tag. Drop the "already recorded" skip
     * in decodeColumn() -> red: the second up() turns "&amp;amp;" into "&".
     */
    [$brand, $encoded, $plain, $cat] = ampShop('SKIN&amp;LAB &#8211; Vitamin C &#8220;Glow&#8221; &amp;amp; more');
    $encoded->forceFill(['seo' => ['title' => 'SKIN&amp;LAB serum', 'desc' => 'Bright &amp; even', 'canonical' => 'https://x.test/?a=1&amp;b=2']])->saveQuietly();
    $stamp = $encoded->fresh()->updated_at;

    $tag = Tag::create(['name' => 'K&amp;Beauty', 'slug' => 'k-amp-beauty']);
    $post = Post::create(['title' => 'Toners &#8211; what&#8217;s new', 'slug' => 'amp-toners', 'body' => '<p>Oil &amp; water</p>', 'author' => 'Rafi &amp; team', 'status' => 'published']);
    $page = Page::create(['title' => 'Terms &amp; Conditions', 'slug' => 'amp-terms', 'content' => '<p>&amp;</p>', 'status' => 'published']);
    $review = Review::create(['product_id' => $plain->id, 'author_name' => 'Sara &amp; Mona', 'title' => 'Love &#8217;it', 'content' => 'Glow &amp; calm', 'rating' => 5, 'status' => 'approved']);
    $ar = Translation::create(['locale' => 'ar', 'group' => 'products', 'item_id' => $encoded->id, 'field' => 'name', 'value' => 'سكين &amp; لاب', 'status' => 'published']);
    $arRich = Translation::create(['locale' => 'ar', 'group' => 'products', 'item_id' => $encoded->id, 'field' => 'description', 'value' => '<p>&lt;b&gt;</p>', 'status' => 'published']);
    $typed = Product::where('id', $plain->id)->value('name');

    $m = ampMigration();
    $m->up();

    expect($encoded->fresh()->name)->toBe('SKIN&LAB – Vitamin C “Glow” &amp; more')
        ->and($encoded->fresh()->slug)->toBe('skin-amp-lab-vitamin-c-brightening-serum')
        ->and($encoded->fresh()->description)->toBe('<p>Oil &amp; water, &lt;b&gt; as text.</p>')
        ->and($encoded->fresh()->short_description)->toBe('<p>Brightens &amp; evens tone.</p>')
        ->and($encoded->fresh()->seo)->toBe(['title' => 'SKIN&LAB serum', 'desc' => 'Bright & even', 'canonical' => 'https://x.test/?a=1&amp;b=2'])
        ->and($encoded->fresh()->updated_at?->toIso8601String())->toBe($stamp?->toIso8601String())
        ->and(Product::where('id', $plain->id)->value('name'))->toBe($typed)
        ->and($tag->fresh()->only(['name', 'slug']))->toBe(['name' => 'K&Beauty', 'slug' => 'k-amp-beauty'])
        ->and($post->fresh()->only(['title', 'author', 'body']))->toBe(['title' => 'Toners – what’s new', 'author' => 'Rafi & team', 'body' => '<p>Oil &amp; water</p>'])
        ->and($page->fresh()->title)->toBe('Terms &amp; Conditions')
        ->and($review->fresh()->only(['author_name', 'title', 'content']))->toBe(['author_name' => 'Sara & Mona', 'title' => 'Love ’it', 'content' => 'Glow & calm'])
        ->and($ar->fresh()->value)->toBe('سكين & لاب')
        ->and($arRich->fresh()->value)->toBe('<p>&lt;b&gt;</p>');

    // Once: a second run leaves the "&amp;" the first run produced.
    $m->up();
    expect($encoded->fresh()->name)->toBe('SKIN&LAB – Vitamin C “Glow” &amp; more')
        ->and(DB::table('entity_decode_undo')->where('table_name', 'products')->where('column_name', 'name')->count())->toBe(1);

    // And back: every value nobody edited since is restored; an edit made since wins.
    $tag->update(['name' => 'K-Beauty (edited)']);
    $m->down();

    expect($encoded->fresh()->name)->toBe('SKIN&amp;LAB &#8211; Vitamin C &#8220;Glow&#8221; &amp;amp; more')
        ->and($encoded->fresh()->seo['title'])->toBe('SKIN&amp;LAB serum')
        ->and($post->fresh()->title)->toBe('Toners &#8211; what&#8217;s new')
        ->and($review->fresh()->author_name)->toBe('Sara &amp; Mona')
        ->and($ar->fresh()->value)->toBe('سكين &amp; لاب')
        ->and($tag->fresh()->name)->toBe('K-Beauty (edited)')
        ->and(DB::table('entity_decode_undo')->count())->toBe(1);
});

it('decodes "Terms &amp; Conditions" once in a content page <title>, and leaves its HTML heading alone', function () {
    /*
     * pages.title is HTML and printed raw in the <h1>; the <title> read it as
     * text and published `Terms &amp;amp;amp; Conditions` (PageTitle's header).
     * MUTATION, RUN: put strip_tags() back in store/page.blade.php -> red:
     * `Terms &amp;amp; Conditions`.
     */
    Page::updateOrCreate(['slug' => 'terms-and-conditions'], ['title' => 'Terms &amp; Conditions', 'status' => 'published']);

    $html = (string) $this->get('/terms-and-conditions/')->assertOk()->getContent();

    expect(ampBetween($html, '<title>', '</title>'))->toStartWith('<title>Terms &amp; Conditions')
        ->and($html)->toContain('Terms &amp; Conditions</h1>')
        ->and($html)->not->toContain('&amp;amp;');
});

it('imports a WordPress title, author and label as plain text, keeps every slug, and leaves order lines as Woo stored them', function () {
    /*
     * The other half of the source: a re-import must not bring it back.
     * MUTATION, RUN: drop PlainText::decode() from ProductImporter -> red on the
     * product; from PostImporter / TagImporter / ReviewImporter /
     * MenuItemImporter / AttributeImporter / CustomerImporter / YoastSeo -> red
     * on that row.
     */
    $dir = storage_path('framework/testing/amp-import-'.getmypid().'-'.bin2hex(random_bytes(3)));
    @mkdir($dir, 0775, true);

    foreach (glob(base_path('tests/Fixtures/kbb-export').'/*') ?: [] as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    // Rewrite cells in place, keyed by column name; the manifest is kept honest.
    $edit = function (string $file, string $keyColumn, string $key, array $cells) use ($dir): void {
        $h = fopen($dir.'/'.$file, 'rb');
        $header = fgetcsv($h, null, ',', '"', '');
        $rows = [];
        while (($r = fgetcsv($h, null, ',', '"', '')) !== false) {
            $row = array_combine($header, $r);
            if ($row[$keyColumn] === $key) {
                $row = array_merge($row, $cells);
            }
            $rows[] = $row;
        }
        fclose($h);
        $h = fopen($dir.'/'.$file, 'wb');
        fputcsv($h, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($h, array_values($row), ',', '"', '');
        }
        fclose($h);

        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        $bytes = (string) file_get_contents($dir.'/'.$file);
        $manifest['files'][$file] = ['rows' => count($rows), 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    };

    $edit('products.csv', 'id', '4021', ['name' => 'SKIN&amp;LAB &#8211; Ginseng Serum']);
    $edit('tags.csv', 'term_id', '601', ['name' => 'K&amp;Beauty']);
    $edit('posts.csv', 'id', '7001', ['title' => 'How to layer &#8211; it&#8217;s easy', 'author_name' => 'Rafi &amp; team']);
    $edit('reviews.csv', 'comment_id', '8101', ['author' => 'Layla &amp; Noor', 'content' => 'Cleared &amp; calm']);
    $edit('menu_items.csv', 'id', '7501', ['label' => 'Skin &amp; Care']);
    $edit('attributes.csv', 'term_id', '701', ['name' => '50ml &#8211; mini', 'attribute_label' => 'Size &amp; Volume']);
    $edit('customers.csv', 'user_id', '1', ['name' => 'Rafi &amp; Co']);
    $edit('seo.csv', 'id', '4021', ['_yoast_wpseo_title' => 'SKIN&amp;LAB %%sep%% %%sitename%%', '_yoast_wpseo_metadesc' => 'Ginseng &amp; glow']);

    $orderItem = null;
    $h = fopen($dir.'/order_items.csv', 'rb');
    $oh = fgetcsv($h, null, ',', '"', '');
    $first = array_combine($oh, (array) fgetcsv($h, null, ',', '"', ''));
    fclose($h);
    $itemKey = 'item_id';
    $edit('order_items.csv', $itemKey, $first[$itemKey], ['name' => 'SKIN&amp;LAB &#8211; Ginseng Serum']);

    try {
        (new ImportRunner)->run(new ImportOptions(directory: $dir, runKey: 'amp-'.bin2hex(random_bytes(4))));
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    $product = Product::withTrashed()->where('wc_id', 4021)->first();
    expect($product?->only(['name', 'slug']))->toBe(['name' => 'SKIN&LAB – Ginseng Serum', 'slug' => 'serum-4021'])
        ->and($product?->seo['title'] ?? null)->toBe('SKIN&LAB %%sep%% %%sitename%%')
        ->and($product?->seo['desc'] ?? null)->toBe('Ginseng & glow')
        ->and(Tag::where('source_term_id', 601)->first()?->only(['name', 'slug']))->toBe(['name' => 'K&Beauty', 'slug' => 'k-beauty'])
        ->and(Post::where('source_post_id', 7001)->first()?->only(['title', 'author', 'slug']))->toBe(['title' => 'How to layer – it’s easy', 'author' => 'Rafi & team', 'slug' => 'how-to-layer-a-k-beauty-routine'])
        ->and(Review::where('source_id', 8101)->first()?->only(['author_name', 'content']))->toBe(['author_name' => 'Layla & Noor', 'content' => 'Cleared & calm'])
        ->and(DB::table('menu_items')->where('source_post_id', 7501)->value('label'))->toBe('Skin & Care')
        ->and(DB::table('attribute_values')->where('source_term_id', 701)->value('name'))->toBe('50ml – mini')
        ->and(DB::table('attributes')->where('slug', 'size')->value('name'))->toBe('Size & Volume')
        ->and(DB::table('customers')->where('wp_user_id', 1)->value('name'))->toBe('Rafi & Co');

    // The order line: the snapshot as Woo stored it, read as text.
    $line = OrderItem::where('wc_item_id', (int) $first[$itemKey])->first();
    expect($line)->not->toBeNull()
        ->and(DB::table('order_items')->where('id', $line->id)->value('name'))->toBe('SKIN&amp;LAB &#8211; Ginseng Serum')
        ->and($line->name)->toBe('SKIN&LAB – Ginseng Serum');
});

it('decodes only well-formed references, once', function () {
    expect(PlainText::decode('SKIN&amp;LAB'))->toBe('SKIN&LAB')
        ->and(PlainText::decode('A&#038;B &#8211; it&#8217;s &quot;q&quot; &#x27;x&#x27;'))->toBe('A&B – it’s "q" \'x\'')
        ->and(PlainText::decode('&amp;amp;'))->toBe('&amp;')
        ->and(PlainText::decode('AT&T, Fish &chips; and a &amp b'))->toBe('AT&T, Fish &chips; and a &amp b')
        ->and(PlainText::decode('a&#0;b&#1;c&#xD800;'))->toBe('a&#0;b&#1;c&#xD800;')
        ->and(PlainText::decode('سكين &amp; لاب'))->toBe('سكين & لاب')
        ->and(PlainText::decode(null))->toBeNull()
        ->and(PlainText::encoded('Bath & Body'))->toBeFalse()
        ->and(PlainText::encoded('Bath &amp; Body'))->toBeTrue();
});
