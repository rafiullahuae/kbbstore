<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\EcommerceApiController;
use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\DeliveryLine;
use App\Support\ReviewBadgeSettings;

/**
 * AN EMPTY BOX IS A VALUE. Every settings screen, measured rather than read.
 *
 * WHAT WENT WRONG. Laravel's global ConvertEmptyStringsToNull middleware turns
 * a box the owner has cleared into NULL before any controller sees it. Four
 * screens were written against '' instead and refused the null:
 *
 *   Store → Ecommerce         EcommerceApiController::cast()'s text arm was
 *                             `is_string($raw) && ... ? $raw : null`, and
 *                             save() reads null as "not acceptable":
 *                             {"checkout_coupon":""} → 422 “Suggested coupon
 *                             code” is not a valid value, while "GLOW" → 200.
 *   Store → Marketing Pixels  ModuleSchema::cast()'s text arm, same shape,
 *                             same 422 — on a screen whose own help says
 *                             "Leave any of them blank to skip it".
 *   Store → Mail              MailApiController skipped validation for a blank
 *                             box with `is_string($value) && trim($value)===''`,
 *                             which a null never matches, so the `string` and
 *                             `email` rules ran on it.
 *   Store → Reviews → Badges  `review_badge_label` had a bare `string` rule.
 *
 * WHY IT IS BIGGER THAN A FIELD. The Ecommerce console posts a WHOLE TAB:
 * ecSaveNow() collects every [data-ec] control on screen and sends the lot.
 * Thirteen of that schema's sixteen text boxes ship empty, so on a fresh store
 * the Cart, Checkout and Product tabs could not be saved at all — the first
 * empty box returned 422 and nothing after it was written. The empty state is
 * the documented way to switch several of those features off ("Empty shows
 * nothing", "Empty means nothing is ever sent"), and it was unreachable.
 *
 * ReviewSettingsApiController and AdminController::checkSetting() were already
 * right, by two different routes (`nullable` and `trim((string) $raw)`), and
 * this file pins both so neither loses it.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

function emptyBoxOwner(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Empty Box Owner',
        'email' => 'empty-box-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

/** The private schema, read the way the screen reads it. */
function ecommerceSchema(): array
{
    $m = new ReflectionMethod(EcommerceApiController::class, 'schema');
    $m->setAccessible(true);

    return $m->invoke(app(EcommerceApiController::class));
}

/** @return array<int, array{0:string, 1:string, 2:string}> tab, key, label */
function ecommerceTextFields(): array
{
    $out = [];

    foreach (ecommerceSchema() as $tab => $def) {
        foreach ($def['fields'] as $name => $field) {
            if (in_array($field[0], ['text', 'textarea'], true)) {
                $out[] = [$tab, $name, $field[1]];
            }
        }
    }

    return $out;
}

it('has text boxes on the Ecommerce screen and most of them ship empty', function () {
    // Guards the two tests below: if the schema ever loses its text fields,
    // their loops would pass by running nothing at all.
    $fields = ecommerceTextFields();

    expect($fields)->toHaveCount(16);

    $blankByDefault = array_filter(
        ecommerceSchema()['cart']['fields'],
        fn ($f) => in_array($f[0], ['text', 'textarea'], true) && $f[2] === ''
    );

    expect(count($blankByDefault))->toBe(6);
});

it('accepts an empty value for every text box on the Ecommerce screen', function () {
    emptyBoxOwner();

    $fields = ecommerceTextFields();
    expect($fields)->not->toBeEmpty();

    foreach ($fields as [$tab, $key, $label]) {
        $response = test()->postJson('/admin-api/ecommerce', ['settings' => [$key => '']]);

        expect($response->status())
            ->toBe(200, "{$tab}/{$key} (“{$label}”) refused an empty box: " . json_encode($response->json()));

        expect($response->json('ok'))->toBeTrue();
    }

    // And it is genuinely written, not skipped: the row exists and holds ''.
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    foreach ($fields as [, $key]) {
        expect(Setting::query()->where('key', $key)->exists())
            ->toBeTrue("{$key} was reported saved but no row was written");
        expect(Setting::query()->where('key', $key)->value('value'))->toBe('');
    }
});

it('saves every Ecommerce tab whole on a fresh store, exactly as the console posts it', function () {
    emptyBoxOwner();

    // This is the bug as the owner meets it. ecSaveNow() in the admin console
    // does exactly this: every control on the open tab, whatever it holds.
    $tabs = test()->get('/admin-api/ecommerce')->assertOk()->json('tabs');

    expect($tabs)->toHaveCount(5);

    foreach ($tabs as $tab) {
        $settings = [];

        foreach ($tab['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $settings[$field['name']] = $field['type'] === 'bool'
                    ? (bool) $field['value']
                    : (string) $field['value'];
            }
        }

        expect($settings)->not->toBeEmpty();

        $response = test()->postJson('/admin-api/ecommerce', ['settings' => $settings]);

        expect($response->status())->toBe(
            200,
            "the {$tab['key']} tab could not be saved as it ships: " . json_encode($response->json())
        );
        expect($response->json('saved'))->toBe(count($settings));
    }
});

it('lets the owner type into one box on a tab whose other boxes are empty', function () {
    emptyBoxOwner();

    // The Checkout tab, posted whole, with one field filled in. Before the fix
    // the empty siblings returned 422 and the typed value was never written.
    $tabs = collect(test()->get('/admin-api/ecommerce')->assertOk()->json('tabs'));
    $checkout = $tabs->firstWhere('key', 'checkout');

    $settings = [];

    foreach ($checkout['sections'] as $section) {
        foreach ($section['fields'] as $field) {
            $settings[$field['name']] = $field['type'] === 'bool'
                ? (bool) $field['value']
                : (string) $field['value'];
        }
    }

    $settings['checkout_coupon'] = 'GLOW';

    test()->postJson('/admin-api/ecommerce', ['settings' => $settings])
        ->assertOk()
        ->assertJson(['ok' => true]);

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(app(SettingsService::class)->get('checkout_coupon'))->toBe('GLOW');
});

it('still refuses an emptied number box and an unparseable colour', function () {
    emptyBoxOwner();

    // The fix folds null to '' in the TEXT arm only. A number box that has been
    // emptied holds no number, and "is not a valid value" is the right answer.
    foreach (['products_per_page', 'grid_columns', 'dispatch_cutoff_hour', 'cod_fee'] as $key) {
        expect(test()->postJson('/admin-api/ecommerce', ['settings' => [$key => '']])->status())
            ->toBe(422, "{$key} accepted an empty number box");
    }

    expect(test()->postJson('/admin-api/ecommerce', ['settings' => ['checkout_coupon_color' => 'not-a-colour']])->status())
        ->toBe(422);

    // And a text box still has a ceiling.
    expect(test()->postJson('/admin-api/ecommerce', ['settings' => ['minicart_promo' => str_repeat('x', 2001)]])->status())
        ->toBe(422);

    expect(test()->postJson('/admin-api/ecommerce', ['settings' => ['minicart_promo' => str_repeat('x', 2000)]])->status())
        ->toBe(200);
});

it('accepts an empty value on Marketing Pixels, Mail and the review badge', function () {
    emptyBoxOwner();

    foreach (['meta_id', 'ga4_id', 'tiktok_id'] as $key) {
        expect(test()->postJson('/admin-api/marketing-pixels', ['settings' => [$key => '']])->status())
            ->toBe(200, "marketing pixels refused an empty {$key}");
    }

    // Every box on the Mail screen the SMTP option asks for.
    foreach (['mail_host', 'mail_port', 'mail_username', 'mail_password',
              'mail_from_address', 'mail_from_name', 'mail_merchant_address',
              'mail_timeout'] as $key) {
        expect(test()->postJson('/admin-api/mail', ['settings' => [$key => '']])->status())
            ->toBe(200, "the Mail screen refused an empty {$key}");
    }

    expect(test()->putJson('/admin-api/review-badges', ['review_badge_label' => ''])->status())
        ->toBe(200, 'the review badge refused an empty label');
});

it('keeps the two screens that already had this right', function () {
    emptyBoxOwner();

    // ReviewSettingsApiController, by `nullable`.
    expect(test()->putJson('/admin-api/review-settings', ['sr_empty_text' => ''])->status())->toBe(200);

    // AdminController::checkSetting(), by trim((string) $raw). Every `text`
    // key on Business Details, swept.
    $rules = (new ReflectionClass(AdminController::class))->getConstant('SETTING_RULES');
    $textKeys = array_keys(array_filter($rules, fn ($d) => ($d[0] ?? '') === 'text'));

    expect($textKeys)->not->toBeEmpty();

    foreach ($textKeys as $key) {
        expect(test()->putJson('/admin-api/settings', ['settings' => [$key => '']])->status())
            ->toBe(200, "Business Details refused an empty {$key}");
    }
});

/**
 * EMPTY, OR CLEARED? The answer is per field, and it is not the same one.
 *
 * SettingsService::get($key, $default) returns its default only when the ROW
 * IS ABSENT. A cleared box stores '', so the row exists and the default is out
 * of reach from that moment on. Whether that is what the owner wanted depends
 * entirely on the reader, and the three Ecommerce text fields that ship with a
 * non-empty default answer differently:
 *
 *   delivery_default_text   cleared means cleared. DeliveryLine::for() prints
 *                           '' and the storefront shows no delivery line. The
 *                           two states are distinct and the reader honours it.
 *   fbt_title               cleared means an EMPTY HEADING. fbt.blade.php
 *                           prints `<h2 class="kbb-fbt-title"></h2>` — it has
 *                           no blank fallback and no blank branch, so the
 *                           owner gets an empty element rather than no title.
 *   review_badge_label      cleared means NOTHING AT ALL.
 *                           ReviewBadgeSettings::label() folds a blank value
 *                           back to '{n} reviews', so '' and the default are
 *                           indistinguishable downstream and the box cannot be
 *                           emptied however many times it is saved.
 *
 * Pinned rather than changed: which of the three is wrong is the owner's call,
 * not a lane's. This test says what the shop does today so that a change to
 * any of it is a decision somebody made on purpose.
 */
it('records what a cleared box means for each field that has a non-empty default', function () {
    emptyBoxOwner();

    $settings = app(SettingsService::class);

    foreach (['delivery_default_text', 'fbt_title', 'review_badge_label'] as $key) {
        test()->postJson('/admin-api/ecommerce', ['settings' => [$key => '']])->assertOk();
    }

    $settings->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    // The row exists and holds '', so the schema default is no longer reachable.
    foreach (['delivery_default_text' => '1–3 days fast delivery all over UAE',
              'fbt_title' => 'Complete your routine',
              'review_badge_label' => '{n} reviews'] as $key => $default) {
        expect(Setting::query()->where('key', $key)->value('value'))->toBe('');
        expect($settings->get($key, $default))->toBe('', "{$key} still answered its default after being cleared");
    }

    // delivery_default_text: cleared is honoured — no line at all.
    expect(app(DeliveryLine::class)->for('AE'))->toBe('');

    // review_badge_label: cleared is not honoured — the default comes back.
    expect(ReviewBadgeSettings::normalise('review_badge_label', ''))->toBe('{n} reviews');
    expect(ReviewBadgeSettings::normalise('review_badge_label', '   '))->toBe('{n} reviews');
});
