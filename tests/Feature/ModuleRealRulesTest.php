<?php

/**
 * The rules that are NOT policy points, pinned where they now live — Lane M2.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────────
 *
 * Lane M moved ten module screens onto ModuleSchema and deliberately did not
 * take CartPage or CheckoutPage, naming the reason in
 * docs/M-PHASE3-SETTINGS-SCHEMA.md §1:
 *
 *   "CheckoutPage's refuses a digit in `rating_text` and CartPage's money
 *    clamps at zero rather than refusing, which are real rules, not policy
 *    points, so each wants its own round."
 *
 * This round took both. The danger in doing so is not that the migration fails
 * loudly — it is that one of those two constraints is quietly flattened into
 * the nearest policy axis, the suite stays green, and a control that used to
 * refuse something starts accepting it. A rule lost in a migration is worse
 * than a module left unmigrated, because nothing says it happened.
 *
 * `ModuleSchemaEquivalenceTest` cannot catch either one on its own. Its corpus
 * has no digit-bearing string in it at all (`'abc'`, `''`, `'  sp  '`, 300 x's,
 * null, `'<b>x</b>'`), so the whole of CheckoutPage's rule is invisible to it;
 * and the two money inputs it does carry would pass a `max` that happened to
 * agree. So the rules are asserted here, by value, from the outside.
 *
 * ── MUTATIONS ACTUALLY RUN, AND WHAT EACH ONE PRINTED ──────────────────────
 *
 * 1. `CheckoutPage::overrides()` emptied to `[]`, so `rating_text` falls back
 *    to the shared text arm under this screen's POLICY. Three tests go red:
 *      it refuses a rating the owner typed into the wording template
 *      it refuses an over-length template rather than truncating it
 *      ModuleSchemaEquivalenceTest > it answers every recorded cast exactly…
 *        checkout_page|rating_text|text|"xxx…(300)"|'{rating} from {count}
 *        reviews'   ==>   …|'xxx…(120)'
 *    — the recorded fixture catching the truncate-instead-of-refuse change on
 *    its own, which is the one this migration was most at risk of making.
 *
 * 2. `CartPage::overrides()`'s two money entries removed:
 *      it clamps a cart-page money field at zero instead of refusing it
 *        Failed asserting that null is identical to 0.
 *      ModuleSchemaEquivalenceTest > it answers every recorded cast exactly…
 *
 * 3. `ModuleSchema::rule()`'s OPTION_TYPES/colour guard deleted:
 *      it refuses to let a module-local rule replace a colour or a select
 *
 * 4. `MobileMenu::TABS`' 'open' group reordered (rule_colour moved up one):
 *      ModuleScreenPayloadTest > it sends every module screen the payload…
 *        mobile-menu.groups (outside tabs) changed
 *    The endpoint test in THIS file stays green under that mutation, correctly
 *    and worth saying: it compares the endpoint against the constant, and under
 *    this mutation both moved together. It pins that the controller reads the
 *    constant; the recorded payload is what pins the constant itself.
 *
 * 5. A `m2_orphan` bool added to `MobileMenu::SCHEMA` and to no group — the
 *    whole point of Task 2, and it now fails where before it could not:
 *      ModuleFrameworkGuardTest > it gives every module setting a control…
 *        mobile_menu stores these with no control to write them: m2_orphan
 *
 * 6. The bespoke-control exemption in ModuleFrameworkGuardTest pointed at
 *    cart-panel-screen.blade.php instead:
 *        cart_page.rec_ids names a console file that does not exist
 */

use App\Models\AdminUser;
use App\Services\CartPage;
use App\Services\CheckoutPage;
use App\Services\MobileMenu;
use App\Services\ModuleSchema;
use Illuminate\Support\Facades\Hash;

/** The private cast, driven directly. */
function m2Cast(string $class, string $key, mixed $value): mixed
{
    $method = (new ReflectionClass($class))->getMethod('cast');
    $method->setAccessible(true);

    return $method->invoke(app($class), $key, $value);
}

/* ─────────────────── CheckoutPage · a template with no digit ─────────────── */

it('refuses a rating the owner typed into the wording template', function () {
    /*
     * THE DEFECT THIS FORBIDS. `rating_text` sits beside the pay button and its
     * two tokens are replaced with figures read from the reviews table. A digit
     * anywhere else in it is a rating the owner invented — which is exactly
     * what `reassure_rating_text` was removed from this shop for. The shipped
     * wording stands instead; the typed figure is never stored, so it can never
     * be printed at the moment of payment.
     *
     * Refused, not stripped: silently deleting the "4.8" somebody typed leaves
     * them reading a line they did not write and did not agree to.
     */
    $shipped = CheckoutPage::SCHEMA['rating_text'][2];

    expect(m2Cast(CheckoutPage::class, 'rating_text', '4.8 out of 5 from {count} shoppers'))->toBe($shipped);
    expect(m2Cast(CheckoutPage::class, 'rating_text', 'Loved by 12000 shoppers'))->toBe($shipped);
    // A digit INSIDE the two tokens costs nothing — they are not the owner's.
    expect(m2Cast(CheckoutPage::class, 'rating_text', 'Rated {rating} out of five by {count} shoppers'))
        ->toBe('Rated {rating} out of five by {count} shoppers');
});

it('refuses an over-length template rather than truncating it', function () {
    /*
     * THE HALF A `max` POLICY WOULD HAVE BROKEN. The shared text arm TRUNCATES
     * at the module's cap; this screen REFUSES back to the shipped wording,
     * because half a sentence printed beside the pay button is its own defect.
     * Flattening this into `max` would have looked like a faithful migration
     * and changed what the checkout says.
     */
    $shipped = CheckoutPage::SCHEMA['rating_text'][2];
    $long = str_repeat('a', 121);

    expect(m2Cast(CheckoutPage::class, 'rating_text', $long))->toBe($shipped);
    // 120 exactly is still accepted — the boundary did not move either.
    expect(m2Cast(CheckoutPage::class, 'rating_text', str_repeat('a', 120)))->toBe(str_repeat('a', 120));
});

it('keeps the wording an owner is allowed to type', function () {
    // Trimmed, and an emptied box restores the shipped line rather than
    // printing nothing where the reassurance used to be.
    $shipped = CheckoutPage::SCHEMA['rating_text'][2];

    expect(m2Cast(CheckoutPage::class, 'rating_text', '  Loved by shoppers  '))->toBe('Loved by shoppers');
    expect(m2Cast(CheckoutPage::class, 'rating_text', ''))->toBe($shipped);
    expect(m2Cast(CheckoutPage::class, 'rating_text', null))->toBe($shipped);
});

/* ──────────────────────── CartPage · money floors at zero ────────────────── */

it('clamps a cart-page money field at zero instead of refusing it', function () {
    /*
     * THE DEFECT THIS FORBIDS, and it is a regression rather than a leak.
     * ModuleSchema's shared `money` arm REFUSES anything that is not a plain
     * run of digits — right for PayShipRules' Cash-on-delivery bounds, where
     * "12.50" stored as 12 fils is a hundredfold error that reads back as a
     * plausible number. This screen has never done that: both figures are
     * quoted-not-charged amounts in a plain number box and have always answered
     * max(0, (int) $value). Migrating it onto the shared arm would turn a save
     * that worked into a rejected field on a live shop.
     */
    foreach (['sum_express', 'sum_service'] as $key) {
        expect(m2Cast(CartPage::class, $key, -5))->toBe(0, "{$key} stopped flooring at zero");
        expect(m2Cast(CartPage::class, $key, '12.50'))->toBe(12, "{$key} started refusing a typed decimal");
        expect(m2Cast(CartPage::class, $key, '2500'))->toBe(2500);
        expect(m2Cast(CartPage::class, $key, null))->toBe(0);
        // Never null: this screen's save() writes whatever cast() answers, so a
        // null here would store an empty value rather than report a refusal.
        expect(m2Cast(CartPage::class, $key, 'abc'))->not->toBeNull("{$key} started refusing");
    }
});

it('caps the recommended rail at the number the screen advertises', function () {
    /*
     * MAX_REC is sent to the screen as `maxRec` and the picker stops taking
     * products at it, so the cast has to agree or the screen and the store
     * disagree about what was saved. It used to agree by coincidence —
     * ModuleSchema::castIds() defaults to 24 and MAX_REC is 24 — and now says
     * so in CartPage::overrides().
     */
    $ids = implode(',', range(1, CartPage::MAX_REC + 10));

    expect(substr_count((string) m2Cast(CartPage::class, 'rec_ids', $ids), ','))
        ->toBe(CartPage::MAX_REC - 1);
});

/* ───────────────── the two arms a rule may never take over ───────────────── */

it('refuses to let a module-local rule replace a colour or a select', function () {
    /*
     * Rule 5 of the project notes names exactly two of these arms by hand — "a
     * select stores one of its own options or the default" and a colour is
     * scheme-checked before it reaches a stylesheet. `rule` REPLACES the type
     * arm, so a rule on either of those would move that guarantee out of the
     * one shared boundary and into a module, which is the arrangement that let
     * four copies of the isValidHex bug live for a year.
     */
    foreach (['colour', 'select', 'skin', 'sections'] as $type) {
        expect(fn () => ModuleSchema::field('x', [
            'type' => $type,
            'label' => 'X',
            'options' => ['a' => 'A'],
            'rule' => 'strtoupper',
        ]))->toThrow(InvalidArgumentException::class);
    }

    // And a rule that is not callable at all is refused rather than ignored.
    expect(fn () => ModuleSchema::field('x', ['type' => 'text', 'label' => 'X', 'rule' => 'no_such_function_m2']))
        ->toThrow(InvalidArgumentException::class);
});

/* ───────────────── MobileMenu · the groups are the constant ──────────────── */

it('draws the mobile menu groups from the constant the guard checks', function () {
    /*
     * THE DEFECT THIS FORBIDS. These five groups were written inline in
     * MobileMenuApiController::show(), which is why MobileMenu was the one
     * module with a SCHEMA that could not join ModuleFrameworkGuardTest — that
     * guard's own note said so. Outside it, a setting stored with no control to
     * write it goes unnoticed, which is precisely what it caught once already:
     * "cart_panel stores these with no control to write them: accent".
     *
     * So this pins that the endpoint really is reading MobileMenu::TABS rather
     * than a second copy that could drift from it.
     */
    $owner = AdminUser::create([
        'name' => 'M2 Menu Owner',
        'email' => 'm2-menu-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');

    $body = test()->getJson('/admin-api/mobile-menu')->assertOk()->json();

    $expected = [];

    foreach (MobileMenu::TABS as $key => [$label, $description, $keys]) {
        $expected[] = ['key' => $key, 'label' => $label, 'description' => $description, 'fields' => $keys];
    }

    expect($body['groups'])->toBe($expected);

    // And every control the screen draws is placed in exactly one group, which
    // is the guarantee the constant was lifted out of the controller to buy.
    $placed = array_merge(...array_column($body['groups'], 'fields'));

    expect($placed)->toHaveCount(count(MobileMenu::SCHEMA));
    expect(array_unique($placed))->toHaveCount(count($placed));
    expect(array_diff(array_column($body['fields'], 'key'), $placed))->toBe([]);
});
