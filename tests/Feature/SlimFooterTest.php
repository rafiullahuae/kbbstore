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
        "@if (\$sfC['l1_text'] !== '' || \$sfC['l2_text'] !== '')",
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

    expect(collect($body['tabs'])->pluck('key')->all())->toBe(['pages', 'layout', 'content']);

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

    expect(sf()->bodyClass())->toBe(' sf-bar sf-t-ink');
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
