<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\UgcSettings;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Cache;

/**
 * Content → Shoppable video → Sections, and Appearance → Video rail.
 *
 * "when i create new section, it should give me proper list of inside videos, and
 * each videos will have popup edit options with full controls, products selection,
 * and upload videos or enter instagram, tiktok urls."
 *
 * The popup's own fields post to the endpoints the PREVIOUS round already shipped
 * (UgcVideoController) — there is deliberately no second video editor here,
 * because a second one is a second set of validation rules that drift apart. What
 * this round adds is the sections above them.
 */
function secUser(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Sec '.$role,
        'email' => 'sec-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function secVideo(string $slug): UgcVideo
{
    return UgcVideo::query()->create([
        'slug' => $slug,
        'title' => 'Clip '.$slug,
        'status' => 'publish',
        'rights_status' => 'granted',
        'file_path' => '/uploads/ugc/clip-'.$slug.'.mp4',
        'poster_path' => '/uploads/ugc/poster-'.$slug.'.webp',
        'width' => 360,
        'height' => 640,
    ]);
}

it('maps every new endpoint to a capability, with the writes above the reads', function () {
    /*
     * AdminCapabilities::RULES is FIRST-MATCH-WINS, so the order between a
     * `GET admin-api/ugc-sections/**` rule and a `POST admin-api/ugc-sections/**`
     * one IS the access control. POST /ugc-sections/7/videos reorders a rail on the
     * live shop; resolved to `ugc.view` it would be a read-only account rearranging
     * the homepage.
     *
     * That exact fault has two names in this repository already — the quiz-leads
     * and coupons/manage rules AdminCapabilities names in its own comments — and
     * both were found by a test rather than by a reader.
     *
     * MUTATION NOTE. Move the two `GET admin-api/ugc-sections...` lines ABOVE the
     * five write lines in AdminCapabilities::RULES and this is red: the reorder and
     * the delete both resolve to ugc.view. RUN: red on four of the six.
     */
    expect(AdminCapabilities::forPath('POST', 'admin-api/ugc-sections'))->toBe('ugc.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/ugc-sections/7/videos'))->toBe('ugc.manage')
        ->and(AdminCapabilities::forPath('PUT', 'admin-api/ugc-sections/7'))->toBe('ugc.manage')
        ->and(AdminCapabilities::forPath('DELETE', 'admin-api/ugc-sections/7'))->toBe('ugc.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/ugc-appearance'))->toBe('ugc.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/ugc-sections'))->toBe('ugc.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/ugc-sections/7'))->toBe('ugc.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/ugc-appearance'))->toBe('ugc.view');
});

it('refuses a signed-out caller on every one of them', function (string $method, string $url) {
    /*
     * Not a nicety: GET /admin-api/ugc-sections returns a 300-row index of the
     * video library, and POST /ugc-sections/{id}/videos rewrites what the shop
     * shows. `/api/*` is unauthenticated on this shop and NONE of this belongs
     * there.
     *
     * MUTATION NOTE. Move the new Route:: declarations in routes/ugc-admin.php
     * outside the admin-api group — which is what a second route file required at
     * the top level of routes/web.php would have done — and every one of these is
     * red with a 200. RUN: red.
     */
    $this->{$method === 'GET' ? 'getJson' : ($method === 'DELETE' ? 'deleteJson' : ($method === 'PUT' ? 'putJson' : 'postJson'))}($url)
        ->assertStatus(401);
})->with([
    ['GET', '/admin-api/ugc-sections'],
    ['POST', '/admin-api/ugc-sections'],
    ['GET', '/admin-api/ugc-sections/1'],
    ['PUT', '/admin-api/ugc-sections/1'],
    ['DELETE', '/admin-api/ugc-sections/1'],
    ['POST', '/admin-api/ugc-sections/1/videos'],
    ['GET', '/admin-api/ugc-appearance'],
    ['POST', '/admin-api/ugc-appearance'],
]);

it('generates a handle from the title and then never moves it', function () {
    /*
     * THE HANDLE IS THE SHORTCODE. Regenerating it when the title is edited would
     * silently break every page the owner has already pasted
     * `[kbb_videos section="..."]` into — and those pages would then render NOTHING
     * AT ALL, because that is what an unresolvable shortcode correctly does. A
     * stale-looking handle is a far smaller problem than a rail that vanished from
     * four pages when somebody fixed a typo in a heading.
     *
     * MUTATION NOTE. Remove the `if ((string) $section->handle === '')` guard in
     * UgcSectionController::write() so the handle is regenerated on every save, and
     * this is red: the handle becomes `renamed-after-the-shortcode-was-pasted`.
     * RUN: red.
     */
    $this->actingAs(secUser(), 'admin');

    $created = $this->postJson('/admin-api/ugc-sections', ['title' => 'Homepage hero rail'])
        ->assertStatus(201)
        ->json('section');

    expect($created['handle'])->toBe('homepage-hero-rail')
        ->and($created['shortcode'])->toBe('[kbb_videos section="homepage-hero-rail"]')
        ->and($created['status'])->toBe('draft');   // ships off, like everything here

    $renamed = $this->putJson('/admin-api/ugc-sections/'.$created['id'], [
        'title' => 'Renamed after the shortcode was pasted',
    ])->assertOk()->json('section');

    expect($renamed['handle'])->toBe('homepage-hero-rail');
});

it('gives a title that slugs to nothing a handle it can still use', function () {
    /*
     * An Arabic-only title slugs to the EMPTY STRING — Str::slug transliterates
     * nothing outside its map — and an empty handle is a shortcode that can never
     * resolve. UgcVideoController::slug() already makes the same fallback for a
     * clip; this follows it.
     *
     * MUTATION NOTE. Drop the `$base === ''` arm from
     * UgcSectionController::handle() and this is red: the row saves with handle ''
     * and the shortcode reads `[kbb_videos section=""]`. RUN: red.
     */
    $this->actingAs(secUser(), 'admin');

    /*
     * ▲ NOT AN ARABIC TITLE, AND THE MUTATION RUN IS WHY. This case used
     * 'روتين الصباح' on the reasoning that Str::slug transliterates nothing outside
     * its map — and it was simply wrong: Str::ascii DOES carry an Arabic map and
     * that title slugs to 'rotyn-alsbah'. So removing the empty-slug fallback left
     * this case GREEN, because nothing here ever reached it. Measured:
     * Str::slug('روتين الصباح') === 'rotyn-alsbah', Str::slug('♥♥♥') === ''.
     *
     * A title of punctuation or symbols is what actually slugs to nothing, and it
     * is not contrived: "♥♥♥" and "!!!" are the kind of thing somebody types into a
     * name field while deciding what to call a rail.
     */
    expect(\Illuminate\Support\Str::slug('♥♥♥'))->toBe('');

    $section = $this->postJson('/admin-api/ugc-sections', ['title' => '♥♥♥'])
        ->assertStatus(201)->json('section');

    expect($section['handle'])->not->toBe('')
        ->and($section['handle'])->toMatch(UgcSection::HANDLE_RE)
        ->and($section['shortcode'])->not->toContain('section=""');

    // And an Arabic title keeps its transliteration rather than being thrown away.
    $arabic = $this->postJson('/admin-api/ugc-sections', ['title' => 'روتين الصباح'])
        ->assertStatus(201)->json('section');

    expect($arabic['handle'])->toBe('rotyn-alsbah');
});

it('stores a column choice only from its own vocabulary', function () {
    /*
     * Rule 5: a select stores one of its own options or the default. `columns` ends
     * up in a `calc()` on the section element, so a value from outside that set is
     * a value inside a style attribute.
     *
     * MUTATION NOTE. Replace the Rule::in in UgcSectionController::write() with
     * 'nullable|string' and this is red: the 422 becomes a 200 and the column reads
     * `1;background:url(x)`. RUN: red.
     */
    $this->actingAs(secUser(), 'admin');

    $id = $this->postJson('/admin-api/ugc-sections', ['title' => 'Cols'])->json('section.id');

    $this->putJson('/admin-api/ugc-sections/'.$id, ['title' => 'Cols', 'columns' => 'two'])
        ->assertOk()->assertJsonPath('section.columns', 'two');

    $this->putJson('/admin-api/ugc-sections/'.$id, ['title' => 'Cols', 'columns' => '1;background:url(x)'])
        ->assertStatus(422);

    expect(UgcSection::query()->whereKey($id)->value('columns'))->toBe('two')
        ->and(array_keys(UgcSettings::COLUMNS))->toContain('two');
});

it('sets the whole order in one write, dropping ids that are not clips', function () {
    /*
     * ONE sync(), NOT A LOOP. The whole list arrives and replaces the whole list,
     * with `position` taken from the array index — a per-row PATCH would let two
     * drags interleave into an order neither operator asked for, and the pivot's
     * unique index would make that a 500 halfway through rather than a refusal.
     *
     * A duplicate is COLLAPSED and a non-existent id is DROPPED rather than
     * refused: a clip deleted in another tab must not make saving the order
     * impossible, and a double-click must not become a constraint violation the
     * operator cannot act on.
     *
     * MUTATION NOTE. Remove the array_unique() and this is red with a
     * UniqueConstraintViolationException rather than a 200. Remove the whereIn
     * check against the real ids and it is red with a foreign-key violation. RUN:
     * both.
     */
    $this->actingAs(secUser(), 'admin');

    $a = secVideo('order-a');
    $b = secVideo('order-b');
    $c = secVideo('order-c');

    $id = $this->postJson('/admin-api/ugc-sections', ['title' => 'Ordered'])->json('section.id');

    $this->postJson('/admin-api/ugc-sections/'.$id.'/videos', [
        'videos' => [$c->id, $a->id, $a->id, 999999, $b->id],
    ])->assertOk()->assertJsonPath('count', 3);

    $order = UgcSection::query()->findOrFail($id)->videos()->pluck('slug')->all();

    expect($order)->toBe(['order-c', 'order-a', 'order-b']);

    /*
     * ▲ AND THE DUPLICATE AT THE END, which is the case that actually pins
     * array_unique(). The mutation run found this: with array_unique() removed the
     * list above still came out [c, a, b], because sync() is handed an array KEYED
     * by video id and the second `a` simply overwrites the first — at a HIGHER
     * position, which in that particular order happens not to move anything.
     *
     * Put the duplicate last and it does move: [a, b, a] becomes a=0, b=1, a=2,
     * so the order is [b, a] — the operator's first choice pushed behind their
     * second by a double-click. RUN: red with array_unique() removed.
     */
    $this->postJson('/admin-api/ugc-sections/'.$id.'/videos', [
        'videos' => [$a->id, $b->id, $a->id],
    ])->assertOk()->assertJsonPath('count', 2);

    expect(UgcSection::query()->findOrFail($id)->videos()->pluck('slug')->all())
        ->toBe(['order-a', 'order-b']);
});

it('deletes the section and not one frame of video', function () {
    /*
     * THE WHOLE REASON THIS IS A PIVOT. Deleting "the homepage rail" must not delete
     * the sunscreen clip that is also on the sun-care page, and must not touch a
     * file on disk. `ugc_section_video` cascades on the SECTION's id and the videos
     * stay in the library and in every other section they belong to.
     *
     * MUTATION NOTE. Add `$section->videos()->each->delete();` before the delete()
     * in UgcSectionController::destroy() — the shape somebody reaches for when they
     * think of a section as owning its clips — and this is red: the clip is gone
     * from the library and from the OTHER section as well. RUN: red.
     */
    $this->actingAs(secUser(), 'admin');

    $shared = secVideo('shared');

    $one = $this->postJson('/admin-api/ugc-sections', ['title' => 'Rail one'])->json('section.id');
    $two = $this->postJson('/admin-api/ugc-sections', ['title' => 'Rail two'])->json('section.id');

    foreach ([$one, $two] as $id) {
        $this->postJson('/admin-api/ugc-sections/'.$id.'/videos', ['videos' => [$shared->id]])->assertOk();
    }

    $this->deleteJson('/admin-api/ugc-sections/'.$one)->assertOk();

    expect(UgcSection::query()->whereKey($one)->exists())->toBeFalse()
        ->and(UgcVideo::query()->whereKey($shared->id)->exists())->toBeTrue()
        ->and(UgcSection::query()->findOrFail($two)->videos()->count())->toBe(1);
});

it('tells the operator on the screen that the module is off', function () {
    /*
     * The single most likely support call this feature can generate: "I made a
     * section and nothing shows on the shop." The module ships OFF, a rail renders
     * the empty string while it is, and both admin endpoints say so in their own
     * payload so the screen can put it in the header rather than leaving the owner
     * to guess.
     *
     * MUTATION NOTE. Remove `'module_on' => ...` from either payload and the screen
     * draws no warning at all — this case is red, and the picture in
     * docs/ugc-rail-shots is what the owner would have been looking at.
     */
    $this->actingAs(secUser(), 'admin');

    $this->getJson('/admin-api/ugc-sections')->assertOk()->assertJsonPath('module_on', false);
    $this->getJson('/admin-api/ugc-appearance')->assertOk()->assertJsonPath('module_on', false);

    app(\App\Services\SettingsService::class)->setModule('shoppable_video', true);
    \App\Services\SettingsService::forgetMemo();
    Cache::flush();

    $this->getJson('/admin-api/ugc-sections')->assertOk()->assertJsonPath('module_on', true);
});

it('draws a control for every setting the module stores, and stores every one it draws', function () {
    /*
     * The guarantee ModuleSchema exists to make, read off the ENDPOINT rather than
     * the constant — which is the difference between "a constant lists this key"
     * and "the owner is shown a box for it", and the two that came apart on
     * `reassure_auth_text`: it looked settings-driven for its whole life and was
     * not, so the shipped default was the only value that ever printed, at the
     * moment of payment.
     *
     * MUTATION NOTE. Remove 'likes_on' from UgcSettings::TABS while leaving it in
     * SCHEMA — the exact shape of that old fault — and this is red: the schema
     * stores a value the screen never draws. RUN: red.
     */
    $this->actingAs(secUser(), 'admin');

    $tabs = $this->getJson('/admin-api/ugc-appearance')->assertOk()->json('tabs');

    $drawn = [];

    foreach ($tabs as $tab) {
        foreach ($tab['fields'] as $field) {
            $drawn[] = $field['key'];

            expect($field['type'])->not->toBe('', "{$field['key']} renders with no type");
            expect($field)->toHaveKey('value');
        }
    }

    $stored = array_keys(\App\Services\ModuleSchema::normalise(UgcSettings::SCHEMA, UgcSettings::POLICY));

    sort($drawn);
    sort($stored);

    expect($drawn)->toBe($stored);
});

it('ships every setting at the value R3 already draws', function () {
    /*
     * RULE 1, and it is the rule this round is judged on: "Any NEW setting ships at
     * the value the page already has, so applying the package moves nothing until
     * somebody moves a slider." The page has nothing today, so the value it "has"
     * is the measured geometry of R3 — the design the owner chose and then asked to
     * match exactly.
     *
     * MUTATION NOTE. Change `tile_w`'s default to 170 and this is red. It is also
     * red in docs/ugc-rail-shots/r3-compare.json, at `cell.width 158 vs 170`, which
     * is the same fact measured in a browser. RUN: red in both.
     */
    $defaults = array_map(
        fn ($f) => $f['default'],
        \App\Services\ModuleSchema::normalise(UgcSettings::SCHEMA, UgcSettings::POLICY)
    );

    expect($defaults)->toBe([
        // R3's measured rail: a 158px tile at 390px, 206px from 900px, 12px gap,
        // the 16px medium radius.
        'cols' => 'peek',
        'tile_w' => 158,
        'desk_tile' => 206,
        'gap' => 12,
        'radius' => 16,
        // The loop the owner asked for, on, at the measured 2.5s.
        'teaser' => true,
        'teaser_ms' => 2500,
        'max_playing' => 4,
        'autoplay_open' => true,
        'controls' => true,
        // Muted, because a rail that makes noise on a tap is the thing people leave
        // a page over — and because muted is what lets autoplay happen at all.
        'sound_on_open' => false,
        // The rating bar: ON, and inside the product box, which is the one change
        // to R3 he asked for.
        'rating' => true,
        'caption' => true,
        'handle' => true,
        // R3 draws NO count badge and NO like button, so both ship off.
        'badge' => false,
        'strike' => true,
        'likes_on' => false,
    ]);
});

it('reports a refused value rather than dropping it in silence', function () {
    /*
     * A save that quietly discarded a bad number is the fault ModuleSchema exists to
     * remove. A slider outside its range is CLAMPED (that is this module's declared
     * policy point) and an unrecognised select becomes the default — and either way
     * the response says what happened.
     *
     * MUTATION NOTE. Change UgcSettings::POLICY's `invalid` from 'default' to
     * 'reject' and this is red: `cols` comes back in `rejected` rather than being
     * stored as 'peek'. That is a legitimate other choice and the test names which
     * one this module made. RUN: red.
     */
    $this->actingAs(secUser(), 'admin');

    $this->postJson('/admin-api/ugc-appearance', ['settings' => [
        'cols' => 'not-an-option',
        'gap' => 9999,
    ]])->assertOk()->assertJsonPath('rejected', []);

    $values = app(UgcSettings::class)->all();

    expect($values['cols'])->toBe('peek')      // the default, not the junk
        ->and($values['gap'])->toBe(28);        // clamped to the bound, not refused

    // An unknown key is refused outright rather than stored.
    $this->postJson('/admin-api/ugc-appearance', ['settings' => ['not_a_setting' => 1]])
        ->assertStatus(422);
});
