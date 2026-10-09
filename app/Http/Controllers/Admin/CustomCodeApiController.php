<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pixels\CustomCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Growth & Marketing → Marketing Pixels → Custom code. (Lane MP)
 *
 * Capability `marketing.customcode`, held by the OWNER role only and mapped in
 * AdminCapabilities::RULES ahead of the rest of the module, failing closed:
 * this is arbitrary script on every shop page, so no preset below Full Admin
 * carries it. CSRF comes from admin-api's web middleware; every save and
 * restore is a version row (who, when) and a security-log entry.
 *
 * The code goes back to the screen as JSON, which the screen puts into a
 * textarea's value — text, never markup.
 */
final class CustomCodeApiController extends Controller
{
    public function __construct(private CustomCode $code) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'slots' => $this->code->current(),
            'labels' => CustomCode::SLOTS,
            'where' => CustomCode::WHERE,
            'load' => CustomCode::LOAD,
            'max_bytes' => CustomCode::MAX_BYTES,
            'history' => $this->code->history(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $slots = $request->input('slots');

        if (! is_array($slots) || array_diff(array_keys($slots), array_keys(CustomCode::SLOTS)) !== []) {
            return response()->json(['ok' => false, 'error' => 'Malformed request.'], 422);
        }

        $result = $this->code->save($slots, $this->who($request));

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'error' => implode(' ', $result['errors']), 'errors' => $result['errors']], 422);
        }

        return response()->json(['ok' => true, 'warnings' => $result['warnings'], 'history' => $this->code->history()]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        if (! $this->code->restore($id, $this->who($request))) {
            return response()->json(['ok' => false, 'error' => 'That version no longer exists.'], 404);
        }

        return response()->json(['ok' => true, 'slots' => $this->code->current(), 'history' => $this->code->history()]);
    }

    private function who(Request $request): string
    {
        $admin = $request->user('admin');

        return (string) ($admin?->email ?? 'unknown');
    }
}
