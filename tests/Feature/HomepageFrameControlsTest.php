<?php

declare(strict_types=1);

/**
 * THE TWO CONTROLS EXIST, SAVE, AND SURVIVE THE ROUND TRIP.         (Lane BG)
 *
 * `Appearance → Homepage → <section> → Background` and `→ Section width`.
 *
 * ── WHY THIS IS A SEPARATE FILE FROM HomepageSectionFrameTest ───────────────
 *
 * That one is about what the SHOP draws. This one is about the CONSOLE, and the
 * two fail for different reasons: the storefront can be perfect while the save
 * endpoint drops the field on the floor, which is the "built, never wired up"
 * shape this repo keeps finding — a setting the owner changes, that reports
 * saved, and that reads back at its default.
 *
 * It drives the CONTROLLER rather than the service, for the reason
 * GridSectionHomepageOrderTest gives one file along: the places a field gets
 * dropped are `sectionRules()` and `payloadFor()`, and a service-level test
 * stays green through both.
 *
 * ── AND ONE CASE IS ABOUT A MEMO KEY, WHICH IS THE SUBTLEST THING HERE ──────
 *
 * ModuleSchema::normalised() is `self::$normalised[$key] ??= …`, a
 * process-level memo. Its key was the section's SKIN default, which was right
 * while the skin was the only thing that varied by section. `cards_banner` now
 * varies the WIDTH default too and has no skin — so its key would have been the
 * same string every other skinless section produces, and whichever was read
 * first in the process would have decided the width default for all of them.
 * Read the banner first and the whole page goes full-bleed; read the hero first
 * and the banner never leaves its card. Both are one cached array away from the
 * other and neither errors, which is why it is pinned by ORDER below.
 */

use App\Models\AdminUser;
use App\Services\HomepageSections;
use App\Services\ModuleSchema;
use App\Services\SettingsService;

function hfcAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'HFC', 'email' => 'hfc-' . uniqid() . '@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);
}

/** Every section, at its current values, with $spec merged over named rows. */
function hfcRows(array $spec = []): array
{
    $rows = [];

    foreach (app(HomepageSections::class)->all() as $key => $row) {
        $rows[] = [
            'key' => $key,
            'desktop' => $row['desktop'],
            'mobile' => $row['mobile'],
            'skin' => $row['skin'],
            'background' => $row['background'],
            'width' => $row['width'],
        ] ;
    }

    foreach ($rows as $i => $row) {
        if (isset($spec[$row['key']])) {
            $rows[$i] = array_merge($row, $spec[$row['key']]);
        }
    }

    return $rows;
}

beforeEach(function () {
    SettingsService::forgetMemo();
});

it('sends the two option lists to the screen', function () {
    /*
     * The console draws its own row rather than going through
     * ModuleSchema::tabs(), so it needs the options as DATA. A list written
     * into the console's JavaScript instead is how a screen comes to offer a
     * token the storefront has stopped drawing.
     *
     * MUTATION NOTE: delete the two `collect(...)` lines from show() and this
     * goes red — the keys are simply absent and the selects render empty.
     */
    $body = test()->actingAs(hfcAdmin(), 'admin')
        ->getJson('/admin-api/homepage')->assertOk()->json();

    expect(array_column($body['backgrounds'], 'key'))->toBe(array_keys(HomepageSections::BACKGROUNDS))
        ->and(array_column($body['widths'], 'key'))->toBe(array_keys(HomepageSections::WIDTHS))
        // every option has a label the owner can read
        ->and(collect($body['widths'])->every(fn ($o) => strlen((string) $o['label']) > 8))->toBeTrue();

    // And the shipped values are what the screen is told they are.
    $banner = collect($body['sections'])->firstWhere('key', 'cards_banner');
    $hero = collect($body['sections'])->firstWhere('key', 'hero');

    expect($banner['width'])->toBe('bleed')
        ->and($banner['background'])->toBe('off')
        ->and($hero['width'])->toBe('normal')
        ->and($hero['background'])->toBe('off');
});

it('saves a background and a width through the endpoint', function () {
    /*
     * MUTATION NOTE: take `'background'` out of payloadFor()'s array and this
     * goes red — the POST answers ok, `saved` counts every section, and the
     * value reads back as 'off'. That is exactly the "reports saved, reads
     * back at its default" failure this file exists for.
     */
    test()->actingAs(hfcAdmin(), 'admin')
        ->postJson('/admin-api/homepage', ['sections' => hfcRows([
            'newsletter' => ['background' => 'panel'],
            'about' => ['width' => 'full'],
        ])])
        ->assertOk();

    SettingsService::forgetMemo();

    $all = app(HomepageSections::class)->all();

    expect($all['newsletter']['background'])->toBe('panel')
        ->and($all['about']['width'])->toBe('full')
        // and the sections nobody touched are still at the shipped values
        ->and($all['reviews']['background'])->toBe('off')
        ->and($all['reviews']['width'])->toBe('normal')
        ->and($all['cards_banner']['width'])->toBe('bleed');
});

it('refuses nothing and stores nothing for a token that is not an option', function () {
    /*
     * The endpoint's rules are `nullable|string|max:20` and the VALUES are
     * checked once, in SECTION_SCHEMA. So a bad token is accepted by the
     * request and replaced by the default on the way into storage rather than
     * 422ing the whole save — which is the policy this screen has always had
     * for `skin`, stated for these two.
     *
     * MUTATION NOTE: change SECTION_POLICY's `invalid` to 'keep' and this goes
     * red with the raw token stored.
     */
    test()->actingAs(hfcAdmin(), 'admin')
        ->postJson('/admin-api/homepage', ['sections' => hfcRows([
            'newsletter' => ['background' => 'chartreuse', 'width' => '3000px'],
        ])])
        ->assertOk();

    SettingsService::forgetMemo();

    $all = app(HomepageSections::class)->all();

    expect($all['newsletter']['background'])->toBe('off')
        ->and($all['newsletter']['width'])->toBe('normal');
});

it('gives each section its own width default however the fields are read', function () {
    /*
     * THE MEMO KEY. Read in BOTH orders in one process, because the defect is
     * order-dependent: the first read populates ModuleSchema::$normalised and
     * every later read with the same key gets that array back.
     *
     * MUTATION NOTE: change fieldsFor()'s key back to
     * `self::class.':'.$defaultSkin` and this goes red — `hero` and
     * `cards_banner` both produce the key `App\Services\HomepageSections:`
     * (neither has a skin), so whichever is asked first decides both. Whether
     * the failure is "the banner is normal" or "the hero is bleed" depends on
     * which of the two loops below runs in a fresh process, which is the point.
     */
    hfcFlushNormalised();

    $bannerFirst = [
        'cards_banner' => hfcWidthDefault('cards_banner'),
        'hero' => hfcWidthDefault('hero'),
    ];

    hfcFlushNormalised();

    $heroFirst = [
        'hero' => hfcWidthDefault('hero'),
        'cards_banner' => hfcWidthDefault('cards_banner'),
    ];

    expect($bannerFirst)->toBe(['cards_banner' => 'bleed', 'hero' => 'normal'])
        ->and($heroFirst)->toBe(['hero' => 'normal', 'cards_banner' => 'bleed']);
});

/**
 * Empty ModuleSchema's process-level memo.
 *
 * BY REFLECTION, AND ON PURPOSE. The alternative was a public flush method on
 * ModuleSchema, which would be production API existing only so that a test can
 * call it — and a memo that anything can clear is a memo whose behaviour is
 * harder to reason about, not easier. The private static is the thing under
 * test here; reaching it directly is the honest way to ask the question.
 */
function hfcFlushNormalised(): void
{
    $prop = new ReflectionProperty(ModuleSchema::class, 'normalised');
    $prop->setAccessible(true);
    $prop->setValue(null, []);
}

/**
 * The width a section answers with when nothing has been saved for it.
 *
 * Read through all(), which is the reader both the console and the storefront
 * go through, so this asks the question the way the shop asks it.
 */
function hfcWidthDefault(string $key): string
{
    app(SettingsService::class)->set('homepage_sections', []);
    SettingsService::forgetMemo();

    return (string) app(HomepageSections::class)->all()[$key]['width'];
}
