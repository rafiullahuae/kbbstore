<?php

declare(strict_types=1);

/**
 * Lane DD — the console does not describe machinery it does not have.
 *
 * Three claims, none of which had anything behind it:
 *
 *   1. The amber strip under the top bar told the owner that while the Live /
 *      Sandbox switch read Sandbox, his changes were held back from the live
 *      store until a deploy. The switch sets an attribute on <body>. There is
 *      one database and every screen writes to it either way, so the promise
 *      invited him to try a price or a VAT rate "safely" on the real shop.
 *
 *   2. Safety -> Sandbox & Deploy showed five pre-flight checks lit green, a
 *      diff summary, a Deploy to Live button and a Rollback button, and
 *      promised a database backup on every deploy. The checks were string
 *      literals, the deploy handler was a setTimeout, and no code path in this
 *      application has ever backed up the database from that screen. The real
 *      mechanism is Core Updates, one row down the same sidebar.
 *
 *   3. Store -> SEO & Meta grouped four verification tokens and two tracking
 *      fields under one line saying the section changed nothing about the
 *      storefront. Saving a Google Analytics ID puts Google's tag on every
 *      page; saving a Meta Pixel ID does nothing at all, because nothing reads
 *      `meta_pixel` — the pixel that fires is the one on Marketing Pixels.
 *
 * THE COMMENT TRAP. The notes left in app.blade.php beside each of these
 * necessarily describe the claim that was removed, and a plain search of that
 * file would find the words in the explanation and fail. Every assertion below
 * runs against a copy with HTML and block comments stripped, so it reads code
 * and page copy only. That is the failure five lanes here have already hit.
 */

$lddSource = static function (): string {
    $src = file_get_contents(resource_path('views/admin/app.blade.php'));

    // Comments out, so a guard cannot be satisfied — or defeated — by prose.
    $src = preg_replace('/<!--.*?-->/s', ' ', $src);
    $src = preg_replace('/\{\{--.*?--\}\}/s', ' ', $src);
    $src = preg_replace('#/\*.*?\*/#s', ' ', $src);

    return $src;
};

it('does not tell the owner his changes are staged anywhere', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, 'isolated from the live store'))
        ->toBeFalse('The Live / Sandbox switch stages nothing; the strip must not say it does.');

    expect(str_contains($code, 'Everything you change here is saved to the live shop immediately'))
        ->toBeTrue('The strip has to say what the switch actually leaves the owner looking at.');
});

it('does not report a switch of environment that did not happen', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, "'Switched to Sandbox'"))
        ->toBeFalse('Nothing switched: the handler sets document.body.dataset.env and stops.');
});

it('offers no deploy, rollback or pre-flight check it cannot perform', function () use ($lddSource) {
    $code = $lddSource();

    foreach ([
        'Deploy to Live',
        'Rollback',
        '5 / 5 passed',
        'Clone of live data',
        'auto-backs-up the live database',
    ] as $claim) {
        expect(str_contains($code, $claim))
            ->toBeFalse('Sandbox & Deploy still advertises "' . $claim . '", which nothing behind it does.');
    }
});

/**
 * The dashboard's third card. It was labelled "live feed" over three literals
 * dated "just now" — a foundation initialised, a monitor watching, a sandbox
 * ready — and hydrateDash() only overwrote them when the store HAD recent
 * orders. A quiet shop read invented events for ever.
 */
it('shows no invented activity on the dashboard', function () use ($lddSource) {
    $code = $lddSource();

    foreach ([
        'Foundation initialised',
        'Debug &amp; Monitor enabled',
        'Sandbox ready',
        'Pre-flight checks armed for first deploy',
    ] as $invented) {
        expect(str_contains($code, $invented))
            ->toBeFalse('The dashboard still prints the invented event "' . $invented . '".');
    }

    expect(str_contains($code, 'No orders yet.'))
        ->toBeTrue('A store with no orders has to be told that, not shown a feed of nothing.');
});

it('sends the owner to the screen that really versions and restores this site', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, 'There is no sandbox to deploy from'))
        ->toBeTrue('Safety -> Sandbox & Deploy has to say what is true of this install.');

    expect(str_contains($code, "<button class=\"btn\" onclick=\"go('updates')\">Core Updates"))
        ->toBeTrue('The honest screen has to hand the owner the real one, as Meta & Facebook does.');
});

it('leaves no caller for the deploy and rollback handlers it removed', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, 'window.deploy'))->toBeFalse('Exported a handler with no button.');
    expect(str_contains($code, 'window.rollback'))->toBeFalse('Exported a handler with no button.');
    expect(str_contains($code, 'onclick="deploy()"'))->toBeFalse('A button with no handler.');
    expect(str_contains($code, 'onclick="rollback()"'))->toBeFalse('A button with no handler.');
});

it('does not claim the SEO tracking fields leave the storefront alone', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, 'nothing here changes how the storefront behaves'))
        ->toBeFalse('A Google Analytics ID saved on that screen loads Google’s tag on every page.');

    expect(str_contains($code, 'Saving an ID here loads Google’s tag on every storefront page'))
        ->toBeTrue('The Analytics box has to say what saving it does.');
});

/**
 * The Meta Pixel box on SEO & Meta writes `meta_pixel`; App\Services\
 * MarketingPixels writes `meta_id` and is the one the storefront fires. Both
 * boxes exist, so the dead one has to say which it is until the owner decides
 * which to keep.
 */
it('says which of the two Meta Pixel boxes is the one that fires', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, 'Stored, but no storefront page fires it.'))
        ->toBeTrue('The SEO screen must not present a dead pixel box as a working one.');
});


/* ==========================================================================
 * LANE DH — four more screens that were describing machinery they do not have.
 *
 * Same file, same stripped-source helper, on purpose. The notes left in
 * app.blade.php beside each fix below necessarily quote the claim that was
 * removed, so a plain search of that file would find the words in the
 * explanation and pass or fail for the wrong reason. $lddSource() strips HTML
 * and block comments first; every assertion here goes through it.
 * ========================================================================== */

/**
 * The dashboard's health card and the whole of Safety → Debug & Monitor.
 *
 * Six hrow() literals under a green pill reading "All core OK", and six more
 * under an amber one reading "2 need setup". An App server with a 120ms
 * average, a database doing 4ms reads in WAL mode — on an install whose
 * production database is MySQL — a Storefront API that was operational and a
 * Sandbox that was in sync with a sandbox this application does not have.
 * Nothing measured any of them, so the card read the same on the morning every
 * product page was 500ing as it did on a good day.
 */
it('invents no service health on the dashboard or on Debug & Monitor', function () use ($lddSource) {
    $code = $lddSource();

    foreach ([
        'All core OK',
        'WAL · healthy',
        'WAL · 4ms reads',
        '120ms avg',
        '2 need setup',
        'Database (SQLite)',
        "hrow('green','Storefront API','operational')",
        "hrow('green','Sandbox','in sync')",
        "hrow('amber','Stripe','keys not added')",
        "hrow('amber','Tabby / Tamara','not configured')",
    ] as $claim) {
        expect(str_contains($code, $claim))
            ->toBeFalse('a health panel still states "'.$claim.'", which nothing behind it measures');
    }
});

/**
 * And the replacement has to start blank. A card that renders a tick before
 * anything has been checked is the same defect with better plumbing: the owner
 * cannot tell "we looked and it is fine" from "we have not looked".
 */
it('starts the health card at not-checked and fills it from the endpoint', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, 'Not checked yet'))
        ->toBeTrue('the health card has to say it has not run before it has run');

    expect(str_contains($code, "api('/admin-api/health')"))
        ->toBeTrue('the card has to be filled from the real check, not from literals');

    // The rows and the pill are written by shPaint() and by nothing else.
    expect(str_contains($code, 'function shPaint()'))
        ->toBeTrue('the one painter both screens share is gone');
});

/**
 * Debug & Monitor's error console listed three errors that never happened,
 * each with an invented count and age, and its "Copy report for Claude" button
 * opened a diagnostic naming a Stripe failure at modules/payments/api.js:48 —
 * a file that does not exist in this application — for a request of AED 549.78
 * that nobody ever made.
 */
it('lists no error that did not happen', function () use ($lddSource) {
    $code = $lddSource();

    foreach ([
        'PaymentGateway: Stripe keys missing',
        'Image 404 on import preview',
        'SMTP not configured — email queued',
        'PaymentGateway: Stripe secret key missing',
        'modules/payments/api.js:48',
        'add Stripe secret key in Payments settings',
    ] as $invented) {
        expect(str_contains($code, $invented))
            ->toBeFalse('Debug & Monitor still reports the invented error "'.$invented.'"');
    }
});

/**
 * The Copy report button copied nothing. It closed the modal and raised a
 * toast saying the report had been copied, so whatever the owner pasted was
 * whatever he had copied last.
 */
it('does not claim to have copied a report it never put on the clipboard', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, "toast('Report copied — paste it to Claude')"))
        ->toBeFalse('the copy button still reports a copy it does not make');

    expect(str_contains($code, 'navigator.clipboard.writeText(text)'))
        ->toBeTrue('the copy button has to actually copy');
});

/**
 * The sidebar badge. navItemHTML() rendered a literal 3 for any NAV row tagged
 * 'live', and Debug & Monitor was the only row that carried the tag. Not a
 * count of anything — three, always, on every install, beside a screen whose
 * three errors were themselves typed in. The bell in the top bar had the same
 * problem: a red dot in the markup that nothing ever set or cleared.
 */
it('shows no alert count that is not a count of anything', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, '<span class="cnt">3</span>'))
        ->toBeFalse('the sidebar still prints a hard-coded alert badge');

    expect(str_contains($code, "tag==='live'"))
        ->toBeFalse('the branch that printed the hard-coded badge is still reachable');

    expect(str_contains($code, "'Debug & Monitor',I.debug,'live'"))
        ->toBeFalse('the Debug row still carries the tag that drew the badge');

    expect(str_contains($code, '</svg><span class="dot"></span></button>'))
        ->toBeFalse('the top-bar bell still carries a permanently lit alert dot');
});

/**
 * Shop Filters kept its configuration in a `let` in this file and its Save
 * button was a toast. There is no shop-filters endpoint and no shop-filters
 * setting; resources/views/store/shop.blade.php renders four fixed groups and
 * reads no configuration at all. The owner could switch Brand off, press Save,
 * be told it saved, and the shop never differed by a pixel.
 */
it('offers no Save on Shop Filters, because nothing stores what it would save', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, "toast('Shop filters saved (preview)')"))
        ->toBeFalse('the Shop Filters Save button still reports a save that never happens');

    expect(str_contains($code, 'Shop Filters isn&rsquo;t built yet'))
        ->toBeTrue('Shop Filters has to say what it is, as Meta & Facebook does');

    // And the controls that pretended to configure it went with the button.
    foreach (['SF_CONCERNS', 'renderSFGroups', 'renderSFPrev'] as $orphan) {
        expect(str_contains($code, $orphan))
            ->toBeFalse('"'.$orphan.'" is left behind with nothing to save it');
    }
});

/**
 * Platform → Settings offered six cards — Store details, Regional,
 * Localisation, Notifications, Security, API & Keys — each of which raised a
 * toast and opened nothing. Four of the six subjects exist in this console
 * already, under other names, fully wired; the screen is the index of those
 * now, and says plainly that the other two are not built.
 *
 * SCOPED TO renderSettings(). "Phase 0 build" still appears elsewhere in this
 * console — Users & Roles has an Invite user button that raises it — and that
 * screen is another lane's. A file-wide search here would either fail on
 * somebody else's defect or, worse, be softened until it stopped catching this
 * one.
 */
it('opens a real screen from every Settings card it still shows', function () use ($lddSource) {
    $code = $lddSource();

    $from = strpos($code, 'function renderSettings()');
    $to = strpos($code, 'function renderDebug()');

    expect($from !== false && $to !== false && $to > $from)
        ->toBeTrue('renderSettings() and renderDebug() are no longer where this test looks for them');

    $screen = substr($code, $from, $to - $from);

    expect(str_contains($screen, 'toast('))
        ->toBeFalse('a Settings card still answers a press with a message and nothing else');

    foreach (['store-settings', 'tax', 'mail', 'payments', 'shipping', 'seo'] as $id) {
        expect(str_contains($screen, "'".$id."',"))
            ->toBeTrue('the Settings index no longer routes to '.$id);
    }

    // The two with nothing behind them say so, rather than pointing somewhere
    // almost-right.
    expect(str_contains($screen, 'This shop is in English only.'))
        ->toBeTrue('Localisation has to say it is not built');
    expect(str_contains($screen, 'cannot be edited from this console'))
        ->toBeTrue('Security settings has to say it is not built');
});
