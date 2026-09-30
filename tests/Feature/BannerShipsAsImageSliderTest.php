<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\BannerApiController;
use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Services\HeaderSettings;
use App\Services\ModuleRegistry;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * THE BANNER THE OWNER ASKED FOR, AS THE SHIPPED STATE.             Lane SEC
 *
 * His words, whole:
 *
 *   "The top countries bar, i need under banner, and the banner i need to
 *    change to simple image banners, not cards, simple only images banner,
 *    with slide if multiple and user can slide left to right and right to
 *    left. if single image, then no sliding. apply this on desktop and mobile
 *    both. for desktop the size should be 1920 x 550 and in mobile 500 x 600"
 *
 * and, when asked whether that should be controls rather than the shipped
 * state: "whatever i said, keep applying on the site, don't let me know that
 * change from the backend, i have the options on backend, i want to apply such
 * things directly to the site to save time."
 *
 * ── WHAT THIS FILE IS FOR THAT THE OTHER BANNER FILES ARE NOT ───────────────
 *
 * SliderBannerTest asserts the slider draws correctly. This asserts the SHOP
 * SHIPS DRAWING IT, which is a different claim and the one the owner made — a
 * feature that works perfectly behind an off switch is the fault
 * docs/FO-HOMEPAGE-INVENTORY.md catalogues, and `instagram` sat in the registry
 * for a whole release moving a row on a screen and nothing on the shop.
 *
 * A default here lives in FOUR places and each is load-bearing, because
 * `banner_sets.kind` is a real column with a database default of `cards`: every
 * row already on the shop carries the string, so neither the model's fallback
 * nor the create path is ever consulted for it. Take any one away and the
 * owner's own banner goes on drawing cards while three assertions elsewhere say
 * it does not.
 *
 *   BannerSet::$attributes        a model built from nothing
 *   BannerSet::kind() / ratios    a row whose column is null or nonsense
 *   BannerApiController@store     a set created through the API
 *   the migration                 THE ROWS THAT ALREADY EXIST
 *
 * The first three are pinned in SliderBannerTest's `it ships at slider, at the
 * two shapes the owner gave in pixels`. This file is the fourth, run against
 * real rows, plus the two claims that are only true of the whole shop: the
 * module ships armed, and a single picture does not slide.
 */

/** Rows in the state the shop was in before this round: cards, at 16/9 and 4/3. */
function bsisLegacySet(string $slug, int $pictures = 2, string $status = 'publish'): BannerSet
{
    $set = BannerSet::create([
        'name' => 'Legacy '.$slug, 'slug' => $slug, 'status' => $status, 'position' => 0,
        'kind' => 'cards', 'slider_ratio' => '16/9', 'slider_ratio_m' => '4/3',
    ]);

    foreach (range(1, $pictures) as $i) {
        BannerCard::create([
            'banner_set_id' => $set->id, 'image' => 'uploads/banners/'.$slug.'-'.$i.'.webp',
            'alt' => 'A'.$i, 'heading' => 'H'.$i, 'body' => 'B'.$i,
            'button_label' => 'Shop', 'button_url' => '/shop/',
            'position' => $i, 'status' => 'publish',
        ]);
    }

    return $set;
}

/** The migration under test, run by hand against whatever is in the tables. */
function bsisRunMigration(): void
{
    $path = base_path('database/migrations/2027_06_05_000000_banner_ships_as_image_slider.php');

    /** @var \Illuminate\Database\Migrations\Migration $migration */
    $migration = require $path;

    $migration->up();

    SettingsService::forgetMemo();
    \Illuminate\Support\Facades\Cache::forget('kbb.settings');
    \Illuminate\Support\Facades\Cache::forget('kbb.modules');
    \Illuminate\Support\Facades\Cache::forget('kbb.module_settings');
}

it('moves the rows that already exist off cards and onto his two sizes', function () {
    /*
     * THE HALF A DEFAULT CANNOT DO, and the reason this file exists at all.
     *
     * The migration has already run once as part of this suite's database
     * build, against an empty `banner_sets`, so running it again here is the
     * only way to see it do anything. That is not a trick: it is the same
     * situation on the live shop, where the table is full when the package
     * applies.
     *
     * MUTATION, run: delete the three `update()` calls from the migration's
     * up() and this is red on the first three expectations while every default
     * assertion in SliderBannerTest stays green — which is precisely the
     * "looked applied and moved nothing" failure the four-place note above is
     * about.
     */
    $legacy = bsisLegacySet('bsis-legacy');

    expect($legacy->fresh()->kind)->toBe('cards');

    bsisRunMigration();

    $moved = $legacy->fresh();

    expect($moved->kind)->toBe('slider', 'the owner\'s existing banner still draws cards')
        ->and($moved->slider_ratio)->toBe('1920/550')
        ->and($moved->slider_ratio_m)->toBe('500/600')
        ->and($moved->homePartial())->toBe('partials.home.slider-banner')
        ->and($moved->sliderRatioCss())->toBe('1920 / 550')
        ->and($moved->sliderRatioMobileCss())->toBe('500 / 600');
});

it('leaves a shape the owner chose for himself alone', function () {
    /*
     * THE SCOPING, AND IT IS THE ONE THING IN THIS MIGRATION THAT COULD HAVE
     * COST HIM WORK. Each write is `where(<old default>)`, so a set he set to
     * 21:9 by hand keeps 21:9. Written as a blanket update it would have
     * overruled the screen he typed into — which is the opposite of "i have the
     * options on backend".
     *
     * MUTATION: drop the three `where()` clauses and this is red on the first
     * two expectations. Run, red, put back.
     */
    $mine = BannerSet::create([
        'name' => 'Chosen', 'slug' => 'bsis-chosen', 'status' => 'publish', 'position' => 1,
        'kind' => 'slider', 'slider_ratio' => '21/9', 'slider_ratio_m' => '1/1',
    ]);

    bsisRunMigration();

    $after = $mine->fresh();

    expect($after->slider_ratio)->toBe('21/9', 'a shape the owner picked was overwritten')
        ->and($after->slider_ratio_m)->toBe('1/1')
        ->and($after->kind)->toBe('slider');
});

it('arms the module and turns the countries strip on for both widths', function () {
    /*
     * The two writes that are not about a set. Both are read through a
     * whole-table cache, so the migration writes underneath the cache with the
     * query builder — Setting::map() memoises in a process-level static as well
     * as in the cache and a migration going through SettingsService would read
     * its own stale memo — and the paired `clear_caches_image_banner_and_strip`
     * drops the four keys. bsisRunMigration() above does the same forgetting,
     * which is why it is in the helper rather than in each case.
     */
    app(SettingsService::class)->setModule('cards_banner', false);
    app(HeaderSettings::class)->save(['fb_desktop' => false, 'fb_mobile' => false]);

    expect(app(Banners::class)->enabled())->toBeFalse()
        ->and(app(HeaderSettings::class)->all()['fb_desktop'])->toBeFalse();

    bsisRunMigration();

    expect(app(Banners::class)->enabled())->toBeTrue('the banner module is still off')
        ->and(app(HeaderSettings::class)->all()['fb_desktop'])->toBeTrue()
        ->and(app(HeaderSettings::class)->all()['fb_mobile'])->toBeTrue()
        ->and(app(HeaderSettings::class)->flagBarOn())->toBeTrue();

    // And the registry ships the same way, for a shop installed from scratch
    // rather than upgraded.
    expect(ModuleRegistry::REGISTRY['cards_banner'][3])->toBeTrue();
});

it('chooses the homepage set when exactly one would draw, and never when two would', function () {
    /*
     * ── THE ONE JUDGEMENT IN THE MIGRATION, AND WHY IT STOPS WHERE IT DOES ──
     *
     * Every other write above is inert until there is a picture: `forHome()`
     * requires a published card with a non-empty image. So a shop with a set
     * full of pictures and the setting at None would have had the module armed,
     * the kind moved, both shapes written — and nothing on the page. The
     * migration therefore picks the set, but only when there is exactly ONE
     * candidate. Two is a guess about which of his campaigns is current, and a
     * guess on the front page of a live shop is not a saving.
     *
     * MUTATION: change `=== 1` to `>= 1` in the migration and the second half
     * of this case is red — the two-candidate shop has a set chosen for it.
     */
    bsisLegacySet('bsis-only');

    expect(app(Banners::class)->all()['set'])->toBe('');

    bsisRunMigration();

    $chosen = app(Banners::class)->all()['set'];

    expect($chosen)->not->toBe('', 'the one set that could draw was not chosen');
    expect(app(Banners::class)->forHome())->not->toBeNull('the homepage still draws no banner');

    // Now a second publishable set, and the choice must not be revisited or
    // made on a shop that starts with two.
    app(SettingsService::class)->setModuleSetting(Banners::MODULE, 'set', '');
    bsisLegacySet('bsis-second');
    SettingsService::forgetMemo();

    bsisRunMigration();

    expect(app(Banners::class)->all()['set'])->toBe(
        '',
        'the migration picked one of two campaigns for him'
    );
});

it('does not choose a set whose only pictures are unpublished or missing', function () {
    /*
     * The candidate query is the same shape as forHome()'s: published set,
     * published card, non-empty image. A draft set or a text-only card is not a
     * candidate, or the migration would arm the homepage onto a set that draws
     * nothing and the owner would see the module on, a set chosen, and no
     * banner — which reads as broken rather than as empty.
     */
    bsisLegacySet('bsis-draft', 2, 'draft');

    $textOnly = bsisLegacySet('bsis-textless');
    BannerCard::query()->where('banner_set_id', $textOnly->id)->update(['image' => '']);

    bsisRunMigration();

    expect(app(Banners::class)->all()['set'])->toBe('')
        ->and(app(Banners::class)->forHome())->toBeNull();
});

it('slides when there are several pictures and stands still when there is one', function () {
    /*
     * ── "with slide if multiple ... if single image, then no sliding" ────────
     *
     * This was already true before the round and it is pinned here anyway,
     * because it is one of the four things he asked for and a claim with no
     * assertion behind it is a claim nobody can check. `Banners::sliderDwell()`
     * is the one place that decides the dwell, and `$bsSteerable = $bsCount > 1`
     * in the partial gates the arrows and the bars — so a single picture has no
     * timer, no previous, no next and no row of bars, and there is nothing on
     * the page for a swipe to move.
     *
     * BOTH HALVES, because either alone is satisfied by a slider that never
     * moves at all: the several-picture case asserts the controls ARE there.
     *
     * MUTATION: change `$bsCount > 1` to `$bsCount > 0` in the partial and the
     * one-picture case is red on the arrows; make sliderDwell() ignore the
     * count and it is red on `is-auto`.
     */
    $several = bsisLegacySet('bsis-many', 3);
    $several->update(['kind' => 'slider', 'show_arrows' => true, 'show_dots' => true, 'autoplay' => true]);

    [$set, $cards] = app(Banners::class)->forPreview($several->id);
    $many = view($set->homePartial(), ['set' => $set, 'cards' => $cards])->render();

    expect(Banners::sliderDwell($set, $cards))->toBeGreaterThan(0)
        ->and($many)->toContain('kbbs-prev')
        ->and($many)->toContain('kbbs-next')
        ->and($many)->toContain('is-auto');

    $one = bsisLegacySet('bsis-one', 1);
    $one->update(['kind' => 'slider', 'show_arrows' => true, 'show_dots' => true, 'autoplay' => true]);

    [$set1, $cards1] = app(Banners::class)->forPreview($one->id);
    $single = view($set1->homePartial(), ['set' => $set1, 'cards' => $cards1])->render();

    // The stylesheet and the script NAME every class, so the markup has to be
    // read with both stripped or these absences are false for every page this
    // partial has ever drawn. SliderBannerTest's own helper records why.
    $body = (string) preg_replace('#<script>.*?</script>#s', '',
        (string) preg_replace('#<style>.*?</style>#s', '', $single));

    expect(Banners::sliderDwell($set1, $cards1))->toBe(0)
        ->and($body)->not->toContain('kbbs-prev')
        ->and($body)->not->toContain('kbbs-next')
        ->and($body)->not->toContain('is-auto')
        // and the one picture IS drawn, or the two absences above are a
        // tautology over an empty section.
        ->and($body)->toContain('uploads/banners/bsis-one-1.webp');
});

it('creates a new set as a picture slider at the same two shapes', function () {
    /*
     * The create path and the model default must agree with the migration, or
     * the shop has two ideas about what a banner is depending on when the set
     * was made. Asserted against the controller's own constant-free source so
     * that a lane changing one of the three is told about the other two.
     */
    $fresh = new BannerSet;

    expect($fresh->kind())->toBe('slider')
        ->and($fresh->slider_ratio)->toBe('1920/550')
        ->and($fresh->slider_ratio_m)->toBe('500/600');

    $controller = (string) file_get_contents(
        (new ReflectionClass(BannerApiController::class))->getFileName()
    );

    expect($controller)->toContain("\$kind = (string) (\$data['kind'] ?? 'slider')")
        ->and($controller)->toContain("'slider_ratio' => '1920/550'")
        ->and($controller)->toContain("'slider_ratio_m' => '500/600'");

    // And both shapes are real presets, so the screen can show what the shop
    // is doing rather than falling through to a default it cannot name.
    expect(BannerSet::SLIDER_RATIOS)->toHaveKey('1920/550')
        ->and(BannerSet::SLIDER_RATIOS)->toHaveKey('500/600')
        ->and(BannerSet::SLIDER_RATIOS['1920/550'][1])->toBe('1920 / 550')
        ->and(BannerSet::SLIDER_RATIOS['500/600'][1])->toBe('500 / 600');
});

it('is reversible back to the shape it found', function () {
    /*
     * down() is asserted because a migration in this repository travels inside
     * an update package and UpdateRunner::rollback() calls it. It is NOT a
     * perfect inverse and the migration says so in its own docblock: a set the
     * owner had switched to `slider` himself is switched back to `cards`,
     * because after up() there is nothing in the row that tells the two apart.
     * Pinning the lossy behaviour is the point — a reader who needs a perfect
     * inverse needs a second column, which is a schema change to make a
     * rollback tidier than the forward path.
     */
    $legacy = bsisLegacySet('bsis-down');

    bsisRunMigration();

    expect($legacy->fresh()->kind)->toBe('slider');

    $migration = require base_path('database/migrations/2027_06_05_000000_banner_ships_as_image_slider.php');
    $migration->down();

    $back = $legacy->fresh();

    expect($back->kind)->toBe('cards')
        ->and($back->slider_ratio)->toBe('16/9')
        ->and($back->slider_ratio_m)->toBe('4/3');

    expect(DB::table('banner_sets')->where('kind', 'slider')->count())->toBe(0);
});
