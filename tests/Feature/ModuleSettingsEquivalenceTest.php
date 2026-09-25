<?php

/**
 * The proof that moving the three App\Support\*Settings classes onto the shared
 * cast moved nothing — Lane M3, round 3 of the per-module settings schema.
 *
 * ── WHY A SECOND FIXTURE AND NOT A ROW IN THE FIRST ─────────────────────────
 *
 * Lane M recorded 4,653 calls of thirteen modules' own `cast($key, $raw)` into
 * tests/Fixtures/module-cast-baseline.txt, and ModuleSchemaEquivalenceTest
 * replays them. That instrument could not see these three at all: they have no
 * `cast()` — they have a STATIC `normalise($key, $value)` over a positional
 * `[type, default, min, max]` schema whose types are `enum`/`int` rather than
 * `select`/`range`. Reflecting `cast` over ReviewSettings finds nothing. That
 * is precisely why round 2 could name them as a blocker and stop:
 *
 *   > label-less [type, default, min, max] schema, static normalise() rather
 *   > than cast(), enum/int types rather than select/range, and a THIRD
 *   > boolean dialect ('null' is false to them, true to ModuleSchema::castBool)
 *
 * So they got their own recording, in the same shape and read the same way.
 * 345 calls, driven off the PARENT revision before a line of these three
 * classes was touched, into tests/Fixtures/module-settings-baseline.txt.
 *
 * ── THE RESULT ──────────────────────────────────────────────────────────────
 *
 * 345 calls. 345 byte-identical. **NOT ONE EXEMPTION**, and none was needed —
 * the same result round 2 got, and it is the whole argument for the way this
 * lane works. Two axis VALUES were added to carry these three (`bool =>
 * 'words+null'` and `hex => 'expand'`) and one new axis (`markup`), rather than
 * three behaviours being folded into arms that nearly matched. Round 1 measured
 * what folding two nearly-matching arms costs: 322 answers moved.
 *
 * ── WHAT THE CORPUS ADDS OVER THE FIRST ONE, AND WHY IT HAD TO ──────────────
 *
 * The first corpus's bool inputs are [true, false, '1', '0', '', 'on', 'off',
 * 'no', 1, 0, null]. The literal string 'null' IS NOT IN IT. So the third
 * boolean dialect — the whole stated blocker — could not have been caught by
 * the existing fixture even if these classes had been in it: the one input the
 * two dialects disagree about was never driven. Tests\Support\SettingsCorpus
 * adds it, in both cases, along with 'true', 'FALSE', '  off ', 'yes' and '2'.
 *
 * MUTATIONS ACTUALLY RUN — quoted, not summarised, in
 * docs/M-PHASE3-SETTINGS-SCHEMA-ROUND-3.md §3.
 */

use App\Services\ModuleSchema;
use App\Support\CacheSettings;
use App\Support\ReviewBadgeSettings;
use App\Support\ReviewSettings;
use Tests\Support\SettingsCorpus;

it('answers every recorded settings call exactly as it did before the shared schema', function () {
    $recorded = file(base_path('tests/Fixtures/module-settings-baseline.txt'), FILE_IGNORE_NEW_LINES);
    $now = SettingsCorpus::rows();

    expect(count($now))->toBe(count($recorded), 'the corpus changed shape; the fixture no longer describes it');

    $moved = [];

    foreach ($recorded as $i => $before) {
        if ($before !== $now[$i]) {
            $moved[] = $before.'   ==>   '.$now[$i];
        }
    }

    /*
     * NO ALLOWED-DIFFERENCE LIST, deliberately. ModuleSchemaEquivalenceTest has
     * one because Lane M was fixing a live colour defect in the same change;
     * this round fixes nothing inside these three, so an exemption here would
     * be an exemption with no defect behind it. If this ever needs one, the
     * reason belongs beside it and it has to be narrower than a module.
     */
    expect($moved)->toBe([], "These settings would store a different value than they did before:\n".implode("\n", $moved));

    // A guard on the guard: an emptied fixture makes the loop pass by doing
    // nothing. 345 when recorded, across three classes and twenty-one keys.
    expect(count($recorded))->toBe(345, 'the recorded corpus changed size');
});

it('keeps the third boolean dialect a dialect rather than folding it', function () {
    /*
     * ── THE DEFECT THIS WOULD HAVE BEEN ─────────────────────────────────────
     *
     * All three classes read the literal four letters `null` as FALSE.
     * ModuleSchema's `words` arm reads it as TRUE, because it is a non-empty
     * string that is not in the list. That string is what a value that was SQL
     * NULL or PHP null comes back as once anything has exported it —
     * json_encode(null) is "null" — which is exactly the path a WordPress
     * import takes.
     *
     * Had it been folded, every one of these five review switches and the one
     * cache switch would have read ON where the shop reads them OFF today. The
     * cache one is the one that matters most: it decides whether a LIVE shop's
     * Cache-Control header changes, and that shop takes real orders.
     *
     * MUTATION: set ReviewSettings::POLICY's `bool` to 'words' and this goes
     * red with
     *   ReviewSettings folded 'null' to true — that is the third dialect gone
     * Set it to 'wurds' and field() throws instead, which is POLICY_VALUES
     * doing its job: a typo used to fall through to the word-aware arm.
     */
    foreach ([
        ReviewSettings::class => 'sr_show_stars',
        CacheSettings::class => CacheSettings::ENABLED,
        ReviewBadgeSettings::class => 'review_badge_heart',
    ] as $class => $key) {
        expect($class::normalise($key, 'null'))
            ->toBeFalse(class_basename($class)." folded 'null' to true — that is the third dialect gone");
        expect($class::normalise($key, 'NULL'))
            ->toBeFalse(class_basename($class)." reads 'null' case-sensitively — the fold is on the lowered word");
        // And the words every dialect agrees on still answer the same, so the
        // declaration has not quietly become a different list.
        expect($class::normalise($key, 'off'))->toBeFalse();
        expect($class::normalise($key, 'on'))->toBeTrue();
    }

    // The modules that were on `words` before this round must NOT have gained
    // the extra word. This is the half that stops the new value leaking.
    $field = ModuleSchema::normalise(['x' => ['type' => 'bool', 'label' => 'x']], ['bool' => 'words'])['x'];
    expect(ModuleSchema::cast($field, 'null'))->toBeTrue('`words` grew the null fold — that moves three migrated modules');
});

it('keeps the third hex dialect, because the fold that looks free breaks a screen', function () {
    /*
     * ── THE DEFECT THIS WOULD HAVE BEEN, AND IT IS NOT THE OBVIOUS ONE ──────
     *
     * ReviewBadgeSettings expands `#abc` to `#AABBCC`. ModuleSchema's `repair`
     * arm stores `#ABC`. Those are the SAME COLOUR to a browser, so folding
     * `expand` into `repair` looks like it costs nothing.
     *
     * It costs ReviewBadgeSettings::activeTheme(), two hundred lines below the
     * cast, which decides which preset a shop is on by string-comparing the
     * stored six digits against each theme's values. A shop whose colour was
     * stored as `#ABC` reads back as "Custom" while its badge draws exactly as
     * Classic does — a screen telling its owner something untrue about itself.
     *
     * MUTATION: set ReviewBadgeSettings::POLICY's `hex` to 'repair' and this
     * goes red with
     *   expected '#ABC' to be '#AABBCC'
     * and the activeTheme case below goes red with 'custom' for a shop on
     * Classic. Set it to 'strict' and `#abc` is refused to the default instead.
     */
    expect(ReviewBadgeSettings::normalise('review_badge_colour', '#abc'))->toBe('#AABBCC');
    expect(ReviewBadgeSettings::normalise('review_badge_colour', '#ABC'))->toBe('#AABBCC');
    // The `#` is required: `repair` would have accepted this and stored a
    // colour where the default has always stood.
    expect(ReviewBadgeSettings::normalise('review_badge_colour', 'e23a4e'))->toBe('#E8A33D');
    // Upper-cased: `strict` would have kept the lower case, and activeTheme()
    // compares strings.
    expect(ReviewBadgeSettings::normalise('review_badge_colour', '#e23a4e'))->toBe('#E23A4E');

    /*
     * The end the defect would actually have shown up at. A shop whose stored
     * colour is the THREE-DIGIT spelling of Classic's gold reads back as
     * Classic, because both sides of activeTheme()'s compare are expanded to
     * the one canonical form. Under `repair` the stored side would be '#E8A'
     * and the theme side '#E8A33D', and the screen would say Custom about a
     * shop drawing exactly Classic.
     */
    $shorthandGold = ReviewBadgeSettings::themes()['classic']['values'];
    $shorthandGold['review_badge_colour'] = ReviewBadgeSettings::normalise('review_badge_colour', '#E8A');

    expect($shorthandGold['review_badge_colour'])->toBe('#EE88AA');
    expect(ReviewBadgeSettings::activeTheme(ReviewBadgeSettings::themes()['classic']['values']))->toBe('classic');
});

it('strips tags on the two fields that always did, and on no others', function () {
    /*
     * The seventh axis. `markup => 'strip'` is a WORDING rule, not a safety one
     * — what makes these strings safe to print is Blade's escaping at the print
     * site — but it is behaviour these two fields already had, and rule 1 says
     * it may not move.
     *
     * MUTATION: drop `'markup' => 'strip'` from ReviewSettings::POLICY and the
     * recorded-call test above goes red with
     *   review_settings|sr_empty_text|text|"<b>x</b>"|'x'   ==>   ...|'<b>x</b>'
     */
    expect(ReviewSettings::normalise('sr_empty_text', '<b>hello</b>'))->toBe('hello');
    expect(ReviewBadgeSettings::normalise('review_badge_label', '<i>{n}</i> reviews'))->toBe('{n} reviews');

    // Nothing but tags empties, and then meets the `blank` policy rather than
    // storing a blank gap where the page has to say something.
    expect(ReviewSettings::normalise('sr_empty_text', '<b></b>'))
        ->toBe('Be the first to share your thoughts ♡');

    // And a module that did not ask for it does not get it. `home_ticker` is
    // free wording that has always stored what was typed.
    $field = ModuleSchema::normalise(['x' => ['type' => 'text', 'label' => 'x']])['x'];
    expect(ModuleSchema::cast($field, '<b>x</b>'))->toBe('<b>x</b>', 'markup=strip leaked to the default policy');
});

it('describes a stored value in its own module dialect, not in one of them', function () {
    /*
     * ── THE DEFECT ──────────────────────────────────────────────────────────
     *
     * ModuleSchema::describe() called castBool($value) flat, with no mode. Its
     * whole job is to say what a module is CURRENTLY DOING, and it answered in
     * `words` for every module — so it described a stored 'off' as "off" for the
     * ten modules whose cast reads that string as TRUE, and would have described
     * a stored 'null' as "on" for the three this round put on `words+null`.
     *
     * It is used by the guard and by no screen today, so nothing was wrong on a
     * page — which is exactly why it would have stayed wrong until the first
     * screen that summarised a module printed the opposite of what the module
     * does.
     *
     * MUTATION: revert the `bool` arm of describe() to `self::castBool($value)`
     * and this goes red with
     *   a `cast` module describes 'off' as the words dialect does
     *   Failed asserting that two strings are identical. -'on' +'off'
     */
    $words = ModuleSchema::normalise(['x' => ['type' => 'bool', 'label' => 'x']], ['bool' => 'words'])['x'];
    $cast = ModuleSchema::normalise(['x' => ['type' => 'bool', 'label' => 'x']], ['bool' => 'cast'])['x'];
    $nulls = ModuleSchema::normalise(['x' => ['type' => 'bool', 'label' => 'x']], ['bool' => 'words+null'])['x'];

    // Each one describes the string its own cast() would store.
    expect(ModuleSchema::describe($cast, 'off'))->toBe('on', "a `cast` module describes 'off' as the words dialect does");
    expect(ModuleSchema::describe($words, 'off'))->toBe('off');

    expect(ModuleSchema::describe($words, 'null'))->toBe('on');
    expect(ModuleSchema::describe($nulls, 'null'))->toBe('off', "a `words+null` module describes 'null' as the words dialect does");

    // And describe() agrees with cast() rather than merely differing from it.
    foreach ([$words, $cast, $nulls] as $field) {
        foreach ([true, false, '1', '0', '', 'on', 'off', 'no', 'null', 'yes'] as $input) {
            expect(ModuleSchema::describe($field, $input))
                ->toBe(ModuleSchema::cast($field, $input) ? 'on' : 'off', "describe disagreed with cast on ".var_export($input, true));
        }
    }
});

it('refuses a policy value cast() would not recognise', function () {
    /*
     * ── THE DEFECT: A TYPO IN A POLICY IS A MODULE ON THE OTHER DIALECT ─────
     *
     * Every axis is compared with `===` against a literal inside cast(), and
     * every comparison has an else. Before this round `'bool' => 'Cast'` did
     * not fail — it missed `=== 'cast'` and fell through to the word-aware arm,
     * so a module silently got the dialect it had not asked for. With two
     * values per axis that was a coin toss; this round put a THIRD value on two
     * of the axes, which makes it a coin toss the reader cannot resolve by
     * reading.
     *
     * MUTATION: delete the POLICY_VALUES check in ModuleSchema::field() and
     * this goes red with
     *   Failed asserting that exception of type "\InvalidArgumentException" is thrown
     * — the schema accepts the typo and 'Cast' quietly becomes 'words'.
     */
    expect(fn () => ModuleSchema::normalise(['x' => ['type' => 'bool', 'label' => 'x']], ['bool' => 'Cast']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => ModuleSchema::normalise(['x' => ['type' => 'colour', 'label' => 'x', 'hex' => 'expnd']]))
        ->toThrow(InvalidArgumentException::class);

    // And the eleven real values are all accepted, so the guard cannot have
    // been written narrower than the arms it guards.
    foreach (ModuleSchema::POLICY_VALUES as $axis => $values) {
        foreach ($values as $value) {
            $type = $axis === 'hex' ? 'colour' : 'text';
            expect(fn () => ModuleSchema::normalise(['x' => ['type' => $type, 'label' => 'x', $axis => $value]]))
                ->not->toThrow(InvalidArgumentException::class, "{$axis} => {$value} is an arm cast() has and field() refuses");
        }
    }
});
