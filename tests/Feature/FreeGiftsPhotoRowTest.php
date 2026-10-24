<?php

declare(strict_types=1);

/*
 * THE GIFT BOX'S PHOTO ROW (Lane FGP). The owner, 24 October, on a cart panel
 * whose five 36px photos stopped well short of the box's right edge:
 *
 *   "increase the gifts thumbnails size to the end of the box. if one product,
 *    then make 25% more size ... 2-4 products 15-20% ... 4-5 products 10-15
 *    ... if more products then it may come in 2nd row inside the box. and the
 *    view all will remain same with same functionality."
 *
 * then, having seen previews A, B and C: "give us option to choose all three
 * options from backend, and keep option B by default." Marketing -> Free gifts
 * -> Display -> Gift box look -> Photo row.
 */

use App\Models\Setting;
use App\Services\FreeGifts;
use App\Services\SettingsService;

function fgpRows(int $n): array
{
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = ['product_id' => $i + 1, 'name' => 'Gift ' . $i, 'brand' => 'Brand', 'image' => '/storage/media/g' . $i . '.jpg', 'quantity' => 1, 'value' => 2500];
    }

    return $rows;
}

function fgpBox(int $n, ?string $photos = null): string
{
    app(SettingsService::class)->set(FreeGifts::KEY, ['mode' => 'live'] + ($photos === null ? [] : ['look' => ['photos' => $photos]]));
    SettingsService::forgetMemo();
    Setting::flushMap();

    return view('partials.free-gifts.box', ['kbbFgRows' => fgpRows($n), 'kbbFgWords' => 'Gifts'])->render();
}

it('ships B: one gift 25% bigger, 2-4 gifts 17%, five or more fill the row five across', function () {
    // What it looked like: five photos at 36px left a third of the box empty.
    // Mutation note: set LOOK['photos'] back to 'fixed' and every class here
    // is gone -- red.
    expect(FreeGifts::LOOK['photos'])->toBe('fill5')
        ->and(fgpBox(1))->toContain('fgb-clamp2 fgb-k1"')->toContain('width="45" height="45"')
        ->and(fgpBox(3))->toContain('fgb-clamp2 fgb-k2"')->toContain('width="42" height="42"')
        ->and(fgpBox(5))->toContain('fgb-clamp2 fgb-g5"')->toContain('@supports (aspect-ratio:1){')
        ->toContain('.fgb-g5 .fgb-th{grid-template-columns:repeat(5,1fr)}')
        // The phone's srcset pick follows the bigger photo, never a 36px copy stretched.
        ->toContain('width="64" height="64"');
});

it('keeps "View all" working the same: the same button and handler, on its own line', function () {
    $html = fgpBox(6);

    expect(substr_count($html, 'class="fgb-tg" aria-expanded="false"'))->toBe(1)
        ->and($html)->toContain("f.querySelector('.fgb-th').hidden=true;f.querySelector('.fgb-ls').hidden=false")
        ->toContain('.fgb-g5 .fgb-th .fgb-tg,.fgb-g4 .fgb-th .fgb-tg{flex-basis:100%');
});

it('offers A and C and the old fixed size, each a fixed class, a damaged value reading as B', function () {
    $tier = static fn (string $photos, int $n): string => FreeGifts::photoTier(['photos' => $photos], $n)[0];

    // A: by count, wrapping.
    expect([$tier('scale', 1), $tier('scale', 4), $tier('scale', 5), $tier('scale', 9)])->toBe([' fgb-k1', ' fgb-k2', ' fgb-k3', ' fgb-k3'])
        // C: four across from four gifts.
        ->and([$tier('fill4', 1), $tier('fill4', 3), $tier('fill4', 4), $tier('fill4', 8)])->toBe([' fgb-k1', ' fgb-k2', ' fgb-g4', ' fgb-g4'])
        ->and([$tier('fill5', 4), $tier('fill5', 5)])->toBe([' fgb-k2', ' fgb-g5'])
        ->and($tier('fixed', 5))->toBe('')
        ->and(FreeGifts::lookOf(['photos' => '"><script>'])['photos'])->toBe('fill5');

    // The old look, byte for byte: no class, no extra CSS, 36px photos.
    $fixed = fgpBox(5, 'fixed');
    expect($fixed)->not->toContain('fgb-g5')->not->toContain('fgb-k')->not->toContain('aspect-ratio')
        ->toContain('width="36" height="36"');
});

it('puts the choice in Display -> Gift box look, with the preview following it', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));

    expect($screen)->toContain("field('Photo row', photos,")
        ->toContain("[['fill5', 'B · Fill the row, 5 per row (as asked)'], ['scale',")
        ->toContain("['fill4', 'C · Biggest, 4 per row'], ['fixed', 'Same size for every count (before)']]")
        ->toContain("(lk.separator ? ' fgp-sep' : '') + photoTier(lk, rows.length)");
});
