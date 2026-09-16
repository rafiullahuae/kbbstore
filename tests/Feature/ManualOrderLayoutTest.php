<?php

declare(strict_types=1);

/**
 * The New Order screen's layout, pinned where it can actually regress.
 *
 * The owner's complaint was not a bug report: "revise the layout of the Add
 * New order page too, I want very nice." What was wrong was measurable though.
 * At a 1280 viewport the left column was 485px tall beside a right column of
 * 1190 — Customer and Items stopped, and Delivery & payment plus the total ran
 * on for two screens beside a blank half. Delivery & payment was ten fields in
 * one undivided stack. The total, the number being read down the phone while
 * the customer waits, was at the bottom of the longer column.
 *
 * Pest has no layout engine, so the pixels live in the commit and in the
 * screenshots. What is pinned here is the STRUCTURE that produces them, and
 * only the parts a later edit could quietly undo:
 *
 *   - four cards split across the two columns rather than 2/2, which is what
 *     makes the columns roughly the same height;
 *   - the summary sticky, and stretched so it has room to stick;
 *   - the running total inside the items card;
 *   - the grouping headings in place of the flat stack;
 *   - every field id, bind key and picker hook the other tests and the browser
 *     walk reach for, because a redesign is exactly when those get dropped.
 *
 * A note on how these read. `toContain` takes a SECOND NEEDLE, not a failure
 * message, and `toHaveKey` takes an expected VALUE — passing a sentence to
 * either silently asserts something else. So every check below is
 * str_contains() into toBeTrue('...'), where the string is the message.
 *
 * And a class-name search of this file matches the inlined <style> block as
 * happily as the markup, so anything that must exist IN THE MARKUP is matched
 * against the screen's JavaScript string literals, not against a bare name.
 */
function manualOrderScreen(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/manual-order-screen.blade.php')
    );
}

it('splits the four steps across the two columns so neither runs on alone', function () {
    $src = manualOrderScreen();

    /*
     * The shape that fixes the emptiness: who/what/where on the left, how they
     * pay plus the summary on the right. Matched as the render() call itself —
     * a CSS rule mentioning these names would not move a card.
     */
    expect(str_contains($src, "customerCard() + itemsCard() + deliveryCard()"))
        ->toBeTrue('the left column should carry steps 1-3 (customer, items, delivery)');

    expect(str_contains($src, "'<div class=\"mo-col mo-rail\">' + paymentCard() + totalsCard()"))
        ->toBeTrue('the right column should carry step 4 and the summary, in that order');

    // The summary must stay LAST in the rail: a sticky first child would pin
    // itself over the card beneath it and make step 4 unreachable.
    $rail = strpos($src, 'mo-col mo-rail');
    expect($rail)->not->toBeFalse();
    expect(strpos($src, 'paymentCard()', $rail) < strpos($src, 'totalsCard()', $rail))
        ->toBeTrue('the summary must be the last card in the rail or sticky pins it over step 4');
});

it('gives the summary somewhere to stick', function () {
    $src = manualOrderScreen();

    /*
     * Both halves, because either alone is a no-op. A sticky child can only
     * travel inside its parent's box, and under `align-items:start` the column
     * is only as tall as its own content — so without the stretch the card
     * sticks to nothing and the pixel result is the old layout.
     */
    expect((bool) preg_match('/\.mo-rail\{[^}]*align-self:stretch/', $src))
        ->toBeTrue('.mo-rail must stretch, or its sticky child has no room to travel');

    expect((bool) preg_match('/\.mo-card\.mo-sum\{[^}]*position:sticky/', $src))
        ->toBeTrue('the summary card must be sticky so the total stays on screen');

    // A pinned card taller than the window would hold its own Create button
    // below the fold with nothing able to scroll to it.
    expect((bool) preg_match('/\.mo-card\.mo-sum\{[^}]*max-height:/', $src))
        ->toBeTrue('a sticky summary needs a max-height or Create order can go unreachable');

    // On a phone a pinned card is just a card that eats the viewport.
    expect((bool) preg_match('/@media \(max-width:1080px\)\{.*?\.mo-card\.mo-sum\{position:static/s', $src))
        ->toBeTrue('the summary must stop sticking once the grid is one column');
});

it('keeps the total beside the items as well as in the summary', function () {
    $src = manualOrderScreen();

    expect(str_contains($src, "'<div class=\"mo-run\" id=\"moRun\">' + runHTML()"))
        ->toBeTrue('the items card should carry its own running total');

    // It has to follow a quantity change and a landed quote, both of which go
    // through renderLines(). Rendered once and never refreshed, it would show
    // a stale figure — worse than no figure, since this one is read aloud.
    expect((bool) preg_match('/function renderLines\(\)\{.*?#moRun.*?\}/s', $src))
        ->toBeTrue('renderLines() must refresh #moRun or the running total goes stale');
});

it('groups delivery and payment instead of stacking ten fields', function () {
    $src = manualOrderScreen();

    foreach ([
        'The address',
        'How it ships, and what it costs',
        'How they pay',
        'Where the order came from',
    ] as $heading) {
        expect(str_contains($src, '<h4>'.$heading.'</h4>'))
            ->toBeTrue("the grouping heading \"{$heading}\" is missing");
    }

    // The old single card is gone, not merely renamed around.
    expect(str_contains($src, 'Delivery &amp; payment</h3>'))
        ->toBeFalse('the undivided "Delivery & payment" card should have been split');
});

it('replaces the two dead empty states with useful ones', function () {
    $src = manualOrderScreen();

    expect(str_contains($src, 'mo-empty">Nothing added yet.'))
        ->toBeFalse('the dead grey "Nothing added yet." box should no longer be rendered');

    expect(str_contains($src, 'Add a customer, items and a delivery address and the total appears here.'))
        ->toBeFalse('the totals placeholder should be the readiness list, not one grey sentence');

    // The basket's empty state now says what to type, including a brand — the
    // product search matches brands, so "anua" is a legitimate query and the
    // rows it returns will not have that word in their names.
    expect(str_contains($src, 'The basket is empty'))
        ->toBeTrue('the items card should have a useful empty state');
    expect(str_contains($src, 'brand such as'))
        ->toBeTrue('the empty state should tell the operator a brand is searchable');

    // The readiness list is built from the same four conditions quotable()
    // tests, so it cannot claim the form is ready while the quote refuses.
    expect(str_contains($src, 'function readyHTML()'))
        ->toBeTrue('the totals empty state should be the readiness checklist');
});

it('preserves every hook the rest of the suite and the browser walk reach for', function () {
    $src = manualOrderScreen();

    /*
     * A redesign is exactly when an id gets dropped. These are the ones that
     * are reached from OUTSIDE this file — tests/browser/order-product-picker.mjs
     * drives the pickers and counts .mo-line, and the screen's own handlers
     * bind by id — so losing one fails somewhere that does not name this file.
     */
    foreach ([
        'moScreen', 'moBanner',
        'moCustSearch', 'moCustResults', 'moCustClear', 'moCustNew', 'moCustBack',
        'moProdSearch', 'moProdResults',
        'moTotals', 'moCouponRow', 'moCouponApply', 'moCouponClear',
        'moSubmit', 'moReset', 'moAnother', 'moToOrders', 'moPacking',
        'moEmirates',
        'mo_state', 'mo_coupon', 'mo_customer_note',
    ] as $id) {
        expect(str_contains($src, 'id="'.$id.'"'))
            ->toBeTrue("element id {$id} went missing in the relayout");
    }

    // mo_line1, mo_city and the rest are not literals: textInput() and
    // selectInput() build them from the bind key, which is why the loop above
    // cannot see them and why this line is what actually protects them.
    expect(str_contains($src, '\'<input id="mo_\' + key + \'" data-bind="\' + key'))
        ->toBeTrue('textInput() must keep building mo_<key> ids and data-bind together');

    // The payload keys, which are the server's contract. fld()/textInput()
    // build ids from these, so a renamed bind key is a silently dropped field.
    foreach ([
        'line1', 'city', 'state', 'country', 'phone',
        'shipping_override', 'payment_method', 'status', 'channel',
        'customer_note', 'whatsapp_optin', 'send_confirmation',
    ] as $key) {
        expect(str_contains($src, 'data-bind="'.$key.'"') || str_contains($src, "'".$key."',"))
            ->toBeTrue("the {$key} field is no longer bound to the payload");
    }

    // The shared picker, which another lane fixed. Rebuilding it per render is
    // the bug that fix removed.
    expect(str_contains($src, 'window.kbbProductPicker'))
        ->toBeTrue('both searches must stay on the shared picker component');
    expect(str_contains($src, 'if (picker) return picker;'))
        ->toBeTrue('the product picker must still be built once and re-attached, not rebuilt');
});
