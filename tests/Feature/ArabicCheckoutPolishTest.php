<?php

declare(strict_types=1);

use App\Support\ArabicWording;
use Illuminate\Support\Facades\DB;

/**
 * Three things on the checkout the owner found on his phone (17 October 2026).
 *
 * 1. "you didn't translate the Tabby Installments for arabic version": the live
 *    Tabby title is "Tabby Installments", which matched no ArabicWording row,
 *    so /ar/checkout listed it in English.
 * 2. "the phone should come on the right align in the field": the tel box is
 *    left-to-right (as browsers keep it) and so started on the LEFT on /ar.
 * 3. "when i click the login option on the phone, it gives weird dark overlay
 *    ... also in english": the sign-in dialog put the cursor in the password
 *    box on open, which on a phone raised Chrome's "Use saved password?" sheet.
 *
 * MUTATION: drop the wording pair -- the first test is red; drop the tel rule
 * -- the third; drop either `!TOUCH &&` -- the fourth.
 */
it('names "Tabby Installments" in Arabic on /ar and leaves the English alone', function () {
    expect(ArabicWording::keyFor('Tabby Installments'))->toBe('wording.pay_tabby_installments')
        ->and(ArabicWording::ARABIC['wording.pay_tabby_installments'])->toBe('تقسيط مع تابي');
});

it('publishes that Arabic once on a shop that has no row for it, literals only, and keeps one he typed', function () {
    $file = database_path('migrations/2027_11_09_200000_arabic_tabby_installments_title.php');
    expect((string) file_get_contents($file))->not->toContain('use App\\')->not->toMatch('/\\\\App\\\\/');

    $field = 'store.wording.pay_tabby_installments';
    $row = fn () => DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)->where('field', $field);

    // The live shop: no row at all for this key.
    $row()->delete();
    (require $file)->up();
    expect($row()->count())->toBe(1)
        ->and($row()->value('status'))->toBe('published')
        ->and($row()->value('value'))->toBe('تقسيط مع تابي');

    // Twice changes nothing; a row he edited is his.
    $row()->update(['value' => 'تابي']);
    (require $file)->up();
    expect($row()->count())->toBe(1)->and($row()->value('value'))->toBe('تابي');
});

it('puts a phone number on the right on Arabic pages, still left-to-right', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    expect($css)->toContain('[dir="rtl"] input[type="tel"]{text-align:right}')
        // ...with the icon's 42px on the right, where /ar draws the icon -- or
        // the number runs under it (measured: 10px clear, the mirror of English).
        ->toContain('[dir="rtl"] .fld.ico input[type="tel"]{padding-inline-start:14px;padding-inline-end:42px}');

    expect((string) file_get_contents(resource_path('css/kbb/kbb-checkout.css')))
        ->toContain('[dir="rtl"] .kbb-checkout .fld.kbb-fl.ico input[type="tel"]{padding-inline-start:13px;padding-inline-end:42px}');
});

it('opens the sign-in dialog on a phone with nothing focused, and as before on a computer', function () {
    $view = (string) file_get_contents(resource_path('views/partials/checkout/sign-in.blade.php'));

    expect($view)->toContain("var TOUCH = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);")
        ->toContain("if (!TOUCH && first && !first.closest('[hidden]')) { first.focus(); return; }")
        ->toContain("if (!TOUCH && \$('#kbbSiEmail').value) \$('#kbbSiPw').focus();");
});
