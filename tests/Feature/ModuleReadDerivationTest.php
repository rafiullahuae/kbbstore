<?php

/**
 * A stored value is re-derived on the way out, not trusted — Lane M3, task 2.
 *
 * ── THE DISAGREEMENT THIS SETTLES ───────────────────────────────────────────
 *
 * Two parts of this codebase held opposite positions on one question, and round
 * 2 left it standing as "a decision, not a cleanup":
 *
 *   ModuleSchema::coerceRead()  cast the stored value to its PHP type and
 *       TRUSTED it. `(int) $raw` for a number, `(string) $raw` for the rest.
 *
 *   App\Support\ReviewSettings' docblock  "applied on READ as well as on write,
 *       so a row hand-edited in the database — or left behind by an older build
 *       — cannot put the storefront outside the range the screen would allow."
 *
 * Both could not be true, and the three services that read through
 * ModuleSchema::read() (PayShipRules, BuildMyRoutine, HomepageContent) were on
 * the first one. This round settles it on the second, and the tests below are
 * what that decision costs and what it buys.
 *
 * ── THE ARGUMENT, MEASURED RATHER THAN REASONED ─────────────────────────────
 *
 * "Trust it, because it went through write()" is only true of a row that went
 * through write(). A row reaches `settings` and `module_settings` by four paths
 * that do not: a hand-edit, an older build, a WordPress import, a restored
 * backup. So a hostile row was PLANTED by those paths — straight into the
 * tables — and read back through each module's own public reader. What came
 * back, before this change:
 *
 *   BuildMyRoutine::all()['steps_mode']    'evil-not-an-option'  verbatim
 *   BuildMyRoutine::all()['offer_scope']   '<script>alert(1)</script>' verbatim
 *   BuildMyRoutine::all()['offer_coupon']  9,000 characters, cap 5,000
 *   PayShipRules::all()['cod_min']         -50000, a negative money bound
 *   PayShipRules::all()['cod_max']         12, from a stored '12.50'
 *
 * The first two are rule 5 of the project notes failing in its own words — "a
 * select stores one of its own options or the default". The last is worse than
 * it looks: castInt() REFUSES '12.50' on the way in, and its own comment says
 * why ("a hundredfold error that reads back as a plausible number"), and the
 * old coerceRead reintroduced exactly that error on the way out.
 *
 * ── AND WHAT IT COSTS, WHICH IS THE HALF THAT MAKES IT SAFE ─────────────────
 *
 * Nothing, for any row that was saved from a screen — and that is proved by
 * round-tripping every field of every module on the schema through the REAL
 * write() and the REAL read(), rather than by arguing that cast() is
 * idempotent. See the second case.
 */

use App\Models\Setting;
use App\Services\BuildMyRoutine;
use App\Services\HomepageContent;
use App\Services\ModuleSchema;
use App\Services\PayShipRules;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A row written by a path that never ran cast() — an import, a hand-edit, a
 * restored backup. Straight into the table, then every memo in front of it
 * dropped, because Setting::map() is a landmine this repo has already paid for.
 */
function m3PlantGlobal(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    m3Forget();
}

function m3PlantModule(string $module, string $key, string $value): void
{
    DB::table('module_settings')->updateOrInsert(
        ['module' => $module, 'key' => $key],
        ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
    );
    m3Forget();
}

function m3Forget(): void
{
    Cache::forget('kbb.module_settings');
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

it('refuses a planted row that its own screen could never have saved', function () {
    /*
     * ── WHAT THIS LOOKED LIKE ON THE SHOP ───────────────────────────────────
     *
     * Every expectation below FAILS on the parent revision, with the planted
     * value coming back verbatim. Concretely: a `steps_mode` of anything at all
     * — a leftover from an older build, a WordPress meta row, a typo in a
     * restored dump — was handed to routines-screen.blade.php, which asks
     * `data.settings.steps_mode === 'custom'` and gets false for every value
     * that is not that word, so the Build-my-routine screen drew the fixed-steps
     * arm for a shop whose stored mode was neither of its two options and whose
     * screen could not be used to correct it.
     *
     * MUTATION ACTUALLY RUN: put ModuleSchema::coerceRead() back to its match
     * over $field['type'] and this case goes red with
     *   Failed asserting that two strings are identical.
     *   -'fixed'
     *   +'evil-not-an-option'
     */
    m3PlantModule('build_my_routine', 'steps_mode', 'evil-not-an-option');
    m3PlantModule('build_my_routine', 'offer_scope', '<script>alert(1)</script>');
    m3PlantModule('build_my_routine', 'offer_coupon', str_repeat('A', 9000));

    $routine = app(BuildMyRoutine::class)->all();

    // A select holds one of its own options or the default. Rule 5, in a test.
    expect($routine['steps_mode'])->toBe('fixed')
        ->and(array_key_exists($routine['steps_mode'], BuildMyRoutine::SCHEMA['steps_mode']['options']))->toBeTrue();
    expect($routine['offer_scope'])->toBe('site')
        ->and(array_key_exists($routine['offer_scope'], BuildMyRoutine::SCHEMA['offer_scope']['options']))->toBeTrue();
    // And text meets the cap its own policy declares, rather than whatever the
    // column will hold.
    expect(strlen((string) $routine['offer_coupon']))->toBe(5000);

    m3PlantModule('pay_ship_rules', 'cod_min', '-50000');
    m3PlantModule('pay_ship_rules', 'cod_max', '12.50');

    $rules = app(PayShipRules::class)->all();

    // Money is typed. A negative bound and a truncated decimal are the two
    // things castInt() was written to refuse, and read() used to let both past.
    expect($rules['cod_min'])->toBe(0, 'a negative money bound survived the read');
    expect($rules['cod_max'])->toBe(0, "'12.50' read back as 12 — the hundredfold error, on the way out");
});

it('returns a value saved through the screen byte-identical, on every field of every module', function () {
    /*
     * ── THE COST OF THE DECISION, PROVED RATHER THAN ARGUED ─────────────────
     *
     * Re-deriving on read only fails to be a behaviour change if cast() is
     * idempotent on everything it will store. That is a claim about 3 modules
     * and every field they declare, and it is checked by driving the REAL
     * round trip — write() into the real tables, read() back out — rather than
     * by calling cast() twice in memory, because the tables stringify on the
     * way through and that is where an idempotence claim would break.
     *
     * A shop whose values came from its own screens therefore reads back
     * exactly what it read back before, and the only rows that move are the
     * ones no screen could have produced. That is rule 1.
     *
     * MUTATION ACTUALLY RUN: make coerceRead() return `$field['default']`
     * whenever `$raw !== null` and this goes red on the first written field.
     */
    $modules = [
        'pay_ship_rules' => [PayShipRules::class, PayShipRules::SCHEMA],
        'build_my_routine' => [BuildMyRoutine::class, BuildMyRoutine::SCHEMA],
        'homepage_content' => [HomepageContent::class, HomepageContent::SCHEMA],
    ];

    // Values a screen could really post, per type, chosen to be ones cast()
    // ACCEPTS — an idempotence claim is about the image of cast, not about
    // what it refuses.
    $posts = [
        'bool' => [true, false],
        'money' => [0, 2500],
        'text' => ['A real value', '', 'Autumn sale'],
        'textarea' => ["Two\nlines", ''],
        'select' => ['__FIRST__', '__LAST__'],
    ];

    $settings = app(SettingsService::class);
    $checked = 0;

    foreach ($modules as $module => [$class, $schema]) {
        foreach (ModuleSchema::normalise($schema) as $key => $field) {
            foreach ($posts[$field['type']] ?? [] as $post) {
                if ($post === '__FIRST__') {
                    $post = (string) array_key_first($field['options']);
                }

                if ($post === '__LAST__') {
                    $post = (string) array_key_last($field['options']);
                }

                $result = ModuleSchema::write($settings, $module, $schema, [$key => $post]);

                // Only a value the screen would have accepted is a fair test.
                if ($result['written'] === []) {
                    continue;
                }

                m3Forget();

                $back = ModuleSchema::read(app(SettingsService::class), $module, $schema)[$key];
                $wrote = ModuleSchema::cast($field, $post);

                expect($back)->toBe(
                    $wrote,
                    "{$module}.{$key}: saved ".var_export($wrote, true).' and read back '.var_export($back, true)
                );

                $checked++;
            }
        }
    }

    // A guard on the guard: a $posts map that matched no type would make the
    // loop pass by doing nothing.
    expect($checked)->toBeGreaterThan(10, 'the round trip drove almost nothing');
});

it('hands a reader the declared default when the stored row is unusable, never a null', function () {
    /*
     * The one place read() and write() have to differ, stated as a rule rather
     * than left to be noticed. cast() answers null for "will not store this",
     * and write() turns that into a reported rejection the screen can show. A
     * READER has no such channel and the page has to render, so a refusal on
     * the way out is the module's own declared default — the value a shop that
     * has saved nothing already gets, which is the one answer that cannot
     * itself be a surprise.
     *
     * MUTATION: make coerceRead() return cast()'s answer unchanged and this
     * goes red with `Failed asserting that null is of type string` on the first
     * key, and every screen reading a planted row renders a null into a select.
     */
    m3PlantModule('build_my_routine', 'offer_scope', 'nonsense');
    m3PlantModule('pay_ship_rules', 'cod_min', 'not a number');

    foreach (app(BuildMyRoutine::class)->all() as $key => $value) {
        expect($value)->not->toBeNull("build_my_routine.{$key} read back null");
    }

    foreach (app(PayShipRules::class)->all() as $key => $value) {
        expect($value)->not->toBeNull("pay_ship_rules.{$key} read back null");
    }

    expect(app(BuildMyRoutine::class)->all()['offer_scope'])->toBe('site');
    expect(app(PayShipRules::class)->all()['cod_min'])->toBe(0);
});
