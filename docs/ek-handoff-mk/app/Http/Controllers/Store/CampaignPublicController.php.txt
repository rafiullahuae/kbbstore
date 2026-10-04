<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Marketing\CampaignTracking;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The three public addresses in a campaign email — Lane EK. CampaignTracking's
 * header is the security argument; this is only the HTTP around it.
 *
 *   GET  /m/o/{token}                   the 1×1 open pixel: always the same
 *                                       43-byte GIF, whatever the token
 *   GET  /m/c/{token}/{link}/{sig}      a tracked click: 302 to the campaign's
 *                                       own link, or to the home page
 *   GET  /m/u/{token}                   the unsubscribe page (changes nothing)
 *   POST /m/u/{token}                   unsubscribe, now — the page's button and
 *                                       the mail client's one-click POST
 *
 * None of these is under /api/*, and none returns anything about a recipient:
 * not the address, not the campaign, not whether the token was real.
 */
class CampaignPublicController extends Controller
{
    /** A transparent 1×1 GIF. */
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(private CampaignTracking $tracking) {}

    public function open(string $token): Response
    {
        try {
            $this->tracking->open($token);
        } catch (\Throwable) {
            // A pixel never fails in public.
        }

        return response((string) base64_decode(self::GIF), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function click(string $token, string $link, string $sig): RedirectResponse
    {
        $url = null;

        try {
            $url = ctype_digit($link) && strlen($link) < 6 ? $this->tracking->click($token, (int) $link, $sig) : null;
        } catch (\Throwable) {
            $url = null;
        }

        return redirect()->away($url ?? Url::external('/'), 302)
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function page(string $token): Response
    {
        return response()->view('store.marketing.unsubscribe', [
            'action' => Url::to('/m/u/' . rawurlencode($token)),
        ])->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store');
    }

    public function unsubscribe(Request $request, string $token): Response
    {
        $ok = false;

        try {
            $ok = $this->tracking->unsubscribe($token);
        } catch (\Throwable) {
            $ok = false;
        }

        // RFC 8058: the mail client posts List-Unsubscribe=One-Click and reads
        // only the status. A person pressing the button gets the page.
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response($ok ? 'Unsubscribed.' : 'Not found.', $ok ? 200 : 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response()->view('store.newsletter.done', ['ok' => $ok, 'action' => 'unsubscribe'])
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store');
    }
}
