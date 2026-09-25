<?php

/**
 * A setting never reaches a page unescaped — Lane M3.
 *
 * ── THE DEFECT, FOUND WHILE ANSWERING TASK 2 AND NOT PART OF IT ─────────────
 *
 * Task 2 asked what happens when a hostile row is already in `settings`. Driving
 * that answer through the STOREFRONT rather than through the reader turned up
 * something the schema cannot fix, because it is not a cast:
 *
 *   resources/views/store/home.blade.php builds the promo ticker as an array of
 *   chips and prints them with {!! $chip !!}, because the free-delivery chip
 *   carries a <b> of its own that has to render. Two of the three chips are
 *   safe by construction — one is a translation string, one calls e(). The
 *   third was `'🎁 ' . $ownTicker`, where $ownTicker is
 *   $settings->get('home_ticker'), a SETTING, unescaped.
 *
 * Measured, not reasoned: a `home_ticker` of `<img src=x onerror=alert(1)>`
 * rendered into the homepage verbatim. Rule 5 of the project notes states the
 * rule it broke in one line — "anything printed unescaped is a constant, never
 * a setting".
 *
 * AND THE CAST IS NO DEFENCE, which is the part worth writing down. The value
 * goes through ModuleSchema's text arm on its way in (Appearance → Homepage
 * content → Other wording), and that arm trims and caps — it does not escape,
 * and it must not: escaping is a property of where a string is PRINTED, and the
 * same stored string is legitimately printed into a JSON payload for the admin
 * screen in the same request. So the fix is e() at the print site, where it
 * holds for a row that never went through the screen at all.
 *
 * MUTATION ACTUALLY RUN: revert home.blade.php's `e($ownTicker)` to
 * `$ownTicker` and the first case goes red with
 *   the homepage printed a setting unescaped — rule 5
 * Failed asserting that false is true.
 */

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

function m3Ticker(string $value): string
{
    DB::table('settings')->updateOrInsert(['key' => 'home_ticker'], ['value' => $value]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    return test()->get('/')->getContent();
}

it('escapes the promo ticker chip, which is the one chip on that strip that is a setting', function () {
    $html = m3Ticker('<img src=x onerror=alert(1)>');

    expect(str_contains($html, '<img src=x onerror=alert(1)>'))
        ->toBeFalse('the homepage printed a setting unescaped — rule 5');

    // And it is still PRINTED, escaped — dropping the chip would be a different
    // bug wearing this one's clothes.
    expect($html)->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

it('leaves a chip of ordinary wording byte-identical', function () {
    /*
     * Rule 1. e() over a string with no HTML in it is the identity, so a shop
     * with a real ticker sees nothing move — and the shipped default is no chip
     * at all, so a shop that has never opened the screen sees no strip change
     * either. Both halves, because "it is escaped now" is not the same claim as
     * "nothing moved".
     */
    expect(m3Ticker('Free gift over AED 200'))->toContain('🎁 Free gift over AED 200');

    $empty = m3Ticker('');
    expect($empty)->not->toContain('🎁');
});
