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
     * by both writers; `store()` and `update()` had their own copies of the
     * old one, which is how one of them would eventually have been missed.
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

    /** The one message, so all three writers refuse in the same words. */
    private const SELF_POINTING_MESSAGE = 'Source and target cannot be the same path — that would redirect a page to itself.';

    /**
     * Does this row point its own source back at itself?
     *
     * ═════════════════════════════════════════════════════════════════════
     * WHY THIS IS A METHOD AND NOT AN `if` INSIDE store()
     * ═════════════════════════════════════════════════════════════════════
     *
     * It WAS an `if` inside store(), and store() is not the only writer. Two
     * others reach the same table with the same power:
     *
     *   resolveNotFound()  takes the source from a logged 404 and the target
     *                      from the request. It is the likeliest way to make
     *                      this row by accident — the owner is looking at a
     *                      path that 404s and types that same path as where it
     *                      should go.
     *
     *   toggle()           cannot author a target, but it can ENABLE a
     *                      self-pointing row that is sitting disabled. Rows
     *                      written before RedirectMap learned to refuse one are
     *                      in the owner's database now.
     *
     * WHAT THE ROW DOES IF IT IS WRITTEN. `CheckRedirects::loops()` catches it
     * at read time with no query at all, so the storefront is not at risk and
     * this is not urgent. What it costs instead is the owner's afternoon: a row
     * sits on the Redirects screen looking correct, its `hits` column never
     * moves, and nothing anywhere says why. A row that can never fire is a row
     * somebody will one day stare at.
     *
     * ── IT IS STRICTER THAN THE CHECK IT REPLACES, DELIBERATELY ──────────
     *
     * store() compared the two strings whole. That missed every target whose
     * path equals the source but which carries a query string or a fragment —
     * `/foo/` → `/foo/?utm=x` is the same page, and the read-time guard already
     * treats it as a loop because it compares `parse_url(..., PATH)`. So the
     * two halves disagreed: one refused to write it, the other refused to
     * follow it, and they drew the line in different places.
     *
     * This uses the read-time definition, so write time and read time now agree
     * on what "points at itself" means. NOTHING THAT WORKS CHANGES: every row
     * this newly refuses is a row `CheckRedirects::lookup()` already returns
     * null for, so the storefront has never followed one.
     *
     * An off-host target — `https://…`, or a protocol-relative `//…` the
     * scheme allowlist refuses anyway — cannot collide with a path this
     * application is asked for, so it is never self-pointing. Same reasoning,
     * same answer, as `CheckRedirects::targetPath()`.
     */
    private static function pointsAtItself(string $source, string $target): bool
    {
        $target = trim($target);

        if ($target === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $target) === 1) {
            return false;
        }

        $targetPath = parse_url($target, PHP_URL_PATH);

        if (! is_string($targetPath) || $targetPath === '') {
            return false;
        }

        return '/' . ltrim(trim($source), '/') === '/' . ltrim($targetPath, '/');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:255'],
            'target' => self::TARGET_RULES,
            'code' => ['required', 'integer', 'in:301,302'],
        ]);

        $data['source'] = '/' . ltrim(trim($data['source']), '/');

        if (self::pointsAtItself($data['source'], $data['target'])) {
            return response()->json(['ok' => false, 'message' => self::SELF_POINTING_MESSAGE], 422);
        }

        $redirect = Redirect::query()->updateOrCreate(
            ['source' => $data['source']],
            ['target' => $data['target'], 'code' => $data['code'], 'enabled' => true, 'auto_created' => false]
        );

        return response()->json(['ok' => true, 'redirect' => $redirect]);
    }

    public function toggle(Redirect $redirect): JsonResponse
    {
        /*
         * ENABLING ONLY. Turning a self-pointing row OFF is always allowed and
         * is the thing the owner most wants to do with one, so the guard is on
         * the direction that would put it back into service rather than on the
         * endpoint.
         *
         * This endpoint cannot author a target, which is why it had no check —
         * but the rows are already there. `RedirectMap` line 425 refuses to
         * WRITE one and only learned to recently; anything written before that,
         * by hand or by an earlier import, is in the owner's database now,
         * disabled and one click from being switched back on.
         *
         * There is no update endpoint on this controller, so a row refused here
         * cannot be edited into shape. The message says to delete it, because
         * that is the only thing that will work.
         */
        if (! $redirect->enabled && self::pointsAtItself((string) $redirect->source, (string) $redirect->target)) {
            return response()->json([
                'ok' => false,
                'message' => 'This row points "' . $redirect->source . '" at itself, so it can never fire. Delete it and add the redirect you meant.',
            ], 422);
        }

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

        /*
         * The likeliest way to author a self-pointing row, and until now the
         * only writer with no guard on it. The owner is looking at a path that
         * 404s and types that same path as where it should go — which produces
         * a row the storefront refuses to follow (CheckRedirects::loops()) and
         * the screen shows as if it were fine.
         *
         * Refused BEFORE the 404 log row is deleted: a request that writes
         * nothing must not also destroy the entry the owner was working on, or
         * the failure costs him the address as well as the redirect.
         */
        if (self::pointsAtItself((string) $notFound->path, $data['target'])) {
            return response()->json(['ok' => false, 'message' => self::SELF_POINTING_MESSAGE], 422);
        }

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
