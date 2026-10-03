<?php

declare(strict_types=1);

/**
 * WhatsApp's own first fetch of a product link gets the JPEG, not the WebP.
 *                                                        (Integrator, 2.60.364)
 *
 * THE DEFECT, ON THE LIVE SHOP: "when share the product, it picks short
 * description and title. but no image." The JPEG share card was made AFTER the
 * first response for a product, and the first request is usually WhatsApp's
 * link-preview fetcher itself. It was handed og:image = the WebP original, which
 * it drops, and it cached that bare preview for the URL. Measured on the
 * preview before the fix: fetch 1 → fino.webp, fetch 2 → fino.webp.jpg.
 *
 * MUTATION NOTE, RUN: delete the `self::isPreviewFetcher()` branch in
 * ShareImage::forPage() → the WhatsApp case is RED (og:image ends in .webp).
 */

use App\Models\Product;
use App\Support\ShareImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

function sffWebp(): string
{
    $rel = 'media/products/sff-'.Str::lower(Str::random(6)).'.webp';
    $path = public_path($rel);
    @mkdir(\dirname($path), 0755, true);
    $im = imagecreatetruecolor(900, 900);
    imagefilledrectangle($im, 0, 0, 900, 900, (int) imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 350, 120, 550, 820, (int) imagecolorallocate($im, 230, 90, 140));
    imagewebp($im, $path, 90);
    imagedestroy($im);

    return '/'.$rel;
}

function sffOgImage(Product $p, string $ua): ?string
{
    $html = (string) test()->withHeaders(['User-Agent' => $ua])->get('/product/'.$p->slug.'/')->assertOk()->getContent();

    return preg_match('#<meta property="og:image" content="([^"]*)">#', $html, $m) ? $m[1] : null;
}

function sffProduct(string $image): Product
{
    return Product::create([
        'slug' => 'sff-'.Str::lower(Str::random(8)), 'name' => 'Fino Premium Touch Hair Mask',
        'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'price' => 4500,
        'stock_status' => 'instock', 'short_description' => '<p>Mask.</p>', 'image' => $image,
    ]);
}

it('hands a link-preview fetcher the JPEG card on its very first fetch', function (string $ua) {
    $image = sffWebp();
    Cache::flush();

    try {
        expect(sffOgImage(sffProduct($image), $ua))->toEndWith('/'.ShareImage::DIR.$image.'.jpg');
    } finally {
        @unlink(public_path(ltrim($image, '/')));
        @unlink(public_path(ShareImage::DIR.$image.'.jpg'));
    }
})->with([
    'WhatsApp' => 'WhatsApp/2.24.20.80 A',
    'Facebook / iMessage' => 'facebookexternalhit/1.1 Facebot Twitterbot/1.0',
    'Telegram' => 'TelegramBot (like TwitterBot)',
]);

it('still never makes a shopper wait for the encode', function () {
    $image = sffWebp();
    Cache::flush();

    try {
        $p = sffProduct($image);
        // A browser's first view publishes the original, as before; the copy follows the response.
        expect(sffOgImage($p, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1'))->toEndWith($image);
        expect(ShareImage::isPreviewFetcher())->toBeFalse();
    } finally {
        @unlink(public_path(ltrim($image, '/')));
        @unlink(public_path(ShareImage::DIR.$image.'.jpg'));
    }
});
