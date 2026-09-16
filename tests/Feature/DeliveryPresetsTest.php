<?php

declare(strict_types=1);

/**
 * Lane CY — the Gulf delivery lines, filled in by clicking rather than typing.
 *
 * WHY THIS LANE COULD EXIST WHEN THE EARLIER ONES COULD NOT. Every lane before
 * this one was told never to invent a delivery window, and DeliveryLine's own
 * doc comment records why: none had been measured, and a plausible figure
 * printed as a promise is one the shop then has to keep. So the screen shipped
 * empty. The owner has now supplied the figures himself — one to three days for
 * the UAE, three to five for the rest of the Gulf, Saudi Arabia included — and
 * the job here is to reproduce them faithfully rather than improve on them. He
 * gave Saudi Arabia and the rest of the Gulf the SAME window, and a case below
 * pins that so nobody later reads a distinction into it he did not draw.
 *
 * THE TWO THINGS THESE CASES ARE REALLY DEFENDING:
 *
 *   1. A PRESET FILLS A FORM. IT DOES NOT SAVE. Applying the package that
 *      carries this feature must not change one word any shopper is told. The
 *      column stays empty until the owner presses Save, and several cases below
 *      load the console and then check the database and the storefront are
 *      exactly as they were.
 *
 *   2. THE PLACEHOLDER IS A NO-OP UNTIL SOMEBODY USES IT. DeliveryLine now
 *      substitutes {country} on the way out. Every line stored before this
 *      existed is literal words with no placeholder in it, and must come back
 *      byte for byte unchanged.
 *
 * THE CLASS-NAME TRAP, as GeoDeliveryLineTest and ShopperPathTruthTest both
 * record: the console inlines its own stylesheet, so searching rendered HTML
 * for a bare class name matches a CSS rule whether or not any element carries
 * it. The console cases below match ELEMENTS with preg_match_all, or exact copy
 * that appears nowhere in a stylesheet.
 */

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Countries;
use App\Support\CountryPresets;
use App\Support\CountryTemplate;
use App\Support\DeliveryLine;
use App\Support\Money;

beforeEach(function () {
    // Every cache these settings live behind. SettingsService holds a
    // forever-cache AND a per-process memo, and Setting::map() holds a second
    // static of its own — the trap CLAUDE.md records against it.
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
});

/** An owner, for the console and endpoint cases. */
function cyAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Preset Owner',
        'email' => 'cy-presets-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);
}

/** The admin console, rendered. */
function cyConsole(): string
{
    return test()->actingAs(cyAdmin(), 'admin')
        ->get('/' . \App\Services\AdminPathService::current())
        ->assertOk()->getContent();
}

/**
 * The preset groups as the CONSOLE received them, decoded from the page.
 *
 * Decoded rather than string-matched, and for a reason worth stating: Blade's
 * @json escapes non-ASCII, so the en dash in "3–5 days" reaches the browser as
 * – and a str_contains for the sentence would fail against a page that
 * carries it perfectly. Decoding asks the question the browser answers.
 *
 * @return array<string, mixed>
 */
function cyConsolePresets(string $html): array
{
    $m = [];

    expect(preg_match('/var KBB_PRESETS = (\{.*\});/', $html, $m))
        ->toBe(1, 'The preset groups never reached the console at all.');

    $decoded = json_decode($m[1], true);

    expect(is_array($decoded))->toBeTrue('The preset groups reached the console as something that is not JSON.');

    return $decoded;
}

/*
|------------------------------------------------------------------------------
| 1. The Gulf, and the owner's own figures
|------------------------------------------------------------------------------
*/

it('offers presets for exactly the six countries this shop already calls the Gulf', function () {
    /*
     * NOT A LIST OF ITS OWN. Countries::REGIONS['GCC'] is what the delivery
     * picker, the Extended tab and the shipping zone are all built from. A
     * second Gulf list here would be a second answer that drifts from the
     * first, and the drift would show up as a country that can be charged the
     * Gulf rate and never offered a Gulf delivery line.
     */
    expect(CountryPresets::codes(CountryPresets::DELIVERY))
        ->toBe(Countries::REGIONS['GCC'], 'The preset group stopped reading the shop\'s own Gulf list.');

    expect(Countries::REGIONS['GCC'])
        ->toBe(['AE', 'SA', 'KW', 'QA', 'BH', 'OM'], 'The shop\'s Gulf list changed under this lane.');
});

it('reproduces the owner\'s figures for each Gulf country, word for word', function () {
    /*
     * THE EXACT SENTENCES, quoted here because they are a PROMISE the shop has
     * to keep and not a formatting detail. If a future change reworded one, the
     * owner would find out from a customer rather than from this file.
     *
     * The UAE reads "1–3 days delivery all over UAE". He wrote "1-3 Delivery
     * all over UAE"; "days" is added because the sentence is ungrammatical
     * without it and the figure would read as a price, and the hyphen is the
     * en dash the shipped `delivery_default_text` already uses. Nothing else
     * about his wording is touched, and one click on the row replaces it.
     */
    $expected = [
        'AE' => '1–3 days delivery all over UAE',
        'SA' => '3–5 days delivery all over Saudi Arabia',
        'KW' => '3–5 days delivery all over Kuwait',
        'QA' => '3–5 days delivery all over Qatar',
        'BH' => '3–5 days delivery all over Bahrain',
        'OM' => '3–5 days delivery all over Oman',
    ];

    foreach ($expected as $code => $sentence) {
        expect(CountryPresets::value(CountryPresets::DELIVERY, $code))
            ->toBe($sentence, "The preset offered for {$code} is no longer the sentence the owner asked for.");
    }
});

it('gives Saudi Arabia the same window as the rest of the Gulf, because the owner did', function () {
    /*
     * HIS TWO SENTENCES GAVE SAUDI ARABIA THE SAME FIGURE AS EVERYWHERE ELSE
     * IN THE GULF — "for all other gulf countries 3-5 days, Saudi: 3-5 days".
     * Naming it separately is not the same as making it different, and reading
     * a distinction into it would be inventing a window, which is the one thing
     * this screen has always refused to do.
     */
    $saudi = CountryPresets::value(CountryPresets::DELIVERY, 'SA');

    foreach (['KW', 'QA', 'BH', 'OM'] as $code) {
        $other = CountryPresets::value(CountryPresets::DELIVERY, $code);

        expect(str_starts_with($saudi, '3–5 days') && str_starts_with($other, '3–5 days'))
            ->toBeTrue("Saudi Arabia and {$code} no longer carry the same delivery window.");
    }

    expect(str_contains(CountryPresets::value(CountryPresets::DELIVERY, 'AE'), '1–3 days'))
        ->toBeTrue('The UAE lost the shorter window the owner gave it.');
});

/*
|------------------------------------------------------------------------------
| 2. One sentence, the country name substituted into it
|------------------------------------------------------------------------------
*/

it('substitutes the country name into a sentence written once', function () {
    /*
     * THE CORE OF THE REQUEST: "make the full sentence and the country name
     * will auto change according". Spelled {country}, the same brace convention
     * VatDisplay::label() already uses for {rate}.
     */
    expect(CountryTemplate::fill('3–5 days delivery all over {country}', 'SA'))
        ->toBe('3–5 days delivery all over Saudi Arabia');

    expect(CountryTemplate::fill('3–5 days delivery all over {country}', 'OM'))
        ->toBe('3–5 days delivery all over Oman');

    // Twice in one sentence, because a str_replace that stopped at the first
    // would leave a brace on a shopper's screen.
    expect(CountryTemplate::fill('{country} orders ship fast. Free over AED 199 in {country}.', 'BH'))
        ->toBe('Bahrain orders ship fast. Free over AED 199 in Bahrain.');
});

it('leaves a sentence with no placeholder in it exactly as it was', function () {
    /*
     * THE COMPATIBILITY CASE, and the reason this change is safe to apply to a
     * live shop. Every line stored before the placeholder existed is literal
     * words. If substitution touched them at all, applying the package would
     * change what somebody is being told.
     */
    $literal = '1–3 days fast delivery all over UAE';

    expect(CountryTemplate::fill($literal, 'AE'))->toBe($literal);
    expect(CountryTemplate::fill('', 'SA'))->toBe('');
    expect(CountryTemplate::isDynamic($literal))->toBeFalse();
    expect(CountryTemplate::isDynamic('all over {country}'))->toBeTrue();
});

it('prints the code itself for a country it has no name for, rather than an empty gap', function () {
    // Countries::NAMES is a curated ninety-odd, not the full ISO list, so a
    // code can legitimately be missing. A sentence reading "delivery all over "
    // is worse than one reading "delivery all over ZZ".
    expect(CountryTemplate::fill('delivery all over {country}', 'ZZ'))
        ->toBe('delivery all over ZZ');
});

it('substitutes the placeholder when the storefront reads a saved line', function () {
    // The substitution has to happen where the shopper is served, not only in
    // the console preview — otherwise the screen shows him one sentence and the
    // shop prints another.
    app(SettingsService::class)->set(DeliveryLine::SETTING, [
        ['country' => 'SA', 'text' => '3–5 days delivery all over {country}'],
        ['country' => 'KW', 'text' => '3–5 days delivery all over {country}'],
    ]);

    expect(app(DeliveryLine::class)->for('SA'))->toBe('3–5 days delivery all over Saudi Arabia');
    expect(app(DeliveryLine::class)->for('KW'))->toBe('3–5 days delivery all over Kuwait');
});

it('serves a literal saved line byte for byte, placeholder support or not', function () {
    app(SettingsService::class)->set(DeliveryLine::SETTING, [
        ['country' => 'SA', 'text' => 'Delivered across Saudi Arabia'],
    ]);

    expect(app(DeliveryLine::class)->for('SA'))->toBe('Delivered across Saudi Arabia');

    // And the untouched default, which is what the UAE has been told all along.
    expect(app(DeliveryLine::class)->for('AE'))->toBe('1–3 days fast delivery all over UAE');
});

/*
|------------------------------------------------------------------------------
| 3. A preset is a suggestion. Nothing is written until Save.
|------------------------------------------------------------------------------
*/

it('writes nothing to the settings table when the console is merely opened', function () {
    /*
     * THE PROMISE THE WHOLE FEATURE RESTS ON. The figures are a delivery
     * promise; a screen that committed one by being looked at would be making
     * it on the owner's behalf. So: render the console — the page that carries
     * every preset and every chip — and the column must be untouched.
     */
    $html = cyConsole();

    // The suggestions really did reach the page, or this case proves nothing.
    $rows = cyConsolePresets($html)['delivery']['rows'];

    // in_array and not toContain(): the second argument to toContain is another
    // needle, not a message — the Pest trap this repo keeps re-learning.
    expect(in_array('3–5 days delivery all over Saudi Arabia', array_column($rows, 'value'), true))
        ->toBeTrue('The Gulf presets never reached the console, so this case cannot fail.');

    SettingsService::forgetMemo();
    Setting::flushMap();
    app(SettingsService::class)->flush();

    expect(Setting::query()->where('key', DeliveryLine::SETTING)->exists())
        ->toBeFalse('Opening the console wrote a delivery line to the database.');

    expect(DeliveryLine::rows(app(SettingsService::class)))
        ->toBe([], 'Opening the console gave some country a delivery line.');
});

it('tells a Gulf shopper nothing until the owner has saved, even after the presets shipped', function () {
    /*
     * THE SAME PROMISE, FROM THE SHOPPER'S END. This is the assertion that
     * would catch a preset quietly becoming a default in DeliveryLine or a
     * seeded row in a migration — neither of which would show up in the console
     * case above.
     */
    cyConsole();

    SettingsService::forgetMemo();
    Setting::flushMap();
    app(SettingsService::class)->flush();

    foreach (['SA', 'KW', 'QA', 'BH', 'OM'] as $code) {
        expect(app(DeliveryLine::class)->for($code))
            ->toBe('', "A shopper in {$code} is now being promised a delivery window nobody saved.");
    }

    // The UAE keeps exactly the promise it had before this lane, no more.
    expect(app(DeliveryLine::class)->for('AE'))->toBe('1–3 days fast delivery all over UAE');
});

it('stores what the chips offered once the owner actually saves', function () {
    /*
     * The other half: filling the form has to end somewhere. This is the PUT
     * the Save button makes, carrying the values the chips wrote into the
     * boxes, and the storefront reading them straight back.
     */
    $rows = [];

    foreach (CountryPresets::codes(CountryPresets::DELIVERY) as $code) {
        $rows[] = ['country' => $code, 'text' => CountryPresets::value(CountryPresets::DELIVERY, $code)];
    }

    $response = $this->actingAs(cyAdmin(), 'admin')
        ->putJson('/admin-api/settings', ['settings' => [DeliveryLine::SETTING => $rows]])
        ->assertOk();

    expect($response->json('saved'))->toBe(1, 'The endpoint reported success without saving anything.');
    expect($response->json('rejected'))->toBeNull('The endpoint rejected the delivery lines key.');

    SettingsService::forgetMemo();
    Setting::flushMap();
    app(SettingsService::class)->flush();

    expect(app(DeliveryLine::class)->for('AE'))->toBe('1–3 days delivery all over UAE');
    expect(app(DeliveryLine::class)->for('SA'))->toBe('3–5 days delivery all over Saudi Arabia');
    expect(app(DeliveryLine::class)->for('OM'))->toBe('3–5 days delivery all over Oman');
});

it('accepts the placeholder form through the same save path', function () {
    // The checkbox on the bar writes the template rather than the finished
    // sentence. The validator must take it — a `rows` rule that refused a brace
    // would make that half of the feature unsavable.
    $this->actingAs(cyAdmin(), 'admin')
        ->putJson('/admin-api/settings', [
            'settings' => [DeliveryLine::SETTING => [
                ['country' => 'QA', 'text' => '3–5 days delivery all over {country}'],
            ]],
        ])->assertOk();

    SettingsService::forgetMemo();
    Setting::flushMap();
    app(SettingsService::class)->flush();

    expect(app(DeliveryLine::class)->for('QA'))->toBe('3–5 days delivery all over Qatar');
});

/*
|------------------------------------------------------------------------------
| 4. The screen itself
|------------------------------------------------------------------------------
*/

it('hands the Delivery lines screen a preset for every Gulf country, sentence and all', function () {
    /*
     * WHAT THIS CAN AND CANNOT SEE. The chips are drawn by the console's own
     * JavaScript when the tab is opened, so no <button> for them exists in the
     * server's HTML and no amount of regex over it would find one. What this
     * case pins is the thing that would actually be missing if the feature
     * broke on the PHP side: the data reaching the page, correct and complete.
     * That the chips then appear, fill the boxes and save nothing is proved in
     * a real browser, in this lane's report.
     *
     * DECODED, NOT SEARCHED. See cyConsolePresets — a string search for the
     * sentence fails on a perfectly good page because @json escapes the dash.
     */
    $group = cyConsolePresets(cyConsole())['delivery'];

    expect(array_column($group['rows'], 'code'))->toBe(
        CountryPresets::codes(CountryPresets::DELIVERY),
        'The screen is no longer offered one preset per Gulf country.',
    );

    /*
     * HE IS AGREEING TO WORDS, so the whole sentence travels with the chip
     * rather than being assembled out of his sight. A chip that reached the
     * page carrying only "Saudi Arabia" would be a promise made sight unseen.
     */
    expect(array_column($group['rows'], 'value'))->toBe([
        '1–3 days delivery all over UAE',
        '3–5 days delivery all over Saudi Arabia',
        '3–5 days delivery all over Kuwait',
        '3–5 days delivery all over Qatar',
        '3–5 days delivery all over Bahrain',
        '3–5 days delivery all over Oman',
    ], 'The sentences the screen shows are no longer the ones the owner asked for.');
});

it('carries the code that draws the chips, binds them and fills in the whole Gulf at once', function () {
    /*
     * The other half of the same screen, and the part a PHP test can see: the
     * console has to actually contain the renderer, the binder and the delivery
     * screen's call into them. Without the last one the group would reach the
     * page and nothing would ever draw it.
     *
     * MATCHED ON CODE, NOT ON A CLASS NAME. The console inlines its stylesheet,
     * so `pst-chip` is in the page whether or not anything uses it — the trap
     * ShopperPathTruthTest records. These are function names and attribute
     * spellings, which appear in no stylesheet.
     */
    $html = cyConsole();

    foreach ([
        'data-pst-code=' => 'the chip a single country is filled in from',
        'data-pst-all=' => 'the button that fills in the whole Gulf at once',
        'data-pst-group=' => 'the attribute that binds a bar to its group',
        'function pstBarHtml' => 'the renderer itself',
        'function pstBind' => 'the click handling',
        "pstBarHtml('delivery'" => 'the Delivery lines screen asking for its bar',
        "pstBind('dlPresets'" => 'the Delivery lines screen binding it',
    ] as $needle => $what) {
        expect(str_contains($html, $needle))
            ->toBeTrue("The console no longer carries {$what}.");
    }
});

it('says on the screen that clicking fills the form and saving is a separate act', function () {
    // The note is not decoration. It is the screen telling the owner the thing
    // the code above guarantees, in the place he will read it.
    $html = cyConsole();

    expect(str_contains($html, 'nothing reaches the shop until you press Save changes'))
        ->toBeTrue('The screen no longer tells the owner that a click saves nothing.');
});

/*
|------------------------------------------------------------------------------
| 5. The mechanism is the reusable part
|------------------------------------------------------------------------------
*/

it('answers for an unregistered group instead of throwing at whoever asks', function () {
    /*
     * The Tax tab is being built in another lane and will register its own
     * group. Until it does, asking for one must be an empty answer rather than
     * an exception that takes a console screen down — a half-applied package on
     * a host with no shell is exactly the situation where these two differ.
     */
    expect(CountryPresets::has('tax'))->toBeFalse();
    expect(CountryPresets::codes('tax'))->toBe([]);
    expect(CountryPresets::value('tax', 'SA'))->toBe('');
    expect(CountryPresets::forConsole('tax')['rows'])->toBe([]);
});

it('hands the console a finished sentence and the shape it came from, for every row', function () {
    /*
     * The console needs both: the literal, which is what a chip writes and
     * shows, and the template, which is what it writes when the owner asks for
     * the country name to stay automatic. `dynamic` is how the screen knows
     * whether to offer that choice at all.
     */
    $group = CountryPresets::forConsole(CountryPresets::DELIVERY);

    expect($group['key'])->toBe('delivery');
    expect(count($group['rows']))->toBe(6);

    $byCode = [];
    foreach ($group['rows'] as $row) {
        $byCode[$row['code']] = $row;
    }

    expect($byCode['SA']['template'])->toBe('3–5 days delivery all over {country}');
    expect($byCode['SA']['value'])->toBe('3–5 days delivery all over Saudi Arabia');
    expect($byCode['SA']['name'])->toBe('Saudi Arabia');
    expect($byCode['SA']['dynamic'])->toBeTrue();

    /*
     * The UAE is the one country whose suggestion names itself, in the short
     * form the owner used. So its template carries no placeholder, both halves
     * are the same string, and the automatic-name checkbox changes nothing for
     * it rather than turning his "UAE" into "United Arab Emirates" behind his
     * back.
     */
    expect($byCode['AE']['template'])->toBe($byCode['AE']['value']);
    expect($byCode['AE']['dynamic'])->toBeFalse();
});

it('picks up a country added to the shop\'s Gulf list without being edited', function () {
    // The group names a region; it does not restate its members. This is what
    // makes the mechanism worth reusing rather than copying.
    foreach (CountryPresets::codes(CountryPresets::DELIVERY) as $code) {
        expect(isset(Countries::NAMES[$code]))
            ->toBeTrue("The Gulf list names {$code}, which the shop cannot put in a sentence.");

        expect(str_contains(CountryPresets::value(CountryPresets::DELIVERY, $code), Countries::NAMES[$code])
            || $code === 'AE')
            ->toBeTrue("The preset for {$code} does not name the country it is for.");
    }
});
