<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\CategoryRollup;
use Illuminate\Support\Facades\Cache;

/**
 * A CATEGORY PAGE'S BREADCRUMB: Home / Shop / [parents /] the category. (Lane BC)
 *
 * The owner, 10 October: "on the categories pages, the breakcrums should say
 * like Home > Collecton > Skincare sets ... the collection will take to the
 * all products page." A top-level category read "Home / Category" -- a
 * placeholder word, and Home not even a link -- while the page's
 * BreadcrumbList told Google "Home -> Shop -> Skincare sets". Google asks for
 * the visible trail and the structured one to agree; now they do.
 */
beforeEach(function () {
    SettingsService::forgetMemo();
    Cache::flush();
    CategoryRollup::flush();
});

function bcCategory(string $slug, string $name, ?Category $parent = null): Category
{
    return Category::create(['name' => $name, 'slug' => $slug, 'path' => $slug, 'short_url' => true, 'parent_id' => $parent?->id]);
}

function bcStock(Category $c): void
{
    $p = Product::create(['slug' => 'bc-'.$c->slug, 'name' => 'BC '.$c->name, 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock']);
    $p->categories()->attach($c->id);
}

/** The visible trail, tags stripped, and the BreadcrumbList names. */
function bcTrails(string $html): array
{
    preg_match('#<nav class="crumb"[^>]*>(.*?)</nav>#s', $html, $m);
    $visible = trim(preg_replace('/\s+/', ' ', strip_tags($m[1] ?? '')));

    $names = [];
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $ld);
    foreach ($ld[1] as $json) {
        foreach ((array) (json_decode($json, true) ?: []) as $node) {
            $nodes = isset($node['@type']) ? [$node] : (is_array($node) ? $node : []);
            foreach ($nodes as $n) {
                if (is_array($n) && ($n['@type'] ?? null) === 'BreadcrumbList') {
                    $names = array_map(fn ($i) => $i['name'] ?? '', $n['itemListElement']);
                }
            }
        }
        $top = json_decode($json, true);
        if (is_array($top) && ($top['@type'] ?? null) === 'BreadcrumbList') {
            $names = array_map(fn ($i) => $i['name'] ?? '', $top['itemListElement']);
        }
        foreach ((array) ($top['@graph'] ?? []) as $n) {
            if (($n['@type'] ?? null) === 'BreadcrumbList') {
                $names = array_map(fn ($i) => $i['name'] ?? '', $n['itemListElement']);
            }
        }
    }

    return [$visible, $names];
}

it('reads Home / Shop / Skincare sets on a top-level category, Home and Shop as links, the page last', function () {
    /*
     * MUTATION, RUN: set 'crumbShop' back to null in ShopController and the
     * trail is "Home / Category" again -- red.
     */
    $sets = bcCategory('bc-skincare-sets', 'Skincare sets');
    bcStock($sets);

    $html = $this->get('/collections/bc-skincare-sets/')->assertOk()->getContent();
    [$visible, $names] = bcTrails($html);

    expect($visible)->toBe('Home / Shop / Skincare sets')
        ->and($html)->toContain('<a href="/"><b>Home</b></a> / <a href="/shop/">Shop</a> / <span aria-current="page">Skincare sets</span>')
        ->and($html)->not->toContain('/ Category</div>')
        // The trail Google is given says the same, step for step.
        ->and($names)->toBe(['Home', 'Shop', 'Skincare sets']);
});

it('runs through the parents on a sub-category, and still agrees with the BreadcrumbList', function () {
    $skin = bcCategory('bc-skincare', 'Skincare');
    $toners = bcCategory('bc-toners', 'Toners', $skin);
    bcStock($toners);

    [$visible, $names] = bcTrails($this->get($toners->url())->assertOk()->getContent());

    expect($visible)->toBe('Home / Shop / Skincare / Toners')
        ->and($names)->toBe(['Home', 'Shop', 'Skincare', 'Toners']);
});

it('leaves the whole-shop page and search with the trail they had', function () {
    bcStock(bcCategory('bc-any', 'Any'));

    expect($this->get('/shop/')->assertOk()->getContent())->toContain('<div class="crumb"><b>Home</b> / Shop</div>')
        ->and($this->get('/shop/?s=bc')->assertOk()->getContent())->toContain('<div class="crumb"><b>Home</b> / ');
});
