<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\CartPanel;
use App\Models\Coupon;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Appearance → Cart panel. */
class CartPanelApiController extends Controller
{
    public function __construct(private CartPanel $panel) {}

    public function show(): JsonResponse
    {
        // One schema, drawn by one renderer. This loop used to be copied
        // into nine controllers that had to agree by hand.
        $tabs = ModuleSchema::tabs(
            CartPanel::SCHEMA,
            CartPanel::TABS,
            $this->panel->all(),
            CartPanel::POLICY,
        );

        /*
         * `touch` and the two breakpoints are sent rather than repeated in the
         * screen's JavaScript, because they are facts about the SHOP and a second
         * copy of a fact is a copy that goes stale. The screen turns the help
         * text under a `touch` slider warm below 44 and prints the two widths on
         * the previews' rulers.
         *
         * NOTHING HERE IS A THRESHOLD THE SERVER ENFORCES. `min` in the schema is
         * below 44 on all four keys on purpose — the owner asked to squeeze his
         * own tap targets, and a slider that stops where nobody asked it to stop
         * reads as a bug. See CartPanel::TOUCH_TARGETS.
         */
        return response()->json([
            'tabs' => $tabs,
            'touch' => CartPanel::TOUCH_TARGETS,
            'touchMin' => 44,
            'phoneMax' => 680,
            'tapMax' => 900,
            'coupons' => $this->coupons(),
        ]);
    }

    /**
     * The Coupon hint tab's list: the shop's own coupons, usable ones first,
     * each labelled with its code, what it takes off and when it ends. (Lane QK3)
     *
     * A LIST, NOT AN OBJECT. The screen draws the select from it in this order;
     * a JSON object keyed by id would be re-sorted by the browser, which orders
     * integer keys ascending, and "usable first" would be lost.
     *
     * One query whatever the count, and capped: this is the admin, and the
     * panel itself never reads the coupons table. The chosen coupon is always
     * in the list even past the cap, so the select can show what is stored.
     *
     * @return list<array{id: string, code: string, label: string, usable: bool}>
     */
    private function coupons(): array
    {
        $cols = ['id', 'code', 'type', 'amount', 'starts_at', 'expires_at', 'usage_limit', 'usage_count'];
        $rows = Coupon::query()->orderBy('code')->orderBy('id')->limit(500)->get($cols);
        $chosen = (int) $this->panel->get('coupon_id');

        if ($chosen > 0 && ! $rows->contains('id', $chosen)) {
            $rows->push(...Coupon::query()->whereKey($chosen)->get($cols));
        }

        $now = now();
        $out = [];

        foreach ($rows as $c) {
            $state = match (true) {
                $c->expires_at !== null && $now->gt($c->expires_at) => 'expired ' . $c->expires_at->format('j M Y'),
                $c->usage_limit !== null && (int) $c->usage_count >= (int) $c->usage_limit => 'used up',
                $c->starts_at !== null && $now->lt($c->starts_at) => 'starts ' . $c->starts_at->format('j M Y'),
                default => null,
            };

            $worth = (string) $c->type === 'percent'
                ? rtrim(rtrim(number_format((int) $c->amount / 100, 2, '.', ''), '0'), '.') . '% off'
                : Money::plain((int) $c->amount) . ' off';

            $out[] = [
                'id' => (string) $c->id,
                'code' => (string) $c->code,
                'label' => $c->code . ' · ' . $worth . ' · '
                    . ($state ?? ($c->expires_at !== null ? 'until ' . $c->expires_at->format('j M Y') : 'no expiry')),
                'usable' => $state === null,
            ];
        }

        // Usable first; the order within each half stays by code.
        usort($out, static fn (array $a, array $b) => $b['usable'] <=> $a['usable']);

        return $out;
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(CartPanel::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->panel->save($data['settings']);

        return response()->json(['ok' => true]);
    }
}
