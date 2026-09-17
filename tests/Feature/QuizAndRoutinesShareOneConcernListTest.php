<?php

declare(strict_types=1);

use App\Services\Translation\InterfaceStrings;
use App\Support\RoutineConcerns;

/**
 * The skin quiz and Build-my-routine ask about the SAME eight concerns — Lane FM.
 *
 * ── WHY THIS IS WORTH A TEST ────────────────────────────────────────────────
 *
 * resources/views/store/skin-quiz.blade.php declares `CONCERNS` in JavaScript
 * and App\Support\RoutineConcerns declares the same eight in PHP. Nothing links
 * them: one is a browser array the shopper clicks, the other is a slug map the
 * catalogue is tagged against. Two lists of the same thing, in two languages,
 * maintained by two lanes.
 *
 * The strings are load-bearing on the quiz side in a way that is easy to miss.
 * Api\QuizController stores them as the lead's `concerns`, the owner's Quiz
 * Leads screen lists them, and recommend() COMPARES them
 * (`state.concerns.includes('Hydration')`). So the day somebody wires "the quiz
 * said Acne & blemishes, show me that routine" — the obvious next step, and one
 * this lane deliberately did not take — the join is on those exact strings, and
 * a spelling that has drifted fails silently by matching nothing.
 *
 * ── WHAT IT COMPARES, AND WHY THAT DIRECTION ────────────────────────────────
 *
 * The quiz's array is read from the FILE, not from a copy of it kept here: a
 * test holding its own list of eight strings is a third list to keep in sync
 * and would pass while both of the real ones were wrong.
 *
 * Equality in both directions. A concern added to the quiz and not here is a
 * routine the shop cannot offer for something it asks shoppers about; one added
 * here and not to the quiz is a routine no answer can ever reach.
 */

/** The CONCERNS array as the quiz really declares it. @return list<string> */
function fmQuizConcerns(): array
{
    $source = file_get_contents(resource_path('views/store/skin-quiz.blade.php'));

    expect($source)->not->toBeFalse('could not read the skin quiz');

    // `const CONCERNS=[ … ];` — matched to the first closing bracket at the
    // start of a line, which is how that file formats every one of its tables.
    expect(preg_match('/const\s+CONCERNS\s*=\s*\[(.*?)\n\];/s', (string) $source, $m))
        ->toBe(1, 'the quiz no longer declares a CONCERNS array in the shape this reads');

    preg_match_all("/\{\s*v:\s*'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $found);

    return array_map(static fn (string $v) => stripcslashes($v), $found[1]);
}

it('asks about exactly the concerns Build-my-routine can build for', function () {
    $quiz = fmQuizConcerns();

    expect($quiz)->not->toBeEmpty('read no concerns out of the quiz at all');

    expect(array_values(RoutineConcerns::LIST))->toBe(
        $quiz,
        'The skin quiz and App\Support\RoutineConcerns no longer agree, in wording or in order.'
    );
});

it('has a keyed, translatable label for every concern and every role', function () {
    $keys = InterfaceStrings::flat();

    foreach (RoutineConcerns::slugs() as $slug) {
        $key = RoutineConcerns::labelKey($slug);

        expect($keys)->toHaveKey($key);

        /*
         * And the English behind the key is the quiz's own wording. The label a
         * shopper reads on /routines and the value the quiz posts are the same
         * sentence in English, so the Arabic has one thing to be a translation
         * OF — and a reviewer comparing the two screens sees one vocabulary.
         */
        expect($keys[$key])->toBe(RoutineConcerns::LIST[$slug]);
    }

    foreach (\App\Support\RoutineRoles::ORDER as $role) {
        expect($keys)->toHaveKey(\App\Support\RoutineRoles::labelKey($role))
            ->toHaveKey(\App\Support\RoutineRoles::helpKey($role));

        // The back office is NOT translated — InterfaceStrings' header says so —
        // but it must still have a word for every role, or the admin's role
        // dropdown prints a slug.
        expect(\App\Support\RoutineRoles::ADMIN_LABELS)->toHaveKey($role);
    }
});
