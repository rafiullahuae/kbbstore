<?php

declare(strict_types=1);

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Review;
use App\Services\NavigationService;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * An address this shop did not author never becomes an href, an img src or a
 * CSS declaration without its scheme being read first.
 *
 * ── WHAT THE DEFECT LOOKED LIKE ON THE SHOP ─────────────────────────────────
 *
 * A menu row whose url was `javascript:alert(1)` rendered, in the header of
 * every page:
 *
 *     <a class="navlink" href="javascript:alert(1)">Sunscreens</a>
 *
 * `{{ }}` escapes &, <, >, " and '. That string holds none of them, so it came
 * through the escaper BYTE FOR BYTE and the browser ran it on click. The same
 * template pasted `highlight_color` straight into a `style=` attribute — a CSS
 * context, where the escaper's `&#39;` is handed back to CSS as a quote by the
 * HTML parser before CSS ever reads it, so a stored colour could close the
 * declaration and write its own. And a review photograph whose address pointed
 * offsite was FETCHED by the browser before any JavaScript ran, which is a
 * tracking pixel the shop serves on its own product page.
 *
 * ── WHY IT IS WORTH A TEST RATHER THAN A SHRUG ──────────────────────────────
 *
 * None of these is visitor-supplied today, and that was checked rather than
 * assumed: ReviewController UPLOADS the photo and writes
 * /uploads/reviews/{uuid} itself, and MegaMenuApiController validates the
 * colour on save with regex:/^#[0-9a-fA-F]{6}$/. They are IMPORT-exposed. The
 * WordPress import writes menu_items and review photos straight out of a
 * database this shop did not author, which is why this landed before the import
 * rather than after it.
 *
 * ── WHERE THE GATE SITS, WHICH IS THE POINT OF THE FIRST CASE ───────────────
 *
 * EIGHT href sites and SEVEN style sites read the menu tree (nav-bar,
 * mobile-menu-item, footer). The first cut of this fix gated exactly ONE of
 * them and called the hole closed. So the gate is at NavigationService::tree(),
 * the single place all fifteen read from, and the first case asserts it there —
 * where a ninth template added tomorrow inherits it instead of having to
 * remember it.
 */
function schemeMenu(array $rows): array
{
    Menu::query()->update(['show_desktop' => false, 'show_mobile' => false, 'show_footer' => false]);

    $menu = Menu::create([
        'name' => 'scheme probe',
        'slug' => 'scheme-probe-'.Str::random(6),
        'show_desktop' => true,
        'show_mobile' => true,
    ]);

    foreach (array_values($rows) as $i => $row) {
        MenuItem::create(['menu_id' => $menu->id, 'position' => $i] + $row);
    }

    // menu() caches for five minutes and this helper is called more than once
    // per run. Without the flush the second fixture reads the first one's tree.
    app(NavigationService::class)->flush();
    Cache::flush();

    return app(NavigationService::class)->menu('primary');
}

it('refuses an executable or offsite scheme on a menu row, at the tree', function () {
    /*
     * MUTATION NOTE. Put `'url' => $item->url` back in NavigationService::tree()
     * and every REFUSED row below reads back its own attack string — which is
     * then exactly what lands in the href, because Url::to() passes an absolute
     * address through by name. RUN.
     *
     * The obfuscated pair is not decoration. A browser resolves
     * `jav&#x09;ascript:alert(1)` and `java\nscript:alert(1)` to a javascript
     * URL; a check that reads the raw string sees something starting "jav&" or
     * "java" and waves both through. That is why SafeUrl decodes entities and
     * strips controls BEFORE it matches the scheme, and why they are pinned
     * here rather than trusted to the implementation.
     */
    $refused = [
        'javascript:alert(1)',
        'JaVaScRiPt:alert(1)',
        "jav&#x09;ascript:alert(1)",
        "java\nscript:alert(1)",
        'vbscript:msgbox(1)',
        'data:text/html,<script>alert(1)</script>',
        '//evil.test/looks-relative',
    ];

    $tree = schemeMenu(array_map(
        static fn (string $u, int $i) => ['label' => 'row'.$i, 'url' => $u],
        $refused,
        array_keys($refused)
    ));

    expect($tree)->toHaveCount(count($refused));

    foreach ($tree as $i => $item) {
        expect($item['url'])->toBe('/', sprintf(
            'NavigationService::tree() must refuse %s — it reached the header as %s, and '
            .'Url::to() passes an absolute address through by name.',
            var_export($refused[$i], true),
            var_export($item['url'], true)
        ));
    }
});

it('leaves every address a real menu carries byte for byte', function () {
    /*
     * The other half of the claim, and the one that makes the fix shippable at
     * all: applying the package must not move a single link on a shop whose
     * menu is ordinary. A path, a query, a fragment and an http(s) address all
     * come back unchanged; mailto: and tel: are allowed because a menu row
     * legitimately holds one and this shop renders such rows today.
     *
     * MUTATION NOTE. Drop 'mailto' from SafeUrl::LINK_SCHEMES and the contact
     * row below becomes '/' — a support link silently rewritten to the home
     * page, which is the shape of regression this case exists to catch. RUN.
     */
    $kept = [
        '/',
        '/shop/',
        '/shop/?orderby=date',
        '/product-category/sunscreens/',
        '#top',
        'https://extrabeauty.ae/pages/about',
        'http://extrabeauty.ae/legacy',
        'mailto:hello@extrabeauty.ae',
        'tel:+97141234567',
    ];

    $tree = schemeMenu(array_map(
        static fn (string $u, int $i) => ['label' => 'row'.$i, 'url' => $u],
        $kept,
        array_keys($kept)
    ));

    foreach ($tree as $i => $item) {
        expect($item['url'])->toBe($kept[$i], sprintf(
            'SafeUrl::href() moved %s to %s. Every address an ordinary menu carries must render '
            .'byte for byte as it did before the gate existed.',
            var_export($kept[$i], true),
            var_export($item['url'], true)
        ));
    }
});

it('refuses a highlight colour that is not a colour', function () {
    /*
     * `style="background:{{ $c }};color:#fff;…"`. The escaper turns `'` into
     * `&#39;` and the HTML parser hands the quote back BEFORE the CSS parser
     * reads the attribute, so a stored value can close the declaration and open
     * its own — one rule of somebody else's choosing on that element, which in
     * practice is a background:url() firing a request from the shopper's
     * browser.
     *
     * MUTATION NOTE. Drop the Color::isValidHex() call in
     * NavigationService::tree() and `red;background:url(https://evil.test/p)`
     * reads back verbatim and is drawn. RUN.
     */
    /*
     * EVERY PAYLOAD HERE IS NINE CHARACTERS OR FEWER, AND THAT IS NOT AN
     * ARBITRARY CHOICE. `menu_items.highlight_color` is varchar(9): MySQL
     * answers SQLSTATE[22001] "Data too long" for anything longer while SQLite
     * stores it whole, so a longer payload would be a case that can only fail
     * on one of the two engines — which is exactly what ColumnWidths::
     * violations() exists to stop, and it caught the first cut of this case.
     *
     * Nine characters is plenty to be a real attack. `#fff;x:y` closes the
     * background declaration and opens another, and the template pastes four
     * more declarations after it.
     */
    $tree = schemeMenu([
        ['label' => 'good', 'url' => '/a/', 'highlight_color' => '#E23A4E'],
        ['label' => 'short', 'url' => '/b/', 'highlight_color' => '#fff'],
        ['label' => 'css', 'url' => '/c/', 'highlight_color' => '#fff;x:y'],
        ['label' => 'quote', 'url' => '/d/', 'highlight_color' => "#fff'"],
        ['label' => 'named', 'url' => '/e/', 'highlight_color' => 'red'],
    ]);

    expect($tree[0]['highlight_color'])->toBe('#E23A4E', 'A valid six-digit hex must survive untouched.');
    expect($tree[1]['highlight_color'])->toBe('#fff', 'A valid three-digit hex must survive untouched.');

    foreach ([2, 3, 4] as $i) {
        expect($tree[$i]['highlight_color'])->toBeNull(sprintf(
            'highlight_color %s must be refused — the templates gate on `! empty(...)`, so anything '
            .'that survives here is pasted into a style attribute.',
            var_export($tree[$i]['label'], true)
        ));
    }
});

it('draws no executable address in the rendered header', function () {
    /*
     * The gate is asserted at the tree above; this asserts the OUTCOME, on the
     * bytes a shopper's browser receives, because that is the thing the defect
     * was about. It renders the real page through the real composer rather than
     * calling the service again.
     *
     * MUTATION NOTE. Revert tree() and the home page contains
     * `href="javascript:alert(1)"`. RUN.
     */
    schemeMenu([
        ['label' => 'Sunscreens', 'url' => 'javascript:alert(1)'],
        // Nine characters, for the varchar(9) reason recorded above. It closes
        // `background:` and opens a declaration of its own, which is the whole
        // defect in the smallest string the column can hold.
        ['label' => 'Toners', 'url' => '/product-category/toners/', 'highlight_color' => "#fff;x:y"],
    ]);

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect(str_contains($html, 'javascript:'))->toBeFalse(
        'The rendered home page carries a javascript: URL. A menu row reached an href unchecked.'
    );
    expect(str_contains($html, '#fff;x:y'))->toBeFalse(
        'The rendered home page carries a refused highlight_color. It is pasted into a style '
        .'attribute, where the HTML parser hands the CSS parser back whatever the escaper encoded.'
    );
    expect(str_contains($html, 'Sunscreens'))->toBeTrue(
        'The refused row must still be DRAWN, pointing at the shop home — refusing the address is not '
        .'a licence to drop the item, which would change the header of a shop that has one odd row.'
    );
});

it('drops a review photograph whose address is not an address a picture can have', function () {
    /*
     * MUTATION NOTE. Put `array_filter($r->images)` back in
     * resources/views/partials/reviews.blade.php and the product page emits
     * `<img src="javascript:alert(1)">` and `<img src="//evil.test/x.png">`.
     * RUN.
     *
     * Filtered at the SOURCE array and not at the <img>: the count drives the
     * photo badge, the one/multi class and the popup payload, so gating at the
     * tag would draw "4 photos" over two of them.
     *
     * ── WHAT THIS DOES *NOT* CLAIM, WHICH MATTERS MORE THAN WHAT IT DOES ────
     *
     * An https:// photograph on somebody else's host is ALLOWED, and the
     * legacy row below pins that it stays allowed. It has to be: the WordPress
     * import carries thousands of review photos that still live on the old
     * host, and a gate that dropped them would empty the review section of the
     * shop on the day of the import. So this is a SCHEME gate and not an origin
     * gate, and the offsite-fetch half of the original report is answered by
     * the media migration, not by this.
     *
     * What it does answer is the two shapes that are never a photograph:
     * anything executable, and `//host/x.png`, which carries no scheme at all
     * and so is the one form the gate has nothing to read — it reads like a
     * path in a database row and is not one.
     */
    $product = Product::create([
        'slug' => 'scheme-probe-toner', 'name' => 'Scheme Probe Toner', 'status' => 'publish',
        'is_visible' => true, 'price' => 100, 'stock_status' => 'instock',
    ]);

    Review::create([
        'product_id' => $product->id,
        'author_name' => 'Imported Shopper',
        'rating' => 5,
        'content' => 'Imported review body.',
        'status' => 'approved',
        'images' => [
            '/uploads/reviews/00000000-0000-4000-8000-000000000001.jpg',
            'https://legacy-host.test/wp-content/uploads/review.jpg',
            'javascript:alert(1)',
            '//evil.test/protocol-relative.png',
        ],
    ]);

    $html = (string) $this->get('/product/'.$product->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, '/uploads/reviews/00000000-0000-4000-8000-000000000001.jpg'))->toBeTrue(
        'The photograph this shop serves itself must still be drawn.'
    );
    expect(str_contains($html, 'https://legacy-host.test/wp-content/uploads/review.jpg'))->toBeTrue(
        'An imported photograph still hosted on the old site must still be drawn — this is a scheme '
        .'gate, not an origin gate, and an origin gate would empty the review section on import day.'
    );

    foreach (['javascript:', 'evil.test'] as $needle) {
        expect(str_contains($html, $needle))->toBeFalse(sprintf(
            'The product page carries %s from a review photo address.',
            $needle
        ));
    }
});

it('drops a footer social icon whose address is not followable', function () {
    /*
     * The weakest of the three cases — these come from Appearance and an
     * operator types them — and still worth the call: the field takes free text
     * and the value goes straight into an href.
     *
     * '' and NOT '#' is the refusal, and that is the whole reason this case
     * exists. The surrounding array_filter is already the "the owner cleared
     * this" rule, so '' drops the icon. '#' would pass that filter and draw an
     * Instagram badge linking to the page it sits on, which looks like a
     * working control and is worse than no icon.
     *
     * MUTATION NOTE. Change either SafeUrl::href($u, '') call in
     * footer.blade.php to href($u) and an <a href="#"> is drawn where the icon
     * should be gone. RUN.
     */
    $settings = app(\App\Services\SettingsService::class);
    $settings->set('social_instagram', 'javascript:alert(1)');
    $settings->set('social_tiktok', 'https://www.tiktok.com/@kbeauty.bliss');
    $settings->set('social_facebook', '');
    Cache::flush();

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect(preg_match('/aria-label="Instagram"/', $html))->toBe(
        0,
        'A refused social address must drop the icon entirely, not draw one pointing at "#".'
    );
    expect(str_contains($html, '<a href="https://www.tiktok.com/@kbeauty.bliss" aria-label="TikTok"'))->toBeTrue(
        'A real social address must render byte for byte as it did before the gate existed.'
    );
    expect(preg_match('/aria-label="Facebook"/', $html))->toBe(
        0,
        'A cleared address must still drop its icon — the gate must not resurrect one the owner '
        .'deliberately blanked.'
    );
});

it('publishes no executable address as one of the shop\'s own social profiles', function () {
    /*
     * A FOURTH DOOR, AND IT WAS FOUND BY A SCREENSHOT, NOT BY READING.
     *
     * With the menu gated and the footer icon gated, the rendered home page
     * still carried, in the Organization JSON-LD:
     *
     *     "sameAs":["https://…facebook…","javascript:alert(4)","https://…tiktok…"]
     *
     * Not clickable, and still the shop telling Google that one of its own
     * social profiles is an executable URL — from the same free-text
     * Appearance field the footer icon reads, reaching the page through a door
     * nobody had counted. It is the reason the scan for this fix is a picture
     * of the whole page and not a list of templates.
     *
     * SafeUrl::web() and not href(): a sameAs entry is a PAGE about this
     * business, so mailto: and tel: are as wrong there as javascript:.
     *
     * MUTATION NOTE. Drop the array_map in Seo.php's sameAs block and the home
     * page publishes the javascript: URL again. RUN.
     */
    $settings = app(\App\Services\SettingsService::class);
    $settings->set('social_facebook', 'https://www.facebook.com/kbeautyblissuae');
    $settings->set('social_instagram', 'javascript:alert(4)');
    $settings->set('social_tiktok', 'https://www.tiktok.com/@kbeauty.bliss');
    $settings->set('social_pinterest', 'mailto:hello@extrabeauty.ae');
    Cache::flush();

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect(preg_match('/"sameAs":\[(.*?)\]/', $html, $m))->toBe(
        1,
        'The Organization JSON-LD published no sameAs at all, so this case is asserting nothing.'
    );

    expect(str_contains($m[1], 'javascript'))->toBeFalse(
        'The shop publishes an executable URL as one of its own social profiles: '.$m[1]
    );
    expect(str_contains($m[1], 'mailto'))->toBeFalse(
        'A sameAs entry is a page about this business. mailto: is not one, and Google reads the list '
        .'as profile pages.'
    );
    expect(str_contains($m[1], 'facebook.com'))->toBeTrue(
        'A real social profile must still be published — the gate must not empty sameAs, which would '
        .'cost the shop its Knowledge Panel links.'
    );
    expect(str_contains($m[1], 'tiktok.com'))->toBeTrue('A real social profile must still be published.');
});

it('answers the same way for a link and for a picture, which are different answers', function () {
    /*
     * The two refusals differ on purpose and the difference is not cosmetic:
     * `src="#"` and `src=""` BOTH make the browser fetch the current page and
     * try to decode it as an image. '#' is right for a link and wrong for a
     * picture, so src() answers '' and its callers draw nothing at all.
     *
     * MUTATION NOTE. Make src() fall back to '#' and this is red — and the
     * product page requests itself once per refused photograph. RUN.
     */
    expect(SafeUrl::href('javascript:alert(1)'))->toBe('#');
    expect(SafeUrl::href('javascript:alert(1)', '/'))->toBe('/');
    expect(SafeUrl::src('javascript:alert(1)'))->toBe('');
    expect(SafeUrl::src('https://evil.test/x.png'))->toBe('https://evil.test/x.png');
    expect(SafeUrl::src('mailto:a@b.test'))->toBe(
        '',
        'mailto is a link scheme and not a picture scheme. src() allowing it would let an imported '
        .'review photo become <img src="mailto:…">.'
    );
    expect(SafeUrl::href(null))->toBe('#');
    expect(SafeUrl::src(null))->toBe('');
});
