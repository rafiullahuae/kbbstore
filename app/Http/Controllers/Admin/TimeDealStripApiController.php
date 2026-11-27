<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TimeDealStrip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance -> Time deal strip (Lane TDS). GET the values, POST {settings:{...}} to save them, all or nothing.
 * Capability timedeal.manage (AdminCapabilities), owner / manager / editor; fails closed.
 */
class TimeDealStripApiController extends Controller
{
    public function __construct(private TimeDealStrip $strip) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);
        $r = $this->strip->save($data['settings']);

        if (! $r['ok']) {
            return response()->json(['ok' => false, 'error' => 'Not saved — check: '.implode(', ', $r['rejected']), 'rejected' => $r['rejected']] + $this->payload(), 422);
        }

        return response()->json(['ok' => true] + $this->payload());
    }

    private function payload(): array
    {
        return [
            'values' => $this->strip->all(),
            'modes' => TimeDealStrip::MODES,
            'text_max' => TimeDealStrip::TEXT_MAX,
            'live' => $this->strip->visible(),
            'now' => now(TimeDealStrip::ZONE)->format('Y-m-d\TH:i'),
            'css' => TimeDealStrip::CSS,
        ];
    }
}
