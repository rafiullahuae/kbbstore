<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Mail\Kit\WebCopy;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two public answers the email kit needs — Lane RM. routes/mail-kit.php.
 *
 * webCopy()  "View this email in your browser": the stored HTML of one sent
 *            message, or a plain 404 that is the same for a forged, malformed,
 *            expired or never-sent token (WebCopy::find() does one lookup for
 *            all four). Not indexed, not cached by anything shared, framed by
 *            nobody, and with a policy that lets the page load its pictures
 *            and styles and nothing else — it is a stored document, never an
 *            application page.
 *
 * font()     The Outfit file at a fixed address (MailKit::FONT_PATH), for the
 *            @font-face rule in every email. Cached for a year: the bytes at
 *            this path never change.
 */
final class MailKitController extends Controller
{
    public function webCopy(string $token): Response
    {
        $html = WebCopy::find($token);

        if ($html === null) {
            abort(404);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            /*
             * No enforcing content-security header (Lane EM): this codebase
             * ships report-only and nothing else, and SecurityCspTest holds
             * app/ to the absence of the enforcing name. The copy is the
             * shop's own rendered email -- no script, every value escaped when
             * it was rendered -- so what is left to stop is framing and
             * sniffing: X-Frame-Options is SecurityHeaders' SAMEORIGIN on every
             * response, and nosniff is set here.
             */
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function font(): BinaryFileResponse
    {
        return response()->file(resource_path('fonts/outfit/outfit-latin.woff2'), [
            'Content-Type' => 'font/woff2',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
