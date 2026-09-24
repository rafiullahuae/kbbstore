<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\BuildMyRoutine;
use Illuminate\Support\Facades\Route;

/**
 * The hand-off from the skin quiz to Build my routine — Lane FT.
 *
 * ── WHAT THIS IS FOR ────────────────────────────────────────────────────────
 *
 * A shopper finishes the quiz having named up to three concerns, and Lane FM
 * built a page that turns exactly one of those concerns into a step-by-step
 * routine filled from this shop's own stock. Nothing joined the two.
 * docs/FM-ADMIN-APP-BLOCKS.md names that join as the quiz lane's, and
 * App\Support\RoutineConcerns exists so that it is a LOOKUP rather than a
 * mapping table: the eight English strings in that class are copied character
 * for character from the quiz's own CONCERNS array, and
 * QuizAndRoutinesShareOneConcernListTest fails if they ever drift.
 *
 * So this returns a map keyed by the exact string the quiz posts to /api/quiz —
 * 'Dark spots & tone', ampersand and all — whose value is the URL of that
 * concern's routine. The page's script looks the shopper's own answer up in it
 * and finds either a URL or nothing.
 *
 * ── THE MODULE SHIPS OFF, SO THE LINK MUST NOT EXIST WHEN IT IS OFF ─────────
 *
 * `build_my_routine` is off by default and /routines 404s in that state, by
 * design — RoutineController's header sets out why a 404 rather than a page
 * that explains itself. A quiz that offered a link into that is worse than one
 * that offered none: the shopper who presses it has been told the shop has
 * something it does not.
 *
 * Null is therefore the SHIPPED answer, and null means the quiz emits nothing
 * at all — no script, no element, not one byte different from the page that is
 * live today. That is also what keeps StorefrontEnglishUnchangedTest honest
 * about this change: with the module off there is nothing to compare, because
 * nothing moved.
 *
 * ── AND A ROUTINE WITH NOTHING IN IT IS NOT A DESTINATION ──────────────────
 *
 * `filled > 0` is Lane FM's own rule, applied here for Lane FM's own reason:
 * RoutineController::index() drops a routine no step of which can be filled,
 * because "a card that opens onto five 'we don't stock this' rows is worse than
 * no card". A link out of the quiz is a stronger promise than a card on a list,
 * so it gets at least the same test. /routines/{concern} still answers 200 for
 * such a concern — that page is FM's to define and this does not change it —
 * this simply does not send anybody there.
 *
 * NOTHING IS INVENTED. Every URL returned is a page that exists, for a concern
 * this shop has a product for. When the shop stocks nothing, the answer is
 * null and the quiz says nothing about routines at all.
 *
 * ── AND THE SECOND DESTINATION, WHICH IS THE ONE THAT WILL EXIST — Lane Q ──
 *
 * Everything above is about /routines/{concern}, and /routines/{concern} lives
 * behind `build_my_routine`, which SHIPS OFF. So on the shop as it is applied
 * today map() is null, the quiz emits nothing, and a shopper who has just named
 * three concerns is shown three chips and four buttons that all go to /shop/.
 * Measured, not assumed: /skin-quiz renders 46,136 bytes with no KBB_ROUTINES
 * in them, and the only URL any results-screen button carries is SHOP_URL.
 *
 * There is a second page per concern and it is NOT behind that switch:
 * /concern/{slug}/, App\Support\ConcernCollections. It exists when the concern
 * has copy AND MIN_PRODUCTS live tagged products — which is precisely the job
 * docs/SEO-FEATURE-MATRIX.md ranks as the highest-value action left in the
 * project, and the job the owner does on Catalog -> Build my routine. The day
 * he finishes tagging acne, /concern/acne/ starts answering 200 whether or not
 * the routine module was ever switched on.
 *
 * So concernPages() is the same lookup against the same eight strings, pointed
 * at the page that will be there. Both are offered, routine first, because a
 * routine is a stronger answer than a shelf when the shop has one; the shelf is
 * what it falls back to. Neither is invented and both are fetched in the tests.
 *
 * NULL IS STILL THE SHIPPED ANSWER FOR BOTH. Nothing in this repository is
 * tagged for any concern, ConcernCollections::live() is [], and the quiz page
 * is byte-identical to the one that is live today.
 */
final class QuizRoutineLink
{
    /**
     * The quiz's English concern => the URL of that concern's routine.
     *
     * Null when there is nothing to link to — the module is off, the routes are
     * not in the compiled route table, or no routine has a single step this
     * shop can fill.
     *
     * @return array<string, string>|null
     */
    public static function map(): ?array
    {
        /*
         * The route check comes first and is not decoration. This host applies
         * updates as zip packages and serves from a compiled route table; a
         * package whose clear_caches_* migration did not run leaves /routines
         * missing from the table while `module_toggles` says the feature is on.
         * Route::has() is the cheap question that is true of the table rather
         * than of the settings, and asking it first also means this costs one
         * array lookup on any tree where the routes were never wired up.
         */
        if (! Route::has('routines.show')) {
            return null;
        }

        $engine = app(BuildMyRoutine::class);

        if (! $engine->enabled()) {
            return null;
        }

        $map = [];

        foreach ($engine->routines() as $routine) {
            $slug = (string) ($routine['concern'] ?? '');

            if (! RoutineConcerns::exists($slug) || (int) ($routine['filled'] ?? 0) < 1) {
                continue;
            }

            /*
             * Keyed by the ENGLISH, because the English is what the shopper's
             * answer is: the quiz compares those strings in recommend() and
             * posts them to /api/quiz as the lead's concerns, so an Arabic
             * shopper's answer is still 'Hydration'. The label the routine page
             * then prints is translated at the far end, through
             * RoutineConcerns::labelKey().
             *
             * Url::to(), not route(): the shop is served under a base path on
             * staging (KBB_BASE_PATH=/kbb-upgrade) and Url::to() is the helper
             * the storefront — including routines.blade.php's own links between
             * these two pages — already uses for exactly that.
             */
            $map[RoutineConcerns::adminLabel($slug)] = Url::to('/routines/' . $slug . '/');
        }

        return $map === [] ? null : $map;
    }

    /**
     * The routine URL for one shopper's answers, or null.
     *
     * The FIRST concern that has one, in the order the shopper picked them,
     * because that order is the only statement of priority the quiz collects.
     * It does not fall back to a routine they did not ask for: a shopper who
     * chose "Sun protection" and is sent to an acne routine has been given
     * somebody else's answer.
     *
     * @param  iterable<mixed>  $concerns  the lead's concerns, as posted
     */
    public static function forConcerns(iterable $concerns): ?string
    {
        $map = self::map();

        if ($map === null) {
            return null;
        }

        foreach ($concerns as $concern) {
            if (is_string($concern) && isset($map[$concern])) {
                return $map[$concern];
            }
        }

        return null;
    }

    /**
     * The quiz's English concern => the URL of that concern's COLLECTION page.
     *
     * Keyed the same way map() is and for the same reason: the shopper's answer
     * is the English string, whatever language the shop is being read in.
     *
     * Null when no concern page exists — which is the state this repository
     * ships in, because nothing is tagged. It is deliberately NOT gated on the
     * routine module: /concern/{slug}/ answers independently of it, and gating
     * it would hide the only destination the shipped shop can ever offer.
     *
     * One query for all eight, ConcernCollections::counts(); the quiz page's
     * budget is two and this is the first one it spends.
     *
     * @return array<string, string>|null
     */
    public static function concernPages(): ?array
    {
        $map = [];

        foreach (ConcernCollections::live() as $slug) {
            /*
             * Url::to(), not route(), for the reason map() gives: this shop is
             * served under KBB_BASE_PATH on staging and Url::to() is what the
             * storefront already uses. ConcernCollections::path() supplies the
             * trailing slash the canonical carries, so the quiz never advertises
             * an address the site would redirect.
             */
            $map[RoutineConcerns::adminLabel($slug)] = Url::to(ConcernCollections::path($slug));
        }

        return $map === [] ? null : $map;
    }

    /**
     * The concern-page URL for one shopper's answers, or null.
     *
     * The FIRST of their concerns that has one, in the order they picked them —
     * the same rule forConcerns() applies and for the same reason: a shopper who
     * chose "Sun protection" is not sent to an acne page.
     *
     * @param  iterable<mixed>  $concerns  the lead's concerns, as posted
     */
    public static function concernUrlForConcerns(iterable $concerns): ?string
    {
        $map = self::concernPages();

        if ($map === null) {
            return null;
        }

        foreach ($concerns as $concern) {
            if (is_string($concern) && isset($map[$concern])) {
                return $map[$concern];
            }
        }

        return null;
    }
}
