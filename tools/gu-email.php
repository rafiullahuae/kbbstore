<?php

declare(strict_types=1);

/*
 * Lane GU: the basket reminder email, rendered EN and AR into storage/gu-shots/
 * for tools/gu-shots.cjs. Run inside tools/fg-preview.sh's environment (a
 * preview database, never a real one): a basket of AED 650, inside a gift range.
 */

$out = __DIR__ . '/../storage/gu-shots';
@mkdir($out, 0777, true);
$items = [['name' => 'Glow Ritual Set 650', 'slug' => 'glow-ritual-set-650', 'quantity' => 1, 'unit_price' => 65000]];

foreach (['en', 'ar'] as $lang) {
    app()->setLocale($lang);
    app()->forgetInstance(\App\Services\FreeGifts::class);
    $html = (new \App\Mail\CartRecoveryReminder($lang === 'ar' ? 'سلتكِ بانتظاركِ' : 'Your basket is waiting', 'Hi', $items, url('/cart/'), url('/u')))->render();
    if ($lang === 'ar' && class_exists(\App\Services\Mail\Kit\KitRtl::class) && method_exists(\App\Services\Mail\Kit\KitRtl::class, 'mirror') && ! str_contains($html, 'dir="rtl"')) {
        $html = \App\Services\Mail\Kit\KitRtl::mirror($html);
    }
    file_put_contents("$out/email-$lang.html", $html);
    echo "email-$lang ", strlen($html), ' ', substr_count($html, 'Gifts go') + substr_count($html, 'الهدايا تنفد'), "\n";
}
