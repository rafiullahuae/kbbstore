<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
 * schema rows and `show()` reads rows back. The only destructive act in the
 * whole module is retention — rows past `keep_days` are dropped when this
 * screen is opened — and it is bounded by a slider whose floor is 7 days.
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
    public function __construct(private SecurityModule $security) {}

    public function show(): JsonResponse
    {
        /*
         * Retention runs here, on a request that is already authenticated,
         * owner-only and off every hot path. This host has no cron and no
         * queue worker, so a schedule would be a schedule that never runs; a
         * shop nobody opens this screen on simply keeps its rows, which is the
         * safe direction for evidence to fail in.
         */
        $pruned = $this->security->prune();

        $values = $this->security->all();
        $fields = [];

        foreach (SecurityModule::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[$key] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'default' => $default, 'value' => $values[$key], 'options' => $def[4] ?? null,
            ];
        }

        $tabs = [];

        foreach (SecurityModule::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

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
}
