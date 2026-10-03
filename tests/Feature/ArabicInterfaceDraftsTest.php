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
 * 1,035 interface strings fell back to English on every /ar page. These pin
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
     * 35 interface strings carry a `|`. Every Arabic one of them must carry
     * exactly six segments, in the order zero, one, two, few, many, other.
     * The count is re-derived by this test rather than trusted, which is how
     * the 34 an earlier draft of this comment claimed got corrected.
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
     * SIXTY-SIX INTERFACE KEYS ARE PRINTED RAW, which is the whole reason this
     * case is not decoration.
     *
     * Grepping resources/views for `{!! __(`, `{!! trans_choice(` and
     * `{!! \App\Support\Phrase::inline(__(` finds 66 distinct keys --
     * store.cart.free_delivery_away, store.order_received.lead,
     * store.home.quiz_heading, store.checkout.gift_characters_left and the
     * rest. They are raw because each wraps a bold amount, a link or a <br>
     * that Arabic has to be able to REORDER, which InterfaceStrings' header
     * explains at length.
     *
     * The consequence for a file of 1,018 translations is that a tag in any of
     * those 66 Arabic values is injected HTML, and a tag in any of the other
     * 952 is visible angle brackets on a page. Both are defects, so the rule is
     * not "no tags" but PARITY: each Arabic value carries exactly the tags its
     * English carries, in the same order. Only one string has any --
     * store.home.quiz_heading's single <br>, which is layout and which the
     * Arabic keeps because the heading still has to break over two lines.
     *
     * Asserted over all 1,018 rather than over the 66, because a key moves
     * between the two sets whenever a template changes {{ }} to {!! !!}, and
     * this test must not have to be told.
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
     * `translated` jumps to 1,023 while nobody has read a word. Ran it.
     *
     * ── 1,018 -> 1,023, ADVANCED DELIBERATELY AND THE DIFF READ FIRST ──────
     *
     * Five keys landed after the original 1,018 were written and had no Arabic
     * at all: store.set.close (the set-contents popup's close button) and the
     * four store.checkout.wallet_* sentences that came with Apple Pay and
     * Google Pay. They are the whole of the difference -- the sibling case
     * `it translates everything except the eight paragraphs it says it left`
     * enumerates the untranslated set by NAME rather than by count, and it is
     * unchanged and still green, which is what says no sixth key crept in
     * behind these.
     *
     * `translated` is still 0 and the eight concern intros are still English.
     * Nothing a shopper sees moved: every one of the five is a DRAFT like the
     * 1,018 before them.
     *
     * ── 1,023 -> 1,026, SAME PROCEDURE, LANE FB ───────────────────────────
     *
     * Three more, and they are the flag bar's: store.flagbar.text (the line in
     * the strip above the header) and the accessible names of the two flags,
     * store.flagbar.uae and store.flagbar.korea. The sibling case that
     * enumerates the untranslated set BY NAME is untouched and green, which is
     * what says these three are the whole of the difference.
     *
     * They reach an EXISTING install through a migration of their own --
     * 2027_05_10_000100_seed_flag_bar_arabic_drafts -- because
     * 2027_04_28_000000 has already run on this shop and a migration that has
     * run does not run again. Adding keys to ArabicInterfaceDrafts alone would
     * have left them in the class and in no database anywhere, with the
     * console's Drafts figure not moving and nothing for the owner to click.
     *
     * `translated` is still 0, all three are DRAFTS, and the strip renders its
     * English on /ar until somebody approves them.
     * ── 1,023 -> 1,032, ADVANCED THE SAME WAY — Lane BN2, round 8 ──────────
     *
     * Nine keys landed with the picture slider, and they are the whole of the
     * difference: store.home.banner_slider_label, _prev, _next, _bars, _go,
     * _slide, _live, _pause and _play. Every one of them is an ACCESSIBLE NAME
     * or a live-region sentence for an icon-only control, so all nine are read
     * aloud and none of them is drawn — which is why they had to exist in
     * Arabic at all.
     *
     * Read the diff rather than the number: the sibling case
     * `it translates everything except the eight paragraphs it says it left`
     * still enumerates the untranslated set by NAME, is unchanged and is still
     * green, which is what says no tenth key crept in behind these. All nine
     * are drafts like the 1,023 before them, and `translated` is still 0.
     * ── AND THE TWO ABOVE LANDED IN THE SAME ROUND: 1,023 -> 1,035 ────────
     *
     * Lane FB and Lane BN2 each advanced this from 1,023 in their own branch
     * and neither could see the other. FB added three and wrote 1,026; BN2
     * added nine and wrote 1,032; the merged truth is 1,023 + 3 + 9 = 1,035.
     * Both notes are kept above, because each still explains its own keys and
     * neither is wrong about them — only the running total was, and only after
     * they met.
     *
     * The sibling case that enumerates the untranslated set BY NAME is the
     * check that matters here: it is untouched and green, which is what says
     * twelve keys is the WHOLE of the difference and no thirteenth crept in
     * with the merge.
     *
     * ── AND A THIRD LANE MET THEM HERE: 1,035 -> 1,037, LANE SEC ──────────
     *
     * Same story one round later. Lane SEC branched when the figure was 1,026,
     * added two and wrote 1,028; by the time it was merged FB's three and
     * BN2's nine had already met each other at 1,035. The merged truth is
     * 1,023 + 3 + 9 + 2 = 1,037. THREE lanes have now advanced this one number
     * from three different bases, which is the argument for the rule the
     * sibling case enforces: the figure is a consequence, and the enumeration
     * BY NAME is the thing that actually says what changed.
     *
     * ── LANE SEC'S OWN TWO ─────────────────────────────────────────────────
     *
     * Two more, and they are the set-stock notice's:
     * store.cart.set_took_the_last_one and store.cart.set_took_some — what a
     * shopper reads when a set in their basket has taken the last of something
     * they also added loose and the loose line has gone. Before this lane the
     * whole basket simply could not be paid for, so there was nothing to say
     * and no string to say it with.
     *
     * They reach an EXISTING install through a migration of their own --
     * 2027_05_20_000100_seed_set_stock_notice_arabic_drafts -- for the reason
     * the paragraph above gives: both earlier seeding migrations have already
     * run on this shop and a migration that has run does not run again.
     *
     * The sibling case that enumerates the untranslated set BY NAME is
     * untouched and green, which is what says these two are the whole of the
     * difference. `translated` is still 0 and both are DRAFTS.
     *
     * ── AND A FOURTH, IN THE SAME ROUND: 1,037 -> 1,056, LANE PLC ─────────
     *
     * Lane PLC branched at 1,035 too and wrote 1,054 for its nineteen; Lane SEC
     * had added two by the time either was merged. 1,023 + 3 + 9 + 2 + 19 =
     * 1,056, confirmed by running this rather than by adding up.
     *
     * FOUR lanes have now advanced this one number from four different bases in
     * two rounds, and not one of them could see the others. Stop reading the
     * figure as a fact about the shop: it is a consequence, and the sibling
     * case that enumerates the untranslated set BY NAME is the thing that says
     * what actually changed.
     *
     * ── AND THREE MORE FROM THE SAME LANE A ROUND LATER: 1,056 -> 1,059 ────
     *
     * The "Put my basket back" button: store.checkout.restore_basket,
     * restore_done and restore_gone. A shopper who abandons a payment at Tabby
     * or Tamara comes back to an empty basket, and these are the only words on
     * that page that offer it back, say it came back, and say there was nothing
     * to come back — all three read by somebody whose order has just gone
     * wrong, which is not a moment to be reading a language they do not.
     *
     * Their migration is 2027_06_03_000100_seed_restore_basket_arabic_drafts,
     * and it is a second one FROM THE SAME LANE for the reason the paragraphs
     * above give about other lanes': this lane's own 2027_05_11_000100 has
     * already run on this shop, and a migration that has run does not run
     * again. A round gets a migration, not an edit.
     *
     * ── LANE PLC'S OWN NINETEEN ────────────────────────────────────────────
     *
     * Nineteen more, and they are the Place-order overlay's: twelve under
     * store.checkout.placing_* (the freeze, the tick, and the five distinct
     * failures it can report), two under store.checkout.return_* (what a
     * shopper is told when they come back from Tabby or Tamara without having
     * paid) and five under store.order_received.* (the tick, and the honest
     * bounded "confirming your payment" state that replaces it while the shop
     * is still waiting on a webhook).
     *
     * They reach an EXISTING install through a migration of their own --
     * 2027_05_11_000100_seed_placing_overlay_arabic_drafts -- for the reason
     * the flag bar's needed one: 2027_04_28_000000 and 2027_05_10_000100 have
     * both already run on this shop, and a migration that has run does not run
     * again, so keys added to ArabicInterfaceDrafts alone would sit in the
     * class and in no database anywhere.
     *
     * The sibling case that enumerates the untranslated set BY NAME is
     * untouched and green, which is what says these nineteen are the whole of
     * the difference. `translated` is still 0, all nineteen are DRAFTS, and
     * every one of them renders its English on /ar until somebody approves it —
     * so the overlay a shopper actually sees on /ar today says "Placing your
     * order…" in English, exactly as every other string on that page does.
     */
    ArabicShop::on();

    $progress = TranslationEstimate::progress('ar');
    $ui = $progress['areas'][Translation::GROUP_UI];

    /*
     * ── 1,059 -> 1,063: LANE PI-B'S FOUR ─────────────────────────────────
     *
     * The listing pager's accessible name, its two arrows' names and the
     * "Loading more products…" status — store.shop.pages_label, page_prev,
     * page_next, loading_more — seeded by their own migration,
     * 2027_07_06_000200_seed_listing_pager_arabic_drafts, for the reason every
     * round above needed one. Read off the run, not added up.
     */
    /*
     * ── 1,063 -> 1,070: LANE PQ'S SEVEN ──────────────────────────────────
     *
     * The account-invite landing page (store.account.welcome_*, five) and the
     * invite email's button and "why you received this" line
     * (email.customer_invite.*, two), seeded by
     * 2027_07_12_000200_seed_customer_invite_arabic_drafts. Read off the run.
     *
     * ── 1,070 -> 1,072: LANE PT'S TWO ───────────────────────────────────────
     *
     * "Read more" / "Read less" under a long description in the imported
     * category title header -- store.shop.header_more, header_less -- seeded
     * by 2027_07_12_000300_seed_title_header_arabic_drafts.
     *
     * ── LANE PW'S ELEVEN (counted below, after Lane PS's two) ──────────────
     *
     * The product page's delivery box, "Authenticity Guaranteed" panel and
     * share bar -- store.product.pts_* -- seeded by
     * 2027_07_13_000100_seed_product_trust_share_arabic_drafts. Read off the
     * run.
     */
    /*
     * ── 1,072 -> 1,074: LANE PS'S TWO ───────────────────────────────────
     *
     * The "You may also like" carousel's two arrows — store.product.
     * related_prev and related_next — seeded by their own migration,
     * 2027_07_11_000250_seed_also_like_arabic_drafts. Read off the run.
     *
     * ── 1,074 -> 1,085: LANE PW'S ELEVEN, on top of PS's two at merge ─────
     *
     * ── 1,085 -> 1,088: LANE QA'S THREE ─────────────────────────────────
     *
     * The share button's name (store.product.share_open) and the two
     * Tabby & Tamara cards' default wording (store.product.paylater_tabby,
     * paylater_tamara), seeded by
     * 2027_07_15_000100_seed_product_mobile_sections_arabic_drafts. Read off
     * the run.
     *
     * ── 1,088 -> 1,094: LANE QB'S SIX ─────────────────────────────────────
     *
     * The share sheet's heading, the share icon's name and four tile labels
     * (store.product.pts_sheet_heading, pts_share_btn, pts_tile_messages,
     * pts_tile_email, pts_tile_copy, pts_tile_more), seeded by
     * 2027_07_15_000600_seed_product_share_sheet_arabic_drafts. Read off the
     * run. Email is "البريد" rather than "البريد الإلكتروني", which the
     * three e-mail FIELDS already use — the collision test below.
     *
     * ── 1,094 -> 1,107: LANE RB'S THIRTEEN ──────────────────────────────
     *
     * "Buy these together": its heading, the button's three counted
     * wordings, the total's label, "Sold out",
     * and the seven sentences the one-request add answers with
     * (store.buy_together.*), seeded by
     * 2027_07_16_000700_seed_buy_together_arabic_drafts. Read off the run.
     *
     * ── 1,107 -> 1,111: LANE RE'S FOUR ─────────────────────────────────────
     *
     * The bundle discount: "You're saving" (the green pill under the total),
     * the tag on a bundled cart line, the "Buy-together discount" row on the
     * cart and checkout, and the same row on the order email, invoice and
     * order pages (store.buy_together.saving, bundle_badge, bundle_row,
     * email.totals.bundle), seeded by
     * 2027_07_19_000200_seed_buy_together_bundle_arabic_drafts. Read off the run.
     *
     * ── 1,111 -> 1,115: THE LINK PREVIEW CARD'S FOUR (2.60.365) ────────────
     *
     * The card's three points and the message above a shared link
     * (store.product.pts_card_p1..p3, pts_card_msg), seeded by
     * 2027_07_23_000200_seed_share_card_arabic_drafts. Read off the run.
     */
    expect($ui['drafts'])->toBe(1115, 'the shipped Arabic is not showing as drafts to review')
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

    expect($response->json('published'))->toBeGreaterThanOrEqual(1023);

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

/* ══════════════════ 5. the three groups the console could not open ══════════════════ */

it('opens product tabs, video sections and clips on Translation -> Strings', function () {
    /*
     * THE OTHER HALF OF THE CONTENT FIX, AND THE ONE THE OWNER ACTUALLY TOUCHES.
     *
     * TranslationsApiController::contentClassFor() resolves a group name by
     * walking TranslationEstimate::CONTENT. ProductTab, UgcSection and UgcVideo
     * were on no such list, so `?group=product_tabs` resolved to null and the
     * screen could not list them AT ALL -- there was no way to type the Arabic
     * for a product tab, a video section heading or a clip's title anywhere in
     * the admin except the two editors that happen to draw an inline box.
     *
     * UgcSection has no inline box, so before this it had NO path at all: two
     * columns the storefront reads through $section->t() and nowhere to fill
     * them in. This is the assertion that it now has one.
     *
     * MUTATION: drop any of the three from TranslationEstimate::CONTENT and the
     * matching group 404s here. Ran it for ugc_sections.
     */
    ArabicShop::on();

    $admin = \App\Models\AdminUser::create([
        'name' => 'Lane AR owner',
        'email' => 'lane-ar-'.uniqid().'@example.com',
        'password' => bcrypt('secret'),
        'role' => 'owner',
    ]);

    $section = \App\Models\UgcSection::create([
        'handle' => 'ar-'.uniqid(),
        'title' => 'Shop the look',
        'heading' => 'Shop the look',
        'subheading' => 'Real routines from our community.',
        'status' => 'publish',
    ]);

    foreach (['product_tabs', 'ugc_sections', 'ugc_videos'] as $group) {
        $this->actingAs($admin, 'admin')
            ->getJson('/admin-api/translations?locale=ar&group='.$group)
            ->assertOk()
            ->assertJsonPath('group', $group);
    }

    // And a heading typed there reaches the storefront reader, which is the
    // whole point -- UgcRail::… calls $section->t('heading').
    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/translations', [
            'locale' => 'ar', 'group' => 'ugc_sections', 'item_id' => $section->id,
            'field' => 'heading', 'value' => 'تسوقي الإطلالة',
        ])->assertOk();

    TranslationStore::flush();
    app()->setLocale('ar');

    expect($section->fresh()->t('heading'))->toBe('تسوقي الإطلالة');
});

/* ══════════════════ 6. the fragments that get slotted into other strings ══════════════════ */

it('composes the newsletter fallback into a sentence rather than a repeated word', function () {
    /*
     * A CLASS OF ERROR NO PLACEHOLDER CHECK CAN SEE, AND THIS LANE SHIPPED ONE.
     *
     * `email.newsletter.our` is not a word the reader ever sees on its own. It
     * is what the templates pass as `:store` when the shop has no name set:
     *
     *     __('email.newsletter.somebody_asked',
     *        ['store' => $brand['storeName'] ?? __('email.newsletter.our')])
     *
     * English composes "asked for OUR emails to be sent". The Arabic sentence is
     * built the other way round -- "أن تُرسل رسائل :store" -- so :store wants a
     * NAME there, and with none it wants "منّا". Translated as the English word
     * points ("رسائل") it composed to "رسائل رسائل": the same word twice, in a
     * transactional email, on the one path a shop that has not set its name
     * takes.
     *
     * Every placeholder is present in both strings, so the placeholder test is
     * green on the broken version. Only rendering it catches this.
     *
     * MUTATION: set 'email.newsletter.our' back to 'رسائل' and this is red on
     * the doubled word. Ran it.
     */
    $strings = \App\Services\Translation\ArabicInterfaceDrafts::all();

    $withFallback = str_replace(
        ':store',
        $strings['email.newsletter.our'],
        $strings['email.newsletter.somebody_asked']
    );

    $withName = str_replace(':store', 'K-Beauty Bliss', $strings['email.newsletter.somebody_asked']);

    // No word repeated back-to-back once the fallback is slotted in.
    expect((bool) preg_match('/(\S+)\s+\1(\s|$)/u', $withFallback))
        ->toBeFalse('the newsletter fallback composes to a repeated word: '.$withFallback);

    // And the same sentence still reads with a real shop name in it.
    expect(str_contains($withName, 'K-Beauty Bliss'))
        ->toBeTrue('the shop name no longer appears in the sentence: '.$withName)
        ->and((bool) preg_match('/(\S+)\s+\1(\s|$)/u', $withName))
        ->toBeFalse($withName);
});

/* ══════════════════ 7. the door the CONTENT fix opened, and closed ══════════════════ */

it('cleans an Arabic product-tab body typed on the standalone screen', function () {
    /*
     * STORED XSS, OPENED BY THIS LANE'S OWN FIX AND CLOSED IN THE SAME ROUND.
     *
     * partials/product-tabs.blade.php prints a tab body with {!! !!} twice --
     * the desktop panel and the mobile accordion. TranslationStore::put()'s
     * sanitiser was scoped to ONE group, 'products', so `product_tabs.body` was
     * never cleaned by it.
     *
     * That was inert only because of a second defect: TranslationsApiController
     * resolves a group by walking TranslationEstimate::CONTENT, ProductTab was
     * not on it, and `?group=product_tabs` resolved to null. A dead filter
     * hiding a live hole -- the exact shape CLAUDE.md records under "a broken
     * filter can hide a second bug", where fixing Api\ProductController's
     * status filter turned it into a 500.
     *
     * Putting ProductTab on CONTENT so the owner could translate a tab is what
     * made the filter live. MEASURED BEFORE THE FIX: this same request answered
     * 200 and stored `<script>alert(1)</script>` verbatim.
     *
     * MUTATION: drop the 'product_tabs' row from
     * TranslationStore::RICH_BY_GROUP and this is red with the script tag in
     * the stored value. Ran it.
     */
    ArabicShop::on();

    $admin = \App\Models\AdminUser::create([
        'name' => 'Lane AR owner',
        'email' => 'lane-ar-'.uniqid().'@example.com',
        'password' => bcrypt('secret'),
        'role' => 'owner',
    ]);

    $product = \App\Models\Product::create([
        'name' => 'Tabbed', 'slug' => 'ar-tab-'.uniqid(), 'price' => 100,
        'status' => 'publish', 'is_visible' => true,
    ]);

    $tab = \App\Models\ProductTab::create([
        'product_id' => $product->id, 'source_key' => 'ar-'.uniqid(),
        'title' => 'How we chose it', 'body' => '<p>ok</p>', 'position' => 1, 'is_enabled' => true,
    ]);

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/translations', [
            'locale' => 'ar', 'group' => 'product_tabs', 'item_id' => $tab->id, 'field' => 'body',
            'value' => '<p>مرحبا</p><script>alert(1)</script><img src=x onerror=alert(2)>',
        ])->assertOk();

    $stored = (string) \App\Models\Translation::query()
        ->where('group', 'product_tabs')->where('field', 'body')
        ->where('item_id', $tab->id)->value('value');

    expect(str_contains($stored, '<script'))
        ->toBeFalse('a script tag reached a column the product page prints raw: '.$stored)
        ->and(str_contains($stored, 'onerror'))
        ->toBeFalse('an inline event handler survived: '.$stored)
        // and the legitimate Arabic is still there, so the fix is a sanitiser
        // and not a rejection.
        ->and(str_contains($stored, 'مرحبا'))
        ->toBeTrue('the sanitiser ate the translation as well: '.$stored);
});

it('still leaves the pages and posts gap exactly where it was found', function () {
    /*
     * NOT WIDENED, ON PURPOSE. `pages.content` and `posts.body` are printed raw
     * too and are still not on RICH_BY_GROUP. Their ENGLISH is stored as
     * trusted operator HTML by a decision older than this lane, so cleaning
     * only the Arabic would render one document differently in its two
     * languages -- ContentPageEditorTest names that gap and asserts its shape.
     *
     * ProductTab had no such asymmetry, which is why it could be closed here
     * and these cannot: ProductTabsApiController already runs RichText::clean()
     * over the English body.
     *
     * This exists so that widening the map to pages or posts is a deliberate
     * act with a test to delete, rather than a quiet afternoon's tidy-up.
     */
    expect(array_keys(TranslationStore::RICH_BY_GROUP))
        ->toBe(['products', 'product_tabs'],
            'RICH_BY_GROUP has grown or shrunk -- if pages/posts are now sanitised, '
            .'ContentPageEditorTest has a case to delete and their English needs the same treatment');
});

/* ══════════════════ 8. one Arabic word doing two different jobs ══════════════════ */

it('never labels two controls on one screen with the same Arabic', function () {
    /*
     * A CLASS OF ERROR THE OTHER CASES CANNOT SEE, AND THIS LANE SHIPPED TWO.
     *
     * Arabic collapses distinctions English makes. Translating 1,018 strings a
     * few dozen at a time, it is easy to reach for the obvious word twice --
     * and if the two keys happen to sit on the SAME screen, the shopper gets
     * two identically-labelled controls.
     *
     * The two this caught, both found by running it:
     *
     *   store.reviews.field_rating  "Your rating"  and
     *   store.reviews.field_review  "Your review"  were both تقييمك --
     *   the star picker and the textarea directly under it, on one form.
     *
     *   store.checkout.heading      "Checkout"     and
     *   store.checkout.place_order  "Place order"  were both إتمام الطلب --
     *   the page's own <h1> and its submit button, inches apart.
     *
     * The rest of the collapses are legitimate and are listed below rather than
     * suppressed by a rule, because the difference between "Best sellers" and
     * "Best selling" genuinely does not survive into Arabic and pretending
     * otherwise would invent a distinction the language does not make. Each
     * group is either one screen where the repetition reads naturally, or two
     * screens a shopper never sees at once.
     *
     * MUTATION: set store.reviews.field_review back to تقييمك and this is red
     * naming the pair. Ran it.
     */
    $english = InterfaceStrings::flat();

    $byArabic = [];

    foreach (ArabicInterfaceDrafts::all() as $key => $arabic) {
        $byArabic[$arabic][] = $key;
    }

    $collapsed = [];

    foreach ($byArabic as $keys) {
        if (count($keys) < 2) {
            continue;
        }

        // Keys whose ENGLISH is the same word are not a collapse at all -- one
        // Arabic for one English is the point of keying by component.
        $englishes = array_unique(array_map(
            static fn (string $k): string => mb_strtolower($english[$k]),
            $keys
        ));

        if (count($englishes) > 1) {
            sort($keys);
            $collapsed[] = $keys;
        }
    }

    usort($collapsed, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

    /*
     * The collapses that are correct. Named in full, so adding a new one is a
     * decision somebody writes down rather than a number that creeps.
     */
    $allowed = [
        ['email.invoice.col_unit', 'invoice.invoice.col_unit_price'],
        ['email.invoice.deliver_to', 'store.order_received.address_heading'],
        ['email.items.col_qty', 'invoice.delivery_note.col_quantity'],
        ['store.account.dashboard_title', 'store.account_panel.default_name', 'store.account_panel.link_account', 'store.footer.account_heading', 'store.footer.link_my_account', 'store.header.account_label', 'store.mobile_menu.link_account'],
        ['store.account.orders_empty', 'store.collection.empty'],
        ['store.account.register_title', 'store.account_panel.register_button', 'store.account_panel.tab_register', 'store.mobile_menu.link_register'],
        ['store.account_panel.field_email', 'store.checkout.field_email', 'store.quiz.js_label_email', 'store.reviews.field_email'],
        ['store.addresses.add_address', 'store.addresses.form_heading_add'],
        ['store.brands.card_count', 'store.cart.item_count', 'store.home.category_product_count', 'store.set.count_note'],
        ['store.checkout.field_address', 'store.reviews.field_title'],
        ['store.checkout.secure_badge', 'store.home.trust_payments_title'],
        ['store.collection.title_best_sellers', 'store.footer.link_best_sellers', 'store.home.bestsellers_grid_label', 'store.home.bestsellers_heading', 'store.product_card.label_bestseller', 'store.shop.sort_popularity'],
        ['store.home.flash_link', 'store.product_grid.view_all'],
        ['store.instagram.follow', 'store.quiz.js_continue'],
        ['store.order_received.show_more', 'store.set.show_all'],
        ['store.order_status.on-hold', 'store.order_status.onhold'],
        ['store.orders.pager_newer', 'store.shop.sort_date'],
    ];

    expect($collapsed)->toBe($allowed,
        "one Arabic value is covering two different English strings that is not on the allowed list -- "
        ."if it is legitimate, add it there with a reason; if the two sit on one screen, give them different words");
});
