<?php

declare(strict_types=1);

/**
 * A description picture that cannot be shown does not hold its space.
 *                                                    (Integrator, 2.60.364)
 *
 * THE DEFECT, ON THE LIVE SHOP (3 October 2026). "Space under the detail tab
 * row · laptop" at 0px, and still ~65px between the tab row and "Benefits:" on
 * Shiseido Fino, ~150px above "About brand". The importer rewrites a
 * description's pictures to this shop's /wp-content/uploads/..., and one whose
 * file was never copied across is a 404: the browser draws an empty box of its
 * width x height. trimLeadingBlank() keeps an <img>, so the gap stayed.
 *
 * MUTATIONS, RUN:
 *   - return false from RichText::pictureMissing() -- the first two cases red;
 *   - drop the dropMissingPictures() call in ProductController::tabs() -- the
 *     page case red;
 *   - take out the wrapper loop -- the first case red (an empty <p> stays).
 */

use App\Models\Product;
use App\Support\RichText;
use Illuminate\Support\Str;

function mdpReal(): string
{
    $dir = public_path('uploads/mdp-test');
    @mkdir($dir, 0775, true);
    $file = $dir.'/real.jpg';
    if (! is_file($file)) {
        file_put_contents($file, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
    }

    return '/uploads/mdp-test/real.jpg';
}

afterAll(function () {
    @unlink(public_path('uploads/mdp-test/real.jpg'));
    @rmdir(public_path('uploads/mdp-test'));
});

it('takes out a picture this shop does not have, with the paragraph it leaves empty', function () {
    $html = '<p><img src="/wp-content/uploads/2019/03/never-copied.jpg" alt="" width="800" height="60"></p>'
        ."\n<p><strong>Benefits:</strong></p>\n<p>Copy.</p>";

    expect(RichText::trimLeadingBlank(RichText::dropMissingPictures($html)))
        ->toBe("<p><strong>Benefits:</strong></p>\n<p>Copy.</p>");

    // In the middle too: the wrapper goes, the copy around it stays.
    $mid = '<p>One</p><p><a href="/x"><img src="/wp-content/uploads/gone.png" width="600" height="150"></a></p><p>Two</p>';
    expect(RichText::dropMissingPictures($mid))->toBe('<p>One</p><p>Two</p>');
});

it('keeps a picture that exists, a picture on another site, and copy with no picture byte for byte', function () {
    $real = mdpReal();
    $kept = '<p><img src="'.$real.'" alt="" width="10" height="10"></p><p>x</p>';
    expect(RichText::dropMissingPictures($kept))->toBe($kept);

    // Another site, not recorded as lost: this server cannot see it, so it stays.
    $remote = '<p><img src="https://cdn.example.com/a.jpg" alt=""></p><p>x</p>';
    expect(RichText::dropMissingPictures($remote))->toBe($remote);

    $plain = "<p>Plain <b>copy</b>.</p>\n";
    expect(RichText::dropMissingPictures($plain))->toBe($plain);
});

it('draws the product page tab without the missing picture, and stores nothing', function () {
    $raw = '<p><img src="/wp-content/uploads/2019/03/never-copied.jpg" alt="" width="800" height="60"></p>'
        ."\n<p><strong>Benefits:</strong></p>\n<p>Lightweight.</p>";
    $p = Product::create([
        'slug' => 'mdp-'.Str::lower(Str::random(8)), 'name' => 'Fino Premium Touch', 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 6500, 'stock_status' => 'instock',
        'short_description' => '<p>A hair mask.</p>', 'description' => $raw,
    ]);

    $html = (string) $this->get('/product/'.$p->slug.'/')->assertOk()->getContent();

    expect(substr_count($html, '<div class="dcontent clamp"><p><strong>Benefits:</strong></p>'))->toBe(1)
        ->and($html)->not->toContain('never-copied.jpg')
        ->and(Product::find($p->id)->description)->toBe($raw);
});
