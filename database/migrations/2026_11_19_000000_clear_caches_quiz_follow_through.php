<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the quiz follow-through package (Lane FT).
 *
 *   - VIEWS, and this is the one that matters here. NO ROUTE IS ADDED by this
 *     package — /api/quiz and /skin-quiz are both already in the route table
 *     and /routines came in with Lane FM's own clear_caches migration — so the
 *     usual reason for this file does not apply and a different one does.
 *
 *     resources/views/store/skin-quiz.blade.php is EDITED, not new, and two
 *     wholly new templates land beside it (emails/quiz-plan.blade.php and its
 *     text twin). Compiled Blade is keyed by the view's PATH and its freshness
 *     check is a filemtime comparison against the compiled copy; an update
 *     package on this host is an unzip, so the timestamps it lands are whatever
 *     the archive carried. A stale compiled skin-quiz would keep serving the
 *     page WITHOUT the routine hand-off while the module said it was on — the
 *     same "indistinguishable from the switch being off" trap Lane FM's
 *     migration describes, arriving from the other end.
 *
 *   - OPCACHE. App\Support\QuizRoutineLink and App\Mail\QuizPlanEmail are new
 *     classes, and App\Http\Controllers\Api\QuizController,
 *     App\Services\Translation\InterfaceStrings and
 *     App\Support\RoutineConcerns's callers have changed around them. A worker
 *     holding the old QuizController beside the new views would go on capturing
 *     leads and emailing nobody, which is exactly the defect this package
 *     exists to end and would be invisible from the outside.
 *
 *   - CONFIG. The translation keys the plan email is written in are compiled
 *     into the interface-string set, and a stale config cache on a shop that
 *     has Arabic switched on would send an English plan to a shopper who
 *     answered an Arabic quiz.
 *
 * NO SCHEMA CHANGE. The plan email is rendered from columns `quiz_submissions`
 * already has — `recommended_routines`, `skin_type`, `concerns`, `name`,
 * `email` — and nothing new is recorded about anybody. In particular no column
 * is added for the allergy answers: the form does not ask to keep them, and
 * this package does not start.
 *
 * Best-effort throughout, like every other clear_caches migration in this set:
 * a file that cannot be unlinked mid-update must not fail the package and
 * strand the site half-updated. A stale cache is a visible bug; a failed
 * migration is an outage.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files; the skin quiz now emails the\n";
            echo "plan its contact step has always promised, and offers a way into\n";
            echo "Build my routine when that module is on. With the module off the\n";
            echo "quiz page is byte-for-byte what it was.\n";
        }
    }

    /** Nothing to undo. Deleting a cache is not a change to reverse. */
    public function down(): void {}
};
