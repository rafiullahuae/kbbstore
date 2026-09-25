<?php

declare(strict_types=1);

/**
 * Appearance → Homepage's section rows move onto ModuleSchema. (Lane P1)
 *
 * ── WHY THIS FILE IS AN EQUIVALENCE TEST AND NOT A FEATURE TEST ─────────────
 *
 * Nothing on the shop is supposed to change here. This screen is older than
 * App\Services\ModuleSchema and grew its own coercion — a pair of `(bool)`
 * casts in all(), the same pair again in save(), and
 * `GridSkins::exists($skin) ? $skin : $defaultSkin` written out twice. Four
 * copies of three rules, which is precisely the arrangement
 * docs/M-PHASE3-SETTINGS-SCHEMA.md §1 measured the cost of: the isValidHex
 * defect survived in four modules because there were four copies of the same
 * three lines and nothing tied them together.
 *
 * A third reader has now arrived — HomepageSections::proposing(), which renders
 * the homepage from an arrangement nobody has saved — and the only thing that
 * makes such a preview worth looking at is that it answers the way the save
 * would. Two copies of the rules make that a promise. One cast makes it a fact.
 *
 * So the migration is only correct if it moves NOTHING, and the old rules are
 * written out below, literally, as the expectation. Every case is driven
 * through the REAL round trip — written into the settings table, read back out
 * — rather than by calling a caster twice in memory, because the table
 * stringifies on the way through and that is exactly where an equivalence claim
 * would break. (That is round 3's own argument, in
 * docs/M-PHASE3-SETTINGS-SCHEMA-ROUND-3.md §2, applied one screen along.)
 *
 * ── THE CORPUS IS ADVERSARIAL ON PURPOSE ────────────────────────────────────
 *
 * The bool list carries the two strings the three dialects disagree about —
 * `'off'`, which the word-aware arm reads as FALSE and this screen has always
 * read as TRUE, and `'null'`, which `words+null` reads as false. Declaring
 * `bool => 'cast'` is what keeps both answering the way they always have, and
 * dropping that one word is mutation 1 in this lane's write-up.
 */

use App\Services\HomepageSections;
use App\Services\SettingsService;
use App\Support\GridSkins;

/**
 * Values a `homepage_sections` row can really carry.
 *
 * Not hypothetical: the four paths round 3 names — a hand-edit, an older build,
 * a WordPress import, a restored backup — all reach this table without passing
 * through any screen, and the preview endpoint added by this lane is a fifth
 * writer of the same shape, posted from a browser.
 */
function p1BoolCorpus(): array
{
    return [true, false, '1', '0', '', 'on', 'off', 'no', 'yes', 'null', 'NULL',
        1, 0, 2, '2', 0.0, '0.0', null, [], ['x']];
}

function p1SkinCorpus(): array
{
    return ['classic', 'luxe', 'soft', 'ribbon', '', 'CLASSIC', 'not-a-skin',
        '<script>alert(1)</script>', '" onload="alert(1)', null, 0, '0', ' classic '];
}

/** Plant one value into every field of every section, and read the table back. */
function p1Plant(mixed $bool, mixed $skin): array
{
    $settings = app(SettingsService::class);

    $settings->set('homepage_sections', collect(array_keys(HomepageSections::REGISTRY))
        ->mapWithKeys(fn ($key) => [$key => [
            'desktop' => $bool,
            'mobile' => $bool,
            'order' => 0,
            'skin' => $skin,
        ]])->all());

    SettingsService::forgetMemo();

    // What the TABLE now holds, which is what any reader really sees.
    return (array) $settings->get('homepage_sections');
}

/* ===========================================================================
 | §1 · Every answer, byte for byte, against the rules that were there
 |=========================================================================== */

it('answers every value on read exactly as the hand-written coercion did', function () {
    /*
     * THE ONE THING THIS CORPUS ACTUALLY CAUGHT, recorded because it would have
     * been a live defect: a stored `null`. Both old readers wrote
     * `$row['desktop'] ?? true`, so null has always meant SHOWN; the obvious
     * migration — array_key_exists() plus the field default — hands cast() a
     * literal null, and `(bool) null` is false. Thirty-four rows went dark in
     * this test's own output before HomepageSections::castRow() was changed to
     * `??`. Put array_key_exists() back and this is red.
     */
    $moved = [];
    $calls = 0;

    foreach (p1BoolCorpus() as $bool) {
        foreach (['classic', '', 'not-a-skin', null] as $skin) {
            $stored = p1Plant($bool, $skin);
            $all = app(HomepageSections::class)->all();

            foreach (HomepageSections::REGISTRY as $key => [, , $hasGrid, $defaultSkin]) {
                $row = (array) ($stored[$key] ?? []);

                // ── THE RULES AS THEY WERE, WRITTEN OUT ──────────────────────
                $wasDesktop = (bool) ($row['desktop'] ?? true);
                $wasMobile = (bool) ($row['mobile'] ?? true);
                $rawSkin = (string) ($row['skin'] ?? '');
                $wasSkin = $hasGrid ? (GridSkins::exists($rawSkin) ? $rawSkin : $defaultSkin) : null;

                $calls += 3;

                foreach ([['desktop', $wasDesktop], ['mobile', $wasMobile], ['skin', $wasSkin]] as [$field, $was]) {
                    if ($all[$key][$field] !== $was) {
                        $moved[] = $key.'.'.$field.' | '.var_export($field === 'skin' ? $skin : $bool, true)
                            .' | was '.var_export($was, true).' | now '.var_export($all[$key][$field], true);
                    }
                }
            }
        }
    }

    // No exemption list, deliberately: this lane fixes no defect inside these
    // three rules, and an exemption with no defect behind it is a blank cheque.
    expect($moved)->toBe([])
        ->and($calls)->toBeGreaterThan(4000);
});

it('answers every grid skin on read exactly as GridSkins::exists did', function () {
    $moved = [];

    foreach (p1SkinCorpus() as $skin) {
        $stored = p1Plant(true, $skin);
        $all = app(HomepageSections::class)->all();

        foreach (HomepageSections::REGISTRY as $key => [, , $hasGrid, $defaultSkin]) {
            $rawSkin = (string) (((array) ($stored[$key] ?? []))['skin'] ?? '');
            $was = $hasGrid ? (GridSkins::exists($rawSkin) ? $rawSkin : $defaultSkin) : null;

            if ($all[$key]['skin'] !== $was) {
                $moved[] = $key.' | '.var_export($skin, true).' | was '.var_export($was, true)
                    .' | now '.var_export($all[$key]['skin'], true);
            }
        }
    }

    expect($moved)->toBe([]);
});

it('stores every value on write exactly as the hand-written save did', function () {
    $moved = [];

    foreach (p1BoolCorpus() as $bool) {
        foreach (p1SkinCorpus() as $skin) {
            $payload = collect(array_keys(HomepageSections::REGISTRY))
                ->mapWithKeys(fn ($key) => [$key => ['desktop' => $bool, 'mobile' => $bool, 'skin' => $skin]])
                ->all();

            app(HomepageSections::class)->save($payload);
            SettingsService::forgetMemo();

            $stored = (array) app(SettingsService::class)->get('homepage_sections');

            // ── save()'s OWN RULES AS THEY WERE ─────────────────────────────
            $order = 0;
            $expected = [];

            foreach ($payload as $key => $row) {
                $hasGrid = HomepageSections::REGISTRY[$key][2];
                $defaultSkin = HomepageSections::REGISTRY[$key][3];
                $raw = (string) ($row['skin'] ?? '');

                $expected[$key] = [
                    'desktop' => (bool) ($row['desktop'] ?? true),
                    'mobile' => (bool) ($row['mobile'] ?? true),
                    'order' => (int) ($row['order'] ?? $order),
                    'skin' => $hasGrid ? (GridSkins::exists($raw) ? $raw : $defaultSkin) : null,
                ];

                $order++;
            }

            if ($stored !== $expected) {
                $moved[] = var_export($bool, true).' / '.var_export($skin, true);
            }
        }
    }

    expect($moved)->toBe([]);
});

/* ===========================================================================
 | §2 · And the vocabulary really is the shared one
 |=========================================================================== */

it('declares the three controls as ModuleSchema fields rather than as a fourth dialect', function () {
    expect(array_keys(HomepageSections::SECTION_SCHEMA))->toBe(['desktop', 'mobile', 'skin']);

    foreach (HomepageSections::SECTION_SCHEMA as $key => $field) {
        expect(array_key_exists($field['type'], App\Services\ModuleSchema::TYPES))
            ->toBeTrue($key.' declares a type ModuleSchema does not have');
    }

    // `skin` is one of ModuleSchema's OPTION_TYPES, which is what makes rule 5
    // — "a select stores one of its own options or the default" — checkable
    // from the schema rather than from whoever remembered to call exists().
    expect(App\Services\ModuleSchema::OPTION_TYPES)->toContain('skin');
});

it('picks the plain boolean dialect in writing, because the word-aware one would move the shop', function () {
    /*
     * The defect this pins: `'off'` is TRUE to `(bool)` and FALSE to
     * ModuleSchema::castBool(). A stored `'off'` is not hypothetical — this
     * screen's payload is JSON from a browser and the four import paths write
     * this table directly — and reading it the other way switches a section off
     * on a shop that had it on. Drop `bool => 'cast'` from SECTION_POLICY and
     * this is red.
     */
    expect(HomepageSections::SECTION_POLICY['bool'])->toBe('cast');

    p1Plant('off', 'classic');

    expect(app(HomepageSections::class)->all()['categories']['desktop'])->toBeTrue();
});

it('falls back to the section’s own default skin rather than refusing one it does not know', function () {
    /*
     * `invalid => default` restates GridSkins::exists(...) ? ... : $defaultSkin.
     * The strict end of the axis would answer null, and a null skin reaches
     * partials/home/grid.blade.php, which has to draw a card either way.
     */
    expect(HomepageSections::SECTION_POLICY['invalid'])->toBe('default');

    p1Plant(true, 'not-a-skin');

    $all = app(HomepageSections::class)->all();

    expect($all['bestsellers']['skin'])->toBe('luxe')
        ->and($all['flash']['skin'])->toBe('ribbon')
        ->and($all['categories']['skin'])->toBeNull();
});

it('keeps `order` out of the schema, because a position is not a control', function () {
    // Nothing on the screen types it: the console posts a SEQUENCE and the
    // server numbers it by position, and settle() rewrites it on every read.
    expect(HomepageSections::SECTION_SCHEMA)->not->toHaveKey('order');

    p1Plant(true, 'classic');

    expect(array_column(app(HomepageSections::class)->all(), 'order'))
        ->toBe(range(0, count(HomepageSections::REGISTRY) - 1));
});
