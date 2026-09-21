<?php

declare(strict_types=1);

use App\Services\MobileHeader;
use App\Services\SettingsService;
use App\Support\HeaderIcons;

/**
 * Appearance → Mobile Header: the five things the owner asked for, and the one
 * bug he reported.
 *
 *   "in the Mobile header (including everything, i want the font size to b
 *    choses from backend of everything), also use the same icons as you used in
 *    preview of mobile header as attached, but if the user is login, i want the
 *    user icon green instead of grey. AND the top row left right spacing don't
 *    work, please check. AND the mobile search box height need to be
 *    controlled."
 *
 *   "and i need the mobile header rows, auto height adjust, mean if i adjust
 *    the height of rows. then inner content must need to be adjust
 *    automatically and resized as per the row height."
 *
 * WHAT THE SPACING BUG ACTUALLY WAS, because it is the one worth writing down.
 * `pad_left` and `pad_right` were live settings that the screen drew, dimmed to
 * 45% while "Match the page" was on, and left draggable. They saved. Then
 * cssVariables() — the only place that read them — threw them away for
 * PAGE_INSET whenever the toggle was on, and the toggle is on out of the box.
 * The stylesheet was never the problem: `header > .wrap` carries the two
 * properties inside the 900px query and beats every other rule that sets side
 * padding on that element. Measured in Chromium at 360x740 with
 * `--mh-l:28px;--mh-r:4px` forced onto the element: `.hin` x=28 w=328, against
 * x=12 w=336 untouched. The number could always get there; nothing ever sent
 * a different one.
 *
 * So the fix is that there is now ONE answer. all() reports what is in force,
 * save() writes the toggle and the two numbers together, and cssVariables()
 * reads all() without a second opinion.
 *
 * NOTHING HERE MAY MOVE A LIVE HEADER. Every new control's default reproduces
 * what the shop renders today, and the first test is the one that says so.
 * Verified in Chromium against the tip of the branch: at defaults every probe
 * on the phone header is identical to the pixel — header 181px tall, burger
 * 46x46, logo 280x44 at 22px, icon buttons 44x44 with 21x21 glyphs, the search
 * field 336x46 with 16px text. The single computed-style difference in the
 * whole header is `.search-in`'s min-height, 30px -> 44px, which changes no
 * box: the input inside it has been holding it open at 44 all along.
 */
beforeEach(function () {
    $this->mh = app(MobileHeader::class);
    $this->settings = app(SettingsService::class);
});

/** cssVariables() as a key => value map, so a test can name one property. */
function mhVars(MobileHeader $mh): array
{
    $out = [];

    foreach (explode(';', $mh->cssVariables()) as $pair) {
        [$k, $v] = array_pad(explode(':', $pair, 2), 2, '');
        $out[trim($k)] = trim($v);
    }

    return $out;
}

it('changes nothing about a header nobody has touched', function () {
    $vars = mhVars($this->mh);

    // The sides, unchanged: the page inset, which is what "Match the page"
    // produced before it was the only thing that could produce it.
    expect($vars['--mh-l'])->toBe('12px');
    expect($vars['--mh-r'])->toBe('12px');

    // The search field's height and the top row's, both stated as the number
    // the browser already measures rather than as a new opinion.
    expect($vars['--mh-sh'])->toBe('44px');
    expect($vars['--mh-rowh'])->toBe('44px');

    // The account mark inherits #2A2228 (--ink) today and carries no colour of
    // its own, so that is what "signed out" has to mean.
    expect($vars['--mh-acct'])->toBe('#2A2228');

    /*
     * AND NOT ONE SIZE PROPERTY. This is the assertion that keeps the promise.
     * Every rule in kbb.css is written `var(--mh-logo,var(--hd-logo))` or
     * `var(--mh-badge,10px)`, so an absent property leaves the declaration
     * resolving to exactly what it resolved to before the control existed —
     * including on a shop that has already moved the wordmark size on the
     * Header screen, where any fixed number here would be a visible change.
     *
     * MUTATION (run, red): default `size_logo` to 22 instead of 0 in
     * MobileHeader::SCHEMA. `--mh-logo:22px` is emitted and this fails.
     */
    foreach (['--mh-logo', '--mh-logoacc', '--mh-stsize', '--mh-phsize', '--mh-badge', '--mh-trendsize'] as $var) {
        expect($vars)->not->toHaveKey($var, "{$var} is emitted at the defaults, so an untouched shop's header moves");
    }
});

it('gives every piece of text in the phone header its own size, and lets it through', function () {
    $this->mh->save([
        'size_logo' => 26, 'size_accent' => 18, 'size_search' => 15,
        'size_ph' => 13, 'size_badge' => 12, 'size_trend' => 14,
    ]);

    $vars = mhVars(app(MobileHeader::class));

    expect($vars['--mh-logo'])->toBe('26px');
    expect($vars['--mh-logoacc'])->toBe('18px');
    expect($vars['--mh-stsize'])->toBe('15px');
    expect($vars['--mh-phsize'])->toBe('13px');
    expect($vars['--mh-badge'])->toBe('12px');
    expect($vars['--mh-trendsize'])->toBe('14px');

    /*
     * A property nothing reads is not a setting. Each one is checked against
     * the rule that consumes it, with the fallback that reproduces today.
     *
     * MUTATION (run, red): delete the `header .ib i{font-size:var(--mh-badge,
     * 10px)}` rule from kbb.css. The save still round-trips and the property
     * is still emitted; this half fails, which is the point of it.
     */
    $css = file_get_contents(base_path('resources/css/kbb/kbb.css'));

    expect($css)->toContain('font-size:var(--mh-logo,var(--hd-logo))');
    expect($css)->toContain('font-size:var(--mh-logoacc,inherit)');
    expect($css)->toContain('font-size:var(--mh-phsize,inherit)');
    expect($css)->toContain('font-size:var(--mh-badge,10px)');
    expect($css)->toContain('font-size:var(--mh-trendsize,12px)');
    // The typed text is the one that also carries the row's scale — see the
    // row-height test below for why the factor can only ever raise it.
    expect($css)->toContain('font-size:calc(var(--mh-stsize,16px) * max(1, var(--mh-sfit,1)))');
});

it('reports the side spacing that is actually in force, not the one stored under it', function () {
    /*
     * The state the old screen could leave a shop in, and the state
     * extrabeauty.ae may well be in right now: "Match the page" on, with a
     * Left the owner dragged and saved sitting underneath it.
     *
     * Both halves matter. all() must say 12, because 12 is what the header
     * draws and the screen must not show a number that is not happening. And
     * cssVariables() must still emit 12, because this package must not move
     * that shop's header the moment it lands.
     *
     * MUTATION (run, red): remove the `if ($out['match_page'])` block from
     * all(). all()['pad_left'] comes back 28 and --mh-l becomes 28px — the
     * live header jumps 16px on install, which is exactly what this forbids.
     */
    $this->settings->set('mhd_pad_left', 28);
    $this->settings->set('mhd_pad_right', 30);

    $mh = app(MobileHeader::class);

    expect($mh->all()['match_page'])->toBeTrue();
    expect($mh->all()['pad_left'])->toBe(12);
    expect($mh->all()['pad_right'])->toBe(12);
    expect(mhVars($mh)['--mh-l'])->toBe('12px');
    expect(mhVars($mh)['--mh-r'])->toBe('12px');
});

it('lets the top row left and right spacing through once it is the owner who is deciding', function () {
    $this->mh->save(['match_page' => false, 'pad_left' => 28, 'pad_right' => 4]);

    $vars = mhVars(app(MobileHeader::class));

    expect($vars['--mh-l'])->toBe('28px');
    expect($vars['--mh-r'])->toBe('4px');

    /*
     * And a rule reads them, inside the phone query, on the element that
     * carries them. `header > .wrap` is one element plus one class, which
     * beats the bare `.wrap{padding:0 22px}` in this file and the two in
     * kbb-shop.css and kbb-product.css that load after it.
     *
     * MUTATION (run, red): delete the `header > .wrap` rule from kbb.css. The
     * service half still passes and this fails, which is the split worth
     * having — the property reaching the element and a rule consuming it are
     * two different ways for this to be broken.
     */
    $css = file_get_contents(base_path('resources/css/kbb/kbb.css'));

    expect($css)->toContain('header > .wrap{padding-inline-start:var(--mh-l,22px);padding-inline-end:var(--mh-r,22px)}');
});

it('writes the toggle and the two numbers as one decision', function () {
    /*
     * Without this, a save can put the shop straight back into the state the
     * third test describes: toggle on, a different number underneath it. The
     * screen does the same thing on the way in (moving Left switches the
     * toggle off), and this is the half a request cannot get around.
     *
     * MUTATION (run, red): remove the `array_key_exists('match_page', ...)`
     * block from save(). mhd_pad_left is stored as 28 and this fails.
     */
    $this->mh->save(['match_page' => true, 'pad_left' => 28, 'pad_right' => 4]);

    expect((int) $this->settings->get('mhd_pad_left'))->toBe(12);
    expect((int) $this->settings->get('mhd_pad_right'))->toBe(12);

    // Turning it back off afterwards finds the page inset, not the 28 that was
    // never in force.
    app(MobileHeader::class)->save(['match_page' => false]);

    expect(app(MobileHeader::class)->all()['pad_left'])->toBe(12);
});

it('controls the height of the mobile search box, starting at the height it already has', function () {
    $css = file_get_contents(base_path('resources/css/kbb/kbb.css'));

    /*
     * 44 is measured, not chosen. `.sbox input{min-height:44px}` from the
     * mobile-polish tap-target pass is what decides this height today: the
     * input is the wrapper's content, so it holds a 30px wrapper open at 44.
     * Appearance → Header's "Search field · phone" feeds the WRAPPER and tops
     * out at 44, so it could never move the field in either direction — an
     * inert control, which is why the owner asked for this one.
     *
     * Both selectors are needed. The wrapper alone cannot make the field
     * shorter, because the input's own floor would hold it open.
     *
     * MUTATION (run, red): drop the `header .sbox .search-in input{min-height:
     * var(--mh-sh,44px)}` rule. This fails — and on a phone the field would
     * ignore every value under 44 while appearing to accept it.
     */
    expect($css)->toContain('header .sbox .search-in{min-height:var(--mh-sh,44px)}');
    expect($css)->toContain('header .sbox .search-in input{min-height:var(--mh-sh,44px)}');

    expect(mhVars($this->mh)['--mh-sh'])->toBe('44px');

    $this->mh->save(['search_h' => 60]);
    expect(mhVars(app(MobileHeader::class))['--mh-sh'])->toBe('60px');
});

it('sizes the row from one number and everything standing in it from that same number', function () {
    /*
     * Job 6. The row height is the driver and every derived size is a
     * MULTIPLICATION by --mh-fit, which is the row height over the 44 the row
     * is built out of — so it is 1 at the default and every product of it
     * renders the pixel it renders today. The per-element controls are the
     * other factor in the same product, which is why none of them can ever be
     * overridden into doing nothing.
     *
     * Measured in Chromium at 360x740 across the range:
     *     row 36  icon button 36x36, glyph 17.17, header 106.6px tall
     *     row 44  icon button 44x44, glyph 21.00, header 181.0px tall  (= tip)
     *     row 64  icon button 64x64, glyph 30.53, header 249.9px tall
     * with document.scrollWidth == 360 at all three, so nothing overflows the
     * screen sideways at either end of the range.
     *
     * THE BARE NUMBER IS THE WHOLE TRICK and is what this pins. CSS cannot
     * divide by a length: `calc(var(--mh-rowh) / 44px)` is invalid and the
     * browser drops the entire declaration, taking --mh-fit with it and
     * leaving every calc() that reads it invalid too.
     *
     * MUTATION (run, red): emit `--mh-rown` with a px suffix. This fails here;
     * in a browser the whole scaling silently stops working.
     */
    $vars = mhVars($this->mh);

    expect($vars['--mh-rown'])->toBe('44');
    expect($vars['--mh-shn'])->toBe('44');

    $css = file_get_contents(base_path('resources/css/kbb/kbb.css'));

    expect($css)->toContain('--mh-fit:calc(var(--mh-rown,44) / 44)');
    expect($css)->toContain('--mh-sfit:calc(var(--mh-shn,44) / 44)');
    // The two buttons scale whole — box, glyph and the little count together.
    expect($css)->toContain('header .kbbmi{zoom:var(--mh-fit,1)}');
    expect($css)->toContain('header .ib{zoom:var(--mh-fit,1)}');

    $this->mh->save(['row_h' => 64]);
    $vars = mhVars(app(MobileHeader::class));

    expect($vars['--mh-rowh'])->toBe('64px');
    expect($vars['--mh-rown'])->toBe('64');
});

it('leaves the wordmark out of the row scaling unless the shop asks for it', function () {
    /*
     * The wordmark is the one thing on the row that cannot give way: a single
     * nowrap line sharing 360px with a burger and three icon buttons. Measured
     * at the tip, at 360x740, the top row ALREADY wraps the three icons onto a
     * line of their own — the logo takes 280 of the 336 available. Scaling it
     * up from there is the one change that pushes the header taller again by
     * accident, so it is opt-in and everything else is not.
     *
     * MUTATION (run, red): default `fit_text` to true. bodyClass() carries
     * mhs-fittext with nothing saved and the first expectation fails.
     */
    expect($this->mh->bodyClass())->not->toContain('mhs-fittext');

    $this->mh->save(['fit_text' => true]);
    expect(app(MobileHeader::class)->bodyClass())->toContain('mhs-fittext');

    $css = file_get_contents(base_path('resources/css/kbb/kbb.css'));
    expect($css)->toContain('header.mhs-fittext .logo{font-size:calc(var(--mh-logo,var(--hd-logo)) * var(--mh-fit,1))}');
});

it('draws one set of icons, in the shop and in both previews', function () {
    /*
     * There were three sets and they disagreed. The storefront drew its own
     * inline SVGs; the Mobile Header preview drew two blank grey discs and no
     * icons at all; the Header screen's PHONE preview drew emoji —
     * &#128100; &#9825; &#128722; — which is the "dull grey filled person
     * silhouette, a heart outline and a cart" in the owner's screenshot, and
     * also why the mark could not be recoloured: an emoji is painted by the
     * platform's font and ignores `color`.
     *
     * MUTATION (run, red): put `&#128100;` back into hdPreview(). The emoji
     * check fails. Second mutation (run, red): paste the old inline
     * `<circle cx="12" cy="8" r="4"/>` account svg back into
     * partials/header.blade.php. The blade check fails.
     */
    $blade = file_get_contents(base_path('resources/views/partials/header.blade.php'));
    $admin = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    foreach (['account', 'wishlist', 'cart'] as $mark) {
        expect($blade)->toContain("\\App\\Support\\HeaderIcons::{$mark}()");
    }

    expect($admin)->toContain('const MHICONS=@json(\App\Support\HeaderIcons::forPreview());');
    expect($admin)->toContain('${MHICONS.account}');
    expect($admin)->toContain('${MHICONS.wishlist}');
    expect($admin)->toContain('${MHICONS.cart}');

    foreach (['&#128100;', '&#9825;', '&#128722;'] as $emoji) {
        expect($admin)->not->toContain($emoji);
    }

    // The account mark is FILLED, like the silhouette it replaces — and that
    // is also what lets one colour paint the whole of it.
    expect(HeaderIcons::account())->toContain('fill="currentColor"');
    expect(HeaderIcons::wishlist())->toContain('stroke="currentColor"');
    expect(HeaderIcons::cart())->toContain('stroke="currentColor"');
});

it('turns the account mark green for someone who is signed in', function () {
    /*
     * Both colours are settings rather than a hex in a stylesheet, and the
     * signed-out one is #2A2228 because that is what the mark inherits today
     * through body -> a{color:inherit}. The green is the one the signed-in dot
     * beside it has always used (HeaderSettings' account_dot_col).
     *
     * Measured in Chromium: signed out the glyph computes rgb(42,34,40);
     * with `mh-signedin` on the link it computes rgb(31,157,85), and setting
     * --mh-accti to #C13E63 moves it to rgb(193,62,99) — so the class decides
     * it and the setting colours it.
     *
     * MUTATION (run, red): drop the `@auth('customer') mh-signedin @endauth`
     * from the blade. The class check fails.
     */
    $vars = mhVars($this->mh);

    expect($vars['--mh-acct'])->toBe('#2A2228');
    expect($vars['--mh-accti'])->toBe('#1F9D55');

    $blade = file_get_contents(base_path('resources/views/partials/header.blade.php'));
    expect($blade)->toContain("@auth('customer') mh-signedin @endauth");

    $css = file_get_contents(base_path('resources/css/kbb/kbb.css'));
    expect($css)->toContain('header .ib-acct > .ib svg{color:var(--mh-acct,#2A2228)}');
    expect($css)->toContain('header .ib-acct > .ib.mh-signedin svg{color:var(--mh-accti,#1F9D55)}');

    $this->mh->save(['acct_in' => '#0B6B3A', 'acct_out' => '#777777']);
    $vars = mhVars(app(MobileHeader::class));

    expect($vars['--mh-accti'])->toBe('#0B6B3A');
    expect($vars['--mh-acct'])->toBe('#777777');
});

it('puts the class on the account link only for a shopper who is signed in', function () {
    /*
     * The rendered half, and the reason it signs in the way it does.
     *
     * A shopper is on the `customer` guard. Signing in here writes the session
     * key that guard reads, exactly as a browser does, rather than calling
     * actingAs() — actingAs() calls Auth::shouldUse() and moves the DEFAULT
     * guard, which would make a `@auth('customer')` check pass for the wrong
     * reason and, worse, would also make a plain `@auth` pass. That is the
     * whole distinction this test exists to hold, so it must not be handed to
     * the helper. The long note at the top of AccountAreaTest.php is the
     * incident this convention came out of.
     *
     * MUTATION (run, red): change the blade to a bare `@auth`. The guest half
     * still passes; the signed-in half fails, because the default guard is
     * `web` and no shopper is ever on it.
     */
    expect($this->get('/')->getContent())->not->toContain('mh-signedin');

    $shopper = \App\Models\Customer::create([
        'name' => 'Ada Shopper',
        'email' => 'green-icon@example.com',
        'password' => 'password123',
    ]);

    $key = 'login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class);

    expect($this->withSession([$key => $shopper->id])->get('/')->getContent())
        ->toContain('mh-signedin');
});

it('leaves no setting the screen cannot reach', function () {
    /*
     * Eleven controls were added at once. A key in SCHEMA that no tab lists is
     * saved by nothing, shown by nothing and read by cssVariables() — a dead
     * control that looks alive in the API response, which is the shape of the
     * bug this whole lane was sent to fix.
     *
     * MUTATION (run, red): remove 'size_trend' from the 'type' tab. It is
     * named as unreachable and this fails.
     */
    $tabbed = [];

    foreach (MobileHeader::TABS as [$label, $description, $keys]) {
        foreach ($keys as $key) {
            $tabbed[] = $key;
        }
    }

    $unreachable = array_diff(array_keys(MobileHeader::SCHEMA), $tabbed);
    $phantom = array_diff($tabbed, array_keys(MobileHeader::SCHEMA));

    expect($unreachable)->toBe([], 'settings no tab shows: ' . implode(', ', $unreachable));
    expect($phantom)->toBe([], 'tabs naming settings that do not exist: ' . implode(', ', $phantom));
    expect($tabbed)->toBe(array_unique($tabbed), 'a setting is listed on two tabs');
});

it('says Unchanged rather than 0px on a size control nobody has set', function () {
    /*
     * A slider reading "0px" beside "Wordmark" reads as invisible text. The
     * zero is the off position, and the screen has to say so.
     *
     * MUTATION (run, red): delete the `o.zero` branch from mhReadout(). The
     * helper no longer distinguishes the two and this fails.
     */
    $admin = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect($admin)->toContain('return (o.zero && Number(v)===0) ? escHtml(o.zero) : (v+(o.unit||\'\'));');
    expect($admin)->toContain('<i id="mhv-${f.key}">${mhReadout(f,v)}</i>');

    foreach (['size_logo', 'size_accent', 'size_search', 'size_ph', 'size_badge', 'size_trend'] as $key) {
        expect(MobileHeader::SCHEMA[$key][4])->toHaveKey('zero');
        expect(MobileHeader::SCHEMA[$key][2])->toBe(0);
    }
});
