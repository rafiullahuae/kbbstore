<?php

declare(strict_types=1);

use App\Services\Marketing\Blocks;
use App\Services\Marketing\CampaignRenderer;
use App\Services\Marketing\ProductFill;
use App\Services\Marketing\TemplateLibrary;
use Illuminate\Support\Facades\DB;
use Tests\Support\MarketingEmailsRoutes;
use Tests\Support\MarketingFixtures as F;

/**
 * Marketing Emails — the builder's blocks and the ready templates (Lane MK,
 * docs/EMAILS-PLAN.md §2.2 and §7 E3).
 *
 * A block list is the one thing the owner's browser sends that becomes an
 * email in thousands of inboxes, so every way it can carry something the shop
 * did not mean is refused here: a kind of block the builder does not have, an
 * email with no unsubscribe footer, a javascript: link, and markup in text.
 */

function mkClean(array $blocks, ?array &$errors = null, ?array &$warnings = null): array
{
    return Blocks::clean($blocks, $errors, $warnings);
}

function mkRender(array $blocks, array $ctx = []): string
{
    $r = app(CampaignRenderer::class);

    return $r->render($r->materialize(Blocks::clean($blocks)), $ctx + ['audience' => 'customers'])['html'];
}

it('refuses a block type the builder does not have, raw HTML included', function () {
    /*
     * The defect this stops: a `{"type":"html","props":{"html":"<script>…"}}`
     * posted straight to the endpoint, around the palette. There is no raw
     * HTML block, and an unknown type is an error, not a silently dropped row.
     *
     * MUTATION: in Blocks::clean(), `continue` past an unknown type without
     * adding to $errors and this goes red.
     */
    mkClean([['type' => 'html', 'props' => ['html' => '<script>alert(1)</script>']], Blocks::make('footer')], $errors);

    expect($errors)->not->toBe([])->and($errors[0])->toContain('is not a kind of block');
});

it('refuses an email with no footer, a footer that is not last, and a header that is not first', function () {
    /*
     * The footer carries the unsubscribe link and the postal address; an
     * email without it is one the shop may not send (plan §4).
     */
    mkClean([Blocks::make('heading', ['title' => 'Hi'])], $errors);
    expect(implode(' ', $errors))->toContain('ends with the footer');

    mkClean([Blocks::make('footer'), Blocks::make('text', ['body' => 'after'])], $errors);
    expect(implode(' ', $errors))->toContain('must be the last block');

    mkClean([Blocks::make('text', ['body' => 'x']), Blocks::make('mini_header'), Blocks::make('footer')], $errors);
    expect(implode(' ', $errors))->toContain('mini header can only be the first');

    mkClean([Blocks::make('mini_header'), Blocks::make('footer')], $errors);
    expect($errors)->toBe([]);
});

it('refuses a javascript: link anywhere a link can go', function () {
    /*
     * MUTATION: make Blocks::safeUrl() return its argument unchanged and
     * every one of these is accepted.
     */
    $bad = ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'java' . "\t" . 'script:alert(1)', 'data:text/html,<b>x</b>', '//evil.example/x', 'vbscript:x', ' javascript:x'];

    foreach ($bad as $url) {
        mkClean([Blocks::make('button', ['href' => $url]), Blocks::make('footer')], $errors);
        expect($errors)->not->toBe([], "button href {$url} was accepted");

        mkClean([Blocks::make('image', ['src' => $url]), Blocks::make('footer')], $errors);
        expect($errors)->not->toBe([], "image src {$url} was accepted");

        mkClean([Blocks::make('text', ['body' => "[click]({$url})"]), Blocks::make('footer')], $errors);
        expect($errors)->not->toBe([], "a text link to {$url} was accepted");
    }

    foreach (['https://extrabeauty.ae/shop/', 'mailto:info@kbeautybliss.com', '/super-sale/'] as $good) {
        mkClean([Blocks::make('button', ['href' => $good]), Blocks::make('footer')], $errors);
        expect($errors)->toBe([], "{$good} was refused");
    }

    // And a link that reached render some other way prints as plain text.
    $html = (string) Blocks::marks('[x](javascript:void)', fn ($u) => $u);
    expect($html)->toBe('x');
});

it('escapes a <script> in text, keeping only bold, italic and links', function () {
    /*
     * MUTATION: drop the e() in Blocks::inline() and the raw <script> reaches
     * the email.
     */
    $html = mkRender([
        Blocks::make('text', ['body' => "<script>alert(1)</script> **bold** *soft* [shop](/shop/) <img src=x onerror=alert(1)>"]),
        Blocks::make('footer'),
    ]);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<img src=x onerror')
        ->and($html)->toContain('<b>bold</b>')
        ->and($html)->toContain('<i>soft</i>')
        ->and($html)->toMatch('#<a href="https?://[^"]+/shop/" style="color:\#C13E63;text-decoration:underline;">shop</a>#');
});

it('stores a select only as one of its own options', function () {
    $b = mkClean([Blocks::make('heading', ['style' => 'evil', 'icon' => '"><script>', 'tone' => 'x']), Blocks::make('footer')]);

    expect($b[0]['props']['style'])->toBe('hero')->and($b[0]['props']['icon'])->toBe('spark')->and($b[0]['props']['tone'])->toBe('pink');
});

it('refuses ids that do not exist, and holds an incomplete choice as a warning a draft may keep', function () {
    mkClean([Blocks::make('product_grid', ['fill' => 'hand_picked', 'product_ids' => [999999]]), Blocks::make('footer')], $errors, $warnings);
    expect(implode(' ', $errors))->toContain('no longer exists');

    mkClean([Blocks::make('product_grid', ['fill' => 'brand']), Blocks::make('footer')], $errors, $warnings);
    expect($errors)->toBe([])->and(implode(' ', $warnings))->toContain('choose the brand');
});

it('fills product blocks at send time and drops sold-out and unpublished products', function () {
    /*
     * The defect: an email that advertises what the shop cannot sell. Product
     * blocks store how to choose, never names or prices, and both the choosing
     * and the reading go through visible() and inStock().
     *
     * MUTATION: remove ->inStock() from ProductFill::cards() and the sold-out
     * product reappears once it has been chosen.
     */
    $brand = F::brand('MK Anua ' . uniqid());
    $live = F::product('Heartleaf Toner', 89, ['brand_id' => $brand->id, 'total_sales' => 10]);
    $sold = F::product('Sold Out Serum', 99, ['brand_id' => $brand->id, 'total_sales' => 99, 'stock_status' => 'outofstock']);
    $draft = F::product('Draft Cream', 59, ['brand_id' => $brand->id, 'total_sales' => 80, 'status' => 'draft']);

    $ids = ProductFill::ids(['fill' => 'brand', 'brand_id' => $brand->id, 'count' => 4, 'order' => 'best_sellers']);
    expect($ids)->toBe([$live->id]);

    // Chosen by hand while live, then sold out before the email goes.
    $ids = ProductFill::ids(['fill' => 'hand_picked', 'product_ids' => [$live->id, $sold->id], 'count' => 4]);
    expect($ids)->toBe([$live->id]);
    $cards = ProductFill::cards([$live->id, $sold->id, $draft->id]);
    expect(array_keys($cards))->toBe([$live->id])
        ->and($cards[$live->id]['brand'])->toBe($brand->name)
        ->and($cards[$live->id]['price'])->toContain('89');
});

it('fills new arrivals, on sale (biggest saving first), under a price, best sellers and sets from the catalogue', function () {
    $old = F::product('Old', 40, ['total_sales' => 900000]);
    DB::table('products')->where('id', $old->id)->update(['created_at' => now()->subYear()]);
    $new = F::product('New', 120, ['total_sales' => 500000]);
    $saleSmall = F::product('Sale 10%', 100, ['sale_price' => 9000, 'total_sales' => 800000]);
    $saleBig = F::product('Sale 50%', 100, ['sale_price' => 5000, 'total_sales' => 700000]);
    $set = F::product('Glow set', 200, ['type' => 'set', 'total_sales' => 600000]);

    // The catalogue may hold other products (demo content); compare in the
    // order the fill chose, over these five only.
    $mine = [$old->id, $new->id, $saleSmall->id, $saleBig->id, $set->id];
    $only = fn (array $ids) => array_values(array_intersect($ids, $mine));

    expect($only(ProductFill::ids(['fill' => 'newest', 'count' => 8])))->not->toContain($old->id)
        ->and($only(ProductFill::ids(['fill' => 'on_sale', 'count' => 8])))->toBe([$saleBig->id, $saleSmall->id])
        ->and($only(ProductFill::ids(['fill' => 'under_price', 'max_price' => 54, 'count' => 8])))->toBe([$old->id, $saleBig->id])
        ->and($only(ProductFill::ids(['fill' => 'best_sellers', 'count' => 8, 'order' => 'best_sellers'])))->toBe([$old->id, $saleSmall->id, $saleBig->id, $set->id, $new->id])
        ->and($only(ProductFill::ids(['fill' => 'sets', 'count' => 8])))->toBe([$set->id]);
});

it('ships fourteen ready templates, each a valid email under 95 KB with the unsubscribe footer', function () {
    $keys = array_keys(TemplateLibrary::templates());

    // Lane EC added the last two: design C, "New look, less prices", English and Arabic.
    expect($keys)->toBe([
        'new-arrivals', 'weekly-special', 'best-sellers', 'under-54', 'super-sale', 'bundles-sets',
        'brand-spotlight', 'we-miss-you', 'brand-fans', 'autumn-glow', 'welcome', 'skincare-tips',
        'new-look', 'new-look-ar',
    ]);

    // Seeded by the migrations as read-only presets, once.
    expect(DB::table('mkt_templates')->where('preset', true)->whereIn('key', $keys)->count())->toBe(14);

    F::product('Glow serum', 70, ['total_sales' => 9]);

    foreach (TemplateLibrary::templates() as $key => $t) {
        mkClean($t['blocks'], $errors);
        expect($errors)->toBe([], "{$key}: " . implode(' ', $errors));

        $r = app(CampaignRenderer::class);
        $out = $r->render($r->materialize(Blocks::clean($t['blocks'])), [
            'audience' => 'customers', 'subject' => $t['subject'], 'unsubscribe' => 'https://extrabeauty.ae/email/u/1-' . str_repeat('a', 32),
            'theme' => $t['theme'] ?? 'standard', 'locale' => $t['locale'] ?? 'en',
        ]);

        expect($out['bytes'])->toBeLessThan(CampaignRenderer::MAX_BYTES, "{$key} is too big")
            ->and($out['html'])->toContain('/email/u/1-')
            ->and($out['html'])->toContain(($t['theme'] ?? 'standard') === 'playful' ? '/privacy-policy/' : e(__('email.kit.footer_terms')))
            ->and($out['text'])->toContain('/email/u/1-');
    }
});

it('renders the three approved campaigns with their approved words', function () {
    F::product('Birch Juice Sunscreen', 79, ['total_sales' => 9, 'brand_id' => F::brand('Round Lab')->id]);

    $words = [
        'autumn-glow' => ['/email/art/autumn-glow.jpg', 'your glow edit is here', 'Shop the Glow Edit'],
        'we-miss-you' => ['It has been a while', 'We saved you something', 'New since your last visit', 'Come back and shop'],
        'brand-fans' => ['Picked for you', 'More Medicube, just for you', 'Shop all Medicube'],
    ];

    foreach ($words as $key => $expect) {
        $t = TemplateLibrary::templates()[$key];
        $r = app(CampaignRenderer::class);
        $html = $r->render($r->materialize(Blocks::clean($t['blocks'])), [
            'audience' => 'customers', 'first_name' => 'Aisha', 'top_brand' => 'Medicube',
            'top_brand_url' => 'https://extrabeauty.ae/brands/medicube/',
        ])['html'];

        foreach ($expect as $w) {
            expect($html)->toContain($w);
        }
    }
});

it('previews the blocks it is sent with a marker per block, and measures them', function () {
    MarketingEmailsRoutes::wire(app());
    $this->actingAs(F::admin('manager'), 'admin');

    $r = $this->postJson('/admin-api/email-marketing/preview', [
        'blocks' => [Blocks::make('mini_header'), Blocks::make('text', ['body' => 'Hello **there**']), Blocks::make('footer')],
        'subject' => 'Hi',
    ])->assertOk();

    expect($r->json('html'))->toContain('<tbody data-mkb="1">')
        ->and($r->json('bytes'))->toBeGreaterThan(1000)
        ->and($r->json('max_bytes'))->toBe(95 * 1024);

    $this->postJson('/admin-api/email-marketing/campaigns', ['template_id' => DB::table('mkt_templates')->where('key', 'best-sellers')->value('id')])
        ->assertOk()->assertJsonPath('campaign.status', 'draft');

    $id = DB::table('mkt_campaigns')->max('id');
    $this->putJson("/admin-api/email-marketing/campaigns/{$id}", ['blocks' => [['type' => 'html', 'props' => []]]])->assertStatus(422);
    $this->putJson("/admin-api/email-marketing/campaigns/{$id}", ['subject' => "Hi\r\nBcc: x@y.z"])->assertStatus(422);
});
