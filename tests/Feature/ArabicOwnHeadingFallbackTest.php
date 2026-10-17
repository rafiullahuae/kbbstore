<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\HomeArabic;
use App\Support\HomeBundles;
use App\Support\HomeSections;
use Illuminate\Support\Facades\DB;

/**
 * The owner's own English homepage heading on /ar, with no Arabic typed for it.
 *
 * 17 October 2026, after 2.60.536: the live Arabic homepage still headed a
 * section "Recommended for you" in ENGLISH, with its Arabic line and "عرض
 * الكل" right under it. It was not a Grid section (2.60.536 fixed those) but a
 * homepage section whose heading he had retyped in Homepage content. Its
 * Arabic twin had no row, so HomeArabic::pick() dropped the shipped Arabic
 * (a translation of a heading that is gone) and printed his English -- even
 * though the shop already says "Recommended for you" in Arabic on the cart
 * page (wording.cpage_00, "مقترحة لكِ").
 *
 * Now: his Arabic twin if he wrote one; else the shop's own Arabic for an
 * English sentence it already translates; else his English. And
 * 2027_11_10_200000 writes the twin itself, so the admin box shows it.
 *
 * MUTATION: put `return $english;` back in HomeArabic::pick() (or twin()) and
 * the first test is red; drop the migration's insert and the last is red.
 */
function aohSet(array $rows): array
{
    foreach ($rows as $k => $v) {
        DB::table('settings')->updateOrInsert(['key' => $k], ['value' => $v, 'autoload' => 1]);
    }
    Setting::flushMap();
    SettingsService::forgetMemo();

    return HomeSections::settings();
}

afterEach(fn () => app()->setLocale('en'));

it('prints his "Recommended for you" heading in Arabic on /ar when he typed no Arabic, in every kind of section', function () {
    $c = aohSet(['home_bs_title' => 'Recommended for you', 'home_tr_title' => 'Recommended for you', 'home_hb_title' => 'Recommended for you']);

    app()->setLocale('ar');
    expect(HomeSections::text($c, 'home_bs_title'))->toBe('مقترحة لكِ')
        ->and(HomeSections::text($c, 'home_tr_title'))->toBe('مقترحة لكِ')
        ->and(HomeBundles::config()['title'])->toBe('مقترحة لكِ');

    // English: exactly what he typed.
    app()->setLocale('en');
    expect(HomeSections::text($c, 'home_bs_title'))->toBe('Recommended for you')
        ->and(HomeBundles::config()['title'])->toBe('Recommended for you');
});

it('still prints his own Arabic first, and his English when the shop has no Arabic for it', function () {
    $c = aohSet(['home_bs_title' => 'Recommended for you', 'home_bs_title_ar' => 'اخترناها لكِ', 'home_tr_title' => 'My very own words']);

    app()->setLocale('ar');
    expect(HomeSections::text($c, 'home_bs_title'))->toBe('اخترناها لكِ')
        ->and(HomeSections::text($c, 'home_tr_title'))->toBe('My very own words');

    // The shipped heading and its shipped Arabic are untouched.
    $c = aohSet(['home_u54_title' => (string) HomeSections::SCHEMA['home_u54_title']['default']]);
    expect(HomeSections::text($c, 'home_u54_title'))->toBe(HomeArabic::TEXT['home_u54_title']);
});

it('writes "مقترحة لكِ" into the Arabic box of every field that says "Recommended for you", and never over his Arabic', function () {
    aohSet(['home_bs_title' => 'Recommended for you', 'home_hb_title' => ' recommended  for YOU ', 'home_tr_title' => 'Recommended for you',
        'home_tr_title_ar' => 'عربيته', 'home_u54_title' => 'Something else']);

    (require database_path('migrations/2027_11_10_200000_arabic_recommended_for_you_heading.php'))->up();

    $ar = DB::table('settings')->where('key', 'like', 'home_%_title_ar')->pluck('value', 'key')->all();

    expect($ar['home_bs_title_ar'] ?? null)->toBe('مقترحة لكِ')
        ->and($ar['home_hb_title_ar'] ?? null)->toBe('مقترحة لكِ')
        ->and($ar['home_tr_title_ar'])->toBe('عربيته')
        ->and($ar)->not->toHaveKey('home_u54_title_ar');

    $src = (string) file_get_contents(database_path('migrations/2027_11_10_200000_arabic_recommended_for_you_heading.php'));
    expect($src)->not->toMatch('/\\\\?App\\\\/'); // literals only: no shop class in a migration
});
