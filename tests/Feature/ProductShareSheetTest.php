<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\ProductTrustShare;
use App\Services\SettingsService;
use App\Support\ProductShare;
use App\Support\ShareImage;
use App\Support\TrustShareIcons;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The product page's share icon, its sheet, and the picture a shared link
 * carries.                                                           (Lane QB)
 *
 * THE OWNER, 2 October, with Amazon's share sheet as his reference:
 *
 *   "ALSO the share icons don't bring the image along with the message. also
 *    i want to remove the share row completely, and make the share icon only,
 *    and bring that beside the right side of the product title, need same
 *    icon as attached the amazon screenshot. and also upon click it will open
 *    popup from bottom side same as attached fro mamazon with same product
 *    image carry, title row, and sharing platforms. plan it super well. ...
 *    FOR DEKSTOP ... the share icon will also desktop beside the title on
 *    right side."
 *
 * WHAT WAS WRONG WITH THE PICTURE, measured on the preview before this lane:
 * og:image named the ORIGINAL upload — for this catalogue mostly a full-size
 * WebP — and WhatsApp's preview fetcher drops WebP and heavy pictures, so the
 * bubble arrived with text and no image. A wa.me link cannot attach a file at
 * all; the picture IS the link preview. So og:image now names a 1200×630 JPEG
 * made for previews (App\Support\ShareImage), and "More" shares the file
 * itself where the phone allows.
 *
 * MUTATION NOTES are on each case and were run.
 */
function qbAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Share Owner',
        'email' => 'share-owner-'.Str::random(6).'@example.test',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);
}

function qbProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'qb-'.Str::lower(Str::random(8)),
        'name' => 'Heartleaf 77% Soothing Toner',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 6500,
        'stock_status' => 'instock',
        'short_description' => '<p>A gentle daily toner for skin that reacts to everything.</p>',
        'image' => '/media/products/qb-not-on-disk.jpg',
    ], $overrides));
}

function qbPage(Product $product): string
{
    return (string) test()->get('/product/'.$product->slug.'/')->assertOk()->getContent();
}

function qbSet(array $values): void
{
    app(ProductTrustShare::class)->save($values);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

/** The sheet's markup alone. */
function qbSheet(string $html): string
{
    return preg_match('#<div class="pdp-share" id="pdpShareSheet".*?<!--/pdp-share-->#s', $html, $m) ? $m[0] : '';
}

/** Every tile, network => decoded href (copy: the link it copies). */
function qbHrefs(string $html): array
{
    $sheet = qbSheet($html);
    preg_match_all('#<a class="pdp-share-tile" data-net="([a-z]+)" href="([^"]+)"#', $sheet, $m, PREG_SET_ORDER);
    $out = [];

    foreach ($m as $row) {
        $out[$row[1]] = html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    if (preg_match('#data-share-copy="([^"]+)"#', $sheet, $c)) {
        $out['copy'] = html_entity_decode($c[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return $out;
}

/** The tile order as drawn, including More. */
function qbOrder(string $html): array
{
    preg_match_all('#class="pdp-share-tile" data-net="([a-z]+)"#', qbSheet($html), $m);

    return $m[1];
}

function qbParam(string $url, string $key): ?string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    return isset($q[$key]) ? (string) $q[$key] : null;
}

function qbMeta(string $html, string $attr, string $name): ?string
{
    return preg_match('#<meta '.$attr.'="'.preg_quote($name, '#').'" content="([^"]*)">#', $html, $m)
        ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
        : null;
}

/**
 * A real, NOISY photograph on disk as WebP — the worst case for a JPEG
 * budget: flat colour encodes to almost nothing and would prove nothing.
 */
function qbWebp(string $relative, int $w = 1600, int $h = 1600, bool $heavy = false): string
{
    $path = public_path(ltrim($relative, '/'));
    @mkdir(\dirname($path), 0755, true);
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, (int) imagecolorallocate($im, 255, 255, 255));

    if ($heavy) {
        // Per-pixel sensor noise over the middle 80%: measured, this encodes
        // to ~530 KB as a 1200x630 JPEG at q95 and ~150 KB at q84, so it is the
        // picture that proves the quality steps are doing the work.
        mt_srand(3);
        for ($y = 0; $y < $h; $y++) {
            for ($x = (int) ($w * 0.1); $x < $w * 0.9; $x++) {
                imagesetpixel($im, $x, $y, (mt_rand(0, 255) << 16) | (mt_rand(0, 255) << 8) | mt_rand(0, 255));
            }
        }
        imagewebp($im, $path, 92);
        imagedestroy($im);

        return '/'.ltrim($relative, '/');
    }

    for ($y = 0; $y < $h; $y += 2) {
        for ($x = (int) ($w * 0.25); $x < $w * 0.75; $x += 2) {
            imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, (int) imagecolorallocate(
                $im, ($x * 7 + $y) % 255, ($y * 13) % 255, ($x * 3 + $y * 5) % 255
            ));
        }
    }

    imagewebp($im, $path, 92);
    imagedestroy($im);

    return '/'.ltrim($relative, '/');
}

function qbCleanup(string $image): void
{
    @unlink(public_path(ltrim($image, '/')));
    @unlink(public_path(ShareImage::DIR.'/'.ltrim($image, '/').'.jpg'));
}

/* ═══════════════════════════════════════════════════════ the row is gone ═══ */

it('removes the share row from the product page completely', function () {
    /*
     * "i want to remove the share row completely". The colourful bar under
     * Add to cart (Lane PW's .pts-share) must not be drawn anywhere — not on a
     * phone, not on a laptop, not hidden by CSS: not in the markup.
     *
     * MUTATION NOTE — RUN. Put `@include('partials.product.share-bar')` back
     * into trust-share-stack (with the partial restored) and the first
     * expectation is red.
     */
    $html = qbPage(qbProduct());

    expect($html)->not->toContain('class="pts-share')
        ->and($html)->not->toContain('pts-share-list')
        ->and($html)->not->toContain('data-pts-native')
        ->and(is_file(resource_path('views/partials/product/share-bar.blade.php')))->toBeFalse();

    $stack = (string) file_get_contents(resource_path('views/partials/product/trust-share-stack.blade.php'));
    expect($stack)->not->toContain("partials.product.share-bar")
        ->and(substr_count($stack, "@include('partials.product.share-sheet')"))->toBe(1);

    // The authenticity line it shared a wrapper with is still there.
    expect($html)->toContain('<div class="pts-stack">')->and($html)->toContain('class="pts-auth"');

    // And the bar's retired settings are gone from the screen's schema.
    foreach (['share_label', 'share_style', 'share_shape', 'share_size', 'share_above_m', 'share_below_d'] as $retired) {
        expect(ProductTrustShare::fields())->not->toHaveKey($retired);
    }
});

/* ════════════════════════════════════════════════════════════ the sheet ═══ */

it('draws one closed share sheet, a labelled modal dialog, just before </body>', function () {
    /*
     * MUTATION NOTES — RUN.
     *  · Drop `hidden` from the sheet's root and the closed-by-default
     *    expectation is red: the sheet would sit open over every product page.
     *  · Remove @once from share-sheet.blade.php and include it a second time
     *    (as Lane QA will) — the count is 2, red.
     *  · Change aria-labelledby to "pdpShareHead" and the label expectation
     *    is red.
     */
    $product = qbProduct();
    $html = qbPage($product);

    expect(substr_count($html, 'id="pdpShareSheet"'))->toBe(1)
        ->and($html)->toContain('<div class="pdp-share" id="pdpShareSheet" role="dialog" aria-modal="true" aria-labelledby="pdpShareTitle" data-share-sheet hidden>')
        ->and($html)->toContain('<h2 class="pdp-share-h" id="pdpShareTitle">Share this product with friends</h2>')
        ->and(substr_count($html, 'id="pdpShareTitle"'))->toBe(1)
        ->and($html)->toContain('<button type="button" class="pdp-share-x" data-share-close aria-label="Close">');

    // At the end of <body>, outside the buy column's form and every
    // transformed ancestor: after </main>'s content, before </body>.
    $sheetAt = strpos($html, 'id="pdpShareSheet"');
    expect($sheetAt)->toBeGreaterThan(strrpos($html, '</form>'))
        ->and($sheetAt)->toBeLessThan(strrpos($html, '</body>'));

    // The product card: the picture with the name as its alt, the name on its
    // own strip.
    $sheet = qbSheet($html);
    expect($sheet)->toContain('alt="Heartleaf 77% Soothing Toner"')
        ->and($sheet)->toContain('<p class="pdp-share-name">Heartleaf 77% Soothing Toner</p>');

    // Included twice — the way Lane QA's @includeIf and this lane's include
    // will both run — it still draws ONE sheet.
    $twice = view('partials.product.share-sheet', ['product' => $product, 'seoCtx' => []])->render()
        .view('partials.product.share-sheet', ['product' => $product, 'seoCtx' => []])->render();
    expect($twice)->not->toContain('pdpShareSheet'); // pushed to the stack, not printed in place
});

it('draws no sheet when the share icon is switched off', function () {
    /*
     * MUTATION NOTE — RUN. Drop `$kbbPts->on('share_on') &&` from the sheet's
     * @if and this is red.
     */
    qbSet(['share_on' => false]);
    $html = qbPage(qbProduct());

    expect($html)->not->toContain('pdpShareSheet');
});

it('ships Amazon’s platform set in Amazon’s order, with Facebook, X and LinkedIn off', function () {
    /*
     * His reference sheet: WhatsApp, Messenger, Pinterest, Telegram, Snapchat /
     * Messages, Email, Copy, More. Facebook, X and LinkedIn are not on it, so
     * they ship off — one switch away on Appearance → Product page → Share.
     *
     * MUTATION NOTES — RUN. Default `share_facebook` to true and the order is
     * red (a tenth tile). Swap two keys in ORDER_DEFAULT and the order is red.
     */
    $html = qbPage(qbProduct());

    expect(qbOrder($html))->toBe(['whatsapp', 'messenger', 'pinterest', 'telegram', 'snapchat', 'sms', 'email', 'copy', 'native']);

    // More is drawn hidden: the script reveals it only where navigator.share exists.
    expect(qbSheet($html))->toMatch('#<li data-share-more hidden><button type="button" class="pdp-share-tile" data-net="native" data-share-native #');

    // The labels a shopper reads, in order.
    preg_match_all('#<span class="pdp-share-lbl">([^<]+)</span>#', qbSheet($html), $l);
    expect($l[1])->toBe(['WhatsApp', 'Messenger', 'Pinterest', 'Telegram', 'Snapchat', 'Messages', 'Email', 'Copy', 'More']);

    qbSet(['share_facebook' => true, 'share_x' => true, 'share_linkedin' => true, 'share_snapchat' => false]);
    expect(qbOrder(qbPage(qbProduct())))->toBe(['whatsapp', 'messenger', 'pinterest', 'telegram', 'sms', 'email', 'copy', 'native', 'facebook', 'x', 'linkedin']);
});

it('draws the tiles in the order he sets, and stores only a clean list of its own keys', function () {
    /*
     * Rule 5 for a list: a stored order is every known platform exactly once,
     * nothing else.
     *
     * MUTATION NOTE — RUN. Return $raw unchanged from cleanOrder() and the
     * stored value keeps `"><script>` — red.
     */
    qbSet(['share_order' => 'email, copy,"><script>,email,WHATSAPP,bogus']);

    $stored = app(ProductTrustShare::class)->all()['share_order'];
    expect($stored)->toBe('email,copy,whatsapp,messenger,pinterest,telegram,snapchat,sms,native,facebook,x,linkedin');

    expect(qbOrder(qbPage(qbProduct())))->toBe(['email', 'copy', 'whatsapp', 'messenger', 'pinterest', 'telegram', 'snapchat', 'sms', 'native']);

    expect(ProductTrustShare::cleanOrder(null))->toBe(ProductTrustShare::ORDER_DEFAULT)
        ->and(ProductTrustShare::cleanOrder(['x', 'x', 'nope']))->toStartWith('x,whatsapp,');
});

it('builds every platform link on the server, encoded once, with the product link as the only address', function () {
    /*
     * Quotes, an ampersand and Arabic in the name and the blurb, an entity in
     * the stored HTML, and a stray web address inside the blurb — which would
     * steal WhatsApp's preview, because WhatsApp previews the FIRST link.
     *
     * MUTATION NOTES — RUN.
     *  · Remove self::unlinked() from the blurb in ProductShare::facts() and
     *    the "only one address" expectation is red (two http in the text).
     *  · Use PHP_QUERY_RFC1738 in href() and the `%20` expectation is red.
     *  · Change `sms:?&` to `sms:?` and the SMS expectation is red.
     */
    $product = qbProduct([
        'name' => 'Rosé "Glow" Toner & Mist — تونر',
        'short_description' => '<p>Lift &amp; glow, "dewy" skin — ترطيب عميق. See https://other.example/x for more.</p>',
    ]);

    $html = qbPage($product);
    $h = qbHrefs($html);
    $path = '/product/'.$product->slug.'/';

    $wa = qbParam($h['whatsapp'], 'text');
    expect($h['whatsapp'])->toStartWith('https://wa.me/?text=')
        ->and($wa)->toStartWith('Rosé "Glow" Toner & Mist — تونر – AED 65')
        ->and($wa)->toContain("\nLift & glow, \"dewy\" skin — ترطيب عميق. See for more.\nhttp")
        ->and(substr_count($wa, 'http'))->toBe(1);

    // The link is on its own LAST line, so it is whole and nothing trails it.
    $lines = explode("\n", $wa);
    expect(end($lines))->toContain($path)->and(end($lines))->toStartWith('http');

    preg_match('#data-net="whatsapp" href="([^"]+)"#', $html, $raw);
    expect($raw[1])->toContain('%26')->and($raw[1])->toContain('%22')->and($raw[1])->toContain('%20')
        ->and($raw[1])->not->toContain('%2526')->and($raw[1])->not->toContain('&amp;amp;');

    // Messenger: the web form in href, the app form for phones beside it.
    expect($h['messenger'])->toStartWith('https://www.facebook.com/sharer/sharer.php?u=')
        ->and(qbParam($h['messenger'], 'u'))->toContain($path);
    preg_match('#data-net="messenger" href="[^"]+" data-app-href="([^"]+)"#', $html, $app);
    expect(html_entity_decode($app[1]))->toStartWith('fb-messenger://share/?link=')
        ->and(qbParam(html_entity_decode($app[1]), 'link'))->toContain($path);

    // Snapchat: Snapchat for Web on a laptop; the Creative Kit deep link on a phone.
    expect($h['snapchat'])->toStartWith('https://www.snapchat.com/share?link=')
        ->and(qbParam($h['snapchat'], 'link'))->toContain($path);
    preg_match('#data-net="snapchat" href="[^"]+" data-app-href="([^"]+)"#', $html, $snap);
    expect(html_entity_decode($snap[1]))->toStartWith('https://www.snapchat.com/scan?attachmentUrl=');

    // Messages: the form both iOS and Android read; name, price and link.
    expect($h['sms'])->toStartWith('sms:?&body=');
    parse_str(substr($h['sms'], strlen('sms:?&')), $sms);
    expect($sms['body'])->toStartWith('Rosé "Glow" Toner & Mist — تونر – AED 65'."\nhttp")->and($sms['body'])->toContain($path);

    expect(qbParam($h['telegram'], 'url'))->toContain($path)
        ->and(qbParam($h['telegram'], 'text'))->toContain('Lift & glow');
    expect(qbParam($h['email'], 'subject'))->toBe('Rosé "Glow" Toner & Mist — تونر')
        ->and(qbParam($h['email'], 'body'))->toContain("\n\nhttp");
    expect(qbParam($h['pinterest'], 'media'))->toStartWith('http')->and(qbParam($h['pinterest'], 'media'))->toEndWith('/media/products/qb-not-on-disk.jpg');
    expect($h['copy'])->toContain($path);

    // Off-site tiles open a new tab and hand nothing back; mailto:, sms: and
    // Copy open nothing.
    $sheet = qbSheet($html);
    foreach (['whatsapp', 'messenger', 'pinterest', 'telegram', 'snapchat'] as $net) {
        expect($sheet)->toMatch('#data-net="'.$net.'" href="https://[^"]+"(?: data-app-href="[^"]+")? target="_blank" rel="noopener noreferrer" #');
    }
    expect($sheet)->not->toMatch('#data-net="(sms|email)"[^>]*target=#');
});

it('prints his heading as text, never as markup, and the reviewed default otherwise', function () {
    /*
     * Two locks: `markup => strip` takes a whole tag off at save, and `{{ }}`
     * escapes what a sentence can still hold — ampersands, quotes, a bare >.
     *
     * MUTATION NOTE — RUN. Print the heading with {!! !!} and the second
     * expectation is red (a raw `"` and `&` in the markup).
     */
    qbSet(['share_heading' => '<script>alert(1)</script> Fish & "chips" > all']);
    $html = qbPage(qbProduct());

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and(qbSheet($html))->toContain('id="pdpShareTitle">alert(1) Fish &amp; &quot;chips&quot; &gt; all</h2>');
});

/* ═══════════════════════════════════════════════ the picture that travels ═══ */

it('turns a heavy WebP into a 1200×630 JPEG under the WhatsApp budget, letterboxed, never cropped', function () {
    /*
     * The defect, measured: og:image was the WebP original, which WhatsApp's
     * preview fetcher drops. This makes the replacement out of a REAL noisy
     * WebP and measures it.
     *
     * MUTATION NOTES — RUN.
     *  · Encode with imagewebp() instead of imagejpeg() in make() and the
     *    IMAGETYPE_JPEG expectation is red.
     *  · Drop the quality steps (QUALITIES = [95]) and the byte budget is red
     *    on this photograph (~530 KB at q95).
     *  · Cover instead of contain in geometry() (max() for min()) and the
     *    "whole photograph fits" expectation is red.
     */
    $image = qbWebp('media/products/qb-heavy-'.Str::random(6).'.webp', 1600, 1600, true);

    try {
        $sourceBytes = filesize(public_path(ltrim($image, '/')));
        $result = ShareImage::make($image);
        $file = public_path(ShareImage::DIR.'/'.ltrim($image, '/').'.jpg');
        $info = getimagesize($file);

        expect($result['made'])->toBeTrue()
            ->and($info[2])->toBe(IMAGETYPE_JPEG)
            ->and([$info[0], $info[1]])->toBe([1200, 630])
            ->and(filesize($file))->toBeLessThanOrEqual(ShareImage::BUDGET)
            ->and($result['bytes'])->toBe(filesize($file));

        // Recorded for the report: what the WebP was and what the JPEG is.
        fwrite(STDERR, sprintf("\n    [share image] WebP %d bytes -> JPEG %d bytes at q%d\n", $sourceBytes, $result['bytes'], $result['quality']));

        // Letterboxed: the corners are the white of the card, and the square
        // photograph is 630 tall, centred — the whole of it, nothing cropped.
        [$cw, $ch, $x, $y, $dw, $dh] = ShareImage::geometry(1600, 1600);
        expect([$cw, $ch, $x, $y, $dw, $dh])->toBe([1200, 630, 285, 0, 630, 630]);
        $im = imagecreatefromjpeg($file);
        $corner = imagecolorsforindex($im, imagecolorat($im, 5, 5));
        expect($corner['red'])->toBeGreaterThan(245)->and($corner['green'])->toBeGreaterThan(245)->and($corner['blue'])->toBeGreaterThan(245);
        imagedestroy($im);

        // A second call is a stat(), not an encode.
        expect(ShareImage::make($image)['reason'])->toBe('fresh');
    } finally {
        qbCleanup($image);
    }
});

it('fits every shape inside the card without enlarging a small picture past the large-card floor', function () {
    expect(ShareImage::geometry(2000, 500))->toBe([1200, 630, 0, 165, 1200, 300])
        ->and(ShareImage::geometry(600, 1200))->toBe([1200, 630, 442, 0, 315, 630])
        // 400×400 is not blown up to 630: the card shrinks to 400 tall.
        ->and(ShareImage::geometry(400, 400))->toBe([762, 400, 181, 0, 400, 400])
        // ...but never below Facebook's 600×315 large-card floor.
        ->and(ShareImage::geometry(200, 200)[0])->toBe(600)
        ->and(ShareImage::geometry(200, 200)[1])->toBe(315);
});

it('puts a cut-out picture on white, not on black', function () {
    /*
     * MUTATION NOTE — RUN. Remove the white imagefilledrectangle() in make()
     * and the corner reads black — red.
     */
    $rel = 'media/products/qb-cutout-'.Str::random(6).'.png';
    $path = public_path($rel);
    @mkdir(\dirname($path), 0755, true);
    $im = imagecreatetruecolor(800, 800);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefilledrectangle($im, 0, 0, 800, 800, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledellipse($im, 400, 400, 300, 500, (int) imagecolorallocatealpha($im, 200, 40, 90, 0));
    imagepng($im, $path);
    imagedestroy($im);

    try {
        ShareImage::make('/'.$rel);
        $out = imagecreatefromjpeg(public_path(ShareImage::DIR.'/'.$rel.'.jpg'));
        // Inside the photograph's own transparent corner, not the letterbox.
        $px = imagecolorsforindex($out, imagecolorat($out, 600, 20));
        expect($px['red'])->toBeGreaterThan(245)->and($px['blue'])->toBeGreaterThan(245);
        imagedestroy($out);
    } finally {
        qbCleanup('/'.$rel);
    }
});

it('publishes the JPEG as og:image with its type and size once it exists, and the original until then', function () {
    /*
     * The first view finds no copy: og:image is the original exactly as
     * before (no new tag at all), and the copy is made AFTER that response.
     * The next view publishes the JPEG. JSON-LD keeps the original either way.
     *
     * MUTATION NOTES — RUN.
     *  · Delete the `app()->terminating(...)` in ShareImage::makeAfterResponse()
     *    and the second view still names the WebP — red.
     *  · Point previewImage() at $image always and og:image:type is missing —
     *    red.
     *  (The JSON-LD Product reads the product's own pictures, not the value
     *  this lane changed, so it cannot be fed the card by this code path; the
     *  expectation below is a guard on that staying true, not a mutation
     *  target.)
     */
    $image = qbWebp('media/products/qb-og-'.Str::random(6).'.webp', 1000, 1000);
    Cache::flush();

    try {
        $product = qbProduct(['image' => $image]);

        $first = qbPage($product);
        expect(qbMeta($first, 'property', 'og:image'))->toEndWith($image)
            ->and(qbMeta($first, 'property', 'og:image:type'))->toBeNull()
            ->and(qbMeta($first, 'property', 'og:image:width'))->toBe('1000');

        // The test client runs the kernel's terminate() as the server does.
        expect(is_file(public_path(ShareImage::DIR.'/'.ltrim($image, '/').'.jpg')))->toBeTrue();

        $second = qbPage($product);
        $og = qbMeta($second, 'property', 'og:image');
        expect($og)->toStartWith('http')
            ->and($og)->toEndWith('/'.ShareImage::DIR.$image.'.jpg')
            ->and(qbMeta($second, 'property', 'og:image:type'))->toBe('image/jpeg')
            ->and(qbMeta($second, 'property', 'og:image:width'))->toBe('1200')
            ->and(qbMeta($second, 'property', 'og:image:height'))->toBe('630')
            ->and(qbMeta($second, 'property', 'og:image:alt'))->toBe('Heartleaf 77% Soothing Toner')
            ->and(qbMeta($second, 'name', 'twitter:image'))->toBe($og);

        // secure_url only for an https address — this test app is http.
        expect(qbMeta($second, 'property', 'og:image:secure_url'))->toBeNull();

        // Order: og:image, then its structured properties.
        expect(strpos($second, 'og:image:type'))->toBeGreaterThan(strpos($second, '"og:image"'));

        // The sheet's card shows the same picture a friend's preview will.
        expect(qbSheet($second))->toContain('src="'.$og.'"')
            ->and(qbSheet($second))->toContain('width="1200" height="630"')
            ->and(qbSheet($second))->toContain('data-file="/'.ShareImage::DIR.$image.'.jpg"');

        // The Product rich result keeps the full-resolution original.
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $second, $ld);
        $images = [];
        foreach ($ld[1] as $json) {
            $node = json_decode($json, true);
            if (($node['@type'] ?? null) === 'Product') {
                $images = (array) ($node['image'] ?? []);
            }
        }
        expect(implode(' ', $images))->toContain($image)->and(implode(' ', $images))->not->toContain('.webp.jpg');

        // The page's own <img> tags never name the share card.
        $outsideSheet = str_replace(qbSheet($second), '', $second);
        expect($outsideSheet)->not->toMatch('#<img[^>]+'.preg_quote(ShareImage::DIR, '#').'#');
    } finally {
        qbCleanup($image);
    }
});

it('falls back to the original when the copy is stale or the picture is not this site’s', function () {
    /*
     * MUTATION NOTE — RUN. Drop the mtime comparison in ShareImage::find()
     * and a replaced original keeps publishing its old card — red.
     */
    $image = qbWebp('media/products/qb-stale-'.Str::random(6).'.webp', 800, 800);

    try {
        ShareImage::make($image);
        $copy = public_path(ShareImage::DIR.'/'.ltrim($image, '/').'.jpg');
        expect(ShareImage::find($image))->not->toBeNull();

        // The original is replaced after the copy was made.
        touch($copy, time() - 600);
        touch(public_path(ltrim($image, '/')), time());
        clearstatcache();
        expect(ShareImage::find($image))->toBeNull();

        // Remote and missing pictures: nothing to make, the original stands.
        expect(ShareImage::forPage('https://kbeautybliss.com/wp-content/uploads/x.webp'))->toBeNull()
            ->and(ShareImage::forPage('/media/products/qb-not-on-disk.jpg'))->toBeNull()
            ->and(ShareImage::forPage(null))->toBeNull()
            ->and(ShareImage::make('/media/products/qb-not-on-disk.jpg')['reason'])->toBe('not a local image');

        // No traversal into, or out of, the cache.
        expect(ShareImage::make('/img-cache/share/x.jpg')['made'])->toBeFalse()
            ->and(ShareImage::make('/../.env')['made'])->toBeFalse();

        // forget() removes the card even after the original is gone.
        @unlink(public_path(ltrim($image, '/')));
        expect(ShareImage::forget($image))->toBeTrue()->and(is_file($copy))->toBeFalse();
    } finally {
        qbCleanup($image);
    }
});

/* ═══════════════════════════════════════════════════════════ the script ═══ */

it('opens from any [data-share-open], closes four ways, keeps focus, and shares the picture file', function () {
    /*
     * Read off the shipped script, comments stripped, because the behaviour
     * runs in a browser: docs/qb-shots/MEASUREMENTS.json holds the same paths
     * driven in Chromium (Escape, the backdrop, Back and × each leave the sheet
     * hidden with focus on the button; More received a 1200×630 image/jpeg
     * File).
     *
     * MUTATION NOTES — RUN.
     *  · Bind to '.pdp-share-btn' instead of '[data-share-open]' and the first
     *    expectation is red: Lane QA's button would open nothing.
     *  · Drop the canShare({ files }) check and the file-share expectation is red.
     *  · Remove `opener.focus(` and the focus-return expectation is red.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/pdp-trust.js'));
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $js);
    $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

    expect($code)->toContain("t.closest('[data-share-open]')")
        ->and($code)->toContain("e.key === 'Escape'")
        ->and($code)->toContain("t.closest('[data-share-close]')")
        ->and($code)->toContain("window.addEventListener('popstate'")
        ->and($code)->toContain('window.history.pushState(')
        ->and($code)->toContain('opener.focus(')
        ->and($code)->toContain("e.key !== 'Tab'")
        ->and($code)->toContain("classList.add('pdp-share-lock')")
        ->and($code)->toContain('navigator.canShare({ files: [file] })')
        ->and($code)->toContain('{ files: [shareFile], title, text:')
        ->and($code)->toContain("typeof navigator.share === 'function'")
        ->and($code)->toContain("matchMedia('(prefers-reduced-motion: reduce)')")
        ->and($code)->toContain("matchMedia('(pointer: coarse)')");

    // The data-app-href swap accepts only the two schemes the server builds.
    expect($code)->toContain('/^(https:|fb-messenger:)/');

    // Rule 4: no element-measuring API, and no forced reflow trick either.
    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'scrollHeight', 'getComputedStyle', 'innerHTML'] as $api) {
        expect($code)->not->toContain($api);
    }
});

it('slides with CSS, stands still for reduced motion, and is a centred card on a laptop', function () {
    /*
     * MUTATION NOTE — RUN. Remove the sheet from the reduced-motion block and
     * the last expectation is red.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-pdp-trust.css'));

    expect($css)->toContain('.pdp-share[hidden]{display:none}')
        ->and($css)->toContain('transform:translateY(100%);transition:transform .32s')
        ->and($css)->toContain('.pdp-share.is-open .pdp-share-panel{transform:none}')
        ->and($css)->toContain('-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px)')
        ->and($css)->toContain('html.pdp-share-lock,html.pdp-share-lock body{overflow:hidden}')
        ->and($css)->toContain('grid-template-columns:repeat(5,minmax(0,1fr))')
        ->and($css)->toContain('-webkit-line-clamp:2')
        ->and($css)->toMatch('#@media \(min-width:881px\)\{\s*\.pdp-share\{align-items:center\}\s*\.pdp-share-panel\{inline-size:480px#')
        ->and($css)->toMatch('#@media \(prefers-reduced-motion:reduce\)\{[^@]*\.pdp-share-scrim,\.pdp-share-panel,\.pdp-share-toast,\.pdp-share-ico\{transition:none\}#');

    // The row's rules went with the row.
    expect($css)->not->toContain('.pts-share-list')->and($css)->not->toContain('.pts-sb');
});

it('draws every tile from a constant, and offers Lane QA the Amazon share glyph', function () {
    foreach (array_keys(ProductTrustShare::NETWORKS) as $key) {
        expect(TrustShareIcons::tile($key))->toStartWith('<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false">');
    }

    expect(TrustShareIcons::tile('nope'))->toBe('')
        // Snapchat: yellow tile, white ghost outlined black.
        ->and(TrustShareIcons::TILE['snapchat'])->toContain('fill="#FFFC00"')->and(TrustShareIcons::TILE['snapchat'])->toContain('fill="#fff" stroke="#111"')
        // Three dots joined by two lines.
        ->and(substr_count(TrustShareIcons::SHARE, '<circle'))->toBe(3);
});

/* ═════════════════════════════════════════════════════════════ the screen ═══ */

it('serves the Share tab on Appearance → Product page with its own controls, and saves them', function () {
    /*
     * MUTATION NOTE — RUN. Leave `share_order` out of TABS['ts_share'] and
     * ModuleFrameworkGuardTest is red ("stores these with no control"), and
     * the key list here is red.
     */
    $this->actingAs(qbAdmin(), 'admin');

    $body = $this->getJson('/admin-api/product-page')->assertOk()->json();
    $share = collect($body['trust'])->firstWhere('key', 'ts_share');

    expect($share['label'])->toBe('Share')
        ->and(array_column($share['fields'], 'key'))->toBe([
            'share_on', 'share_heading', 'share_whatsapp', 'share_messenger', 'share_pinterest', 'share_telegram',
            'share_snapchat', 'share_sms', 'share_email', 'share_copy', 'share_native',
            'share_facebook', 'share_x', 'share_linkedin', 'share_order', 'share_utm',
        ])
        ->and($body['preview']['share_networks']['snapchat'])->toBe('Snapchat')
        ->and($body['preview']['trust_props'])->not->toHaveKey('share');

    $this->postJson('/admin-api/product-page', ['trust' => ['share_heading' => 'Send it to a friend', 'share_order' => 'copy,whatsapp']])
        ->assertOk()->assertJson(['ok' => true, 'saved' => 2]);

    $all = app(ProductTrustShare::class)->all();
    expect($all['share_heading'])->toBe('Send it to a friend')
        ->and($all['share_order'])->toStartWith('copy,whatsapp,messenger');

    // A retired key is refused rather than "saved" into nothing.
    $this->postJson('/admin-api/product-page', ['trust' => ['share_style' => 'brand']])->assertStatus(422);

    $screen = (string) file_get_contents(resource_path('views/admin/partials/product-trust-share-screen.blade.php'));
    expect($screen)->toContain("f.key === 'share_order'")->and($screen)->toContain('data-pts-move');
});

it('returns the three platforms Amazon does not show to off, and keeps a choice he made', function () {
    /*
     * MUTATION NOTE — RUN. Drop the `whereIn('value', …)` and a stored "0" is
     * deleted too — red on the LinkedIn row.
     */
    DB::table('settings')->insert([
        ['key' => 'pdpts_share_facebook', 'value' => '1', 'autoload' => true],
        ['key' => 'pdpts_share_x', 'value' => 'true', 'autoload' => true],
        ['key' => 'pdpts_share_linkedin', 'value' => '0', 'autoload' => true],
        ['key' => 'pdpts_share_whatsapp', 'value' => '1', 'autoload' => true],
    ]);

    $migration = require database_path('migrations/2027_07_15_000500_ship_share_sheet_platform_set.php');
    ob_start();
    $migration->up();
    ob_end_clean();

    $left = DB::table('settings')->where('key', 'like', 'pdpts_share_%')->pluck('value', 'key')->all();
    expect($left)->toBe(['pdpts_share_linkedin' => '0', 'pdpts_share_whatsapp' => '1']);

    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
    $ts = new ProductTrustShare(app(SettingsService::class));
    expect($ts->on('share_facebook'))->toBeFalse()->and($ts->on('share_x'))->toBeFalse();
});

it('makes every product’s card from the command line', function () {
    $image = qbWebp('media/products/qb-cli-'.Str::random(6).'.webp', 900, 900);

    try {
        qbProduct(['image' => ltrim($image, '/')]); // stored without the slash, as imports do
        qbProduct(['image' => 'https://kbeautybliss.com/wp-content/uploads/remote.webp']);

        $this->artisan('kbb:share-images')
            ->expectsOutputToContain('1 made')
            ->assertSuccessful();

        expect(is_file(public_path(ShareImage::DIR.$image.'.jpg')))->toBeTrue();

        $this->artisan('kbb:share-images')->expectsOutputToContain('1 already up to date')->assertSuccessful();
    } finally {
        qbCleanup($image);
    }
});

it('carries the sheet’s words in the interface strings, with Arabic drafts', function () {
    foreach (['pts_sheet_heading', 'pts_share_btn', 'pts_tile_messages', 'pts_tile_email', 'pts_tile_copy', 'pts_tile_more'] as $k) {
        expect(\App\Services\Translation\InterfaceStrings::english('store.product.'.$k))->not->toBeNull()
            ->and(\App\Services\Translation\ArabicInterfaceDrafts::all())->toHaveKey('store.product.'.$k);
    }

    expect(__('store.product.pts_sheet_heading'))->toBe('Share this product with friends');
});

it('builds the native share payload from the product, tagged for analytics', function () {
    $ts = app(ProductTrustShare::class);
    $facts = ['name' => 'N', 'headline' => 'N – AED 5', 'blurb' => 'B', 'url' => 'https://shop.test/product/n/', 'image' => null];

    expect(ProductShare::native($facts, $ts))->toBe([
        'title' => 'N',
        'text' => "N – AED 5\nB",
        'url' => 'https://shop.test/product/n/?utm_source=native&utm_medium=social&utm_campaign=product_share',
    ]);
});
