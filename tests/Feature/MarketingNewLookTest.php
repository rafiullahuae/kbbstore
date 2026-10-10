<?php

declare(strict_types=1);

use App\Mail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Refund;
use App\Services\Marketing\Blocks;
use App\Services\Marketing\CampaignRenderer;
use App\Services\Marketing\EmailTheme;
use App\Services\Marketing\TemplateLibrary;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use Illuminate\Support\Facades\App as AppFacade;
use Illuminate\Support\Facades\DB;
use Tests\Support\MarketingEmailsRoutes;
use Tests\Support\MarketingFixtures as F;

/**
 * Design C, "Playful K-beauty" — the "New look, less prices" campaign the
 * owner picked on 10 October (Lane EC), and "remove the returns words
 * completely, we don't offer returns."
 *
 * What each case stops, as it would look in an inbox:
 *   · the ready template drawing nothing (or the standard look) because a fill
 *     or the look was lost between the template and the campaign;
 *   · hand-picked products arriving in the database's order, not the owner's;
 *   · a "Returns" link or word in any email the shop sends — the footer row
 *     "Shipping & delivery · Returns · Privacy policy · Contact us" of the
 *     preview, or anything like it in an order email;
 *   · a grid that overflows a 320px phone, an image an image-blocking client
 *     collapses because it has no size, a layout that only exists in <style>;
 *   · an HTML-only message (spam filters and text-only readers);
 *   · an Arabic campaign printed left to right, or one that leaves the next
 *     English email in Arabic.
 */

/** Six sale products, and their ids in the order given. */
function ecCatalogue(): array
{
    $ids = [];

    foreach ([
        ['Medicube', 'Medicube PDRN Collagen Firm & Glow Set', 358, 290],
        ['Anua', 'Anua PDRN Hyaluronic Acid Capsule Mist Mini', 120, 85],
        ['Medicube', 'Medicube PDRN Pink Collagen Jelly Eye Mask', 80, 59],
        ['Dr.Althea', 'Dr.Althea 345 Relief Cream', 155, 99],
        ['Arencia', 'Arencia Vitamin C Booster Shot', 105, 76],
        ['numbuzin', 'numbuzin No.9 NAD+ Collagen Under Eye Patches', 120, 70],
    ] as $i => [$brand, $name, $was, $now]) {
        $ids[] = F::product($name, $was, [
            'brand_id' => F::brand($brand)->id, 'sale_price' => $now * 100, 'total_sales' => 900000 - $i,
            'image' => 'https://cdn.example.com/ec-' . $i . '.webp',
        ])->id;
    }

    return $ids;
}

/** The ready template's blocks, the grid switched to Manual with $ids when given. */
function ecBlocks(string $key = 'new-look', ?array $ids = null): array
{
    $blocks = TemplateLibrary::templates()[$key]['blocks'];

    foreach ($blocks as &$b) {
        if ($b['type'] === 'product_grid' && $ids !== null) {
            $b['props']['fill'] = 'hand_picked';
            $b['props']['product_ids'] = $ids;
        }
    }

    return $blocks;
}

/** @return array{html:string, text:string, bytes:int} */
function ecRender(array $blocks, string $locale = 'en', string $theme = 'playful'): array
{
    $r = app(CampaignRenderer::class);

    return $r->render($r->materialize(Blocks::clean($blocks)), [
        'audience' => 'customers', 'first_name' => 'Aisha', 'subject' => 'New look', 'preheader' => 'Six fresh picks',
        'unsubscribe' => 'https://extrabeauty.ae/email/u/1-' . str_repeat('a', 32), 'theme' => $theme, 'locale' => $locale,
    ]);
}

/** Visible words of an email: tags, comments and the <head> gone, entities decoded. */
function ecWords(string $html): string
{
    $html = (string) preg_replace('#<head>.*?</head>|<!--.*?-->#s', ' ', $html);

    return str_replace('Return to my basket', '', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

it('ships "New look, less prices" as a ready template, in design C, that Use turns into a playful campaign', function () {
    /*
     * The defect: the look lives on the template row, and store() only copied
     * blocks and subject — the owner's campaign would come out in the standard
     * look. MUTATION: drop `...CampaignRenderer::look($t)` from
     * MktCampaignsController::store() and the campaign reads standard.
     */
    MarketingEmailsRoutes::wire(app());
    $this->actingAs(F::admin('manager'), 'admin');

    $t = DB::table('mkt_templates')->where('key', 'new-look')->first();
    expect($t)->not->toBeNull()->and($t->theme)->toBe('playful')->and($t->locale)->toBe('en')->and((bool) $t->preset)->toBeTrue();
    expect(DB::table('mkt_templates')->where('key', 'new-look-ar')->value('locale'))->toBe('ar');

    $c = $this->postJson('/admin-api/email-marketing/campaigns', ['template_id' => $t->id])->assertOk()->json('campaign');

    expect($c['theme'])->toBe('playful')->and($c['locale'])->toBe('en')
        ->and($c['subject'])->toBe('We got a glow-up, and so did the prices 🌸')
        ->and($c['subject_ideas'])->toHaveCount(3)
        ->and(array_column($c['blocks'], 'type'))->toBe(['mini_header', 'heading', 'hero_image', 'button', 'heading', 'product_grid', 'badges', 'button', 'footer']);

    // A look or a language that is not one of the select's own is refused.
    $this->putJson('/admin-api/email-marketing/campaigns/' . $c['id'], ['theme' => '"><script>'])->assertStatus(422);
    $this->putJson('/admin-api/email-marketing/campaigns/' . $c['id'], ['locale' => 'fr'])->assertStatus(422);
    $this->putJson('/admin-api/email-marketing/campaigns/' . $c['id'], ['theme' => 'standard', 'locale' => 'ar'])->assertOk()
        ->assertJsonPath('campaign.theme', 'standard')->assertJsonPath('campaign.locale', 'ar');

    // The builder's preview draws the look it is asked for.
    $html = $this->postJson('/admin-api/email-marketing/preview', ['blocks' => ecBlocks(), 'theme' => 'playful', 'locale' => 'en'])->assertOk()->json('html');
    expect($html)->toContain('less prices</span></h1>')->toContain('data-mkb="5"');
});

it('fills the grid Automatically (rule + count) and Manually (picked, in the owner\'s order)', function () {
    /*
     * Automatic: the template's rule, Super Sale biggest saving first, six.
     * Manual: exactly the picked products, in the order picked — the email
     * prints product_ids as stored, never the catalogue's order.
     *
     * MUTATION: in ProductFill::ids() hand_picked, return $live (the query's
     * order) instead of filtering $wanted, and the manual order case is red.
     */
    $ids = ecCatalogue();

    $auto = ecRender(ecBlocks())['html'];
    expect(substr_count($auto, 'class="hy"'))->toBe(6)
        // Biggest saving first: numbuzin 42% off, then Dr.Althea 36%.
        ->and(strpos($auto, 'No.9 NAD+ Collagen Under Eye Patches'))->toBeLessThan(strpos($auto, '345 Relief Cream'));

    $order = [$ids[4], $ids[0], $ids[2]];
    $manual = ecRender(ecBlocks('new-look', $order))['html'];
    preg_match_all('#<div class="ink2"[^>]*>([^<]+)</div></td></tr>#', $manual, $m);

    expect($m[1])->toBe(['Vitamin C Booster Shot', 'PDRN Collagen Firm &amp; Glow Set', 'PDRN Pink Collagen Jelly Eye Mask'])
        // The stickers read the product: a set, a booster, eye care.
        ->and($manual)->toContain('>Booster</span>')->toContain('>Set</span>')->toContain('>Eye care</span>')
        // The sale price, and the old one struck through as a bare number.
        ->and($manual)->toContain('AED&nbsp;76</b>&nbsp; <s dir="ltr" style="font-size:11.5px;color:#857A82;white-space:nowrap;">105</s>');

    // Reordered by the owner: the email follows.
    $again = ecRender(ecBlocks('new-look', array_reverse($order)))['html'];
    expect(strpos($again, 'PDRN Pink Collagen Jelly Eye Mask'))->toBeLessThan(strpos($again, 'Vitamin C Booster Shot'));

    // "Show up to" cuts the picked list from the top, keeping its order.
    $blocks = ecBlocks('new-look', [$ids[5], $ids[1], $ids[3]]);
    $blocks[5]['props']['count'] = 2;
    $two = ecRender($blocks)['html'];
    expect(substr_count($two, 'class="hy"'))->toBe(2)->and($two)->toContain('Capsule Mist Mini')->not->toContain('345 Relief Cream');
});

it('sends no "Returns" in any email: every marketing template, in both looks, and every order and account email', function () {
    /*
     * The owner, 10 October: "remove the returns words completely, we don't
     * offer returns." The approved preview's footer read "Shipping & delivery
     * · Returns · Privacy policy · Contact us"; a returns link or word in an
     * email promises something the shop does not do.
     *
     * ONE EXCEPTION, decided rather than missed: the basket reminder's button
     * "Return to my basket" (go back to the basket — not a returns offer, and
     * in the owner-approved golden file tests/Fixtures/mail-kit-golden/13). It
     * is taken out by its exact words, so any other "return" still fails. No
     * order email says "return" (a refund is a refund — "refunded" does not
     * match). The pattern is /\breturns?\b/ on the words a reader sees, plus
     * the old returns pages' addresses anywhere in the markup.
     *
     * MUTATION: put a 'returns' => '/refund_returns/' row back in
     * EmailTheme::FOOTER_LINKS (or MailKit::FOOTER_LINKS) and this is red.
     */
    ecCatalogue();
    $bad = '/\breturns?\b|إرجاع|الإرجاع|استرجاع/iu';
    $badHref = '#refund_returns|returns?-(information|policy)#i';

    foreach (TemplateLibrary::templates() as $key => $t) {
        foreach (['standard', 'playful'] as $theme) {
            $out = ecRender($t['blocks'], $t['locale'] ?? 'en', $theme);
            expect(ecWords($out['html']))->not->toMatch($bad, "{$key}/{$theme} html")
                ->and($out['html'])->not->toMatch($badHref, "{$key}/{$theme} href")
                ->and($out['text'])->not->toMatch($bad, "{$key}/{$theme} text");
        }
    }

    // The order and account emails, HTML and text part.
    $order = Order::create([
        'order_number' => 'KBB-EC1', 'email' => 'ec@example.com', 'status' => 'processing', 'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'city' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Aisha', 'city' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 8900, 'total' => 8900, 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery', 'paid_at' => now(),
    ]);
    $order->items()->create(['name' => 'Toner', 'brand' => 'Anua', 'quantity' => 1, 'unit_price' => 8900, 'subtotal' => 8900, 'total' => 8900]);
    $order = $order->fresh('items');
    $unpaid = (clone $order)->forceFill(['paid_at' => null]);
    $url = fn (string $p) => \App\Support\Url::external($p);
    $mails = [
        new Mail\OrderConfirmation($order),
        new Mail\OrderPaymentReminder($unpaid, 1),
        new Mail\OrderPaymentReminder($unpaid, 2),
        new Mail\OrderStatusChanged($order, 'onhold', 'Your building name'),
        new Mail\OrderStatusChanged($order, 'shipped'),
        new Mail\OrderStatusChanged($order, 'completed'),
        new Mail\OrderStatusChanged($order, 'cancelled'),
        new Mail\OrderStatusChanged($unpaid, 'failed'),
        new Mail\OrderRefunded($order, new Refund(['amount' => 8900, 'status' => 'succeeded', 'provider_ref' => 'r1'])),
        new Mail\NewOrderAlert($order),
        new Mail\OrderInvoice($order),
        new Mail\OrderFeedbackRequest($order),
        new Mail\BackInStockAlert('Back in stock', 'It is back.', 'Toner', $url('/product/x/'), $url('/mail-preferences/?t=x')),
        new Mail\CartRecoveryReminder('Basket', 'Saved.', [['name' => 'Toner', 'slug' => 'x', 'quantity' => 1, 'unit_price' => 8900]], $url('/cart/'), $url('/mail-preferences/?t=x')),
        new Mail\CustomerAccountInvite('Ready', [['text' => 'We moved.'], ['link' => true]], 'text', $url('/set/'), 'K Beauty Bliss'),
        new Mail\NewsletterConfirmation($url('/newsletter/confirm/x/'), $url('/newsletter/unsubscribe/x/'), 14),
        new Mail\QuizPlanEmail('Aisha', 'Combination', ['Dullness'], [['name' => 'Morning', 'steps' => ['Cleanse']]], $url('/shop/'), null),
    ];

    foreach ($mails as $mail) {
        $name = class_basename($mail);
        $html = $mail->render();
        $text = $mail->textView ? view($mail->textView, $mail->buildViewData())->render() : '';

        expect(ecWords($html))->not->toMatch($bad, "{$name} html")
            ->and($html)->not->toMatch($badHref, "{$name} href")
            ->and(ecWords($text))->not->toMatch($bad, "{$name} text");
    }

    // The account emails go through notifications.
    $aisha = Customer::firstOrCreate(['email' => 'ec-aisha@example.com'], ['name' => 'Aisha Khan']);
    foreach ([new \App\Notifications\CustomerPasswordReset('tok', $aisha->id), new \App\Notifications\CustomerEmailVerification($aisha)] as $n) {
        $mail = $n->toMail($aisha);
        expect(ecWords((string) view($mail->view[0] ?? $mail->view, $mail->viewData)->render()))->not->toMatch($bad, class_basename($n));
    }

    // Every email string, English and the Arabic drafts: no returns word.
    $strings = array_filter(InterfaceStrings::flat(), fn ($k) => str_starts_with((string) $k, 'email.'), ARRAY_FILTER_USE_KEY);
    $arabic = array_filter(ArabicInterfaceDrafts::all(), fn ($k) => str_starts_with((string) $k, 'email.'), ARRAY_FILTER_USE_KEY);
    expect($strings)->not->toBe([])->and($arabic)->not->toBe([]);
    foreach ($strings + array_combine(array_map(fn ($k) => 'ar:' . $k, array_keys($arabic)), $arabic) as $k => $v) {
        expect(ecWords((string) $v))->not->toMatch($bad, $k);
    }
});

it('prints the footer row "Shipping & delivery · Privacy policy · Contact us", and no returns link', function () {
    /*
     * The preview's row had a Returns link to /returns-information/; the
     * owner asked for it gone. MUTATION: add a returns entry to
     * EmailTheme::FOOTER_LINKS and the exact row below is red.
     */
    $html = ecRender(ecBlocks())['html'];
    preg_match_all('#<a href="[^"]*(/delivery/|/privacy-policy/|/contact-us/)" class="muted"[^>]*>([^<]+)</a>#', $html, $m);

    expect(array_map(fn ($l) => html_entity_decode($l), $m[2]))->toBe(['Shipping & delivery', 'Privacy policy', 'Contact us'])
        ->and($html)->toMatch('#Contact us</a>\s*<br><a href="https://extrabeauty.ae/email/u/1-#')
        ->and(EmailTheme::FOOTER_LINKS)->toBe(['delivery' => '/delivery/', 'privacy' => '/privacy-policy/', 'contact' => '/contact-us/']);

    $ar = ecRender(ecBlocks('new-look-ar'), 'ar')['html'];
    expect($ar)->toContain('>الشحن والتوصيل</a>')->toContain('>سياسة الخصوصية</a>')->toContain('>تواصلي معنا</a>');
});

it('holds a phone: nothing wider than 600, every picture sized, the grid laid out without <style>, Outlook given its own button', function () {
    /*
     * The defects: a fixed width over 600 that a phone scrolls sideways; an
     * <img> without width/height that an image-blocking client collapses; a
     * product grid that only exists in a media query, so Gmail without
     * <style> support shows nothing sensible; a CSS-only pill button that
     * Outlook's Word engine draws as a bare link.
     *
     * Measured in Chromium on the real render (docs/lane-ec-shots/): 320 /
     * 375 / 414 / 640 → scrollWidth 320 / 375 / 414 / 640, cards 2×3 / 2×3 /
     * 2×3 / 3×2, card 130 / 158 / 177 / 178 px, nothing past the viewport.
     *
     * MUTATION: set the .hy cell's inline max-width to 600 or drop its
     * width:100% fallback, or drop the <!--[if mso]> roundrect, and this is red.
     */
    $html = ecRender(ecBlocks('new-look', ecCatalogue()))['html'];

    preg_match_all('/\bwidth="(\d+)"/', $html, $w);
    preg_match_all('/max-width:(\d+)px/', $html, $mw);
    expect(max(array_map('intval', $w[1])))->toBeLessThanOrEqual(600)
        ->and(max(array_map('intval', $mw[1])))->toBeLessThanOrEqual(600);

    preg_match_all('/<img\b[^>]*>/', $html, $imgs);
    expect($imgs[0])->toHaveCount(7);
    foreach ($imgs[0] as $img) {
        expect($img)->toMatch('/\swidth="\d+"/')->toMatch('/\sheight="\d+"/')->toContain('height:auto')->toContain('max-width:');
    }

    // Each product picture is the img-cache 400 copy the card asks for.
    foreach (ProductFillCardsProbe::srcs() as $src) {
        expect($html)->toContain('src="' . e($src) . '"');
    }

    // The grid without <style>: inline-block cells, each max 178px wide and
    // width:100%, so 3 fit at 600 and a narrow screen takes one per row; the
    // query only folds them to 2 on a phone; Outlook gets a 534px ghost table.
    expect(substr_count($html, '<div class="hy" style="display:inline-block;vertical-align:top;width:100%;max-width:178px;">'))->toBe(6)
        ->and($html)->toContain('.hy{max-width:50%!important}')
        ->and($html)->toContain('<!--[if mso]><table role="presentation" width="534"')
        ->and(substr_count($html, '<v:roundrect'))->toBe(2)
        ->and($html)->toContain('<meta name="color-scheme" content="light dark">')
        ->and($html)->toContain('.t5{background:#232F3D!important}');

    // Two columns, fixed: a plain two-cell table at every width.
    $blocks = ecBlocks('new-look', ProductFillCardsProbe::ids());
    $blocks[5]['props']['columns'] = '2';
    $two = ecRender($blocks)['html'];
    expect($two)->not->toContain('class="hy"')->and(substr_count($two, '<td width="50%" valign="top" style="width:50%;font-size:14px;">'))->toBe(6);
});

it('sends a plain-text part with the words, the products, the chips and the unsubscribe link', function () {
    /*
     * The defect: an HTML-only campaign — spam filters score it, and a
     * text-only reader gets nothing. MUTATION: drop the badges case's
     * $text[] line in CampaignRenderer and the chips are missing below.
     */
    $ids = ecCatalogue();
    $text = ecRender(ecBlocks('new-look', [$ids[0]]))['text'];

    expect($text)->toStartWith("New look,\nless prices")
        ->toContain('Start shopping →: ')
        ->toContain('- Medicube PDRN Collagen Firm & Glow Set — AED 290')
        ->toContain("* Free delivery over AED 199\n* 1–3 days across the UAE\n* Tabby, Tamara, card or cash\n* 100% authentic, from Korea")
        ->toContain("To stop these emails, open:\nhttps://extrabeauty.ae/email/u/1-")
        ->not->toMatch('/<[a-z][^>]*>/i');
});

it('renders the Arabic campaign right to left, in Arabic, and leaves the next email in English', function () {
    /*
     * The defects: an Arabic campaign printed left to right (the chips and the
     * sticker hug the wrong edge), and render() leaving the app in Arabic so
     * the next English email in the same sending step comes out half Arabic.
     * MUTATION: drop the `finally` that restores the locale in
     * CampaignRenderer::render() and the last expectation is red.
     */
    ecCatalogue();
    expect(AppFacade::getLocale())->toBe('en');

    $out = ecRender(ecBlocks('new-look-ar'), 'ar');

    expect($out['html'])->toContain('<html lang="ar" dir="rtl"')
        ->toContain('dir="rtl">')
        ->toContain('وأسعار أقل</span></h1>')
        ->toContain('مختارات جديدة بأسعار أقل')
        ->toContain('<b>توصيل مجاني</b>')
        ->toContain('text-align:right;"><span')
        ->and($out['text'])->toContain('لإيقاف هذه الرسائل، افتحي:')
        ->and(AppFacade::getLocale())->toBe('en');

    // The English one straight after is English and left to right.
    expect(ecRender(ecBlocks())['html'])->toContain('<html lang="en" dir="ltr"')->toContain('Fresh picks for less');
});

it('keeps the new blocks and props to their own options', function () {
    $b = Blocks::clean([
        Blocks::make('badges', ['items' => [['icon' => '"><script>', 'bold' => '<b>x</b>', 'text' => str_repeat('y', 80)], 'nope']]),
        Blocks::make('button', ['style' => 'dark']),
        Blocks::make('product_grid', ['layout' => 'evil', 'columns' => '9']),
        Blocks::make('footer'),
    ], $errors);

    expect($b[0]['props']['items'][0]['icon'])->toBe('sparkles')
        ->and($b[0]['props']['items'][1])->toBe(['icon' => 'sparkles', 'bold' => '', 'text' => ''])
        ->and(mb_strlen($b[0]['props']['items'][0]['text']))->toBe(60)
        ->and($errors)->not->toBe([])
        ->and($b[1]['props']['style'])->toBe('dark')
        ->and($b[2]['props']['layout'])->toBe('standard')
        ->and($b[2]['props']['columns'])->toBe('2');

    // A tag typed into a chip prints as text.
    expect(ecRender([Blocks::make('badges', ['items' => [['icon' => 'truck', 'bold' => '<b>x</b>', 'text' => 'ok']]]), Blocks::make('footer')])['html'])
        ->toContain('&lt;b&gt;x&lt;/b&gt;');
});

it('gives the builder Automatic / Manual, and ↑ ↓ and drag for the picked products, in the screen the owner opens', function () {
    /*
     * Growth & Marketing → Marketing Emails → (campaign) → select the product
     * grid → Automatic | Manual. MUTATION: drop data-mke-pup from pickedBox()
     * and this is red.
     */
    $screen = file_get_contents(resource_path('views/admin/partials/marketing-emails-screens.blade.php'));

    expect($screen)->toContain('data-mke-fillmode="auto"')->toContain('data-mke-fillmode="manual"')
        ->toContain('data-mke-pup="')->toContain('data-mke-pdown="')->toContain('data-mke-pgrip="')
        ->toContain("['badges', '✦', 'Benefit chips']")
        ->toContain('data-mke-e="theme"')->toContain('data-mke-e="locale"')
        // No layout measuring: the drop target is the row's own half.
        ->not->toContain('getBoundingClientRect')->not->toContain('offsetHeight');
});

/** The img-cache 400 address each card asks for, read the way ProductFill does. */
final class ProductFillCardsProbe
{
    public static function ids(): array
    {
        return \App\Models\Product::query()->where('image', 'like', 'https://cdn.example.com/ec-%')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public static function srcs(): array
    {
        return \App\Models\Product::query()->where('name', 'like', '%Medicube PDRN%')->get()
            ->map(fn ($p) => \App\Services\Mail\Kit\MailKit::image($p->image, 400))->filter()->values()->all();
    }
}
