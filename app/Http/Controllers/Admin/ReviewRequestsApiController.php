<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reviews\ReviewRequests;
use App\Services\Reviews\ReviewRequestSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Emails → Customer emails → Review request → Schedule & rules (Lane RV).
 *
 *   GET  admin-api/emails/review-requests   rules, their schema, the report,
 *                                            the twenty latest requests
 *   POST admin-api/emails/review-requests   save the rules (each value cleaned
 *                                            by ReviewRequestSettings::clean())
 *
 * The on/off and the words are NOT here: the switch is the email's own module
 * toggle on the Customer emails list, and the words are the template editor's
 * Wording tabs. One copy of each, as CustomerEmails' header requires.
 *
 * Capability `emails.review_requests` (AdminCapabilities), fails closed for any
 * role without it. The latest-requests list carries an order NUMBER and a
 * status — no customer address, no token, nothing a screenshot should not show.
 */
class ReviewRequestsApiController extends Controller
{
    public function __construct(private ReviewRequestSettings $settings, private ReviewRequests $requests) {}

    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $this->settings->save(array_intersect_key((array) $data['settings'], ReviewRequestSettings::FIELDS));

        return response()->json(['ok' => true] + $this->state());
    }

    private function state(): array
    {
        $recent = [];

        try {
            $recent = DB::table(ReviewRequests::TABLE . ' as rr')
                ->leftJoin('orders', 'orders.id', '=', 'rr.order_id')
                ->orderByDesc('rr.id')
                ->limit(20)
                ->get(['rr.status', 'rr.reason', 'rr.completed_at', 'rr.sent_at', 'rr.reviews_count', 'orders.order_number', 'orders.id as order_id'])
                ->map(static fn ($r) => [
                    'order' => (string) ($r->order_number ?? ('#' . $r->order_id)),
                    'order_id' => $r->order_id === null ? null : (int) $r->order_id,
                    'status' => (string) $r->status,
                    'reason' => $r->reason === null ? null : (string) $r->reason,
                    'completed_at' => $r->completed_at === null ? null : \Illuminate\Support\Carbon::parse((string) $r->completed_at)->toIso8601String(),
                    'sent_at' => $r->sent_at === null ? null : \Illuminate\Support\Carbon::parse((string) $r->sent_at)->toIso8601String(),
                    'reviews' => (int) $r->reviews_count,
                ])->all();
        } catch (\Throwable) {
            // The table is missing until the migration has run; the screen says so.
        }

        return [
            'enabled' => $this->settings->enabled(),
            'settings' => $this->settings->all(),
            'schema' => ReviewRequestSettings::schema(),
            'stats' => $this->requests->stats(),
            'recent' => $recent,
        ];
    }
}
