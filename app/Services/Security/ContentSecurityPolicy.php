<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\Url;

/**
 * The content-security policy this shop would enforce, sent REPORT-ONLY.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IT CANNOT BE THE ENFORCING HEADER, AND THAT IS STRUCTURAL
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Phase 18's sequencing is report before enforce, in every part: audit trail
 * and reporting screen → integrity checking in report-only mode → CSP
 * REPORT-ONLY → the request gate in observe mode → then, with real traffic
 * observed, enforcement one rule at a time. This class is the third step and
 * only the third step.
 *
 * The plan's own words about why this one wants a report-only phase more than
 * any other part of the module: CSP "is the single most effective control
 * against injected script actually executing, and it is also the one most
 * likely to break a working page". Both halves of that are true of THIS shop
 * in particular — see WHAT ENFORCEMENT WOULD BREAK below, which is not a
 * warning but a measured list.
 *
 * So the enforcing header is not a setting, not a mode and not a branch. There
 * is ONE header name in this module and it is a constant:
 *
 *     self::HEADER === 'Content-Security-Policy-Report-Only'
 *
 * SecurityCspTest strips comments with token_get_all and then searches every
 * file under app/ for the string `Content-Security-Policy` NOT followed by
 * `-Report-Only`. There is no value any setting can hold, and no POST anybody
 * can hand-roll, that makes this application emit the enforcing header: the
 * name it would need is not in the tree. A future round that turns enforcement
 * on has to add that string and delete that assertion by hand, which is the
 * point of it — the same technique this lane used in round two to keep
 * `integrity_action` on `alert`.
 *
 * `MODES` is the seam beside it, and it holds exactly one option for the same
 * reason `IntegrityChecker::ACTIONS` does. SecurityModule::cast() stores a
 * select value only when it is one of that field's own options and otherwise
 * stores the default, so a hand-rolled POST of `enforce` is stored as
 * `report`. The control cannot be moved to a behaviour that does not exist.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE POLICY IS WRITTEN FROM WHAT THIS SHOP SERVES, NOT FROM A TEMPLATE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Every source below is here because a page reads it, and the comment on each
 * line says which page and which feature. Read out of the tree, not assumed:
 *
 *   - `resources/views/layouts/store.blade.php` loads two Google Fonts
 *     stylesheets (Poppins, and Cairo for Arabic) from fonts.googleapis.com,
 *     whose @font-face rules then fetch the faces themselves from
 *     fonts.gstatic.com. `AccountPanel::fontHref()` adds a third — one of
 *     eight display faces the owner picks from — and every one of those eight
 *     is a fonts.googleapis.com URL from a `match` with no interpolation.
 *   - `@vite(['resources/css/kbb/kbb.css', 'resources/js/kbb/app.js'])` emits
 *     same-origin `/build/…` assets, so 'self' covers the whole bundle. There
 *     is no CDN in this tree and no external host named in resources/js at
 *     all, which was measured rather than assumed.
 *   - `App\Services\Analytics` is the ONE place that emits analytics markup.
 *     It loads Google's tag from www.googletagmanager.com, Meta's from
 *     connect.facebook.net, and TikTok's from analytics.tiktok.com — the last
 *     two by document.createElement('script') from an inline loader, which is
 *     why those hosts must be in script-src even though no <script src> in the
 *     tree names them.
 *   - `resources/views/partials/checkout/stripe-elements.blade.php` loads
 *     js.stripe.com/v3, which then frames the card fields from js.stripe.com
 *     and talks to api.stripe.com. Card entry is the one feature on this shop
 *     that a wrong policy takes down for money, so its three hosts are named
 *     on three different directives rather than left to default-src.
 *   - The payment marks in the footer are INLINE <svg> elements out of
 *     `App\Support\PaymentMarkArt`, not image loads. An <svg> written into the
 *     document is not a fetch and no directive applies to it — which is worth
 *     saying here, because "the payment marks will break" is the first thing
 *     anybody assumes when they read img-src on a checkout page.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT ENFORCEMENT WOULD BREAK, AND WHY IT IS NOT PAPERED OVER
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * script-src does NOT carry 'unsafe-inline' and style-src does NOT carry it
 * either, and that is deliberate. The storefront views hold, counted:
 *
 *     24  inline <script> blocks        124  inline on* handlers
 *     29  inline <style> blocks         210  inline style="" attributes
 *
 * A policy with 'unsafe-inline' in script-src is a policy that stops an
 * injected <script> from doing nothing at all, because an injected <script> IS
 * inline. Adding it would make this screen report zero violations and protect
 * nothing, which is the failure mode Phase 18 names by name: "everyone now
 * believes it is protected".
 *
 * So the policy is the one this shop should eventually enforce, the violations
 * it reports are the real cost of getting there, and docs/LC-SECURITY-MODULE.md
 * carries the measured list. Nothing in this round asks anybody to fix them;
 * the round after this one has the numbers to plan with, which is what a
 * report-only phase is for.
 */
final class ContentSecurityPolicy
{
    /**
     * The only header name in this module.
     *
     * A constant and not a computed string: see the class docblock. Nothing
     * concatenates onto this and nothing strips from it.
     */
    public const HEADER = 'Content-Security-Policy-Report-Only';

    /**
     * The seam where enforcement would appear, holding one option.
     *
     * The owner has not been asked whether to enforce and a lane does not
     * answer that by shipping code for one branch. Same shape, and the same
     * argument, as IntegrityChecker::ACTIONS.
     */
    public const MODES = ['report' => 'Report only — collect violations, refuse nothing'];

    /** The route name the report endpoint declares, when it is wired up. */
    public const ROUTE = 'security.csp-report';

    /**
     * Where reports go when the route is not registered.
     *
     * A literal fallback rather than a throw, because this string is built on
     * every storefront response: a package whose routes have landed but whose
     * route cache has not been cleared yet must serve a policy with a report
     * address that is merely wrong for a few minutes, never a 500 on every
     * page. The `clear_caches_` migration that ships beside the route file is
     * what makes that window short.
     */
    public const FALLBACK_PATH = '/api/csp-report';

    /**
     * directive => [sources], and the comment on each says what needs it.
     *
     * An ordered constant rather than a built string, so SecurityCspTest can
     * assert against the directives themselves and docs/LC-SECURITY-MODULE.md
     * can print the policy without restating it by hand.
     *
     * @var array<string, list<string>>
     */
    public const DIRECTIVES = [
        // Everything not named below. 'self' is this origin, which on this
        // install includes the /kbb-upgrade base path — an origin is scheme,
        // host and port, and the base path is not part of it.
        'default-src' => ["'self'"],

        // A <base> tag an injection could plant is how a same-origin script
        // src becomes an attacker's host. Nothing in this tree emits <base>.
        'base-uri' => ["'self'"],

        // No <object>, <embed> or <applet> anywhere in the storefront, and
        // nothing should be able to add one.
        'object-src' => ["'none'"],

        // The modern spelling of the X-Frame-Options: SAMEORIGIN that
        // App\Http\Middleware\SecurityHeaders already sends. Same answer, so
        // this adds no refusal even the day it is enforced. The admin console
        // uses same-origin iframes, which 'self' allows.
        'frame-ancestors' => ["'self'"],

        // Every form on the storefront posts to this shop. Stripe's card
        // fields are an iframe, not a cross-origin form post, so they do not
        // need to be here.
        'form-action' => ["'self'"],

        'script-src' => [
            // The Vite bundle, and every inline block once this shop has a
            // nonce to give them. It has none today; see the class docblock.
            "'self'",
            // resources/views/partials/checkout/stripe-elements.blade.php.
            'https://js.stripe.com',
            // App\Services\Analytics — Google's gtag loader.
            'https://www.googletagmanager.com',
            // App\Services\Analytics — Meta's fbevents.js, injected by the
            // inline loader rather than by a <script src> in any view.
            'https://connect.facebook.net',
            // App\Services\Analytics — TikTok's events.js, same shape.
            'https://analytics.tiktok.com',
        ],

        'style-src' => [
            "'self'",
            // Poppins and Cairo in layouts/store.blade.php, plus the eight
            // display faces AccountPanel::fontHref() picks between.
            'https://fonts.googleapis.com',
        ],

        'font-src' => [
            "'self'",
            // The face files the Google Fonts stylesheets point at.
            'https://fonts.gstatic.com',
            // Icon faces embedded in CSS as data URIs are still a font fetch.
            'data:',
        ],

        'img-src' => [
            "'self'",
            // Inline SVG data URIs in resources/views/partials.
            'data:',
            /*
             * ANY HTTPS IMAGE, and this one is a judgement rather than an
             * inventory. `products.image` can hold an absolute URL left over
             * from the WooCommerce import, and a category or a post can carry
             * one the owner pasted in. An image cannot execute, the worst a
             * wrong one costs is a broken tile and a referrer, and a policy
             * that breaks the product grid is a policy that gets switched off
             * — which is the failure mode this whole phase is sequenced
             * around. Narrowing this is a job for the round that enforces,
             * with the violation list in hand.
             */
            'https:',
        ],

        'connect-src' => [
            // The storefront's own fetches: cart, wishlist, quiz, reviews.
            "'self'",
            // Google Analytics 4 collects over these two, not over the
            // googletagmanager host that served the loader.
            'https://www.google-analytics.com',
            'https://*.google-analytics.com',
            'https://*.analytics.google.com',
            // Meta's and TikTok's pixels beacon back to their own hosts.
            'https://connect.facebook.net',
            'https://analytics.tiktok.com',
            // Stripe Elements tokenises the card against this.
            'https://api.stripe.com',
        ],

        // Stripe's card fields are iframes, and 3-D Secure opens a second one
        // on hooks.stripe.com. 'none' here is a checkout that cannot take a
        // card, which is why this directive is named rather than defaulted.
        'frame-src' => ['https://js.stripe.com', 'https://hooks.stripe.com'],

        // No <video> or <audio> from anywhere but this shop.
        'media-src' => ["'self'"],

        // Nothing registers a worker today. blob: is here because a bundler
        // that starts emitting one would otherwise fail silently.
        'worker-src' => ["'self'", 'blob:'],

        'manifest-src' => ["'self'"],
    ];

    /** The header name, and there is only one it can be. */
    public function headerName(): string
    {
        return self::HEADER;
    }

    /**
     * The policy, with the report address appended.
     *
     * Built from constants and one route lookup — no database read, no cache
     * read, no settings read. It runs on every storefront response that the
     * switch is on for, so it stays as close to free as a string can be.
     */
    public function header(): string
    {
        $parts = [];

        foreach (self::DIRECTIVES as $directive => $sources) {
            $parts[] = $directive.' '.implode(' ', $sources);
        }

        /*
         * `report-uri` AND NOT `report-to`, deliberately.
         *
         * report-to needs a second header (Reporting-Endpoints) and is honoured
         * by Chromium alone; report-uri is deprecated in the spec and honoured
         * by every browser that has ever shipped CSP, Chromium included. One
         * mechanism that every visitor's browser uses beats two that overlap on
         * some of them and would post the same violation twice from the rest.
         * The day Chromium drops report-uri, the round that enforces adds the
         * other and this comment is the record of why it was not here first.
         */
        $parts[] = 'report-uri '.$this->reportUri();

        return implode('; ', $parts);
    }

    /**
     * The path a browser posts a violation to, base path included.
     *
     * Through App\Support\Url::base(), which is the one answer this whole
     * application uses for "what is this shop served under" — the production
     * host serves it from /kbb-upgrade, and a hand-built `/api/csp-report`
     * would be a 404 there and correct everywhere else, which is the exact
     * shape of bug this project has paid for more than once. Url::base()
     * derives it from the request rather than from configuration for the same
     * reason, and memoises, so this costs nothing per response.
     *
     * A PATH AND NOT AN ABSOLUTE URL. A report-uri is resolved against the
     * document, so a path keeps reports same-origin whichever host name the
     * shop is being served under — kbeautybliss.com, the Cloudways staging
     * name, or localhost in a preview — without the policy having to know
     * which, and without a violation report ever being posted off-origin.
     *
     * SecurityCspTest asserts that what this returns is the path the route
     * file actually registers, which is a stronger check than a route lookup
     * here would be and costs the hot path nothing.
     */
    public function reportUri(): string
    {
        return Url::base().self::FALLBACK_PATH;
    }
}
