<?php

declare(strict_types=1);

use App\Services\Analytics\Rollup;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;

/*
 * Overview → Analytics, 20 October 2026: "Analytics could not be loaded
 * (Server Error)" on the live shop. The day's rebuild groups values exactly
 * in PHP, but the live MySQL key (day, dim, val) is case- and accent-
 * insensitive and ignores trailing spaces: "/Product/x/" and "/product/x/"
 * were two rows to insert and one key, the INSERT threw, and the summary and
 * the live card -- both of which trigger the rebuild -- answered 500.
 */

function arcHit(array $over): void
{
    DB::table('an_hits')->insert($over + [
        'v' => 'v000000000000001', 's' => 's000000000000001', 'k' => 0, 'e' => 1,
        'path' => '/', 'title' => 'Home', 'ref' => '', 'ch' => 'direct', 'src' => '', 'med' => '', 'cmp' => '',
        'dev' => 'mobile', 'br' => 'Safari', 'os' => 'iOS', 'cc' => 'AE', 'lang' => 'en',
    ]);
}

it('folds values the database calls equal into one row, summing them', function () {
    /* MUTATION: return $rows unchanged from mergeCollisions() → three rows, red. */
    $rows = Rollup::mergeCollisions([
        ['dim' => 'page', 'val' => '/Product/x/', 'label' => '', 'views' => 2, 'visitors' => 1, 'sessions' => 1, 'bounces' => 0, 'timed' => 0, 'secs' => 0],
        ['dim' => 'page', 'val' => '/product/x/ ', 'label' => '', 'views' => 3, 'visitors' => 2, 'sessions' => 2, 'bounces' => 1, 'timed' => 0, 'secs' => 0],
        ['dim' => 'source', 'val' => 'Instagram', 'label' => '', 'views' => 1, 'visitors' => 1, 'sessions' => 1, 'bounces' => 0, 'timed' => 0, 'secs' => 0],
        ['dim' => 'source', 'val' => 'instagram', 'label' => '', 'views' => 1, 'visitors' => 1, 'sessions' => 1, 'bounces' => 0, 'timed' => 0, 'secs' => 0],
        ['dim' => 'page', 'val' => 'instagram', 'label' => '', 'views' => 9, 'visitors' => 9, 'sessions' => 9, 'bounces' => 0, 'timed' => 0, 'secs' => 0],
    ]);

    expect($rows)->toHaveCount(3)
        ->and($rows[0]['views'])->toBe(5)->and($rows[0]['visitors'])->toBe(3)
        ->and($rows[1]['sessions'])->toBe(2)
        ->and($rows[2]['views'])->toBe(9);
});

it('rebuilds a day whose hits differ only in case, and the board answers', function () {
    $m = intdiv(StoreTime::now()->getTimestamp(), 60) - 5;
    arcHit(['m' => $m, 'v' => 'va00000000000001', 's' => 'sa00000000000001', 'path' => '/Product/x/', 'src' => 'Instagram']);
    arcHit(['m' => $m, 'v' => 'vb00000000000001', 's' => 'sb00000000000001', 'path' => '/product/x/', 'src' => 'instagram']);

    Rollup::rollDay(StoreTime::now()->format('Y-m-d'));

    $pages = DB::table('an_dims')->where('dim', 'page')->get();
    expect($pages)->toHaveCount(1)->and((int) $pages[0]->visitors)->toBe(2);
});
