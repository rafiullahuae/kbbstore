<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\ImageVariants;
use Illuminate\Support\Facades\Cache;

/*
 * Lane PG2 -- "ALSO sometimes the inner pages stuck fully and keep loading,
 * i'm not sure what's the issue, but only rarely some times."
 *
 * WHAT IS PROVED IN CODE (docs/PG2-STALLS.md has the rest). Making a
 * photograph's phone-sized copies, a banner's wide copies or a product's
 * link-preview card happens AFTER the shopper has the page, but inside the
 * PHP-FPM worker that served it, which takes no other request until it is
 * done: 1,105 ms after the first home page view on the seeded preview, 136 ms
 * after a cold product page, a tile batch is allowed 3 s. The per-photograph
 * locks stopped two workers making the SAME picture and nothing stopped every
 * worker making DIFFERENT ones at once, and a small pool with every worker
 * busy is a navigation that sits there loading. Chrome's hover prefetch
 * (InstantNav) rendered pages the shopper may never open and paid the same.
 *
 * PINNED: while one worker holds the picture lock, another view makes nothing
 * and leaves the photograph for the next view; the lock is released after the
 * work; a prefetched page schedules nothing.
 *
 * MUTATIONS, each run: unwrap the loop in ImageVariants::tileAfterResponse()
 * from `self::oneWorkerAtATime(...)` and test 1 is red ("a second worker made
 * pictures"); delete `|| ! self::mayWorkAfterResponse()` from its guard and
 * test 2 is red ("a prefetch made pictures").
 */

function owPhoto(string $rel): void
{
    $path = public_path($rel);
    @mkdir(\dirname($path), 0775, true);
    $im = imagecreatetruecolor(900, 900);
    imagefilledrectangle($im, 0, 0, 900, 900, (int) imagecolorallocate($im, 200, 120, 160));
    imagejpeg($im, $path, 85);
    imagedestroy($im);
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD, which is the thing under test');
    }

    config(['kbb.image_tile_after_response' => true]);
    $this->owRel = 'wp-content/uploads/ow-'.uniqid().'/shot.jpg';
    owPhoto($this->owRel);
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    Product::query()->visible()->get()->each(fn (Product $p) => $p->forceFill(['image' => '/'.$this->owRel])->save());
    $this->owCopy = fn (): bool => is_file(public_path(ImageVariants::DIR.'/400/'.$this->owRel));
});

afterEach(function () {
    ImageVariants::forget('/'.$this->owRel);
    @unlink(public_path($this->owRel));
    @rmdir(\dirname(public_path($this->owRel)));
    Cache::forget(ImageVariants::AFTER_RESPONSE_BUSY);
});

it('makes no pictures while another worker is making them, and leaves the photograph for the next view', function () {
    Cache::add(ImageVariants::AFTER_RESPONSE_BUSY, 1, 30);   // another worker, mid-batch

    $this->get('/shop')->assertOk();
    expect(($this->owCopy)())->toBeFalse('a second worker made pictures while the first held the lock');

    Cache::forget(ImageVariants::AFTER_RESPONSE_BUSY);       // that worker finished

    $this->get('/shop')->assertOk();
    expect(($this->owCopy)())->toBeTrue('the photograph skipped while the lock was held was never made')
        ->and(Cache::has(ImageVariants::AFTER_RESPONSE_BUSY))->toBeFalse('the lock outlived the work');
});

it('schedules no pictures for a page Chrome fetched ahead', function () {
    $this->get('/shop', ['Sec-Purpose' => 'prefetch'])->assertOk();
    expect(($this->owCopy)())->toBeFalse('a prefetch made pictures in a worker for a page nobody opened');

    $this->get('/shop')->assertOk();
    expect(($this->owCopy)())->toBeTrue('the real view did not make the copies');
});
