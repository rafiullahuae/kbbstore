<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\InstagramPost;
use App\Services\Instagram\IgPath;
use App\Services\Instagram\InstagramAuth;
use App\Services\Instagram\InstagramCredentials;
use App\Services\InstagramSettings;
use Illuminate\Support\Facades\Http;
use Tests\Support\InstagramAdminRoutes;

/**
 * Content → Instagram: the preview, and Configure now in a popup.
 *
 * Phase 21 round 2, Lane IG. Two gaps were closed and this file is the proof of
 * both:
 *
 *   1  there was no preview at all. `grep -c preview` on the screen answered 0,
 *      while every other Appearance screen in this console draws what it controls.
 *   2  Configure now was a full-page redirect. The docblock above it defended that
 *      with a reason that is CORRECT and is not an argument against a popup — what
 *      it rules out is an XHR, and a popup window is a top-level navigation in a
 *      window of its own, which is why it is the standard shape for a handshake.
 *
 * ── WHY SO MANY OF THESE READ THE SOURCE ────────────────────────────────────
 *
 * Most of what shipped this round is browser behaviour in one inline script: a
 * window.open, a postMessage, an origin check and a repaint on `input`. There is no
 * JavaScript test runner in this project, so the properties that MATTER most —
 * "the origin is checked before the data is read", "nothing is prevented until a
 * window exists", "no timer survives the popup" — are asserted over the source.
 *
 * That is a weaker instrument than executing it and it is worth being exact about
 * what it does and does not buy. It CANNOT prove the script runs. It CAN prove the
 * security-relevant ORDERING and the absence of the shapes that would be wrong,
 * which is what a reviewer would otherwise have to re-read the file to check, and
 * it is what goes red when somebody later "simplifies" the origin check away.
 * Behaviour is checked in Chromium by tools/ig-shots.cjs, whose numbers ship with
 * the round; these are the assertions that run on every suite.
 */

/** This file's own helpers, with this file's own prefix — see InstagramSectionShapeTest's note. */
function igpAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Igp owner',
        'email' => 'igp-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** A stored post with a picture that really exists on disk. */
function igpPost(array $attributes = []): InstagramPost
{
    $id = $attributes['remote_id'] ?? ('igp-'.uniqid());
    $name = sha1((string) $id).'.jpg';

    @mkdir(IgPath::directory(), 0775, true);
    file_put_contents(IgPath::directory().'/'.$name, 'not-really-a-jpeg');

    return InstagramPost::query()->create(array_merge([
        'remote_id' => $id,
        'media_type' => 'IMAGE',
        'permalink' => 'https://www.instagram.com/p/ABC'.substr(sha1((string) $id), 0, 8).'/',
        'shortcode' => 'ABC'.substr(sha1((string) $id), 0, 8),
        'caption' => 'A caption',
        'local_path' => '/'.IgPath::ROOT.$name,
        'width' => 640,
        'height' => 640,
        'like_count' => 120,
        'comments_count' => 4,
        'posted_at' => now()->subMinutes(random_int(1, 9000)),
        'seen_at' => now(),
    ], $attributes));
}

function igpScreen(): string
{
    return (string) file_get_contents(base_path('resources/views/admin/partials/instagram-screen.blade.php'));
}

/* ─────────────────────────────── gap 1: the preview ───────────────────────── */

it('puts the preview’s pictures on the payload the screen already asks for', function () {
    /*
     * ── THE PREVIEW COSTS NO ENDPOINT AND NO SECOND REQUEST ─────────────────
     *
     * "Light weight" was asked for in as many words, and the shape of it here is
     * that `tiles` rides on the read the screen makes anyway. The alternative is a
     * fetch per repaint, and the preview repaints on every `input` event — thirty of
     * them in one drag of the "How many posts" slider.
     *
     * So this asserts the tiles are THERE, in the shop's own order, on the endpoint
     * that was already being called.
     *
     * MUTATION NOTE. Delete `'tiles' => $this->previewTiles(),` from
     * InstagramController::content() and the first expectation is red — the screen
     * then draws placeholders over a shop full of posts. RUN: red.
     */
    InstagramAdminRoutes::wire($this->app);
    $this->actingAs(igpAdmin(), 'admin');

    $newest = igpPost(['remote_id' => 'igp-new', 'caption' => 'newest', 'posted_at' => now()->subMinute()]);
    igpPost(['remote_id' => 'igp-old', 'caption' => 'oldest', 'posted_at' => now()->subDays(9)]);

    $tiles = $this->getJson('/admin-api/instagram')->assertOk()->json('content.tiles');

    expect($tiles)->toBeArray()
        ->and(count($tiles))->toBe(2)
        // Newest first, the same `recent()` scope the section draws by — a preview in
        // a different order from the shop is a preview of a different page.
        ->and($tiles[0]['caption'])->toBe('newest')
        ->and($tiles[1]['caption'])->toBe('oldest')
        // ...and pointing at OUR OWN file, never at Meta's CDN.
        ->and($tiles[0]['image'])->toContain(IgPath::ROOT)
        ->and($newest->local_path)->toContain(IgPath::ROOT);
});

it('gives the preview its own allowlist, narrower than the one the shop gets', function () {
    /*
     * ── RULE 5, AND THE THREE FIELDS A DRAWING MUST NOT CARRY ───────────────
     *
     * `InstagramPost::toTile()` is the STOREFRONT's allowlist and it carries
     * `permalink`, `embed` and the row `id`. A preview is a drawing: it has no links
     * in it, it plays nothing, and it never asks this server about a row. So it gets
     * a list of its own, and the property worth pinning is that it is SHORTER —
     * "allowlist what a model returns, never the model" is not satisfied by reusing
     * somebody else's longer list.
     *
     * A preview carrying a permalink is one careless edit away from being a link,
     * which is how an admin drawing starts opening instagram.com in new tabs.
     *
     * MUTATION NOTE. Change previewTiles()' map to `$p->toTile()` and this is red on
     * the key list, naming embed, id and permalink. RUN: red.
     */
    InstagramAdminRoutes::wire($this->app);
    $this->actingAs(igpAdmin(), 'admin');
    igpPost();

    $tile = $this->getJson('/admin-api/instagram')->assertOk()->json('content.tiles.0');

    $keys = array_keys($tile);
    sort($keys);

    expect($keys)->toBe(['caption', 'carousel', 'comments', 'image', 'likes', 'video']);

    foreach (['permalink', 'embed', 'id', 'remote_id', 'shortcode', 'seen_at', 'local_path'] as $forbidden) {
        expect(array_key_exists($forbidden, $tile))->toBeFalse("the preview payload carries {$forbidden}");
    }
});

it('draws a real zero in the preview and draws nothing at all for a count it does not have', function () {
    /*
     * docs/UGC-ENGAGEMENT.md's rule, carried onto the admin drawing: "A NUMBER THIS
     * SHOP CANNOT VERIFY IS NOT PRINTED. Not zero. Not a dash where a figure should
     * be." A reel posted an hour ago genuinely has zero comments, so 0 is a fact and
     * null is "Instagram did not tell us", and the preview has to be able to tell
     * them apart or it teaches the owner something false about his own grid.
     *
     * The payload half is asserted here; the drawing half is `pvCount()`, which
     * compares with `=== null` and returns null for the tile to skip.
     *
     * MUTATION NOTE. Write `'likes' => (int) $p->like_count` in previewTiles() and
     * the null expectation is red — it becomes 0, which is the preview inventing a
     * number. RUN: red.
     */
    InstagramAdminRoutes::wire($this->app);
    $this->actingAs(igpAdmin(), 'admin');

    igpPost(['remote_id' => 'igp-zero', 'like_count' => 0, 'comments_count' => 0, 'posted_at' => now()->subMinute()]);
    igpPost(['remote_id' => 'igp-null', 'like_count' => null, 'comments_count' => null, 'posted_at' => now()->subHour()]);

    $tiles = $this->getJson('/admin-api/instagram')->assertOk()->json('content.tiles');

    expect($tiles[0]['likes'])->toBe(0)
        ->and($tiles[0]['comments'])->toBe(0)
        ->and($tiles[1]['likes'])->toBeNull()
        ->and($tiles[1]['comments'])->toBeNull();

    // And the drawing skips what the payload has no number for, rather than printing
    // a zero — the `=== null` in pvCount(), stated so a "simplification" to `!n` is red.
    expect(igpScreen())->toContain('(n === null || n === undefined) ? null');
});

it('caps the preview at the most the slider can ask for, not at what it says today', function () {
    /*
     * ── WHY THE CAP IS 24 AND NOT `posts` ───────────────────────────────────
     *
     * The slider moves without asking this server anything — that is the whole point
     * of a preview that redraws on input. So dragging "How many posts" from 3 to 24
     * has to find 24 tiles already in hand, or the preview draws PLACEHOLDERS for
     * posts that exist and the owner reads it as a fetch that failed.
     *
     * The number is read from the schema's own `max` rather than written twice, so
     * raising the slider's ceiling raises this with it.
     *
     * MUTATION NOTE. Use `(int) $this->settings->all()['posts']` as the cap and this
     * is red at 3. RUN: red.
     */
    InstagramAdminRoutes::wire($this->app);
    $this->actingAs(igpAdmin(), 'admin');

    app(\App\Services\SettingsService::class)->setModuleSetting(InstagramSettings::MODULE, 'posts', 3);
    \App\Services\SettingsService::forgetMemo();

    for ($i = 0; $i < 30; $i++) {
        igpPost(['remote_id' => 'igp-cap-'.$i, 'posted_at' => now()->subMinutes($i)]);
    }

    $max = (int) InstagramSettings::SCHEMA['posts']['options']['max'];

    expect(count($this->getJson('/admin-api/instagram')->assertOk()->json('content.tiles')))->toBe($max)
        ->and($max)->toBe(24);
});

it('truncates a caption on the way to the preview rather than in the browser', function () {
    /*
     * The overlay clamps to four lines whatever arrives, so a 2,200-character caption
     * is 2,100 characters of payload nobody can see — on a screen that ships up to 24
     * of them. 160 is comfortably more than four lines at the size it is drawn.
     *
     * MUTATION NOTE. Drop the Str::limit() from previewTiles() and this is red with
     * 900. RUN: red.
     */
    InstagramAdminRoutes::wire($this->app);
    $this->actingAs(igpAdmin(), 'admin');

    igpPost(['caption' => str_repeat('a', 900)]);

    expect(strlen((string) $this->getJson('/admin-api/instagram')->json('content.tiles.0.caption')))->toBe(160);
});

it('drops a post from the preview whose stored path is not one IgPath will hand out', function () {
    /*
     * ── THE SAME HOLE THE SHOP REFUSES TO DRAW, AND WHAT stored() ACTUALLY IS ─
     *
     * Worth being exact, because the obvious reading of this filter is wrong and the
     * first draft of this very case asserted the wrong thing: `IgPath::stored()` does
     * NOT check that the file exists. It checks the SHAPE — under the uploads root,
     * no backslash, no NUL, and a filename matching its own alphabet and one of four
     * extensions. A row whose file has been deleted still draws, as a broken picture,
     * here and on the shop alike; that is a real gap and it is named in this round's
     * report rather than silently "fixed" by a stat() per tile on a screen read.
     *
     * What the filter IS for is a row whose PATH is not one this shop would issue —
     * written before a validation change, or by anything that ever wrote that column
     * without going through IgPath. `drawable()` can only say the column is not null,
     * so that row reaches the map and comes back with a null image, and an <img src>
     * of "null" is a hole in the drawing at first paint.
     *
     * MUTATION NOTE. Remove the `->filter(fn (array $tile) => $tile['image'] !== null)`
     * from previewTiles() and this is red with 2 tiles, the second carrying a null
     * image. RUN: red.
     */
    InstagramAdminRoutes::wire($this->app);
    $this->actingAs(igpAdmin(), 'admin');

    igpPost(['remote_id' => 'igp-here', 'posted_at' => now()->subMinute()]);
    $bad = igpPost(['remote_id' => 'igp-bad', 'posted_at' => now()->subHour()]);

    // Outside the uploads root, which is what stored() refuses. Written straight to
    // the column, because nothing that goes through IgPath could produce it.
    $bad->forceFill(['local_path' => '/etc/passwd'])->save();

    expect(IgPath::stored('/etc/passwd'))->toBeNull();

    $tiles = $this->getJson('/admin-api/instagram')->assertOk()->json('content.tiles');

    expect(count($tiles))->toBe(1)
        ->and($tiles[0]['image'])->not->toBeNull();
});

it('previews the section on input and not on save, and repaints without rebuilding the controls', function () {
    /*
     * ── THE ONE PROPERTY THE BRIEF NAMES ────────────────────────────────────
     *
     * "It must move on input, not on save." So every branch of the `input` listener
     * ends in a repaint: the checkbox, the slider and the text box. Three, and the
     * count is the assertion — a listener with two of them is a screen where one
     * control silently does nothing until Save, which is the shape this gap was.
     *
     * AND IT IS paintPreview() AND NOT render(). Both faults are already recorded in
     * this console: a full render replaces #content, which takes the slider out from
     * under the finger dragging it, and puts the caret at the end of the heading box
     * on the second character typed. So the listener must not call render() at all.
     *
     * MUTATION NOTE. Replace either `paintPreview()` in the range branch with
     * `render()` and this is red twice — once on the paint count and once on the
     * "render() is not in the input listener" expectation. RUN: red.
     */
    $screen = igpScreen();

    $start = strpos($screen, "document.addEventListener('input'");
    $end = strpos($screen, "document.addEventListener('change'", (int) $start);

    expect($start)->not->toBeFalse()
        ->and($end)->not->toBeFalse();

    $listener = substr($screen, (int) $start, (int) $end - (int) $start);

    /* The CALL and not the word: this listener's own comment names paintPreview()
       and render() in prose, which is why both counts are of the statement form. */
    expect(substr_count($listener, 'paintPreview();'))->toBe(3)
        ->and(str_contains($listener, 'render();'))->toBeFalse(
            'the input listener re-renders the whole screen, which drops the slider mid-drag'
        );

    // The repaint swaps the one node, which is what makes it safe to do per keystroke.
    expect($screen)->toContain("document.querySelector('[data-igs-preview]')")
        ->and($screen)->toContain('node.replaceWith(holder.firstChild)')
        /* And render() MOUNTS it, under the controls and on both tabs. Without this
           line the preview exists as a function nothing ever calls, paintPreview()
           finds no node and returns, and every other assertion in this file still
           passes — which is precisely the shape of "built, never wired up" CLAUDE.md
           says this repo keeps finding. */
        ->and($screen)->toContain("+ (current ? previewHTML() : '')");
});

it('draws the preview from the controls on screen and never from what is saved', function () {
    /*
     * A preview of the SAVED settings is a picture of the page the owner can already
     * go and look at. The whole value is in showing the unsaved state, so
     * previewHTML() reads `values` — the live control state — and reads the module
     * settings nowhere.
     *
     * It is also why there is no query string anywhere in this: the first draft of
     * this feature's camera asked the SHOP for `?ig_layout=rail`, and
     * tools/ig-shots.cjs's header records why that was rejected — a setting taken
     * from a request is rule 5's own example, pointed at the thing that decides what
     * to render.
     *
     * MUTATION NOTE. Change `pick('layout', ...)` to read from a fetched payload and
     * this is red: previewHTML() no longer names `values`. RUN: red.
     */
    $screen = igpScreen();

    $start = strpos($screen, 'function previewHTML()');
    $body = substr($screen, (int) $start, 3200);

    /* The five that go through pick() or pvNum(), which are the ones with a set of
       allowed values or a range to be clamped to. The other four are read straight
       off `values` below, because a checkbox and a free-text heading have neither. */
    foreach (['layout', 'profile_style', 'posts', 'gap', 'radius', 'tap'] as $key) {
        expect($body)->toContain("'".$key."'");
    }

    expect($body)->toContain("values.heading")
        ->and($body)->toContain("values.counts === true")
        ->and($body)->toContain("values.caption === true")
        ->and($body)->toContain("values.play_badge === true")
        // No request of its own, on any repaint.
        ->and(str_contains($body, 'fetch('))->toBeFalse()
        ->and(str_contains($body, 'api('))->toBeFalse();
});

it('previews every layout and every profile style the screen offers a name for', function () {
    /*
     * A dropdown entry with no rule behind it renders as the default and reads as a
     * bug in the saving. The section's own stylesheet is already pinned this way by
     * InstagramSectionShapeTest; this is the same assertion for the DRAWING, because
     * a preview missing one layout is worse than no preview — the owner picks
     * "Mosaic", sees a plain grid, and concludes the setting does not work.
     *
     * MUTATION NOTE. Add a sixth key to InstagramSettings::LAYOUTS without an
     * `.igs-pvt.is-<key>` rule and this is red naming it. RUN: red.
     */
    $screen = igpScreen();

    foreach (array_keys(InstagramSettings::LAYOUTS) as $key) {
        expect($screen)->toContain('.igs-pvt.is-'.$key);
    }

    foreach (array_keys(InstagramSettings::PROFILE_STYLES) as $key) {
        // `off` draws nothing and `inline` puts the avatar in the grid, so neither
        // has a box rule of its own — exactly as on the shop.
        if (in_array($key, ['off', 'inline'], true)) {
            continue;
        }

        expect($screen)->toContain('.igs-pvp.is-'.$key);
    }
});

it('sizes the preview with the same arithmetic the section is sized with', function () {
    /*
     * ── TWO COPIES OF ONE EXPRESSION, DELIBERATELY, AND PINNED ──────────────
     *
     * A peeking rail shows 2.3 tiles on a phone and therefore carries 1.3 gaps; the
     * slim strip shows 4.5 and carries 3.5. InstagramSettings::cssVariables() carries
     * the reason at length — "n tiles carry (n-1) whole gaps plus the fraction of one
     * belonging to the partly visible tile, and getting that wrong is how a rail
     * overflows by 24px".
     *
     * Two copies of one piece of arithmetic is a real cost. Two DIFFERENT pieces of
     * arithmetic between a preview and the thing it previews is a worse one: the
     * preview would be believed. So the fractions are asserted to match, and the two
     * breakpoints with them.
     *
     * MUTATION NOTE. Change the preview's rail divisor from 2.3 to 3 and this is red.
     * RUN: red.
     */
    $screen = igpScreen();
    $shop = InstagramSettings::cssVariables(['layout' => 'rail', 'gap' => 10, 'radius' => 4]);
    $strip = InstagramSettings::cssVariables(['layout' => 'strip', 'gap' => 10, 'radius' => 4]);

    // The shop's own numbers at gap 10, so this reads the expression rather than
    // restating it: 1.3 * 10 = 13 and 3.5 * 10 = 35.
    expect($shop)->toContain('/ 2.3')
        ->and($strip)->toContain('/ 4.5')
        ->and($screen)->toContain("'calc((100% - ' + (gap * 1.3) + 'px) / 2.3)'")
        ->and($screen)->toContain("'calc((100% - ' + (gap * 3.5) + 'px) / 4.5)'");

    // The same two breakpoints, as @container rules — the preview is a box inside a
    // console, so the width that decides its arrangement is the box's and never the
    // window's. @media here draws the desktop grid inside a 340px frame.
    expect($screen)->toContain('@container (min-width:640px)')
        ->and($screen)->toContain('@container (min-width:900px)')
        ->and($screen)->toContain('container-type:inline-size');
});

it('says a placeholder is a placeholder rather than drawing an empty box', function () {
    /*
     * A shop that has fetched nothing gets a grid of ghosts at the size and spacing
     * its settings ask for, and a sentence saying exactly that. The alternative — an
     * empty box, or nine grey squares with no caption — is the state this screen was
     * in before the preview existed, reproduced inside the preview: the owner cannot
     * tell "nothing fetched" from "broken".
     *
     * MUTATION NOTE. Delete the `is-ghost` branch from pvTile() and this is red. RUN:
     * red.
     */
    $screen = igpScreen();

    expect($screen)->toContain('is-ghost')
        ->and($screen)->toContain('<b>These are placeholders.</b>')
        // And it is a wash with a camera glyph rather than a blank grey square, so it
        // does not read as a picture that failed to load.
        ->and($screen)->toContain('repeating-linear-gradient(135deg');
});

it('says how many posts are STORED and how many are drawn, which are different numbers', function () {
    /*
     * ── THE BUG THE 390px PICTURE CAUGHT ────────────────────────────────────
     *
     * The note under the preview was passed ONE number, `Math.min(stored, cap)`, and
     * printed it as "Drawn from the 6 posts this shop has already stored" on a shop
     * holding NINE with the slider at six. That is the screen telling the owner he
     * has lost three posts — the exact opposite of what the preview is for, and the
     * sort of sentence that gets Refresh posts pressed until somebody's Meta rate
     * limit is spent looking for posts that were never missing.
     *
     * So pvNote takes both, and there are two separate sentences for the two
     * situations, which genuinely differ in what the owner should do: more stored
     * than drawn is a setting (move the slider), fewer stored than the setting is a
     * fetch (press Refresh posts).
     *
     * MUTATION NOTE. Change the call back to
     * `pvNote(cells.length, real.length ? Math.min(real.length, cap) : 0, cap, tap)`
     * and this is red on the first expectation. RUN: red — it is the state the
     * screenshot was taken in.
     */
    $screen = igpScreen();

    expect($screen)->toContain('pvNote(cells.length, real.length, cap, tap)')
        ->and($screen)->toContain('function pvNote(drawn, stored, cap, tap)')
        // More stored than the slider asks for: nothing is lost, and it says so.
        ->and($screen)->toContain('if (drawn < stored) {')
        ->and($screen)->toContain('of them are drawn and the rest are kept')
        // Fewer stored than the slider asks for: that one IS a fetch, and the remedy
        // named is the other button.
        ->and($screen)->toContain('if (stored < cap) {')
        ->and($screen)->toContain('Press <b>Refresh posts</b> to fetch more.');
});

/* ────────────────────── gap 2: Configure now, in a popup ──────────────────── */

it('checks where a message came from before it reads one byte of it', function () {
    /*
     * ── THE ORIGIN CHECK, AND THAT IT IS THE FIRST LINE ─────────────────────
     *
     * A handler that reads `event.data` before it has established who sent it is a
     * handler any page with a handle on this window can talk to. What it would be
     * telling this admin is that the shop is connected to Instagram when it is not,
     * which is a lie the owner acts on — he stops setting it up.
     *
     * So two things are asserted and the second is the one a review would miss: the
     * comparison EXISTS, and it comes BEFORE the data is looked at. An origin check
     * written after the payload has been parsed protects nothing.
     *
     * MUTATION NOTE. Delete the `if (e.origin !== window.location.origin) return;`
     * line and this is red on the first expectation; move it below the `e.data` line
     * and it is red on the ordering one. RUN: red on both.
     */
    $screen = igpScreen();

    expect($screen)->toContain('if (e.origin !== window.location.origin) return;');

    $listener = strpos($screen, "window.addEventListener('message'");
    expect($listener)->not->toBeFalse();

    $origin = strpos($screen, 'e.origin !== window.location.origin', (int) $listener);
    $data = strpos($screen, 'e.data !==', (int) $listener);

    expect($origin)->not->toBeFalse()
        ->and($data)->not->toBeFalse()
        ->and($origin < $data)->toBeTrue('the payload is read before the origin is checked');
});

it('sends one constant word between the windows and never a credential', function () {
    /*
     * ── NOTHING SENSITIVE CROSSES, AND NOTHING AT ALL CROSSES ───────────────
     *
     * The token is exchanged and stored server-side by InstagramSync, so there was
     * never a reason for it to travel — and the message is therefore a bare literal
     * with no payload to get wrong and nothing to validate. The screen then ASKS
     * this server what the state is, over its own authenticated read.
     *
     * `window.location.origin` as the target, never '*'. A '*' broadcasts to whatever
     * the opener has since navigated to; the word itself is harmless, the habit is
     * not, and this is the line somebody copies onto the next feature.
     *
     * MUTATION NOTE. Change the post to `postMessage({ token: t }, '*')` and this is
     * red three times: the constant is gone, the '*' expectation trips, and the
     * object expectation trips. RUN: red.
     */
    $screen = igpScreen();

    expect($screen)->toContain("var OAUTH_DONE = 'kbb-instagram-oauth-done';")
        ->and(substr_count($screen, 'postMessage('))->toBe(1)
        ->and($screen)->toContain('window.opener.postMessage(OAUTH_DONE, window.location.origin)')
        // No wildcard target, and no object literal riding along with it.
        ->and(str_contains($screen, "postMessage(OAUTH_DONE, '*')"))->toBeFalse()
        ->and(preg_match('/postMessage\(\s*\{/', $screen))->toBe(0);

    // And having received it, the screen asks the server rather than believing the
    // window: afterOauth() reads /admin-api/instagram and decides from `connection`.
    $after = strpos($screen, 'async function afterOauth()');
    $body = substr($screen, (int) $after, 1600);

    expect($after)->not->toBeFalse()
        ->and($body)->toContain('await load();')
        ->and($body)->toContain('connection && connection.connected');
});

it('keeps the anchor as the blocked-popup fallback and prevents nothing until a window exists', function () {
    /*
     * ── THE ORDER OF THREE LINES IS THE WHOLE OF THE FALLBACK ───────────────
     *
     * window.open first; if it returned a window, and only then, preventDefault().
     * A blocked popup returns null, nothing is prevented, and the browser follows
     * the href exactly as it did before this handler existed — so the owner finishes
     * in this tab and reads Instagram's own sentence off the landing banner.
     *
     * Written the other way round, a blocked popup is a button that does nothing at
     * all, with no way for the owner to tell that from a broken one. This asserts the
     * ordering rather than the existence, because the existence is not the property
     * that matters.
     *
     * The anchor's href also stays exactly one: two would be two things to keep in
     * step with the route, and zero is the state this gap was the other half of.
     *
     * MUTATION NOTE. Move `e.preventDefault();` above `openPopup(...)` in the
     * `data-igs-oauth` branch and this is red on the ordering. Delete the `<a ...>`
     * in favour of a <button> and it is red on the href count. RUN: red.
     */
    $screen = igpScreen();

    $branch = strpos($screen, "var oauth = t.closest('[data-igs-oauth]');");
    expect($branch)->not->toBeFalse();

    $body = substr($screen, (int) $branch, 1400);
    $modified = strpos($body, 'e.metaKey || e.ctrlKey || e.shiftKey || e.altKey');
    $opened = strpos($body, 'openPopup(');
    $guard = strpos($body, 'if (!win) return;');
    $prevented = strpos($body, 'e.preventDefault();');

    expect($modified)->not->toBeFalse()
        ->and($opened)->not->toBeFalse()
        ->and($guard)->not->toBeFalse()
        ->and($prevented)->not->toBeFalse()
        /* A MODIFIED CLICK IS HANDED BACK TO THE BROWSER, and it is checked first:
           Ctrl, Cmd, Shift and Alt on a link mean "open it somewhere else", and this
           would otherwise be the one control in the console that ignores them. */
        ->and($modified < $opened)->toBeTrue('a Ctrl-click is swallowed into a popup')
        ->and($opened < $guard)->toBeTrue('the popup is prevented before it is attempted')
        ->and($guard < $prevented)->toBeTrue('a blocked popup still calls preventDefault, so the anchor is dead');

    /* The real link survives, exactly once, pointing at the real route and built
       from base() rather than from a constant — the admin path is a setting. Counted
       as the ATTRIBUTE and not as the path, because the docblock above it names the
       path in prose. */
    expect(substr_count($screen, "href=\"' + esc(base()) + '/instagram/start\""))->toBe(1);

    // And the reasoning in the docblock survives too, because it is right: what it
    // rules out is an XHR, which a popup is not.
    expect($screen)->toContain('AN XHR CANNOT LOG ANYBODY IN TO ONE');
});

it('leaves no timer running once the popup is gone', function () {
    /*
     * ── THE ONE PIECE OF POLLING, AND ITS THREE WAYS TO STOP ────────────────
     *
     * There is no event for "the owner closed the popup", so the only way to notice
     * an abandoned handshake is to look. "No polling loop left running after the
     * popup closes" is met by the loop having no way to survive:
     *
     *   · the popup reports closed  — cleared, then the state is re-read once;
     *   · the message arrives       — the listener calls endWatch() first;
     *   · 900 seconds pass          — InstagramAuth::STATE_TTL_SECONDS, after which
     *                                 the callback refuses anyway.
     *
     * And watchPopup() opens with endWatch(), so pressing Configure twice leaves one
     * timer and not two.
     *
     * MUTATION NOTE. Delete the `endWatch();` at the top of watchPopup() and the
     * "starts by clearing" expectation is red; delete it from the message listener
     * and the listener expectation is red. RUN: red on both.
     */
    $screen = igpScreen();

    expect(substr_count($screen, 'setInterval('))->toBe(1)
        ->and($screen)->toContain('window.clearInterval(popupWatch); popupWatch = null;');

    $watch = strpos($screen, 'function watchPopup() {');
    expect($watch)->not->toBeFalse();

    $body = substr($screen, (int) $watch, 900);

    expect(trim(substr($body, strpos($body, '{') + 1, 40)))->toStartWith('endWatch();');
    expect($body)->toContain('if (gone) {')
        ->and($body)->toContain('endWatch();')
        // The cap is the state's own lifetime and not a number invented here.
        ->and($body)->toContain('waited >= 900 * 1000')
        ->and(InstagramAuth::STATE_TTL_SECONDS)->toBe(900);

    // The listener stops it before it does anything else.
    $listener = strpos($screen, "window.addEventListener('message'");
    $after = substr($screen, (int) $listener, 700);

    expect($after)->toContain('endWatch();')
        ->and(strpos($after, 'endWatch();') < strpos($after, 'afterOauth();'))->toBeTrue();
});

it('does not paint the Instagram screen into a console showing something else', function () {
    /*
     * A popup can be open while the owner wanders off to Orders. The handshake
     * finishes minutes later, the listener fires, and load() would paint an Instagram
     * screen into a #content that now belongs to another module — which looks to the
     * owner like the console jumping on its own.
     *
     * `data-igs-screen` is on this screen's wrapper in EVERY render path, so its
     * absence is the answer. A presence test and not a measurement: rule 4 forbids
     * the element-measuring APIs in this file and InstagramSectionShapeTest holds it
     * to that by name.
     *
     * MUTATION NOTE. Remove `data-igs-screen` from either render path and this is red
     * on the count of 2. RUN: red.
     */
    $screen = igpScreen();

    /* The two render paths, counted as the attribute as it is WRITTEN into the
       markup — `data-igs-screen>` — so the two prose mentions and the selector do
       not inflate it. */
    expect(substr_count($screen, 'data-igs-screen>'))->toBe(2)
        ->and($screen)->toContain("return document.querySelector('[data-igs-screen]') !== null;")
        // Both the guard at the top of afterOauth() and the one after the await: the
        // owner can leave the screen DURING the read.
        ->and(substr_count($screen, 'if (!onScreen()) return;'))->toBe(2);
});

/* ─────────────────────── the state, end to end through the route ──────────── */

it('exchanges nothing and stores nothing when the callback’s state does not match', function () {
    /*
     * ── THE CSRF DEFENCE, ASSERTED THROUGH THE REAL ROUTE ───────────────────
     *
     * InstagramAuth::consume() was already unit-tested three ways (empty on both
     * sides, replayed, expired) and it was already correct — the brief asked this
     * lane to CHECK rather than to assume, and the answer is that it verifies the
     * state, spends it by looking at it, and compares with hash_equals after
     * checking both sides are non-empty.
     *
     * What nothing covered is the END of that: that a refused callback never reaches
     * Meta. A handshake that verified the state and exchanged the code anyway would
     * pass all three of those unit tests. So this drives the real route with a real
     * session and asserts THREE things at once — no outbound request was made, no
     * token was stored, and the state is gone from the session either way.
     *
     * The popup makes this worse if it is wrong, which is why it is pinned now: a
     * forged callback in a popup is one the owner never sees the address bar of.
     *
     * MUTATION NOTE. Delete the `if (! ($check['ok'] ?? false))` return from
     * InstagramController::callback() and this is red on the very first expectation —
     * Http::assertNothingSent(). RUN: red.
     */
    InstagramAdminRoutes::wire($this->app);
    Http::fake();

    InstagramCredentials::saveApp('1234567890123456', 'abcdef0123456789abcdef0123456789');
    $this->actingAs(igpAdmin(), 'admin');

    // A state really is minted, so the refusal below is about the MISMATCH and not
    // about there being nothing to compare against.
    $this->get('/admin-api/instagram/start')->assertRedirect();
    expect(session()->has(InstagramAuth::STATE_SESSION_KEY))->toBeTrue();

    $this->get('/admin-api/instagram/callback?code=a-real-looking-code&state=not-the-one-we-minted')
        ->assertRedirect();

    Http::assertNothingSent();

    expect(InstagramCredentials::hasToken())->toBeFalse()
        // Spent by being looked at, so the URL cannot be replayed with the right one.
        ->and(session()->has(InstagramAuth::STATE_SESSION_KEY))->toBeFalse();
});

it('never lets the app secret reach the browser, on any of the screen’s five answers', function () {
    /*
     * ── THE LEAK THAT WOULD MATTER MOST, ON EVERY DOOR AND NOT ONE ──────────
     *
     * The existing test checks GET /admin-api/instagram. But every write on this
     * screen returns `connection` too — saveApp(), save(), refresh() and disconnect()
     * all do, so that the screen can redraw without a second read. Five answers, one
     * of which could carry it, and the one a later edit adds a field to is the one
     * nobody re-checks.
     *
     * Asserted BY VALUE and not by key name, because a key rename is exactly how a
     * leak survives a test that reads keys.
     *
     * MUTATION NOTE. Add `'secret' => InstagramCredentials::secret()` to
     * InstagramController::connection() and this is red on all five. RUN: red.
     */
    InstagramAdminRoutes::wire($this->app);
    Http::fake(['*' => Http::response(['error' => ['message' => 'no']], 400)]);

    $secret = 'abcdef0123456789abcdef0123456789';
    InstagramCredentials::saveApp('1234567890123456', $secret);
    InstagramCredentials::saveToken('a-very-long-lived-token', 60 * 86400, '17841400000000000');

    $this->actingAs(igpAdmin(), 'admin');
    igpPost();

    $answers = [
        'read' => $this->getJson('/admin-api/instagram')->getContent(),
        'save' => $this->postJson('/admin-api/instagram', ['settings' => ['layout' => 'rail']])->getContent(),
        'app' => $this->postJson('/admin-api/instagram/app', ['app_id' => '1234567890123456'])->getContent(),
        'refresh' => $this->postJson('/admin-api/instagram/refresh', [])->getContent(),
        'disconnect' => $this->deleteJson('/admin-api/instagram')->getContent(),
    ];

    foreach ($answers as $which => $body) {
        expect(str_contains((string) $body, $secret))->toBeFalse("the {$which} answer carries the app secret")
            ->and(str_contains((string) $body, 'a-very-long-lived-token'))->toBeFalse(
                "the {$which} answer carries the access token"
            );
    }

    // And the screen never asks for one: it reads `secret_saved`, a boolean, and the
    // secret box is drawn EMPTY with a placeholder rather than holding asterisks.
    $screen = igpScreen();

    expect($screen)->toContain('c.secret_saved')
        ->and(preg_match('/connection\.(secret|token)\b/', $screen))->toBe(0)
        ->and(preg_match('/c\.(secret|token)\b/', $screen))->toBe(0);
});
