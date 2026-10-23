<?php

declare(strict_types=1);

/*
 * MAIN AND BACKUP GIFTS (the owner, 23 October 2026):
 *
 *   "i should be able to add multiple masks, minis, and full size products, as
 *    primary and back-log. and i can change the number of mini, masks and full
 *    size in the range. so the system will act according and print according
 *    sentence as total number of products ... the back log will only trigger
 *    if the primary products get out of stock by any reason, so the system will
 *    pick any from the back log as per the quantity available, for example if
 *    3 qnty of one product, and 1 qnty of another product, so the system will
 *    use first the 3 qnty product from the back log"
 *
 * What it looked like before: a range listed its gifts once; a sold-out sheet
 * mask was simply skipped, so a shopper promised "3 Masks + 2 Mini Products"
 * got 2 Mini Products and a sentence that still said 3 Masks.
 */

use App\Models\Product;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    DB::table('free_gift_ranges')->delete();
});

function fgbProduct(string $name, ?int $stock = null, string $kind = 'mask'): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 2500, 'stock_status' => $stock === 0 ? 'outofstock' : 'instock', 'manage_stock' => $stock !== null, 'stock' => $stock, 'gift_kind' => $kind,
    ]);
}

/** [[Product, qty, kind, role], ...] */
function fgbRange(array $gifts, ?array $needs): void
{
    $id = DB::table('free_gift_ranges')->insertGetId([
        'min_fils' => 30000, 'max_fils' => 49900, 'label_en' => 'Old words', 'label_ar' => 'كلمات', 'sort' => 0, 'enabled' => true,
        'need_mask' => $needs['mask'] ?? null, 'need_mini' => $needs['mini'] ?? null, 'need_full' => $needs['full'] ?? null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($gifts as $i => [$p, $q, $kind, $role]) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $p->id, 'quantity' => $q, 'kind' => $kind, 'role' => $role, 'sort' => $i]);
    }
}

/** The lines a basket gets now, as [name => quantity], read fresh (a new request, a new compile). */
function fgbGiven(): array
{
    app()->instance('request', Request::create('/'));
    SettingsService::forgetMemo();
    Setting::flushMap();
    $gifts = app(FreeGifts::class);
    $gifts->compile();
    $lines = $gifts->linesFor($gifts->ranges()[0]);

    $out = [];
    foreach ($lines as $l) {
        $out[$l['product']->name] = $l['quantity'] . ($l['backup'] ? ' backup' : '');
    }

    return $out;
}

it('gives the main gifts while they are in stock, and never touches a backup', function () {
    $mask = fgbProduct('Tea Tree Mask', 40);
    $mini = fgbProduct('Toner Mini', 20, 'mini');
    $bk = fgbProduct('Backup Mask', 9);
    fgbRange([[$mask, 3, 'mask', 'primary'], [$mini, 2, 'mini', 'primary'], [$bk, 1, 'mask', 'backup']], ['mask' => 3, 'mini' => 2, 'full' => 0]);

    expect(fgbGiven())->toBe(['Tea Tree Mask' => '3', 'Toner Mini' => '2']);
});

it('tops a sold-out main gift up from the backups, the one with the most stock first', function () {
    // The owner's own example: backups with 3 and with 1 in stock -- the 3 goes first.
    $main = fgbProduct('Tea Tree Mask', 0);
    $one = fgbProduct('Backup One Left', 1);
    $three = fgbProduct('Backup Three Left', 3);
    fgbRange([[$main, 3, 'mask', 'primary'], [$one, 1, 'mask', 'backup'], [$three, 1, 'mask', 'backup']], ['mask' => 3, 'mini' => 0, 'full' => 0]);

    // MUTATION: sort the backups by their list order instead of stock. RED (the 1-left one first).
    expect(fgbGiven())->toBe(['Backup Three Left' => '3 backup']);

    // A main gift short by one: the main gives what it has, the backup the rest.
    DB::table('products')->where('id', $main->id)->update(['stock' => 2, 'stock_status' => 'instock']);
    expect(fgbGiven())->toBe(['Tea Tree Mask' => '2', 'Backup Three Left' => '1 backup']);
});

it('runs through several backups when one is not enough, and gives what there is when all are gone', function () {
    $main = fgbProduct('Main Mini', 0, 'mini');
    $a = fgbProduct('Backup A', 1, 'mini');
    $b = fgbProduct('Backup B', 1, 'mini');
    fgbRange([[$main, 2, 'mini', 'primary'], [$a, 1, 'mini', 'backup'], [$b, 1, 'mini', 'backup']], ['mask' => 0, 'mini' => 3, 'full' => 0]);

    expect(fgbGiven())->toBe(['Backup A' => '1 backup', 'Backup B' => '1 backup']);
});

it('writes the sentence from what is actually given, in English and Arabic', function () {
    $mask = fgbProduct('Tea Tree Mask', 0);
    $mini = fgbProduct('Toner Mini', 10, 'mini');
    $full = fgbProduct('Senka Whip', 5, 'full');
    fgbRange([[$mask, 3, 'mask', 'primary'], [$mini, 2, 'mini', 'primary'], [$full, 1, 'full', 'primary']], ['mask' => 3, 'mini' => 2, 'full' => 1]);

    app()->instance('request', Request::create('/'));
    $gifts = app(FreeGifts::class);
    $gifts->compile();

    // MUTATION: keep the range's saved label in box(). RED (it would still promise 3 Masks).
    expect(FreeGifts::sentence($gifts->linesFor($gifts->ranges()[0])))
        ->toBe(['en' => '2 Mini Products + 1 Full Size Product', 'ar' => 'منتجان صغيران + منتج بالحجم الكامل']);

    DB::table('products')->where('id', $mask->id)->update(['stock' => 50, 'stock_status' => 'instock']);
    app()->instance('request', Request::create('/'));
    $gifts->compile();
    expect(FreeGifts::sentence($gifts->linesFor($gifts->ranges()[0]))['en'])->toBe('3 Masks + 2 Mini Products + 1 Full Size Product');
});

it('keeps a range with no counts exactly as before: main gifts as listed, backups ignored', function () {
    $a = fgbProduct('Gift A', null, 'mask');
    $b = fgbProduct('Gift B', 0, 'mini');
    $bk = fgbProduct('Backup C', 5, 'mini');
    fgbRange([[$a, 2, 'mask', 'primary'], [$b, 1, 'mini', 'primary'], [$bk, 1, 'mini', 'backup']], null);

    expect(fgbGiven())->toBe(['Gift A' => '2']);
});

it('stays one query for the products, however many backups a range holds', function () {
    $gifts = [];
    for ($i = 0; $i < 3; $i++) {
        $gifts[] = [fgbProduct('Main '.$i, 0), 1, 'mask', 'primary'];
    }
    $count = function (int $backups) use ($gifts): int {
        DB::table('free_gift_ranges')->delete();
        $list = $gifts;
        for ($i = 0; $i < $backups; $i++) {
            $list[] = [fgbProduct('Backup '.$backups.'-'.$i, 2), 1, 'mask', 'backup'];
        }
        fgbRange($list, ['mask' => 3, 'mini' => 0, 'full' => 0]);
        app()->instance('request', Request::create('/'));
        $fg = app(FreeGifts::class);
        $fg->compile();
        $range = $fg->ranges()[0];
        $q = 0;
        DB::listen(function () use (&$q) { $q++; });
        $fg->linesFor($range);

        return $q;
    };

    expect($count(40))->toBe($count(2));
});

it('saves main and backup from the admin, and a gift sent without a role stays main', function () {
    $mask = fgbProduct('Tea Tree Mask', 10);
    $bk = fgbProduct('Backup Mask', 10);
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 30000, 'max_fils' => 49900, 'label_en' => 'x', 'label_ar' => 'x', 'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $owner = \App\Models\AdminUser::create(['name' => 'O', 'email' => 'fgb-'.uniqid().'@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);

    $r = test()->actingAs($owner, 'admin')->putJson('/admin-api/free-gifts/ranges/'.$id, [
        'min' => 300, 'max' => 499, 'label_en' => '3 Masks', 'label_ar' => '3 أقنعة', 'enabled' => true,
        'gifts' => [['product_id' => $mask->id, 'quantity' => 3, 'kind' => 'mask'], ['product_id' => $bk->id, 'quantity' => 1, 'kind' => 'mask', 'role' => 'backup']],
        'needs' => ['mask' => 3, 'mini' => 0, 'full' => 0],
    ])->assertOk();

    $range = collect($r->json('ranges'))->firstWhere('id', $id);
    expect(collect($range['gifts'])->pluck('role', 'product_id')->all())->toBe([$mask->id => 'primary', $bk->id => 'backup']);

    test()->actingAs($owner, 'admin')->putJson('/admin-api/free-gifts/ranges/'.$id, [
        'min' => 300, 'max' => 499, 'label_en' => 'x', 'label_ar' => 'x', 'enabled' => true,
        'gifts' => [['product_id' => $bk->id, 'quantity' => 1, 'kind' => 'mask', 'role' => 'spare']],
    ])->assertStatus(422);
});
