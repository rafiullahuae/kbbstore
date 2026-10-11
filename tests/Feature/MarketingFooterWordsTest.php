<?php

declare(strict_types=1);

use App\Services\Marketing\Blocks;
use App\Services\Marketing\CampaignRenderer;
use App\Services\SettingsService;

/**
 * The marketing footer's own words, owner-editable (Lane RO2). The owner,
 * 11 October, about to send a campaign: "give me all footer easy editor".
 * Before this the line under the logo, "You are receiving this because you
 * shopped with K-Beauty Bliss UAE before.", the copyright and the link labels
 * were fixed wording with no box anywhere.
 *
 * What each case stops, as it would look in an inbox:
 *   · a box the owner filled in that the email ignores (the old fixed line);
 *   · every campaign already written changing its footer the day this ships;
 *   · an empty Unsubscribe box leaving a link with no words to click;
 *   · a typed "<b>" arriving as markup instead of text.
 */
function fwRender(array $footer, string $theme = 'standard', string $locale = 'en'): array
{
    $r = app(CampaignRenderer::class);
    $blocks = [Blocks::make('heading', ['title' => 'Hello']), Blocks::make('footer', $footer)];

    return $r->render($r->materialize(Blocks::clean($blocks)), [
        'audience' => 'customers', 'first_name' => 'Aisha', 'subject' => 'Hi', 'preheader' => '',
        'unsubscribe' => 'https://extrabeauty.ae/email/u/1-' . str_repeat('a', 32), 'theme' => $theme, 'locale' => $locale,
    ]);
}

beforeEach(fn () => app(SettingsService::class)->set('store_name', 'K-Beauty Bliss UAE'));

it('prints the owner\'s own footer words, with {store} and {year} filled in, in every look', function (string $theme) {
    $out = fwRender([
        'tagline' => 'Glow from Seoul to Dubai',
        'why_text' => 'You get this because you love {store}.',
        'copyright' => '© {year} {store} — made in Dubai',
        'unsub_label' => 'Stop these emails', 'prefs_label' => 'Choose what I get', 'view_label' => 'Open in browser',
    ], $theme);
    $html = html_entity_decode($out['html']);
    $year = now()->format('Y');

    expect($html)->toContain('Glow from Seoul to Dubai')
        ->toContain('You get this because you love K-Beauty Bliss UAE.')
        ->toContain('© ' . $year . ' K-Beauty Bliss UAE — made in Dubai')
        ->toContain('>Stop these emails</a>')
        ->toContain('>Choose what I get</a>')
        ->toContain('>Open in browser</a>')
        ->not->toContain('because you shopped with')
        ->not->toContain('{store}')
        ->not->toContain('{year}')
        // MUTATION: drop the copyright line from the plain text in render() and this is red.
        ->and($out['text'])->toContain('You get this because you love K-Beauty Bliss UAE.')
        ->toContain('made in Dubai');
})->with(['standard', 'playful']);

it('keeps the built-in footer, unchanged, while the boxes are empty', function (string $theme, string $why) {
    $html = html_entity_decode(fwRender([], $theme)['html']);

    expect($html)->toContain($why)->toContain('>Unsubscribe</a>')->toContain('>Email preferences</a>');
})->with([
    ['standard', 'You are receiving this because you bought from K-Beauty Bliss UAE before.'],
    ['playful', 'You are receiving this because you shopped with K-Beauty Bliss UAE before.'],
]);

it('never leaves the unsubscribe link without words, and prints typed markup as text', function (string $theme) {
    $html = fwRender(['unsub_label' => '   ', 'copyright' => '<b>Bliss</b>', 'prefs_label' => '"><script>x</script>'], $theme)['html'];

    // MUTATION: print a label raw in the footer and the <script> assertion is red.
    expect($html)->toMatch('#href="https://extrabeauty\.ae/email/u/1-a{32}"[^>]*>Unsubscribe</a>#')
        ->toContain('&lt;b&gt;Bliss&lt;/b&gt;')
        ->not->toContain('<b>Bliss</b>')
        ->not->toContain('<script>x</script>');
})->with(['standard', 'playful']);

it('uses the why line in the personal letter look too', function () {
    $html = html_entity_decode(fwRender(['why_text' => 'Sent with care by {store}.'], 'letter')['html']);

    expect($html)->toContain('Sent with care by K-Beauty Bliss UAE.')->not->toContain('because you bought from');
});

it('caps each footer box at its length', function () {
    $clean = Blocks::clean([Blocks::make('footer', ['why_text' => str_repeat('a', 300), 'unsub_label' => str_repeat('b', 90)])]);

    expect(mb_strlen($clean[0]['props']['why_text']))->toBe(200)
        ->and(mb_strlen($clean[0]['props']['unsub_label']))->toBe(40);
});
