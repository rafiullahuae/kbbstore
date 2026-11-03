<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\GiftOffers\GiftClock;
use App\Services\GiftOffers\GiftOffers;
use App\Services\GiftOffers\GiftOfferStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Marketing -> Gift offers on the shop (Lane GO). routes/gift-offers.php.
 *
 * NOTHING HERE SIGNS ANYBODY IN OR RESTORES A BASKET (CartRecoveryReminder's
 * header on bearer links): a claim link only claims the gift onto the basket
 * the browser already has. An unknown token, a forged one and an ended one
 * all land on the same "This gift has ended" note, so the answer tells
 * nobody which tokens exist.
 */
class GiftOfferController extends Controller
{
    /** A fresh instance per request: Laravel keeps a controller on its route, so a memoised configuration must not outlive the request. */
    private function offers(): GiftOffers
    {
        return app(GiftOffers::class);
    }

    public function claim(Request $request, string $token): RedirectResponse
    {
        [$to] = $this->offers()->claim($token, $request);

        return redirect()->to($to)->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    /**
     * The moving clock. Cached per (end time, minute): a thousand opens in one
     * minute draw it once. Never cached by the client, so the next open shows
     * the time then. Every open is counted (not a test's).
     */
    public function clock(string $token): Response
    {
        $claim = $this->offers()->claimByToken($token);
        $left = 0;
        $end = 0;

        if ($claim !== null && ! in_array($claim->status, ['used', 'ended'], true)) {
            $end = CarbonImmutable::parse($claim->expires_at)->getTimestamp();
            $left = max(0, $end - time());

            if (! $claim->is_test) {
                GiftOfferStats::bump((string) $claim->place, (int) $claim->range_id, 'open');
            }
        }

        $bucket = intdiv(time(), 60);
        $gif = ! GiftClock::available() ? base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==')
            : Cache::remember('gift_offer_gif:' . ($left > 0 ? $end . ':' . $bucket : 'zero'), 120, static fn () => GiftClock::render(max(0, $end - $bucket * 60)));

        return response($gif, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function popup(Request $request): JsonResponse
    {
        $to = $this->offers()->claimFromPopup($request);

        return $to === null ? response()->json(['ok' => false], 409) : response()->json(['ok' => true, 'url' => $to]);
    }

    /** The popup was shown or dismissed: one count, nothing stored about the shopper. */
    public function event(Request $request): Response
    {
        $e = (string) $request->input('e', '');
        $range = (int) $request->input('r', 0);

        if (in_array($e, ['shown', 'dismissed'], true) && $this->offers()->placeOn('popup') && $this->offers()->rangeById($range) !== null) {
            GiftOfferStats::bump('popup', $range, $e);
        }

        return response()->noContent();
    }
}
