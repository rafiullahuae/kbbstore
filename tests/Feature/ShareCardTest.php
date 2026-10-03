<?php

declare(strict_types=1);

/**
 * The link preview card a shared product link draws.            (2.60.365)
 *
 * THE OWNER picked template C off the preview sheet — "icon point is fine. but
 * give options to chooose from backend and control everything." So C ships ON
 * and every part of it is a control on Appearance → Product page →
 * Share · Link preview card.
 *
 * THE DEFECT THIS PINS: a shared product link drew the page title and the short
 * description, and WhatsApp's message was "<name> – <price> ⏎ <blurb> ⏎ <url>".
 * He wanted picture + title + the three points + the domain, and "See what
 * I've found on K-Beauty Bliss" above the link.
 *
 * MUTATION NOTES, RUN:
 *   · Seo::render() printing $title / $desc for og:title / og:description again
 *     → the first case is RED (og:description is the short description).
 *   · ShareCard::message() returning null → the WhatsApp expectation is RED.
 *   · ShareImage::geometry() ignoring $square → the square case is RED (1200×630).
 */

use App\Models\Product;
use App\Services\ProductTrustShare;
use App\Services\SettingsService;
use App\Support\ShareImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

function scSet(array $values): void
{
    app(ProductTrustShare::class)->save($values);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

function scProduct(array $over = []): Product
{
    return Product::create($over + [
        'slug' => 'sc-'.Str::lower(Str::random(8)), 'name' => 'Shiseido Fino Premium Touch Hair Mask 230g',
        'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'price' => 4500,
        'stock_status' => 'instock', 'short_description' => '<p>Salon-smooth hair in five minutes.</p>',
    ]);
}

function scHtml(Product $p, string $ua = 'Mozilla/5.0'): string
{
    return (string) test()->withHeaders(['User-Agent' => $ua])->get('/product/'.$p->slug.'/')->assertOk()->getContent();
}

function scMeta(string $html, string $attr, string $name): ?string
{
    return preg_match('#<meta '.$attr.'="'.preg_quote($name, '#').'" content="([^"]*)">#', $html, $m)
        ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
}

function scWhatsapp(string $html): string
{
    preg_match('#href="(https://wa\.me/\?text=[^"]+)"#', $html, $m);
    parse_str((string) parse_url(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5), PHP_URL_QUERY), $q);

    return (string) ($q['text'] ?? '');
}

const SC_C = '🚚 Express delivery all over UAE & Gulf · ✅ 100% original products from the brand · 💳 Accepts Tabby & Tamara';

it('ships template C: the name, the three icon points, and the message above the link', function () {
    $html = scHtml(scProduct());

    expect(scMeta($html, 'property', 'og:title'))->toBe('Shiseido Fino Premium Touch Hair Mask 230g')
        ->and(scMeta($html, 'property', 'og:description'))->toBe(SC_C)
        ->and(scMeta($html, 'name', 'twitter:title'))->toBe('Shiseido Fino Premium Touch Hair Mask 230g')
        ->and(scMeta($html, 'name', 'twitter:description'))->toBe(SC_C);

    // Google's own description and the <title> are not the card.
    expect(scMeta($html, 'name', 'description'))->toBe('Salon-smooth hair in five minutes.')
        ->and($html)->toContain('<title>Shiseido Fino Premium Touch Hair Mask 230g');

    $wa = scWhatsapp($html);
    expect($wa)->toStartWith("See what I’ve found on K-Beauty Bliss 💖\nhttp")
        ->and(substr_count($wa, 'http'))->toBe(1)
        ->and($wa)->toContain('utm_source=whatsapp');
});

it('lets him change every part of the card', function () {
    scSet([
        'card_title' => 'name_price', 'card_icons' => 'ticks', 'card_sep' => 'bullet',
        'card_p2_on' => false, 'card_domain' => true, 'card_p3_text' => 'Pay in 4 with Tabby',
        'card_msg' => 'Look at this!',
    ]);
    config(['app.url' => 'https://www.extrabeauty.ae']);
    app(SettingsService::class)->set('site_url', 'https://www.extrabeauty.ae');
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    $html = scHtml(scProduct());

    expect(scMeta($html, 'property', 'og:title'))->toBe('Shiseido Fino Premium Touch Hair Mask 230g – AED 45')
        ->and(scMeta($html, 'property', 'og:description'))->toBe('✓ Express delivery all over UAE & Gulf • ✓ Pay in 4 with Tabby • extrabeauty.ae')
        ->and(scWhatsapp($html))->toStartWith("Look at this!\nhttps://");

    scSet(['card_title' => 'name_shop', 'card_icons' => 'none', 'card_sep' => 'bar', 'card_domain' => false]);
    $html = scHtml(scProduct());
    expect(scMeta($html, 'property', 'og:title'))->toBe('Shiseido Fino Premium Touch Hair Mask 230g | K-Beauty Bliss')
        ->and(scMeta($html, 'property', 'og:description'))->toBe('Express delivery all over UAE & Gulf | Pay in 4 with Tabby');
});

it('sends the message only from the tiles he picks, and the old message otherwise', function () {
    scSet(['card_msg_use' => 'whatsapp']);
    $html = scHtml(scProduct());
    preg_match('#href="(https://t\.me/share/url\?[^"]+)"#', $html, $tg);
    expect(scWhatsapp($html))->toStartWith('See what I’ve found')
        ->and(urldecode(html_entity_decode($tg[1])))->toContain('Shiseido Fino Premium Touch Hair Mask 230g – AED 45');

    scSet(['card_msg_use' => 'off']);
    expect(scWhatsapp(scHtml(scProduct())))->toStartWith('Shiseido Fino Premium Touch Hair Mask 230g – AED 45');
});

it('puts the page title and short description back when the card is off', function () {
    scSet(['card_on' => false]);
    $html = scHtml(scProduct());

    expect(scMeta($html, 'property', 'og:description'))->toBe('Salon-smooth hair in five minutes.')
        ->and(scMeta($html, 'property', 'og:title'))->toBe(scMeta($html, 'name', 'twitter:title'))
        ->and(scWhatsapp($html))->toStartWith('Shiseido Fino Premium Touch Hair Mask 230g – AED 45');
});

it('stores only its own options and prints his words as text', function () {
    scSet(['card_title' => 'evil', 'card_sep' => '<script>', 'card_p1_text' => '<b>Fast</b> "delivery" & more']);
    $ts = app(ProductTrustShare::class);
    expect($ts->choice('card_title'))->toBe('name')->and($ts->choice('card_sep'))->toBe('dot');

    $html = scHtml(scProduct());
    expect(scMeta($html, 'property', 'og:description'))->toStartWith('🚚 Fast "delivery" & more · ')
        ->and($html)->not->toContain('<b>Fast</b>');
});

it('makes a square picture (up to 1200×1200) when he picks Square, in its own folder', function () {
    $rel = 'media/products/sc-'.Str::lower(Str::random(6)).'.webp';
    @mkdir(\dirname(public_path($rel)), 0755, true);
    $im = imagecreatetruecolor(900, 900);
    imagewebp($im, public_path($rel), 90);
    imagedestroy($im);
    Cache::flush();
    scSet(['card_picture' => 'square']);

    try {
        $html = scHtml(scProduct(['image' => '/'.$rel]), 'WhatsApp/2.24.20.80 A');
        expect(scMeta($html, 'property', 'og:image'))->toEndWith('/'.ShareImage::DIR_SQUARE.'/'.$rel.'.jpg')
            // A 900px source is never enlarged: a 900×900 square (above the 600 floor).
            ->and(scMeta($html, 'property', 'og:image:width'))->toBe('900')
            ->and(scMeta($html, 'property', 'og:image:height'))->toBe('900');
        expect(ShareImage::geometry(900, 900, true))->toBe([900, 900, 0, 0, 900, 900])
            ->and(ShareImage::geometry(1600, 1600, true))->toBe([1200, 1200, 0, 0, 1200, 1200])
            ->and(ShareImage::geometry(1600, 1600))->toBe([1200, 630, 285, 0, 630, 630]);
    } finally {
        ShareImage::forget('/'.$rel);
        @unlink(public_path($rel));
    }
});

it('draws the card tab on Appearance → Product page', function () {
    expect(ProductTrustShare::TABS['ts_card'][0])->toBe('Share · Link preview card');
    $screen = (string) file_get_contents(resource_path('views/admin/partials/product-trust-share-screen.blade.php'));
    expect(substr_count($screen, "'ts_card'"))->toBeGreaterThanOrEqual(2);
});
