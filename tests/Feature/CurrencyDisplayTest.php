<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminController;
use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Currencies;
use App\Support\Money;

/**
 * Currency display: the configurable symbol, the bidi fix, and the arithmetic
 * underneath both.
 *
 * Money used to be a constant and a hard-coded x100. Everything below exists
 * because making it configurable can go wrong in ways that are invisible until
 * they are on the live store: prices silently reformatted, a symbol reordered
 * around its own digits, an operator-supplied string landing unescaped in
 * every page, or a settings key that reports success and writes nothing.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
});

/** Write currency settings the way the admin screen does. */
function setCurrency(array $rows): void
{
    $svc = app(SettingsService::class);

    foreach ($rows as $key => $value) {
        $svc->set($key, (string) $value);
    }
}

// ---------------------------------------------------------------------------
// 1. Nothing configured: identical to what the store rendered before.
// ---------------------------------------------------------------------------

it('renders exactly what it rendered before when no currency settings exist', function () {
    expect(Setting::query()->where('key', 'like', 'currency%')->count())->toBe(0);

    // Whole dirhams, thousands separated, no decimal point — the WooCommerce
    // configuration the live store runs.
    expect(Money::amount(19900))->toBe('199')
        ->and(Money::amount(311200))->toBe('3,112')
        ->and(Money::amount(0))->toBe('0');

    // Same wrapper classes, same order, same number.
    expect(Money::format(19900))->toBe(
        '<span class="woocommerce-Price-amount amount" dir="ltr">'
        . '<span class="woocommerce-Price-currencySymbol" dir="auto">' . Currencies::AED_SYMBOL . '</span> '
        . '199</span>'
    );

    // Minor units are still hundredths, in both directions.
    expect(Money::toMajor(19900))->toBe(199.0)
        ->and(Money::fromMajor('199'))->toBe(19900)
        ->and(Money::minorExponent())->toBe(2)
        ->and(Money::displayDecimals())->toBe(0);
});

it('rounds to whole units the way number_format did, including the half case', function () {
    expect(Money::amount(150))->toBe('2')      // 1.50 -> 2
        ->and(Money::amount(149))->toBe('1')   // 1.49 -> 1
        ->and(Money::amount(-150))->toBe('-2')
        ->and(Money::amount(99))->toBe('1')
        ->and(Money::amount(49))->toBe('0');
});

it('keeps the deprecated toAed/fromAed aliases working for the existing call sites', function () {
    expect(Money::toAed(19900))->toBe(Money::toMajor(19900))
        ->and(Money::fromAed('12.34'))->toBe(1234);
});

// ---------------------------------------------------------------------------
// 2. The symbol.
// ---------------------------------------------------------------------------

/*
 * The default was U+20C3 briefly. It was tried on a real device and drew as an
 * empty box -- no shipped font has the glyph before Unicode 18.0 -- so the
 * default is the three letters every device can already render. The sign stays
 * available as a constant, as a value for the Symbol field, and through the
 * drawn-glyph rendering mode.
 */
it('defaults to the letters AED, which every device can render', function () {
    expect(Money::symbol())->toBe('AED')
        ->and(Currencies::AED_SYMBOL)->toBe('AED')
        ->and(Currencies::AED_SIGN)->toBe("\u{20C3}")
        ->and(Money::plain(19900))->toBe('AED 199')
        ->and(Money::format(19900))->toContain('AED');

    // The constant survives as the documented last-resort fallback.
    expect(Money::SYMBOL)->toBe('AED');
});

it('falls back to the legacy constant for a currency it has never heard of', function () {
    setCurrency(['currency' => 'XTS']);

    expect(Currencies::has('XTS'))->toBeFalse()
        ->and(Money::symbol())->toBe(Money::SYMBOL);
});

it('lets the operator override the symbol for any currency', function () {
    setCurrency(['currency' => 'AED', 'currency_symbol' => 'AED']);

    // A letter-based symbol takes a space with no explicit position set.
    expect(Money::symbol())->toBe('AED')
        ->and(Money::plain(19900))->toBe('AED 199');
});

// ---------------------------------------------------------------------------
// 3. The bidi bug. "Free delivery over د.إ199" used to render as 199|د.إ.
// ---------------------------------------------------------------------------

it('isolates the symbol so an RTL one cannot swap places with the digits', function () {
    setCurrency(['currency_symbol' => 'د.إ']);

    $html = Money::format(19900);

    // The price is its own LTR run: per the HTML rendering rules a dir
    // attribute also applies unicode-bidi: isolate, so the sentence around it
    // cannot reach in and reorder it.
    expect($html)->toContain('<span class="woocommerce-Price-amount amount" dir="ltr">');

    // And the symbol is its own isolate inside that, so the Arabic letters lay
    // themselves out RTL within their own box instead of dragging the number
    // across. dir="auto" rather than dir="rtl" because the same wrapper has to
    // be right for "$" and "kr" too.
    expect($html)->toContain('<span class="woocommerce-Price-currencySymbol" dir="auto">د.إ</span>');

    // The regression itself: symbol and digits adjacent with nothing between
    // them is precisely the string the bidi algorithm reordered.
    expect($html)->not->toContain('د.إ199');

    // Tag order is still symbol-then-number; only the isolation is new.
    expect(strpos($html, 'د.إ'))->toBeLessThan(strpos($html, '199'));
});

it('isolates the price inside the LTR sentence that broke on the homepage', function () {
    setCurrency(['currency_symbol' => 'د.إ']);

    $sentence = 'Free delivery over ' . Money::format(19900);

    // Before: "Free delivery over د.إ199" — one unbroken bidi run from the
    // symbol onwards. After: the price is a closed island in the sentence.
    expect($sentence)->toContain('Free delivery over <span class="woocommerce-Price-amount amount" dir="ltr">')
        ->and($sentence)->not->toContain('over د.إ');
});

// ---------------------------------------------------------------------------
// 4. Position.
// ---------------------------------------------------------------------------

it('puts the symbol where the setting says, with or without a space', function () {
    setCurrency(['currency_symbol' => '$']);

    expect(Money::plain(19900))->toBe('$199');           // default: before

    setCurrency(['currency_position' => 'before_space']);
    expect(Money::plain(19900))->toBe('$ 199');

    setCurrency(['currency_position' => 'after']);
    expect(Money::plain(19900))->toBe('199$');
    expect(Money::format(19900))->toContain('199<span class="woocommerce-Price-currencySymbol"');

    setCurrency(['currency_position' => 'after_space']);
    expect(Money::plain(19900))->toBe('199 $');

    // Anything unrecognised is the default rather than an error.
    setCurrency(['currency_position' => 'sideways']);
    expect(Money::position())->toBe('before')
        ->and(Money::plain(19900))->toBe('$199');
});

// ---------------------------------------------------------------------------
// 5. Currencies that do not have two decimal places.
// ---------------------------------------------------------------------------

it('formats and round-trips a three-decimal currency (KWD)', function () {
    setCurrency(['currency' => 'KWD', 'currency_decimals' => '3']);

    expect(Money::symbol())->toBe('د.ك')
        ->and(Money::minorExponent())->toBe(3)
        ->and(Money::displayDecimals())->toBe(3);

    // 12345 fils of a three-decimal currency is 12.345, not 123.45.
    expect(Money::amount(12345))->toBe('12.345')
        ->and(Money::amount(1234567))->toBe('1,234.567')
        ->and(Money::amount(5))->toBe('0.005');

    expect(Money::toMajor(12345))->toBe(12.345)
        ->and(Money::fromMajor('12.345'))->toBe(12345)
        ->and(Money::fromMajor(Money::toMajor(12345)))->toBe(12345);
});

it('formats and round-trips a zero-decimal currency (JPY)', function () {
    setCurrency(['currency' => 'JPY', 'currency_decimals' => '0']);

    expect(Money::symbol())->toBe('¥')
        ->and(Money::minorExponent())->toBe(0)
        ->and(Money::displayDecimals())->toBe(0);

    // A yen is its own minor unit: 500 stored is ¥500, never ¥5.
    expect(Money::amount(500))->toBe('500')
        ->and(Money::amount(1234567))->toBe('1,234,567');

    expect(Money::toMajor(500))->toBe(500.0)
        ->and(Money::fromMajor('500'))->toBe(500)
        ->and(Money::fromMajor(Money::toMajor(500)))->toBe(500);
});

it('takes the table decimals from Currencies, not from a hard-coded 2', function () {
    expect(Currencies::decimalsFor('AED'))->toBe(2)
        ->and(Currencies::decimalsFor('JPY'))->toBe(0)
        ->and(Currencies::decimalsFor('KWD'))->toBe(3)
        ->and(Currencies::decimalsFor('OMR'))->toBe(3)
        ->and(Currencies::decimalsFor('BHD'))->toBe(3)
        ->and(Currencies::decimalsFor('ZZZ', 2))->toBe(2);

    // Every GCC currency is selectable, which was the point of the list.
    foreach (['AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR'] as $code) {
        expect(Currencies::has($code))->toBeTrue();
    }
});

it('never formats through a float, so a three-decimal amount does not drift', function () {
    setCurrency(['currency' => 'OMR', 'currency_decimals' => '3']);

    // 8.615 and 1.005 are the classic binary-float casualties: (float) 8.615
    // is 8.6149999999999998, which rounds the wrong way through number_format.
    expect(Money::amount(8615))->toBe('8.615')
        ->and(Money::amount(1005))->toBe('1.005')
        ->and(Money::amount(2675))->toBe('2.675');
});

// ---------------------------------------------------------------------------
// 6. The symbol is operator-supplied text landing in HTML.
// ---------------------------------------------------------------------------

it('escapes an XSS attempt in the symbol setting rather than rendering it', function () {
    setCurrency(['currency_symbol' => '<script>alert(1)</script>']);

    $html = Money::format(19900);

    expect($html)->not->toContain('<script>')
        ->and($html)->not->toContain('</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('escapes a symbol that tries to break out of an attribute', function () {
    setCurrency([
        'currency_symbol' => '"><img src=x onerror=alert(1)>',
        'currency_symbol_render' => 'svg',
    ]);

    $html = Money::format(19900);

    // The payload survives as inert text — the point is that neither the
    // quote that would close an attribute nor the angle bracket that would
    // open a tag reaches the browser as itself.
    expect($html)->not->toContain('<img')
        ->and($html)->toContain('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;');
});

// ---------------------------------------------------------------------------
// 7. The tofu escape hatch.
// ---------------------------------------------------------------------------

it('draws the dirham sign as an inline SVG when the operator asks for it', function () {
    // svg only ever applies to U+20C3 -- no other symbol needs drawing -- and
    // that is no longer the default, so it has to be selected explicitly.
    setCurrency([
        'currency_symbol' => Currencies::AED_SIGN,
        'currency_symbol_render' => 'svg',
    ]);

    $html = Money::format(19900);

    // Self-hosted: a path in this repo, not a font from a CDN.
    expect($html)->toContain('<svg class="kbb-currency-glyph"')
        ->and($html)->not->toContain('http');

    // The character is still the accessible name, so a screen reader is not
    // handed an anonymous image.
    expect($html)->toContain('aria-label="' . Currencies::AED_SIGN . '"')
        ->and($html)->toContain('<title>' . Currencies::AED_SIGN . '</title>');
});

it('defaults to the bare character, as the owner asked', function () {
    expect(Money::symbolRender())->toBe('unicode')
        ->and(Money::format(19900))->not->toContain('<svg');
});

it('falls back to the character for a symbol it ships no drawing for', function () {
    setCurrency(['currency' => 'USD', 'currency_symbol_render' => 'svg']);

    // There is no drawn "$" in this repo and there is no need for one — it has
    // been in every font for forty years.
    expect(Money::format(19900))->not->toContain('<svg')
        ->and(Money::format(19900))->toContain('>$</span>');
});

it('keeps plain() free of markup and invisible control characters', function () {
    setCurrency(['currency_symbol' => 'د.إ']);

    $plain = Money::plain(19900);

    // pdp.js reads this back out of a data-price attribute and re-injects it,
    // and SearchController puts it in JSON. Anything invisible in here would
    // survive into both.
    expect($plain)->toBe('د.إ 199')
        ->and($plain)->not->toContain('<')
        ->and($plain)->not->toContain("\u{2068}")
        ->and($plain)->not->toContain("\u{2069}")
        ->and($plain)->not->toContain("\u{200E}");
});

// ---------------------------------------------------------------------------
// 8. Every new key has to survive updateSettings.
// ---------------------------------------------------------------------------

it('lists every currency key in the updateSettings allowlist', function () {
    // Asserted against the allowlist as well as over HTTP: seventeen keys were
    // already found reporting success and writing nothing, and naming the
    // missing key beats "a save failed" when it happens again.
    //
    // Read as DATA, not by grepping the method body for quoted strings. The
    // source-scraping version of this check broke the moment the allowlist
    // moved into a constant, while the behaviour it describes was unchanged —
    // a test that fails for a refactor it does not care about teaches people to
    // edit the test until it passes.
    $allowed = array_keys(AdminController::SETTING_RULES);

    foreach (['currency', 'currency_symbol', 'currency_position', 'currency_decimals', 'currency_symbol_render'] as $key) {
        expect($allowed)->toContain($key);
    }
});

describe('signed in as an admin', function () {
    beforeEach(function () {
        $this->admin = AdminUser::create([
            'name' => 'T Currency Admin', 'email' => 't-currency@example.test',
            'password' => 'password-long-enough', 'role' => 'owner',
        ]);

        $this->actingAs($this->admin, 'admin');
    });

    it('saves every currency key through updateSettings instead of rejecting it', function () {
        $posted = [
            'currency' => 'KWD',
            'currency_symbol' => 'د.ك',
            'currency_position' => 'after_space',
            'currency_decimals' => '3',
            'currency_symbol_render' => 'unicode',
        ];

        $this->putJson('/admin-api/settings', ['settings' => $posted])
            ->assertOk()
            ->assertJsonPath('saved', count($posted))
            ->assertJsonMissingPath('rejected');

        foreach ($posted as $key => $value) {
            expect(Setting::query()->where('key', $key)->value('value'))->toBe($value);
        }
    });

    it('reaches the formatter, which is the only reason to save it', function () {
        $this->putJson('/admin-api/settings', ['settings' => [
            'currency' => 'JPY',
            'currency_symbol' => '¥',
            'currency_position' => 'before',
            'currency_decimals' => '0',
        ]])->assertOk();

        Money::forgetConfig();

        expect(Money::plain(500))->toBe('¥500')
            ->and(Money::minorExponent())->toBe(0);
    });
});
