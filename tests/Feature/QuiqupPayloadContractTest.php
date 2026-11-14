<?php

declare(strict_types=1);

use App\Services\Quiqup\OrderDraft;
use App\Services\Quiqup\QuiqupClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuiqupKit;

/*
 * Our create body against the OpenAPI contract, KEY BY KEY
 * (tests/Fixtures/quiqup/schema-keys.json is generated from
 * docs/plans/quiqup/api-docs/qq-orders.yaml). Quiqup silently ignores unknown
 * fields, so `phone` for `contact_phone` would book an order with no phone
 * and no error. MUTATION: rename contact_phone -> phone in
 * OrderDraft::payload() and the first test is red.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    QuiqupKit::configure('sandbox', 'k', ['test' => ['emails' => [], 'phones' => ['+971501234567'], 'orders' => []]]);
});

function qqPayloadFor(array $orderAttrs = [], array $defaults = []): array
{
    $o = QuiqupKit::order($orderAttrs);
    DB::table('order_items')->insert(['order_id' => $o->id, 'name' => 'Beauty of Joseon Relief Sun', 'sku' => 'BOJ-SUN', 'quantity' => 1, 'unit_price' => 6900, 'subtotal' => 6900, 'created_at' => now(), 'updated_at' => now()]);
    $d = app(OrderDraft::class)->drafts([$o->id], $defaults)[0];
    expect($d['blocks'])->toBe([]);

    return app(OrderDraft::class)->payload($d, OrderDraft::requestUuid('sandbox', $o->id, 0));
}

it('uses only fields the OpenAPI contract declares, at every level', function () {
    $k = QuiqupKit::json('schema-keys.json');

    foreach ([qqPayloadFor(), qqPayloadFor(['payment_method' => 'stripe', 'paid_at' => now()], ['service' => 'partner_4hr'])] as $p) {
        expect(array_diff(array_keys($p), $k['OrderRequest']))->toBe([])
            ->and(array_diff($k['OrderRequest.required'], array_keys($p)))->toBe([]);

        foreach (['origin', 'destination'] as $stop) {
            expect(array_diff(array_keys($p[$stop]), $k['OrderRequestAddress']))->toBe([], $stop)
                ->and(array_diff($k['OrderRequestAddress.required'], array_keys($p[$stop])))->toBe([], $stop)
                ->and(array_diff(array_keys($p[$stop]['address']), $k['Address']))->toBe([], $stop)
                ->and($p[$stop]['address'])->toHaveKey('address1')
                ->and($p[$stop]['contact_phone'])->toMatch('/^\+[1-9]\d{7,14}$/');
        }

        foreach ($p['items'] as $item) {
            expect(array_diff(array_keys($item), $k['OrderRequestItem']))->toBe([])->and($item['name'])->not->toBe('');
        }

        foreach ($p['products'] as $pr) {
            expect(array_diff(array_keys($pr), $k['OrderRequestProduct']))->toBe([])
                ->and(mb_strlen($pr['description']))->toBeLessThanOrEqual(18);
        }

        expect(in_array($p['kind'], $k['OrderRequestKind'], true))->toBeTrue()
            ->and($p['payment_mode'])->toBeIn(['pre_paid', 'paid_on_delivery'])
            ->and($p['uuid'])->toMatch('/^[0-9a-f-]{36}$/');
    }
});

it('matches the shape of the payload Quiqup support sends (docs example), plus uuid and emirate', function () {
    $doc = QuiqupKit::json('create-request-doc.json');
    $ours = qqPayloadFor();

    expect(array_keys($ours['origin']['address']))->toEqualCanonicalizing(array_keys($doc['origin']['address']))
        ->and(array_keys($ours['items'][0]))->toEqualCanonicalizing(array_keys($doc['items'][0]))
        ->and($ours['destination']['address']['country'])->toBe('AE');
});

it('puts the 4-hour collection time only on a 4-hour order, in UTC with Z', function () {
    expect(qqPayloadFor())->not->toHaveKey('scheduled_for');
    $p = qqPayloadFor([], ['service' => 'partner_4hr']);
    expect($p['scheduled_for'])->toMatch('/^\d{4}-\d{2}-\d{2}T(0[4-9]|1[0-4]):\d{2}:00Z$/');
});

it('sends the body to POST /orders on the staging host exactly as built', function () {
    Http::fake(['*' => Http::response(QuiqupKit::json('create-response.json'), 201)]);
    $p = qqPayloadFor();

    $r = app(QuiqupClient::class)->createOrder('sandbox', $p);

    expect($r->order()['tracking_url'])->toBe('https://track-parcel.quiqup.com/00000000-0000-4000-8000-000000000001');
    Http::assertSent(fn (Request $req) => $req->url() === 'https://api.staging.quiqup.com/orders' && $req->data() === $p);
});
