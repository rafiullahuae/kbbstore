<?php

declare(strict_types=1);

/**
 * The new site footer on every storefront page, and the switch back. (Lane HB)
 *
 * THE OWNER, approving docs/home-preview/footer-final.html (master plan row 55,
 * "Owner's picks, 3 Oct"): "use whatsapp green colors … the colored strip of
 * need help, will changes colors itself within same color range … replace chat
 * with us [with] 24/7 available … we don't offer returns so don't include any
 * return word … bottom we need our name super big center align with light
 * color". Controls: Appearance → Footer → the three "Site footer" tabs.
 *
 * The defects these pin, as each would look on the shop:
 *   · (Lane EC, 10 October — REVERSED AGAIN by the owner: "remove the returns
 *     words completely, we don't offer returns.") Case 1 now pins NO returns
 *     link in the Help column, shipped or from a footer menu the owner built:
 *     "Returns Information" under HELP promised returns the shop does not
 *     offer. The page itself still answers; nothing links to it.
 *   · (Lane TP, 6 October — since reversed, see above.) This used to pin "no word
 *     about returns": "Returns Information" absent from every footer. He has
 *     since asked for the old shop's policy links back — "Shipping & Delivery,
 *     Returns Information, Order Tracking, Feedback, FAQs, Privacy Policy …
 *     apply the links in the main footer" — so case 1 now pins the opposite:
 *     the Help column carries them, in that order, at addresses that answer
 *     (Feedback excepted: this shop has no such page);
 *   · an empty "Korea" line with nothing after it, or an invented address;
 *   · no way back to the footer the shop had, if he changes his mind;
 *   · a social profile saved as javascript:… reaching an href;
 *   · the colour drift running for a visitor who asked for reduced motion.
 *
 * MUTATION NOTES, RUN:
 *   · put the Returns Information row back in SiteFooter::helpLinks(), or
 *     make isReturnsLink() answer false → RED (case 1).
 *   · point Order Tracking back at /track-my-order/ → RED (case 1).
 *   · draw the Korea line without its `@if ($kft['korea'] !== '')` → RED (case 2).
 *   · make footer.blade.php always include footer-bliss → RED (case 3).
 *   · delete the prefers-reduced-motion block from kbb.css → RED (case 5).
 *   · put `color:inherit` back in `.kft a{}` → RED (case 5): the WhatsApp
 *     button's words go white on its white pill, as they did on the preview.
 */

use App\Models\AdminUser;
use App\Services\SettingsService;
use App\Services\SiteFooter;
use Illuminate\Support\Str;

function kftSave(array $values): void
{
    app(SiteFooter::class)->save($values);
    SettingsService::forgetMemo();
}

function kftBlock(string $html): string
{
    return preg_match('#<footer class="kft[^"]*"[^>]*>.*?</footer>#s', $html, $m) ? $m[0] : '';
}

it('ships the new footer on every page, with the policy links in its Help column and no returns link', function () {
    $html = $this->get('/')->assertOk()->getContent();
    $footer = kftBlock($html);

    expect($footer)->not->toBe('')
        ->and($footer)->toContain('Find your perfect K-beauty match <span class="kft-avail">24/7 available</span>')
        ->and($html)->not->toContain('<footer><div class="wrap">');

    preg_match('#<nav class="kft-col kft-col2"[^>]*>.*?</nav>#s', $footer, $help);
    preg_match_all('#<a href="([^"]*)">([^<]*)</a>#', $help[0] ?? '', $links, PREG_SET_ORDER);

    expect(array_map(fn ($l) => html_entity_decode($l[2]).' '.$l[1], $links))->toBe([
        'Shipping & Delivery /delivery/',
        'Order Tracking /my-account/orders/',
        'FAQs /faqs/',
        'Privacy policy /privacy-policy/',
        'Contact us /contact-us/',
        'About us /about/',
    ]);

    // Every one answers: a page, or (Order Tracking, for a guest) the sign-in
    // that brings them back to the order list.
    foreach (['/delivery/', '/refund_returns/', '/faqs/', '/privacy-policy/', '/contact-us/', '/about/'] as $path) {
        $this->get($path)->assertOk();
    }
    $this->get('/my-account/orders/')->assertRedirect();
    expect(session('url.intended'))->toContain('/my-account/orders');
    expect($footer)->not->toMatch('/\breturns?\b/i')->not->toContain('refund_returns');

    // A footer menu the owner built WITH a Returns row: not drawn (Lane EC),
    // whether it says so in the label or only in the address.
    $links = SiteFooter::helpLinks([
        ['label' => 'Returns & Refunds', 'url' => '/refund_returns/'],
        ['label' => 'Our policy', 'url' => '/returns-information/'],
        ['label' => 'معلومات الإرجاع', 'url' => '/ar/x/'],
        ['label' => 'Track my order', 'url' => '/track-my-order/'],
    ]);
    expect(array_column($links, 'label'))->toBe(['Track my order']);

    // …nor typed into the Help column's own link list.
    kftSave(['site_col2_links' => "Returns | /refund_returns/\nFAQs | /faqs/"]);
    $typed = kftBlock($this->get('/')->getContent());
    expect($typed)->toContain('>FAQs</a>')->not->toContain('refund_returns');
});

it('draws an address only when one was typed, and the Visit us block only when there is one', function () {
    $footer = kftBlock($this->get('/')->getContent());
    expect($footer)->not->toContain('Visit us')->not->toContain('Dubai, UAE')->not->toContain('kft-visit');

    kftSave(['site_addr_korea' => 'Gangnam-gu, Seoul']);
    $footer = kftBlock($this->get('/')->getContent());
    expect($footer)->toContain('<h2 class="kft-ch">Visit us</h2>')
        ->toContain('<b>Korea</b>Gangnam-gu, Seoul</span>')
        ->not->toContain('Dubai, UAE');

    kftSave(['site_addr_dubai' => 'Business Bay, Dubai']);
    expect(kftBlock($this->get('/')->getContent()))->toContain('<b>Dubai, UAE</b>Business Bay, Dubai</span>');
});

it('switches back to the old footer, byte for byte, and only to one of its own two designs', function () {
    kftSave(['site_design' => 'classic']);

    $html = $this->get('/')->getContent();
    expect($html)->toContain('<footer><div class="wrap">')
        ->and($html)->not->toContain('class="kft')
        ->and(view('partials.footer', ['kbbFooterNav' => [], 'kbbSettings' => app(SettingsService::class)])->render())
        ->toBe(view('partials.footer-classic', ['kbbFooterNav' => [], 'kbbSettings' => app(SettingsService::class)])->render());

    kftSave(['site_design' => '"><script>alert(1)</script>']);
    expect(app(SiteFooter::class)->design())->toBe('bliss');
});

it('is fed from the shop\'s own settings, and refuses a profile that is not a web address', function () {
    app(SettingsService::class)->set('brand_whatsapp', '+971 50 123 4567');
    app(SettingsService::class)->set('social_tiktok', 'javascript:alert(1)');
    app(SettingsService::class)->set('social_youtube', 'https://www.youtube.com/@kbeautybliss');
    SettingsService::forgetMemo();

    $footer = kftBlock($this->get('/')->getContent());

    expect($footer)->toContain('href="https://wa.me/971501234567" target="_blank" rel="noopener">Chat on WhatsApp</a>')
        ->toContain('aria-label="YouTube"')
        ->not->toContain('aria-label="TikTok"')
        ->not->toContain('javascript:')
        // ▲ 2.60.466 (Lane AN): the drift's empty copy of the name (.kft-nm,
        // the compositor moves its colours) comes first inside the <p>.
        ->toContain('<p class="kft-name" aria-hidden="true" style="--kft-n:14"><span class="kft-nm" data-kft-copy="K-Beauty Bliss"></span>K-Beauty Bliss</p>');

    kftSave(['site_name_text' => '<i>KBB</i>', 'site_help_title' => 'Need a hand?', 'site_track_on' => false, 'site_news_on' => false]);
    $footer = kftBlock($this->get('/')->getContent());
    expect($footer)->toContain('style="--kft-n:8"><span class="kft-nm" data-kft-copy="KBB"></span>KBB</p>')
        ->toContain('Need a hand? <span class="kft-avail">')
        ->not->toContain('kft-bt-o')
        ->not->toContain('kft-news')
        // No address and no sign-up box: the fifth column is not drawn at all.
        ->toContain('<div class="kft-grid kft-grid-4">');
});

it('moves with CSS only, and never for a visitor who asked for reduced motion', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $start = strpos($css, '/* ══ THE SITE FOOTER');
    $block = substr($css, $start);

    expect($start)->not->toBeFalse()
        ->and($block)->toContain('@keyframes kft-drift{from{background-position:0% 50%}to{background-position:100% 50%}}')
        // ▲ 2.60.466 (Lane AN): the strip's drift moved to its ::before, which
        // stops too; the name's compositor copy is drawn only under
        // prefers-reduced-motion:no-preference.
        ->and($block)->toContain("@media (prefers-reduced-motion:reduce){\n  .kft-motion .kft-help,.kft-motion .kft-help::before,.kft-motion .kft-name{animation:none}")
        ->and($block)->toContain("@supports (mix-blend-mode:plus-lighter) and selector(:has(*)){\n  @media (prefers-reduced-motion:no-preference){")
        // The big name's size is its length, printed by the server: nothing measures it.
        // (Lane HF) times the owner's size slider, 100 = the size that fits.
        ->and($block)->toContain('font-size:calc(min(calc(173.6vw / var(--kft-n, 14)), calc(2324px / var(--kft-n, 14))) * var(--kft-fn-d, 100) / 100)')
        // `footer.kft` out-specifies the old dark rule without editing it.
        ->and($css)->toContain('  footer{background:#241C20;color:#CDBFC6;padding:52px 0 26px}')
        ->and($block)->toContain('footer.kft{background:var(--kft-bg,#fff);')
        // Found on the preview: `.kft a{color:inherit}` (0,1,1) outranked
        // `.kft-bt-p{color:#063F37}` (0,1,0), and "Chat on WhatsApp" was white
        // on a white pill — an empty button. No colour on the bare-link rule.
        ->and($block)->not->toMatch('/\.kft a\{[^}]*color/');

    kftSave(['site_motion' => false]);
    $open = preg_match('#<footer class="(kft[^"]*)"#', $this->get('/')->getContent(), $m) ? $m[1] : '';
    expect($open)->toStartWith('kft ')->not->toContain('kft-motion');
});

it('is on Appearance → Footer, first, under the existing capability', function () {
    $owner = AdminUser::create(['name' => 'O', 'email' => 'kft-'.Str::random(6).'@example.com', 'password' => bcrypt('x'), 'role' => 'owner']);

    $body = $this->actingAs($owner, 'admin')->getJson('/admin-api/slim-footer')->assertOk()->json();
    expect(array_slice(collect($body['tabs'])->pluck('label')->all(), 0, 3))
        ->toBe(['Site footer · design', 'Site footer · help strip', 'Site footer · Visit us & name']);

    $this->actingAs($owner, 'admin')->postJson('/admin-api/slim-footer', ['settings' => ['site_design' => 'classic', 'brand' => 'KBB']])
        ->assertOk();
    SettingsService::forgetMemo();
    expect(app(SiteFooter::class)->design())->toBe('classic')
        ->and(app(\App\Services\SlimFooter::class)->get('brand'))->toBe('KBB');

    $this->actingAs($owner, 'admin')->postJson('/admin-api/slim-footer', ['settings' => ['site_made_up' => 1]])->assertStatus(422);
});
