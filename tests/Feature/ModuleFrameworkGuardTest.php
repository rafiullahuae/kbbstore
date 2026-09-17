<?php

/**
 * The guard for the module framework as a class of thing — Lane EH.
 *
 * ── WHAT THIS IS FOR ────────────────────────────────────────────────────────
 *
 * This project has one recurring defect and the module framework is where it
 * breeds: A SCREEN OR A REGISTRY ENTRY STATING SOMETHING THE CODE DOES NOT DO.
 * The list of times it has already been paid for is long enough to be a
 * specification:
 *
 *   - `seo_engine` and `product_sorting` were marked `live` — which
 *     ModuleRegistry defines as "something on the storefront reads
 *     moduleEnabled() for this key" — while nothing read either.
 *   - `single_name` was a toggle that saved and nothing read.
 *   - `reassure_auth_text` was a setting with no control, so its shipped
 *     default was the only value it ever had.
 *   - PayShipRules' help text named "Store → Ecommerce → Delivery", a screen
 *     that does not exist.
 *   - `brands` said "Not ported yet" about a directory, per-brand pages, two
 *     301s and an admin editor that have all been serving since 2.60.109, and
 *     pointed its settings link at "Its own screen — screen not built yet"
 *     while Catalog → Brands was right there. Found by THIS FILE'S todo check,
 *     which is the one below that had no precedent.
 *
 * Phase3ModuleSwitchesTest already pins two modules end to end. This pins the
 * PROPERTIES every module must have, so the next one cannot ship with the same
 * fault in a different row.
 *
 * ── HOW IT LOOKS, AND WHY NOT THE OBVIOUS WAY ───────────────────────────────
 *
 * NOT a source-text search. The existing check in Phase3ModuleSwitchesTest
 * str_contains()es the raw concatenated source of app/ and resources/views/ for
 * `moduleEnabled('key'`, and that reads comments and quoted strings as code: the
 * long explanatory note on a registry row, or a test's own description of a
 * defect, satisfies it. A row could be "proved" live by prose about it.
 *
 * So every reader here is found by TOKENISING. Blade is compiled through the
 * real BladeCompiler first, because `@if ($settings->moduleEnabled('x'))` is
 * T_INLINE_HTML to token_get_all() and a plain tokeniser cannot see a single
 * storefront gate — nine of the live modules are read only from a .blade.php
 * and would all have looked unread. Compiling also removes `{{-- --}}` blade
 * comments, and stripping T_COMMENT/T_DOC_COMMENT removes the rest, so what is
 * left is code.
 *
 * Where a claim is about the ADMIN, this asks the endpoint and reads the JSON it
 * really returns, rather than searching the console's HTML — that file is one
 * enormous document with its own CSS inlined, and a class-name search of it
 * matches the stylesheet as readily as the markup.
 */

use App\Http\Controllers\Admin\AdminController;
use App\Models\AdminUser;
use App\Services\MarketingPixels;
use App\Services\ModuleRegistry;
use App\Services\ModuleSchema;
use App\Services\PayShipRules;
use Illuminate\Support\Facades\Hash;

/* ─────────────────────────── finding the readers ─────────────────────────── */

/**
 * Every gate call in the app, as `key => [where, ...]`, found by tokenising.
 *
 * Computed once per process: it compiles several hundred Blade files, which is
 * far too slow to repeat per test and cannot change within a run.
 *
 * @return array<string, list<string>>
 */
function ehGateCalls(): array
{
    static $calls = null;

    if ($calls !== null) {
        return $calls;
    }

    $blade = app('blade.compiler');

    /*
     * The methods that GATE something on a module key.
     *
     * `on`, `hidden` and `classFor` are ModuleRegistry's and the section
     * registries' (HomepageSections, ProductSections); `moduleEnabled` is
     * SettingsService's. A key read through any of them is genuinely switched
     * by something — which is what `live` claims.
     */
    $methods = ['moduleEnabled', 'on', 'hidden', 'classFor'];

    $calls = [];
    $keys = ModuleRegistry::REGISTRY;

    foreach ([base_path('app'), base_path('resources/views'), base_path('routes')] as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            // The registry itself names every key and would match all of them.
            if ($path === base_path('app/Services/ModuleRegistry.php')) {
                continue;
            }

            $code = file_get_contents($path);

            if (str_ends_with($path, '.blade.php')) {
                try {
                    $code = $blade->compileString($code);
                } catch (\Throwable) {
                    continue;
                }
            }

            $tokens = @token_get_all($code);

            if (! $tokens) {
                continue;
            }

            // Comments out, so prose about a module never counts as a reader.
            $flat = [];

            foreach ($tokens as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }

                    $flat[] = [$token[0], $token[1]];
                } else {
                    $flat[] = [null, $token];
                }
            }

            $count = count($flat);

            for ($i = 0; $i < $count - 3; $i++) {
                if ($flat[$i][0] !== T_STRING || ! in_array($flat[$i][1], $methods, true)) {
                    continue;
                }

                if ($flat[$i + 1][1] !== '(' || $flat[$i + 2][0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $key = trim($flat[$i + 2][1], "'\"");

                if (isset($keys[$key])) {
                    $calls[$key][] = str_replace(base_path().'/', '', $path);
                }
            }
        }
    }

    return $calls;
}

/** @return list<string> the files that gate on this key */
function ehReaders(string $key): array
{
    return array_values(array_unique(ehGateCalls()[$key] ?? []));
}

/** The registry rows with a given status. @return array<string, array> */
function ehRowsWithStatus(string $status): array
{
    return array_filter(ModuleRegistry::REGISTRY, static fn ($row) => $row[9] === $status);
}

/* ───────────────────────── the registry tells the truth ───────────────────── */

it('has a real, tokenised storefront reader for every module the registry calls live', function () {
    $unread = [];

    foreach (ehRowsWithStatus('live') as $key => $row) {
        if (ehReaders($key) === []) {
            $unread[] = $key;
        }
    }

    expect($unread)->toBe([], 'Registry says these modules are live, but no code reads their switch: '.implode(', ', $unread));
});

/**
 * The `live` rows whose only reader is an admin screen's own status flag.
 *
 * EMPTY, AND THAT IS THE POINT. It held `mega_menu` — marked `live` while the
 * only code anywhere that read its key was `'module_on' => ...` in
 * MegaMenuApiController, a flag the admin screen printed about itself, with the
 * storefront rendering the panels regardless. Lane EM fixed it in 2.60.199:
 * partials/nav-bar.blade.php now gates both the caret and the `.drop` panel on
 * the switch, and partials/mobile-menu-item.blade.php gates the phone menu's
 * expandable sections, with 2026_11_10_000000 aligning the stored toggle so the
 * newly-real gate does not strip a live store's header on apply.
 *
 * SUBSET, NOT EQUALITY: a row leaving this list is a fix and must not fail;
 * a row JOINING it is a new instance of the defect and must. So this returning
 * `[]` is not a weaker guard than it was — it is the same guard with nothing
 * left excused.
 *
 * @return list<string>
 */
function ehAdminOnlyReaders(): array
{
    return [];
}

it('does not rest a live row on an admin screen’s own status flag', function () {
    /*
     * A reader in app/Http/Controllers/Admin or resources/views/admin is the
     * console asking "is this module on" so it can draw its own header. It is
     * not a gate on anything a shopper receives, and `live` — "something on the
     * storefront reads moduleEnabled() for this key" — claims that it is.
     *
     * The plain live-has-a-reader check cannot tell the two apart, which is how
     * mega_menu passed every guard this repo had while its switch did nothing.
     */
    $adminOnly = [];

    foreach (ehRowsWithStatus('live') as $key => $row) {
        $readers = ehReaders($key);

        if ($readers === []) {
            continue; // the other test's business
        }

        $storefront = array_filter($readers, static fn (string $path) => ! str_starts_with($path, 'app/Http/Controllers/Admin/')
            && ! str_starts_with($path, 'resources/views/admin/'));

        if ($storefront === []) {
            $adminOnly[] = $key;
        }
    }

    $known = ehAdminOnlyReaders();
    $new = array_values(array_diff($adminOnly, $known));

    expect($new)->toBe([], 'These modules are marked live but only an admin screen reads the switch, so it changes nothing a shopper sees: '.implode(', ', $new));
});

it('does not say “not ported yet” about a module something already reads', function () {
    /*
     * THE OTHER DIRECTION, AND THE ONE NOTHING CHECKED.
     *
     * Every guard this repo had asked "is a `live` row really live". None asked
     * whether a `todo` row is really absent, so a module could be built,
     * shipped, gated and serving while the one screen the owner looks at said
     * "Not ported yet" — and the Modules screen draws a `todo` row with its
     * switch DISABLED, so he cannot turn it off either.
     *
     * That is not hypothetical: `brands` was in exactly that state when this
     * test was written, and this is the assertion that caught it.
     *
     * A key read through a SECTION registry only — HomepageSections'
     * `hidden('brands')`, say — is a different switch with the same name, so
     * the message names the file and lets a human judge rather than guessing.
     */
    $ported = [];

    foreach (ehRowsWithStatus('todo') as $key => $row) {
        $readers = ehReaders($key);

        if ($readers !== []) {
            $ported[] = $key.' (read in '.implode(', ', $readers).')';
        }
    }

    expect($ported)->toBe([], 'These modules are marked “todo” but something already gates on them: '.implode('; ', $ported));
});

it('names a settings screen on every row that tells the owner to go and switch it there', function () {
    /*
     * `elsewhere` renders as "Switched in <screen>" and `screen` as "Always on
     * — a screen, not a switch", and both print the screen name. An empty one
     * prints "Switched in " and trails off.
     */
    $nameless = [];

    foreach (ModuleRegistry::REGISTRY as $key => $row) {
        if (! in_array($row[9], ['elsewhere', 'screen'], true)) {
            continue;
        }

        if (trim((string) $row[4]) === '') {
            $nameless[] = $key;
        }
    }

    expect($nameless)->toBe([], 'These rows tell the owner to switch the module somewhere and do not say where: '.implode(', ', $nameless));
});

it('points every settings link at a console screen that exists', function () {
    /*
     * A row's sixth field is the console route its settings link goes to, and
     * the Modules screen renders it as a real anchor. A route naming a screen
     * the console cannot draw lands the owner on the dashboard with no error —
     * go()'s dispatch object ends `|| renderDash`, so an unknown id silently
     * becomes the dashboard.
     *
     * A screen is registered in one of three ways in this console, and all
     * three count, because the question is "can the owner get there", not "how
     * is it wired":
     *
     *   1. the go() dispatch object,
     *   2. a render<Id>() function (several screens are installed from a
     *      partial later in the document than that object is built),
     *   3. a partial that wraps go() itself and claims an id — `var SCREEN =
     *      'media'` in admin/partials/media-library-screen.blade.php.
     */
    $src = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('resources/views/admin')));

    foreach ($files as $file) {
        if ($file->isFile()) {
            $src .= file_get_contents($file->getPathname());
        }
    }

    $screens = [];

    if (preg_match('/\(\{([a-z0-9_\'":,\-]+)\}\[id\]\s*\|\|/i', $src, $m)) {
        preg_match_all("/'?([a-z0-9_\-]+)'?\s*:/i", $m[1], $found);
        $screens = $found[1];
    }

    expect($screens)->not->toBeEmpty('could not find the console’s screen dispatch table at all');

    preg_match_all('/render([A-Za-z0-9_]+)\s*(?:=\s*function|\()/', $src, $fns);

    foreach ($fns[1] as $name) {
        $screens[] = strtolower($name);
    }

    preg_match_all("/var\s+SCREEN\s*=\s*'([a-z0-9_\-]+)'/i", $src, $wrapped);

    foreach ($wrapped[1] as $name) {
        $screens[] = $name;
    }

    $normalise = static fn (string $s) => str_replace('-', '', strtolower($s));
    $known = array_map($normalise, $screens);

    $nowhere = [];

    foreach (ModuleRegistry::REGISTRY as $key => $row) {
        $route = trim((string) $row[5]);

        if ($route === '') {
            continue;
        }

        // "screen:tab" — the base id is the screen; product_sorting and brands
        // both name a sub-tab so the owner is not left guessing which of six.
        $base = explode(':', $route)[0];

        if (! in_array($normalise($base), $known, true)) {
            $nowhere[] = $key.' → '.$route;
        }
    }

    expect($nowhere)->toBe([], 'These settings links point at a console screen that does not exist: '.implode(', ', $nowhere));
});

it('sends the two catalog sub-tab links at real sub-tabs', function () {
    // The base id existing is not enough for a "screen:tab" route: landing on
    // Catalog's Products tab when the row said Brands is the same wrong answer
    // in a quieter voice.
    $src = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect(preg_match('/\{([a-z0-9_\'":,\-]+)\}\[catTab\]/i', $src, $m))
        ->toBe(1, 'could not find the Catalog screen’s sub-tab dispatch');

    preg_match_all("/'?([a-z0-9_\-]+)'?\s*:/i", $m[1], $found);
    $tabs = $found[1];

    foreach (ModuleRegistry::REGISTRY as $key => $row) {
        $parts = explode(':', (string) $row[5]);

        if (($parts[0] ?? '') !== 'catalog' || ! isset($parts[1])) {
            continue;
        }

        expect($tabs)->toContain($parts[1]);
    }
});

/* ──────────────────────── the schema keeps both halves ────────────────────── */

/**
 * Every module that has been migrated onto ModuleSchema.
 *
 * Adding a module here is the whole cost of adopting the schema, and the point
 * of the list: a module with a SCHEMA that is not on it is not checked, and a
 * module on it cannot ship a control that saves nothing.
 *
 * @return array<string, array{schema: array, tabs: array}>
 */
function ehSchemaModules(): array
{
    return [
        'pay_ship_rules' => ['schema' => PayShipRules::SCHEMA, 'tabs' => PayShipRules::TABS],
        'marketing_pixels' => ['schema' => MarketingPixels::SCHEMA, 'tabs' => MarketingPixels::TABS],
    ];
}

it('normalises and describes every field of every schema on the shared shape', function () {
    // normalise() throws on an unknown type, an unknown store, or a select with
    // no options — a field the renderer would draw as an empty box.
    foreach (ehSchemaModules() as $module => $parts) {
        $fields = ModuleSchema::normalise($parts['schema']);

        expect($fields)->not->toBeEmpty();

        foreach ($fields as $key => $field) {
            expect($field['label'])->not->toBe('', "{$module}.{$key} has no label to draw");

            // The third job the shape has to serve: say what the value is.
            expect(ModuleSchema::describe($field, $field['default']))->toBeString();
        }
    }
});

it('gives every module setting a control, and every control a setting', function () {
    /*
     * BOTH HALVES, PINNED TOGETHER, which is the requirement this file exists
     * for. A value with no control is `reassure_auth_text`: a default that was
     * the only value it ever had. A control with no value is the opposite
     * failure and is worse, because it reports "Saved".
     */
    foreach (ehSchemaModules() as $module => $parts) {
        $keys = array_keys(ModuleSchema::normalise($parts['schema']));
        $placed = [];

        foreach ($parts['tabs'] as $tab => [$label, $description, $tabKeys]) {
            foreach ($tabKeys as $k) {
                $placed[] = $k;
            }
        }

        $noControl = array_values(array_diff($keys, $placed));
        $noSetting = array_values(array_diff($placed, $keys));

        expect($noControl)->toBe([], "{$module} stores these with no control to write them: ".implode(', ', $noControl));
        expect($noSetting)->toBe([], "{$module} draws controls for values it does not store: ".implode(', ', $noSetting));

        // Twice in one tab is not a second control, it is the same one drawn
        // twice — and whichever the operator filled in last would appear to win.
        expect(count($placed))->toBe(count(array_unique($placed)), "{$module} draws a control for the same value more than once");
    }
});

it('gives every schema field saved through the generic endpoint a validation rule', function () {
    /*
     * AdminController::updateSettings() SILENTLY DROPS any key not in
     * SETTING_RULES — it reports success and writes nothing. A field a module
     * declares as `store: 'admin'` goes through that endpoint, so a missing
     * rule is a control that saves nothing and says it saved.
     *
     * ModuleSchema::missingRules() answers this per schema; this asserts it for
     * every migrated module at once, so the rule cannot be forgotten later.
     */
    foreach (ehSchemaModules() as $module => $parts) {
        $missing = ModuleSchema::missingRules($parts['schema']);

        expect($missing)->toBe([], "{$module} would have these dropped in silence by the settings endpoint: ".implode(', ', $missing));
    }
});

it('emits a settings rule in the shape the real rules list uses', function () {
    // settingRules() is only worth trusting if what it emits would actually fit
    // in SETTING_RULES, so it is compared against a real entry's shape.
    $sample = ModuleSchema::settingRules([
        'ehg_demo' => ['type' => 'text', 'label' => 'Demo', 'store' => ModuleSchema::STORE_ADMIN],
    ]);

    expect($sample)->toHaveKey('ehg_demo');
    expect($sample['ehg_demo'])->toBe(['text', 'Demo']);

    $real = AdminController::SETTING_RULES['store_name'];

    expect(count($sample['ehg_demo']))->toBe(count($real));
});

it('refuses a field the admin console could not draw', function () {
    // A schema is meant to be unable to describe an undrawable control, which
    // is only true if it says so out loud rather than defaulting to 'text'.
    expect(fn () => ModuleSchema::field('x', ['type' => 'wormhole', 'label' => 'X']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => ModuleSchema::field('x', ['type' => 'select', 'label' => 'X']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => ModuleSchema::field('x', ['type' => 'text', 'label' => 'X', 'store' => 'elsewhere']))
        ->toThrow(InvalidArgumentException::class);
});

/* ─────────────────── the control really renders, on the screen ───────────── */

function ehAsOwner(): void
{
    $owner = AdminUser::create([
        'name' => 'EH Owner',
        'email' => 'eh-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');
}

it('renders a real control for every field of every migrated schema', function () {
    /*
     * The endpoint, not the source. This is the difference between "a constant
     * lists this key" and "the owner is shown a box for it" — the two that came
     * apart on `reassure_auth_text` — and it is read off the JSON the console
     * actually receives.
     */
    ehAsOwner();

    foreach ([
        'pay_ship_rules' => '/admin-api/pay-ship-rules',
        'marketing_pixels' => '/admin-api/marketing-pixels',
    ] as $module => $url) {
        $body = test()->getJson($url)->assertOk()->json();

        $drawn = [];

        foreach ($body['tabs'] as $tab) {
            foreach ($tab['fields'] as $field) {
                $drawn[] = $field['key'];

                // A control with no type is a box the console cannot draw.
                expect($field['type'])->not->toBe('', "{$module}.{$field['key']} renders with no type");
                expect($field)->toHaveKey('value');
            }
        }

        $expected = array_keys(ModuleSchema::normalise(ehSchemaModules()[$module]['schema']));

        sort($drawn);
        sort($expected);

        expect($drawn)->toBe($expected, "{$module}: what the screen draws and what the schema stores do not match");
    }
});

it('gives the checkout legal notice both a control and a reader', function () {
    /*
     * The module ported in this lane, checked from both ends in one test
     * because that is the pairing the whole file is about — it would have been
     * possible to ship the partial with no control, or the control with no
     * partial, and each on its own looks finished from the side you are
     * standing on.
     */
    ehAsOwner();

    $body = test()->getJson('/admin-api/ecommerce')->assertOk()->json();

    $found = null;

    foreach ($body['tabs'] as $tab) {
        foreach ($tab['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['name'] === \App\Support\CheckoutLegalNotice::KEY) {
                    $found = $field;
                }
            }
        }
    }

    expect($found)->not->toBeNull('the legal notice has no control on Store → Ecommerce');
    expect($found['type'])->toBe('textarea');

    // The help has to name the mechanism that exists — the placeholders are the
    // whole interface, and a notice written without them has no links in it.
    foreach (array_keys(\App\Support\CheckoutLegalNotice::LINKS) as $token) {
        expect($found['help'])->toContain($token);
    }

    // And the reader half: something gates the storefront on the module.
    expect(ehReaders('legal_notice'))->not->toBe([], 'nothing on the storefront reads the legal_notice switch');
});

it('places every ecommerce field in a section rather than leaving it unreachable', function () {
    /*
     * The Ecommerce screen falls any unplaced field into an "other" section, so
     * a field cannot go missing — but a control that lands in a bucket named
     * after the tab is a control the owner cannot find by looking for what it
     * does. The legal notice got its own section for that reason, and this
     * keeps the next one honest.
     */
    ehAsOwner();

    $body = test()->getJson('/admin-api/ecommerce')->assertOk()->json();

    $orphaned = [];

    foreach ($body['tabs'] as $tab) {
        foreach ($tab['sections'] as $section) {
            if ($section['key'] !== 'other') {
                continue;
            }

            foreach ($section['fields'] as $field) {
                $orphaned[] = $tab['key'].'.'.$field['name'];
            }
        }
    }

    expect($orphaned)->toBe([], 'These Ecommerce fields are in no named section: '.implode(', ', $orphaned));
});
