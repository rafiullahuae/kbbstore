<?php

declare(strict_types=1);

use App\Models\Translation;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationEstimate;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

/**
 * THE ARABIC THIS LANE WROTE, AND THE THREE WAYS IT COULD HAVE BEEN WRONG.
 *
 * Before this lane the repository shipped no Arabic at all, so every one of the
 * 1,026 interface strings fell back to English on every /ar page. These pin
 * what was done about it and, more importantly, what was NOT: the shop does not
 * move until the owner presses a button.
 */

/* ══════════════════ 1. the shop does not move ══════════════════ */

it('serves not one word of this Arabic until somebody approves it', function () {
    /*
     * THE WHOLE SAFETY ARGUMENT FOR THIS LANE, IN ONE ASSERTION.
     *
     * 1,018 rows are written by the migration and every one of them is a DRAFT.
     * TranslationStore::uiMap() filters on `published`, so a draft cannot reach
     * a page — which is what lets a thousand unreviewed strings ship without
     * changing a shop that is live.
     *
     * MUTATION: change STATUS_DRAFT to STATUS_PUBLISHED in
     * 2027_04_28_000000_seed_arabic_interface_drafts and this goes red on the
     * first key it checks. Ran it -- the cart heading came back as حقيبتك.
     */
    ArabicShop::on();
    app()->setLocale('ar');

    foreach (['store.cart.heading', 'store.product_card.add_to_cart', 'store.set.page_heading'] as $key) {
        expect(__($key))->toBe(
            InterfaceStrings::english($key),
            $key.' is being served from a draft row -- a shopper can see unapproved Arabic'
        );
    }
});

it('leaves a string the owner has already typed exactly as he typed it', function () {
    /*
     * The migration is guarded on the row not existing. His work outranks this
     * file in every case and it has to: he reads Arabic and this file was
     * written by a model.
     *
     * The migration has already run by the time a test boots, so this re-runs
     * its up() against a row written first -- which is also the re-application
     * case, since an update package can be applied twice.
     *
     * MUTATION: change the `isset($existing[$field])` guard to `false` and this
     * is red. Ran it, and what came back is worth writing down: not a changed
     * string but a UniqueConstraintViolationException. The table is unique on
     * (locale, group, item_id, field), so the guard is not only what protects
     * his typing -- it is the only reason the migration can be run a second
     * time at all, which an update package applied twice will do.
     */
    ArabicShop::on();

    $key = 'store.cart.heading';

    DB::table('translations')
        ->where('locale', 'ar')->where('group', Translation::GROUP_UI)
        ->where('item_id', 0)->where('field', $key)->delete();

    ArabicShop::string($key, 'سلّتي');

    require_once __DIR__.'/../../database/migrations/2027_04_28_000000_seed_arabic_interface_drafts.php';
    $migration = require __DIR__.'/../../database/migrations/2027_04_28_000000_seed_arabic_interface_drafts.php';
    $migration->up();

    TranslationStore::flush();
    app()->setLocale('ar');

    expect(__($key))->toBe('سلّتي', "the owner's own translation was overwritten by the shipped draft");

    expect(DB::table('translations')
        ->where('locale', 'ar')->where('group', Translation::GROUP_UI)
        ->where('item_id', 0)->where('field', $key)->count())
        ->toBe(1, 'a second row was written for a key that already had one');
});

/* ══════════════════ 2. six plural forms, because Arabic has six ══════════════════ */

it('gives every counted string six forms, or Arabic renders the zero form', function () {
    /*
     * THE TRAP, AND IT IS SILENT.
     *
     * Illuminate\Translation\MessageSelector::getPluralIndex returns 0..5 for
     * 'ar'. choose() then does:
     *
     *     if (count($segments) === 1 || ! isset($segments[$pluralIndex])) {
     *         return $segments[0];
     *     }
     *
     * $segments[0] is the ZERO form. So an Arabic plural written with English's
     * TWO forms does not fall back to the plural -- it renders "no products"
     * for five products, on the shop, with nothing logged and nothing thrown.
     *
     * 34 interface strings carry a `|`. Every Arabic one of them must carry
     * exactly six segments, in the order zero, one, two, few, many, other.
     *
     * MUTATION: cut any six-form value below down to two segments and this is
     * red naming the key. Ran it on store.cart.item_count.
     */
    $english = InterfaceStrings::flat();
    $wrong = [];

    foreach (ArabicInterfaceDrafts::all() as $key => $arabic) {
        $isPlural = str_contains($english[$key] ?? '', '|');
        $forms = substr_count($arabic, '|') + 1;

        if ($isPlural && $forms !== 6) {
            $wrong[] = $key.' has '.$forms.' forms, Arabic needs 6';
        }

        // And the other direction: a `|` in a string the English does not
        // pluralise is printed literally, pipes and all. store.quiz.js_step_count
        // is the one that was written that way and caught here.
        if (! $isPlural && $forms !== 1) {
            $wrong[] = $key.' has a | but its English does not -- it will print literally';
        }
    }

    expect($wrong)->toBe([], implode('; ', $wrong));
});

it('renders the right Arabic form for one, three and eleven', function () {
    /*
     * The arity check above is structural. This is the behaviour it protects,
     * driven through the framework's own trans_choice() so the plural rule
     * being exercised is Laravel's and not this test's idea of it.
     *
     * 1 -> index 1, 3 -> index 3, 11 -> index 4. Three different segments, and
     * with a two-form translation all three would come back as the zero form.
     */
    ArabicShop::on();

    $key = 'store.cart.item_count';

    DB::table('translations')
        ->where('locale', 'ar')->where('group', Translation::GROUP_UI)
        ->where('item_id', 0)->where('field', $key)
        ->update(['status' => Translation::STATUS_PUBLISHED]);

    TranslationStore::flush();
    app()->setLocale('ar');

    $one = trans_choice($key, 1, ['count' => 1]);
    $three = trans_choice($key, 3, ['count' => 3]);
    $eleven = trans_choice($key, 11, ['count' => 11]);

    expect($one)->not->toBe($three, 'one and three chose the same Arabic form')
        ->and($three)->not->toBe($eleven, 'three and eleven chose the same Arabic form')
        ->and(str_contains($one, 'واحد'))->toBeTrue('the singular form was not chosen for 1, got: '.$one)
        ->and(str_contains($three, '3'))->toBeTrue('the count was dropped from the few form, got: '.$three)
        ->and(str_contains($eleven, '11'))->toBeTrue('the count was dropped from the many form, got: '.$eleven);
});

/* ══════════════════ 3. nothing invented, nothing dropped ══════════════════ */

it('keeps every placeholder the English carries and invents none', function () {
    /*
     * A dropped :placeholder is a number, a name or a price that vanishes off
     * an Arabic page -- "Free delivery over" with no amount. An invented one is
     * printed raw, colon and all, because Laravel only replaces what it is
     * given.
     *
     * MUTATION: delete `:amount` from store.delivery.free_over's Arabic and
     * this is red naming the key. Ran it.
     */
    $english = InterfaceStrings::flat();
    $wrong = [];

    foreach (ArabicInterfaceDrafts::all() as $key => $arabic) {
        preg_match_all('/:([a-z_]+)/', $english[$key] ?? '', $me);
        preg_match_all('/:([a-z_]+)/', $arabic, $ma);

        $missing = array_diff(array_unique($me[1]), array_unique($ma[1]));
        $invented = array_diff(array_unique($ma[1]), array_unique($me[1]));

        foreach ($missing as $p) {
            $wrong[] = $key.' drops :'.$p;
        }

        foreach ($invented as $p) {
            $wrong[] = $key.' invents :'.$p;
        }
    }

    expect($wrong)->toBe([], implode('; ', $wrong));
});

it('never names a key that does not exist', function () {
    /*
     * A key with a typo in it is a row nothing ever reads and a string that
     * stays English for ever, with no error anywhere. It is also the shape a
     * rename leaves behind: InterfaceStrings moves a key, this file does not,
     * and the Arabic silently stops being used.
     */
    $unknown = array_diff_key(ArabicInterfaceDrafts::all(), InterfaceStrings::flat());

    expect($unknown)->toBe([], 'keys that are not in InterfaceStrings: '.implode(', ', array_keys($unknown)));
});

it('translates everything except the eight paragraphs it says it left', function () {
    /*
     * The coverage figure, pinned so that a key added by a later lane shows up
     * here as one more untranslated string rather than disappearing into a
     * total nobody reads.
     *
     * The eight exceptions are the concern pages' intros, 342 to 802 characters
     * of indexed landing-page copy each. The class doc argues why a machine
     * must not write those; this asserts that those are the only ones.
     */
    $missing = array_keys(array_diff_key(InterfaceStrings::flat(), ArabicInterfaceDrafts::all()));

    sort($missing);

    expect($missing)->toBe([
        'store.concern.intro_acne',
        'store.concern.intro_ageing',
        'store.concern.intro_dark_spots',
        'store.concern.intro_dullness',
        'store.concern.intro_hydration',
        'store.concern.intro_pores',
        'store.concern.intro_sensitivity',
        'store.concern.intro_sun',
    ], 'the untranslated set has changed: '.implode(', ', $missing));
});

it('writes no markup into a string whose English carries none', function () {
    /*
     * Three interface strings are printed with {!! !!} and the rest are
     * escaped. A tag that appears in the Arabic and not the English is either
     * printed as visible angle brackets or, on one of those three, injected --
     * so the counts have to match rather than merely "be safe".
     */
    $english = InterfaceStrings::flat();
    $wrong = [];

    foreach (ArabicInterfaceDrafts::all() as $key => $arabic) {
        preg_match_all('/<[a-z\/][^>]*>/i', $english[$key] ?? '', $te);
        preg_match_all('/<[a-z\/][^>]*>/i', $arabic, $ta);

        if ($te[0] !== $ta[0]) {
            $wrong[] = $key.' English has ['.implode(' ', $te[0]).'] Arabic has ['.implode(' ', $ta[0]).']';
        }
    }

    expect($wrong)->toBe([], implode('; ', $wrong));
});

it('writes actual Arabic, not English left in an Arabic-shaped slot', function () {
    /*
     * A value copied across and never translated is the failure this file is
     * least likely to notice about itself, because it looks like work. Every
     * value must either contain Arabic script or be one of the named reasons
     * not to -- a brand, an abbreviation the shop prints in Latin, or a string
     * that is nothing but placeholders and punctuation.
     */
    $identical = [];

    foreach (ArabicInterfaceDrafts::all() as $key => $arabic) {
        if (preg_match('/\p{Arabic}/u', $arabic) === 1) {
            continue;
        }

        $identical[] = $key.' => '.$arabic;
    }

    // Everything that legitimately carries no Arabic letter at all, and why
    // each one is allowed to: the shop's own name and Latin-script brands, the
    // AED price bands (docs/BILINGUAL-PLAN.md keeps prices exactly as they
    // are), the abbreviations UAE retail prints in Latin (COD, SKU, the tax
    // TRN), the format hints a shopper is about to type over, and the language
    // switcher naming English in English.
    //
    // It is a pinned LIST and not a count, so adding a twenty-third means
    // writing down which one and why rather than moving a number.
    expect($identical)->toBe([
        'store.footer.pay_cod => COD',
        'store.language.english => English',
        'store.shop.page_title => :title · K-Beauty Bliss',
        'store.shop.price_54_150 => AED 54 – 150',
        'store.shop.price_150_300 => AED 150 – 300',
        'store.shop.price_300p => AED 300+',
        'store.collection.page_title => :title · K-Beauty Bliss',
        'store.product_card.count_thousands => :countk',
        'store.product_card.price_range => :low – :high',
        'store.page.page_title => :title · K-Beauty Bliss',
        'store.home.count_thousands_plus => :countk+',
        'store.home.trust_support_text => WhatsApp :phone',
        'store.reviews.field_email_placeholder => you@email.com',
        'store.notify_me.email_placeholder => you@example.com',
        'store.cart_reminder.email_placeholder => you@example.com',
        'store.checkout.field_email_placeholder => you@email.com',
        'store.checkout.field_phone_placeholder => +971 5x xxx xxxx',
        'store.quiz.brand_heading => K-Beauty Bliss :suffix',
        'email.common.sign_off => — K Beauty Bliss',
        'email.items.sku => SKU :sku',
        'email.invoice.trn => TRN :trn',
        'invoice.packing.col_sku => SKU',
    ], "values with no Arabic in them: \n".implode("\n", $identical));
});

/* ══════════════════ 4. the console tells the owner the truth about them ══════════════════ */

it('counts them as drafts awaiting review and not as work already done', function () {
    /*
     * THE NUMBER THIS LANE MUST NOT BREAK.
     *
     * arabic-boxes.blade.php: "BLANK MEANS NOT TRANSLATED YET ... That is the
     * only reason the progress figure is countable rather than claimed."
     *
     * Publishing these outright would have told the owner, on his own progress
     * screen, that a human had translated the interface. As drafts the figure
     * stays honest: `translated` is untouched and `drafts` carries them.
     *
     * MUTATION: publish the seeded rows (STATUS_PUBLISHED in the migration) and
     * `translated` jumps to 1,018 while nobody has read a word. Ran it.
     */
    ArabicShop::on();

    $progress = TranslationEstimate::progress('ar');
    $ui = $progress['areas'][Translation::GROUP_UI];

    expect($ui['drafts'])->toBe(1018, 'the shipped Arabic is not showing as drafts to review')
        ->and($ui['translated'])->toBe(0, 'unreviewed Arabic is being counted as translated work')
        ->and($ui['total'])->toBe(count(InterfaceStrings::flat()));
});

it('can be approved in one press, the way the console already does it', function () {
    /*
     * The delivery only works if approving 1,018 drafts is one action. It is:
     * TranslationsApiController::publish() takes a locale and no field, and the
     * screen's "Publish all" button posts exactly that.
     *
     * This drives the endpoint rather than the model, because the thing being
     * checked is that the shipped rows are reachable BY IT -- a row written
     * with the wrong group or item_id would be invisible to the button and
     * perfectly fine everywhere else.
     */
    ArabicShop::on();

    // Built here rather than through TranslationConsoleTest's fcAdmin(): a
    // global declared in one Pest file only exists for another when the WHOLE
    // suite is loaded, so depending on it fatals the moment somebody runs this
    // file on its own. Tests\Support\ArabicShop's header records the same trap.
    $admin = \App\Models\AdminUser::create([
        'name' => 'Lane AR owner',
        'email' => 'lane-ar-'.uniqid().'@example.com',
        'password' => bcrypt('secret'),
        'role' => 'owner',
    ]);

    $response = $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/translations/publish', ['locale' => 'ar']);

    $response->assertOk();

    expect($response->json('published'))->toBeGreaterThanOrEqual(1018);

    TranslationStore::flush();
    app()->setLocale('ar');

    expect(__('store.cart.heading'))->toBe('حقيبتك', 'approving the drafts did not put them on the shop');
});

it('records the English it was translated from, so a later edit marks it stale', function () {
    /*
     * source_hash is sha1 of the English the row was made from. Without it the
     * console cannot tell a current translation from one whose English was
     * rewritten underneath it, and 1,018 permanently un-checkable rows is a
     * worse state than none.
     */
    $row = DB::table('translations')
        ->where('locale', 'ar')->where('group', Translation::GROUP_UI)
        ->where('item_id', 0)->where('field', 'store.cart.heading')->first();

    expect($row)->not->toBeNull()
        ->and($row->source, 'a model wrote these, so they are a machine\'s')->toBe(Translation::SOURCE_MACHINE)
        ->and($row->source_hash)->toBe(sha1(InterfaceStrings::english('store.cart.heading')))
        ->and($row->reviewed_at)->toBeNull('nobody has reviewed these yet and the column must say so');
});
