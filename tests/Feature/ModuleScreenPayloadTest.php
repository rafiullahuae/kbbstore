<?php

/**
 * What the module settings screens receive, pinned — Lane M.
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * Nine controllers each carried a byte-identical copy of the same fifteen-line
 * loop turning a module's SCHEMA and TABS constants into the JSON its screen
 * draws from. Phase 3's open item is to have that loop exist once —
 * ModuleSchema::tabs() — so a module can ship a setting and the screen draws it
 * without the renderer being edited.
 *
 * Replacing nine copies of a loop with one call is only safe if what comes out
 * is what came out before, and "I read both and they look the same" is how the
 * nine copies drifted in the first place. So the payload of every module screen
 * was captured from the endpoints BEFORE the migration —
 * tests/Fixtures/module-screen-payloads.json, 482 fields across 14 screens —
 * and this requires the endpoints to still produce it.
 *
 * ── THE ONE PERMITTED DIFFERENCE ────────────────────────────────────────────
 *
 * ModuleSchema::fields() emits `name` beside `key`, holding the same string;
 * the three modules migrated before this lane already received it and the nine
 * did not. It is additive and nothing reads it on these screens (every field
 * renderer in resources/views/admin/app.blade.php addresses `f.key`), so it is
 * allowed here and nothing else is.
 *
 * ── WHAT THIS CAUGHT ────────────────────────────────────────────────────────
 *
 * Not hypothetical. The first version of the migration passed each module's
 * validation `overrides()` into the render call as well, which is how
 * ProductStyles' card style and SectionDividers' section picker get checked
 * against option sets that live in other registries. That put both sets into
 * the payload's `options` key, where they had always been null — and the
 * divider registry's rows are `[label, description, bool, skin]`, not the
 * `value => label` shape a select needs, so the screen would have drawn a
 * picker out of nested arrays. This test failed on exactly those two fields and
 * nothing else, which is what sent the overrides back to the cast where they
 * belong.
 *
 * MUTATION ACTUALLY RUN: passing `SectionDividers::overrides()` back into the
 * ModuleSchema::tabs() call in SectionDividersApiController fails this with
 *   dividers.sections.options: None -> {'hero': [...], ...}
 */

use App\Models\AdminUser;
use Illuminate\Support\Facades\Hash;

/** Every module settings endpoint, as the console asks for it. */
function mScreenUrls(): array
{
    return [
        'cart-panel', 'account-panel', 'header', 'mobile-header', 'mobile-menu',
        'newsletter', 'product-labels', 'product-styles', 'dividers',
        'slim-footer', 'cart-page', 'checkout-page', 'pay-ship-rules',
        'marketing-pixels',
    ];
}

it('sends every module screen the payload it sent before the shared schema', function () {
    $owner = AdminUser::create([
        'name' => 'Payload Owner',
        'email' => 'payload-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');

    $expected = json_decode(file_get_contents(base_path('tests/Fixtures/module-screen-payloads.json')), true);

    expect($expected)->toBeArray()->not->toBeEmpty();

    $moved = [];
    $compared = 0;

    foreach (mScreenUrls() as $url) {
        $response = test()->getJson('/admin-api/'.$url);

        expect($response->status())->toBe(200, "/admin-api/{$url} did not answer");

        $now = $response->json();
        $was = $expected[$url] ?? null;

        expect($was)->not->toBeNull("no recorded payload for {$url}");

        // Everything beside the controls — the previews' own data, the module
        // flag, the currency — has to match outright.
        foreach ($was as $key => $value) {
            if ($key === 'tabs') {
                continue;
            }

            if ($value !== ($now[$key] ?? null)) {
                $moved[] = "{$url}.{$key} (outside tabs) changed";
            }
        }

        if (! isset($was['tabs'])) {
            continue;
        }

        expect(count($now['tabs'] ?? []))->toBe(count($was['tabs']), "{$url}: tab count changed");

        foreach ($was['tabs'] as $i => $tab) {
            $nowTab = $now['tabs'][$i];

            foreach (['key', 'label', 'description'] as $k) {
                if (($tab[$k] ?? null) !== ($nowTab[$k] ?? null)) {
                    $moved[] = "{$url}.tab{$i}.{$k} changed";
                }
            }

            expect(count($nowTab['fields']))->toBe(count($tab['fields']), "{$url}.{$tab['key']}: field count changed");

            foreach ($tab['fields'] as $j => $field) {
                $nowField = $nowTab['fields'][$j];
                $compared++;

                // `name` is the one addition this migration is allowed to make.
                $added = array_diff(array_keys($nowField), array_keys($field), ['name']);
                $lost = array_diff(array_keys($field), array_keys($nowField));

                foreach ($lost as $k) {
                    $moved[] = "{$url}.{$field['key']}: lost “{$k}”";
                }

                foreach ($added as $k) {
                    $moved[] = "{$url}.{$field['key']}: unexpected new key “{$k}”";
                }

                foreach ($field as $k => $value) {
                    if ($value !== ($nowField[$k] ?? null)) {
                        $moved[] = "{$url}.{$field['key']}.{$k}: ".json_encode($value).' => '.json_encode($nowField[$k] ?? null);
                    }
                }
            }
        }
    }

    expect($moved)->toBe([], "These module screens would now draw something different:\n".implode("\n", $moved));

    // A guard on the guard: if the fixture or the URL list is emptied, the loop
    // above passes by doing nothing. It compared 482 fields when written.
    expect($compared)->toBe(482, 'the number of controls drawn changed');
});
