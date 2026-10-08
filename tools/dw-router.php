<?php

declare(strict_types=1);

/*
 * Lane DW: the preview's front controller for `php -S`, with the outside world
 * faked AT THE BOUNDARY and nowhere else (tools/dw-preview.sh starts it).
 *
 *   - Static files are served as files (as tools/m1-router.php does).
 *   - extrabeauty.ae and kbeautybliss.com (reached through Chromium's
 *     --host-resolver-rules) arrive the way Cloudways' TLS-ending proxy hands
 *     them over: HTTPS on, port 443, no port in Host.
 *   - The application is booted from $DW_PREVIEW_DIR/.env, so APP_URL is a
 *     file the shop can really rewrite.
 *   - Once booted, Http::fake() answers every outbound request from
 *     $DW_PREVIEW_DIR/scenario.json: the public DNS resolver, the TLS fetch,
 *     Stripe, Tabby, Tamara and the WordPress picture host. Anything else gets
 *     a 599 "blocked" -- the preview never reaches the network -- and every
 *     outbound request is appended to outbound.log for the report.
 *   - dns_get_record() and the picture fetcher's own resolver are replaced
 *     by stand-ins, for the same reason.
 *
 * Never used outside the preview; nothing in app/ knows it exists.
 */

use App\Services\DomainMove\DnsLookup;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaSideloader;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$dir = (string) getenv('DW_PREVIEW_DIR');
$appRoot = (string) getenv('DW_APP');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';

if ($path !== '/' && ! str_contains($path, '..') && is_file($docroot.$path)) {
    return false;
}

$host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));

if (in_array($host, ['extrabeauty.ae', 'www.extrabeauty.ae', 'kbeautybliss.com', 'www.kbeautybliss.com'], true)) {
    $_SERVER['HTTP_HOST'] = $host;
    $_SERVER['SERVER_NAME'] = $host;
    $_SERVER['SERVER_PORT'] = '443';
    $_SERVER['HTTPS'] = 'on';
}

require $appRoot.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $appRoot.'/bootstrap/app.php';
$app->useEnvironmentPath($dir);

$app->booted(function ($app) use ($dir) {
    $scenario = json_decode((string) @file_get_contents($dir.'/scenario.json'), true) ?: [];

    Http::fake(function (ClientRequest $r) use ($scenario, $dir) {
        $url = $r->url();
        $h = (string) parse_url($url, PHP_URL_HOST);
        $p = (string) parse_url($url, PHP_URL_PATH);
        @file_put_contents($dir.'/outbound.log', $r->method().' '.$url."\n", FILE_APPEND);

        if ($h === 'dns.google') {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $records = $scenario['dns'][$q['name'] ?? ''][$q['type'] ?? ''] ?? [];

            return Http::response(['Status' => 0, 'Answer' => array_map(fn ($v) => [
                'name' => ($q['name'] ?? '').'.', 'type' => DnsLookup::TYPES[$q['type']] ?? 1, 'TTL' => 300, 'data' => $v,
            ], $records)]);
        }

        if ($h === 'kbeautybliss.com' && $p === '/robots.txt') {
            return match ($scenario['tls'] ?? 'nodns') {
                'ok' => Http::response("User-agent: *\n", 200),
                'nocert' => Http::failedConnection('cURL error 60: SSL: no alternative certificate subject name matches target host name'),
                default => Http::failedConnection('cURL error 6: Could not resolve host: kbeautybliss.com'),
            };
        }

        if ($h === 'kbeautybliss.com' && str_contains($p, '/wp-content/uploads/')) {
            return Http::response("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01".str_repeat('*', 2048)."\xFF\xD9", 200, ['Content-Type' => 'image/jpeg']);
        }

        /*
         * Lane DS: the read-only payments check (Platform -> Domain switch ->
         * Payments ready?), answered as scenario.json's `ds` world says.
         * GETs only -- the check never sends anything else.
         */
        if (isset($scenario['ds']) && $r->method() === 'GET') {
            $ds = (array) $scenario['ds'];
            $hook = (string) ($ds['hook_host'] ?? 'extrabeauty.ae');

            if ($h === 'api.stripe.com') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

                return match (true) {
                    $p === '/v1/account' => Http::response(['id' => 'acct_preview', 'charges_enabled' => true, 'business_profile' => ['name' => 'K-Beauty Bliss']]),
                    str_starts_with($p, '/v1/webhook_endpoints/') => Http::response(['id' => basename($p), 'status' => 'enabled',
                        'url' => 'https://'.$hook.'/api/payments/webhook/stripe/whsec-stripe-dwpreview0123456789abcdef',
                        'enabled_events' => \App\Services\Payments\StripeConnect::EVENTS]),
                    $p === '/v1/payment_method_domains' => Http::response(['data' => in_array($q['domain_name'] ?? '', (array) ($ds['wallets'] ?? []), true)
                        ? [['domain_name' => $q['domain_name'], 'enabled' => true, 'apple_pay' => ['status' => 'active']]] : []]),
                    default => Http::response(['error' => ['message' => 'not in the preview']], 404),
                };
            }

            if ($h === 'api.tabby.ai') {
                return Http::response([['id' => 'wh_preview', 'url' => 'https://'.$hook.'/api/payments/webhook/tabby/whsec-tabby-dwpreview0123456789abcdef', 'is_test' => true]]);
            }

            if (str_ends_with($h, 'tamara.co')) {
                return match (true) {
                    str_starts_with($p, '/checkout/payment-types') => Http::response(['message' => 'Unauthorized'], 401),
                    default => Http::response(['message' => 'Not found'], 404),
                };
            }
        }

        if ($h === 'api.stripe.com') {
            if (($scenario['stripe'] ?? 'ok') === 'error') {
                return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: sk_test_****dw']], 401);
            }

            return match (true) {
                $r->method() === 'GET' && str_starts_with($p, '/v1/webhook_endpoints') => Http::response(['data' => [
                    ['id' => 'we_extrabeauty', 'url' => 'https://extrabeauty.ae/api/payments/webhook/stripe/whsec-stripe-dwpreview0123456789abcdef', 'enabled_events' => \App\Services\Payments\StripeConnect::EVENTS],
                ]]),
                $r->method() === 'DELETE' => Http::response(['id' => basename($p), 'deleted' => true]),
                $r->method() === 'POST' && $p === '/v1/webhook_endpoints' => Http::response(['id' => 'we_kbeautybliss', 'url' => (string) ($r->data()['url'] ?? ''), 'secret' => 'whsec_preview_new']),
                default => Http::response(['id' => 'acct_preview', 'settings' => ['payments' => ['statement_descriptor' => 'KBEAUTYBLISS']]]),
            };
        }

        if ($h === 'api.tabby.ai') {
            return match (true) {
                $r->method() === 'GET' => Http::response([]),
                $r->method() === 'POST' && ($scenario['tabby'] ?? 'ok') === 'error' => Http::response(['status' => 'error', 'errorType' => 'bad_data', 'error' => 'url is not reachable'], 400),
                default => Http::response(['id' => 'wh_tabby_preview', 'url' => (string) ($r->data()['url'] ?? ''), 'is_test' => true]),
            };
        }

        if (str_ends_with($h, 'tamara.co')) {
            return match (true) {
                $r->method() === 'DELETE' => Http::response([], 200),
                ($scenario['tamara'] ?? 'ok') === 'error' => Http::response(['message' => 'Service unavailable'], 503),
                default => Http::response(['webhook_id' => 'tw_preview_'.substr(md5((string) microtime(true)), 0, 6)]),
            };
        }

        return Http::response('blocked by the dw preview boundary: '.$url, 599);
    });

    // dns_get_record(): never reached from the preview.
    $app->instance(DnsLookup::class, new class extends DnsLookup
    {
        protected function native(string $host, string $type): ?array
        {
            return null;
        }
    });

    // The picture fetcher's own address check, answered with a public address.
    $app->bind(MediaSideloader::class, fn () => new MediaSideloader(new MediaAudit, null, fn (string $h) => ['93.184.216.34']));
});

$app->handleRequest(Request::capture());
