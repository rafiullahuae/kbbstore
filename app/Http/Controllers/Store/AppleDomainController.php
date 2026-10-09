<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Payments\AppleDomainFile;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Wallets;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/.well-known/apple-developer-merchantid-domain-association`
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT APPLE IS ACTUALLY ASKING FOR
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Apple Pay on the web will not draw a sheet on a domain Apple has not
 * verified. Verification is one HTTP GET: Apple (through Stripe, which does
 * the registration on the merchant's behalf) fetches
 *
 *     https://<your domain>/.well-known/apple-developer-merchantid-domain-association
 *
 * (your domain = Platform → Site address, else APP_URL; AppleDomainFile::url())
 * and expects back, byte for byte, the file the Stripe dashboard offers for
 * download at the moment the domain is added. No extension, no redirect, no
 * HTML wrapper, 200 and text.
 *
 * Nothing else about Apple Pay is blocked on anybody: the button, the sheet,
 * the PaymentIntent and the refund are all built. This one file is the whole
 * of what only the owner can do, which is why it gets a route of its own and a
 * numbered page in docs/WALLETS-APPLE-GOOGLE-PAY.md rather than a sentence in
 * a report.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY A ROUTE AND NOT JUST "PUT THE FILE IN public_html"
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Because on THIS deployment the two are different directories. The
 * application root is
 *
 *     /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app
 *
 * and the web root is its sibling `../public_html` — `bootstrap/app.php` ends
 * with a usePublicPath() saying so, and docs/CUTOVER-EXTRABEAUTY.md is the
 * authority. So `public/` in this repository is NOT the directory the web
 * server serves, and a file committed there arrives on the server in a folder
 * nothing can reach over HTTP. Code, meanwhile, travels as a zip applied
 * through Store → Core Updates, which writes into the application root.
 *
 * A route is therefore the one delivery mechanism that works without anybody
 * touching the server at all: the value is pasted into a box on a screen the
 * owner already uses, and this answers with it.
 *
 * THE REAL FILE STILL WINS IF IT IS THERE. A web server serves an existing
 * file before it ever reaches Laravel's front controller, so an owner who
 * would rather scp the file into `public_html/.well-known/` gets exactly that
 * and this route never runs. Both routes to the same outcome, and neither
 * needs the other — which is the point, because Apple's verification is the
 * one step in this whole feature that cannot be retried cheaply.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * SECURE BY CONSTRUCTION
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This prints a SETTING, and CLAUDE.md's rule is that what is printed
 * unescaped is a constant, never a setting. So the value is constrained until
 * it can only be the kind of thing Apple sends, and the response is typed so
 * that even a value that got past the constraint could not be interpreted:
 *
 *   - it is read from the ENCRYPTED gateway config, which only an
 *     authenticated admin with the payments screen can write. It is not in
 *     `settings`, so /api/settings cannot reach it however its allowlist
 *     changes;
 *   - it must be Apple's file: one line of hexadecimal, 200 to 32,768
 *     characters (AppleDomainFile::problem()). That refuses markup, control
 *     characters and whitespace by construction — and, since Lane WL, the
 *     `pmd_…` Stripe domain ID the live shop was serving in its place, which
 *     Apple cannot verify a domain from;
 *   - it is served `text/plain` with `X-Content-Type-Options: nosniff`, so no
 *     browser may decide it is HTML;
 *   - `Content-Disposition: inline` and `X-Robots-Tag: noindex` because this
 *     is machine-read and belongs in no index;
 *   - a missing or refused value is a 404, identical to the one an unrouted
 *     path gets. It never says "there is a value here but it is malformed",
 *     which would be a fact about the shop's configuration handed to anybody
 *     who asked.
 *
 * It is deliberately NOT gated on Apple Pay being switched on. Registration is
 * the step BEFORE the switch can honestly be turned on, so a gate here would
 * make the feature impossible to enable — the classic ordering bug, and one
 * this project has paid for elsewhere.
 */
class AppleDomainController extends Controller
{
    /** The gateway-config key the pasted blob is stored under. */
    public const CONFIG_KEY = AppleDomainFile::CONFIG_KEY;

    /**
     * Where a file placed by hand is looked for, relative to storage/.
     *
     * `storage/app/` and not `public/`, because on this host `public/` is not
     * served (see the class docblock) and `storage/` is inside the application
     * root the update packages write to. An owner with SSH who prefers a file
     * to a paste drops it here; one who prefers the web root drops it there
     * instead and never reaches this controller at all.
     */
    public const FILE_PATH = AppleDomainFile::FILE_PATH;

    /** A bound on the response body. See AppleDomainFile::MAX_BYTES. */
    public const MAX_BYTES = AppleDomainFile::MAX_BYTES;

    public function __invoke(GatewayCredentials $credentials): Response
    {
        $body = $this->fromFile() ?? $this->fromConfig($credentials);

        if ($body === null) {
            abort(404);
        }

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'X-Robots-Tag' => 'noindex',
            /*
             * Not cached, and this is the one place on the shop where that is
             * the right answer for a static-looking document. Apple re-verifies
             * periodically and the owner may paste a new blob the moment Stripe
             * rotates one; a shared cache holding the old one for an hour is a
             * verification that fails for an hour after it was fixed, with
             * nothing on the screen to say why.
             */
            'Cache-Control' => 'no-store',
        ]);
    }

    /** The file an owner with a shell put there, if it is Apple's file. */
    private function fromFile(): ?string
    {
        $raw = AppleDomainFile::onDisk();

        return $raw === null ? null : AppleDomainFile::clean($raw);
    }

    /** The blob pasted into Store → Payments, if it is Apple's file. */
    private function fromConfig(GatewayCredentials $credentials): ?string
    {
        return AppleDomainFile::clean($credentials->get(Wallets::GATEWAY, self::CONFIG_KEY));
    }
}
