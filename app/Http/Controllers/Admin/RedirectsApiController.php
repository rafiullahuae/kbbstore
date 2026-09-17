<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotFoundLog;
use App\Models\Redirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RedirectsApiController extends Controller
{
    /** GET /admin-api/redirects — both the redirect list and the 404 log, one screen's worth of data. */
    public function index(): JsonResponse
    {
        return response()->json([
            // Both end in `id`. The redirect list is not truncated, so there
            // its only job is that the screen stops reshuffling rows that tie
            // on created_at; the 404 log is cut at 100 and there it decides
            // which of a tied block the owner is shown at all — `hits` is 1
            // for most of that table.
            'redirects' => Redirect::query()->orderByDesc('created_at')->orderByDesc('id')->get([
                'id', 'source', 'target', 'code', 'enabled', 'hits', 'auto_created', 'last_hit_at', 'created_at',
            ]),
            'not_found' => NotFoundLog::query()->orderByDesc('hits')->orderByDesc('id')->limit(100)->get([
                'id', 'path', 'hits', 'referer', 'first_seen_at', 'last_seen_at',
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:255'],
            'target' => ['required', 'string', 'max:2048'],
            'code' => ['required', 'integer', 'in:301,302'],
        ]);

        $data['source'] = '/' . ltrim(trim($data['source']), '/');

        if ($data['source'] === '/' . ltrim($data['target'], '/')) {
            return response()->json(['ok' => false, 'message' => 'Source and target cannot be the same path — that would redirect a page to itself.'], 422);
        }

        $redirect = Redirect::query()->updateOrCreate(
            ['source' => $data['source']],
            ['target' => $data['target'], 'code' => $data['code'], 'enabled' => true, 'auto_created' => false]
        );

        return response()->json(['ok' => true, 'redirect' => $redirect]);
    }

    public function toggle(Redirect $redirect): JsonResponse
    {
        $redirect->update(['enabled' => !$redirect->enabled]);

        return response()->json(['ok' => true, 'enabled' => $redirect->enabled]);
    }

    public function destroy(Redirect $redirect): JsonResponse
    {
        $redirect->delete();

        return response()->json(['ok' => true]);
    }

    /** Turns a logged 404 directly into a redirect — the common "someone hit this, fix it now" flow. */
    public function resolveNotFound(Request $request, NotFoundLog $notFound): JsonResponse
    {
        $data = $request->validate([
            'target' => ['required', 'string', 'max:2048'],
            'code' => ['required', 'integer', 'in:301,302'],
        ]);

        $redirect = Redirect::query()->updateOrCreate(
            ['source' => $notFound->path],
            ['target' => $data['target'], 'code' => $data['code'], 'enabled' => true, 'auto_created' => false]
        );

        $notFound->delete();

        return response()->json(['ok' => true, 'redirect' => $redirect]);
    }

    public function destroyNotFound(NotFoundLog $notFound): JsonResponse
    {
        $notFound->delete();

        return response()->json(['ok' => true]);
    }
}
