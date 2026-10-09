<?php

declare(strict_types=1);

namespace App\Services\Pixels;

use App\Services\Analytics;
use App\Services\Seo\MerchantFeed;
use Illuminate\Support\Facades\Http;

/**
 * The "Check" buttons of the Connect wizards. (Lane MP)
 *
 * Each answers {ok, steps: [{label, ok, message}]} where `message` is the
 * platform's own words when it refused, so the owner sees "Invalid OAuth
 * access token" rather than a guess of ours.
 *
 *   meta    GET the pixel with the token (proves both are right and belong
 *           together), then — with a Test Events code — send one PageView
 *           test event and read back events_received.
 *   ga4     GA4's validation server checks a sample purchase body; then one
 *           `kbb_connection_test` event goes to the real endpoint with
 *           debug_mode, to be seen in Admin → DebugView. Google's validator
 *           does NOT check the API secret, and the real endpoint answers 2xx
 *           whatever it is sent, so the honest last step is "look in
 *           DebugView", and the screen says so.
 *   tiktok  one ViewContent test event with the Test Events code, read back
 *           from TikTok's `code` / `message`.
 *   live    the shop's own home page, fetched as the internet sees it, read
 *           for each configured pixel's loader and both verification tags.
 *   feed    the catalog feeds built and parsed, every item checked for the
 *           attributes Merchant Center, Commerce Manager and TikTok require.
 */
final class ConnectChecks
{
    public function __construct(
        private Analytics $analytics,
        private PixelConfig $config,
        private PlatformClient $client,
    ) {}

    public function meta(): array
    {
        $steps = [];
        $pixel = $this->analytics->validId('meta');
        $token = $this->config->secret('meta_capi_token');

        if ($pixel === null || preg_match('/^\d{8,20}$/', $pixel) !== 1) {
            return self::result([['Pixel (dataset) ID', false, 'Paste the Pixel ID first: 15–16 digits, from Events Manager → Data sources.']]);
        }

        $steps[] = ['Pixel (dataset) ID', true, $pixel];

        if ($token === null) {
            $steps[] = ['Conversions API token', false, 'Paste the access token from Events Manager → your dataset → Settings → Conversions API → Generate access token.'];

            return self::result($steps);
        }

        $info = $this->client->metaPixel($pixel, $token);
        $steps[] = ['Token can reach this pixel', $info['ok'], $info['ok'] ? 'Meta knows it as “' . (string) ($info['data']['name'] ?? $pixel) . '”.' : $info['message']];

        if (! $info['ok']) {
            return self::result($steps);
        }

        $test = $this->config->get('meta_test_code');

        if ($test === '') {
            $steps[] = ['Test event', null, 'Optional: paste a Test Events code (Events Manager → Test events) and press Check again to send one test event.'];

            return self::result($steps);
        }

        $event = ServerEvents::metaEvent('PageView', 'kbb-test-' . time(), time(), [
            'ip' => request()->ip(), 'ua' => (string) request()->userAgent(), 'url' => $this->siteBase() . '/',
        ], [], []);
        $sent = $this->client->metaEvents($pixel, $token, [$event], $test);
        $received = (int) ($sent['data']['events_received'] ?? 0);
        $steps[] = ['Test event', $sent['ok'] && $received > 0, $sent['ok']
            ? "Meta received {$received} event. Open Events Manager → Test events: a PageView from “Server” is there now."
            : $sent['message']];

        return self::result($steps);
    }

    public function ga4(): array
    {
        $id = $this->analytics->validId('ga4');
        $secret = $this->config->secret('ga4_api_secret');

        if ($id === null || ! str_starts_with($id, 'G-')) {
            return self::result([['Measurement ID', false, 'Paste the Measurement ID first (G-XXXXXXXXXX), from Admin → Data streams → your web stream.']]);
        }

        $steps = [['Measurement ID', true, $id]];

        if ($secret === null) {
            $steps[] = ['API secret', false, 'Create one in Admin → Data streams → your stream → Measurement Protocol API secrets → Create, and paste its “Secret value”.'];

            return self::result($steps);
        }

        $sample = ServerEvents::ga4Body('555.1700000000', null, 'purchase', [
            'transaction_id' => 'KBB-TEST', 'value' => 10.0, 'currency' => 'AED',
            'items' => [['item_id' => '1', 'item_name' => 'Test', 'price' => 10.0, 'quantity' => 1]],
        ]);
        $check = $this->client->ga4($id, $secret, $sample, true);
        $messages = $check['data']['validationMessages'] ?? null;
        $valid = $check['ok'] && is_array($messages) && $messages === [];
        $steps[] = ['Purchase format (Google validation server)', $valid, $valid
            ? 'Google accepts the purchase this shop sends.'
            : ($check['ok'] ? (string) ($messages[0]['description'] ?? 'Google returned validation messages.') : $check['message'])];

        if (! $valid) {
            return self::result($steps);
        }

        $test = ServerEvents::ga4Body('555.' . time(), null, 'kbb_connection_test', ['debug_mode' => 1]);
        $sent = $this->client->ga4($id, $secret, $test);
        $steps[] = ['Test event sent', $sent['ok'], $sent['ok']
            ? 'Sent. Google does not confirm the secret itself — open Admin → DebugView: “kbb_connection_test” appears within a minute if the secret is right.'
            : $sent['message']];

        return self::result($steps);
    }

    public function tiktok(): array
    {
        $pixel = $this->analytics->validId('tiktok');
        $token = $this->config->secret('tiktok_token');
        $test = $this->config->get('tiktok_test_code');

        if ($pixel === null || preg_match('/^[A-Z0-9]{10,30}$/', $pixel) !== 1) {
            return self::result([['Pixel ID', false, 'Paste the Pixel ID first (capital letters and digits, about 20), from Events Manager → Web.']]);
        }

        $steps = [['Pixel ID', true, $pixel]];

        if ($token === null) {
            $steps[] = ['Events API token', false, 'Events Manager → your pixel → Settings → Events API → Generate Access Token, then paste it.'];

            return self::result($steps);
        }

        if ($test === '') {
            $steps[] = ['Test event', false, 'Paste the Test Events code (Events Manager → your pixel → Test events → Server) so the check does not count as a real visit.'];

            return self::result($steps);
        }

        $body = ServerEvents::tiktokBody($pixel, 'ViewContent', 'kbb-test-' . time(), time(), [
            'ip' => request()->ip(), 'ua' => (string) request()->userAgent(), 'url' => $this->siteBase() . '/',
        ], [], ['currency' => 'AED', 'value' => 1], $test);
        $sent = $this->client->tiktok($token, $body);
        $steps[] = ['Test event', $sent['ok'], $sent['ok']
            ? 'TikTok accepted it. Events Manager → Test events shows a ViewContent from “Server”.'
            : $sent['message']];

        return self::result($steps);
    }

    /** The live home page, as a visitor's browser would receive it. */
    public function live(): array
    {
        $base = $this->siteBase();
        $host = (string) parse_url($base, PHP_URL_HOST);

        if ($host === '' || ! str_starts_with($base, 'http')) {
            return self::result([['Shop address', false, 'The shop address is not set (Platform → Site address).']]);
        }

        try {
            $response = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'KBB-PixelCheck/1.0', 'Cache-Control' => 'no-cache'])->get($base . '/');
        } catch (\Throwable $e) {
            return self::result([['Home page', false, 'Could not open ' . $base . '/: ' . mb_substr($e->getMessage(), 0, 160)]]);
        }

        if (! $response->successful()) {
            return self::result([['Home page', false, $base . '/ answered HTTP ' . $response->status() . '.']]);
        }

        $html = (string) $response->body();
        $steps = [['Home page', true, $base . '/ (' . number_format(strlen($html) / 1024, 0) . ' KB)']];
        $want = array_filter([
            'meta' => $this->analytics->validId('meta'),
            'ga4' => $this->analytics->validId('ga4'),
            'tiktok' => $this->analytics->validId('tiktok'),
        ]);

        if ($this->analytics->enabled()) {
            foreach ($want as $network => $id) {
                $found = match ($network) {
                    'meta' => str_contains($html, 'fbevents.js') && str_contains($html, "fbq('init'," . json_encode($id)),
                    'ga4' => str_contains($html, 'gtag/js?id=' . rawurlencode($id)),
                    'tiktok' => str_contains($html, 'ttq.load(' . json_encode($id)),
                };
                $label = ['meta' => 'Meta Pixel', 'ga4' => 'Google tag', 'tiktok' => 'TikTok Pixel'][$network];
                $steps[] = [$label . ' on the page', $found, $found ? 'Found ' . $id . '.' : 'Not found. If you just saved, a page cache may still hold the old copy.'];
            }
        } elseif ($want !== []) {
            $steps[] = ['Pixels', false, 'The Marketing Pixels module is off (Store → Modules), so no pixel is printed.'];
        }

        foreach (['google' => $this->config->googleVerification(), 'meta' => $this->config->metaVerification()] as $which => $token) {
            if ($token === null) {
                continue;
            }
            $name = $which === 'google' ? 'google-site-verification' : 'facebook-domain-verification';
            $found = str_contains($html, '<meta name="' . $name . '" content="' . $token . '"');
            $steps[] = [($which === 'google' ? 'Google' : 'Meta') . ' verification tag', $found, $found ? 'Found.' : 'Not found on the home page.'];
        }

        return self::result($steps);
    }

    /** Build each feed and check every item. */
    public function feed(MerchantFeed $feed): array
    {
        if (! MerchantFeed::enabled()) {
            return self::result([['Product feed', false, 'The feed is switched off (Growth & Marketing → Google Shopping feed).']]);
        }

        $steps = [];

        foreach ([MerchantFeed::SCHEME_SKU => 'Google feed', MerchantFeed::SCHEME_SHOP => 'Meta / TikTok feed'] as $scheme => $label) {
            $xml = (string) ($feed->cached($scheme)['xml'] ?? '');
            $steps[] = self::feedReport($label, $xml);
        }

        return self::result($steps);
    }

    /** @return array{0: string, 1: bool, 2: string} */
    public static function feedReport(string $label, string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            return [$label, false, 'The XML does not parse.'];
        }

        $required = ['id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'condition'];
        $count = 0;
        $problems = [];

        foreach ($doc->channel->item ?? [] as $item) {
            $count++;
            $g = $item->children('http://base.google.com/ns/1.0');
            $missing = [];

            foreach ($required as $field) {
                if (trim((string) ($g->{$field} ?? '')) === '') {
                    $missing[] = $field;
                }
            }

            if (trim((string) ($g->brand ?? '')) === '' && trim((string) ($g->gtin ?? '')) === '') {
                $missing[] = 'brand or gtin';
            }

            if (! preg_match('/^\d+(\.\d{2})? [A-Z]{3}$/', (string) ($g->price ?? ''))) {
                $missing[] = 'price format';
            }

            if ($missing !== [] && count($problems) < 5) {
                $problems[] = (string) ($g->id ?? '?') . ': ' . implode(', ', $missing);
            }
        }

        if ($count === 0) {
            return [$label, false, 'The feed has no products in it yet.'];
        }

        return [$label, $problems === [], $problems === []
            ? number_format($count) . ' items, every one with the required attributes.'
            : number_format($count) . ' items; needs attention — ' . implode(' · ', $problems)];
    }

    private function siteBase(): string
    {
        return rtrim(substr(MerchantFeed::url(), 0, -strlen(MerchantFeed::PATH)), '/');
    }

    /** @param list<array{0: string, 1: ?bool, 2: string}> $steps */
    private static function result(array $steps): array
    {
        $ok = $steps !== [] && ! in_array(false, array_column($steps, 1), true);

        return ['ok' => $ok, 'steps' => array_map(fn ($s) => ['label' => $s[0], 'ok' => $s[1], 'message' => $s[2]], $steps)];
    }
}
