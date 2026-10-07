<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentLog;
use App\Services\Payments\StripeConnect;
use Illuminate\Http\JsonResponse;

/**
 * Store -> Payments -> Stripe: the status block, "Set up webhook
 * automatically" and the payment log. (Lane SR.)
 *
 *   GET  /admin-api/payments/stripe/webhook         payments.stripe_webhook
 *   POST /admin-api/payments/stripe/webhook/setup   payments.stripe_webhook
 *   GET  /admin-api/payments/stripe/log             payments.log
 *
 * Each has its own capability in App\Support\AdminCapabilities, owner-only as
 * this ships, and an unmapped route would fail closed anyway. None of the
 * three accepts a key, returns a key or echoes anything the browser sent: the
 * setup uses the key already stored for the current mode, and both reads are
 * allowlisted by the service that builds them (StripeConnect::settingsStatus(),
 * PaymentLog::present()).
 */
class StripeSettingsController extends Controller
{
    public function __construct(private StripeConnect $connect) {}

    public function status(): JsonResponse
    {
        return response()->json($this->connect->settingsStatus());
    }

    public function setup(): JsonResponse
    {
        $result = $this->connect->setupWebhook();

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function log(): JsonResponse
    {
        return response()->json([
            'keep' => PaymentLog::KEEP,
            'rows' => PaymentLog::recent(StripeConnect::GATEWAY, 100),
        ]);
    }
}
