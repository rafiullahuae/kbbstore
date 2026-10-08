<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentsReadiness;
use App\Services\SecurityModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform -> Domain switch -> "Payments ready?" (Lane DS).
 *
 * POST /admin-api/domain-switch/payments-check, behind its own capability
 * `payments.check` (AdminCapabilities: owner-only, failing closed like every
 * other key). A POST although nothing is written: the checks reach Stripe,
 * Tabby and Tamara, and a GET would let a prefetch, a reload or a link start
 * outbound calls nobody pressed a button for. CSRF-protected like every
 * admin-api write.
 *
 * Read-only at the providers -- see PaymentsReadiness. Logged as a notice, so
 * the Security log shows who looked and when.
 */
final class PaymentsCheckApiController extends Controller
{
    public const AUDIT_EVENT = 'payments_check';

    public function run(Request $request, PaymentsReadiness $readiness): JsonResponse
    {
        $result = $readiness->run();

        try {
            app(SecurityModule::class)->record(self::AUDIT_EVENT, 'Payments check run: '.$result['level'], [
                'subject' => 'payments.check',
                'after' => json_encode($result['counts']),
                'severity' => 'notice',
            ]);
        } catch (\Throwable) {
            // The answer is the point; a log write must not take it away.
        }

        return response()->json($result);
    }
}
