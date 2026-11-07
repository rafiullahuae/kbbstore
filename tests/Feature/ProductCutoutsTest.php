<?php

declare(strict_types=1);

/**
 * App\Services\Cutouts\ProductCutouts -- the transparent product cut-outs for
 * the homepage occasion banner and the free-gifts strip (the owner, 6 Nov
 * 2026: "the trimming is not correctly, still white edges are there").
 */

use App\Services\Cutouts\ProductCutouts;

function pcoPhoto(bool $speck = true)
{
    // a red product with a soft (anti-aliased) rim on white, and a stray grey speck
    $im = imagecreatetruecolor(300, 300);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    for ($y = 40; $y < 260; $y++) {
        for ($x = 100; $x < 200; $x++) {
            imagesetpixel($im, $x, $y, imagecolorallocate($im, 200, 30, 60));
        }
    }
    // the rim a photo really has: half-white red
    for ($y = 40; $y < 260; $y++) {
        imagesetpixel($im, 99, $y, imagecolorallocate($im, 228, 142, 158));
        imagesetpixel($im, 200, $y, imagecolorallocate($im, 228, 142, 158));
    }
    if ($speck) {
        imagefilledrectangle($im, 260, 20, 261, 21, imagecolorallocate($im, 150, 150, 150));
    }

    return $im;
}

it('takes the white background away and leaves no pale rim on the product', function () {
    // Defect: rims of near-white pixels left round every product ("white
    // edges"). Mutation: drop the un-premultiply in cut() and the rim check is red.
    $out = ProductCutouts::cut(pcoPhoto(false), false, 246);
    expect($out)->not->toBeNull();
    $w = imagesx($out);
    $h = imagesy($out);
    $alpha = fn (int $x, int $y) => (imagecolorat($out, $x, $y) >> 24) & 127; // 127 = clear
    expect($w)->toBeLessThan(110)->and($h)->toBeLessThan(225)
        ->and($alpha(intdiv($w, 2), intdiv($h, 2)))->toBe(0);

    // every visible pixel is product-coloured, none of it the photo's white
    $pale = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($out, $x, $y);
            if ((($c >> 24) & 127) < 100 && min(($c >> 16) & 255, ($c >> 8) & 255, $c & 255) > 200) {
                $pale++;
            }
        }
    }
    expect($pale)->toBe(0);
});

it('drops a stray speck beside the product', function () {
    // Mutation: keep every piece (minKeep = 0) and the crop grows to reach the speck.
    $out = ProductCutouts::cut(pcoPhoto(true), false, 246);
    expect(imagesx($out))->toBeLessThan(110)->and(imagesy($out))->toBeLessThan(225);
});

it('returns nothing for a photo with no white background to take away', function () {
    $im = imagecreatetruecolor(200, 200);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
    expect(ProductCutouts::cut($im, false, 246))->toBeNull();
});

it('makes two sizes on request, reads them from the disk, and never takes a path outside the web root', function () {
    $dir = public_path('uploads/pco-test');
    @mkdir($dir, 0755, true);
    imagejpeg(pcoPhoto(), $dir . '/red.jpg', 92);

    expect(ProductCutouts::for('/uploads/pco-test/red.jpg'))->toBeNull();
    $r = ProductCutouts::make('/uploads/pco-test/red.jpg');
    expect($r['ok'])->toBeTrue();
    $c = ProductCutouts::for('/uploads/pco-test/red.jpg');
    expect($c['h'])->toBe(480)
        // ?v= the file's time (Lane CUT): the path stays when a cut-out is remade or hand-made
        ->and($c['src'])->toMatch('#/img-cache/cut/480/uploads/pco-test/red\.jpg\.webp\?v=\d+$#')
        ->and($c['srcset'])->toMatch('#/img-cache/cut/240/uploads/pco-test/red\.jpg\.webp\?v=\d+ #')->toContain(', ');

    ProductCutouts::forget('/uploads/pco-test/red.jpg');
    expect(ProductCutouts::for('/uploads/pco-test/red.jpg'))->toBeNull();
    @unlink($dir . '/red.jpg');
    @rmdir($dir);

    foreach (['/uploads/../.env', 'uploads/x.jpg', 'https://evil.example/x.jpg', '/img-cache/400/x.jpg', '/x.jpg?v=1', ''] as $bad) {
        expect(ProductCutouts::relative($bad))->toBeNull();
    }
    expect(ProductCutouts::make('/uploads/../.env')['ok'])->toBeFalse();
});
