<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\SiteUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform → Site address → "This shop is being served from a different address".
 *
 * Two endpoints: one that says whether the address the shop is answering on
 * matches `APP_URL`, and one that writes the new one.
 *
 * =============================================================================
 * THE ONE DESIGN DECISION
 * =============================================================================
 *
 * `adopt()` TAKES THE ADDRESS FROM THE REQUEST IT IS ANSWERING, NOT FROM ITS
 * BODY. The body carries a `confirm` field, and all that field does is have to
 * equal, byte for byte, the value the server derived for itself. It cannot
 * introduce a value; it can only agree with one.
 *
 * That inversion is the whole safety property. A field in a POST body is a
 * value an attacker chooses. The host of a request is a value an attacker can
 * only choose FOR A REQUEST THEY ARE THEMSELVES MAKING — and to make this
 * request they need an owner session, which a browser will only ever send to
 * the shop's real domain, because that is what a cookie's domain scope means.
 * So "forge Host, get it written" requires already holding the owner's session
 * on the real domain, which is checkmate several moves earlier.
 *
 * `confirm` is therefore not authorisation, it is an anti-surprise device: it
 * guarantees that what gets written is the same string the owner was looking at
 * when they pressed the button, and that a stale banner left open in a tab
 * since before a DNS change cannot write yesterday's answer.
 *
 * =============================================================================
 * WHY THESE ROUTES ARE NOT IN AdminCapabilities
 * =============================================================================
 *
 * Because that map fails CLOSED and an unmapped admin route is therefore the
 * STRICTEST setting available, not the loosest: `AdminCapabilities::for()`
 * returns null, `roleCan()` refuses null for every role, and
 * EnforceAdminCapability 403s everybody except the owner. Adding a named
 * capability here could only widen that.
 *
 * This is the identical arrangement SiteAddressApiController documents beside
 * it, for the identical reason: changing where the shop lives is an owner
 * decision. SiteUrlEndpointIsOwnerOnlyTest pins it rather than trusting the
 * comment, because "it is owner-only by not being listed" is exactly the kind
 * of property a later tidy-up removes without noticing.
 *
 * =============================================================================
 * WHAT THIS DELIBERATELY DOES NOT DO
 * =============================================================================
 *
 * It does not touch `canonical_host`, the alias list or the forwarding switch.
 * Those are SiteAddressApiController's, they are a different decision (which of
 * several addresses is the real one), and folding them together would mean a
 * domain move silently switching on 301s. The response NAMES the ones that have
 * gone stale so the owner is sent to the right screen; it does not go there for
 * them.
 */
final class SiteUrlApiController extends Controller
{
    /** GET /admin-api/site-url — is there a mismatch, and what would be written? */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->state($request));
    }

    /**
     * POST /admin-api/site-url/adopt — write the address this request arrived on.
     */
    public function adopt(Request $request): JsonResponse
    {
        $proposed = SiteUrl::fromRequest($request);

        if ($proposed === null) {
            return response()->json([
                'ok' => false,
                'reason' => 'This request did not arrive on an address that can be written down.',
            ], 422);
        }

        $confirm = (string) $request->input('confirm', '');

        /*
         * hash_equals, and not because the value is a secret — it is printed on
         * the screen. Because a constant-time compare of two strings is the
         * habit, and because the one place this project relaxed it
         * (QuizSubmission's public token, CLAUDE.md "Known gaps") is the one
         * place it had to be put back.
         */
        if (! hash_equals($proposed, $confirm)) {
            return response()->json([
                'ok' => false,
                'reason' => 'The address on screen is no longer the one this shop is answering on. Reload and look again.',
                'proposed' => $proposed,
            ], 409);
        }

        if (SiteUrl::configured() === $proposed) {
            // Not an error: two tabs, or a double click. Say so and change
            // nothing, rather than rewriting .env for no reason.
            return response()->json(['ok' => true, 'changed' => false] + $this->state($request));
        }

        $result = SiteUrl::writeEnv(app()->environmentFilePath(), [SiteUrl::KEY => $proposed]);

        if (($result['ok'] ?? false) !== true) {
            return response()->json([
                'ok' => false,
                'reason' => (string) ($result['reason'] ?? 'The .env file could not be updated.'),
            ], 500);
        }

        /*
         * config() is read from the compiled config this process booted with, so
         * it still says the old address however well the file was written. The
         * in-memory value is corrected too, so the response this endpoint is
         * about to build — and anything else in this request — agrees with the
         * file rather than with the past.
         */
        config(['app.url' => $proposed]);

        return response()->json([
            'ok' => true,
            'changed' => true,
            'caches_cleared' => $result['caches_cleared'] ?? [],
        ] + $this->state($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Request $request): array
    {
        $mismatch = SiteUrl::mismatch($request);
        $proposed = SiteUrl::fromRequest($request);

        return [
            'ok' => true,
            'configured' => SiteUrl::configured(),
            'current' => $proposed,
            'mismatch' => $mismatch !== null,
            'configured_host' => $mismatch['configured_host'] ?? SiteUrl::configuredHost(),
            'current_host' => $request->getHost(),

            // The confirmation the button has to send back. Naming it here
            // rather than letting the screen rebuild the string means the two
            // cannot drift.
            'confirm' => $proposed,

            /*
             * The things a domain move breaks that writing APP_URL does not fix.
             * Listed on the screen because every one of them fails WEEKS later
             * and silently — see docs/DOMAIN-MOVE-CHECKLIST.md.
             */
            'also_moves' => $mismatch === null ? [] : $this->alsoMoves($mismatch['current_host']),
        ];
    }

    /**
     * @return list<array{what: string, where: string, kind: string}>
     */
    private function alsoMoves(string $currentHost): array
    {
        $out = [];

        $canonical = (string) (\App\Models\Setting::map()[\App\Support\SiteHost::KEY_CANONICAL] ?? '');

        if ($canonical !== '' && \App\Support\SiteHost::normalise($canonical) !== \App\Support\SiteHost::normalise($currentHost)) {
            $out[] = [
                'what' => 'Main address is still '.$canonical,
                'where' => 'Platform → Site address',
                'kind' => 'here',
            ];
        }

        $siteUrl = (string) (\App\Models\Setting::map()['site_url'] ?? '');

        if ($siteUrl !== '' && ! str_contains(strtolower($siteUrl), strtolower($currentHost))) {
            $out[] = [
                'what' => 'Site URL (sitemap, canonical tags, IndexNow) is still '.$siteUrl,
                'where' => 'Store → Settings → Site URL',
                'kind' => 'here',
            ];
        }

        $out[] = [
            'what' => 'Payment webhook addresses registered at Stripe, Tabby and Tamara',
            'where' => 'the provider dashboards — nothing on this shop can change them',
            'kind' => 'third-party',
        ];

        $out[] = [
            'what' => 'Change of address in Google Search Console',
            'where' => 'Search Console — or the shop restarts its ranking from zero',
            'kind' => 'third-party',
        ];

        return $out;
    }
}
