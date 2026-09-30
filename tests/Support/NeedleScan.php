<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The survey's one switch, and it is OFF unless a scan turned it on.
 *
 * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────────
 *
 * tools/plc-needle-scan.php measures, for every containment assertion in the
 * suite, how many times its needle occurs in the haystack it ran against — the
 * defect being an assertion that is true for a reason that has nothing to do
 * with the code under test. Pest expectations are pipeable, so toContain and
 * toMatch need nothing from the suite itself.
 *
 * `assertSee` is the exception. It is a method on Illuminate\Testing\
 * TestResponse which hands its haystack to PHPUnit's static assertion through
 * TestResponseAssert — a final-in-spirit `@internal` class with a private
 * constructor and no injectable factory. There is exactly one documented seam
 * in front of it: MakesHttpRequests::createTestResponse(), which the suite's own
 * TestCase may override.
 *
 * ── AND IT COSTS ONE STATIC READ WHEN NOBODY IS SCANNING ────────────────────
 *
 * The sink is null on every ordinary run, `armed()` is a null check, and
 * Tests\TestCase returns exactly what its parent built. Nothing is subclassed,
 * nothing is recorded, and no framework internals are copied anywhere: the
 * recording response is built from `$baseResponse` and handed `$exceptions`,
 * both of which are public API on TestResponse.
 *
 * That is the whole reason this shape was chosen over patching vendor or
 * reimplementing createTestResponse()'s body — a copy of that body would be
 * framework internals in the suite's base class, and it would rot silently on
 * the next Laravel upgrade.
 */
final class NeedleScan
{
    /** @var null|callable(string, string, bool): void */
    private static $sink = null;

    /**
     * Point the survey at a recorder. Called only from the scan's bootstrap.
     *
     * @param  callable(string, string, bool): void  $sink
     */
    public static function recordWith(callable $sink): void
    {
        self::$sink = $sink;
    }

    /** Is a scan running? False on every ordinary run of this suite. */
    public static function armed(): bool
    {
        return self::$sink !== null;
    }

    /**
     * One assertSee, with the haystack it was about to be matched against.
     *
     * Never throws and never changes the assertion: an instrument that can fail
     * the thing it measures is not one. The recorder is called BEFORE the real
     * assertion runs, so a failing assertSee is still recorded — which matters,
     * because a needle that is about to fail is not the interesting case but an
     * absent row would look like one.
     */
    public static function see(string $value, string $haystack, bool $escaped): void
    {
        if (self::$sink === null) {
            return;
        }

        try {
            (self::$sink)($value, $haystack, $escaped);
        } catch (\Throwable) {
            // Measurement must never be the reason a suite goes red.
        }
    }
}
