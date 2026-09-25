<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IntegrityChecker;
use App\Services\ModuleSchema;
use App\Services\SecurityModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store → Security.
 *
 * Two endpoints, the same field/tab shape every module API controller in this
 * project answers in, plus the report itself. Kept in that shape deliberately:
 * the console's field renderer is generic and a controller answering
 * differently would need a renderer of its own.
 *
 * ── WHAT IT CANNOT DO ───────────────────────────────────────────────────────
 *
 * Nothing here blocks, unblocks, restores or deletes a request. `save()` writes
 * schema rows, `show()` reads rows back, and `integrity()` HASHES FILES AND
 * WRITES ROWS ABOUT THEM — it opens every file for reading and not one for
 * writing, which App\Services\IntegrityChecker's own docblock explains and
 * SecurityIntegrityTest asserts by reading that file as text. The only
 * destructive act in the whole module is retention — rows past `keep_days` or
 * past `max_rows` are dropped — and both are bounded by sliders whose floors
 * are 7 days and 1,000 rows.
 *
 * ── THE CAPABILITY ──────────────────────────────────────────────────────────
 *
 * `security.view`, held by the owner alone, declared in the route file and
 * mapped in App\Support\AdminCapabilities. It is NOT `system.diagnostics`,
 * although both are owner-only today, for the reason the Cache screen's own
 * mapping states: the day somebody widens diagnostics to a manager — which is
 * a reasonable thing to want — this must not widen with it in a different file
 * with nothing to notice. Every row here names an operator's email, their role
 * and an IP address.
 *
 * Failing closed needs no code here: an admin route this map did not recognise
 * is null, and EnforceAdminCapability turns null into 403 for everyone but the
 * owner. The route is mapped anyway, so "owner-only because somebody decided
 * so" is on the record rather than "owner-only because nobody mapped it".
 */
class SecurityController extends Controller
{
    public function __construct(
        private SecurityModule $security,
        private IntegrityChecker $integrity,
    ) {}

    public function show(): JsonResponse
    {
        /*
         * Retention runs here, on a request that is already authenticated,
         * owner-only and off every hot path. This host has no cron and no
         * queue worker, so a schedule would be a schedule that never runs.
         *
         * IT IS NO LONGER THE ONLY THING KEEPING THE TABLE BOUNDED, which was
         * the honest gap in round one: SecurityModule::enforceCap() runs on the
         * WRITE path, one write in a hundred, so a shop nobody ever opens this
         * screen on still cannot grow the trail past `max_rows`. This call is
         * the calendar half and that one is the count half.
         */
        $pruned = $this->security->prune();

        /*
         * And the integrity check, in the same place and for the same reasons:
         * authenticated, owner-only, off every hot path, and throttled to
         * `integrity_hours` so a refresh is not a filesystem walk. It returns
         * null when the switch is off or the last scan is recent enough, and
         * the report draws the stored summary either way.
         */
        $this->integrity->scanIfDue();

        // One schema, drawn by one renderer. This was the same fifteen lines
        // that nine other controllers carried, and the reason the three
        // constants a screen depends on could disagree without anything saying
        // so. `POLICY` travels with the schema because ModuleSchema's cast is
        // strict by default and this screen is not — see SecurityModule::POLICY.
        $tabs = ModuleSchema::tabs(
            SecurityModule::SCHEMA,
            SecurityModule::TABS,
            $this->security->all(),
            SecurityModule::POLICY,
        );

        return response()->json([
            'tabs' => $tabs,
            'report' => $this->security->report(),
            'pruned' => $pruned,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        /*
         * Refused rather than ignored. A key this schema does not know is a
         * screen and a service that disagree about what exists, and answering
         * 200 to it would let a mistake sit there looking saved.
         */
        $unknown = array_diff(array_keys($data['settings']), array_keys(SecurityModule::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        $this->security->save($data['settings']);

        /*
         * The saved values are read back rather than echoed, because cast()
         * clamps a range and refuses a select that is not one of its own
         * options — so what the screen should now show is what the service
         * stored, not what the browser sent.
         */
        return response()->json(['ok' => true, 'report' => $this->security->report()]);
    }

    /**
     * "Check now" — run the integrity check regardless of the throttle.
     *
     * ── ITS OWN CAPABILITY, AND WHY ─────────────────────────────────────────
     *
     * `security.integrity`, not `security.view`. CLAUDE.md's rule is that every
     * new admin endpoint gets its own capability and fails closed, and this one
     * earns it rather than merely obeying it: reading a report that is already
     * written and making the server walk its own filesystem hashing every file
     * a package installed are different acts with different costs. The day
     * somebody wants a manager to be able to READ this screen — which is a
     * reasonable thing to want — that must not hand them a button that puts a
     * few thousand file reads on a shared plan on demand.
     *
     * It fails closed twice over. The route is mapped ABOVE the
     * `admin-api/security/**` wildcard in App\Support\AdminCapabilities::RULES,
     * which is first-match-wins, so it resolves to `security.integrity` and not
     * to `security.view`; and an unmapped admin route resolves to null, which
     * EnforceAdminCapability turns into a 403 for everyone but the owner.
     *
     * ── IT IS A POST AND IT CHANGES NOTHING ON DISK ─────────────────────────
     *
     * POST because it does work and writes rows, not because it edits anything:
     * every file it touches it opens for reading. Nothing is restored, nothing
     * is quarantined and nothing is deleted — Phase 18 records "restore
     * automatically, or alert and wait?" as the OWNER's open question, and this
     * round builds the alert half and leaves the seam.
     */
    public function integrity(): JsonResponse
    {
        if (! $this->security->get('integrity_on')) {
            return response()->json([
                'ok' => false,
                'error' => 'Integrity checking is switched off on the "File integrity" tab below.',
            ], 422);
        }

        $state = $this->integrity->scan();

        return response()->json([
            'ok' => true,
            'state' => $state,
            'report' => $this->security->report(),
        ]);
    }
}
