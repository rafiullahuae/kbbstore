<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\HomepageLayouts;
use App\Services\HomepageSections;
use App\Services\InstagramEmbeds;
use App\Services\Security\ContentSecurityPolicy;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\Shortcodes;
use Illuminate\Support\Facades\DB;
use Tests\Support\InstagramEmbedsRoutes;

/**
 * Content → Instagram embeds: paste a post or reel address, the shop draws
 * Instagram's own embed for it. No API, no login.                  (Lane IGE)
 *
 * THE OWNER: "build me a system where i can place the instagram post or video
 * url, and on front-end it will create automatically. as embed functinoality,
 * without any api or login etc."
 *
 * routes/ig-embeds-admin.php and the screen partial are wired by the
 * INTEGRATOR (tools/ige-wire.php), so InstagramEmbedsRoutes mounts the real
 * route file here. The wiring pins below assert the FINISHED state (each
 * require and include exactly once, after the wiring is applied) and never
 * that anything is not yet wired.
 *
 * The shortcodes used below are made-up eleven-character codes in Instagram's
 * alphabet; none is a real post.
 */
function igeSeed(array $items, array $options = []): void
{
    $svc = app(InstagramEmbeds::class);
    $svc->save($options, $items);
    Setting::flushMap();
    SettingsService::forgetMemo();
}

/** Raw rows behind the screen's back, the way an import or phpMyAdmin would write them. */
function igeRaw(string $key, mixed $value): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => is_string($value) ? $value : json_encode($value), 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function igeAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'IGE '.$role,
        'email' => 'ige-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function igeItems(int $n): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = ['c' => 'Cxq'.str_pad((string) $i, 8, 'a', STR_PAD_LEFT), 'k' => $i % 3 === 2 ? 'reel' : 'p', 'l' => '', 'on' => true];
    }

    return $out;
}

/** The section's markup out of a rendered page. */
function igeBlock(string $html): string
{
    $at = strpos($html, '<div class="kie ');

    if ($at === false) {
        return '';
    }

    $end = strpos($html, '</ul>', $at);

    return substr($html, $at, $end === false ? null : $end - $at + 5);
}

/* ══════════════════════════════════════════════════════ parsing ═══ */

it('reads every address shape a phone or a browser bar hands over', function () {
    /*
     * The shapes the owner will paste. MUTATION: drop `reels` or `tv` from the
     * path pattern in InstagramEmbeds::parse(), or the username segment, or the
     * scheme-less branch, and the matching line here goes red.
     */
    $cases = [
        'https://www.instagram.com/p/DAbc123xyz_/' => ['p', 'DAbc123xyz_'],
        'https://www.instagram.com/p/DAbc123xyz_/?utm_source=ig_web_copy_link&igsh=abc' => ['p', 'DAbc123xyz_'],
        'https://instagram.com/reel/C9-Rl_ab12Q?igsh=abc#frag' => ['reel', 'C9-Rl_ab12Q'],
        'https://www.instagram.com/reels/C9-Rl_ab12Q/' => ['reel', 'C9-Rl_ab12Q'],
        'https://www.instagram.com/tv/B7tvCode123/' => ['p', 'B7tvCode123'],
        'https://www.instagram.com/kbeauty.bliss/p/DAbc123xyz_/' => ['p', 'DAbc123xyz_'],
        'https://www.instagram.com/kbeauty.bliss/reel/C9-Rl_ab12Q/' => ['reel', 'C9-Rl_ab12Q'],
        'www.instagram.com/p/DAbc123xyz_' => ['p', 'DAbc123xyz_'],
        'instagram.com/reel/C9-Rl_ab12Q/' => ['reel', 'C9-Rl_ab12Q'],
        'http://instagram.com/p/DAbc123xyz_/' => ['p', 'DAbc123xyz_'],
        'https://m.instagram.com/p/DAbc123xyz_/' => ['p', 'DAbc123xyz_'],
        'https://instagr.am/p/DAbc123xyz_/' => ['p', 'DAbc123xyz_'],
        'https://www.instagram.com/p/DAbc123xyz_/embed/captioned/' => ['p', 'DAbc123xyz_'],
        '  https://www.instagram.com/p/DAbc123xyz_/  ' => ['p', 'DAbc123xyz_'],
    ];

    foreach ($cases as $url => [$kind, $code]) {
        expect(InstagramEmbeds::parse($url))->toBe(['kind' => $kind, 'code' => $code], $url);
    }
});

it('refuses junk, other hosts and every non-web scheme, and says why', function () {
    /*
     * MUTATION: let the scheme check accept anything (drop the in_array) and the
     * javascript:/data: lines go red; compare the host with str_contains and the
     * two look-alike hosts go red; drop the userinfo check and the @ line does.
     */
    $refused = [
        'javascript:alert(1)//www.instagram.com/p/DAbc123xyz_/',
        'JavaScript:alert(document.domain)',
        'data:text/html,<script>alert(1)</script>',
        'data:text/html;base64,AAAA',
        'vbscript:msgbox',
        'ftp://www.instagram.com/p/DAbc123xyz_/',
        'https://instagram.com.evil.test/p/DAbc123xyz_/',
        'https://evil.test/www.instagram.com/p/DAbc123xyz_/',
        'https://www.instagram.com@evil.test/p/DAbc123xyz_/',
        'https://user:pw@www.instagram.com/p/DAbc123xyz_/',
        'https://www.instagram.com:8443/p/DAbc123xyz_/',
        'https://www.instagram.com/share/reel/BAabcdef12/',
        'https://www.instagram.com/kbeauty.bliss/',
        'https://www.instagram.com/explore/tags/kbeauty/',
        'https://www.instagram.com/p/ab/',
        'https://www.instagram.com/p/DAbc"onload=x/',
        'https://www.instagram.com/p/DAbc<b>123/',
        'https://www.instagram.com/p/'.str_repeat('A', 41).'/',
        "https://www.instagram.com/p/DAbc123xyz_/\nhttps://evil.test/",
        'not a url',
        '',
    ];

    foreach ($refused as $url) {
        $r = InstagramEmbeds::parse($url);
        expect(isset($r['error']) && ! isset($r['code']))->toBeTrue('accepted: '.json_encode($url));
    }

    expect(InstagramEmbeds::parse('https://www.instagram.com/share/p/BAabcdef12/')['error'])->toContain('share link')
        ->and(InstagramEmbeds::parse('https://www.instagram.com/kbeauty.bliss/')['error'])->toContain('profile');
});

it('reads a paste of many, drops duplicates and lists what it refused', function () {
    $r = InstagramEmbeds::parseMany("https://www.instagram.com/p/DAbc123xyz_/?igsh=1\n"
        ."https://www.instagram.com/p/DAbc123xyz_/ , https://www.instagram.com/reel/C9-Rl_ab12Q/\n"
        ."javascript:alert(1)\n\nhttps://example.test/p/DAbc123xyz_/");

    expect($r['items'])->toBe([
        ['c' => 'DAbc123xyz_', 'k' => 'p', 'l' => '', 'on' => true],
        ['c' => 'C9-Rl_ab12Q', 'k' => 'reel', 'l' => '', 'on' => true],
    ])->and(array_column($r['refused'], 'line'))->toBe(['javascript:alert(1)', 'https://example.test/p/DAbc123xyz_/']);
});

/* ═══════════════════════════════════════════ what reaches the page ═══ */

it('builds the iframe src only from a valid shortcode and a constant host', function () {
    expect(InstagramEmbeds::src('p', 'DAbc123xyz_', false))->toBe('https://www.instagram.com/p/DAbc123xyz_/embed/')
        ->and(InstagramEmbeds::src('reel', 'C9-Rl_ab12Q', true))->toBe('https://www.instagram.com/reel/C9-Rl_ab12Q/embed/captioned/')
        ->and(InstagramEmbeds::src('p', 'javascript:alert(1)', false))->toBeNull()
        ->and(InstagramEmbeds::src('p', 'DAbc"onload', false))->toBeNull()
        ->and(InstagramEmbeds::src('tv', 'DAbc123xyz_', false))->toBeNull()
        ->and(InstagramEmbeds::src('../evil', 'DAbc123xyz_', false))->toBeNull();

    /*
     * A row written behind the screen's back — a URL where the code goes, a
     * foreign kind, markup in the label — never reaches the page as anything but
     * the escaped label. MUTATION: make clean() keep a row without calling
     * src(), and the evil host appears in the section's markup.
     */
    igeRaw(InstagramEmbeds::ITEMS_KEY, [
        ['c' => 'https://evil.test/x', 'k' => 'p', 'l' => 'a', 'on' => true],
        ['c' => 'DAbc123xyz_', 'k' => 'javascript', 'l' => 'b', 'on' => true],
        ['c' => 'DAbc123xyz_', 'k' => 'p', 'l' => '<img src=x onerror=alert(1)>Hi', 'on' => true],
    ]);

    $html = Shortcodes::render('[kbb_instagram_embeds]');

    expect($html)->not->toContain('evil.test')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('javascript')
        ->and(substr_count($html, '<iframe'))->toBe(1);

    preg_match_all('/<iframe[^>]*\ssrc="([^"]*)"/', $html, $m);
    foreach ($m[1] as $src) {
        expect($src)->toMatch('#^https://www\.instagram\.com/(p|reel)/[A-Za-z0-9_-]{5,40}/embed/(captioned/)?$#');
    }
});

it('draws a facade with no Instagram request in the page but the lazy frame', function () {
    /*
     * The page's own HTML may name instagram.com in exactly two places: the
     * iframe src, which carries loading="lazy" so the browser defers it, and the
     * "View on Instagram" link, which is a navigation, not a request. No script,
     * no stylesheet, no picture, no preconnect.
     *
     * MUTATION: drop loading="lazy" from the near-mode iframe in
     * igembed/section.blade.php and the first assertion in the loop goes red;
     * swap the facade for Instagram's blockquote + embed.js and the script
     * assertion does.
     */
    igeSeed(igeItems(3));

    $html = $this->get('/')->assertOk()->getContent();
    $block = igeBlock($html);

    expect($block)->not->toBe('')
        ->and(substr_count($block, '<iframe'))->toBe(3)
        ->and($html)->not->toContain('instagram.com/embed.js')
        ->and($html)->not->toMatch('#<(script|link|img)[^>]+instagram\.com#i')
        ->and($html)->not->toContain('cdninstagram')
        ->and(substr_count($block, 'class="kie-fac"'))->toBe(3);

    preg_match_all('#<[^>]*instagram\.com[^>]*>#', $html, $tags);
    foreach ($tags[0] as $tag) {
        // A link is a navigation, not a request (the footer's profile link is one).
        $ok = (str_starts_with($tag, '<iframe ') && str_contains($tag, ' loading="lazy"'))
            || str_starts_with($tag, '<a ');
        expect($ok)->toBeTrue('an Instagram reference outside the lazy frame and the link: '.$tag);
    }

    // The frame's attributes, exactly as the brief names them.
    preg_match('#<iframe[^>]*>#', $block, $one);
    expect($one[0])->toContain('sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox"')
        ->and($one[0])->toContain('referrerpolicy="strict-origin-when-cross-origin"')
        ->and($one[0])->toContain('title="Instagram post"')
        ->and($one[0])->toContain('loading="lazy"');
});

it('puts the frame inside a closed details on tap, so nothing loads until a tap', function () {
    /*
     * Measured in Chromium (storage/ige-logs/probe.py): a lazy iframe inside a
     * closed <details> is not fetched; opening it fetches it. MUTATION: render
     * the tap mode's frame outside the details and the count of iframes inside
     * a closed details drops to 0.
     */
    igeSeed(igeItems(2), ['load' => 'tap']);

    $block = igeBlock(Shortcodes::render('[kbb_instagram_embeds]'));

    expect(preg_match_all('#<details class="kie-box kie-tap"><summary class="kie-fac">.*?</summary><iframe [^>]*loading="lazy"[^>]*></iframe></details>#', $block))->toBe(2)
        ->and($block)->not->toContain('<details open')
        ->and($block)->toContain('Show the post');
});

it('reserves each frame\'s height in CSS, so the post arriving moves nothing', function () {
    /*
     * CLS 0 without measuring: the box's height is a calc() on the card's own
     * width (container query units), per type. MUTATION: delete the
     * container-type declaration or the height calc and this goes red.
     */
    $css = InstagramEmbeds::css();

    expect($css)->toContain('.kie-in{container-type:inline-size')
        ->and($css)->toContain('height:calc(100cqw * var(--kie-r) + var(--kie-c) + var(--kie-fit,0px))')
        ->and($css)->toContain('.kie-k-reel{--kie-r:1.7778}')
        ->and($css)->toContain('.kie-if{position:absolute;inset:0;width:100%;height:100%');

    // Rule 4: nothing here may measure layout.
    $section = (string) file_get_contents(resource_path('views/igembed/section.blade.php'));
    expect($section)->not->toContain('<script');
});

it('prints classes and the one custom property from option keys only', function () {
    igeSeed(igeItems(1));
    igeRaw('igembed_style', 'clean kie-x" onmouseover="alert(1)');
    igeRaw('igembed_fit', '0px;background:url(//evil.test)');
    igeRaw('igembed_layout', '</style><script>');

    $html = Shortcodes::render('[kbb_instagram_embeds]');

    expect($html)->toContain('<div class="kie kie-s-clean kie-l-grid kie-cd-3 kie-cm-1" style="--kie-fit:0px">')
        ->and($html)->not->toContain('evil.test')
        ->and($html)->not->toContain('onmouseover');
});

it('allows www.instagram.com as a frame source, and only that Instagram host', function () {
    $frames = ContentSecurityPolicy::DIRECTIVES['frame-src'];

    expect($frames)->toContain('https://www.instagram.com');

    foreach ($frames as $src) {
        expect($src)->not->toContain('*')
            ->and(str_contains($src, 'instagram') ? $src : 'https://www.instagram.com')->toBe('https://www.instagram.com');
    }

    $header = app(ContentSecurityPolicy::class)->header();
    expect($header)->toMatch('#frame-src [^;]*https://www\.instagram\.com#');
});

/* ═════════════════════════════════════════════════ the shortcode ═══ */

it('renders the shortcode, takes only its own option keys, and is empty with nothing on', function () {
    expect(Shortcodes::render('<p>x</p>[kbb_instagram_embeds]'))->toBe('<p>x</p>');

    igeSeed(igeItems(5));

    $html = Shortcodes::render('[kbb_instagram_embeds layout="slider" style="ring" max="3" cols_d="evil" title="Our reels"]');

    expect($html)->toContain('kie-s-ring kie-l-slider kie-cd-3')
        ->and(substr_count($html, '<iframe'))->toBe(3)
        ->and($html)->toContain('<h2 class="kie-h">Our reels</h2>')
        ->and($html)->not->toContain('evil');

    // The stylesheet once per page, however many sections. MUTATION: drop the
    // container binding in Shortcodes::instagramEmbeds() and this is 2.
    $two = Shortcodes::render('[kbb_instagram_embeds][kbb_instagram_embeds]');
    expect(substr_count($two, '<style>'))->toBe(0);

    app()->forgetInstance('kbb.igembed.assets');
    $two = Shortcodes::render('[kbb_instagram_embeds][kbb_instagram_embeds]');
    expect(substr_count($two, '<style>'))->toBe(1);

    // The API module's [kbb_instagram] arm does not claim this tag (`\b` does
    // not match before an underscore), and switched off the section is gone.
    expect(Shortcodes::render('[kbb_instagram_embeds]'))->not->toContain('igp');
    igeSeed([], ['on' => false]);
    expect(Shortcodes::render('[kbb_instagram_embeds]'))->toBe('');
});

it('costs no query and the same work at three posts as at forty', function () {
    /*
     * The list and the options are settings, read from the map the request has
     * already loaded. MUTATION: read the list with Setting::query() and the
     * count is no longer 0.
     */
    $counts = [];

    foreach ([3, 40] as $n) {
        igeSeed(igeItems($n), ['max' => '24']);
        // What every shop page has already read before this section: the
        // settings map and the interface-strings map (any __() loads it).
        app(SettingsService::class)->all();
        __('store.instagram.post_alt');
        app()->forgetInstance('kbb.igembed.assets');

        DB::flushQueryLog();
        DB::enableQueryLog();
        Shortcodes::render('[kbb_instagram_embeds]');
        $counts[$n] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }

    expect($counts)->toBe([3 => 0, 40 => 0]);
});

/* ═════════════════════════════════════════════════ the homepage row ═══ */

it('sits directly after the Instagram Profile row, in the registry, every preset and the page', function () {
    $keys = array_keys(HomepageSections::REGISTRY);
    expect($keys[array_search('instagram', $keys, true) + 1])->toBe('igembeds');

    foreach (HomepageLayouts::LAYOUTS as $name => $layout) {
        $s = $layout['sections'];
        expect($s[array_search('instagram', $s, true) + 1] ?? null)->toBe('igembeds', $name);
    }

    expect(HomepageSections::OFF_BY_DEFAULT)->not->toContain('igembeds');

    $tpl = (string) file_get_contents(resource_path('views/store/home.blade.php'));
    $ig = strpos($tpl, "classFor('instagram')");
    $mine = strpos($tpl, "classFor('igembeds')");
    $next = strpos($tpl, "hidden('trending')");
    expect($ig)->toBeLessThan($mine)->and($mine)->toBeLessThan($next)
        ->and(substr_count($tpl, "Shortcodes::render('[kbb_instagram_embeds]')"))->toBe(1);
});

it('draws the row on the homepage and honours its switches; an empty list adds no byte', function () {
    $empty = $this->get('/')->getContent();
    expect($empty)->not->toContain('kie')->and($empty)->not->toContain('kbb_instagram_embeds');

    igeSeed(igeItems(2));
    $html = $this->get('/')->getContent();
    expect($html)->toContain('<section class="sec')
        ->and(igeBlock($html))->not->toBe('');

    // Off on both devices on Appearance → Homepage: gone.
    $all = app(HomepageSections::class)->all();
    $payload = [];
    foreach ($all as $key => $row) {
        $payload[$key] = ['desktop' => $key === 'igembeds' ? false : $row['desktop'], 'mobile' => $key === 'igembeds' ? false : $row['mobile'], 'skin' => $row['skin'] ?? null, 'order' => $row['order']];
    }
    app(HomepageSections::class)->save($payload);
    SettingsService::forgetMemo();

    expect(igeBlock($this->get('/')->getContent()))->toBe('');
});

/* ═════════════════════════════════════════════════════ the endpoint ═══ */

it('maps every endpoint to its own capability, held by the Content roles', function () {
    foreach ([['GET', 'admin-api/ig-embeds'], ['POST', 'admin-api/ig-embeds'], ['POST', 'admin-api/ig-embeds/parse']] as [$verb, $path]) {
        expect(AdminCapabilities::forPath($verb, $path))->toBe('igembeds.manage', $verb.' '.$path);
    }

    expect(AdminCapabilities::CAPABILITIES['igembeds.manage'])->toBe(['owner', 'manager', 'editor']);
});

it('refuses an account without the capability and a visitor, and writes nothing', function () {
    InstagramEmbedsRoutes::wire($this->app);

    $this->getJson('/admin-api/ig-embeds')->assertStatus(401);

    $this->actingAs(igeAdmin('support'), 'admin');
    $this->getJson('/admin-api/ig-embeds')->assertStatus(403);
    $this->postJson('/admin-api/ig-embeds/parse', ['text' => 'https://www.instagram.com/p/DAbc123xyz_/'])->assertStatus(403);
    $this->postJson('/admin-api/ig-embeds', ['items' => igeItems(1)])->assertStatus(403);

    SettingsService::forgetMemo();
    expect(app(InstagramEmbeds::class)->items())->toBe([]);
});

it('lets an editor paste, save and read back only checked rows', function () {
    InstagramEmbedsRoutes::wire($this->app);
    $this->actingAs(igeAdmin('editor'), 'admin');

    $parsed = $this->postJson('/admin-api/ig-embeds/parse', ['text' => "https://www.instagram.com/reel/C9-Rl_ab12Q/?igsh=x\njavascript:alert(1)"])
        ->assertOk()->json();
    expect($parsed['items'])->toHaveCount(1)->and($parsed['refused'])->toHaveCount(1);

    $saved = $this->postJson('/admin-api/ig-embeds', [
        'options' => ['style' => 'polaroid', 'layout' => 'evil', 'heading' => 'Our feed'],
        'items' => [
            ['c' => 'C9-Rl_ab12Q', 'k' => 'reel', 'l' => '  Glass   skin <b>routine</b> ', 'on' => true],
            ['c' => '<script>', 'k' => 'p', 'l' => '', 'on' => true],
        ],
    ])->assertOk()->json();

    expect($saved['items'])->toBe([['c' => 'C9-Rl_ab12Q', 'k' => 'reel', 'l' => 'Glass skin routine', 'on' => true]]);

    $values = array_column($saved['fields'], 'value', 'key');
    expect($values['style'])->toBe('polaroid')->and($values['layout'])->toBe('grid')->and($values['heading'])->toBe('Our feed');

    // Nothing stored is a URL or markup: the list setting holds codes and kinds.
    $raw = (string) Setting::query()->where('key', InstagramEmbeds::ITEMS_KEY)->value('value');
    expect($raw)->not->toContain('http')->and($raw)->not->toContain('<');
});

/* ═════════════════════════════════════════════════════ the wiring ═══ */

it('wires each route file and the screen exactly once when the integrator applies it', function () {
    $edits = json_decode((string) file_get_contents(base_path('docs/ige-wiring.json')), true);
    $files = [];

    foreach ($edits as $e) {
        $files[$e['file']] ??= (string) file_get_contents(base_path($e['file']));
        if (! str_contains($files[$e['file']], $e['replacement'])) {
            expect(substr_count($files[$e['file']], $e['anchor']))->toBe($e['count'], 'anchor for block '.$e['n']);
            $files[$e['file']] = str_replace($e['anchor'], $e['replacement'], $files[$e['file']]);
        }
    }

    expect(substr_count($files['routes/web.php'], "require __DIR__.'/ig-embeds-admin.php';"))->toBe(1)
        ->and(substr_count($files['resources/views/admin/app.blade.php'], "@include('admin.partials.ig-embeds-screen')"))->toBe(1)
        ->and(substr_count($files['resources/views/admin/app.blade.php'], "'igembeds':['Content','Instagram embeds']"))->toBe(1);
});
