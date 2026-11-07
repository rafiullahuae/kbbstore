<?php

declare(strict_types=1);

/**
 * Appearance -> Occasion banner -> Arrange products (Lane DND). The owner,
 * 7 November 2026: "give me facility to drag n drop etc anywhere the product
 * images inside the slider ... change the size of the image by drag ... in
 * desktop and mobile both ... fully optimized secured."
 *
 * A position belongs to a PLACE (slot 1-5), not to a product, because the
 * products are random per page view. Stored as % of the banner (x/y of the
 * bottom-centre, h only once resized) per layout and device, printed as CSS in
 * the banner's own <style>: no script, no request, no query on the shop.
 */

use App\Http\Controllers\Admin\OccasionBannerController;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\OccasionBanner;
use Illuminate\Http\Request;

function dndFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    request()->attributes->replace([]);
}

function dndItem(int $id, float $cm = 10): array
{
    return ['id' => $id, 'cm' => $cm, 'white' => false, 'name' => 'Item '.$id, 'brand' => 'Brand '.$id, 'brand_id' => $id,
        'image' => '/uploads/ohb/p'.$id.'.jpg', 'cut' => ['p' => 'uploads/ohb/p'.$id.'.jpg', 'w' => [240 => 80, 480 => 160]]];
}

/** One product, shown alone: the page is the same on every request (pick() has nothing to shuffle). */
function dndOne(array $extra = []): array
{
    return array_merge(['display' => 'banner', 'layout' => 'G', 'count' => 1, 'pool' => [dndItem(1)]], $extra);
}

function dndHome(array $config): string
{
    app(SettingsService::class)->set(OccasionBanner::KEY, $config);
    dndFresh();

    return (string) test()->get('/')->getContent();
}

it('prints saved places as CSS for the computer and for the phone, mirrored on Arabic', function () {
    /*
     * THE DEFECT this catches: the owner drags place 3 on the computer and
     * place 2 on the phone, presses Save, and the shop still draws the design
     * -- or draws the computer's place on the phone. One product shown alone
     * lands in place 3 (SLOTS_FOR[1] = slot index 2).
     * MUTATION: swap the two @media openers in posCss(), or drop the
     * `100 - y` (bottom from the top), or the [dir=rtl] rule, and this is red.
     */
    $html = dndHome(dndOne(['pos' => ['G' => [
        'd' => ['3' => ['x' => 60, 'y' => 80, 'h' => 45.5]],
        'm' => ['3' => ['x' => 50, 'y' => 97.5]],
    ]]]));

    $d = '@media (min-width:768px){.ohb-s2{left:60%;right:auto;bottom:20%;width:0;display:flex;justify-content:center;height:45.5%}[dir=rtl] .ohb-s2{left:auto;right:60%}.ohb-s2 img{flex:none}}';
    $m = '@media (max-width:767px){.ohb-s2{left:50%;right:auto;bottom:2.5%;width:0;display:flex;justify-content:center}[dir=rtl] .ohb-s2{left:auto;right:50%}.ohb-s2 img{flex:none}}';

    expect($html)->toContain(OccasionBanner::css().$d.$m.'</style>')
        ->and($html)->toContain('class="ohb-p ohb-s2"')
        // No h on the phone: the product keeps its true size there.
        ->and(OccasionBanner::posCss(['m' => [3 => ['x' => 50.0, 'y' => 97.5]]]))->not->toContain('height');

    // Each layout keeps its own: the same places saved under H print nothing on G.
    expect(dndHome(dndOne(['pos' => ['H' => ['d' => ['3' => ['x' => 60, 'y' => 80]]]]])))->not->toContain('{left:60%');
});

it('clamps out-of-range numbers and refuses garbage, unknown keys and extra places', function () {
    /*
     * CLAUDE.md rule 5: the browser's numbers become CSS. A string that is not
     * a number would end the rule (`50%;background:url(x)`), a huge number
     * flings a product off the banner, and place 9 or 0 does not exist.
     * MUTATION: drop the is_numeric test in posNum(), or the max/min clamp, or
     * the 1..count(SLOTS) bound in cleanPos(), and this is red.
     */
    $c = OccasionBanner::sanitize(['pos' => [
        'G' => [
            'd' => [
                '1' => ['x' => 999, 'y' => -50, 'h' => 0.1],
                '2' => ['x' => '50%;background:url(x)', 'y' => 10],
                '3' => ['x' => '12.345', 'y' => 55, 'h' => 'big', 'z' => 9, 'evil' => '<script>'],
                '0' => ['x' => 1, 'y' => 1], '6' => ['x' => 1, 'y' => 1], '9' => ['x' => 1, 'y' => 1], 'x' => ['x' => 1, 'y' => 1],
                '4' => ['x' => -1e9, 'y' => 1e9, 'h' => 1e9],
                '5' => 'junk',
            ],
            'tablet' => ['1' => ['x' => 1, 'y' => 1]],
        ],
        'Z' => ['d' => ['1' => ['x' => 1, 'y' => 1]]],
        'H' => ['m' => []],
        'I' => 'junk',
    ]]);

    expect($c['pos'])->toBe(['G' => ['d' => [
        1 => ['x' => 120.0, 'y' => 0.0, 'h' => 5.0],
        3 => ['x' => 12.3, 'y' => 55.0],
        4 => ['x' => -20.0, 'y' => 130.0, 'h' => 140.0],
    ]]])
        ->and(OccasionBanner::sanitize(['pos' => 'junk'])['pos'])->toBe([])
        ->and(OccasionBanner::posCss($c['pos']['G']))->toBe('@media (min-width:768px)'
            .'{.ohb-s0{left:120%;right:auto;bottom:100%;width:0;display:flex;justify-content:center;height:5%}[dir=rtl] .ohb-s0{left:auto;right:120%}.ohb-s0 img{flex:none}'
            .'.ohb-s2{left:12.3%;right:auto;bottom:45%;width:0;display:flex;justify-content:center}[dir=rtl] .ohb-s2{left:auto;right:12.3%}.ohb-s2 img{flex:none}'
            .'.ohb-s3{left:-20%;right:auto;bottom:-30%;width:0;display:flex;justify-content:center;height:140%}[dir=rtl] .ohb-s3{left:auto;right:-20%}.ohb-s3 img{flex:none}}');
});

it('stores places through the save endpoint, normalised, and hands them back to the screen', function () {
    /*
     * THE DEFECT: the screen sends the places but save() keeps only the keys
     * it knew before, so Save looks done and the shop never moves; or it
     * stores what the browser sent unchecked. MUTATION: drop the
     * `$out['pos'] = self::cleanPos(...)` line and this is red.
     */
    $res = app(OccasionBannerController::class)->save(Request::create('/x', 'POST', ['config' => dndOne(['pool' => [], 'pos' => [
        'I' => ['m' => ['2' => ['x' => '33.33', 'y' => 140, 'h' => 20]]],
        'junk' => 1,
    ], 'rogue' => '<script>'])]));
    $stored = app(SettingsService::class)->get(OccasionBanner::KEY);

    expect($res->getData(true)['ok'])->toBeTrue()
        ->and(OccasionBanner::config([OccasionBanner::KEY => $stored])['pos'])->toBe(['I' => ['m' => [2 => ['x' => 33.3, 'y' => 130.0, 'h' => 20.0]]]])
        ->and($stored)->not->toHaveKey('rogue')
        ->and($res->getData(true)['config']['pos'])->toEqual(['I' => ['m' => [2 => ['x' => 33.3, 'y' => 130, 'h' => 20]]]])
        ->and($res->getData(true)['config'])->not->toHaveKey('rogue');
});

it('prints nothing new with no places saved, so the shop is byte for byte what it was', function () {
    /*
     * THE DEFECT: the feature changes every banner the moment the package
     * applies -- a stray `@media` or an empty rule after the stylesheet.
     * StorefrontEnglishUnchangedTest pins the page with the banner off; this
     * pins it with the banner ON. MUTATION: make posCss() return '@media
     * (min-width:768px){}' for an empty set, or print anything after css()
     * in the partial, and this is red.
     */
    $before = dndHome(dndOne());
    $emptyPos = dndHome(dndOne(['pos' => []]));
    $otherLayout = dndHome(dndOne(['pos' => ['I' => ['d' => ['1' => ['x' => 10, 'y' => 10]]]]]));

    expect(OccasionBanner::posCss([]))->toBe('')
        ->and($before)->toContain('<style>'.OccasionBanner::css().'</style><div class="ohb ohb-G"')
        ->and($emptyPos)->toBe($before)
        ->and($otherLayout)->toBe($before)
        ->and(OccasionBanner::defaults()['pos'])->toBe([]);
});

it('adds no script, no request and no query to the shop when places are saved', function () {
    /*
     * The speed freeze (CLAUDE.md): the places are CSS printed once in the
     * banner's existing <style>; nothing measures, nothing loads. MUTATION:
     * print a <script> or a <link> with the places, or read anything from the
     * database for them, and this is red.
     */
    $n = 0;
    $on = false;
    \Illuminate\Support\Facades\DB::listen(function () use (&$n, &$on) { $n += $on ? 1 : 0; });
    $count = function () use (&$n, &$on): int {
        dndFresh();
        $n = 0;
        $on = true;
        test()->get('/');
        $on = false;

        return $n;
    };

    $plain = dndHome(dndOne());
    $n0 = $count();
    $arranged = dndHome(dndOne(['pos' => ['G' => ['d' => ['3' => ['x' => 60, 'y' => 80, 'h' => 40]], 'm' => ['3' => ['x' => 50, 'y' => 90, 'h' => 30]]]]]));
    $n1 = $count();

    expect(substr_count($arranged, '<script'))->toBe(substr_count($plain, '<script'))
        ->and(substr_count($arranged, '<link'))->toBe(substr_count($plain, '<link'))
        ->and(substr_count($arranged, '<img'))->toBe(substr_count($plain, '<img'))
        ->and($n1)->toBe($n0)
        // and the CSS is all it adds, with no newer CSS function next to var() (LogoLockupTest's landmine).
        ->and(strlen($arranged) - strlen($plain))->toBeLessThan(600)
        ->and(OccasionBanner::posCss(['d' => [1 => ['x' => 1.0, 'y' => 2.0, 'h' => 3.0]]]))->not->toMatch('/var\(|round\(|color-mix|calc\(/');
});

it('arranges in the admin with Pointer Events, one frame-throttled step and no timer', function () {
    /*
     * "Super light" on the admin side too (CLAUDE.md rule 4): no polling, no
     * timer left running. The screen already had ONE setTimeout, the search's
     * 300 ms debounce; the arrange code adds none, moves at most once a frame
     * and captures the pointer so a touch drag on a phone does not lose the
     * product. MUTATION: add a setInterval / setTimeout anywhere in the screen,
     * or move the product straight from pointermove, and this is red.
     */
    $src = file_get_contents(resource_path('views/admin/partials/occasion-banner-screen.blade.php'));
    $start = strpos($src, 'arrange (Lane DND)');
    $end = strpos($src, '/* ------------------------------------------------------------------- render */');
    $arr = substr($src, $start, $end - $start);

    expect($start)->toBeGreaterThan(0)
        ->and(substr_count($src, 'setInterval'))->toBe(0)
        ->and(substr_count($src, 'setTimeout('))->toBe(1)
        ->and($arr)->not->toContain('setTimeout')
        ->and($arr)->toContain('setPointerCapture')
        ->and($arr)->toContain('releasePointerCapture')
        ->and(substr_count($arr, 'requestAnimationFrame('))->toBe(1)
        ->and($src)->toContain("document.addEventListener('pointercancel', onUp)")
        ->and($src)->toContain('touch-action:none')
        // It measures nothing: the preview boxes' sizes are the screen's own CSS numbers.
        ->and($src)->not->toContain('getBoundingClientRect')
        ->and($src)->toContain('.obx-d{width:640px;height:300px}')
        ->and($src)->toContain('.obx-m{width:234px;height:300px}')
        ->and($src)->toContain('.obx-d{width:320px;height:150px}')
        ->and($src)->toContain('.obx-m{width:156px;height:200px}')
        ->and($arr)->toContain('BOX = { d: [640, 300, 320, 150], m: [234, 300, 156, 200] }')
        // The one sentence the owner reads about slots vs products.
        ->and($arr)->toContain('Places, not products:')
        // The shop's stylesheet and the preview's come from the same rules.
        ->and($arr)->toContain("'@media (min-width:768px){'")
        ->and($arr)->toContain("'@media (max-width:767px){'")
        ->and(OccasionBanner::adminCatalogue()['pos_range'])->toBe(OccasionBanner::POS_RANGE);
});
