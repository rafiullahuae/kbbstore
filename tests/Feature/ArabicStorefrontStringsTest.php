<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\ArabicWording;
use App\Support\Countries;
use App\Support\CountryNames;
use Illuminate\Support\Facades\Validator;

/*
 * Lane AR stage 2 -- the rest of the storefront in Arabic: the sentences the
 * controllers say to a shopper, the framework's validation messages, country
 * names, payment method names, the cart heading's count. Each case says what
 * an Arabic shopper saw before, and English is the other half of every case:
 * it must not change by a byte.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

afterEach(function () {
    app()->setLocale('en');
});

/*
 * 1. A REFUSED ARABIC FORM ANSWERED IN ENGLISH: "The email field is required."
 *    Laravel's validation group ships in English only. Mutation: drop the
 *    ArabicValidation layer in DatabaseTranslationLoader and the Arabic is red.
 */
it('answers a refused form in Arabic on /ar and in the framework English otherwise', function () {
    $en = Validator::make([], ['email' => 'required'])->errors()->first('email');
    expect($en)->toBe('The email field is required.');

    app()->setLocale('ar');
    app('translator')->setLoaded([]);
    $ar = Validator::make(['password' => 'x'], ['email' => 'required', 'password' => 'min:8'])->errors();
    expect($ar->first('email'))->toBe('حقل البريد الإلكتروني مطلوب.')
        ->and($ar->first('password'))->toBe('يجب ألا يقل كلمة المرور عن 8 أحرف.');
});

/*
 * 2. EVERY CONTROLLER SENTENCE A SHOPPER READS GOES THROUGH A KEY, and the
 *    English of each key is the literal it replaced, word for word.
 *    Mutation: put any literal back and the source scan is red; change an
 *    English value and the word-for-word check is red.
 */
it('routes every shopper-facing controller sentence through a key, English unchanged', function () {
    $files = ['CustomerAuthController', 'PasswordResetController', 'ReviewController', 'EmailVerificationController',
        'CartController', 'CartAddressController', 'AddressController', 'StockAlertController', 'CheckoutController'];
    $src = '';
    foreach ($files as $f) {
        $src .= (string) file_get_contents(app_path('Http/Controllers/Store/'.$f.'.php'));
    }
    foreach (['Those details did not match our records.', 'That product is sold out.', 'Your bag is empty — please start again from the cart.',
        'That payment method is not available.', 'Thank you! Your review is awaiting approval.', "'Too many attempts. Try again in '"] as $literal) {
        expect(substr_count($src, "=> '".$literal) + substr_count($src, "\$request, '".$literal) + substr_count($src, $literal === "'Too many attempts. Try again in '" ? $literal : "\0"))
            ->toBe(0, $literal);
    }

    expect((string) __('store.wording.msg_login_failed'))->toBe('Those details did not match our records.')
        ->and((string) __('store.wording.msg_too_many', ['seconds' => 42]))->toBe('Too many attempts. Try again in 42 seconds.')
        ->and(ArabicWording::of(\App\Http\Controllers\Store\PasswordResetController::SENT_MESSAGE))
        ->toBe(\App\Http\Controllers\Store\PasswordResetController::SENT_MESSAGE);

    app()->setLocale('ar');
    expect(ArabicWording::of(\App\Http\Controllers\Store\PasswordResetController::SENT_MESSAGE))
        ->toBe((string) __('store.wording.msg_pw_sent'));
});

/*
 * 3. THE ADDRESS FORM LISTED NINETY COUNTRIES IN ENGLISH on /ar. Shipped as
 *    data (no intl extension needed on the server). Mutation: return the
 *    English from CountryNames::label() on /ar and this is red.
 */
it('names countries in Arabic on /ar and in the shop English otherwise', function () {
    expect(CountryNames::label('KR'))->toBe('South Korea')
        ->and(array_keys(CountryNames::ARABIC))->toBe(array_keys(Countries::NAMES));

    app()->setLocale('ar');
    expect(CountryNames::label('KR'))->toBe('كوريا الجنوبية')
        ->and(CountryNames::label('SA'))->not->toMatch('/[A-Za-z]/')
        ->and(CountryNames::label('ZZ', 'Atlantis'))->toBe('Atlantis');
    foreach (CountryNames::ARABIC as $code => $name) {
        expect($name)->not->toMatch('/[A-Za-z]{2,}/', $code);
    }
});

/*
 * 4. PAYMENT METHOD NAMES AND LINES IN ARABIC, ESCAPED. The checkout prints
 *    the line raw (it carries the fee's markup), so a stored Arabic draft must
 *    come back escaped; English passes through untouched, and the order record
 *    is never rewritten (only the views translate). Mutation: drop e() in
 *    payLine() and the markup case is red.
 */
it('translates payment names for display only and escapes the stored Arabic', function () {
    expect(ArabicWording::payLine('Pay in cash to the courier.'))->toBe('Pay in cash to the courier.')
        ->and(ArabicWording::of('Tabby: Pay in 4 Installments'))->toBe('Tabby: Pay in 4 Installments');

    \Tests\Support\ArabicShop::on();
    \Tests\Support\ArabicShop::string('store.wording.pay_cod_desc', 'ادفعي <b>نقدًا</b>');
    app()->setLocale('ar');
    expect(ArabicWording::payLine('Pay in cash to the courier.'))->not->toContain('<b>')
        ->and(ArabicWording::payLine('Pay in cash to the courier. A small <span class="x">AED 5</span> handling fee applies.'))
        ->toContain('<span class="x">AED 5</span>');

    expect((string) file_get_contents(resource_path('views/partials/checkout/payment-methods.blade.php')))
        ->toContain('ArabicWording::of($g[\'title\'])')
        ->toContain('ArabicWording::payLine($g[\'description\'])');
});

/*
 * 5. THE CART HEADING SAID "(3 items)" ON /ar after any change, because cart.js
 *    built it in English. The server now sends it -- only on a page that is not
 *    English, so an English cart answer is the one it always was.
 */
it('sends the cart heading count in the page language, and nothing new in English', function () {
    $src = (string) file_get_contents(app_path('Http/Controllers/Store/CartController.php'));
    expect($src)->toContain("\\App\\Support\\Locale::isDefault() ? [] : [")
        ->and((string) file_get_contents(resource_path('js/kbb/cart.js')))->toContain("typeof data.lead === 'string' ? data.lead");
});

/*
 * 6. THE CART PAGE'S OWN WORDS (Appearance → Cart page) WERE ENGLISH ON /ar:
 *    "Recommended for you", "Order Value", "Choose location", "Shipping
 *    calculated at checkout". Translated in CartPage::all() -- the view the
 *    coupon lane owns is not touched -- and only while the value is still the
 *    shipped English. Mutation: drop the ArabicWording loop in all() and the
 *    Arabic half is red; translate unconditionally and the owner's-own case is.
 */
it('reads the cart page wording in Arabic on /ar, as shipped only, and English otherwise', function () {
    $page = app(\App\Services\CartPage::class);
    expect($page->get('rec_heading'))->toBe('Recommended for you')
        ->and($page->get('sheet_list_title'))->toBe('Choose location');

    \Tests\Support\ArabicShop::on();
    \Tests\Support\ArabicShop::string('store.wording.cpage_00', 'مقترحة لكِ');
    app()->setLocale('ar');
    expect($page->get('rec_heading'))->toBe('مقترحة لكِ');

    app(SettingsService::class)->set('cartpage_rec_heading', 'My picks');
    SettingsService::forgetMemo();
    expect(app(\App\Services\CartPage::class)->get('rec_heading'))->toBe('My picks');
});

/*
 * 7. THE FOOTER WORDMARK, THE ARROW LABEL AND THE SIGN-IN CHECK. The slim
 *    footer drew "K-Beauty Bliss" in Latin on /ar, its arrow said "Back to top",
 *    and the sign-in sum read "What is 3 + 4?". Mutation: remove the Arabic
 *    branch in SlimFooter::headerLogo() and this is red.
 */
it('draws the footer wordmark, arrow label and sign-in check in Arabic on /ar', function () {
    expect(app(\App\Services\SlimFooter::class)->headerLogo()['text'])->toBe('K-Beauty');

    app()->setLocale('ar');
    $logo = app(\App\Services\SlimFooter::class)->headerLogo();
    expect($logo['text'].$logo['accent'])->toBe('كي بيوتي بليس')
        ->and(ArabicWording::of('Back to top'))->toBe((string) __('store.wording.top_label'))
        ->and(ArabicWording::of('What is'))->toBe((string) __('store.wording.hc_what_is'));

    expect((string) file_get_contents(resource_path('views/partials/slim-footer.blade.php')))->toContain("'l3_text', 'top_label'] as \$sfK")
        ->and((string) file_get_contents(resource_path('views/store/account/login.blade.php')))->toContain("ArabicWording::of(\$panel['sum_label'])");
});
