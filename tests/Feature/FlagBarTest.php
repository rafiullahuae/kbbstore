<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\HeaderSettings;
use App\Services\SettingsService;
use App\Support\FlagArt;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;
use Tests\Support\EnglishRenderWalk;

/**
 * THE FLAG BAR — the thin strip above the header. (Lane FB)
 *
 * The owner: "i need thin bar as same as attached, having uae flat, then text
 * and then korea flag. (This bar is only for mobile, keep this turnef off for
 * desktop by default)."
 *
 * Every case below is a defect this strip could have shipped WITH, written so
 * that undoing the thing it guards turns it red. Each carries the mutation that
 * was actually run.
 *
 * ── THE ONE THAT MATTERS MOST IS THE FLAGS ──────────────────────────────────
 *
 * The obvious way to draw two flags is `🇦🇪` and `🇰🇷`, and it is wrong in a way
 * that never shows up on the machine it is written on. Those are not characters:
 * they are REGIONAL INDICATOR pairs (U+1F1E6..U+1F1FF), and a flag is drawn only
 * where the platform ships a font with that pair in it. Windows does not — every
 * browser on Windows renders the boxed letters `AE` and `KR` — and this strip's
 * whole job is to say "authentic", to an audience that is largely on Windows and
 * Android. `it draws no emoji flag anywhere` is the case that pins it.
 */

/** The storefront home page, as bytes. */
function fbHome(): string
{
    return test()->get('/')->assertOk()->getContent();
}

/** Save flag-bar settings and forget everything that memoised the old ones. */
function fbSet(array $values): void
{
    app(HeaderSettings::class)->save($values);
}

/** The `<div class="kfb …">` element with everything inside it, or null. */
function fbElement(string $html): ?string
{
    return preg_match('#<div class="kfb\b.*?</div>\s*</div>#s', $html, $m) === 1 ? $m[0] : null;
}

/* ══════════════════ 1. what ships ══════════════════ */

it('ships on for phones AND for desktop, which is what he asked for in those words', function () {
    /*
     * CLAUDE.md rule 1 says a new setting ships at the value the page already
     * has, with one exception — "a default the owner asked for in as many
     * words". This case is where that exception is written down.
     *
     * ▲ AND THE WORDS CHANGED, SO THE PIN MOVED WITH THEM.           (Lane SEC)
     *
     * It read `fb_mobile` true, `fb_desktop` FALSE, on this sentence: "(This
     * bar is only for mobile, keep this turnef off for desktop by default)".
     * He has since said the other thing, about the same strip: "The top
     * countries bar, i need under banner ... apply this on desktop and mobile
     * both." So both defaults are now true.
     *
     * TWO PLACES, AND ONE OF THEM IS NOT A DEFAULT. HeaderSettings::SCHEMA
     * covers a shop that has never saved the Header screen; a shop that HAS
     * saved it carries a stored `fb_desktop: false` that no schema default can
     * overrule, and the migration `banner_ships_as_image_slider` writes the
     * key for that shop. This case can only see the first of the two — it
     * reads `all()` on a fresh database — and that is worth saying, because a
     * green here would not have told the owner his own shop had moved.
     *
     * MUTATION: flip either default in HeaderSettings::SCHEMA and this is red
     * naming the one that moved. Ran both — `fb_desktop` false now reports
     * "the desktop shop is missing the strip he asked for".
     */
    $c = app(HeaderSettings::class)->all();

    expect($c['fb_mobile'])->toBeTrue('the strip the owner asked for is not on for phones');
    expect($c['fb_desktop'])->toBeTrue('the desktop shop is missing the strip he asked for');

    $el = fbElement(fbHome());

    expect($el)->not->toBeNull('no flag bar on the shipped storefront');
    expect($el)->toContain('kfb-m')
        ->and($el)->toContain('kfb-d');
});

it('draws the UAE flag, then the words, then the Korean flag — in that source order', function () {
    /*
     * SOURCE ORDER IS THE WHOLE OF THE RTL BEHAVIOUR, so it is asserted as
     * order and not as presence. `.kfb-in` is a flex row with no `order`, no
     * `left` and no `right`, so a browser laying out /ar puts the FIRST child
     * at the reading start — the right — and the pair swaps with no second
     * rule and no `[dir]` selector to keep in step.
     *
     * MUTATION: swap the two @if blocks in partials/flag-bar.blade.php and
     * this is red ("Korea is drawn before the UAE"). Ran it.
     */
    $el = fbElement(fbHome());

    $uae = strpos((string) $el, 'aria-label="Flag of the United Arab Emirates"');
    $text = strpos((string) $el, 'kfb-tx');
    $korea = strpos((string) $el, 'aria-label="Flag of South Korea"');

    expect($uae)->not->toBeFalse('the UAE flag is missing');
    expect($korea)->not->toBeFalse('the Korean flag is missing');
    expect($uae < $text)->toBeTrue('the words are drawn before the UAE flag');
    expect($text < $korea)->toBeTrue('Korea is drawn before the words — or before the UAE flag');
});

it('draws no emoji flag anywhere', function () {
    /*
     * THE DEFECT THIS WHOLE FILE IS MOST FOR, and it is invisible on Linux and
     * on a Mac: `🇦🇪` renders as a flag there and as the boxed letters `AE` on
     * every browser on Windows, because the pair is two REGIONAL INDICATOR
     * codepoints and Segoe UI Emoji carries no flag glyphs. A shopper on
     * Windows would have read "AE ... KR" on a strip whose subject is
     * authenticity.
     *
     * Scanned over the served page AND over both drawings, by codepoint range
     * rather than by the two literals, so `🇸🇦` or any other pair somebody adds
     * later is caught by the same assertion.
     *
     * MUTATION: put `🇦🇪` into partials/flag-bar.blade.php and this is red
     * reporting U+1F1E6. Ran it.
     */
    foreach (['the served page' => fbHome(), 'FlagArt::UAE' => FlagArt::UAE, 'FlagArt::KOREA' => FlagArt::KOREA] as $what => $subject) {
        expect(preg_match('/[\x{1F1E6}-\x{1F1FF}]/u', $subject))
            ->toBe(0, $what.' carries a regional-indicator codepoint, which is an emoji flag and renders as two letters on Windows');
    }

    // And the drawings really are drawings, so the assertion above is not
    // passing because there is nothing there.
    expect(FlagArt::UAE)->toContain('<svg')->toContain('#00732F');
    expect(FlagArt::KOREA)->toContain('<svg')->toContain('#CD2E3A');
});

it('is in the document exactly once, and only once', function () {
    /*
     * PINS THE FINISHED STATE, never an absence — CLAUDE.md records three days
     * lost to lanes asserting they had NOT been wired up. Zero here is "built,
     * never included"; two is two strips stacked above the header, which is
     * what a second @include during a merge produces and what nothing else
     * would catch.
     *
     * MUTATION: delete the @include from layouts/store.blade.php → "included 0
     * times"; duplicate it → "2". Ran both.
     */
    $layout = (string) file_get_contents(resource_path('views/layouts/store.blade.php'));

    expect(substr_count($layout, "@include('partials.flag-bar')"))
        ->toBe(1, 'partials.flag-bar must be included exactly once by the store layout');

    expect(substr_count(fbHome(), 'class="kfb '))
        ->toBe(1, 'the strip is rendered more than once on one page');
});

it('draws nothing at all when both switches are off', function () {
    /*
     * NOT a hidden element — no element. A shop that switches the strip off on
     * both widths gets back the page it had before the package, byte for byte,
     * which is the only honest meaning of "off". A `display:none` div is still
     * a node in every page of the shop and is still read by a browser that gets
     * the stylesheet late.
     *
     * MUTATION: drop the flagBarOn() guard from layouts/store.blade.php and
     * this is red — the markup is there with neither class on it, invisible at
     * every width and present in every page. Ran it.
     */
    $before = fbHome();
    expect($before)->toContain('kfb');

    fbSet(['fb_mobile' => false, 'fb_desktop' => false]);

    $after = fbHome();

    expect($after)->not->toContain('kfb');
    expect($after)->not->toContain('Flag of South Korea');
});

it('is a pure insertion: take the strip out again and the page is byte-identical', function () {
    /*
     * RULE 1, MADE CHECKABLE FROM INSIDE THIS BRANCH.
     *
     * StorefrontEnglishUnchangedTest compares the tree against a COMMIT, so
     * once its pin moves forward to carry this strip it can no longer say
     * anything about this strip. This case says the thing that matters and
     * says it without a commit hash: the whole of what the flag bar does to a
     * storefront page is add its own element at one point. Cut that element
     * out of the served page and what is left is byte-for-byte the page the
     * same shop serves with both switches off — no reflow, no moved
     * whitespace, no second copy of anything, on every page that draws it.
     *
     * Masked with EnglishRenderWalk's own mask, because a CSRF token differs
     * between two renders of the same file whatever this lane does.
     *
     * MEASURED ALONGSIDE, and recorded in the commit rather than pinned here:
     * against the tree this branch was cut from, 39 storefront pages render,
     * 31 carry the strip, and on all 39 the rest of the document is identical.
     * The eight without it are the quick-view fragment, the checkout and the
     * order-received page (both `bare`), Laravel's own 404 document, and the
     * four standalone documents — the Journal, an article, the quiz and the
     * review wall — which carry no site header either.
     *
     * MUTATION: make the partial emit a newline before the element (put the
     * closing `--}}` of its header comment on a line of its own) and this is
     * red at the byte before `<div class="kfb`. Ran it; that is exactly the
     * defect it caught while this was being written.
     */
    $paths = ['/', '/shop/', '/cart/', '/my-wishlist/'];

    $with = [];

    foreach ($paths as $path) {
        $with[$path] = EnglishRenderWalk::mask(test()->get($path)->assertOk()->getContent());
    }

    fbSet(['fb_mobile' => false, 'fb_desktop' => false]);

    foreach ($paths as $path) {
        $without = EnglishRenderWalk::mask(test()->get($path)->assertOk()->getContent());

        $cut = 0;
        $stripped = (string) preg_replace('#<div class="kfb .*?</div>\s*</div>\n#s', '', $with[$path], -1, $cut);

        expect($cut)->toBe(1, $path.' did not carry exactly one strip to cut out');
        expect($stripped)->toBe($without, $path.' changed by something other than the strip');
    }
});

/* ══════════════════ 2. the words ══════════════════ */

it('renders the shipped line from a translated key, not from English in the template', function () {
    /*
     * Arabic is a live language on this shop. A literal here would have to be
     * found and re-keyed by a later lane, and until then /ar would carry an
     * English claim about authenticity — which is the one reader this strip is
     * aimed at.
     *
     * MUTATION: replace __('store.flagbar.text') in the partial with the
     * literal and this is red on the Arabic render; StorefrontStringsAreKeyed
     * goes red as well, from the other direction.
     */
    expect(fbHome())->toContain("UAE&#039;s Authentic K-Beauty Store");

    ArabicShop::on();
    ArabicShop::string('store.flagbar.text', 'متجر الإمارات');

    $ar = test()->get('/ar/')->assertOk()->getContent();

    expect($ar)->toContain('متجر الإمارات')
        ->and($ar)->not->toContain('UAE&#039;s Authentic K-Beauty Store');
});

it('lets the owner type his own wording, and escapes it', function () {
    /*
     * The wording is his. It is also a SETTING, so it is printed with {{ }} and
     * never with {!! !!} — Appearance → Header is behind a capability, but a
     * stored string that reaches the page raw is a stored-XSS sink on every
     * page of the shop, which is the largest blast radius this repo has.
     *
     * MUTATION: change {{ $kfbText }} to {!! $kfbText !!} in the partial and
     * this is red with a live <script> in the served page. Ran it.
     */
    fbSet(['fb_text' => 'Our own line <script>alert(1)</script>']);

    $html = fbHome();

    expect($html)->toContain('Our own line')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});

/* ══════════════════ 3. secure by construction ══════════════════ */

it('cannot be made to write anything but a hex colour into the style attribute', function () {
    /*
     * The strip's five colours land in a `style` attribute. HeaderSettings'
     * POLICY sets `hex => strict`, so ModuleSchema::cast() hands back
     * Color::isValidHex() or the schema default and nothing in between — a
     * stored value that is not a colour can never reach the attribute, and the
     * attribute itself is printed through {{ }} besides.
     *
     * MUTATION: change POLICY's `hex` to a mode that repairs rather than
     * refuses and re-run — the injected text survives the cast. Ran it against
     * a local copy; with `strict` the attribute reads the default #FDEFF4.
     */
    fbSet(['fb_bg' => '#fff" onload="alert(1)', 'fb_ink' => 'javascript:alert(1)']);

    $html = fbHome();
    $el = (string) fbElement($html);

    expect($el)->not->toContain('onload')
        ->and($el)->not->toContain('javascript:')
        ->and($el)->toContain('--kfb-bg:#FDEFF4')
        ->and($el)->toContain('--kfb-ink:#E0567B');
});

it('prints only a constant unescaped, and the constant interpolates nothing', function () {
    /*
     * CLAUDE.md rule 5: anything printed unescaped is a constant, never a
     * setting. The two drawings are the only raw output on this strip, so this
     * reads the class they come from and asserts there is no way for a value to
     * get into it — no interpolation, no concatenation with anything but string
     * literals, no setting read, no database read, no method taking an argument.
     *
     * MUTATION: add `.$something` to either constant and this is red. It is
     * also why the flags' accessible NAMES are on a wrapper rather than inside
     * the SVG: a translated aria-label is a value, and a value inside raw
     * markup is the thing this case forbids.
     */
    $src = (string) file_get_contents(base_path('app/Support/FlagArt.php'));

    // Everything after the class body opens — the docblock above it is prose.
    $body = substr($src, (int) strpos($src, 'final class FlagArt'));

    expect($body)->not->toMatch('/\$[A-Za-z_]/');
    expect($body)->not->toContain('Setting')
        ->and($body)->not->toContain('DB::')
        ->and($body)->not->toContain('app(');

    // Both drawings hide themselves from the accessibility tree, because the
    // wrapper carries the name.
    expect(FlagArt::UAE)->toContain('aria-hidden="true"');
    expect(FlagArt::KOREA)->toContain('aria-hidden="true"');

    // And the page gives each one exactly one accessible name.
    $el = (string) fbElement(fbHome());
    expect(substr_count($el, 'role="img"'))->toBe(2);
});

it('exposes none of the flag-bar settings on the unauthenticated API', function () {
    /*
     * /api/* is unauthenticated on this site. `header_settings` is one row
     * holding sixty-nine keys and the flag bar is eleven of them; the guard is
     * SettingController::PUBLIC_KEYS, an allowlist, and this asserts the new
     * keys did not walk into it.
     *
     * MUTATION: add 'header_settings' to PUBLIC_KEYS and this is red.
     */
    $body = test()->getJson('/api/settings')->assertOk()->getContent();

    foreach (['fb_mobile', 'fb_desktop', 'fb_text', 'fb_bg', 'fb_ink', 'fb_border'] as $key) {
        expect($body)->not->toContain($key);
    }
});

/* ══════════════════ 4. fast, and measured ══════════════════ */

it('costs no query at all', function () {
    /*
     * The strip reads `header_settings` — the SAME one row
     * partials/header.blade.php already reads on every page, memoised by
     * SettingsService behind a forever cache. A settings module of its own
     * would have been a second read on the critical path of every page of the
     * shop, to draw thirty pixels.
     *
     * Measured as a DIFFERENCE rather than as an absolute, because the absolute
     * belongs to StorefrontQueryBudgetTest and moves when anything else on the
     * home page does.
     *
     * MUTATION: give the partial a SettingsService read of its own under a key
     * nothing autoloads (`app(SettingsService::class)->get('kfb_text')`) and
     * the two numbers differ by one. Ran it.
     */
    /*
     * ONE RENDER FIRST, THEN THE SETTINGS MEMOS DROPPED, THEN THE MEASURED ONE.
     *
     * Both halves are needed and both were found by getting it wrong. Measured
     * cold-then-warm the two numbers were 44 and 2 — the first page of a test
     * process resolves the menu, the product count and the settings snapshot,
     * and the second resolves nothing at all — which reads as though the strip
     * SAVED forty-two queries. Warm-then-warm is 0 and 0, which proves as
     * little in the other direction: a settings read that is served from the
     * cache is invisible.
     *
     * So: warm everything, then drop the three memos this app keeps for
     * settings (the process-level static CLAUDE.md records, SettingsService'
     * own memo, and its cache) and render again. The settings read is then real
     * and countable in BOTH configurations, and what is left is exactly what
     * the strip costs.
     */
    $count = function (): int {
        fbHome();

        Setting::flushMap();
        SettingsService::forgetMemo();
        app(SettingsService::class)->flush();

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        fbHome();
        $n = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        return $n;
    };

    fbSet(['fb_mobile' => false, 'fb_desktop' => false]);
    $off = $count();

    fbSet(['fb_mobile' => true]);
    $on = $count();

    expect($off)->toBeGreaterThan(0, 'no queries were seen at all, so this measured nothing');
    expect($on)->toBe($off, 'the strip added queries to the home page');
});

it('measures nothing in JavaScript, and ships no JavaScript', function () {
    /*
     * CLAUDE.md rule 4: no JavaScript that measures layout — this project sizes
     * with calc() for a reason and two tests forbid the element-measuring APIs
     * by name. The strip has no script of any kind: its height is a CSS
     * declaration and its mirroring is the source order of three children.
     *
     * MUTATION: add a <script> to the partial and this is red.
     */
    $partial = (string) file_get_contents(resource_path('views/partials/flag-bar.blade.php'));

    expect($partial)->not->toContain('<script')
        ->and($partial)->not->toContain('getBoundingClientRect')
        ->and($partial)->not->toContain('offsetHeight')
        ->and($partial)->not->toContain('clientWidth');
});

it('reserves its height in the stylesheet, so the header below it cannot jump', function () {
    /*
     * IT SITS ABOVE THE HEADER, so anything that sizes it late pushes the whole
     * page down after first paint — the textbook cause of cumulative layout
     * shift and the reason this is a rule in kbb.css rather than an inline
     * <style> pushed from the partial.
     *
     * Read from the SOURCE and from the BUILT BUNDLE, both as git has them,
     * because this repo's signature failure is a rule that is real in one and
     * stale in the other: `package.json` defines no build script and CI does
     * not build assets.
     *
     * MUTATION: drop `min-height` from `.kfb` and this is red; edit the source
     * without `npx vite build` and the bundle half is red.
     */
    $source = (string) file_get_contents(base_path('resources/css/kbb/kbb.css'));

    /*
     * ▲ THE min-height ASSERTION IS READ OUT OF THE `.kfb` RULE ITSELF.
     *                                                        (Lane BG, r5)
     *
     * It used to be `->and($source)->toContain('min-height:var(--kfb-h,30px)')`
     * against the whole stylesheet, and that string occurs TWICE in kbb.css:
     * once in `.kfb{…}` and once in the `@media` block below it. So the
     * MUTATION NOTE above this case — "drop `min-height` from `.kfb` and this
     * is red" — was not true. MEASURED: dropped exactly that declaration from
     * `.kfb` and this file stayed at 18 passed; the media-query copy satisfied
     * the needle.
     *
     * The rule is now cut out first and the declaration looked for inside it,
     * so the note describes what happens again.
     */
    expect($source)->toContain('.kfb{display:none;');

    $start = strpos($source, '.kfb{display:none;');
    $rule = substr($source, (int) $start, (int) strpos($source, '}', (int) $start) - (int) $start + 1);

    expect(str_contains($rule, 'min-height:var(--kfb-h,30px)'))->toBeTrue(
        'the `.kfb` rule itself no longer sets min-height, so the strip can collapse to nothing'
        .' on a page where no flag image loads. The declaration in the @media block below it does'
        .' not cover that: the rule found was '.$rule);

    $bundles = glob(base_path('public/build/assets/kbb-*.css')) ?: [];
    $built = '';

    foreach ($bundles as $file) {
        $built .= (string) file_get_contents($file);
    }

    expect($built)->not->toBe('', 'no built kbb bundle to read — run npx vite build');
    expect(str_contains($built, '.kfb{') && str_contains($built, '--kfb-h'))
        ->toBeTrue('the built stylesheet has no flag-bar rules: the source was edited without npx vite build');
});

it('keeps the whole line on the narrowest phones', function () {
    /*
     * MEASURED, AND THE FIRST MEASUREMENT WAS THE DEFECT. At 320px the shipped
     * line (215px at 12px Poppins), two 21px flags, two 10px gaps and a 22px
     * gutter a side come to more than the screen, so `.kfb-tx` hit its own
     * ellipsis and the strip read "UAE's Authentic K-Beauty…" — a truncated
     * claim about authenticity, which is the one rendering that undoes the
     * point of the strip. Photographed before and after in
     * docs/lane-fb-shots/.
     *
     * The fix closes the gutter and the gaps BELOW 360 and nowhere else, so the
     * two widths that already fit do not move: measured after, 360 and 390 are
     * byte-for-byte the same geometry as before (text 241.2px, flags at x=22),
     * and 320 has 242px of room for a 235px pill.
     *
     * It is a media query and not a script, and it changes no size the owner
     * controls — the Text size and Flag height sliders mean the same thing at
     * every width, which a vw-scaled font would have quietly stopped being true.
     *
     * MUTATION: delete the @media block and 320 ellipsises again — and this is
     * red from the source side at once.
     */
    $source = (string) file_get_contents(base_path('resources/css/kbb/kbb.css'));

    expect($source)->toContain('@media (max-width:359px){');

    $narrow = substr($source, (int) strpos($source, '@media (max-width:359px){'));

    expect($narrow)->toContain('.kfb-in{padding-inline:12px;gap:6px}')
        ->and($narrow)->toContain('.kfb-pill .kfb-tx{padding-inline:9px}');

    $built = '';

    foreach (glob(base_path('public/build/assets/kbb-*.css')) ?: [] as $file) {
        $built .= (string) file_get_contents($file);
    }

    expect(str_contains($built, '@media (max-width:359px)') && str_contains($built, '.kfb-pill .kfb-tx{padding-inline:9px}'))
        ->toBeTrue('the narrow-phone rule is in the source and not in the bundle: run npx vite build');
});

it('mirrors from logical properties alone, with no direction selector', function () {
    /*
     * RTL. On /ar the UAE flag belongs at the reading start and Korea at the
     * end, which is the mirror of the English arrangement. That comes out of
     * the flex row and the source order; it must NOT come out of a `[dir]`
     * rule, because a second rule is a second thing to keep in step and Lane G
     * has fifty-seven of those to show for it.
     *
     * Scoped to the flag bar's own block, read out of the source file.
     *
     * MUTATION: write `[dir="rtl"] .kfb-in{flex-direction:row-reverse}` into
     * that block and this is red — and the rendering is wrong twice over,
     * because the browser had already mirrored it.
     */
    $source = (string) file_get_contents(base_path('resources/css/kbb/kbb.css'));

    /* COMMENTS STRIPPED FIRST, and that is not tidiness: the block's own
       header explains why there is no `[dir]` rule in it, so a naive read of
       the source finds the string it is looking for inside the prose that says
       it is not there. Same trap as the `require` line quoted in a comment in
       routes/web.php. */
    $block = (string) preg_replace('#/\*.*?\*/#s', '', substr($source, (int) strpos($source, '.kfb{display:none')));

    expect($block)->not->toContain('[dir')
        ->and($block)->not->toMatch('/(^|[;{\s])(margin|padding|border)-(left|right)\s*:/m')
        ->and($block)->not->toMatch('/(^|[;{\s])(left|right)\s*:/m')
        ->and($block)->not->toContain('row-reverse');

    // The logical ones it does use, so the assertions above are not vacuous.
    expect($block)->toContain('padding-inline')
        ->and($block)->toContain('margin-inline');
});

/* ══════════════════ 5. the screen he sets it from ══════════════════ */

it('draws all eleven controls on Appearance → Header → Flag bar', function () {
    /*
     * The strip has no screen of its own ON PURPOSE. Appearance → Header is
     * drawn generically from HeaderSettings::TABS by paintHeader() in
     * admin/app.blade.php, so a tab added to that constant is a tab the console
     * draws with no edit to the console, no new route file and no new partial —
     * which is three integrator edits this lane does not have to ask for and
     * three ways for it to ship half-wired.
     *
     * MUTATION: remove the 'flagbar' entry from HeaderSettings::TABS and this
     * is red — the eleven settings are stored, saveable and drawn by nothing,
     * which is the module-framework defect this repo has hit four times.
     */
    $admin = AdminUser::create([
        'name' => 'FB', 'email' => 'fb-'.uniqid().'@example.com',
        'password' => bcrypt('x'), 'role' => 'owner',
    ]);

    $body = test()->actingAs($admin, 'admin')->getJson('/admin-api/header')->assertOk()->json();

    $tab = null;

    foreach (($body['tabs'] ?? []) as $t) {
        if (($t['key'] ?? '') === 'flagbar') {
            $tab = $t;
        }
    }

    expect($tab)->not->toBeNull('Appearance → Header has no Flag bar tab');
    expect($tab['label'])->toBe('Flag bar');

    $keys = array_column($tab['fields'], 'key');

    /*
     * ▲ ELEVEN FIELDS BECAME TWELVE — Lane BG, and the pin is advanced rather
     * than loosened, because the ORDER is part of what it asserts: the screen
     * draws the tab in this sequence and "Show the wording on desktop" belongs
     * directly under "Wording", which is the field it qualifies.
     *
     * The owner: "UAE's Authentic K-Beauty Store — remove this from the
     * desktop version." The line comes off the desktop strip and stays on the
     * phone one; `fb_text` is shared by both widths, so a switch is the only
     * thing that can be device-scoped without changing what a phone shows.
     */
    expect($keys)->toBe([
        'fb_mobile', 'fb_desktop', 'fb_text', 'fb_text_desktop', 'fb_flags', 'fb_height', 'fb_size',
        'fb_flag_h', 'fb_bg', 'fb_ink', 'fb_pill', 'fb_border',
    ]);

    // Every one of them draws with a control the screen already knows how to
    // draw — hdField() handles exactly these four types and falls through to a
    // text input for anything else.
    foreach ($tab['fields'] as $f) {
        expect($f['type'])->toBeIn(['bool', 'text', 'range', 'colour']);
    }
});

it('saves from that screen and moves the shop', function () {
    /*
     * The half that is easy to leave out: a control that saves and changes
     * nothing is the defect ProductStyles shipped with for a year. This drives
     * the real endpoint and then reads the storefront.
     *
     * MUTATION: drop `fb_height` from flagBarStyle() and this is red — the
     * slider saves, the screen redraws, and the strip stays 30px.
     */
    $admin = AdminUser::create([
        'name' => 'FB2', 'email' => 'fb2-'.uniqid().'@example.com',
        'password' => bcrypt('x'), 'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/header', ['settings' => [
            'fb_height' => 44, 'fb_desktop' => true, 'fb_pill' => false, 'fb_flags' => false,
        ]])
        ->assertOk();

    $el = (string) fbElement(fbHome());

    expect($el)->toContain('--kfb-h:44px')
        ->and($el)->toContain('kfb-d')
        ->and($el)->not->toContain('kfb-pill')
        ->and($el)->not->toContain('role="img"');
});
