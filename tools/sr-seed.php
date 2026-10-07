<?php
/* Lane SR (Stripe settings) preview fixture: the pay-seed basket and gateways,
   plus Stripe in TEST mode with both key sets, the new settings filled in, and
   a few payment-log rows so the status block has something to show. Fake keys:
   nothing here can reach Stripe. PREVIEW FIXTURE ONLY. */
require __DIR__ . '/pay-seed.php';

$fake = 'preview-not-a-real-key';
$row = \App\Models\PaymentProvider::find('stripe');
$row->config = [
    'publishable_key_test' => 'pk_test_' . $fake,
    'secret_key_test' => 'sk_test_' . $fake,
    'webhook_signing_secret_test' => 'whsec_' . $fake,
    'webhook_endpoint_id_test' => 'we_1PreviewEndpoint',
    'webhook_endpoint_managed_test' => '1',
    'webhook_secret' => 'whsec-stripe-previewpreviewpreviewAB12',
    'statement_descriptor_suffix' => 'KBB',
    'statement_descriptor_order_number' => '1',
    'order_reference_prefix' => 'KBB-',
    'account_statement_descriptor' => 'KBEAUTYBLISS.COM',
    'account_descriptor_prefix' => 'KBEAUTY',
];
$row->mode = 'test';
$row->save();

app(\App\Services\SettingsService::class)->set('store_name', 'K-Beauty Bliss');

\App\Services\Payments\PaymentLog::record('stripe', 'info', 'webhook.setup', 'Webhook endpoint created at Stripe.', ['webhook_endpoint' => 'we_1PreviewEndpoint', 'action' => 'created', 'removed' => 1], 'test');
\App\Services\Payments\PaymentLog::record('stripe', 'info', 'intent.created', 'Payment started at checkout.', ['order' => '10234', 'payment_intent' => 'pi_3PreviewA', 'amount' => 65400, 'currency' => 'AED', 'capture_method' => 'automatic'], 'test');
\App\Services\Payments\PaymentLog::record('stripe', 'error', 'webhook.received', 'Webhook payment_intent.payment_failed received.', ['event_type' => 'payment_intent.payment_failed', 'order' => '10234', 'outcome' => 'card declined; the payment can still be retried', 'http_status' => 200], 'test');
\App\Services\Payments\PaymentLog::record('stripe', 'info', 'webhook.received', 'Webhook payment_intent.succeeded received.', ['event_type' => 'payment_intent.succeeded', 'order' => '10234', 'outcome' => 'payment applied', 'http_status' => 200], 'test');

echo "lane-sr seeded\n";
