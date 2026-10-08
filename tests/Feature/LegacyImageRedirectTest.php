<?php

declare(strict_types=1);

/*
 * Old picture addresses, sent to the picture's file as it is today (Lane SEO).
 *
 * WHAT THE SHOP LOOKED LIKE WITHOUT IT: Google Images holds
 * kbeautybliss.com/wp-content/uploads/… for this catalogue, and the shop is
 * moving onto that domain. The import keeps original paths, but WooCommerce's
 * pages showed RESIZED copies (`serum-600x600.jpg`), WordPress 5.3+ keeps
 * `-scaled` originals, and WebP conversion can remove a JPEG -- every one of
 * those answered the shop's 404 page, and Google Images drops a 404.
 *
 * Each test writes real files under public_path() (a throwaway directory of
 * its own) and requests the real address through the kernel.
 */

use App\Support\LegacyImageRedirect;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->webroot = storage_path('framework/testing/lir-'.getmypid().'-'.uniqid());
    @mkdir($this->webroot, 0775, true);
    app()->usePublicPath($this->webroot);
});

afterEach(function () {
    if (is_dir($this->webroot)) {
        exec('rm -rf '.escapeshellarg($this->webroot));
    }
});

function lirFile(string $relative): void
{
    $path = public_path($relative);
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, 'x');
}

function lirConverted(string $from, string $to, string $status = 'removed'): void
{
    DB::table('webp_conversions')->insert([
        'from_path' => $from, 'to_path' => $to, 'origin' => 'bulk', 'status' => $status,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('sends a WordPress resized copy to the original the import brought', function () {
    lirFile('wp-content/uploads/2021/05/serum.jpg');

    // MUTATION: remove the SIZED candidate in LegacyImageRedirect::candidates()
    // and this is the 404 page again.
    test()->get('/wp-content/uploads/2021/05/serum-600x600.jpg')
        ->assertStatus(301)
        ->assertHeader('Location', 'http://localhost/wp-content/uploads/2021/05/serum.jpg');
});

it('finds a -scaled original for the unscaled name and for its sized copies', function () {
    lirFile('wp-content/uploads/2022/01/cream-scaled.jpg');

    test()->get('/wp-content/uploads/2022/01/cream.jpg')
        ->assertStatus(301)->assertHeader('Location', 'http://localhost/wp-content/uploads/2022/01/cream-scaled.jpg');
    test()->get('/wp-content/uploads/2022/01/cream-300x300.jpg')
        ->assertStatus(301)->assertHeader('Location', 'http://localhost/wp-content/uploads/2022/01/cream-scaled.jpg');
});

it('follows a JPEG converted to WebP and removed, including its sized copies', function () {
    lirFile('wp-content/uploads/2020/02/toner.webp');
    lirConverted('wp-content/uploads/2020/02/toner.jpg', 'wp-content/uploads/2020/02/toner.webp');

    // MUTATION: make movedTo() return null and both are 404s.
    test()->get('/wp-content/uploads/2020/02/toner.jpg')
        ->assertStatus(301)->assertHeader('Location', 'http://localhost/wp-content/uploads/2020/02/toner.webp');
    test()->get('/wp-content/uploads/2020/02/toner-768x768.jpg')
        ->assertStatus(301)->assertHeader('Location', 'http://localhost/wp-content/uploads/2020/02/toner.webp');
});

it('answers in ONE hop to the file that exists now, however many times it moved', function () {
    // jpg -> webp -> a later move of that webp (the shape a rename ledger adds).
    lirConverted('wp-content/uploads/2020/03/a.jpg', 'wp-content/uploads/2020/03/a.webp');
    lirConverted('wp-content/uploads/2020/03/a.webp', 'wp-content/uploads/2020/03/a-renamed.webp');
    lirFile('wp-content/uploads/2020/03/a-renamed.webp');

    // Never the intermediate a.webp, which is no longer on disk.
    // MUTATION: return $next instead of looping in current() and this
    // answers a.webp -- a second hop, and a 404 at the end of it.
    expect(LegacyImageRedirect::targetFor('wp-content/uploads/2020/03/a.jpg'))->toBe('wp-content/uploads/2020/03/a-renamed.webp');
});

it('never hands out an address with no file behind it, and refuses what is not a picture', function () {
    lirConverted('wp-content/uploads/2020/04/gone.jpg', 'wp-content/uploads/2020/04/gone.webp');

    expect(LegacyImageRedirect::targetFor('wp-content/uploads/2020/04/gone.jpg'))->toBeNull()
        ->and(LegacyImageRedirect::targetFor('wp-content/uploads/2020/04/nothing-600x600.jpg'))->toBeNull();

    lirFile('wp-content/uploads/2020/04/shell.php');
    lirFile('wp-content/uploads/2020/04/x.jpg');
    expect(LegacyImageRedirect::targetFor('wp-content/uploads/2020/04/shell-600x600.php'))->toBeNull()
        ->and(LegacyImageRedirect::targetFor('wp-content/uploads/../uploads/2020/04/x-600x600.jpg'))->toBeNull()
        ->and(LegacyImageRedirect::targetFor('wp-content/themes/x-600x600.jpg'))->toBeNull()
        ->and(LegacyImageRedirect::targetFor("wp-content/uploads/2020/04/x\0-600x600.jpg"))->toBeNull();

    test()->get('/wp-content/uploads/2020/04/nothing-600x600.jpg')->assertNotFound();
});

it('costs an ordinary page nothing: no lookup on a 200, and none on a 404 outside the upload roots', function () {
    $tables = [];
    DB::listen(function ($q) use (&$tables) { $tables[] = $q->sql; });

    test()->get('/');
    test()->get('/some-page-that-does-not-exist/');
    test()->get('/blog/photo-600x600.jpg');

    // MUTATION: make underRoot() return true and the third request (a picture
    // name outside the upload roots) queries webp_conversions.
    expect(implode("\n", $tables))->not->toContain('webp_conversions');
});

it('also covers the shop\'s own uploads/ root (an admin upload converted to WebP)', function () {
    lirFile('uploads/2026/09/banner.webp');
    lirConverted('uploads/2026/09/banner.jpg', 'uploads/2026/09/banner.webp');

    test()->get('/uploads/2026/09/banner.jpg')
        ->assertStatus(301)->assertHeader('Location', 'http://localhost/uploads/2026/09/banner.webp');
});
