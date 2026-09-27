<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\UgcVideo;

/**
 * When a clip may be shown, and the one requirement that is deliberately absent.
 *
 * ── THE TEASER IS NOT A PRECONDITION, AND THAT IS THE POINT ─────────────────
 *
 * docs/UGC-VIDEO-PLAN.md §0b.5: "publication should not require the teaser — a
 * video with no teaser yet shows its poster, which is the Save-Data behaviour
 * and is already drawn." §8 question 4 — does ffmpeg exist on that Cloudways
 * box — has never been answered, and on a box where it does not, EVERY video is
 * teaser-less. A teaser precondition would therefore be a feature that cannot
 * be used at all until somebody types eight characters into an SSH session.
 *
 * ── RIGHTS FAIL CLOSED ──────────────────────────────────────────────────────
 *
 * §3.3: this shop re-hosts a creator's video, the creator owns the copyright in
 * it, and being tagged in it grants nothing. So permission is a column and a
 * precondition, not a note somebody meant to check.
 */
function ugcRow(array $attributes = []): UgcVideo
{
    return UgcVideo::create(array_merge([
        'slug' => 'gate-'.uniqid(),
        'title' => 'Gate',
        'status' => 'draft',
        'rights_status' => 'granted',
        'file_path' => '/uploads/ugc/clip-20270110-000000-aaaaaaaaaa.mp4',
        'poster_path' => '/uploads/ugc/poster-20270110-000000-aaaaaaaaaa.jpg',
    ], $attributes));
}

it('publishes a clip that has a poster and no teaser at all', function () {
    /*
     * THE CASE THE WHOLE ROUND TURNS ON. A server with no transcoder produces
     * no teasers, so this is what every video on that box looks like.
     *
     * MUTATION NOTE. Add a teaser_path check to UgcVideo::publishBlockers() —
     * the obvious-looking "a shoppable video needs its loop" rule — and this is
     * red, together with the scope case below. RUN: both turned red, which is
     * what proves the rule is asserted rather than assumed.
     */
    $video = ugcRow(['status' => 'publish']);

    expect($video->teaser_path)->toBeNull()
        ->and($video->publishBlockers())->toBe([])
        ->and($video->canPublish())->toBeTrue()
        ->and($video->mediaState())->toBe(UgcVideo::MEDIA_POSTER_ONLY)
        ->and(UgcVideo::published()->pluck('id')->all())->toContain($video->id);
});

/*
 * ── "NOT ASKED YET" AND "THEY SAID NO" ARE NOW DIFFERENT ANSWERS ───────────
 *
 * This case used to run `->with(['pending', 'refused'])` and demand a refusal
 * for both. The owner asked to be able to publish without credits recorded —
 * "the video can be published without credits and poster cover, but warnings
 * should remains there" — and that is the PENDING case: permission he has not
 * got round to writing down.
 *
 * `refused` is not that, and it did not move. The creator was asked and said
 * no; there is no warning that makes overriding that acceptable, because the
 * cost of getting it wrong lands on somebody who is not the shop. Splitting the
 * dataset is the whole change: one row stayed a blocker, one became a warning.
 */
it('still refuses to publish a clip whose creator said no', function () {
    /*
     * MUTATION NOTE. Drop the `rights_status === 'refused'` branch from
     * publishBlockers() and this is green — a clip the creator refused becomes
     * publishable, which is the one failure in this module that costs money
     * rather than pixels. RUN: red.
     */
    $video = ugcRow(['rights_status' => 'refused']);

    expect($video->canPublish())->toBeFalse()
        ->and(implode(' ', $video->publishBlockers()))->toContain('refused');
});

it('lets a clip publish while permission is only pending, and says so', function () {
    $video = ugcRow(['rights_status' => 'pending']);

    expect($video->canPublish())->toBeTrue()
        // NOT SILENT. The point of moving it out of the blockers was never to
        // stop mentioning it: it follows the clip as a warning everywhere the
        // admin lists one.
        ->and(implode(' ', $video->publishWarnings()))->toContain('permission');
});

/*
 * MUTATION NOTE for the pair above. Put `rights_status !== 'granted'` back in
 * publishBlockers() and the second is red; delete the publishWarnings() branch
 * and the second is red on the warning instead. Either way the pair holds both
 * halves: publishable, and still told about.
 */

it('takes a published clip off the shop the moment permission is REFUSED', function () {
    /*
     * Two halves of the same rule, and the second is the one that matters: the
     * controller refuses the publish, and the READ refuses it too. A row that
     * was published while permission stood, and then had it withdrawn, must
     * stop being served without anybody having to remember to unpublish it.
     *
     * MUTATION NOTE. Remove the rights_status condition from
     * UgcVideo::scopePublished() and this is red. RUN.
     */
    $video = ugcRow(['status' => 'publish']);

    expect(UgcVideo::published()->pluck('id')->all())->toContain($video->id);

    $video->update(['rights_status' => 'refused']);

    expect(UgcVideo::published()->pluck('id')->all())->not->toContain($video->id);
});

it('lets a clip publish with no cover, and warns about it', function () {
    /*
     * ANOTHER DELIBERATE DEFAULT CHANGE, asked for in as many words.
     *
     * The old rule was not cosmetic and the reason has not gone away: §2 budgets
     * layout shift at 0. What makes this safe is that the RESERVED BOX never
     * depended on the poster file — Ugc\Tile reserves it from the clip's own
     * width and height with a 9:16 fallback, and two tests in this repo forbid
     * the element-measuring APIs by name. So a cover-less tile holds exactly the
     * same space; the shopper sees an empty box for the moment before the video
     * paints instead of a still. Worse first frame, not a broken page.
     *
     * The clincher is who it was costing: on a host whose PHP may not run
     * ffmpeg, EVERY clip is cover-less, so this was the difference between the
     * feature working and not existing.
     */
    $video = ugcRow(['poster_path' => null]);

    expect($video->canPublish())->toBeTrue()
        ->and(implode(' ', $video->publishWarnings()))->toContain('cover');
});

it('refuses to publish a clip with no video file', function () {
    $video = ugcRow(['file_path' => null]);

    expect($video->canPublish())->toBeFalse()
        ->and($video->mediaState())->toBe(UgcVideo::MEDIA_NONE);
});

it('honours published_at against the clock, because there is no scheduler', function () {
    /*
     * This host has no cron and no queue worker, so a job that flipped a status
     * at the appointed minute would be a job that never ran. ProductVisibility
     * sets the precedent for the catalogue and this follows it: the clip
     * becomes live because the clock moved.
     *
     * MUTATION NOTE. Drop the published_at condition from scopePublished() and
     * the "future" half of this is red. RUN.
     */
    $future = ugcRow(['status' => 'publish', 'published_at' => now()->addDay()]);
    $past = ugcRow(['status' => 'publish', 'published_at' => now()->subDay()]);
    $never = ugcRow(['status' => 'publish', 'published_at' => null]);

    $live = UgcVideo::published()->pluck('id')->all();

    expect($live)->not->toContain($future->id)
        ->and($live)->toContain($past->id)
        // A null published_at on a published row means "as soon as it is
        // published", not "never". The owner should not have to type today's
        // date to publish today.
        ->and($live)->toContain($never->id);
});

it('serves a cover-less clip on the shop, because the admin said it could', function () {
    /*
     * THE HALF THAT MAKES THE OTHER ONE TRUE, and the one that would have been
     * easy to forget: the admin gate and this query are the same decision asked
     * twice, and when they disagree the failure is invisible and the worst of
     * both. The owner would set the clip to Published, the screen would say
     * Published, and the rail would never show it.
     *
     * A refusal he can see beats a success that is not true.
     *
     * MUTATION NOTE. Put ->whereNotNull('poster_path') back into
     * scopePublished() and this is red while "lets a clip publish with no cover"
     * stays green — which is exactly the silent-no-show shape. RUN: red.
     */
    $video = ugcRow(['status' => 'publish']);
    $video->forceFill(['poster_path' => null])->save();

    expect(UgcVideo::published()->pluck('id')->all())->toContain($video->id);
});

it('keeps a draft out of the storefront scope whatever else is true of it', function () {
    $video = ugcRow(['status' => 'draft', 'teaser_path' => '/uploads/ugc/teaser-x-aaaaaaaaaa.mp4']);

    expect(UgcVideo::published()->pluck('id')->all())->not->toContain($video->id);
});

it('restricts a clip to one storefront only when a locale is set', function () {
    /*
     * §4: null means both shops. A clip spoken in English is not automatically
     * right for the Arabic one, and a caption nobody has translated is not
     * either — but that is the owner's call per clip, and the default is both.
     */
    $both = ugcRow(['status' => 'publish', 'locale' => null]);
    $english = ugcRow(['status' => 'publish', 'locale' => 'en']);
    $arabic = ugcRow(['status' => 'publish', 'locale' => 'ar']);

    $onArabic = UgcVideo::published()->forLocale('ar')->pluck('id')->all();

    expect($onArabic)->toContain($both->id)
        ->and($onArabic)->toContain($arabic->id)
        ->and($onArabic)->not->toContain($english->id);
});

it('reports the three media states apart from one another', function () {
    expect(ugcRow(['file_path' => null])->mediaState())->toBe(UgcVideo::MEDIA_NONE)
        ->and(ugcRow()->mediaState())->toBe(UgcVideo::MEDIA_POSTER_ONLY)
        ->and(ugcRow(['teaser_path' => '/uploads/ugc/teaser-x-aaaaaaaaaa.mp4'])->mediaState())
            ->toBe(UgcVideo::MEDIA_READY);
});

/* ═══════════════════════════════════════════ several products per clip ══ */

it('tags several products on one video, in the owner’s order', function () {
    /*
     * REQUIREMENT ONE, and the thing the benchmark app cannot do — its
     * one-product limit is a consequence of somebody else owning its player
     * (§3.2). `position` is what the player's rail follows and what decides
     * which product a rail tile's card shows, so the ORDER is data and not a
     * detail.
     *
     * MUTATION NOTE. Remove the ->orderBy('ugc_video_product.position') from
     * UgcVideo::products() and this is red: the rows come back in id order,
     * which is the order they were tagged and not the order they were dragged
     * into. RUN — green at first, which is what forced the fixture above to be
     * rewritten, then red.
     */
    $video = ugcRow();

    $a = Product::create(['slug' => 'p-a-'.uniqid(), 'name' => 'Toner', 'status' => 'publish',
        'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock']);
    $b = Product::create(['slug' => 'p-b-'.uniqid(), 'name' => 'Essence', 'status' => 'publish',
        'is_visible' => true, 'price' => 2000, 'stock_status' => 'instock']);
    $c = Product::create(['slug' => 'p-c-'.uniqid(), 'name' => 'Cream', 'status' => 'publish',
        'is_visible' => true, 'price' => 3000, 'stock_status' => 'instock']);

    /*
     * The pivot rows are INSERTED in one order and POSITIONED in the reverse,
     * deliberately: with the rows inserted a, b, c and positioned 2, 1, 0, an
     * ordering that quietly fell back to the insert order (or to the row id)
     * gives the opposite answer to the one below. Written the obvious way
     * round — insert order matching position order — this case passes against a
     * relation with no ordering at all, which is how it was first written and
     * what the mutation run caught.
     */
    $video->products()->sync([
        $a->id => ['position' => 1],
        $b->id => ['position' => 2, 'at_ms' => 4200],
        $c->id => ['position' => 0],
    ]);

    $names = $video->fresh()->products->pluck('name')->all();

    expect($names)->toBe(['Cream', 'Toner', 'Essence'])
        // at_ms is player D's and nobody else's: null everywhere by default, so
        // choosing any other player never asks anybody to type a timestamp.
        ->and($video->fresh()->products->firstWhere('name', 'Toner')->pivot->at_ms)->toBeNull()
        ->and($video->fresh()->products->firstWhere('name', 'Essence')->pivot->at_ms)->toBe(4200);
});

it('cannot tag the same product on one video twice', function () {
    /*
     * The unique index. Without it a double-click on Add tags the product twice
     * and the player draws two identical cards — a defect that looks like a
     * rendering bug and is a data one.
     *
     * MUTATION NOTE. Drop the unique(['ugc_video_id','product_id']) from the
     * migration and this is green: two rows, two cards. RUN.
     */
    $video = ugcRow();
    $p = Product::create(['slug' => 'p-dup-'.uniqid(), 'name' => 'Dup', 'status' => 'publish',
        'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock']);

    $video->products()->attach($p->id, ['position' => 0]);

    expect(fn () => $video->products()->attach($p->id, ['position' => 1]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('takes its tags with it when the video is deleted', function () {
    $video = ugcRow();
    $p = Product::create(['slug' => 'p-del-'.uniqid(), 'name' => 'Del', 'status' => 'publish',
        'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock']);

    $video->products()->attach($p->id, ['position' => 0]);
    $id = $video->id;
    $video->delete();

    expect(\Illuminate\Support\Facades\DB::table('ugc_video_product')->where('ugc_video_id', $id)->count())
        ->toBe(0)
        // ...and the product survives. A creator's clip being deleted must
        // never take a catalogue row with it.
        ->and(Product::whereKey($p->id)->exists())->toBeTrue();
});
