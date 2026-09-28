<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use App\Services\StockSetRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalog → Sets → Stock · When a set is sold. (Lane SP)
 *
 * ONE SETTING, two options, and it ships at the value the shop already
 * behaves as -- so applying the package moves nothing at all until somebody
 * moves this switch. App\Services\StockSetRule holds the reasoning for the
 * question and for the default; this file is only its door.
 *
 * ── RULE 5, APPLIED LINE BY LINE ───────────────────────────────────────────
 *
 * IT STORES ONE OF ITS OWN OPTIONS OR THE DEFAULT, never what arrived. The
 * validator refuses anything else with a 422 and nothing is written; a value
 * that somehow reached the column another way is still read back as the
 * default by StockSetRule::mode(). Both halves, because a guard on the way in
 * is not a guard on what a table holds.
 *
 * ITS OWN CAPABILITY, `sets.stock`, and it FAILS CLOSED. It is deliberately
 * not `sets.manage`: creating and repricing sets is a catalogue act, and
 * deciding that selling one empties three other shelves is an inventory act
 * that can silently oversell or silently refuse sales. An admin route
 * App\Support\AdminCapabilities::RULES does not recognise resolves to null and
 * EnforceAdminCapability turns null into 403 for everyone but the owner, so
 * both endpoints are closed before the map is read and closed after it.
 *
 * NOTHING HERE IS PRINTED UNESCAPED and nothing here echoes a request value:
 * the response carries the resolved mode and the two option keys, which are
 * this file's own constants.
 */
class SetStockApiController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    /**
     * What the switch is set to, and what it may be set to.
     *
     * `mode` is the RESOLVED value out of StockSetRule, not the raw row: a
     * shop whose row has never been written, and a shop whose row holds
     * something unrecognisable, both read as the default here -- which is what
     * the storefront is actually doing, and therefore what the screen has to
     * show.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'mode' => app(StockSetRule::class)->mode(),
            'modes' => StockSetRule::MODES,
            'default' => StockSetRule::MODE_SET,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:'.implode(',', StockSetRule::MODES)],
        ]);

        /*
         * Re-checked against the same list rather than trusted from the
         * validator, and written as the LITERAL from that list rather than as
         * the string that arrived. `in:` and this are the same check twice on
         * purpose: the row this writes decides whether selling one product
         * empties three other shelves.
         */
        $mode = in_array($data['mode'], StockSetRule::MODES, true)
            ? (string) $data['mode']
            : StockSetRule::MODE_SET;

        $this->settings->set(StockSetRule::KEY, $mode);

        /*
         * Setting::map() memoises in a PROCESS-LEVEL static as well as in the
         * cache (CLAUDE.md), so a long-lived worker would keep answering the
         * old value. Harmless under PHP-FPM, wrong in a queue worker, and one
         * line to be right either way.
         */
        SettingsService::forgetMemo(StockSetRule::KEY);

        return response()->json(['saved' => true, 'mode' => app(StockSetRule::class)->mode()]);
    }
}
