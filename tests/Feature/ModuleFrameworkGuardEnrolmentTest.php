<?php

/**
 * What enrolling four settings modules in the module framework guard found —
 * Lane MC.
 *
 * ── WHY THIS FILE IS NOT PART OF ModuleFrameworkGuardTest ───────────────────
 *
 * That file pins the framework as a CLASS OF THING: every module on its list
 * has its SCHEMA compared against its TABS, and neither may name something the
 * other does not. It is the right guard and it is why these four modules were
 * enrolled.
 *
 * But it can only compare TWO CONSTANTS. A TABS lifted out of a screen is only
 * worth anything if it says what that screen really draws, and four of these
 * screens are hand-written JavaScript in their own partials rather than
 * schema-rendered forms — nothing in the payload they receive names a field, so
 * the guard cannot ask the endpoint the way it asks Pay & Ship Rules. Left
 * there, a TABS could drift from its screen and both halves of the guard would
 * still agree with each other while agreeing about nothing real.
 *
 * So this file is the other end of each of those four constants: it reads the
 * console file that draws the screen and requires that the control is really
 * there, and that the label the schema now carries is the wording on the page.
 * It is the same move ModuleFrameworkGuardTest already makes for its one
 * bespoke-control exemption ("has a real console control behind every
 * bespoke-control exemption"), applied to four screens.
 *
 * ── WHAT THE ENROLMENT FOUND, MODULE BY MODULE ──────────────────────────────
 *
 *   review_settings   Nothing. All eleven keys have a control, each drawn
 *                     once, and the three cards on the screen are the three
 *                     groups. The only gap was the schema carrying no `label`
 *                     for any field, which the guard refuses; the eleven now
 *                     there are the screen's own `row()` literals.
 *
 *   cache             Nothing. Three keys, three controls, one Save. Same
 *                     label gap, filled the same way.
 *
 *   review_badges     A REAL DEFECT, and not on this module's own screen —
 *                     see below. Reviews → Rating Badge itself draws all seven
 *                     keys once each across its two tabs.
 *
 *   mail              Nothing the guard can call a failure, and one thing
 *                     worth the owner's attention: `mail_cancelled_refund_note`,
 *                     `mail_shipped_timing_note` and `mail_reply_to` are named
 *                     in no band of app.blade.php's MAIL_SECTIONS and reach the
 *                     owner only through mailSections()'s leftover sweep, under
 *                     the heading "Other settings / Added to this store after
 *                     this screen was laid out". They ARE drawn — that sweep
 *                     exists precisely so a new key cannot vanish, and
 *                     MailScreenDesignTest already pins that it stays — so this
 *                     is not a value with no control. It is three boxes about
 *                     refunds, dispatch timing and replies filed under the
 *                     mechanism that caught them rather than under what they
 *                     do. app.blade.php is nobody's this round; the lane report
 *                     asks the integrator to name them.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * `review_capsule_style` — ReviewBadgeSettings' key, the one that decides
 * whether a product page shows a rating at all — was placed TWICE in one tab of
 * Store → Ecommerce. Admin\EcommerceApiController's `product` tab had a
 * `badges` section holding all seven badge keys and, four lines later, a
 * `ratings` section whose entire field list was `['review_capsule_style']`.
 * tabs() places a field once per section that names it, so the owner was shown
 * two "Rating display" dropdowns over one settings row.
 *
 * That is the third failure ModuleFrameworkGuardTest names in its own words:
 * "Twice in one tab is not a second control, it is the same one drawn twice —
 * and whichever the operator filled in last would appear to win." It had been
 * shipping since the 2.60.41 baseline. The fix removed the `ratings` section
 * and its now-unreachable preview; the field keeps its home beside its six
 * siblings, which is where ReviewBadgeSettings' own header says all seven live.
 */

use App\Http\Controllers\Admin\EcommerceApiController;
use App\Models\AdminUser;
use App\Services\Mail\MailSettings;
use App\Services\ModuleSchema;
use App\Support\CacheSettings;
use App\Support\ReviewBadgeSettings;
use App\Support\ReviewSettings;
use Illuminate\Support\Facades\Hash;

function mcAsOwner(): void
{
    $owner = AdminUser::create([
        'name' => 'MC Owner',
        'email' => 'mc-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');
}

/** The console file that draws a screen, read whole. */
function mcPartial(string $name): string
{
    $path = base_path('resources/views/admin/partials/'.$name);

    expect(file_exists($path))->toBeTrue("the console file {$name} is gone");

    return (string) file_get_contents($path);
}

/* ══════════════════════════ the defect, and its fix ════════════════════════ */

it('never places one Ecommerce field in two sections of the same tab', function () {
    /*
     * THE TEST THAT WOULD HAVE CAUGHT IT.
     *
     * What it looked like on the shop: Store → Ecommerce → Product page showed
     * "Rating display" twice — once inside "Review badges" with the heart, the
     * average, the count, the wording, the sold note and the star colour, and
     * once on its own under a "Ratings" heading. Both wrote
     * `review_capsule_style`. Change one, press Save, and the other still shows
     * the value from before the save until the screen is reloaded, so the
     * screen disagrees with itself about whether the product page shows a
     * rating at all.
     *
     * Asked of the ENDPOINT rather than of the constant, because what the owner
     * sees is what tabs() emits: a section naming a key the tab does not carry
     * produces nothing, and a key named twice produces two controls. Only the
     * response can tell those apart.
     *
     * MUTATION: put
     *
     *     'ratings' => ['Ratings', 'How the review score is shown.', 'star',
     *                   ['review_capsule_style']],
     *
     * back among the `product` tab's sections in EcommerceApiController and
     * this is red with "product draws review_capsule_style 2 times". Confirmed
     * by running it.
     */
    mcAsOwner();

    $body = test()->getJson('/admin-api/ecommerce')->assertOk()->json();

    $twice = [];

    foreach ($body['tabs'] as $tab) {
        $drawn = [];

        foreach ($tab['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $drawn[] = $field['name'];
            }
        }

        foreach (array_count_values($drawn) as $name => $count) {
            if ($count > 1) {
                $twice[] = $tab['key'].' draws '.$name.' '.$count.' times';
            }
        }
    }

    expect($twice)->toBe([], 'These Ecommerce controls are drawn more than once in one tab, so the owner has two boxes over one stored row: '.implode('; ', $twice));
});

it('keeps no Ecommerce preview that no section or field can reach', function () {
    /*
     * THE OTHER HALF OF THE FIX ABOVE. Removing the duplicate `ratings` section
     * without removing the `ratings` preview would have left a drawing of a
     * screen that does not exist — the class of defect
     * ModuleFrameworkGuardTest's own header lists five times over, and the one
     * this lane must not commit while fixing another.
     *
     * ONE DIRECTION ONLY, and the absence of the other is deliberate. Seven
     * sections — reminders, guestacct, legal, validation, address, stockalert,
     * trust — already claim a `preview` that previews() does not carry, and
     * that is harmless by construction: tabs() sets `preview => $sk` for every
     * section and `preview => $name` for every field, and the console simply
     * draws no eye icon for a name the map does not hold, which its own comment
     * says in as many words. An empty map entry is nothing; an entry nobody can
     * open is a claim.
     *
     * It was zero before this lane and it is zero after it, which is the only
     * reason it can be asserted outright rather than against a known list.
     *
     * MUTATION: put the `ratings` preview back without its section and this is
     * red with "ratings". Confirmed by running it.
     */
    mcAsOwner();

    $body = test()->getJson('/admin-api/ecommerce')->assertOk()->json();
    $previews = array_keys($body['previews']);

    $claimed = [];
    $sections = [];

    foreach ($body['tabs'] as $tab) {
        foreach ($tab['sections'] as $section) {
            // The catch-all band is built with `preview => null`; it names no
            // section and is not expected to have a drawing.
            if ($section['preview'] !== null && $section['preview'] !== '') {
                $sections[] = $section['preview'];
            }

            foreach (array_column($section['fields'], 'preview') as $name) {
                if ($name === null || $name === '') {
                    continue;
                }

                $claimed[] = $name;
            }
        }
    }

    $claimed = array_values(array_unique(array_merge($claimed, $sections)));

    expect($previews)->not->toBeEmpty('the Ecommerce screen no longer returns any previews');
    expect($claimed)->not->toBeEmpty('no Ecommerce section or field claims a preview at all');

    $orphans = array_values(array_diff($previews, $claimed));

    expect($orphans)->toBe([], 'These Ecommerce previews are drawings of a section that no longer exists: '.implode(', ', $orphans));
});

it('leaves the rating display on the section that holds its six siblings', function () {
    /*
     * WHICH of the two duplicates survived, pinned, because "remove the
     * duplicate" has two answers and only one of them is right. Moving
     * `review_capsule_style` out of `badges` instead would have split one
     * seven-key set across two headings and left ReviewBadgeSettings' own
     * header ("Store → Ecommerce → Product page → Review badges") describing a
     * screen that no longer matched.
     *
     * MUTATION: move the key out of the `badges` section and this is red.
     * Confirmed by running it.
     */
    mcAsOwner();

    $body = test()->getJson('/admin-api/ecommerce')->assertOk()->json();

    $found = null;

    foreach ($body['tabs'] as $tab) {
        foreach ($tab['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['name'] === 'review_capsule_style') {
                    $found = $tab['key'].'.'.$section['key'];
                }
            }
        }
    }

    expect($found)->toBe('product.badges');

    // And all seven of ReviewBadgeSettings' keys are on that one section, which
    // is the arrangement the duplicate was hiding.
    $onBadges = [];

    foreach ($body['tabs'] as $tab) {
        foreach ($tab['sections'] as $section) {
            if ($tab['key'].'.'.$section['key'] !== 'product.badges') {
                continue;
            }

            $onBadges = array_column($section['fields'], 'name');
        }
    }

    sort($onBadges);
    $expected = array_keys(ReviewBadgeSettings::SCHEMA);
    sort($expected);

    expect($onBadges)->toBe($expected);
});

/* ═══════════ the four TABS really describe the screens they were lifted from ═ */

it('draws a real control on Review Settings for every key its TABS names', function () {
    /*
     * ReviewSettings::TABS was lifted out of review-settings-screen.blade.php,
     * and this is what makes that claim checkable rather than a comment. The
     * screen builds its own HTML in JavaScript — the endpoint answers values,
     * not fields — so the control has to be found in the source, the way
     * MailScreenDesignTest finds the Mail screen's.
     *
     * The shapes are the four the partial has: `sw('key')` for a switch,
     * `num('key',` for a number, and a literal `data-rvs-enum` / `data-rvs-text`
     * attribute for the select and the one wide text row.
     *
     * MUTATION: delete the `row('sr_rate_limit', …)` line from the partial and
     * this is red with "sr_rate_limit is in ReviewSettings::TABS and the screen
     * draws no control for it". Confirmed by running it.
     */
    $screen = mcPartial('review-settings-screen.blade.php');

    foreach (array_keys(ReviewSettings::SCHEMA) as $key) {
        $drawn = str_contains($screen, "sw('{$key}')")
            || str_contains($screen, "num('{$key}',")
            || str_contains($screen, "data-rvs-enum=\"{$key}\"")
            || str_contains($screen, "data-rvs-text=\"{$key}\"");

        expect($drawn)->toBeTrue(
            "{$key} is in ReviewSettings::TABS and the screen draws no control for it. "
            .'A value with no control is a shipped default that is the only value it will ever have.'
        );
    }
});

it('gives every Review Settings control the label the schema now carries', function () {
    /*
     * The labels added to ReviewSettings::SCHEMA are the screen's own literals,
     * and that is the whole justification for adding them — the class's own
     * docblock used to refuse to invent any. This is what stops the two
     * drifting: the label here IS what the owner reads.
     *
     * MUTATION: change 'Score summary' to 'Score' in either the schema or the
     * partial and this is red. Confirmed by running it.
     */
    $screen = mcPartial('review-settings-screen.blade.php');

    foreach (ModuleSchema::normalise(ReviewSettings::SCHEMA, ReviewSettings::POLICY) as $key => $field) {
        // sr_empty_text is the one row drawn inline rather than through row(),
        // so its label is markup rather than an argument.
        $onScreen = $key === 'sr_empty_text'
            ? str_contains($screen, '>'.$field['label'].'</div>')
            : str_contains($screen, "row('{$key}', '".$field['label']."'");

        expect($onScreen)->toBeTrue(
            "ReviewSettings::SCHEMA labels {$key} “{$field['label']}”, which is not what the screen prints."
        );
    }
});

it('draws a real control on the Cache screen for every key its TABS names', function () {
    /*
     * Platform → Cache posts its three values under the WIRE names in
     * CacheSettings::FIELDS, not under the storage keys — that constant's own
     * docblock records why (a dot is a path to Laravel's validator, and the
     * first version of this feature saved nothing at all because of it). So the
     * control is found by the wire name and the storage key is reached through
     * FIELDS, which is the one place the two vocabularies meet.
     *
     * MUTATION: drop `'asset_max_age': Number(...)` from the payload the
     * partial posts and this is red. Confirmed by running it.
     */
    $screen = mcPartial('cache-screen.blade.php');

    $wireFor = array_flip(CacheSettings::FIELDS);

    foreach (array_keys(CacheSettings::SCHEMA) as $key) {
        expect(array_key_exists($key, $wireFor))->toBeTrue(
            "{$key} has no wire name in CacheSettings::FIELDS, so the screen cannot post it"
        );

        expect(str_contains($screen, "'".$wireFor[$key]."': "))->toBeTrue(
            "{$key} is in CacheSettings::TABS and the Cache screen never posts its wire name “{$wireFor[$key]}”."
        );
    }

    // The other direction: the screen may not post a wire name that maps to
    // nothing — CacheApiController::save() would fatal on FIELDS[$field].
    preg_match_all("/'([a-z_]+)': (?:!!\(|Number\()/", $screen, $posted);

    expect($posted[1])->not->toBeEmpty('the Cache screen no longer posts a payload this test can read');

    foreach (array_unique($posted[1]) as $wire) {
        expect(array_key_exists($wire, CacheSettings::FIELDS))->toBeTrue(
            "The Cache screen posts “{$wire}”, which CacheSettings::FIELDS does not name."
        );
    }
});

it('gives every Cache control the label the schema now carries', function () {
    // MUTATION: change 'Browsers may keep them for' in either the schema or the
    // partial and this is red. Confirmed by running it.
    $screen = mcPartial('cache-screen.blade.php');

    foreach (ModuleSchema::normalise(CacheSettings::SCHEMA, CacheSettings::POLICY) as $key => $field) {
        expect(str_contains($screen, $field['label']))->toBeTrue(
            "CacheSettings::SCHEMA labels {$key} “{$field['label']}”, which is not what the screen prints."
        );
    }
});

it('draws a real control on the Rating Badge screen for every key its TABS names', function () {
    /*
     * Reviews → Rating Badge owns both 'rev-badge' and 'rev-capsule' since Lane
     * CL merged them, so ONE partial has to carry all seven controls. Its own
     * `KEYS` array is what the save posts, and each control is a `data-rbt-*`
     * binding or, for the colour, the pair of inputs the picker writes.
     *
     * MUTATION: delete `opt('review_badge_sold', …)` from themesTab() and this
     * is red. Confirmed by running it.
     */
    $screen = mcPartial('review-badges-screen.blade.php');

    foreach (array_keys(ReviewBadgeSettings::SCHEMA) as $key) {
        $drawn = str_contains($screen, "opt('{$key}',")
            || str_contains($screen, "data-rbt-enum=\"{$key}\"")
            || str_contains($screen, "data-rbt-text=\"{$key}\"")
            // The colour is a native picker plus a hex box, bound by id.
            || ($key === 'review_badge_colour' && str_contains($screen, "id=\"rbt-hex\""));

        expect($drawn)->toBeTrue(
            "{$key} is in ReviewBadgeSettings::TABS and the Rating Badge screen draws no control for it."
        );
    }

    // And the payload it posts is exactly this schema — a key posted that the
    // schema does not declare would be written into `settings` by a screen
    // nothing else knows about.
    $from = strpos($screen, 'var KEYS = [');
    expect($from)->not->toBeFalse('the Rating Badge screen no longer declares the keys it saves');

    $list = substr($screen, $from, (int) strpos($screen, '];', $from) - $from);
    preg_match_all("/'([a-z_]+)'/", $list, $m);

    sort($m[1]);
    $expected = array_keys(ReviewBadgeSettings::SCHEMA);
    sort($expected);

    expect($m[1])->toBe($expected, 'What the Rating Badge screen saves and what ReviewBadgeSettings declares do not match');
});

it('gives every Rating Badge control the label the schema now carries', function () {
    /*
     * Two shapes on this screen: `opt(key, label, hint)` for the four switches
     * and `field(label, control, hint)` for the three that are not.
     *
     * MUTATION: change 'Star colour' in either the schema or the partial and
     * this is red. Confirmed by running it.
     */
    $screen = mcPartial('review-badges-screen.blade.php');

    foreach (ModuleSchema::normalise(ReviewBadgeSettings::SCHEMA, ReviewBadgeSettings::POLICY) as $key => $field) {
        $onScreen = str_contains($screen, "opt('{$key}', '".$field['label']."'")
            || str_contains($screen, "field('".$field['label']."'");

        expect($onScreen)->toBeTrue(
            "ReviewBadgeSettings::SCHEMA labels {$key} “{$field['label']}”, which is not what the screen prints."
        );
    }
});

it('names in MAIL_SECTIONS only what the Mail schema declares, and draws the rest', function () {
    /*
     * The Mail screen is the one of the four that is NOT its own partial: it is
     * drawn from app.blade.php's MAIL_SECTIONS, which is nobody's file this
     * round. MailScreenDesignTest already pins that every schema key is drawn
     * somewhere and that the leftover sweep survives, so what is added here is
     * the pairing against MailSettings::TABS — the constant this lane wrote.
     *
     * THE FINISHED STATE, NOT THE CURRENT ONE. Three of the seventeen keys
     * (`mail_cancelled_refund_note`, `mail_shipped_timing_note`,
     * `mail_reply_to`) are in no band today and are drawn only by the leftover
     * sweep, under "Other settings". That is what TABS' `other` group
     * describes. This case asserts the thing that is true either way and that
     * can actually regress: every key TABS names is a key the schema declares,
     * and every key MAIL_SECTIONS names is one of those too. It stays green on
     * the day the integrator moves those three into named bands.
     *
     * MUTATION: add 'mail_nonesuch' to any group of MailSettings::TABS and this
     * is red. Confirmed by running it.
     */
    $declared = array_keys(MailSettings::schema());

    $inTabs = [];

    foreach (MailSettings::TABS as [$title, $description, $keys]) {
        foreach ($keys as $key) {
            $inTabs[] = $key;
        }
    }

    expect(array_values(array_diff($inTabs, $declared)))->toBe([], 'MailSettings::TABS names keys the schema does not declare');
    expect(array_values(array_diff($declared, $inTabs)))->toBe([], 'MailSettings::schema() declares keys no group of MailSettings::TABS names');

    // The console's own grouping table, read the way MailScreenDesignTest reads
    // it, may not name a key that is not in the schema either — a mistyped key
    // there silently drops the real field into the catch-all band.
    $console = (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));
    $from = strpos($console, 'const MAIL_SECTIONS=[');
    expect($from)->not->toBeFalse('the Mail screen no longer has a grouping table');

    $table = substr($console, $from, (int) strpos($console, '];', $from) - $from);
    preg_match_all("/'(mail_[a-z_]+)'/", $table, $m);

    expect(array_values(array_diff(array_unique($m[1]), $declared)))->toBe([], 'MAIL_SECTIONS names a key MailSettings::schema() does not declare');
});
