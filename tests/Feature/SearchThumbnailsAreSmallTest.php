<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Support\Facades\File;

/*
 * The owner, 8 October: "why the search box results uses original size of the
 * product. and it's loading super slow, when i chkd the image source, it's full
 * size. it should be super tiny."
 *
 * /api/search handed search.js `$p->image` -- the original, 70-470 KB -- for a
 * thumbnail drawn at about 48-64px. It now hands the 200px img-cache copy, and
 * the original only when no copy exists, so a picture is never lost.
 *
 * MUTATION: put `'image' => $p->image,` back in SearchController -> red.
 */
beforeEach(function () {
    $this->rel = 'wp-content/uploads/2099/01/stt-serum-'.uniqid().'.webp';
    File::ensureDirectoryExists(dirname(public_path($this->rel)));
    file_put_contents(public_path($this->rel), 'original');
});

afterEach(function () {
    @unlink(public_path($this->rel));
    @unlink(public_path('img-cache/200/'.$this->rel));
});

function sttProduct(string $image): Product
{
    return Product::create([
        'name' => 'Thumbtest Glow Serum', 'slug' => 'stt-'.uniqid(), 'price' => 5000,
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        'type' => 'simple', 'image' => $image,
    ]);
}

it('sends the 200px copy for a search thumbnail, never the original', function () {
    $copy = public_path('img-cache/200/'.$this->rel);
    File::ensureDirectoryExists(dirname($copy));
    file_put_contents($copy, 'small');

    sttProduct('/'.$this->rel);

    $json = json_encode($this->getJson('/api/search?q=Thumbtest')->assertOk()->json(), JSON_UNESCAPED_SLASHES);

    expect($json)->toContain('/img-cache/200/'.$this->rel)
        ->and($json)->not->toContain('"/'.$this->rel.'"');
});

it('keeps the original when no small copy exists, so nothing breaks', function () {
    sttProduct('/'.$this->rel);

    $json = json_encode($this->getJson('/api/search?q=Thumbtest')->assertOk()->json(), JSON_UNESCAPED_SLASHES);

    expect($json)->toContain('"/'.$this->rel.'"')
        ->and($json)->not->toContain('/img-cache/200/'.$this->rel);
});
