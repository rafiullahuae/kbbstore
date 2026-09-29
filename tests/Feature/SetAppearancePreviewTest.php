<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;
use Tests\Support\ArabicShop;
use Tests\Support\SetAppearanceRoutes;

/**
 * Appearance → Set: the preview document itself.                    (Lane SA3)
 *
 * Two defects and one new feature, all in one 130-line Blade:
 *
 *   ▲ IT HAD NO DIRECTION. The document opened with `lang="en" dir="ltr"`
 *     written into it and the controller had no locale handling at all, so the
 *     rendering most likely to be wrong — the mirrored one — was the one the
 *     owner could never look at. The "What is in this set" panel hangs its
 *     photographs off its LEADING edge and pads its four sides separately,
 *     which is precisely the thing that goes wrong in Arabic, and Lane AR2
 *     found the shipped box hanging its chips 16px past a panel whose leading
 *     padding it thought was 20 on /ar.
 *
 *   ▲ IT DREW ONE ROW. The owner, 29 September: *"the set row padding etc is
 *     disturbing the whole cart all rows, these controls must be apply only and
 *     only on Set rows."* With one row in the preview there is nothing to
 *     compare against, so a control that moved EVERY row and a control that
 *     moved only the set's row looked identical here. It draws both now.
 *
 *   • And the live overlay's <style> element, which the screen posts into.
 */
function saPreviewOwner(): AdminUser
{
    return AdminUser::query()->create([
        'name' => 'Owner',
        'email' => 'sa3-owner@example.test',
        'password' => 'secret-secret-1',
        'role' => 'owner',
    ]);
}

/** The mirrored layout, which ArabicShop::on() deliberately does NOT set. */
function saMirrorOn(): void
{
    Setting::query()->updateOrCreate(
        ['key' => Locale::SETTING_RTL],
        ['value' => '1', 'autoload' => true]
    );

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

it('draws a set row and an ordinary row, so a Set-row control can be seen landing on one', function () {
    /*
     * MUTATION NOTE — RUN. Delete the second `<div class="ci">` block from
     * resources/views/admin/previews/set-appearance.blade.php and this goes red
     * on the ordinary row: the preview would be back to a state in which
     * `.ci.ci` and `.ci.ci-set` are indistinguishable, which is how the defect
     * the owner caught stayed invisible for a round.
     */
    SetAppearanceRoutes::wire($this->app);
    $this->actingAs(saPreviewOwner(), 'admin');

    $html = $this->post('/admin-api/set-appearance/preview', ['settings' => []])
        ->assertOk()
        ->getContent();

    // The real basket shape, so `.kbb-cartpage .items .ci.ci-set` selects.
    expect($html)->toContain('<div class="kbb-cartpage">')
        ->toContain('<div class="items">')
        ->toContain('<div class="ci ci-set">');

    // And one line that is NOT a set, which is the whole point.
    /* The MARKUP's two rows, counted on the class attribute — `ci-set` also
       appears four times in the stylesheet css() emits, which is the selector
       these rows exist to demonstrate. */
    expect(substr_count($html, '<div class="ci'))->toBe(2);
    expect(substr_count($html, '<div class="ci ci-set">'))->toBe(1);
    expect(substr_count($html, '<div class="ci">'))->toBe(1);

    /* The base declarations the owner's numbers land on top of. Without them
       the ordinary row has no padding at all, so the two rows differ for the
       wrong reason and the preview stops proving anything. */
    expect($html)->toContain('.kbb-cartpage .ci{display:flex;gap:12px;align-items:center;padding:11px 14px');
});

it('renders left to right in English and mirrors for Arabic once the layout switch is on', function () {
    /*
     * BOTH HALVES MATTER. `dir` follows Locale::direction(), which is gated on
     * `language_rtl_enabled` — so a shop still finishing its mirrored
     * stylesheet previews Arabic words in a left-to-right document, because
     * that is what it serves. A preview that mirrored regardless would be
     * showing the owner a page his shop does not draw.
     *
     * MUTATION NOTE — RUN. Change the controller's `'dir' => Locale::direction($locale)`
     * to `Locale::LOCALES[$locale]['dir']` and the third block below goes red:
     * Arabic-without-the-switch starts claiming rtl.
     */
    SetAppearanceRoutes::wire($this->app);
    $this->actingAs(saPreviewOwner(), 'admin');

    $english = $this->post('/admin-api/set-appearance/preview', ['settings' => []])
        ->assertOk()->getContent();

    expect($english)->toContain('<html lang="en" dir="ltr">');
    // The ELEMENT, not the class name: the rule for it is in every document.
    expect($english)->not->toContain('<div class="sap-dirnote">');

    ArabicShop::on();

    // Arabic words, left-to-right layout: the shop's own state until the second
    // switch is thrown, and the document says so rather than hiding it.
    $unmirrored = $this->post('/admin-api/set-appearance/preview', ['settings' => [], 'locale' => 'ar'])
        ->assertOk()->getContent();

    expect($unmirrored)->toContain('<html lang="ar" dir="ltr">')
        ->toContain('<div class="sap-dirnote">')
        ->toContain('language_rtl_enabled');

    saMirrorOn();

    $mirrored = $this->post('/admin-api/set-appearance/preview', ['settings' => [], 'locale' => 'ar'])
        ->assertOk()->getContent();

    expect($mirrored)->toContain('<html lang="ar" dir="rtl">')
        ->not->toContain('<div class="sap-dirnote">');
});

it('takes one of the shop’s own languages or the default, and never the string that arrived', function () {
    /*
     * Rule 5, pointed at a locale. What arrives here goes into `<html lang>`
     * AND into App::setLocale(), which decides which translation files are
     * read — so an unchecked value is a path fragment with an opinion.
     *
     * MUTATION NOTE — RUN. Delete the `Locale::isSupported($locale) ? … :`
     * line from SetAppearanceApiController::preview() and the first
     * expectation goes red with `lang="../../etc"` in the document.
     */
    SetAppearanceRoutes::wire($this->app);
    $this->actingAs(saPreviewOwner(), 'admin');

    foreach (['../../etc', 'fr', 'ar-SA', '', 'en-GB', '<script>'] as $bad) {
        $html = $this->post('/admin-api/set-appearance/preview', ['settings' => [], 'locale' => $bad])
            ->assertOk()->getContent();

        expect($html)->toContain('<html lang="en" dir="ltr">');
    }

    expect(Locale::isSupported('ar'))->toBeTrue('…and a language the shop really has still works.');
});

it('puts the console back in its own language whatever the render does', function () {
    /*
     * The preview renders inside App::setLocale(), and this process serves the
     * next admin request too. A locale left set would hand the NEXT screen —
     * any screen — Arabic strings for an English console, which is the exact
     * shape of the Setting::map() trap CLAUDE.md records: state left behind in
     * a long-lived process by something that looked like it only affected
     * itself.
     *
     * MUTATION NOTE — RUN. Delete the `finally { app()->setLocale($previous); }`
     * from SetAppearanceApiController::preview() and this goes red.
     */
    SetAppearanceRoutes::wire($this->app);
    $this->actingAs(saPreviewOwner(), 'admin');

    ArabicShop::on();

    $before = app()->getLocale();

    $this->post('/admin-api/set-appearance/preview', ['settings' => [], 'locale' => 'ar'])->assertOk();

    expect(app()->getLocale())->toBe($before);
});

it('carries an empty overlay sheet after the server’s, and never before it', function () {
    /*
     * The screen writes the live values into `#kbb-set-live` by postMessage.
     * It wins over `#kbb-set` on DOCUMENT ORDER alone — same selectors, same
     * specificity, no !important and no extra class — so the order of the two
     * elements is load-bearing rather than tidy, and it ships EMPTY so that a
     * preview nobody has touched is exactly the server's answer.
     *
     * MUTATION NOTE — RUN. Move the `<style id="kbb-set-live">` element above
     * `<style id="kbb-set">` in the preview Blade and this goes red.
     */
    SetAppearanceRoutes::wire($this->app);
    $this->actingAs(saPreviewOwner(), 'admin');

    $html = $this->post('/admin-api/set-appearance/preview', ['settings' => []])
        ->assertOk()->getContent();

    expect($html)->toContain('<style id="kbb-set-live"></style>');
    expect(strpos($html, '<style id="kbb-set">'))
        ->toBeLessThan(strpos($html, '<style id="kbb-set-live">'));
});

it('hands the screen the live map and the languages, and still writes nothing', function () {
    SetAppearanceRoutes::wire($this->app);
    $this->actingAs(saPreviewOwner(), 'admin');

    $body = $this->getJson('/admin-api/set-appearance')->assertOk()->json();

    expect($body)->toHaveKeys(['tabs', 'defaults', 'live', 'locales']);
    expect($body['live'])->toHaveKeys(['blocks', 'fields']);
    expect(count($body['live']['fields']))->toBe(160);

    expect(collect($body['locales'])->pluck('code')->all())->toBe(['en', 'ar']);

    /* And a preview really does write nothing: every setting is still at the
       value the package shipped with after asking for one that is not.
       This is rule 1 on the endpoint the owner will hit hundreds of times in a
       session — a preview that saved would move the live shop on every drag. */
    $this->post('/admin-api/set-appearance/preview', [
        'settings' => ['circle' => 150, 'p_panel_bg' => '#123456'],
    ])->assertOk();

    SettingsService::forgetMemo();

    $after = app(\App\Services\SetAppearance::class)->all();

    expect($after)->toBe(\App\Services\SetAppearance::defaults());
    expect(app(\App\Services\SetAppearance::class)->storefrontCss())->toBe('');
});
