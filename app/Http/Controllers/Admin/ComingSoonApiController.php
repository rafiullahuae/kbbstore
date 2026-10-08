<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\ComingSoon;
use App\Support\ComingSoonPage;
use App\Support\SiteHost;
use App\Support\SupportContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appearance -> Coming Soon page (Lane CS). Endpoints in
 * routes/coming-soon-admin.php, all on `comingsoon.manage` (owner only unless
 * a custom role is handed it; EnforceAdminCapability fails closed).
 *
 *   GET  /admin-api/coming-soon            settings, status line, preview link
 *   POST /admin-api/coming-soon            save (on/off, which address, content, link hours)
 *   POST /admin-api/coming-soon/link       a new preview link: every older link and cookie stops working
 *   POST /admin-api/coming-soon/preview    the page drawn from unsaved content (for the screen's frame)
 *   GET  /admin-api/coming-soon/preview    the page as saved, in a tab of its own
 *
 * Every key is written autoload=false, so the shop's own settings map does not
 * grow by a byte; the gate reads them from Setting::map().
 */
final class ComingSoonApiController extends Controller
{
    private const FIELDS = ['on', 'scope', 'host', 'content', 'hours'];

    public function __construct(private SettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function save(Request $request): JsonResponse
    {
        $in = $request->json()->all();
        $unknown = array_diff(array_keys($in), self::FIELDS);

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        $map = Setting::map();
        $errors = [];

        $on = array_key_exists('on', $in) ? filter_var($in['on'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : ComingSoon::on($map);

        if ($on === null) {
            $errors['on'] = 'On or off.';
        }

        $scope = $in['scope'] ?? ComingSoon::scope($map);

        if (! in_array($scope, [ComingSoon::SCOPE_HOST, ComingSoon::SCOPE_ALL], true)) {
            $errors['scope'] = 'Choose "Only this address" or "Every address".';
        }

        $host = array_key_exists('host', $in) ? ComingSoon::cleanHost($in['host']) : ComingSoon::host($map);

        if ($host === null || $host === '') {
            $errors['host'] = 'Type a bare address like kbeautybliss.com — no https://, no /path, no :port.';
        }

        $content = ComingSoon::content($map);

        if (array_key_exists('content', $in)) {
            [$content, $contentErrors] = ComingSoon::cleanContent(is_array($in['content']) ? $in['content'] : [], $this->ownHosts($request));
            $errors += array_combine(array_map(fn ($k) => 'content.'.$k, array_keys($contentErrors)), $contentErrors);
        }

        $hours = array_key_exists('hours', $in) ? filter_var($in['hours'], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) : $this->hours($map);

        if (! in_array($hours, ComingSoon::HOURS, true)) {
            $errors['hours'] = 'Choose one of the offered lengths.';
        }

        if ($errors !== []) {
            return response()->json(['ok' => false, 'error' => 'Not saved — check the marked fields.', 'fields' => $errors], 422);
        }

        $hoursChanged = $hours !== $this->hours($map);

        $this->settings->set(ComingSoon::KEY_ON, $on ? '1' : '0', false);
        $this->settings->set(ComingSoon::KEY_SCOPE, $scope, false);
        $this->settings->set(ComingSoon::KEY_HOST, $host, false);
        $this->settings->set(ComingSoon::KEY_CONTENT, $content, false);
        $this->settings->set(ComingSoon::KEY_HOURS, (string) $hours, false);

        // Switching on with no live link, or changing how long a link lasts,
        // issues a fresh one -- so the owner always has exactly one that works.
        if (($on && $this->link($request, Setting::map()) === null) || $hoursChanged) {
            $this->mint((int) $hours);
        }

        return response()->json(['ok' => true] + $this->payload($request));
    }

    /** "New link": a new secret, so every earlier link and the cookies it set stop working. */
    public function rotate(Request $request): JsonResponse
    {
        $this->mint($this->hours(Setting::map()));

        return response()->json(['ok' => true] + $this->payload($request));
    }

    /** The page from the screen's unsaved fields, for its preview frame. One request per press. */
    public function preview(Request $request): JsonResponse|Response
    {
        $map = Setting::map();
        $lang = $request->input('lang') === 'ar' ? 'ar' : 'en';

        if ($request->isMethod('GET')) {
            $html = ComingSoonPage::html(ComingSoon::content($map), $lang, $map, rtrim($request->getBaseUrl(), '/'));
            $r = ComingSoonPage::headers(new Response($html, 200), $lang);
            $r->headers->remove('Retry-After');

            return $r;
        }

        [$content, $errors] = ComingSoon::cleanContent(is_array($request->input('content')) ? $request->input('content') : [], $this->ownHosts($request));

        if ($errors !== []) {
            return response()->json(['ok' => false, 'error' => 'Check the marked fields.', 'fields' => $errors], 422);
        }

        $html = ComingSoonPage::html($content, $lang, $map, rtrim($request->getBaseUrl(), '/'));

        return response()->json(['ok' => true, 'html' => $html, 'bytes' => strlen($html)]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $map = Setting::map();
        $requestHost = SiteHost::normalise($request->getHost());
        $link = $this->link($request, $map);

        return [
            'config' => [
                'on' => ComingSoon::on($map),
                'scope' => ComingSoon::scope($map),
                'host' => ComingSoon::host($map),
                'content' => ComingSoon::content($map),
                'hours' => $this->hours($map),
            ],
            'status' => ComingSoon::status($map, $requestHost),
            'hosts' => ComingSoon::knownHosts($map, $requestHost),
            'here' => ComingSoon::bare($requestHost),
            'link' => $link,
            'hour_choices' => ComingSoon::HOURS,
            'defaults' => ComingSoon::DEFAULT_TEXT,
            'default_bg' => ComingSoon::DEFAULT_BG,
            'limits' => ComingSoon::LIMITS,
            'contact' => [
                'whatsapp' => SupportContact::whatsappDigits() !== '',
                'instagram' => \App\Support\SafeUrl::web((string) ($map['social_instagram'] ?? 'https://www.instagram.com/kbeauty.bliss/')) !== '',
            ],
            'emergency' => ComingSoon::EMERGENCY,
        ];
    }

    /**
     * The live preview link, or null when there is none (never issued, or expired).
     *
     * @param  array<string, mixed>  $map
     * @return array{url: string, host: string, until: string}|null
     */
    private function link(Request $request, array $map): ?array
    {
        $secret = (string) ($map[ComingSoon::KEY_SECRET] ?? '');
        $until = (int) ($map[ComingSoon::KEY_UNTIL] ?? 0);
        $host = $this->linkHost($request, $map);

        if ($secret === '' || $until <= time() || $host === '') {
            return null;
        }

        $token = ComingSoon::sign($host, $until, $secret);

        return [
            'url' => 'https://'.$host.rtrim($request->getBaseUrl(), '/').'/?'.ComingSoon::QUERY.'='.$token,
            'host' => $host,
            'until' => date(DATE_ATOM, $until),
        ];
    }

    /** The address the link is for: the hidden one, or (every address) the main one. */
    private function linkHost(Request $request, array $map): string
    {
        if (ComingSoon::scope($map) === ComingSoon::SCOPE_HOST) {
            return ComingSoon::host($map);
        }

        return ComingSoon::bare(SiteHost::canonical() !== '' ? SiteHost::canonical() : $request->getHost());
    }

    private function mint(int $hours): void
    {
        $this->settings->set(ComingSoon::KEY_SECRET, bin2hex(random_bytes(32)), false);
        $this->settings->set(ComingSoon::KEY_UNTIL, (string) (time() + $hours * 3600), false);
    }

    /** @param  array<string, mixed>  $map */
    private function hours(array $map): int
    {
        $h = (int) ($map[ComingSoon::KEY_HOURS] ?? ComingSoon::DEFAULT_HOURS);

        return in_array($h, ComingSoon::HOURS, true) ? $h : ComingSoon::DEFAULT_HOURS;
    }

    /** @return list<string> the addresses a Media Library URL may name */
    private function ownHosts(Request $request): array
    {
        return array_values(array_unique(array_filter([
            SiteHost::normalise($request->getHost()),
            SiteHost::canonical(),
            ...SiteHost::aliases(),
            SiteHost::normalise((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
        ])));
    }
}
