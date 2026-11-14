<?php

declare(strict_types=1);

use App\Models\QuiqupConfig;
use App\Models\Shipment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\QuiqupKit;
use Tests\Support\QuiqupRoutes;

/*
 * /api/* is unauthenticated (CLAUDE.md). Nothing Quiqup-related may be
 * reachable from it except the webhook receiver, which answers
 * {"result": ...} and nothing else (QUIQUP-PLAN §4.4). The failure this
 * prevents is the one ApiSecurityTest exists for: a model returned whole,
 * here with a customer's delivery state, a courier note or the API key.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    QuiqupRoutes::wire($this->app);
    QuiqupKit::configure();
});

it('exposes no Quiqup route under /api except the webhook receiver', function () {
    $api = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/') && (str_contains($r->uri(), 'quiqup') || str_contains($r->uri(), 'shipment')))
        ->map(fn ($r) => implode('|', $r->methods()) . ' ' . $r->uri())->values()->all();

    expect($api)->toBe(['POST api/shipping/quiqup/webhook/{secret}']);
});

it('keeps secrets, the draft and the courier note out of every serialisation', function () {
    $s = QuiqupKit::shipment(QuiqupKit::order(), ['draft' => ['destination' => ['contact_phone' => '+971501234567']], 'last_reason' => 'Customer said come tomorrow']);
    $json = json_encode(Shipment::find($s->id)) . json_encode(QuiqupConfig::all());

    expect($json)->not->toContain('+971501234567')
        ->not->toContain('come tomorrow')
        ->not->toContain('qq_test_key_abcd1234')
        ->not->toContain(QuiqupKit::SIGNING)
        ->not->toContain(QuiqupKit::URL_SECRET);
});

it('adds nothing Quiqup to the public settings endpoint', function () {
    $body = $this->getJson('/api/settings')->getContent();
    expect(strtolower($body))->not->toContain('quiqup');
});

it('answers the webhook with a one-key body only', function () {
    $s = QuiqupKit::shipment(QuiqupKit::order(), ['state_at' => '2026-01-01 00:00:00']);
    $raw = QuiqupKit::webhookBody($s, 'collected', now()->utc()->toIso8601String());
    $r = $this->call('POST', '/api/shipping/quiqup/webhook/' . QuiqupKit::URL_SECRET, [], [], [], QuiqupKit::signed($raw), $raw);

    expect(array_keys($r->json()))->toBe(['result']);
});
