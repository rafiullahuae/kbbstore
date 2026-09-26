<?php

/**
 * The preview agrees with the page — Lane S7.
 *
 * ── THE DEFECT THIS EXISTS FOR ──────────────────────────────────────────────
 *
 * Three editors in this console — Catalog → Categories, Catalog → Brands,
 * Content → Blog Posts — collected an SEO title and an SEO description and
 * showed the operator NO CONSEQUENCE. What is in the box is not what Google
 * receives, and the difference is not small:
 *
 *   · An EMPTY title box publishes the row's own name run through
 *     `seo_title_template`, which on this store appends " | K-Beauty Bliss". So
 *     a blank box is not a blank title, it is a ~40-character one.
 *   · A FILLED title box is the WHOLE title and the site name is NOT appended
 *     (`title_is_final`). The same 58 characters therefore mean 58 or 75
 *     depending on a rule that appears nowhere on the screen.
 *   · A `%%title%%` typed into the box is substituted server-side, and a token
 *     TitleTemplate is not handed is DELETED — the defect that published the
 *     site name alone on all 671 imported product pages.
 *
 * The product editor was given a live snippet for exactly this reason and its
 * own comment records the measurement: counting the box told the operator
 * "0 / 60" under a tag Google receives at 40-odd characters, and "58 / 60,
 * good" under a 75-character one. The other three never got the fix.
 *
 * ── WHY THIS TEST FETCHES THE REAL PAGE ─────────────────────────────────────
 *
 * The preview could have been written in the browser, and it would then be a
 * FIFTH dialect of four rules this project has already paid for four times over
 * (docs/M-PHASE3-SETTINGS-SCHEMA-ROUND-3.md §1: the third hex dialect nobody had
 * named, found by driving values rather than by reading). So it asks the server,
 * and the server asks App\Support\Seo.
 *
 * A test that compared the endpoint against a second implementation would prove
 * nothing — both could be wrong together. So for each kind this saves the
 * override, FETCHES THE STOREFRONT PAGE, pulls `<title>` and
 * `<meta name="description">` out of the response, and requires the endpoint to
 * answer those bytes. A preview that agrees with the page cannot be wrong about
 * the page.
 *
 * MUTATIONS ACTUALLY RUN, each applied, run, the output copied and the change
 * reverted — quoted in full in this lane's report:
 *   · drop `title_is_final` from the collection arm of
 *     SeoPreviewApiController::context() → the two taxonomy cases go red with
 *     the site name appended to a title the page publishes bare.
 *   · drop `title_token` → the `%%title%%` case goes red with the site name
 *     alone, which is the 671-page production defect reproduced.
 *   · reach the `home` arm with $ctx['title'] instead of title_is_final → the
 *     homepage case goes red on a shop that has already saved a home title.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SeoBackOfficeRoutes;

beforeEach(function () {
    /*
     * routes/seo-back-office.php is not required from routes/web.php yet — the
     * integrator wires it, and CLAUDE.md forbids this lane from touching that
     * file. So the group is registered here, exactly as the note on parallel
     * work says a lane should ("register the group in the test"), and
     * SeoBackOfficeWiringTest pins the finished state rather than its absence.
     */
    SeoBackOfficeRoutes::wire(app());

    $owner = AdminUser::create([
        'name' => 'S7 Preview Owner',
        'email' => 's7-preview-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');
});

/** `<title>` out of a rendered storefront response. */
function s7PageTitle(string $html): string
{
    preg_match('#<title>(.*?)</title>#si', $html, $m);

    return html_entity_decode(trim($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** `<meta name="description">` out of a rendered storefront response, or ''. */
function s7PageDescription(string $html): string
{
    preg_match('#<meta\s+name="description"\s+content="(.*?)"#si', $html, $m);

    return html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** The preview endpoint, as the console calls it. */
function s7Preview(array $body): array
{
    $response = test()->postJson('/admin-api/seo-preview', $body);

    expect($response->status())->toBe(200, 'the preview endpoint refused '.json_encode($body));

    return $response->json();
}

it('previews a category exactly as the category page renders it', function () {
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    Setting::flushMap();

    $category = Category::create([
        'name' => 'Sun Care',
        'slug' => 'sun-care',
        'path' => 'sun-care',
        'description' => 'Every SPF we stock, for the Gulf sun.',
        'seo' => [
            'title' => 'Korean Sunscreen in Dubai — SPF50+ That Does Not Sting',
            'description' => 'Korean SPF50+ sunscreens that do not leave a white cast, delivered across the UAE.',
        ],
    ]);

    $page = test()->get('/product-category/sun-care/');
    $page->assertStatus(200);
    $html = $page->getContent();

    $preview = s7Preview([
        'kind' => 'category',
        'id' => $category->id,
        'title' => 'Korean Sunscreen in Dubai — SPF50+ That Does Not Sting',
        'description' => 'Korean SPF50+ sunscreens that do not leave a white cast, delivered across the UAE.',
    ]);

    expect($preview['title'])->toBe(s7PageTitle($html));
    expect($preview['description'])->toBe(s7PageDescription($html));

    /*
     * AND THE FILLED-BOX RULE, MEASURED RATHER THAN ASSERTED. The typed title
     * is the whole tag: the site name is NOT appended. If this ever gains
     * " | K-Beauty Bliss", `title_is_final` has been lost and every override on
     * the shop is being re-templated.
     */
    expect($preview['title'])->toBe('Korean Sunscreen in Dubai — SPF50+ That Does Not Sting');
    expect($preview['title_is_yours'])->toBeTrue();
});

it('previews an EMPTY category title box as the tag the page really publishes', function () {
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    Setting::flushMap();

    $category = Category::create([
        'name' => 'Toners',
        'slug' => 'toners-s7',
        'path' => 'toners-s7',
        'description' => 'Hydrating toners and essences.',
        'seo' => null,
    ]);

    $page = test()->get('/product-category/toners-s7/');
    $page->assertStatus(200);
    $html = $page->getContent();

    $preview = s7Preview(['kind' => 'category', 'id' => $category->id, 'title' => '', 'description' => '']);

    /*
     * THE WHOLE POINT OF THE ROUND, IN ONE ASSERTION. An empty box does not
     * publish an empty title. Before this endpoint existed, an operator looking
     * at this screen had nothing telling him so, and the box's own placeholder
     * ("Defaults to the category name") says half of it — it does not say that
     * the site name is appended on top, which is the half that decides whether
     * the tag fits.
     */
    expect($preview['title'])->toBe(s7PageTitle($html));
    expect($preview['title'])->not->toBe('');
    expect($preview['title_is_yours'])->toBeFalse();
    expect($preview['description'])->toBe(s7PageDescription($html));
});

it('previews a brand exactly as the brand page renders it', function () {
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    Setting::flushMap();

    $brand = Brand::create([
        'name' => 'Beauty of Joseon',
        'slug' => 'boj-s7',
        'description' => 'Hanbang formulas, modern textures.',
        'seo' => [
            'title' => 'Beauty of Joseon UAE — Official Stockist',
            'description' => 'The full Beauty of Joseon range in the UAE, authentic and in stock.',
        ],
    ]);

    $page = test()->get('/korean-skincare-brands/boj-s7/');
    $page->assertStatus(200);
    $html = $page->getContent();

    $preview = s7Preview([
        'kind' => 'brand',
        'id' => $brand->id,
        'title' => 'Beauty of Joseon UAE — Official Stockist',
        'description' => 'The full Beauty of Joseon range in the UAE, authentic and in stock.',
    ]);

    expect($preview['title'])->toBe(s7PageTitle($html));
    expect($preview['description'])->toBe(s7PageDescription($html));
});

it('resolves a Yoast token in a typed title the way the page does', function () {
    /*
     * THE 671-PAGE PRODUCTION DEFECT, on the three screens that can still reach
     * it. `%%title%% %%sep%% %%sitename%%` is Yoast's shipped default and the
     * only way it gets into `brands.seo` is somebody typing it into this box.
     * TitleTemplate DELETES a token it is not handed, so without `title_token`
     * the tag becomes the site name alone — which the page renders perfectly and
     * nothing on the shop complains about.
     *
     * A preview that could not show this would be worse than none: it would
     * report a healthy-looking title for a page publishing six words.
     */
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    Setting::flushMap();

    $brand = Brand::create([
        'name' => 'COSRX',
        'slug' => 'cosrx-s7',
        'seo' => ['title' => '%%title%% %%sep%% %%sitename%%'],
    ]);

    $page = test()->get('/korean-skincare-brands/cosrx-s7/');
    $page->assertStatus(200);

    $preview = s7Preview([
        'kind' => 'brand',
        'id' => $brand->id,
        'title' => '%%title%% %%sep%% %%sitename%%',
        'description' => '',
    ]);

    expect($preview['title'])->toBe(s7PageTitle($page->getContent()));
    // And it really did resolve, rather than both sides agreeing on a deletion.
    expect($preview['title'])->toContain('COSRX');
});

it('previews an article exactly as the article page renders it', function () {
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    Setting::flushMap();

    $post = Post::create([
        'slug' => 's7-double-cleansing',
        'title' => 'How double cleansing actually works',
        'excerpt' => 'Oil first, water second, and why the order is not a preference.',
        'body' => '<p>Oil first.</p>',
        'status' => 'published',
        'published_at' => now()->subDay(),
    ]);

    $page = test()->get('/s7-double-cleansing/');
    $page->assertStatus(200);
    $html = $page->getContent();

    $preview = s7Preview(['kind' => 'article', 'id' => $post->id, 'title' => '', 'description' => '']);

    expect($preview['title'])->toBe(s7PageTitle($html));
    expect($preview['description'])->toBe(s7PageDescription($html));
    expect($preview['title_is_yours'])->toBeFalse();
});

it('previews the homepage exactly as the homepage renders it', function () {
    /*
     * THE HOME ARM IS THE ONE THAT COULD NOT BE REACHED WITH A CANDIDATE.
     *
     * Seo::titleOf()'s `home` branch reads `seo_home_title` out of the SETTINGS
     * TABLE and falls back to $ctx['title'] only when that row is empty — so on
     * a shop that has already saved a home title, passing a candidate as
     * $ctx['title'] is IGNORED and the preview would draw the saved value while
     * the owner typed a new one. That is the worst failure a preview can have:
     * it looks like it is working.
     *
     * So this shop HAS a saved home title, and the preview is asked for that
     * same string. The two must agree — which is the check that the arm reached
     * is equivalent to the home arm rather than merely adjacent to it.
     */
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    Setting::query()->updateOrCreate(['key' => 'seo_home_title'], ['value' => 'K-Beauty Bliss — Korean skincare for the UAE']);
    Setting::query()->updateOrCreate(['key' => 'seo_home_description'], ['value' => 'Authentic Korean skincare, delivered across the Emirates.']);
    Setting::flushMap();

    $page = test()->get('/');
    $page->assertStatus(200);
    $html = $page->getContent();

    $preview = s7Preview([
        'kind' => 'home',
        'title' => 'K-Beauty Bliss — Korean skincare for the UAE',
        'description' => 'Authentic Korean skincare, delivered across the Emirates.',
    ]);

    expect($preview['title'])->toBe(s7PageTitle($html));
    expect($preview['description'])->toBe(s7PageDescription($html));
});

it('shows a candidate the owner has not saved, rather than the stored value', function () {
    /*
     * "BEFORE HE SAVES" IS THE FEATURE. A preview that redraws the table is a
     * report, and there is already a tab for that.
     */
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    Setting::query()->updateOrCreate(['key' => 'seo_home_title'], ['value' => 'The saved title']);
    Setting::flushMap();

    $preview = s7Preview(['kind' => 'home', 'title' => 'The title he is typing now', 'description' => '']);

    expect($preview['title'])->toBe('The title he is typing now');
    expect($preview['title'])->not->toContain('saved');
});

it('writes nothing, on a row that already has an SEO override', function () {
    /*
     * The endpoint is a POST because the candidate values travel in the body —
     * they are not in the table yet. A POST that reads is unusual enough to be
     * worth pinning, because the next person to touch this file will reasonably
     * assume it saves.
     */
    $brand = Brand::create([
        'name' => 'Anua',
        'slug' => 'anua-s7',
        'seo' => ['title' => 'Do not touch this', 'desc' => 'Nor this'],
    ]);

    $before = Brand::query()->find($brand->id)->getRawOriginal('seo');

    s7Preview([
        'kind' => 'brand',
        'id' => $brand->id,
        'title' => 'Something completely different',
        'description' => 'And another thing',
        'name' => 'Renamed in the box',
    ]);

    expect(Brand::query()->find($brand->id)->getRawOriginal('seo'))->toBe($before);
    expect(Brand::query()->find($brand->id)->name)->toBe('Anua');
});

it('refuses an unknown page kind rather than defaulting to one', function () {
    /*
     * Rule 5 of the project notes: "a select stores one of its own options or the
     * default." A KIND is not a select and a default is the wrong answer for it
     * — each kind carries different rules about whether the site name is
     * appended, so a guessed kind draws a plausible preview of a page that does
     * not exist, and nothing on the screen says so.
     */
    foreach (['product', 'page', '', 'HOME', null, ['home']] as $bad) {
        $response = test()->postJson('/admin-api/seo-preview', ['kind' => $bad, 'title' => 'x']);

        expect($response->status())->toBe(422, 'kind '.json_encode($bad).' was accepted');
        expect($response->json('ok'))->toBeFalse();
    }
});

it('previews a row that does not exist yet from the boxes on the form', function () {
    /*
     * Creating is the moment the wording matters most and the moment there is no
     * id. A missing row is therefore not an error: the preview reads the Name and
     * Address boxes on the same form. Before this, an operator naming a new
     * category had no way to see its title until after it existed.
     */
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    Setting::flushMap();

    $preview = s7Preview([
        'kind' => 'category',
        'id' => null,
        'title' => '',
        'description' => '',
        'name' => 'Cleansing Balms',
        'slug' => 'Cleansing Balms & Oils',
    ]);

    expect($preview['title'])->toContain('Cleansing Balms');
    // The slug is reduced to what a slug may hold, so the grey address line is
    // an address this shop could serve rather than a sentence with spaces in it.
    expect($preview['url'])->toBe('https://kbb.test/product-category/cleansing-balms-oils/');

    /*
     * AND IT SAYS IT IS NOT THE FINISHED ANSWER. There is no page to read for a
     * row that does not exist, so the empty title box cannot be resolved
     * exactly — the wording the archive's own template adds around the name
     * lives in that template. `published_known` false is what makes the screen
     * say so instead of presenting a near-miss as the tag.
     */
    expect($preview['published_known'])->toBeFalse();
});

it('measures the emitted tag and not the box', function () {
    /*
     * The counters are the reason the product editor's own comment exists:
     * "counting the box told the operator '0 / 60' under a title Google receives
     * at 40-odd characters". These numbers are computed server-side from the
     * EMITTED string, in characters rather than UTF-16 code units, because an
     * emoji in a title is a real thing an owner types and
     * String.prototype.length would count it twice.
     */
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    Setting::flushMap();

    $preview = s7Preview(['kind' => 'home', 'title' => '🌞 Sun', 'description' => '']);

    expect($preview['title'])->toBe('🌞 Sun');
    expect($preview['title_length'])->toBe(5);
    expect($preview['title_max'])->toBe(60);
    expect($preview['description_max'])->toBe(155);
});
