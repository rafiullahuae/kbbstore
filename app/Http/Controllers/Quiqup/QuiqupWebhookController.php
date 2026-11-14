<?php

declare(strict_types=1);

namespace App\Http\Controllers\Quiqup;

use App\Http\Controllers\Controller;
use App\Models\QuiqupConfig;
use App\Models\Shipment;
use App\Services\Quiqup\QuiqupSettings;
use App\Services\Quiqup\ShipmentSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/shipping/quiqup/webhook/{secret} (QUIQUP-PLAN §5.2).
 *
 * Unauthenticated by design: the URL secret and Quiqup's HMAC are the
 * authentication. Custom headers are NOT used, because a delivery-log Retry
 * in Quiqdash does not carry them. Checks, in order, each answered with the
 * same minimal body; the reason goes only to our log:
 *
 *   1. URL secret hash_equals one environment's url_secret          else 404
 *   2. Content-Length / body <= 256 KB                                else 413
 *   3. X-Quiqup-Timestamp digits and within ±300 s                    else 401
 *   4. X-Quiqup-Signature = hex HMAC-SHA256(secret, "{ts}.{raw}")     else 401
 *      (the previous secret is accepted for 24 h after a rotation)
 *   5. type=order, action=update, payload.id int, state well-formed,
 *      state_updated_at parses                                        else 200 ignored
 *   6. shipment by qq_id, then qq_uuid / request_uuid                 else 200 ignored
 *   7. durable INSERT (dedupe) FIRST, then apply AFTER the response; an
 *      apply that throws leaves applied_at NULL for the minute cron.
 *      Only a failed INSERT (database down) answers 5xx, the one case we
 *      want Quiqup to retry.
 *
 * The body is never logged and never stored; only its SHA-256 is kept.
 * The legacy `x-signature` (SHA1) header is never read.
 */
final class QuiqupWebhookController extends Controller
{
    public const MAX_BYTES = 262144;

    public const WINDOW_SECONDS = 300;

    public function __construct(private QuiqupSettings $settings, private ShipmentSync $sync) {}

    public function handle(Request $request, string $secret): JsonResponse
    {
        // Each environment has its own address: a shipment booked in
        // Internal testing keeps reporting on the staging subscription after
        // the mode goes Live, and is verified with the staging secret.
        $env = null;

        foreach (QuiqupSettings::ENVS as $candidate) {
            $expected = $this->settings->urlSecret($candidate);

            if ($expected !== '' && hash_equals($expected, $secret)) {
                $env = $candidate;
            }
        }

        if ($env === null) {
            return $this->reject(404, 'unknown url secret');
        }

        $length = (int) $request->header('Content-Length', '0');

        if ($length > self::MAX_BYTES) {
            return $this->reject(413, 'body too large');
        }

        $raw = (string) $request->getContent();

        if (strlen($raw) > self::MAX_BYTES) {
            return $this->reject(413, 'body too large');
        }

        $ts = (string) $request->header('X-Quiqup-Timestamp', '');

        if (preg_match('/^\d{9,11}$/', $ts) !== 1 || abs(time() - (int) $ts) > self::WINDOW_SECONDS) {
            return $this->reject(401, 'missing or stale timestamp');
        }

        $sig = strtolower((string) $request->header('X-Quiqup-Signature', ''));

        if (preg_match('/^[0-9a-f]{64}$/', $sig) !== 1) {
            return $this->reject(401, 'missing signature');
        }

        $valid = false;

        foreach ($this->settings->signingSecrets($env) as $key) {
            if (hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $key), $sig)) {
                $valid = true;
                break;
            }
        }

        if (! $valid) {
            return $this->reject(401, 'bad signature');
        }

        // Authentic from here on. Record that Quiqup reached us (the health
        // banner on Activity reads this), before deciding what it said.
        $body = json_decode($raw, true);
        $payload = is_array($body) && is_array($body['payload'] ?? null) ? $body['payload'] : null;
        $obs = $payload === null ? null : ShipmentSync::observe($payload);

        QuiqupConfig::query()->whereKey($env)->update([
            'last_webhook_at' => now(),
            'last_webhook_state' => $obs['state'] ?? null,
        ]);

        if (! is_array($body) || ($body['type'] ?? null) !== 'order' || ($body['action'] ?? null) !== 'update'
            || ! is_int($payload['id'] ?? null) || $obs === null) {
            Log::warning('quiqup: webhook ignored (shape)', ['env' => $env]);

            return $this->answer('ignored');
        }

        // Only a shipment booked in THIS environment: staging and production
        // number their orders independently.
        $shipment = Shipment::query()->where('env', $env)->where('qq_id', $payload['id'])->first();

        if ($shipment === null && $obs['uuid'] !== null) {
            $shipment = Shipment::query()->where('env', $env)
                ->where(fn ($q) => $q->where('qq_uuid', $obs['uuid'])->orWhere('request_uuid', $obs['uuid']))->first();
        }

        if ($shipment === null) {
            // An order made by hand in Quiqdash, or one this shop never booked.
            Cache::increment('quiqup:webhook-unknown:' . now()->format('Ymd'));

            return $this->answer('ignored');
        }

        try {
            $event = $this->sync->record($shipment, $obs, 'webhook', hash('sha256', $raw));
        } catch (\Throwable $e) {
            Log::error('quiqup: webhook could not be stored', ['env' => $env, 'shipment' => $shipment->getKey(), 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return response()->json(['result' => 'retry'], 503);
        }

        if ($event !== null) {
            // After the response: status emails and push go out from
            // OrderStatus::moveTo's observers, never inside Quiqup's 15 s.
            app()->terminating(function () use ($event) {
                try {
                    $this->sync->applyEvent($event);
                } catch (\Throwable $e) {
                    Log::error('quiqup: webhook apply failed, the minute cron will retry', ['event' => $event->getKey(), 'error' => mb_substr($e->getMessage(), 0, 200)]);
                    ShipmentSync::markDue((int) $event->shipment_id);
                }
            });
        }

        return $this->answer('ok');
    }

    private function reject(int $status, string $why): JsonResponse
    {
        Log::warning('quiqup: webhook rejected', ['status' => $status, 'why' => $why]);

        return response()->json(['result' => 'rejected'], $status);
    }

    private function answer(string $result): JsonResponse
    {
        return response()->json(['result' => $result === 'ok' ? 'ok' : 'ignored']);
    }
}
