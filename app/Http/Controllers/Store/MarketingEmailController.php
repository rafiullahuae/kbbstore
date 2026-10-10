<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Analytics\Tracker;
use App\Services\Marketing\Blocks;
use App\Services\Marketing\CampaignLinks;
use App\Services\Marketing\UnsubscribeToken;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public end of Marketing Emails (Lane MK, docs/EMAILS-PLAN.md §5):
 * unsubscribe (page + RFC 8058 one-click), the click redirect, and the
 * pictures that ship with the shop. routes/marketing-public.php has the why
 * of each guard; this class is the how.
 *
 * NOTHING HERE PRINTS AN ADDRESS. The holder of a link is not necessarily the
 * person it was sent to, so neither page says who is being unsubscribed.
 */
final class MarketingEmailController extends Controller
{
    /**
     * The unsubscribe page. Changes nothing — a link scanner or an inbox's
     * prefetch fetching this URL must not unsubscribe anybody — and renders
     * for ANY token: the token is judged on submit, so this page is not an
     * oracle for "is this link live?".
     */
    public function unsubscribePage(string $token): Response
    {
        return response()->view('store.marketing.unsubscribe', ['token' => mb_substr($token, 0, 60), 'seoCtx' => self::seo()])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * The unsubscribe, from the page's button or a provider's one-click POST.
     *
     * Idempotent: the suppression is an insert-or-ignore on a unique address,
     * the newsletter row is set to `unsubscribed` (one unsubscribe writes both,
     * plan §2.2), and the campaign's count moves only the first time.
     *
     * 200 for every token. A one-click POST is answered with a short text body;
     * the page's own button with the page.
     */
    public function unsubscribe(Request $request, string $token): Response
    {
        $row = UnsubscribeToken::find($token);

        if ($row !== null) {
            $email = mb_strtolower(trim((string) $row->email));

            DB::table('email_suppressions')->insertOrIgnore([
                'email' => $email,
                'reason' => 'unsubscribe',
                'source' => 'campaign:' . (int) $row->campaign_id,
                'created_at' => now(),
            ]);

            DB::table('subscribers')->where('email', $email)->where('status', '<>', 'unsubscribed')
                ->update(['status' => 'unsubscribed', 'updated_at' => now()]);

            if (DB::table('mkt_sends')->where('id', $row->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]) === 1) {
                DB::table('mkt_campaigns')->where('id', $row->campaign_id)->increment('unsubscribes');
            }
        }

        if ($this->isOneClick($request)) {
            return response($row !== null ? 'Unsubscribed.' : 'Done.', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response()->view('store.marketing.unsubscribed', ['ok' => $row !== null, 'seoCtx' => self::seo()])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * A click. Redirects ONLY to row $n of the token's campaign's mkt_links;
     * an unknown token, a malformed one or an $n the campaign does not have
     * all go to the shop's home page. The destination never comes from the
     * request, so no URL anybody types can make this an open redirect.
     */
    public function click(Request $request, string $token, string $n): RedirectResponse
    {
        $home = Url::external('/');
        $token = preg_match('/^[0-9a-f]{40}$/', $token) === 1 ? $token : str_repeat('x', 40);
        $n = preg_match('/^\d{1,5}$/', $n) === 1 ? (int) $n : 0;

        $send = DB::table('mkt_sends')->where('token', $token)->first(['id', 'campaign_id', 'first_click_at']);
        $link = $send !== null && $n > 0
            ? DB::table('mkt_links as l')->join('mkt_campaigns as c', 'c.id', '=', 'l.campaign_id')
                ->where('l.campaign_id', $send->campaign_id)->where('l.n', $n)->first(['l.id', 'l.url', 'c.name'])
            : null;

        if ($send === null || $link === null || Blocks::safeUrl((string) $link->url) === null) {
            return redirect()->away($home, 302)->header('Referrer-Policy', 'no-referrer');
        }

        // Lane ER: the device of the click (never the user agent itself).
        $ua = (string) $request->userAgent();
        DB::table('mkt_clicks')->insert(['send_id' => $send->id, 'link_id' => $link->id, 'clicked_at' => now(),
            'dev' => Tracker::isBot($ua) ? 'bot' : Tracker::device($ua)]);

        if ($send->first_click_at === null) {
            DB::table('mkt_sends')->where('id', $send->id)->whereNull('first_click_at')->update(['first_click_at' => now()]);
        }

        DB::table('mkt_campaigns')->where('id', $send->campaign_id)->increment('clicks');

        // Lane ER: lands with utm_source=email&utm_medium=marketing&utm_campaign=mkt-<id>-…
        // when the link is the shop's own and does not carry them already.
        return redirect()->away(CampaignLinks::tag((string) $link->url, (int) $send->campaign_id, (string) $link->name), 302)
            ->header('Referrer-Policy', 'no-referrer');
    }

    /** A picture that ships with the shop, from Blocks::ART only. */
    public function art(string $name): Response
    {
        $art = Blocks::ART[$name] ?? null;
        $path = $art !== null ? resource_path('mail-art/' . $art['file']) : null;

        if ($path === null || ! is_file($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Not for search engines, and the token is not echoed into the <head>:
     * the canonical and og:url name the shop, not this one link.
     *
     * @return array{noindex:bool, url:string}
     */
    private static function seo(): array
    {
        return ['noindex' => true, 'url' => Url::to('/')];
    }

    private function isOneClick(Request $request): bool
    {
        return trim((string) $request->input('List-Unsubscribe', '')) === 'One-Click'
            || str_contains((string) $request->getContent(), 'List-Unsubscribe=One-Click');
    }
}
