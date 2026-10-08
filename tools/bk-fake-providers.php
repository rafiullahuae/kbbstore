<?php
/* Lane BK preview only: every Tabby and Stripe call answered locally, so the
   real place() and the real return legs run end to end with no network.
   Tabby says CREATED (the shopper cancelled); Stripe says requires_payment_method
   (3-D Secure failed). Loaded by the preview's own front controller. */
use App\Models\Order;
use Illuminate\Support\Facades\Http;

Http::fake(function (Illuminate\Http\Client\Request $request) {
    $path = (string) parse_url($request->url(), PHP_URL_PATH);
    $post = $request->method() === 'POST';

    if ($post && $path === '/api/v2/checkout') {
        return Http::response(['id' => 'sess_preview', 'status' => 'created', 'payment' => ['id' => 'tabby-preview-'.uniqid()],
            'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/preview']]]]], 200);
    }
    if (preg_match('#^/api/v2/payments/([^/]+)$#', $path, $m)) {
        $o = Order::where('transaction_id', $m[1])->first();
        return Http::response(['id' => $m[1], 'status' => 'CREATED', 'currency' => 'AED', 'captures' => [],
            'amount' => number_format(((int) $o?->total) / 100, 2, '.', ''), 'order' => ['reference_id' => (string) $o?->order_number]], 200);
    }
    if ($post && $path === '/v1/payment_intents') {
        $id = 'pi_preview_'.bin2hex(random_bytes(4));
        return Http::response(['id' => $id, 'client_secret' => $id.'_secret', 'status' => 'requires_payment_method'], 200);
    }
    if (preg_match('#^/v1/payment_intents/(pi_[a-z_0-9]+)/cancel$#', $path, $m)) {
        return Http::response(['id' => $m[1], 'status' => 'canceled'], 200);
    }
    if (preg_match('#^/v1/payment_intents/(pi_[a-z_0-9]+)$#', $path, $m)) {
        return Http::response(['id' => $m[1], 'status' => 'requires_payment_method', 'currency' => 'aed', 'amount' => 0], 200);
    }

    return Http::response([], 404);
});
