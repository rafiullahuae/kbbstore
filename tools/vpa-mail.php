<?php
/*
 * Lane VPA: the order confirmation for the preview's English and Arabic
 * orders, rendered exactly as OrderMailer sends it (inside
 * OrderLocale::render), written into the preview webroot for a picture.
 */
$root = getenv('KBB_PUBLIC_PATH');

foreach (['VPA-EN' => 'en', 'VPA-AR' => 'ar'] as $number => $tag) {
    $order = \App\Models\Order::where('order_number', $number)->firstOrFail();
    $html = \App\Support\OrderLocale::render($order, fn () => (new \App\Mail\OrderConfirmation($order))->render());
    file_put_contents($root.'/vpa-mail-'.$tag.'.html', $html);
    echo $number.': '.(str_contains($html, 'Shade: #13 Bright Beige') ? 'EN shade' : '').(str_contains($html, 'الدرجة') ? ' AR shade' : '')."\n";
}
