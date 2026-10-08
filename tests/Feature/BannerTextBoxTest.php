<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Models\Setting;
use App\Services\Banners;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\BannerTextBox;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Lane HB -- the words on a slider picture ("Text box", styles A and D).
 *
 * The owner: "each slide/image will have a beautiful overlayed text box with a
 * button ... give full control of hide show any element, and according the box
 * height will be adjusted auto ... the button i need small ... give control to
 * reduce the button size by drag, across all banners together".
 *
 * Every test names the defect it stops in its own words, and the MUTATION note
 * is the change that turns it red.
 */

require_once __DIR__.'/../Support/BannerTextBoxHelpers.php';

/* ═════════════════════════ ships byte-identical ═══════════════════════════ */

it('draws nothing new for a picture whose words switch is off, even with old card headings in it', function () {
    /*
     * THE DEFECT THIS STOPS: a set converted from the cards banner kept its
     * cards' heading / body / button label in the same columns. Drawing a box
     * wherever a heading exists would have put those old words on the live
     * homepage the moment the package applied. box_on migrates false.
     *
     * MUTATION: drop the box_on check at the top of BannerTextBox::words()
     * and the first expectation is red.
     */
    $old = hbRender(hbSet([], 2, [1 => ['heading' => 'OLD CARDS HEADING', 'body' => 'old body', 'button_label' => 'Old']]));
    $bare = hbRender(hbSet([], 2));

    expect($old)->not->toContain('OLD CARDS HEADING')
        ->and($old)->not->toContain('hb-box')
        ->and($old)->not->toContain('has-hb')
        ->and($old)->not->toContain('--hb-')
        // Byte for byte the slider it was, apart from the set's own slug/ids.
        ->and(preg_replace('/kbbs-\d+/', 'kbbs-N', $old))->toBe(preg_replace('/kbbs-\d+/', 'kbbs-N', $bare));
});

it('prints the bytes the slider printed before Lane HB when no picture has words', function () {
    /*
     * The template, with every Lane HB print taken back out, is the slider as
     * it was. Rendered from the same row, the two must be identical to the
     * byte -- which is what StorefrontEnglishUnchangedTest needs and what a
     * Blade directive can quietly break: `@endif` at the end of a line eats
     * that line's newline. MUTATION, run: put `@if ($hbAny)...@endif` back at
     * the end of the `</style>` line (where it was first written) and this
     * goes red by exactly the newline the first preview diff showed.
     */
    $src = (string) file_get_contents(resource_path('views/partials/home/slider-banner.blade.php'));
    $prints = [
        "@if (\$hbAny)@include('partials.home.slider-text-box-css')@endif",
        "{{ \$hbAny ? ' has-hb hb-'.\$hbCfg['style'].' hb-glow-'.\$hbCfg['glow'].BannerTextBox::rootClasses(\$hbCfg) : '' }}",
        "{{ \$hbAny ? ';'.BannerTextBox::cssVariables(\$hbCfg).BannerTextBox::siteVariables(\$hbCfg) : '' }}",
        "@if (\$hbWords[\$bsI] !== null)@include('partials.home.slider-text-box', ['hbW' => \$hbWords[\$bsI], 'hbRing' => \$bsUid.'-r'.\$bsI])@endif",
        '{!! $hbNoTab !!}',
    ];

    foreach ($prints as $print) {
        expect(substr_count($src, $print))->toBe(1, 'the Lane HB print moved: '.$print);
        $src = str_replace($print, '', $src);
    }

    $pristine = storage_path('framework/testing/hb-pristine-'.getmypid().'.blade.php');
    @mkdir(dirname($pristine), 0775, true);
    file_put_contents($pristine, $src);

    try {
        $set = hbSet([], 3, [1 => ['heading' => 'OLD HEADING', 'body' => 'b', 'button_label' => 'l']]);
        [$loaded, $cards] = app(Banners::class)->forPreview($set->id);
        $data = ['set' => $loaded, 'cards' => $cards, 'sections' => app(\App\Services\HomepageSections::class)];

        $now = view('partials.home.slider-banner', $data)->render();
        $was = view()->file($pristine, $data)->render();
    } finally {
        @unlink($pristine);
    }

    expect($now)->toBe($was);
});

it('draws no box, and no stylesheet, for a picture switched on with every word empty', function () {
    /*
     * "A picture with every text field empty shows NO box: today's look
     * exactly." MUTATION: return the array instead of null at the end of
     * words() when all four are empty, and `hb-box` appears.
     */
    $on = hbRender(hbSet([], 2, [1 => ['box_on' => true]]));
    $bare = hbRender(hbSet([], 2));

    expect($on)->not->toContain('hb-box')
        ->and(preg_replace('/kbbs-\d+/', 'kbbs-N', $on))->toBe(preg_replace('/kbbs-\d+/', 'kbbs-N', $bare));
});

it('prints the box stylesheet once, only when a box draws', function () {
    // MUTATION: drop the @if ($hbAny) round the include and the bare set gains it.
    $html = hbRender(hbSet([], 3, [1 => hbWords(), 2 => hbWords()]));

    expect(substr_count($html, '.kbbs.has-hb .kbbs-s{position:relative}'))->toBe(1)
        ->and(substr_count($html, 'class="hb-box'))->toBe(2)
        ->and(hbRender(hbSet([], 3)))->not->toContain('has-hb');
});

/* ═══════════════════════════ show / hide, auto height ═════════════════════ */

it('hides each element on its own, set-wide, and keeps the rest', function (string $show, string $gone) {
    /*
     * "give full control of hide show any element". MUTATION: ignore one
     * show_* flag in words() and its row goes red.
     */
    $html = hbRender(hbSet(['text_box' => json_encode([$show => false])], 1, [1 => hbWords()]));
    $box = hbBox($html);

    expect($box)->not->toBe('')
        ->and($box)->not->toContain($gone);

    foreach (['NEW IN EYEBROW', 'Glass skin starts here', 'SHORT TEXT LINE', 'Shop the Glow Edit'] as $word) {
        if ($word !== $gone) {
            expect($box)->toContain($word);
        }
    }
})->with([
    'eyebrow' => ['show_eyebrow', 'NEW IN EYEBROW'],
    'heading' => ['show_heading', 'Glass skin starts here'],
    'text' => ['show_text', 'SHORT TEXT LINE'],
    'button' => ['show_button', 'Shop the Glow Edit'],
]);

it('hides an element on one picture when only that picture leaves it empty', function () {
    $html = hbRender(hbSet([], 2, [1 => hbWords(['body' => '']), 2 => hbWords()]));

    expect(hbBox($html, 0))->not->toContain('hb-t')
        ->and(hbBox($html, 1))->toContain('SHORT TEXT LINE');
});

it('leaves no element behind when it is hidden, so the box can only be as tall as what it holds', function () {
    /*
     * "according the box height will be adjusted auto". The box is a flex
     * column with gaps and NO height of any kind; a hidden element is not
     * printed at all (no empty <p> holding a line), so nothing is left to keep
     * the space. MUTATION: give .hb-box a height or min-height, or print an
     * empty <p class="hb-t"> for an empty text, and this is red.
     */
    $css = (string) file_get_contents(resource_path('views/partials/home/slider-text-box-css.blade.php'));
    preg_match('/\.hb-box\{[^}]*\}/', $css, $rule);

    expect($rule[0] ?? '')->toContain('flex-direction:column')
        ->and($rule[0] ?? '')->not->toMatch('/(^|[;{])\s*(min-|max-)?height\s*:/');

    $box = hbBox(hbRender(hbSet([], 1, [1 => hbWords(['eyebrow' => '', 'body' => ''])])));

    expect($box)->not->toContain('hb-eb')->and($box)->not->toContain('hb-t"')->and($box)->not->toContain('<p></p>');
});

it('shows the sticker only on the Sticker card, and only when the switch and the word are both there', function () {
    $d = ['text_box' => json_encode(['style' => 'd'])];

    expect(hbBox(hbRender(hbSet($d, 1, [1 => hbWords(['sticker' => 'NEW', 'sticker_ring' => 'JUST LANDED'])]))))
        ->toContain('<b>NEW</b>')->toContain('JUST LANDED ✦ JUST LANDED ✦')
        ->and(hbBox(hbRender(hbSet([], 1, [1 => hbWords(['sticker' => 'NEW'])])))) // style A
        ->not->toContain('hb-stk')
        ->and(hbBox(hbRender(hbSet(['text_box' => json_encode(['style' => 'd', 'show_sticker' => false])], 1, [1 => hbWords(['sticker' => 'NEW'])]))))
        ->not->toContain('hb-stk');
});

it('turns the slide dots off with the set\'s existing bars switch', function () {
    // The Text box screen's "Slide dots" writes show_dots, the column the bars already obey.
    expect(hbRender(hbSet(['show_dots' => false], 2, [1 => hbWords()])))->not->toContain('class="kbbs-bar')
        ->and(hbRender(hbSet(['show_dots' => true], 2, [1 => hbWords()])))->toContain('class="kbbs-bar');
});

/* ═══════════════════════════════ security ════════════════════════════════ */

it('escapes every word, and makes <mark> only from *asterisks* after escaping, only on the Sticker card', function () {
    /*
     * MUTATION: run the *word* replacement before e() in headingHtml(), or print
     * the heading with {!! !!} unescaped, and the <img onerror> survives.
     */
    $evil = ['heading' => '<img src=x onerror=alert(1)> Hello *soft glow*', 'eyebrow' => '<b>eb</b>',
        'body' => '"><script>alert(2)</script>', 'button_label' => '<i>go</i>', 'sticker' => '<s>'];

    $d = hbRender(hbSet(['text_box' => json_encode(['style' => 'd'])], 1, [1 => hbWords($evil)]));
    $a = hbRender(hbSet([], 1, [1 => hbWords($evil)]));

    foreach ([$d, $a] as $html) {
        $box = hbBox($html);
        expect($box)->not->toContain('<img')->not->toContain('<script')->not->toContain('<b>eb')->not->toContain('<i>go')
            ->toContain('&lt;img src=x onerror=alert(1)&gt;');
    }

    expect(hbBox($d))->toContain('Hello <mark>soft glow</mark>')
        ->and(hbBox($a))->toContain('Hello soft glow')->not->toContain('<mark>')
        ->and(BannerTextBox::headingHtml('*<b>x</b>*'))->toBe('<mark>&lt;b&gt;x&lt;/b&gt;</mark>')
        ->and(BannerTextBox::headingHtml('<mark>typed</mark>'))->toBe('&lt;mark&gt;typed&lt;/mark&gt;');
});

it('puts only http, https or a path on the button', function (string $url, bool $button) {
    /*
     * MUTATION: return Banners::safeUrl() from buttonUrl() and mailto/tel grow
     * a "Shop" pill; drop the scheme check and javascript: gets one.
     */
    $box = hbBox(hbRender(hbSet([], 1, [1 => hbWords(['button_url' => $url])])));

    expect(str_contains($box, 'hb-btn'))->toBe($button);
})->with([
    'path' => ['/collections/glow-edit', true],
    'https' => ['https://example.com/x', true],
    'http' => ['http://example.com/x', true],
    'javascript' => ['javascript:alert(1)', false],
    'entity javascript' => ['jav&#x09;ascript:alert(1)', false],
    'protocol-relative' => ['//evil.example/x', false],
    'mailto' => ['mailto:a@b.c', false],
    'data' => ['data:text/html,<b>x</b>', false],
]);

it('clamps every size and refuses unknown options, on the way in and on the way out', function () {
    /*
     * MUTATION: drop the max()/min() in normalize() and the 999 survives into
     * the CSS; drop the Rule::in on tb_style and the 'z' is stored.
     */
    $n = BannerTextBox::normalize(['style' => 'z', 'glow' => '}', 'button' => 'grad;x', 'size_h_d' => 999, 'size_h_m' => -5,
        'size_t_d' => 'x;}', 'size_b_d' => 87, 'size_w_m' => 1e9, 'show_text' => 'no', 'evil' => 'x']);

    expect($n['style'])->toBe('a')->and($n['glow'])->toBe('pastel')->and($n['button'])->toBe('fill')
        ->and($n['size_h_d'])->toBe(44)->and($n['size_h_m'])->toBe(20)->and($n['size_t_d'])->toBe(15)
        ->and($n['size_b_d'])->toBe(85)->and($n['size_w_m'])->toBe(100)->and($n['show_text'])->toBeFalse()
        ->and($n)->not->toHaveKey('evil');

    $vars = BannerTextBox::cssVariables(['size_h_d' => '30px;}body{x:y', 'size_w_d' => 999]);

    // ▲ Lane HB2 added the position numbers (--hb-vg1-d, --hb-xo-m, ...): still numbers only.
    expect($vars)->toMatch('/^(--hb-[a-z0-9]+-[dm]:-?[0-9.]+(px|%)?;?)+$/')
        ->and($vars)->toContain('--hb-w-d:560px')->toContain('--hb-h-d:34px');

    // And through the admin endpoint.
    $user = AdminUser::create(['name' => 'HB', 'email' => 'hb-'.uniqid().'@example.com', 'password' => Hash::make('secret-secret'), 'role' => 'owner']);
    test()->actingAs($user, 'admin');
    $set = hbSet([], 1);

    test()->putJson('/admin-api/banners/sets/'.$set->id, ['tb_style' => 'z'])->assertStatus(422);
    test()->putJson('/admin-api/banners/sets/'.$set->id, ['tb_size_h_d' => 999, 'tb_style' => 'd', 'tb_button' => 'grad'])->assertOk();

    $stored = BannerTextBox::forSet($set->fresh());

    expect($stored['size_h_d'])->toBe(44)->and($stored['style'])->toBe('d')->and($stored['button'])->toBe('grad');
});

it('draws Gradient only on the Sticker card', function () {
    expect(hbBox(hbRender(hbSet(['text_box' => json_encode(['button' => 'grad'])], 1, [1 => hbWords()]))))->toContain('hb-btn hb-fill')
        ->and(hbBox(hbRender(hbSet(['text_box' => json_encode(['button' => 'grad', 'style' => 'd'])], 1, [1 => hbWords()]))))->toContain('hb-btn hb-grad');
});

/* ═════════════════════════════ valid HTML ════════════════════════════════ */

it('never puts a link inside a link, and keeps the whole picture a click', function () {
    /*
     * The slide was one <a> round the picture. MUTATION: include the box
     * inside the <a class="kbbs-a"> and the nesting check is red.
     */
    $html = hbRender(hbSet([], 2, [1 => hbWords(), 2 => hbWords(['button_label' => ''])]));

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xp = new DOMXPath($dom);

    expect($xp->query('//a//a')->length)->toBe(0)
        // Slide 1: the button is the first link (the script's tab stop), the picture is still a link, out of the tab order.
        ->and($xp->query('(//div[contains(concat(" ",@class," ")," kbbs-s ")])[1]//a')->item(0)->getAttribute('class'))->toBe('hb-btn hb-fill')
        ->and($xp->query('(//div[contains(concat(" ",@class," ")," kbbs-s ")])[1]//a[@class="kbbs-a"]')->item(0)->getAttribute('tabindex'))->toBe('-1')
        ->and($xp->query('(//div[contains(concat(" ",@class," ")," kbbs-s ")])[1]//a[@class="kbbs-a"]')->item(0)->getAttribute('href'))->not->toBe('')
        // Slide 2 has no button: the picture is the slide's link, as it always was.
        ->and($xp->query('(//div[contains(concat(" ",@class," ")," kbbs-s ")])[2]//a[@class="kbbs-a"]')->item(0)->hasAttribute('tabindex'))->toBeFalse();
});

it('uses h2 for the box heading so the homepage keeps exactly one h1', function () {
    // MUTATION: make the heading an <h1> and this is red.
    $html = hbRender(hbSet([], 2, [1 => hbWords(), 2 => hbWords()]));

    expect(substr_count($html, '<h1'))->toBe(0)->and(substr_count($html, '<h2 class="hb-h"'))->toBe(2);
});

/* ════════════════════════ phones without a phone picture ════════════════ */

it('hides the box on phones for a picture with no phone picture', function () {
    /*
     * Without one, a phone draws the 1920 x 550 picture whole: 390 x 112, too
     * short for any box. MUTATION: drop `no-m` from the box's class and red.
     */
    $html = hbRender(hbSet([], 2, [1 => hbWords(['image_m' => '', 'image_m_w' => null, 'image_m_h' => null]), 2 => hbWords()]));
    $css = (string) file_get_contents(resource_path('views/partials/home/slider-text-box-css.blade.php'));

    expect($html)->toContain('<div class="hb-pos no-m"><div class="hb-box">')
        ->and(substr_count($html, '<div class="hb-pos"><div class="hb-box">'))->toBe(1)
        ->and($css)->toMatch('/@media \(max-width:767\.98px\)\{\s*\.hb-pos\.no-m\{display:none\}/');
});

/* ═══════════════════════════════ Arabic ═══════════════════════════════════ */

it('uses the Arabic words on the Arabic shop, and the English where an Arabic one is empty', function () {
    /*
     * MUTATION: drop the `_ar` lookup in words() and the Arabic heading is
     * missing; drop the fallback and the English eyebrow is.
     */
    hbArabic();

    $html = hbRender(hbSet(['text_box' => json_encode(['style' => 'd'])], 1, [1 => hbWords([
        'heading_ar' => 'بشرة زجاجية تبدأ من هنا', 'button_label_ar' => 'تسوّقي الآن',
        'sticker' => 'NEW', 'sticker_ar' => 'جديد', 'sticker_ring' => 'JUST LANDED', 'sticker_ring_ar' => 'وصل حديثاً',
    ])]));
    $box = hbBox($html);

    expect($box)->toContain('بشرة زجاجية تبدأ من هنا')->toContain('تسوّقي الآن')->toContain('<b>جديد</b>')
        ->toContain('NEW IN EYEBROW') // no Arabic eyebrow typed -> the English one
        ->not->toContain('Glass skin starts here')
        // Arabic on a path: no letter-stretching, which breaks the joins.
        ->not->toContain('textLength');
});

it('uses the English words on the English shop even when Arabic ones exist', function () {
    $box = hbBox(hbRender(hbSet([], 1, [1 => hbWords(['heading_ar' => 'عربي'])])));

    expect($box)->toContain('Glass skin starts here')->not->toContain('عربي');
});

/* ═════════════════════════════ speed ══════════════════════════════════════ */

it('costs no query: the words come in the row the slider already loads', function () {
    /*
     * MUTATION: read a card's words with a fresh query (BannerCard::find) in
     * words() and the two counts differ.
     */
    $count = function (BannerSet $set): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        hbRender($set);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    hbRender(hbSet([], 3)); // warm the settings and translations memos, which the homepage has warm already
    $bare = $count(hbSet([], 3));
    $words = $count(hbSet(['text_box' => json_encode(['style' => 'd'])], 3, [1 => hbWords(['sticker' => 'NEW']), 2 => hbWords(), 3 => hbWords()]));

    expect($words)->toBe($bare)->and($bare)->toBe(1);
});

it('adds no script and measures nothing', function () {
    foreach (['slider-text-box.blade.php', 'slider-text-box-css.blade.php'] as $file) {
        $src = (string) file_get_contents(resource_path('views/partials/home/'.$file));

        expect($src)->not->toContain('<script')
            ->and($src)->not->toMatch('/getBoundingClientRect|offset(Width|Height|Top)|client(Width|Height)|getComputedStyle|ResizeObserver|IntersectionObserver/');
    }
});

/* ═════════════════════════════ admin ══════════════════════════════════════ */

it('saves the words of a picture and the set\'s text box through Save, and sends them back', function () {
    $user = AdminUser::create(['name' => 'HB', 'email' => 'hb-'.uniqid().'@example.com', 'password' => Hash::make('secret-secret'), 'role' => 'owner']);
    test()->actingAs($user, 'admin');
    $set = hbSet([], 1);
    $card = $set->cards()->first();

    test()->putJson('/admin-api/banners/sets/'.$set->id.'/all', [
        'set' => ['tb_glow' => 'white', 'tb_show_text' => false, 'tb_size_b_m' => 80],
        'cards' => [['id' => $card->id, 'box_on' => true, 'box_pos' => 'end', 'eyebrow' => ' Eb ', 'heading_ar' => 'عنوان', 'sticker_ring' => 'RING']],
    ])->assertOk()
        ->assertJsonPath('set.tb_glow', 'white')
        ->assertJsonPath('set.tb_show_text', false)
        ->assertJsonPath('set.tb_size_b_m', 80)
        ->assertJsonPath('cards.0.box_on', true)
        ->assertJsonPath('cards.0.box_pos', 'end')
        ->assertJsonPath('cards.0.eyebrow', 'Eb')
        ->assertJsonPath('cards.0.heading_ar', 'عنوان');

    test()->putJson('/admin-api/banners/sets/'.$set->id.'/all', [
        'cards' => [['id' => $card->id, 'box_pos' => 'middle']],
    ])->assertStatus(422);
});

it('refuses the text box to a signed-out visitor', function () {
    $set = hbSet([], 1);

    expect(test()->putJson('/admin-api/banners/sets/'.$set->id, ['tb_style' => 'd'])->status())->toBeIn([401, 403, 419]);
    expect(BannerTextBox::forSet($set->fresh())['style'])->toBe('a');
});

it('sends every text box key from the console, and only those', function () {
    /*
     * A control on the screen whose key is missing from TB_KEYS saves nothing;
     * a key the server does not know is dropped. MUTATION: take 'tb_glow' out
     * of TB_KEYS in banners-screen.blade.php and this is red.
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/banners-screen.blade.php'));
    preg_match('/var TB_KEYS = \[(.*?)\];/s', $screen, $m);
    preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $keys);

    $want = array_map(fn ($k) => 'tb_'.$k, BannerTextBox::keys());
    sort($want);
    $sent = $keys[1];
    sort($sent);

    expect($sent)->toBe($want)
        ->and($screen)->toContain('SET_KEYS = SET_KEYS.concat(TB_KEYS);');

    foreach ($want as $key) {
        expect($screen)->toContain("'".$key);
    }
});
