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

    /**
     * What a redirect's destination may be.
     *
     * A REDIRECT TABLE LEGITIMATELY POINTS OFF-SITE, so this is an allowlist of
     * schemes and not a same-host rule: a shop that moved a policy page to its
     * parent company's domain is doing a normal thing. What it may not be is a
     * scheme the browser treats as code or as inline content.
     *
     * The exposure used to be narrow and is not any more. Until CheckRedirects
     * was registered in the global pipeline, a row only fired on an address
     * that already 404'd; now it fires before the router, on addresses the shop
     * serves. The value was validated as `string|max:2048` and went straight
     * into a Location header. This project's own rule -- a URL from a setting
     * is scheme-checked before it becomes a destination -- had simply never
     * been applied here.
     *
     * `regex` and not a closure, so the same rule can be stated once and used
     * by both writers. THE TWO WRITERS ARE `store()` AND `resolveNotFound()` —
     * there is no `update()` on this controller, and naming one sent a reader
     * looking for a method that does not exist and, worse, away from the one
     * that does: `resolveNotFound()` is the second place a target reaches the
     * table, from Store → Redirects → the 404 log. Each had its own copy of the
     * old rule, which is how one of them would eventually have been missed.
     *
     * Allowed: a site-relative path (`/a/b`, the overwhelming majority), and an
     * absolute http/https URL. Refused: `javascript:`, `data:`, `vbscript:`,
     * `file:`, a protocol-relative `//evil.test` (which reads as a path and is
     * not one), and anything with a control character or a newline in it -- a
     * newline in a Location header is response splitting.
     */
    public const TARGET_RULES = [
        'required',
        'string',
        'max:2048',
        'regex:/^(?:\\/(?!\\/)[^\\x00-\\x1F\\x7F]*|https?:\\/\\/[^\\x00-\\x1F\\x7F\\s]+)$/i',
    ];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:255'],
            'target' => self::TARGET_RULES,
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
            'target' => self::TARGET_RULES,
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
