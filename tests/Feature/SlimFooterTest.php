<?php

declare(strict_types=1);

/*
 * Appearance → Footer: the slim bar, its two switches, and the three things
 * that would each have made it unsafe or useless.
 *
 * It is NOT the site footer. The checkout declares `bare` and the cart page
 * declares `no-footer`, so partials/footer.blade.php has never rendered on
 * either and still does not — a page asking for money should not offer twenty
 * ways to leave. This is a second, much smaller thing with its own words.
 */

use App\Models\AdminUser;
use App\Services\SlimFooter;
use App\Support\AdminCapabilities;
use Illuminate\Support\Str;

function sf(): SlimFooter
{
    return app(SlimFooter::class);
}

function sfOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'Owner', 'email' => 'sf-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'), 'role' => 'owner',
    ]);
}

/* ------------------------------------------------------------------------
 | 1. The cart page was not to be disturbed
 |------------------------------------------------------------------------*/

it('ships on for the checkout and off for the cart', function () {
    // "don't disturb anything in cart page" — so that page renders exactly as
    // it does today until somebody turns this on, and the checkout gains the
    // bar that was asked for.
    expect(sf()->onCheckout())->toBeTrue()
        ->and(sf()->onCart())->toBeFalse();
});

it('draws one bar from one partial, not a copy per page', function () {
    // A second copy would be a second place for the support number to be
    // wrong, and the wrong one would be whichever page nobody looked at.
    $checkout = (string) file_get_contents(resource_path('views/store/checkout.blade.php'));
    $cart = (string) file_get_contents(resource_path('views/store/cart.blade.php'));

    foreach ([$checkout, $cart] as $blade) {
        expect($blade)->toContain("@include('partials.slim-footer')");
    }

    expect($checkout)->toContain('onCheckout()')
        ->and($cart)->toContain('onCart()');
});

/* ------------------------------------------------------------------------
 | 2. A link in a footer is an href on a page somebody is paying from
 |------------------------------------------------------------------------*/

it('refuses a link scheme that would run script as the shopper', function () {
    /*
     * `javascript:` in an href is script executing as the shopper, on the
     * checkout. Only an admin can write this setting — but "trusted" is a
     * statement about intent, not about whether an account has ever been
     * taken, and refusing three schemes costs nothing.
     */
    foreach ([
        'javascript:alert(1)',
        'JavaScript:alert(1)',
        'data:text/html,<script>alert(1)</script>',
        'vbscript:msgbox',
    ] as $bad) {
        expect(sf()->url($bad))->toBeNull("{$bad} was accepted as a footer link");
    }

    // And the four that are allowed still are.
    expect(sf()->url('https://example.com/a'))->toBe('https://example.com/a')
        ->and(sf()->url('http://example.com'))->toBe('http://example.com')
        ->and(sf()->url('mailto:a@b.com'))->toBe('mailto:a@b.com')
        ->and(sf()->url('tel:+971585052611'))->toBe('tel:+971585052611')
        // A relative path goes through Url::to(), so a subdirectory install
        // cannot produce a link that escapes the app.
        ->and(sf()->url('/terms-of-service'))->toContain('/terms-of-service')
        ->and(sf()->url(''))->toBeNull();
});
// MUTATION: return the raw value from url(). RED on all four.

it('draws the words as plain text when their link is refused', function () {
    // Refusing the href must not lose the sentence: a shop whose policy link
    // was typed wrong should still say "Shipping policy", not go quiet.
    $partial = (string) file_get_contents(resource_path('views/partials/slim-footer.blade.php'));

    expect($partial)->toContain('@elseif ($sfC[\'l1_text\'] !== \'\')<span>{{ $sfC[\'l1_text\'] }}</span>')
        ->and($partial)->toContain('@elseif ($sfC[\'phone\'] !== \'\')');
});

/* ------------------------------------------------------------------------
 | 3. Empty means absent, which is how the bar is made short
 |------------------------------------------------------------------------*/

it('draws no element at all for a setting left empty', function () {
    $partial = (string) file_get_contents(resource_path('views/partials/slim-footer.blade.php'));

    // Each block is behind its own emptiness check, so a shop that wants a
    // brand and a phone number gets a bar with those two in it — not a bar
    // with four empty gaps in it.
    foreach ([
        "@if (\$sfC['brand'] !== '' || \$sfC['byline'] !== '')",
        "@if (\$sfC['help_title'] !== '' || \$sfC['help_sub'] !== '')",
        "@if (\$sfC['phone'] !== '' || \$sfC['email'] !== '')",
        "@if (\$sfC['l1_text'] !== '' || \$sfC['l2_text'] !== '' || \$sfC['l3_text'] !== '')",
        "@if (\$sfC['copy'] !== '')",
    ] as $guard) {
        expect($partial)->toContain($guard);
    }
});

it('resets the padding the site footer rule would otherwise give it', function () {
    /*
     * kbb.css carries a BARE ELEMENT rule — `footer{background:#241C20;
     * color:#CDBFC6;padding:52px 0 26px}` — for the site footer. A class beats
     * an element selector on colour, so the two were already ours; padding was
     * not. Measured in Chromium, the bar rendered 161px tall against the 82px
     * its own contents needed, on a footer whose whole brief was "very less,
     * like a bar type".
     */
    $partial = (string) file_get_contents(resource_path('views/partials/slim-footer.blade.php'));

    $at = strpos($partial, '.kbb-slimfoot{');
    expect($at)->not->toBeFalse();

    // To the rule's own closing brace, found by scanning rather than by a
    // `[^}]*` — the comment inside this rule quotes kbb.css's declaration,
    // brace and all, and a character class would stop at that.
    $rule = substr($partial, (int) $at, strpos($partial, "\n}", (int) $at) - (int) $at);

    expect($rule)->toContain('padding:0;margin:0');
});
// MUTATION: drop that reset. RED — and on the shop, 78px of footer nobody asked for.

/* ------------------------------------------------------------------------
 | 4. The endpoints
 |------------------------------------------------------------------------*/

it('hands the screen every field, grouped into the three tabs', function () {
    $body = test()->actingAs(sfOwner(), 'admin')
        ->getJson('/admin-api/slim-footer')->assertOk()->json();

    expect(collect($body['tabs'])->pluck('key')->all())->toBe(['pages', 'phone', 'layout', 'content', 'marks']);

    $keys = collect($body['tabs'])->flatMap(fn ($t) => collect($t['fields'])->pluck('key'))->all();

    // Every schema key is on a tab and every tab key is in the schema. The
    // second direction is the one that bites: a key named on a tab and missing
    // from the schema is a control the screen silently does not draw.
    expect($keys)->toEqualCanonicalizing(array_keys(SlimFooter::SCHEMA))
        ->and(count($keys))->toBe(count(array_unique($keys)));
});
// MUTATION: add a key to TABS and not to SCHEMA. RED.

it('refuses a setting it does not know rather than dropping it in silence', function () {
    test()->actingAs(sfOwner(), 'admin')
        ->postJson('/admin-api/slim-footer', ['settings' => ['brand' => 'X', 'made_up' => 1]])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect(sf()->get('brand'))->toBe('K-BEAUTY BLISS');
});

it('stores only a value the select actually offers', function () {
    /*
     * Both selects are printed into a CLASS NAME. A value from anywhere else
     * is a class this stylesheet has never heard of at best, and at worst it
     * is an attribute-injection attempt looking for a gap in the escaping.
     */
    test()->actingAs(sfOwner(), 'admin')
        ->postJson('/admin-api/slim-footer', ['settings' => [
            'variant' => 'made-up" onload="x',
            'tone' => 'ink',
            'pad_y' => 9000,
        ]])
        ->assertOk();

    expect(sf()->get('variant'))->toBe('bar')
        ->and(sf()->get('tone'))->toBe('ink')
        ->and(sf()->get('pad_y'))->toBe(40);

    expect(sf()->bodyClass())->toBe(' sf-bar sf-t-ink sf-a-between sf-wa sf-msplit sf-m-rows');
});

it('caps a pasted novel rather than printing it on every order', function () {
    test()->actingAs(sfOwner(), 'admin')
        ->postJson('/admin-api/slim-footer', ['settings' => ['brand' => str_repeat('a', 500)]])
        ->assertOk();

    expect(mb_strlen((string) sf()->get('brand')))->toBe(160);
});

/* ------------------------------------------------------------------------
 | 5. Untouched is untouched
 |------------------------------------------------------------------------*/

it('emits no style attribute while every control is at its default', function () {
    expect(sf()->cssVariables())->toBe('')
        ->and(sf()->styleAttr())->toBe('');

    sf()->save(['pad_y' => 6, 'font' => 85]);

    expect(sf()->cssVariables())->toBe('--sf-pady:6px;--sf-f:0.85');
});

/* ------------------------------------------------------------------------
 | 6. The capability
 |------------------------------------------------------------------------*/

it('puts the screen behind a capability of its own, and fails closed', function () {
    expect(AdminCapabilities::CAPABILITIES)->toHaveKey('slimfooter.manage')
        ->and(AdminCapabilities::CAPABILITIES['slimfooter.manage'])->toBe(['owner', 'manager', 'editor']);

    $rules = collect(AdminCapabilities::RULES);

    expect($rules->contains(['*', 'admin-api/slim-footer', 'slimfooter.manage']))->toBeTrue()
        // Not a reuse of another screen's: narrowing one must not narrow this.
        ->and($rules->contains(['*', 'admin-api/slim-footer', 'checkoutpage.manage']))->toBeFalse();
});

it('keeps a support account out of it', function () {
    $support = AdminUser::create([
        'name' => 'Support', 'email' => 'sf-sup-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'), 'role' => 'support',
    ]);

    test()->actingAs($support, 'admin')
        ->postJson('/admin-api/slim-footer', ['settings' => ['brand' => 'Nope']])
        ->assertForbidden();

    expect(sf()->get('brand'))->toBe('K-BEAUTY BLISS');
});

it('renders the screen as script, not as literal Blade', function () {
    $html = test()->actingAs(sfOwner(), 'admin')
        ->get('/'.app(\App\Services\AdminPathService::class)->current())
        ->assertOk()->getContent();

    expect($html)->toContain("var SCREEN = 'slimfooter';")
        ->and($html)->toContain('.sfs-wrap{');

    $from = strpos($html, "var SCREEN = 'slimfooter';");
    $mine = substr($html, (int) $from, 18000);

    expect($mine)->not->toContain('@json(')
        ->and($mine)->not->toContain('@php')
        ->and($mine)->not->toContain('@endif');
});

/* ------------------------------------------------------------------------
 | 7. The second round of options
 |------------------------------------------------------------------------*/

it('keeps shape and alignment as two controls rather than one list of sixteen', function () {
    /*
     * The obvious way to offer more looks is more entries in the shape list —
     * "one line centred", "one line justified", and so on. That is the same
     * two decisions written out four times, and every option added later
     * doubles it again. Four structures times four alignments is sixteen looks
     * from eight words.
     */
    $shapes = array_keys(SlimFooter::SCHEMA['variant'][4]);
    $aligns = array_keys(SlimFooter::SCHEMA['align'][4]);

    expect($shapes)->toBe(['bar', 'split', 'stack', 'rows'])
        ->and($aligns)->toBe(['start', 'center', 'end', 'between']);

    // No shape may name an alignment, or the two controls would fight over the
    // same property and whichever lost would be a control that does nothing.
    foreach ($shapes as $shape) {
        expect($shape)->not->toContain('center')->and($shape)->not->toContain('right');
    }
});

it('names a state class only where the stylesheet has a rule for it', function () {
    // `sf-a-start`, `sf-sep-none` and `sf-top-ring` are the base rules, so a
    // class for them would be one a future reader has to look up before they
    // can be sure it does nothing.
    /* The shipped pair the owner chose: spread-to-both-edges on a desktop,
       ruled rows on a phone. Both are DEFAULTS and both therefore name a class,
       which is the departure from "only what is not the default" this file
       otherwise keeps -- `between` and `rows` have rules and `start`/`bar` are
       the base, so a default that is not the base has to be said out loud. */
    expect(sf()->bodyClass())->toBe(' sf-bar sf-t-cream sf-a-between sf-wa sf-msplit sf-m-rows');

    sf()->save(['align' => 'between', 'sep' => 'dot', 'top_style' => 'solid',
        'shadow' => true, 'upper' => false, 'icons_on' => false, 'divider' => false]);

    expect(sf()->bodyClass())
        ->toBe(' sf-bar sf-t-cream sf-a-between sf-sep-dot sf-top-solid sf-noline sf-lift sf-nocaps sf-noic sf-wa sf-msplit sf-m-rows');
});

it('draws a separator only where two blocks sit side by side', function () {
    /*
     * In `stack` and `rows` each block is on its own line, so an ::after would
     * hang off the end of every one of them rather than landing between two.
     */
    $partial = (string) file_get_contents(resource_path('views/partials/slim-footer.blade.php'));

    expect($partial)->toContain('.kbb-slimfoot:is(.sf-bar,.sf-split).sf-sep-dot')
        ->and($partial)->not->toContain('.sf-stack.sf-sep-dot')
        ->and($partial)->not->toContain('.sf-rows.sf-sep-dot');

    // And the arrow's auto margin is dropped for every alignment but `start`,
    // or it would eat the whole gap before justify-content could distribute it.
    expect($partial)->toContain('.kbb-slimfoot:is(.sf-a-center,.sf-a-end,.sf-a-between) .sf-top{margin-inline-start:0}');
});

it('prints the payment marks from the constant and never from a setting', function () {
    /*
     * App\Support\PaymentMarkArt is a hardcoded constant with no setting, no
     * database read and no interpolation in it, and its own header says why:
     * the marks are printed unescaped, so artwork assembled from a setting
     * would be a stored-XSS sink on the page orders are placed from. These
     * switches choose WHICH constant is printed and can do nothing else.
     */
    expect(sf()->paymentMarks())->toBe([], 'the marks row is not off by default');

    sf()->save(['pay_on' => true]);

    $marks = sf()->paymentMarks();

    // Visa, Mastercard, Apple Pay and Google Pay ship on; Tabby and Tamara off.
    expect($marks)->toHaveCount(4);

    foreach ($marks as $art) {
        expect($art)->toBeIn(array_values(\App\Support\PaymentMarkArt::marks()));
    }

    sf()->save(['pay_visa' => false, 'pay_apple' => false, 'pay_google' => false]);
    expect(sf()->paymentMarks())->toHaveCount(1);
});
// MUTATION: build a mark from a setting. RED — and on the shop, unescaped
// markup from a settings row on the checkout.

it('ships every new option at the value the bar already had', function () {
    /*
     * Nineteen controls were added in this round. A default that differs from
     * what the bar was already doing is a package that redesigns a live
     * footer on the way in, which is not what "give some more options" asked
     * for.
     */
    $c = sf()->all();

    expect($c['align'])->toBe('between')
        ->and($c['sep'])->toBe('none')
        ->and($c['top_style'])->toBe('ring')
        ->and($c['radius'])->toBe(0)
        ->and($c['line_w'])->toBe(1)
        ->and($c['max_w'])->toBe(1240)
        ->and($c['shadow'])->toBeFalse()
        ->and($c['pay_on'])->toBeFalse()
        ->and($c['upper'])->toBeTrue()
        ->and($c['icons_on'])->toBeTrue()
        ->and($c['l3_text'])->toBe('')
        ->and($c['copy'])->toBe('')
        // And with all of them untouched the element still carries no style
        // attribute at all.
        ->and(sf()->cssVariables())->toBe('');
});

/* ------------------------------------------------------------------------
 | 5. The phone gets its own shape
 |------------------------------------------------------------------------*/

/**
 * The owner's pick: "06 Ruled rows is final and for desktop 03" — ruled rows on
 * a phone, spread-to-both-edges on a desktop. One `variant` cannot be both, so
 * the phone gets a switch and six overrides of its own.
 *
 * MUTATION: turn `mobile_on` off in SCHEMA and the first test is red, because
 * the bar would render the desktop shape at 390px.
 */
it('gives the phone its own shape, and emits nothing for it while the switch is off', function () {
    expect(sf()->bodyClass())->toContain('sf-msplit')
        ->and(sf()->bodyClass())->toContain('sf-m-rows');

    sf()->save(['mobile_on' => false]);

    /*
     * NOT ONE mobile class while the switch is off. The media query in the
     * partial is gated entirely on `.sf-msplit`, so with the class gone there
     * is nothing at 900px to match and the bar renders from the desktop rules
     * alone — which is what a shop that never opens this tab must keep getting.
     */
    $off = sf()->bodyClass();

    expect($off)->not->toContain('sf-msplit')
        ->and($off)->not->toContain('sf-m-')
        ->and($off)->not->toContain('sf-ma-');
});

it('gates every phone rule on the switch class and on 900px, not on this file\'s 640', function () {
    $partial = (string) file_get_contents(resource_path('views/partials/slim-footer.blade.php'));

    // 900 and not 640: the bar is a checkout bar, and CheckoutPage::MOBILE_MAX
    // is where the page above it says a phone stops. Two screens disagreeing
    // about that is a footer in one shape under a page in the other.
    expect($partial)->toContain('@media (max-width:900px){')
        ->and($partial)->toContain('.kbb-slimfoot.sf-msplit{')
        ->and($partial)->toContain('.kbb-slimfoot.sf-msplit.sf-m-rows .sf-in{flex-direction:column;align-items:stretch;gap:0}')
        // Each phone shape first undoes the desktop shape. A phone set to `bar`
        // under a desktop set to `rows` must not keep the hairlines.
        ->and($partial)->toContain('.kbb-slimfoot.sf-msplit .sf-in > * + *{border-top:0;padding-top:0;margin-top:0}');
});

it('draws the phone mark in WhatsApp green, from a constant and not a colour box', function () {
    $partial = (string) file_get_contents(resource_path('views/partials/slim-footer.blade.php'));

    /*
     * The glyph was always WhatsApp's — the path is the speech bubble with the
     * handset in it. What it was not is recognisable: at 15px in currentColor
     * it reads as a generic contact mark, which is why "put whatsapp icon
     * beside the phone" was asked for a number that already had one.
     *
     * #25D366 is WhatsApp's own and is written here, not stored: a colour that
     * came from a setting would be a declaration assembled from user input on
     * the page orders are placed from, which is the rule PaymentMarkArt states.
     */
    expect($partial)->toContain('.kbb-slimfoot.sf-wa .sf-con .sf-c:first-child svg{color:#25D366}')
        ->and($partial)->not->toContain('var(--sf-wa')
        // FIRST CHILD ONLY. The second .sf-c is the email and stays in the ink.
        ->and($partial)->toContain(':first-child svg');

    sf()->save(['phone_mark' => 'mono']);
    expect(sf()->bodyClass())->not->toContain('sf-wa');
});

it('keeps the bar off the cart page, which is where it has always been', function () {
    // "this footer is only for checkout page footer." It already was: co_on
    // ships on and cart_on ships off. The switch stays rather than being
    // removed, because removing a working control is not the same as leaving
    // the cart page alone.
    expect(\App\Services\SlimFooter::SCHEMA['co_on'][2])->toBeTrue()
        ->and(\App\Services\SlimFooter::SCHEMA['cart_on'][2])->toBeFalse();
});
