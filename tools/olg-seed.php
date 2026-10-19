<?php

declare(strict_types=1);

/*
 * Lane OLG: on top of tools/mac-seed.php + tools/emirate-seed.php, mark a third
 * of the orders -- the newest one included -- as placed on /ar, the way
 * App\Support\OrderLocale records them. Every other order keeps the column's
 * default 'en'. Run through tools/olg-preview.sh; never against a real database.
 */

use Illuminate\Support\Facades\DB;

$newest = (int) DB::table('orders')->max('id');
DB::table('orders')->whereRaw('id % 3 = 0')->orWhere('id', $newest)->update(['locale' => 'ar']);
DB::table('orders')->where('id', $newest - 1)->update(['locale' => 'en']);

// Store -> Orders sorts by placed date, the owner app by id: the top row of
// each is AR and the second EN, so the shots open one of each.
$byDate = DB::table('orders')->orderByDesc('created_at')->limit(2)->pluck('id')->all();
DB::table('orders')->where('id', $byDate[0])->update(['locale' => 'ar']);
DB::table('orders')->where('id', $byDate[1])->update(['locale' => 'en']);

echo 'Lane OLG seed: '.DB::table('orders')->where('locale', 'ar')->count().' AR, '.DB::table('orders')->where('locale', 'en')->count()." EN.\n";
