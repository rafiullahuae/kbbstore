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
 *   · "Returns Information" in the footer of every page of a shop that offers
 *     no returns — the old footer's shipped default, and the footer menu could
 *     carry one too;
 *   · an empty "Korea" line with nothing after it, or an invented address;
 *   · no way back to the footer the shop had, if he changes his mind;
 *   · a social profile saved as javascript:… reaching an href;
 *   · the colour drift running for a visitor who asked for reduced motion.
 *
 * MUTATION NOTES, RUN:
 *   · delete the `preg_match(self::BANNED, …)` line in SiteFooter::helpLinks()
 *     → RED (case 1: the footer menu's Returns row is drawn).
 *   · draw the Korea line without its `@if ($kft['korea'] !== '')` → RED (case 2).
 *   · make footer.blade.php always include footer-bliss → RED (case 3).
 *   · delete the prefers-reduced-motion block from kbb.css → RED (case 5).
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
    return preg_match('#<footer class="kft[^"]*">.*?</footer>#s', $html, $m) ? $m[0] : '';
}

it('ships the new footer on every page, with no word about returns anywhere in it', function () {
    $html = $this->get('/')->assertOk()->getContent();
    $footer = kftBlock($html);

    expect($footer)->not->toBe('')
        ->and($footer)->toContain('Find your perfect K-beauty match <span class="kft-chip">24/7 available</span>')
        ->and($footer)->not->toMatch('/return|refund/i')
        ->and($html)->not->toContain('<footer><div class="wrap">');

    // A footer menu the owner built WITH a Returns row: still not drawn.
    $links = SiteFooter::helpLinks([
        ['label' => 'Returns & Refunds', 'url' => '/refund_returns/'],
        ['label' => 'Track my order', 'url' => '/track-my-order/'],
        ['label' => 'Exchange policy', 'url' => '/refund_returns/'],
    ]);
    expect(array_column($links, 'label'))->toBe(['Track my order']);

    // And the shipped defaults, when there is no footer menu, carry none either.
    expect(json_encode(SiteFooter::helpLinks([])))->not->toMatch('/return|refund/i');
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
        ->toContain('<p class="kft-name" aria-hidden="true" style="--kft-n:14">K-Beauty Bliss</p>');

    kftSave(['site_name_text' => '<i>KBB</i>', 'site_help_title' => 'Need a hand?', 'site_track_on' => false, 'site_news_on' => false]);
    $footer = kftBlock($this->get('/')->getContent());
    expect($footer)->toContain('style="--kft-n:8">KBB</p>')
        ->toContain('Need a hand? <span class="kft-chip">')
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
        ->and($block)->toContain("@media (prefers-reduced-motion:reduce){\n  .kft-motion .kft-help,.kft-motion .kft-name{animation:none}")
        // The big name's size is its length, printed by the server: nothing measures it.
        ->and($block)->toContain('font-size:min(calc(173.6vw / var(--kft-n, 14)), calc(2324px / var(--kft-n, 14)))')
        // `footer.kft` out-specifies the old dark rule without editing it.
        ->and($css)->toContain('  footer{background:#241C20;color:#CDBFC6;padding:52px 0 26px}')
        ->and($block)->toContain('footer.kft{background:#fff;');

    kftSave(['site_motion' => false]);
    expect($this->get('/')->getContent())->toContain('<footer class="kft">');
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
