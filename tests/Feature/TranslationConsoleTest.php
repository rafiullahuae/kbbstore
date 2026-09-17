<?php

declare(strict_types=1);

/*
 * The Translation console (T1b, Lane FC).
 *
 * Nine endpoints existed under /admin-api/translations/* and nothing in the
 * admin called any of them. What is pinned here is the screen that does, and
 * the four things about it that are easy to get wrong and impossible to notice:
 *
 *   1. a progress figure that rounds ITSELF to "done" one string short;
 *   2. a cost shown beside a button that is not the cost of pressing it;
 *   3. a control drawn for somebody whose role will answer 403;
 *   4. an explanatory sentence typed into the screen instead of read from the
 *      server, which goes stale silently the first time the behaviour changes.
 *
 * Nothing here makes a network call. The only provider that is ever available
 * is a fake, so the suite costs nothing to run and needs no key.
 */

use App\Models\AdminUser;
use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationEstimate;
use App\Services\Translation\TranslationProvider;
use App\Services\Translation\TranslationStore;
use App\Support\AdminCapabilities;
use App\Support\Locale;
use App\Support\TranslationConsole;
use Illuminate\Support\Facades\DB;

function fcAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'FC '.$role,
        'email' => 'fc-'.uniqid().'@example.com',
        'password' => bcrypt('secret'),
        'role' => $role,
    ]);
}

/** Publish $count interface strings straight into the table, cheaply. */
function fcTranslateInterface(int $count): array
{
    $keys = array_keys(InterfaceStrings::flat());
    $take = array_slice($keys, 0, $count);

    $now = now();
    $rows = array_map(static fn (string $k): array => [
        'locale' => 'ar',
        'group' => Translation::GROUP_UI,
        'item_id' => 0,
        'field' => $k,
        'value' => 'ARABIC',
        'status' => Translation::STATUS_PUBLISHED,
        'source' => Translation::SOURCE_MANUAL,
        'created_at' => $now,
        'updated_at' => $now,
    ], $take);

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('translations')->insert($chunk);
    }

    TranslationStore::flush();

    return $keys;
}

function fcArabicOn(bool $rtl = true): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => $rtl ? '1' : '0', 'autoload' => true]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

/* ══════════════════ 1. the figure that said "done" one string early ══════════════════ */

it('refuses to report 100% while a single string is still untranslated', function () {
    /*
     * THE CASE, MEASURED RATHER THAN IMAGINED.
     *
     * The interface has 752 strings. Translate 751 of them and the old
     * arithmetic — `(int) round(751 / 752 * 100)` — is 100, because 99.867
     * rounds up. The console colours a pill green at exactly 100, so the screen
     * whose entire job is answering "is the Arabic finished?" answered YES
     * while a shopper was still being shown an English word, and there was
     * nothing on the screen to suggest otherwise.
     *
     * The fix is the one the free-delivery bar already carries one screen over
     * (CartService: `$toFree === 0 ? 100 : min(99, ...)`): a milestone reached
     * by rounding has not been reached, so 100 is reserved for actually
     * complete and round() is left alone underneath it.
     */
    $keys = array_keys(InterfaceStrings::flat());
    $total = count($keys);

    expect($total)->toBeGreaterThan(200, 'the rounding this guards only bites on a large denominator');

    fcTranslateInterface($total - 1);

    $ui = TranslationEstimate::progress('ar')['areas'][Translation::GROUP_UI];

    expect($ui['total'])->toBe($total)
        ->and($ui['translated'])->toBe($total - 1)
        // The old code returned 100 here. That is the whole defect.
        ->and($ui['percent'])->toBe(99)
        ->and($ui['percent'])->toBeLessThan(100);
});

it('reports 100% the moment the last string lands, and not before', function () {
    $keys = array_keys(InterfaceStrings::flat());

    fcTranslateInterface(count($keys));

    $ui = TranslationEstimate::progress('ar')['areas'][Translation::GROUP_UI];

    expect($ui['translated'])->toBe($ui['total'])
        ->and($ui['percent'])->toBe(100);
});

it('refuses to report 0% once any work has been done', function () {
    /*
     * The same lie at the other end, and the reason the fix clamps both.
     * 1 of 752 is 0.13%, which rounds to 0, and 0 on this screen means "not
     * started" — so an hour's typing reported as nothing done.
     */
    fcTranslateInterface(1);

    $ui = TranslationEstimate::progress('ar')['areas'][Translation::GROUP_UI];

    expect($ui['translated'])->toBe(1)
        ->and($ui['percent'])->toBe(1)
        ->and($ui['percent'])->toBeGreaterThan(0);
});

it('calls an area with nothing in it complete rather than untouched', function () {
    // There are no journal posts in a fresh install. "0% translated, forever"
    // is a job that does not exist; there is nothing left to do in it.
    $areas = TranslationEstimate::progress('ar')['areas'];

    expect($areas['posts']['total'])->toBe(0)
        ->and($areas['posts']['percent'])->toBe(100);
});

it('applies the same rule to the whole-shop figure as to each area', function () {
    /*
     * The headline percentage is the one the owner reads first, and it is
     * computed separately from the per-area figures — so it needed the same fix
     * and could have been left behind by it.
     *
     * The catalogue is emptied first so the interface strings are the entire
     * denominator and the shop can be put exactly one field short of finished.
     * With the seeded catalogue present the denominator is 867 and no amount of
     * interface work reaches the boundary this is about.
     */
    foreach (TranslationEstimate::CONTENT as $class => $columns) {
        DB::table((new $class)->getTable())->delete();
    }

    $keys = array_keys(InterfaceStrings::flat());

    fcTranslateInterface(count($keys) - 1);

    $progress = TranslationEstimate::progress('ar');

    expect($progress['total'])->toBe(count($keys))
        ->and($progress['translated'])->toBe($progress['total'] - 1)
        ->and($progress['percent'])->toBe(99);
});

/* ══════════════════ 2. the cost shown is the cost of pressing the button ══════════════════ */

it('quotes the run a figure the run itself will accept', function () {
    /*
     * The estimate's headline is THE WHOLE SHOP. The button sends a batch:
     * one group, at most `limit` fields, and only the fields a machine should
     * see at all — every product description is excluded as HTML. Those are
     * three reasons for the two numbers to differ, and machineRun() refuses a
     * confirm_characters that does not match what it is about to send.
     *
     * So a screen that showed the headline beside the button would fail that
     * check on every press, and would have been quoting a price for something
     * other than the thing the button does.
     */
    \Tests\Support\TranslationAdminRoutes::mount();

    $body = test()->actingAs(fcAdmin(), 'admin')
        ->getJson('/admin-api/translations/estimate?locale=ar&limit=5&group=ui')
        ->assertOk()
        ->json();

    expect($body)->toHaveKey('run')
        ->and($body['run']['limit'])->toBe(5)
        ->and($body['run']['group'])->toBe('ui')
        ->and($body['run']['fields'])->toBe(5)
        ->and($body['run']['characters'])->toBeGreaterThan(0)
        ->and($body['run']['confirm_characters'])->toBe($body['run']['characters'])
        // and it is a batch, not the shop: the shop is far larger.
        ->and($body['run']['characters'])->toBeLessThan($body['characters']);

    // The number on the screen is the number that gets authorised.
    app()->instance(TranslationProvider::class, new class implements TranslationProvider
    {
        public function name(): string
        {
            return 'Fake';
        }

        public function available(): bool
        {
            return true;
        }

        public function translate(array $texts, string $from, string $to): array
        {
            return array_map(static fn (string $t): string => 'AR::'.$t, $texts);
        }
    });

    test()->actingAs(fcAdmin(), 'admin')
        ->postJson('/admin-api/translations/machine/run', [
            'locale' => 'ar',
            'limit' => 5,
            'group' => 'ui',
            'confirm_characters' => $body['run']['confirm_characters'],
        ])
        ->assertOk();

    expect(Translation::query()->where('status', Translation::STATUS_DRAFT)->count())->toBe(5);
});

it('states the currency of the cost, and does not put it through Money', function () {
    /*
     * This figure is Google's published USD list price. This shop's money is
     * dirhams, and whole dirhams from this cycle onward — so a console that
     * formatted it would sooner or later format it the way it formats every
     * other number on the site and print a dirham sign on a dollar amount, or
     * round the cents off a figure whose whole purpose is to be small and
     * precise before anything is spent.
     */
    \Tests\Support\TranslationAdminRoutes::mount();

    $body = test()->actingAs(fcAdmin(), 'admin')
        ->getJson('/admin-api/translations/estimate?locale=ar')
        ->assertOk()
        ->json();

    expect($body['currency'])->toBe('USD')
        ->and($body['usd_display'])->toStartWith('USD ')
        ->and($body['usd_display'])->not->toContain('د.إ')
        ->and($body['run']['usd_display'])->toStartWith('USD ')
        ->and($body['free_tier_note'])->toContain('USD');

    expect(TranslationConsole::costDisplay(1.5))->toBe('USD 1.50');
});

/* ══════════════════ 3. a control nobody can use is not drawn ══════════════════ */

it('tells the screen which levers this admin may actually move', function () {
    \Tests\Support\TranslationAdminRoutes::mount();

    $owner = test()->actingAs(fcAdmin('owner'), 'admin')
        ->getJson('/admin-api/translations/settings')->assertOk()->json();

    expect($owner['can'])->toBe([
        'settings' => true, 'machine_run' => true, 'strings' => true, 'machine_field' => true,
    ])->and($owner['capability_note'])->toBeNull();

    $editor = test()->actingAs(fcAdmin('editor'), 'admin')
        ->getJson('/admin-api/translations/settings')->assertOk()->json();

    // The split already in AdminCapabilities::RULES, reported rather than
    // restated: two owner-only levers, the rest editor-and-up.
    expect($editor['can'])->toBe([
        'settings' => false, 'machine_run' => false, 'strings' => true, 'machine_field' => true,
    ]);

    expect($editor['capability_note'])->toBeString()
        ->and($editor['capability_note'])->toContain('API key')
        ->and($editor['capability_note'])->toContain('batch')
        // and it says what they CAN do, because a screen that only lists
        // refusals reads as a broken console.
        ->and($editor['capability_note'])->toContain('publish translations');
});

it('says a control is unavailable only where the endpoint really would refuse', function () {
    /*
     * The screen and the guard must agree, or one of them is lying. Asked of
     * AdminCapabilities by the endpoints' own method and path, so this cannot
     * drift the way a second hand-written list of capability names would.
     */
    \Tests\Support\TranslationAdminRoutes::mount();

    $editor = fcAdmin('editor');

    $can = test()->actingAs($editor, 'admin')
        ->getJson('/admin-api/translations/settings')->json('can');

    expect($can['settings'])->toBeFalse();

    // And the endpoint the screen would have posted to really does refuse.
    test()->actingAs($editor, 'admin')
        ->postJson('/admin-api/translations/settings', ['arabic_enabled' => true])
        ->assertStatus(403);

    test()->actingAs($editor, 'admin')
        ->postJson('/admin-api/translations/machine/run', ['locale' => 'ar', 'confirm_characters' => 0])
        ->assertStatus(403);

    // Arabic did not come on behind a refused request.
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    Setting::flushMap();

    expect(Locale::enabled('ar'))->toBeFalse();
});

it('keeps the capability answers tied to the map that enforces them', function () {
    // Not a copy of the rules: the same lookup, by the same method and path.
    expect(AdminCapabilities::forPath('POST', 'admin-api/translations/settings'))->toBe('store.settings')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/translations/machine/run'))->toBe('store.settings')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/translations'))->toBe('content.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/translations/machine/field'))->toBe('content.manage');

    expect(AdminCapabilities::roleCan('editor', 'store.settings'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('editor', 'content.manage'))->toBeTrue();
});

/* ══════════════════ 4. every sentence comes from the server ══════════════════ */

it('says what a bare / serves, from the switch rather than from a belief about it', function () {
    \Tests\Support\TranslationAdminRoutes::mount();

    $off = test()->actingAs(fcAdmin(), 'admin')
        ->getJson('/admin-api/translations/settings')->assertOk()->json();

    expect($off['default_locale'])->toBe('en')
        ->and($off['default_locale_name'])->toBe('English')
        ->and($off['default_locale_note'])->toContain('not switchable')
        ->and($off['root_serves'])->toContain('does not exist')
        ->and($off['root_serves'])->toContain('404');

    fcArabicOn();

    $on = test()->actingAs(fcAdmin(), 'admin')
        ->getJson('/admin-api/translations/settings')->assertOk()->json();

    // The SAME field, a different sentence, because the state is different.
    expect($on['root_serves'])->not->toBe($off['root_serves'])
        ->and($on['root_serves'])->toContain('/ar/')
        ->and($on['root_serves'])->toContain('301');
});

it('explains a missing API key as a feature that is off, not a screen that is broken', function () {
    \Tests\Support\TranslationAdminRoutes::mount();

    $body = test()->actingAs(fcAdmin(), 'admin')
        ->getJson('/admin-api/translations/settings')->assertOk()->json();

    expect($body['has_api_key'])->toBeFalse()
        ->and($body['provider_available'])->toBeFalse()
        ->and($body['provider_note'])->toContain('by hand')
        ->and($body['provider_note'])->toContain('costs nothing');

    // And the estimate — which the machine screen opens with — says the same
    // thing rather than rendering a blank panel.
    expect(test()->actingAs(fcAdmin(), 'admin')
        ->getJson('/admin-api/translations/estimate?locale=ar')->json('provider_note'))
        ->toContain('by hand');
});

it('points at the screen that actually exists when it tells you where the key lives', function () {
    /*
     * The server names a menu row in three refusals. A row renamed without
     * these moving sends the owner looking for a screen that is not there —
     * which is a small defect that reads exactly like a missing feature, and
     * that is how the coupon editor was concluded never to have shipped.
     */
    \Tests\Support\TranslationAdminRoutes::mount();

    $message = test()->actingAs(fcAdmin(), 'admin')
        ->postJson('/admin-api/translations/machine/field', ['text' => 'Add to bag', 'locale' => 'ar'])
        ->assertStatus(409)
        ->json('message');

    $label = 'Translation → Language settings';

    expect($message)->toContain($label);

    $blocks = (string) file_get_contents(base_path('docs/T1B-ADMIN-APP-BLOCKS.md'));

    expect($blocks)->toContain("'tr-settings','Language settings'")
        ->and($blocks)->toContain("'tr-settings':['Translation','Language settings']");
});

/* ══════════════════ 5. the screen itself ══════════════════ */

function fcScreen(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/translation-screens.blade.php'));
}

it('ships a screen that calls every endpoint the console was missing', function () {
    $src = fcScreen();

    foreach ([
        '/translations/settings',
        '/translations/progress',
        '/translations/estimate',
        '/translations/publish',
        '/translations/machine/field',
        '/translations/machine/run',
    ] as $path) {
        // str_contains and not toContain(): that matcher is VARIADIC, so a
        // second argument is a second needle and the message would be asserted
        // as part of the file.
        expect(str_contains($src, $path))->toBeTrue("the console never calls {$path}");
    }

    // The list and the writer share a path, so they are matched by their verbs.
    expect($src)->toContain("'/translations?locale=' + LOCALE")
        ->and($src)->toContain("api('/translations', 'POST'");
});

it('prints the server\'s sentences instead of keeping its own copies', function () {
    /*
     * THE RULE THIS WHOLE SCREEN TURNS ON. A sentence written into a console is
     * a second copy of an answer, and it is wrong the first time the behaviour
     * changes — silently, because nothing compares the two. This screen's only
     * job is explaining state, so every explanation is read from the payload.
     */
    $src = fcScreen();

    foreach ([
        'root_serves',
        'default_locale_note',
        'provider_note',
        'capability_note',
        'free_tier_note',
        'warning',
    ] as $field) {
        expect(str_contains($src, $field))->toBeTrue("the screen never prints the server's {$field}");
    }

    // And it does not carry its own copy of the two the server computes.
    expect($src)->not->toContain('left-to-right layout. That is fine while')
        ->and($src)->not->toContain('first 500,000 characters');
});

it('colours the done pill at 100 and nowhere else', function () {
    // The other half of the percentage fix. Reserving 100 on the server is
    // pointless if the screen rounds it back up, or greens the pill at 99.
    $src = fcScreen();

    expect($src)->toContain('if (percent >= 100) return \' is-done\';')
        ->and($src)->toContain('if (percent > 0) return \' is-part\';')
        ->and($src)->not->toContain('percent >= 99');
});

it('draws no control that the signed-in role would be refused', function () {
    $src = fcScreen();

    // Every owner-only control is behind the capability the endpoint enforces.
    expect($src)->toContain("can('settings')")
        ->and($src)->toContain("can('machine_run')")
        ->and($src)->toContain("can('strings')")
        ->and($src)->toContain("can('machine_field')")
        // and the switches are rendered disabled rather than omitted, so the
        // state is still readable by somebody who cannot change it.
        ->and($src)->toContain("togHTML('arabic_enabled', s.arabic_enabled, editable)")
        ->and($src)->toContain("togHTML('rtl_enabled', s.rtl_enabled, editable)");
});

it('survives being typed in, which is the only way 752 rows is a tool', function () {
    /*
     * MEASURED IN CHROMIUM, NOT REASONED ABOUT.
     *
     * Every render replaces the whole of #content, and a render fires when each
     * request starts and finishes. The search is the SERVER'S — it has to be,
     * or "only what is untranslated" and the row cap would be applied to a page
     * rather than to the area — so every keystroke schedules a fetch and every
     * fetch repaints.
     *
     * With the caret restored only for the Arabic boxes, typing "basket" into
     * the search box left "b" in it and focus on <body>: the debounce fired
     * after the first letter and the element the owner was typing into stopped
     * existing. He would conclude the search box was broken, on the screen he
     * is meant to spend fifty-five hours in.
     *
     * So the restoration covers whatever input or textarea inside #content had
     * focus, found again by selector because innerHTML destroyed the node.
     */
    $src = fcScreen();

    expect($src)->toContain("active.tagName === 'TEXTAREA' || active.tagName === 'INPUT'")
        ->and($src)->toContain("(active.id ? '#' + active.id : null)")
        ->and($src)->toContain('box.querySelector(keep.selector)')
        // and the search really is the server's, or none of the above matters.
        ->and($src)->toContain("'&q=' + encodeURIComponent(filters.q)")
        ->and($src)->toContain('qTimer = setTimeout(loadRows,');
});

it('spends money only after showing the figure it is about to spend', function () {
    $src = fcScreen();

    // Two presses, and the second posts the server's own count of the pending
    // set rather than anything this screen worked out.
    expect($src)->toContain("confirm_characters: confirming.confirm_characters")
        ->and($src)->toContain("data-tr-act=\"run-confirm\"")
        ->and($src)->toContain('Nothing has been sent yet.');
});

/* ══════════════════ 6. the blocks the integrator applies ══════════════════ */

it('keeps the handover document and the applied console in step', function () {
    /*
     * The lane cannot edit resources/views/admin/app.blade.php, so the screen
     * is delivered as anchor/replacement blocks. An anchor another lane has
     * since edited is a block that cannot be applied mechanically, and the
     * failure would surface as a half-wired console rather than as an error.
     */
    $doc = (string) file_get_contents(base_path('docs/T1B-ADMIN-APP-BLOCKS.md'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    $sections = preg_split('/^## Block /m', $doc);
    array_shift($sections);

    expect($sections)->toHaveCount(4);

    foreach ($sections as $i => $section) {
        preg_match_all('/```\n(.*?)```/s', $section, $fences);

        expect($fences[1])->toHaveCount(2, 'block '.($i + 1).' is not an anchor and a replacement');

        $anchor = rtrim($fences[1][0], "\n");
        $replacement = rtrim($fences[1][1], "\n");

        /*
         * APPLIED, so the console now carries each REPLACEMENT and no longer
         * carries the bare anchor. Before the merge this asserted the opposite.
         * The document is kept rather than deleted because it is the record of
         * what was applied and why — and because a later lane that needs to
         * re-apply these blocks to a rebuilt console needs them to still be
         * true of it.
         */
        expect(trim($replacement))->not->toBe('', 'block '.($i + 1).' has an empty replacement');

        expect(substr_count($app, $replacement))->toBe(
            1,
            'block '.($i + 1).' is not applied to app.blade.php exactly once'
        );
    }

    /*
     * Applied, not merely counted. The four together have to leave a console
     * that agrees with itself: a sidebar group, a breadcrumb and title for each
     * of its four ids, the deep-link replay armed for all four, and the partial
     * that draws them loaded. Three of those four alone are a silent failure —
     * rows that open the dashboard under their own heading — which is what
     * AdminNavAndIdsTest checks once the integrator has applied them.
     */
    expect($app)->toContain("{sec:'Translation',items:[")
        ->and($app)->toContain("@include('admin.partials.translation-screens')");

    foreach (['tr-settings', 'tr-progress', 'tr-strings', 'tr-machine'] as $id) {
        expect(substr_count($app, "'".$id."':['Translation',"))->toBe(1, $id.' has no TITLES entry');
        expect(str_contains($app, "'".$id."'"))->toBeTrue($id.' is not in the applied file');
    }

    /*
     * THE FOUR IDS ARE ARMED — asserted about the four, not about the whole set.
     *
     * This line used to pin the set literal character for character, which made
     * it a lock on every OTHER screen in the console: Lane FO's Appearance →
     * Homepage content joined LATE_RENDERED for exactly the same reason and by
     * exactly the same rule, and this assertion went red over a change that has
     * nothing to do with translation. A guard that fails when somebody else does
     * the right thing teaches people to edit the guard, which is how a guard
     * stops guarding.
     *
     * It is not weaker. The set is located first and the match is asserted, so
     * the check cannot pass by finding nothing (risk ▒36), and each of the four
     * ids is then required inside it — remove any one and this still goes red.
     */
    expect(preg_match('/const LATE_RENDERED\s*=\s*new Set\(\[([^\]]*)\]\);/', $app, $m))
        ->toBe(1, 'LATE_RENDERED could not be found in the applied console at all');

    foreach (['tr-settings', 'tr-progress', 'tr-strings', 'tr-machine'] as $id) {
        expect(str_contains($m[1], "'".$id."'"))
            ->toBeTrue($id.' is not armed in LATE_RENDERED, so a deep link to it opens the dashboard');
    }
});

/*
 * FLIPPED AT MERGE, and deliberately not deleted.
 *
 * While the lane was in flight this asserted that app.blade.php had NOT been
 * touched — the blocks are a document, not an edit, and a lane editing that
 * file would collide with whichever other lane owns it. That guard did its job
 * and is now false by design: the integrator has applied the four blocks.
 *
 * What replaces it is the assertion that matters on this side of the merge.
 * Three of the four blocks alone leave a console that LOOKS wired — a sidebar
 * group whose rows open the dashboard under their own heading — which is the
 * silent failure the handover document warns about. So all four halves are
 * checked together.
 */
it('wires all four halves of the Translation console, not three', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    /*
     * str_contains() rather than toContain(): Pest's toContain is VARIADIC over
     * needles, so a second argument meant as a failure message is asserted as
     * another needle — the file was being required to contain the words "the
     * sidebar group is missing". expect(bool) takes the message properly.
     */
    expect(str_contains($app, "sec:'Translation'"))
        ->toBeTrue('the sidebar group is missing');

    expect(str_contains($app, 'translation-screens'))
        ->toBeTrue('the partial that draws the screens is not included');

    foreach (['tr-settings', 'tr-progress', 'tr-strings', 'tr-machine'] as $id) {
        // A title, so the row has a breadcrumb of its own rather than the
        // dashboard's — which is the silent half of a three-of-four apply.
        expect(str_contains($app, "'".$id."':['Translation',"))
            ->toBeTrue("the console has no breadcrumb for {$id}");
    }
});

it('ships nothing switched on', function () {
    /*
     * The whole of the owner's requirement, and the reason this package is safe
     * to apply to a live shop: no row is seeded, SettingsService::get() returns
     * its default only when a row is ABSENT, so absent means off and /ar is a
     * 404 the moment this lands exactly as it was before.
     */
    expect(Setting::query()->whereIn('key', [
        Locale::SETTING_ENABLED,
        Locale::SETTING_RTL,
        'translation_highlight_missing',
    ])->count())->toBe(0);

    expect(Locale::enabled('ar'))->toBeFalse()
        ->and(Locale::enabledCodes())->toBe(['en']);

    test()->get('/ar/my-wishlist/')->assertNotFound();

    $migration = (string) file_get_contents(
        base_path('database/migrations/2026_11_13_000000_clear_caches_translation_console.php')
    );

    // And the migration that ships with it clears caches and seeds nothing.
    expect($migration)->not->toContain('updateOrCreate')
        ->and($migration)->not->toContain('Schema::')
        ->and($migration)->toContain("storage_path('framework/views/*.php')");
});

/*
|------------------------------------------------------------------------------
| HOW TO GET A KEY
|------------------------------------------------------------------------------
|
| A field asking for a credential from somebody else's console, six clicks deep,
| with no instructions, is a dead end — and it is the one field on this screen
| the owner cannot fill in by thinking harder.
*/

it('tells the owner how to get a key, with links he can actually press', function () {
    $guide = \App\Support\TranslationConsole::setupGuide();

    expect($guide['steps'])->not->toBeEmpty();

    foreach ($guide['steps'] as $i => $step) {
        expect(trim($step['title']))->not->toBe('', "step {$i} has no title");
        expect(trim($step['body']))->not->toBe('', "step {$i} has no body");

        if ($step['url'] !== null) {
            // https only, and Google's own hosts only. A setup guide is exactly
            // the place a wrong link costs the most: the owner is being asked
            // to create a credential, so a link to somewhere that merely LOOKS
            // like Google is the shape of a phishing page.
            expect($step['url'])->toStartWith('https://');
            expect(
                str_starts_with($step['url'], 'https://console.cloud.google.com/')
                || str_starts_with($step['url'], 'https://cloud.google.com/')
            )->toBeTrue("step {$i} links somewhere that is not Google: {$step['url']}");
        }
    }
});

it('names the credential this shop can actually use, not the one it cannot', function () {
    /*
     * GoogleProvider calls the v2 endpoint with the key as a query parameter,
     * so what the owner needs is an API KEY. Google's Credentials screen offers
     * a service account and a JSON file just as prominently, and that is the
     * wrong kind — it is what v3 wants and it will not work here. The guide has
     * to say so, or the likeliest wrong turn is the one the console nudges him
     * towards.
     */
    $text = json_encode(\App\Support\TranslationConsole::setupGuide());

    expect($text)->toContain('API key');
    expect(strtolower((string) $text))->toContain('service account');

    // And the endpoint really is the one this claim rests on.
    $provider = (string) file_get_contents(app_path('Services/Translation/GoogleProvider.php'));
    expect($provider)->toContain('/language/translate/v2');
});

it('says the free allowance is a credit, because that is what Google bills', function () {
    /*
     * Google's published pricing: the first 500,000 characters a month are
     * "Free (applied as a $10 credit every month)". A credit is not a cap —
     * billing must be set up before a single free character is translated, and
     * going past the allowance charges the card rather than refusing.
     *
     * An owner told "the first 500,000 are free" reasonably concludes he cannot
     * be charged by accident. He can. This is the sentence that has to carry
     * that, and the guide's billing step depends on the same fact.
     */
    $note = \App\Support\TranslationConsole::freeTierNote();

    expect(strtolower($note))->toContain('credit');
    expect($note)->not->toContain('at no charge');

    $guide = json_encode(\App\Support\TranslationConsole::setupGuide());
    expect(strtolower((string) $guide))->toContain('billing');
});

it('serves the guide from the settings endpoint whether or not a key is saved', function () {
    // Sent from BOTH payloads: the screen repaints from the save response, and
    // a guide that vanished on save would disappear at the moment a rejected
    // key most needs explaining.
    $body = test()->actingAs(fcAdmin(), 'admin')
        ->getJson('/admin-api/translations/settings')
        ->assertOk()
        ->json();

    expect($body)->toHaveKey('setup_guide');
    expect($body['setup_guide']['steps'])->not->toBeEmpty();
});
