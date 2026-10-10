<?php

declare(strict_types=1);

/**
 * The site footer, reworked to the owner's 4 October brief, and its full set of
 * controls. (Lane HF)
 *
 * THE OWNER, word for word: "in mobile footer, remove the top logo, and center
 * the social media icons, and description text. and on third column will be
 * Account and related links to access their account, orders etc. also center
 * the support text. i don't like the green, i want to use our color, and it
 * will continue shade within the our color range. also the bottom should be
 * with effects something like shiny bar going from left to right and on arabic
 * right to left, also give control for complete footer, for desktop and
 * mobile."
 *
 * Controls: Appearance → Footer → "Site footer · link columns", "· colours &
 * effects", "· layout desktop", "· layout mobile" (and the three tabs that were
 * there already). He asked for each of these, so each ships ON.
 *
 * The defects these pin, as each would look on the shop:
 *   · the K-BeautyBliss wordmark still sitting above the description on a
 *     phone, with the icons pushed to the right edge beside it;
 *   · a third column still headed "Discover", or an "Account" link to a page
 *     that does not exist (a 404 from the footer of every page) — and the old
 *     column's About us, Journal and #KBeautyBliss gone from the footer;
 *   · the WhatsApp greens he said he does not like;
 *   · a shine that sweeps the same way on the Arabic shop as on the English;
 *   · a link box that prints `javascript:` or `//evil.example` into an href, or
 *     brings the word "return" back to a shop that offers none;
 *   · a colour box whose value breaks out of the style attribute.
 *
 * MUTATION NOTES, RUN:
 *   · `site_m_logo` default true in SiteFooter::SCHEMA → RED (case 1).
 *   · drop `.kft-xm-logo .kft-logo` from the phone media block → RED (case 1).
 *   · put the "Discover" column back in SiteFooter::columns() → RED (case 2).
 *   · change /my-account/edit-address/ to /my-account/addresses/ (a POST-only
 *     path) → RED (case 2: no GET route answers it).
 *   · put #25D366 back in `.kft-help` → RED (case 3 and the colour-family case).
 *   · delete the `[dir="rtl"] … {animation-name:kft-sheen-rtl}` rule → RED (case 4).
 *   · return true from SiteFooter::safeAddress() → RED (case 5).
 *   · SiteFooter::POLICY 'hex' => 'repair' instead of 'expand' → RED (case 6:
 *     `#abc` reaches the page as the default rather than #AABBCC). (Emptying
 *     presentation()'s own hex check stays green: all() has already cast
 *     every stored colour, so that check is a second wall, not the first.)
 *   · put #C9B8FF back into `.kft-name` → RED (the colour-family case).
 *   · ship `site_c_to` as #E8734A → RED (contrast under the 3.6 floor).
 *   · move `.kft-bc-d .kft-brand{…}` out of the laptop media block → RED (case 1).
 */

use App\Models\AdminUser;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Services\SiteFooter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

function hfSave(array $values): void
{
    app(SiteFooter::class)->save($values);
    SettingsService::forgetMemo();
}

function hfFooter(\Tests\TestCase $t, string $path = '/'): string
{
    return preg_match('#<footer class="kft[^"]*"[^>]*>.*?</footer>#s', $t->get($path)->getContent(), $m) ? $m[0] : '';
}

/** The classes on the <footer> element itself. */
function hfClasses(string $footer): array
{
    return preg_match('#^<footer class="([^"]*)"#', $footer, $m) ? explode(' ', $m[1]) : [];
}

/** The `.kft` block of kbb.css: the site footer's own rules and nothing else. */
function hfCss(): string
{
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $start = (int) strpos($css, '/* ══ THE SITE FOOTER');

    return substr($css, $start, (int) strpos($css, '/* ── THE FLAG BAR', $start) - $start);
}

/** The body of the first `@media (<query>){ … }` in $css that holds $needle. */
function hfMedia(string $css, string $query, string $needle): string
{
    $offset = 0;

    while (($at = strpos($css, '@media ('.$query.'){', $offset)) !== false) {
        $depth = 0;

        for ($i = strpos($css, '{', $at); $i < strlen($css); $i++) {
            $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);

            if ($depth === 0) {
                break;
            }
        }

        $body = substr($css, $at, $i - $at);

        if (str_contains($body, $needle)) {
            return $body;
        }

        $offset = $i;
    }

    return '';
}

it('takes the logo off phones and centres the brand block and the help strip there, leaving the desktop as it was', function () {
    $footer = hfFooter($this);
    $classes = hfClasses($footer);

    // Shipped on, as he asked: the wordmark hidden on phones, both blocks centred.
    expect($classes)->toContain('kft-xm-logo')->toContain('kft-bc-m')->toContain('kft-hc-m')
        // …and nothing on the desktop side: every part shows and lines up as it did.
        ->and(array_values(array_filter($classes, static fn ($c) => str_starts_with($c, 'kft-xd-') || str_ends_with($c, '-d'))))->toBe([])
        // The wordmark is still in the markup for the desktop.
        ->and($footer)->toContain('<a class="kft-logo" href="/"><bdi>');

    $phone = hfMedia(hfCss(), 'max-width:900px', '.kft-xm-logo .kft-logo');

    expect($phone)->not->toBe('')
        ->toContain('.kft-bc-m .kft-brand{display:flex;flex-direction:column;align-items:center;text-align:center;gap:10px}')
        ->toContain('.kft-bc-m .kft-brand .kft-soc{justify-content:center}')
        ->toContain('.kft-hc-m .kft-help-in{justify-content:center;text-align:center}')
        ->toContain('.kft-hc-m .kft-help-tx{flex:1 1 100%}');

    /*
     * FOUND ON THE PREVIEW, not by reading: "Centred" on the laptop tab was a
     * bare `.kft-bc-d .kft-brand{text-align:center}`, so with Layout mobile set
     * back to "Lined up at the start" the phone's logo and description still
     * sat in the middle. The laptop's alignment rules live in the laptop's own
     * media block and nowhere else.
     * MUTATION, RUN: move `.kft-bc-d .kft-brand{text-align:center}` back out
     * of the min-width:901px block → RED.
     */
    $laptop = hfMedia(hfCss(), 'min-width:901px', '.kft-xd-logo .kft-logo');
    expect($laptop)->toContain('.kft-bc-d .kft-brand{text-align:center}')
        ->toContain('.kft-hc-d .kft-help-tx{text-align:center}')
        ->and(substr_count(hfCss(), '.kft-bc-d .kft-brand{'))->toBe(1)
        ->and(substr_count(hfCss(), '.kft-hc-d .kft-help-tx{'))->toBe(1);

    // And he can put the logo back, or line the block up again, from the console.
    hfSave(['site_m_logo' => true, 'site_m_brand_align' => 'start', 'site_m_help_align' => 'start']);
    expect(hfClasses(hfFooter($this)))->not->toContain('kft-xm-logo')->not->toContain('kft-bc-m')->not->toContain('kft-hc-m');
});

it('makes the third column Account, with links to real pages, and loses none of the old column\'s links', function () {
    $footer = hfFooter($this);

    preg_match_all('#<h2 id="kft-([a-z]+)" class="kft-ch">([^<]*)</h2>#', $footer, $m);
    expect($m[2])->toBe(['Shop', 'Help', 'Account'])
        ->and($footer)->not->toContain('Discover');

    preg_match('#<nav class="kft-col kft-col3"[^>]*>.*?</nav>#s', $footer, $acct);
    preg_match_all('#<a href="([^"]+)">([^<]+)</a>#', $acct[0] ?? '', $links, PREG_SET_ORDER);

    expect(array_map(static fn ($l) => [$l[2], $l[1]], $links))->toBe([
        ['My account', '/my-account/'],
        ['My orders', '/my-account/orders/'],
        ['Wishlist', '/my-wishlist/'],
        ['Addresses', '/my-account/edit-address/'],
    ]);

    // Every one is a GET route this application answers — not a guess at one.
    foreach ($links as $l) {
        $route = Route::getRoutes()->match(Request::create(rtrim($l[1], '/') ?: '/', 'GET'));
        expect($route->methods())->toContain('GET');
    }

    // A guest is sent through the shop's own sign-in, with the page remembered.
    $this->get('/my-account/orders/')->assertRedirect();
    expect(session('url.intended'))->toContain('/my-account/orders');

    // The Discover column's other three links moved rather than disappeared.
    preg_match('#<nav class="kft-col kft-col1"[^>]*>.*?</nav>#s', $footer, $shop);
    preg_match('#<nav class="kft-col kft-col2"[^>]*>.*?</nav>#s', $footer, $help);
    expect($shop[0])->toContain('<a href="/kbeautybliss-spotted/">#KBeautyBliss</a>')->toContain('<a href="/blog/">Journal</a>')
        ->and($help[0])->toContain('<a href="/about/">About us</a>');
});

it('uses the shop\'s own pinks, red and orange for the strip, drifting within them, and no green anywhere in the footer', function () {
    $css = hfCss();

    foreach (['#128C7E', '#12A37A', '#1EBE5D', '#25D366', '#063F37', '37,211,102', '6,63,55'] as $green) {
        expect(stripos($css, $green))->toBeFalse("the WhatsApp green {$green} is still in the site footer's rules");
    }

    expect($css)->toContain('.kft-help{color:#fff;background:linear-gradient(110deg,var(--kft-from,#E0567B),var(--kft-c2,#C13E63),var(--kft-c3,#E23A4E),var(--kft-to,#D9603B),var(--kft-c3,#E23A4E),var(--kft-c2,#C13E63),var(--kft-from,#E0567B));')
        // ▲ 2.60.466 (Lane AN): the drift is a 300% ::before moved by the
        // compositor, not the strip's own background-position (which cost a
        // style pass every frame) -- same gradient, same easing, same period.
        ->toContain('.kft-motion .kft-help{position:relative;isolation:isolate;overflow:hidden}')
        ->toContain('animation:kft-slide var(--kft-dr,14s) ease-in-out infinite alternate}')
        // The WhatsApp button is still a white pill, and its words are not white.
        ->toContain('.kft-bt-p{background:#fff;color:var(--kft-accent,#C13E63);')
        ->and($css)->toContain("@media (prefers-reduced-motion:reduce){\n  .kft-motion .kft-help,.kft-motion .kft-help::before,.kft-motion .kft-name{animation:none}");

    // Two of the four defaults are the shop's own tokens: --pink-deep, --sale.
    // ▲ Lane CT moved --pink to #C6395F for text contrast; the help strip's
    // first stop stays #E0567B on purpose — it is a gradient under 17-22px bold
    // white words (3:1 large text), the owner's approved footer, and was not in
    // the contrast approval.
    $root = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    expect($root)->toContain('--pink:#C6395F; --pink-deep:#C13E63;')->toContain('--sale:#E23A4E;');

    $style = (string) (preg_match('#<footer class="[^"]*" style="([^"]*)"#', hfFooter($this), $m) ? $m[1] : '');
    expect($style)->toStartWith('--kft-from:#E0567B;--kft-c2:#C13E63;--kft-c3:#E23A4E;--kft-to:#D9603B;');
});

/** [hue 0–360, saturation 0–1, lightness 0–1] of a #RRGGBB. */
function hfHsl(string $hex): array
{
    [$r, $g, $b] = array_map(static fn ($h) => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    $d = $max - $min;

    if ($d == 0) {
        return [0.0, 0.0, $l];
    }

    $s = $d / (1 - abs(2 * $l - 1));
    $h = match ($max) {
        $r => fmod(($g - $b) / $d + 6, 6),
        $g => ($b - $r) / $d + 2,
        default => ($r - $g) / $d + 4,
    } * 60;

    return [$h, $s, $l];
}

/** WCAG contrast of white writing on a #RRGGBB. */
function hfWhiteContrast(string $hex): float
{
    $lin = array_map(static function ($h) {
        $c = hexdec($h) / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, str_split(ltrim($hex, '#'), 2));

    return 1.05 / (0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2] + 0.05);
}

it('keeps every colour the strip and the big name drift through in the pink, red, orange and grey family', function () {
    /*
     * THE OWNER SAW LILAC, BLUE AND MINT IN THE BIG NAME. 4 October, with a
     * screenshot of "K-Beauty Bliss" in #C9B8FF and #A8E6CF: "the logo changing
     * color and the support bar colors. i need only our pinkish, red and orange
     * and grey combination effect. no any other colors."
     *
     * So every stop the footer's CSS prints for the strip and for the name —
     * both name palettes, and the strip's four defaults as SiteFooter::SCHEMA
     * ships them — must be hue 330–360° or 0–35°, or a grey (saturation under
     * 0.2). And every strip stop must carry its white headline: 3:1 at least,
     * the large-text floor; the lowest is the shop's --pink at 3.63:1.
     *
     * MUTATION, RUN: put #C9B8FF back into the `.kft-name` gradient → RED.
     * MUTATION, RUN: ship `site_c_to` as #E8734A (a lighter orange) → RED on
     * the contrast floor (3.01 is under the 3.6 the shipped set holds).
     */
    $css = hfCss();
    $stops = [];

    foreach (['.kft-name{', '.kft-name-pink .kft-name{', '.kft-help{'] as $rule) {
        $at = strpos($css, $rule);
        expect($at)->not->toBeFalse("{$rule} is gone from the footer's rules");
        $body = substr($css, $at, strpos($css, '}', $at) - $at);
        preg_match_all('/#[0-9A-Fa-f]{6}\b/', $body, $m);
        expect($m[0])->not->toBeEmpty("{$rule} prints no colour stop");
        $stops = array_merge($stops, $m[0]);
    }

    $strip = array_map(static fn ($k) => SiteFooter::SCHEMA[$k]['default'], ['site_c_from', 'site_c_2', 'site_c_3', 'site_c_to']);
    $stops = array_unique(array_map('strtoupper', array_merge($stops, $strip)));

    $outside = [];

    foreach ($stops as $hex) {
        [$h, $s] = hfHsl($hex);

        if ($s >= 0.2 && ! ($h >= 330 || $h <= 35)) {
            $outside[] = sprintf('%s (hue %d°)', $hex, round($h));
        }
    }

    expect($outside)->toBe([], 'colours outside pink / red / orange / grey: '.implode(', ', $outside));

    $lowest = min(array_map('hfWhiteContrast', $strip));
    expect(round($lowest, 2))->toBeGreaterThanOrEqual(3.6)
        ->and(round(hfWhiteContrast('#E0567B'), 2))->toBe(3.63);

    // The select offers nothing outside the family either.
    expect(array_keys(SiteFooter::SCHEMA['site_name_tone']['options']))->toBe(['warm', 'pink']);
});

it('sweeps a shine across the bottom bar, left to right in English and right to left in Arabic, with CSS alone', function () {
    $css = hfCss();

    // (Lane FT) It ships OFF now — the owner, 4 October: "remove the effect
    // from the very last row of the footer" — so the bar is switched on here.
    hfSave(['site_sheen' => 'bar']);

    expect(hfClasses(hfFooter($this)))->toContain('kft-sheen-bar')
        ->and($css)->toContain('@keyframes kft-sheen{0%{transform:translateX(-100%)}60%,100%{transform:translateX(100%)}}')
        ->and($css)->toContain('@keyframes kft-sheen-rtl{0%{transform:translateX(100%)}60%,100%{transform:translateX(-100%)}}')
        ->and($css)->toContain('[dir="rtl"] .kft-sheen-bar .kft-bot::before,[dir="rtl"] .kft-sheen-bar .kft-bot::after{animation-name:kft-sheen-rtl}')
        ->and($css)->toContain('[dir="rtl"] .kft-sheen-name .kft-name::after{animation-name:kft-sheen-text-rtl}')
        ->and(hfMedia($css, 'prefers-reduced-motion:reduce', '.kft-motion .kft-help'))
        ->toContain('.kft-sheen-bar .kft-bot::before,.kft-sheen-bar .kft-bot::after,.kft-sheen-name .kft-name::after{animation:none;display:none}');

    // No script anywhere is involved: nothing in resources/js names the footer,
    // except wa-away.js, which only watches the help strip to move the floating
    // WhatsApp button aside (9 October) and names nothing of the sheen.
    foreach (glob(resource_path('js/**/*.js')) ?: [] as $file) {
        if (basename($file) === 'wa-away.js') {
            expect((string) file_get_contents($file))->not->toContain('sheen');
            continue;
        }
        expect((string) file_get_contents($file))->not->toContain('kft-');
    }

    // On the big name instead: the copy that carries the glint is the name itself, escaped.
    hfSave(['site_sheen' => 'name', 'site_name_text' => 'K"Bliss & Co']);
    $footer = hfFooter($this);
    expect(hfClasses($footer))->toContain('kft-sheen-name')->not->toContain('kft-sheen-bar')
        // ▲ 2.60.466 (Lane AN): with the drift on, the drift's own empty copy
        // (.kft-nm, escaped the same way, in its own data-kft-copy so that
        // data-kft-text still means the shine) comes first inside the name.
        ->and($footer)->toContain('data-kft-text="K&quot;Bliss &amp; Co"><span class="kft-nm" data-kft-copy="K&quot;Bliss &amp; Co"></span>K&quot;Bliss &amp; Co</p>');

    hfSave(['site_sheen' => 'off']);
    $footer = hfFooter($this);
    expect(implode(' ', hfClasses($footer)))->not->toContain('kft-sheen')
        ->and($footer)->not->toContain('data-kft-text');

    // A value that is not one of the select's own options is the shipped one,
    // which is Off since Lane FT.
    hfSave(['site_sheen' => 'everywhere']);
    expect(implode(' ', hfClasses(hfFooter($this))))->not->toContain('kft-sheen');
});

it('keeps only safe addresses in the owner\'s own link lists, Returns included now that he asked for it', function () {
    $clean = SiteFooter::cleanLinks(implode("\n", [
        'Gift cards | /gift-cards/',
        'Our Instagram | https://www.instagram.com/kbeauty.bliss/',
        'Bad | javascript:alert(1)',
        'Plain web | http://example.com/',
        'Another host | //evil.example/',
        'Backslash | /\\evil.example',
        'Quote | /a"onmouseover=x',
        'Returns | /refund_returns/',
        'Exchange | /returns/',
        'No address',
        ' | /nameless/',
        '<b>Bold</b> label | /bold/',
    ]));

    // (Lane TP) Returns and Exchange used to be dropped here too; the owner
    // asked for Returns Information back on 6 October, so they are kept.
    expect($clean)->toBe("Gift cards | /gift-cards/\nOur Instagram | https://www.instagram.com/kbeauty.bliss/\nReturns | /refund_returns/\nExchange | /returns/\nBold label | /bold/");

    // Twelve at most, and an emptied box is the shipped list, never a refusal.
    expect(count(SiteFooter::parseLinks(str_repeat("A | /a/\n", 30))))->toBe(SiteFooter::MAX_LINKS)
        ->and(SiteFooter::cleanLinks(null))->toBe('')
        ->and(SiteFooter::cleanLinks(['x']))->toBe('');

    hfSave(['site_col1_title' => 'Shop <i>now</i>', 'site_col1_links' => "Gift cards | /gift-cards/\nBad | javascript:alert(1)"]);
    $footer = hfFooter($this);
    preg_match('#<nav class="kft-col kft-col1"[^>]*>.*?</nav>#s', $footer, $shop);

    expect($shop[0])->toContain('class="kft-ch">Shop now</h2>')
        ->toContain('<li><a href="/gift-cards/">Gift cards</a></li>')
        ->not->toContain('javascript:')
        ->not->toContain('New in')
        // The other two columns are untouched by the first one's list.
        ->and($footer)->toContain('<a href="/my-account/orders/">My orders</a>');
});

it('prints nothing into the footer\'s attributes but checked colours, clamped numbers and its own class names', function () {
    hfSave([
        'site_c_from' => '#fff;}body{display:none',
        'site_c_to' => '#abc',
        'site_d_pt' => 9999,
        'site_m_gap' => -40,
        'site_d_fs_name' => '50; color:red',
        'site_drift_speed' => '1',
        'site_d_brand_align' => 'right',
        'site_d_col3' => false,
    ]);

    $footer = hfFooter($this);
    preg_match('#^<footer class="([^"]*)" style="([^"]*)">#', $footer, $m);

    expect($m[2] ?? '')->toMatch('/^(--kft-[a-z0-9-]+:(#[0-9A-F]{6}|\d+(px|s)?))(;--kft-[a-z0-9-]+:(#[0-9A-F]{6}|\d+(px|s)?))*$/')
        ->toContain('--kft-from:#E0567B;')      // refused → the default
        ->toContain('--kft-to:#AABBCC;')        // a short hex, made whole
        ->toContain('--kft-pt-d:80px;')         // clamped to the slider's top
        ->toContain('--kft-gap-m:8px;')         // and to its bottom
        ->toContain('--kft-dr:14s;--kft-drn:12s')
        ->and($m[1] ?? '')->toMatch('/^kft( kft-[a-z0-9-]+)*$/')
        ->toContain('kft-xd-col3')
        ->not->toContain('kft-bc-d');

    // Hidden on the laptop by a rule that only a laptop reads.
    expect(hfMedia(hfCss(), 'min-width:901px', '.kft-xd-col3 .kft-col3'))->not->toBe('');
});

it('draws every new control on Appearance → Footer and saves it through the same endpoint and capability', function () {
    $owner = AdminUser::create(['name' => 'O', 'email' => 'hf-'.Str::random(6).'@example.com', 'password' => bcrypt('x'), 'role' => 'owner']);

    $body = $this->actingAs($owner, 'admin')->getJson('/admin-api/slim-footer')->assertOk()->json();
    $site = array_values(array_filter($body['tabs'], static fn ($t) => str_starts_with((string) $t['key'], 'site')));

    expect(array_column($site, 'label'))->toBe([
        'Site footer · design', 'Site footer · help strip', 'Site footer · Visit us & name',
        'Site footer · link columns', 'Site footer · colours & effects',
        'Site footer · layout desktop', 'Site footer · layout mobile',
        // (Lane FB) The app row's six keys travel in a tab of their own, last,
        // so the seven above keep their order; the screen draws them as the
        // "App row" section of both device pages (FooterPages::SITE).
        'Site footer · app row',
    ]);

    $drawn = array_merge(...array_map(static fn ($t) => array_column($t['fields'], 'key'), $site));
    sort($drawn);
    $stored = array_keys(SiteFooter::SCHEMA);
    sort($stored);
    expect($drawn)->toBe($stored);

    // Every part has a switch on both layout tabs.
    foreach (SiteFooter::PARTS as $part) {
        expect(SiteFooter::SCHEMA)->toHaveKey("site_d_{$part}")->toHaveKey("site_m_{$part}");
    }

    // The screen draws the two control types it did not have before.
    $screen = (string) file_get_contents(resource_path('views/admin/partials/slim-footer-screen.blade.php'));
    expect($screen)->toContain("if (f.type === 'colour') {")->toContain('<input type="color" class="sfs-colour"')
        ->toContain("if (f.type === 'textarea') {")->toContain('<textarea id="');

    $this->actingAs($owner, 'admin')->postJson('/admin-api/slim-footer', ['settings' => [
        'site_c_accent' => '#a82f53', 'site_m_logo' => true, 'site_col3_title' => 'Your account',
    ]])->assertOk();
    SettingsService::forgetMemo();

    $c = app(SiteFooter::class)->all();
    expect($c['site_c_accent'])->toBe('#A82F53')->and($c['site_m_logo'])->toBeTrue()
        ->and(hfFooter($this))->toContain('class="kft-ch">Your account</h2>');

    // Each field normalises, so the shared guard can hold it.
    expect(count(ModuleSchema::normalise(SiteFooter::SCHEMA, SiteFooter::POLICY)))->toBe(count(SiteFooter::SCHEMA));
});

it('draws the Arabic footer with the same controls, its titles through the translator', function () {
    app(SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, '1');
    SettingsService::forgetMemo();

    $footer = hfFooter($this, '/ar/');

    expect($footer)->not->toBe('')
        ->and(hfClasses($footer))->toContain('kft-motion')->toContain('kft-xm-logo')
        // Drafts are not served until he approves them; the keys resolve.
        ->and($footer)->toContain('class="kft-ch">'.__('store.footer.account_title').'</h2>');
});
