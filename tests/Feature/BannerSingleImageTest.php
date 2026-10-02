<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BannersAdminRoutes;

/**
 * Lane RC, item 1 -- the SINGLE-IMAGE banner.
 *
 * The owner, pointing at Appearance -> Banners -> (a set) -> "What this set
 * is": "i need here option single image ... in case of single image, the
 * height will be as per the image height itself. in desktop and mobile both
 * to fit auto to the screen size without overlaping or cutting."
 *
 * THE DEFECT THIS FILE EXISTS TO CATCH is the one this repository keeps
 * finding: a select option, a constant or a column that does not reach the
 * page. `single` has to pass FIVE doors -- the model's kind(), the
 * controller's validation, the admin select and its preview, and the homepage
 * -- and each case below goes through one of them with a stored value and
 * reads the rendered HTML at the other end.
 */

/* ───────────────────────────────── helpers ──────────────────────────────── */

/** A single-image set with $n pictures; the first one is what draws. */
function rcsSet(array $setAttributes = [], array $cards = []): BannerSet
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create($setAttributes + [
        'name' => 'Single', 'slug' => 'rcs-'.uniqid(), 'status' => 'publish', 'position' => 0,
        'kind' => 'single',
    ]);

    $cards = $cards ?: [[]];

    foreach ($cards as $i => $over) {
        BannerCard::create($over + [
            'banner_set_id' => $set->id,
            'image' => 'uploads/banners/rcs-'.($i + 1).'.jpg',
            'image_w' => 1920, 'image_h' => 550,
            'alt' => 'Experience the Skincare Revolution '.($i + 1),
            'button_url' => '/shop/',
            'position' => $i + 1, 'status' => 'publish',
        ]);
    }

    return $set;
}

/** The homepage, with the module on and $set chosen. */
function rcsHome(BannerSet $set): string
{
    $settings = app(SettingsService::class);
    $settings->setModule('cards_banner', true);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    return (string) test()->get('/')->assertOk()->getContent();
}

/** The banner's own markup out of a whole page: from its <div> to its </div>. */
function rcsBlock(string $html): string
{
    $at = strpos($html, '<div class="kbbi');

    if ($at === false) {
        return '';
    }

    return substr($html, $at, (int) strpos($html, '</div>', $at) + 6 - $at);
}

function rcsOwner(): AdminUser
{
    $user = AdminUser::create([
        'name' => 'RC owner', 'email' => 'rc-'.uniqid().'@example.com',
        'password' => Hash::make('secret-secret'), 'role' => 'owner',
    ]);

    test()->actingAs($user, 'admin');

    return $user;
}

/* ═══════════════════════ door 1: the model and its constants ══════════════ */

it('is a kind, with its own partial named by a constant', function () {
    /*
     * MUTATION, run: remove `'single'` from KINDS and kind() answers `slider`
     * for a stored `single` -- the set draws as a slider on the shop while the
     * admin select (which reads the same constant) has no option for it. Red
     * on the first line.
     */
    $set = new BannerSet(['kind' => 'single']);

    expect($set->kind())->toBe('single')
        ->and($set->isSingle())->toBeTrue()
        ->and($set->isSlider())->toBeFalse()
        ->and(BannerSet::KINDS['single'])->toBe('Single image — one picture, shown whole at its own height')
        ->and(BannerSet::KIND_PARTIALS['single'])->toBe('partials.home.single-banner')
        ->and($set->homePartial())->toBe('partials.home.single-banner')
        ->and(view()->exists($set->homePartial()))->toBeTrue();

    // Every kind has a partial and a section style, and nothing else does.
    expect(array_keys(BannerSet::KIND_PARTIALS))->toBe(array_keys(BannerSet::KINDS))
        ->and(array_keys(BannerSet::SECTION_STYLES))->toBe(array_keys(BannerSet::KINDS));

    // A CONSTANT, never a built string: the model has no concatenation that
    // could assemble a view name from the column.
    $code = '';

    foreach (token_get_all((string) file_get_contents(app_path('Models/BannerSet.php'))) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($t) ? $t[1] : $t;
    }

    expect($code)->not->toContain("'partials.home.'.")
        ->and($code)->toContain("'single' => 'partials.home.single-banner',");
});

/* ═══════════════ door 2: stored value -> forHome -> partial -> HTML ═══════ */

it('draws the first published picture, whole, at its own height, with its link and alt', function () {
    /*
     * "the height will be as per the image height itself": full width,
     * `height:auto`, and the width/height attributes from the STORED size so
     * the box is reserved before the file arrives. No `object-fit` at all --
     * there is no frame for the picture to be fitted into.
     *
     * MUTATION, run: give `.kbbi-a img` an `aspect-ratio:1920/550` and
     * `object-fit:cover` (a frame) and the CSS expectations are red; drop the
     * width/height attributes and the `width="1920" height="550"` line is.
     */
    $set = rcsSet([], [
        ['button_url' => '/collections/skincare/', 'alt' => 'First "one"'],
        ['image' => 'uploads/banners/rcs-second.jpg', 'alt' => 'Second'],
    ]);
    // A draft picture BEFORE the first published one must not be the one drawn.
    BannerCard::create([
        'banner_set_id' => $set->id, 'image' => 'uploads/banners/rcs-draft.jpg',
        'image_w' => 800, 'image_h' => 800, 'alt' => 'Draft', 'position' => 0, 'status' => 'draft',
    ]);

    $html = rcsHome($set);
    $block = rcsBlock($html);

    expect($block)->not->toBe('', 'the single-image banner never reached the homepage')
        ->and(substr_count($block, '<img '))->toBe(1)
        ->and($block)->toContain('rcs-1.jpg')
        ->and($block)->not->toContain('rcs-second.jpg')
        ->and($block)->not->toContain('rcs-draft.jpg')
        ->and($block)->toMatch('#<a class="kbbi-a" href="[^"]*/collections/skincare/?">#')
        ->and($block)->toContain('alt="First &quot;one&quot;"')
        ->and($block)->toContain('width="1920" height="550"')
        // No slider machinery of any kind.
        ->and($block)->not->toContain('kbbs')
        ->and($block)->not->toContain('<button')
        ->and($block)->not->toContain('data-dwell');

    $css = (string) (preg_match('#<style>\s*\.kbbi\{.*?</style>#s', $html, $m) ? $m[0] : '');
    // The rules, not the notes: the stylesheet's comments name what it does not do.
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    expect($css)->toContain('.kbbi-a img{display:block;width:100%;height:auto;')
        ->and($css)->not->toContain('object-fit')
        ->and($css)->not->toContain('aspect-ratio');

    // And no script: there is nothing to steer.
    $after = substr($html, (int) strpos($html, '<div class="kbbi'), 4000);

    expect(substr_count((string) strstr($after, '</div>', true), '<script'))->toBe(0);
});

it('gives the phone its own picture at its own proportions', function () {
    /*
     * BannerCard already stores a phone picture and its size (Lane SEC). The
     * single image draws it below 768px through <source media>, carrying ITS
     * OWN width and height, so a 500 x 600 phone picture reserves a 5 : 6 box.
     *
     * MUTATION, run: print the desktop size on the <source> and the
     * `width="500" height="600"` expectation is red.
     */
    $set = rcsSet([], [[
        'image_m' => 'uploads/banners/rcs-phone.jpg', 'image_m_w' => 500, 'image_m_h' => 600,
    ]]);

    $html = rcsHome($set);
    $block = rcsBlock($html);
    $source = (string) (preg_match('#<source [^>]*>#', $block, $m) ? $m[0] : '');

    expect($source)->toContain('media="(max-width: 767.98px)"')
        ->and($source)->toContain('rcs-phone.jpg')
        ->and($source)->toContain('width="500" height="600"')
        ->and($block)->toContain('width="1920" height="550"');

    // One preload per breakpoint, each scoped, so a phone never fetches the
    // desktop file it will not paint.
    expect(substr_count($html, 'rel="preload" as="image" fetchpriority="high" media="(max-width: 767.98px)"'))->toBe(1)
        ->and(substr_count($html, 'rel="preload" as="image" fetchpriority="high" media="(min-width: 768px)"'))->toBe(1);
});

it('reads the size off the file when the row does not carry it, once', function () {
    /*
     * A row written before image_w/image_h existed has nulls there. The page
     * must still reserve the right box, and it must not do it by measuring in
     * the browser -- so BannerCard::naturalSize() reads the header server-side.
     *
     * MUTATION, run: make naturalSize() return null when the columns are empty
     * and the `width="1200" height="400"` expectation is red.
     */
    $rel = 'uploads/banners/rcs-unsized-'.uniqid().'.png';
    @mkdir(dirname(public_path($rel)), 0775, true);
    $im = imagecreatetruecolor(1200, 400);
    imagepng($im, public_path($rel));
    imagedestroy($im);

    try {
        $set = rcsSet([], [['image' => $rel, 'image_w' => null, 'image_h' => null]]);

        expect(rcsBlock(rcsHome($set)))->toContain('width="1200" height="400"');
    } finally {
        @unlink(public_path($rel));
    }
});

it('costs the homepage exactly what the slider costs', function () {
    /*
     * StorefrontQueryBudgetTest is a budget. The single image is read by the
     * SAME joined query as the slider (Banners::load()), so switching kind must
     * not move the count by one.
     */
    $set = rcsSet(['kind' => 'slider'], [[], [], []]);
    rcsHome($set);

    $count = function (): int {
        test()->get('/');
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $slider = $count();
    $set->update(['kind' => 'single']);
    $single = $count();

    expect($single)->toBe($slider);
});

/* ═══════════════════ doors 3 and 4: the controller and the screen ══════════ */

it('is accepted by the controller, refused when misspelt, and drawn by the preview', function () {
    /*
     * MUTATION, run: take `single` out of KINDS and the create and update both
     * 422 here, because the rule is `Rule::in(array_keys(BannerSet::KINDS))`.
     */
    BannersAdminRoutes::wire(app());
    rcsOwner();

    $made = test()->postJson('/admin-api/banners/sets', ['kind' => 'single'])->assertStatus(201)->json('set');

    expect($made['kind'])->toBe('single')
        ->and($made['name'])->toBe('Single image banner');

    $set = rcsSet(['kind' => 'slider']);

    test()->putJson('/admin-api/banners/sets/'.$set->id, ['kind' => 'singular'])->assertStatus(422);
    test()->putJson('/admin-api/banners/sets/'.$set->id, ['kind' => 'single'])->assertOk();

    expect($set->fresh()->kind)->toBe('single');

    // The stored preview and the buffered one both draw the single partial.
    expect((string) test()->getJson('/admin-api/banners/sets/'.$set->id.'/preview')->json('html'))
        ->toContain('class="kbbi');

    $draft = (string) test()->postJson('/admin-api/banners/sets/'.$set->id.'/preview', [
        'set' => ['kind' => 'single'],
    ])->assertOk()->json('html');

    expect($draft)->toContain('class="kbbi')->and($draft)->not->toContain('kbbs-vp');

    // The screen's payload carries the option the select is drawn from.
    expect(test()->getJson('/admin-api/banners')->json('enums.kinds'))->toHaveKey('single');
});

it('is reachable from the admin screen: the select, the pill, the button and the preview frame', function () {
    /*
     * The select is drawn from `enums.kinds` (asserted above). What the screen
     * itself must know about the third kind is everything that branched on
     * `kind === 'slider'`: the editor, the pill on the row, the new-set button
     * and the preview frame's height. Each was a place a third kind would have
     * silently fallen into the CARDS branch.
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/banners-screen.blade.php'));

    expect($screen)->toContain("var single = s.kind === 'single';")
        ->and($screen)->toContain("} else if (single) {")
        ->and($screen)->toContain('data-bns-kind="single"')
        ->and($screen)->toContain("single: 'Single image'")
        ->and($screen)->toContain("draft.set.kind === 'slider' || draft.set.kind === 'single'")
        // The phone picture is a picture-kind control, not a slider-only one.
        ->and($screen)->toContain("var pictures = slider || single;")
        ->and(substr_count($screen, "+ (pictures\n            ? '<div class=\"bns-th bns-th-m\""))->toBe(1);
});
