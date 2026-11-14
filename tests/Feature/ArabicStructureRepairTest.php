<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Translation;
use App\Services\Translation\ArabicStructureRepair;
use App\Services\Translation\TranslationStore;
use App\Support\AdminCapabilities;
use App\Support\BlockStructure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ArabicShop;
use Tests\Support\ArStructureAdminRoutes;

/*
 * Lane ARDX -- the PERMANENT half of the owner's 14 Nov 2026 "in arabic version, the spacing and line
 * breaking spacing has issues ... fix this permanently" (/ar/product/medicube-vanilla-deodrant-and-body-
 * mist-duo/: headings glued under the bullets because the Arabic had lost every blank line its English has).
 *
 *  - kbb:ar-structure / Catalog -> Translate & categorise -> Arabic spacing repairs the STORED Arabic:
 *    dry run writes nothing, apply backs every row up first, undo puts back only rows still holding the
 *    repair. MUTATION: skip the backup insert in apply() -> the undo case is red.
 *  - TranslationStore::put() lines every new Arabic body up with its English on the way in, so a writer
 *    that drops a blank line (a model answering `<div></div>` for `<div>&nbsp;</div>` passes the tag check)
 *    cannot store the defect again. MUTATION: remove the align() block in put() -> red.
 *  - The product page draws unrepaired rows lined up (ProductTabs), English untouched, no query added.
 *    MUTATION: drop the BlockStructure::forDisplay() call in ProductTabs::builtins() -> red.
 */

beforeEach(function () {
    ArStructureAdminRoutes::wire(app());
});

function ardxFixture(string $slug): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/arabic-spacing/'.$slug.'.json')), true);
}

/** The live English as the editor STORES it: blank lines as `<div><br></div>`, not the rendered class. */
function ardxStored(string $html): string
{
    return str_replace('<div class="kbb-bl"></div>', '<div><br></div>', $html);
}

function ardxProduct(string $en, ?string $ar, array $extra = []): Product
{
    $p = Product::create($extra + ['slug' => 'ardx-'.Str::random(8), 'name' => 'ARDX product', 'status' => 'publish', 'is_visible' => true,
        'price' => 9900, 'stock_status' => 'instock', 'description' => $en]);

    if ($ar !== null) {
        DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $p->id, 'field' => 'description',
            'value' => $ar, 'status' => 'published', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $p;
}

function ardxAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'ARDX '.$role, 'email' => 'ardx-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function ardxValue(int $productId): string
{
    return (string) DB::table('translations')->where(['locale' => 'ar', 'group' => 'products', 'item_id' => $productId, 'field' => 'description'])->value('value');
}

it('dry run lists the repair and writes nothing; apply repairs with a backup; undo puts it back', function () {
    $f = ardxFixture('medicube-vanilla-deodrant-and-body-mist-duo');
    $p = ardxProduct(ardxStored($f['en']), $f['ar']);

    $this->artisan('kbb:ar-structure', ['--dry-run' => true])
        ->expectsOutputToContain('FIX   #'.$p->id)
        ->expectsOutputToContain('split a list of 2 into 1 + 1')
        ->expectsOutputToContain('Nothing was written')
        ->assertSuccessful();
    expect(ardxValue($p->id))->toBe($f['ar']);

    $r = ArabicStructureRepair::apply();
    $after = ardxValue($p->id);

    expect($r['changed'])->toBe(1)
        ->and(BlockStructure::shape($after))->toBe('P B L1 B L1 B P B P L5 B P L5')
        ->and(BlockStructure::words($after))->toBe(BlockStructure::words($f['ar']))
        ->and(DB::table('ar_structure_backups')->where('batch', $r['batch'])->value('old_value'))->toBe($f['ar'])
        // the row stays what it was: published, manual
        ->and(DB::table('translations')->where('item_id', $p->id)->value('status'))->toBe('published');

    // A second run finds nothing to do.
    expect(ArabicStructureRepair::apply()['changed'])->toBe(0);

    $this->artisan('kbb:ar-structure', ['--undo' => $r['batch']])->expectsOutputToContain('1 row(s) put back')->assertSuccessful();
    expect(ardxValue($p->id))->toBe($f['ar']);
});

it('undo keeps a row the owner edited after the repair', function () {
    $f = ardxFixture('medicube-vanilla-deodrant-and-body-mist-duo');
    $p = ardxProduct(ardxStored($f['en']), $f['ar']);
    $r = ArabicStructureRepair::apply();

    DB::table('translations')->where('item_id', $p->id)->update(['value' => '<p>كتبه المالك</p>']);

    expect(ArabicStructureRepair::undo($r['batch']))->toBe(['restored' => 0, 'kept' => 1])
        ->and(ardxValue($p->id))->toBe('<p>كتبه المالك</p>');
});

it('leaves what it cannot line up, lists why, and scopes to the newest products', function () {
    $egf = ardxFixture('medicube-egf-nad-firming-glow-set');
    $old = ardxProduct($egf['en'], $egf['ar'], ['created_at' => now()->subDays(30)]);
    $f = ardxFixture('medicube-vanilla-deodrant-and-body-mist-duo');
    $new = ardxProduct($f['en'], $f['ar'], ['created_at' => now()->addDays(5)]);

    $scan = ArabicStructureRepair::scan();
    expect(collect($scan['human'])->pluck('product_id')->all())->toContain($old->id)
        ->and(collect($scan['fixable'])->pluck('product_id')->all())->toContain($new->id);

    $recent = ArabicStructureRepair::scan(ArabicStructureRepair::recentIds(1));
    expect(collect($recent['fixable'])->pluck('product_id')->all())->toBe([$new->id])
        ->and($recent['human'])->toBe([]);

    ArabicStructureRepair::apply();
    expect(ardxValue($old->id))->toBe($egf['ar']);
});

it('stores new Arabic lined up with its English, whoever writes it', function () {
    $p = ardxProduct('<p><b>How to use</b></p><ul><li>Apply</li></ul><div>&nbsp;</div><p><b>Size</b> 50 ml</p>', null);

    // A Claude draft that kept every tag but dropped the &nbsp; (passes HtmlStructure's tag check).
    TranslationStore::put('ar', 'products', $p->id, 'description',
        '<p><b>طريقة الاستخدام</b></p><ul><li>ضعيه</li></ul><div></div><p><b>الحجم</b> 50 مل</p>',
        Translation::STATUS_DRAFT, Translation::SOURCE_MACHINE, englishSource: (string) $p->description);

    expect(BlockStructure::shape(ardxValue($p->id)))->toBe('P L1 B P')
        ->and(BlockStructure::words(ardxValue($p->id)))->toBe(BlockStructure::words('طريقة الاستخدامضعيهالحجم50مل'));

    // Copy that already lines up is stored exactly as the sanitiser leaves it (no blank line added).
    $exact = '<p><b>طريقة الاستخدام</b></p><ul><li>ضعيه</li></ul><div>&nbsp;</div><p><b>الحجم</b> 50 مل</p>';
    TranslationStore::put('ar', 'products', $p->id, 'description', $exact, Translation::STATUS_DRAFT, Translation::SOURCE_MANUAL, englishSource: (string) $p->description);
    expect(ardxValue($p->id))->toBe(\App\Support\RichText::clean($exact));
});

it('draws an unrepaired row lined up on /ar, leaves the English page alone, and adds no query', function () {
    ArabicShop::on();
    $f = ardxFixture('medicube-vanilla-deodrant-and-body-mist-duo');
    $p = ardxProduct(ardxStored($f['en']), $f['ar']);
    TranslationStore::flush();

    $count = function (string $url) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = test()->get($url)->assertOk()->getContent();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$html, $n];
    };

    $count('/ar/product/'.$p->slug.'/'); // warm the caches; the second request is the one measured
    [$ar, $arQueries] = $count('/ar/product/'.$p->slug.'/');
    expect($ar)->toContain("</ul>\n<div class=\"kbb-bl\"></div><strong>طريقة الاستخدام")
        ->and($ar)->toContain('</ul></div><div class="kbb-bl"></div><div><strong>الخطوة 2');

    [$en] = $count('/product/'.$p->slug.'/');
    expect($en)->not->toContain('طريقة')->and(substr_count($en, 'kbb-bl'))->toBe(2 * substr_count($f['en'], 'kbb-bl'));

    // The same page with an Arabic body that already lines up costs the same queries.
    DB::table('translations')->where('item_id', $p->id)->update(['value' => BlockStructure::align($f['en'], $f['ar'])['html']]);
    TranslationStore::flush();
    $count('/ar/product/'.$p->slug.'/');
    [, $alignedQueries] = $count('/ar/product/'.$p->slug.'/');
    expect($arQueries)->toBe($alignedQueries);
});

it('draws the short description lined up too (live sebum-sweeper brush: four blank lines lost)', function () {
    // The live /ar blurb ran "Key Benefits" list, "Made in Korea", the next heading and the next list
    // together; its English has a blank line between each. MUTATION: print t('short_description') in
    // store/product.blade.php instead of BlockStructure::translated() -> red.
    ArabicShop::on();
    $f = ardxFixture('sebum-sweeper-pore-cleansing-silicone-brush-short');
    $p = ardxProduct('<p>Body</p>', null, ['short_description' => ardxStored($f['en'])]);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $p->id, 'field' => 'short_description',
        'value' => $f['ar'], 'status' => 'published', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    TranslationStore::flush();

    $blurb = function (string $html): string {
        preg_match('#<div class="pm-sec pm-short[^"]*"><input[^>]*>(?:<div|<p) class="[^"]*bb-desc">(.*?)</(?:div|p)><label class="bb-more"#s', $html, $m);

        return $m[1] ?? '';
    };

    $ar = $blurb($this->get('/ar/product/'.$p->slug.'/')->assertOk()->getContent());
    expect(BlockStructure::shape($f['ar']))->toBe('P L5 P P L2 P L5 P P')
        ->and(BlockStructure::shape($ar))->toBe(BlockStructure::shape($f['en']))
        ->and(BlockStructure::words($ar))->toBe(BlockStructure::words($f['ar']));

    $en = $blurb($this->get('/product/'.$p->slug.'/')->assertOk()->getContent());
    expect(BlockStructure::shape($en))->toBe(BlockStructure::shape($f['en']))->and($en)->not->toContain('مزيل');
});

it('admin: dry run, apply and undo behind their own capability, failing closed', function () {
    foreach (ArStructureAdminRoutes::registered() as $route) {
        foreach ($route->methods() as $m) {
            if ($m !== 'HEAD') {
                expect(AdminCapabilities::forPath($m, $route->uri()))->toBe('catalog.ar_structure', $m.' '.$route->uri());
            }
        }
    }
    expect(ArStructureAdminRoutes::registered())->toHaveCount(3);

    $f = ardxFixture('medicube-vanilla-deodrant-and-body-mist-duo');
    $p = ardxProduct(ardxStored($f['en']), $f['ar']);

    $this->getJson('/admin-api/ar-structure')->assertStatus(401);
    foreach (['editor', 'support'] as $role) {
        $this->actingAs(ardxAdmin($role), 'admin');
        $this->getJson('/admin-api/ar-structure')->assertForbidden();
        $this->postJson('/admin-api/ar-structure/apply')->assertForbidden();
    }
    expect(ardxValue($p->id))->toBe($f['ar']);

    $this->actingAs(ardxAdmin('manager'), 'admin');
    $j = $this->getJson('/admin-api/ar-structure?recent=50')->assertOk()->json();
    expect($j['fixable_count'])->toBe(1)
        ->and($j['fixable'][0]['after'])->toBe('P B L1 B L1 B P B P L5 B P L5')
        // shapes and reasons only: the copy itself is not sent to the browser
        ->and(json_encode($j))->not->toContain('ميديكوب');
    expect(ardxValue($p->id))->toBe($f['ar']);

    $a = $this->postJson('/admin-api/ar-structure/apply', ['recent' => 50])->assertOk()->json();
    expect($a['changed'])->toBe(1)->and(ardxValue($p->id))->not->toBe($f['ar']);

    $this->postJson('/admin-api/ar-structure/undo', ['batch' => '<script>'])->assertStatus(422);
    $u = $this->postJson('/admin-api/ar-structure/undo', ['batch' => $a['batch']])->assertOk()->json();
    expect($u['restored'])->toBe(1)->and(ardxValue($p->id))->toBe($f['ar']);
});

it('puts the Arabic spacing tab on Translate & categorise, wired to its own endpoints', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/recent-localise-screen.blade.php'));

    expect($screen)->toContain("['spacing', 'Arabic spacing']")
        ->and($screen)->toContain("'/admin-api/ar-structure'")
        ->and(substr_count($screen, 'data-rlz-spapply'))->toBeGreaterThan(1);
});
