<?php

declare(strict_types=1);

use App\Models\UgcVideo;
use App\Services\UgcMedia;

/**
 * Content → Shoppable video → All clips: the guided add-a-clip flow.
 *
 * ── WHY A SOURCE SCAN AND NOT A REQUEST ─────────────────────────────────────
 *
 * Every defect below is in the SCREEN, which is a JavaScript string builder
 * inside a Blade partial that nothing renders server-side. This console already
 * pins that class of invariant the same way — UgcOneFrontDoorTest reads the tab
 * strip out of these files, AdminWritesAreSignedTest reads the signing header
 * out of them, and AdminMediaPickerEverywhereTest reads every file input out of
 * them. Where a claim can be settled against the database instead, it is, and
 * the two cases at the bottom do exactly that.
 *
 * Comments are stripped before any scan that looks for a behaviour, because
 * this file EXPLAINS the defects it fixes in prose — including by quoting the
 * wrong sentence — and a scan of the raw text would match the explanation and
 * fail a screen that is correct. UgcRailR3Test paid for that lesson twice.
 */
function addClipSource(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/ugc-library-screen.blade.php')
    );
}

/** The same text with the prose taken out: Blade comments, block comments, line comments. */
function addClipCode(): string
{
    $src = addClipSource();
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    $src = (string) preg_replace('#^\s*//.*$#m', '', $src);

    return $src;
}

/* ══════════════════════════════════════════════ the misleading teaser copy ══ */

it('never tells the owner that a clip without a teaser file shows a still', function () {
    /*
     * THE DEFECT, IN THE OWNER'S OWN WORDS: "the video must be adopted 2-3
     * seconds auto, i don't want to upload a seperate video for 2-3 seconds".
     *
     * He did not have to. The storefront has always looped the first 2.5 seconds
     * of the full clip when there is no teaser file — playTeaser() in
     * resources/views/ugc mounts `teaser || full` and rewinds on a timeupdate
     * past the rail's teaser-ms. What sent him looking for a second file was
     * THIS SCREEN, which said in three places that a tile without a teaser
     * "shows its poster instead of a short loop".
     *
     * So the sentences are gone and cannot come back quietly.
     *
     * MUTATION NOTE. Put any one of these strings back into the screen — the
     * teaser drop zone's old "Leave it empty and the tile shows its poster
     * instead" is the shortest — and this is red, naming it. RUN: red on each
     * of the three.
     */
    $code = addClipCode();

    $wrong = [
        'shows its poster instead',
        'tile shows its poster',
        'the tile shows its poster instead of a short loop',
        'what a tile shows when there is no teaser',
    ];

    $found = [];

    foreach ($wrong as $claim) {
        if (str_contains($code, $claim)) {
            $found[] = $claim;
        }
    }

    expect($found)->toBe([], implode("\n", array_merge(
        ['This screen is telling the owner a clip needs a second, shorter video file.'],
        ['It does not: with no teaser the tile loops the first 2.5s of the full clip.'],
        $found
    )));
});

it('says in as many words that the loop needs no second file', function () {
    /*
     * The other half, because deleting a wrong sentence is not the same as
     * saying the true one. The screen has to answer the question the owner
     * actually asked, on the step where he would ask it.
     *
     * MUTATION NOTE. Delete the sentence from mediaPanel()'s no-teaser branch
     * and this is red. RUN: red.
     */
    $code = addClipCode();

    expect(str_contains($code, 'The loop needs no second file'))
        ->toBeTrue('the media step never says that the 2-3 second loop works with one file');

    /*
     * ...and the one case where a tile really does stand still is named, because
     * it is the shopper's setting and not a missing file: rebalance() stops
     * every tile under Save-Data or reduced motion, teaser or no teaser.
     */
    expect(str_contains($code, 'data-saver mode'))
        ->toBeTrue('the screen does not say who really sees a still tile');
});

/* ═══════════════════════════════════════════════ the loop preview itself ══ */

it('previews the loop with the same mechanism the shop plays it with', function () {
    /*
     * The preview is evidence, so it has to be the real thing rather than a
     * lookalike: mount `teaser || full`, turn the NATIVE loop off when there is
     * no teaser, and rewind on the first timeupdate past the rail's own
     * teaser-ms. That is playTeaser() in resources/views/ugc/assets.blade.php,
     * transcribed.
     *
     * MUTATION NOTE. Set `v.loop = true` unconditionally in wireLoop() and the
     * screen plays the WHOLE clip round and round — which is the picture the
     * owner would then take to mean he does need a teaser file. RUN: red on the
     * `v.loop = !full` line.
     */
    $code = addClipCode();

    foreach ([
        'v.loop = !full',              // native loop off when it is the full clip
        "v.currentTime * 1000 >= ms",  // ...and the rewind that replaces it
        'v.currentTime = 0',
        'ms = 2500',                   // the rail's own default
        'teaser || clip',              // the same `teaser || full` choice
    ] as $needle) {
        expect(str_contains($code, $needle))
            ->toBeTrue("the loop preview no longer carries `{$needle}`, so it is not the shop's mechanism");
    }

    // And it gives the decoder back rather than leaving it resident, which is
    // the lesson the rail's unmount() records in its own comment.
    expect(str_contains($code, "loopEl.removeAttribute('src')"))
        ->toBeTrue('the loop preview never releases its decoder');
});

/* ════════════════════════════════════════════ the silently discarded file ══ */

it('refuses to send a file for a clip that does not exist yet, out loud', function () {
    /*
     * THE DEFECT. The old screen drew both file inputs on a brand-new clip, and
     * upload() opened with `if (!editing || !editing.id || …) return;`. Choosing
     * a video there did NOTHING AT ALL and said nothing about why: no toast, no
     * error, no disabled control. The owner picked a file, watched the screen
     * not change, and picked it again.
     *
     * Two locks now. The media step is not reachable until step 1 has saved, and
     * the send itself answers instead of returning.
     *
     * MUTATION NOTE. Delete the say(...) from sendFile()'s guard, leaving the
     * bare return, and this is red. RUN: red. Delete the `return 'locked'` line
     * from stepState() and the second expectation is red. RUN: red.
     */
    $code = addClipCode();

    /*
     * SCOPED TO sendFile()'s OWN BODY, and the first draft of this case was not.
     * The same sentence is said in two other places — the cover drop and the
     * drop handler's locked branch — so a whole-file search stayed GREEN with
     * the guard inside sendFile() reduced back to a bare `return`. Found by
     * running the mutation, which is what the mutation run is for.
     */
    $send = substr($code, (int) strpos($code, 'function sendFile(kind, file)'));
    $send = substr($send, 0, (int) strpos($send, 'function choosePoster()'));

    expect(str_contains($send, '!editing || !editing.id'))
        ->toBeTrue('sendFile() no longer checks that the clip exists');

    expect(str_contains($send, "say('Save step 1 first"))
        ->toBeTrue('a file chosen before the clip exists is discarded without a word');

    /*
     * ...and the DROP path says it too, scoped the same way. A drop onto a
     * locked zone never reaches sendFile(), so the guard there cannot cover it;
     * counting occurrences across the file cannot either, because dropPoster()
     * carries the same sentence and keeps the count up on its own. Found by
     * running the mutation.
     */
    $drop = substr($code, (int) strpos($code, "addEventListener('drop'"));

    expect(str_contains($drop, "say('Save step 1 first"))
        ->toBeTrue('a file dropped on a locked zone is swallowed in silence');

    expect(str_contains($code, "if (!v.id && n > 1) return 'locked';"))
        ->toBeTrue('the media steps are reachable before the clip row exists');
});

/* ═══════════════════════════════════════ what a refused publish must keep ══ */

it('folds the typed values back into the row before a save that may be refused', function () {
    /*
     * THE DEFECT, reproduced in Chromium at both widths before the fix and
     * photographed in docs/ugc-add-clip-shots: press Save on the Publish step
     * with a blocker still open, the server answers 422, and render() repainted
     * every field from `editing` — which still held whatever the last GET
     * returned. Everything typed since was silently reverted. Measured: the
     * title box went back from "Lane V4 probe 1280 EDITED" to "Lane V4 probe
     * 1280". After the fix, both widths keep what was typed.
     *
     * MUTATION NOTE. Remove the keepTyped(payload) call from save() and this is
     * red. RUN: red.
     */
    $code = addClipCode();

    expect(str_contains($code, 'function keepTyped(payload)'))
        ->toBeTrue('nothing folds the form back into the row');

    // Before the request goes out, not after it comes back — a 422 never
    // reaches the line that follows the await.
    $save = substr($code, (int) strpos($code, 'async function save()'));
    $save = substr($save, 0, (int) strpos($save, 'async function goStep'));

    $merge = strpos($save, 'keepTyped(payload)');
    $send = strpos($save, 'busy = true');

    expect($merge !== false && $send !== false && $merge < $send)
        ->toBeTrue('keepTyped() does not run before the request that may be refused');
});

/* ═══════════════════════════════════════════════════ the drop zones ══ */

it('gives every video drop zone a real file input with a literal accept', function () {
    /*
     * Two rules meeting in one control.
     *
     * The owner's: a drop zone that only takes a drop is a control a keyboard
     * cannot use, so the real <input type="file"> is inside the label and the
     * label is its activator — natively, which is why nothing here calls click()
     * on an input.
     *
     * AdminMediaPickerEverywhereTest's: it reads the accept attribute out of
     * this SOURCE TEXT and treats an input it cannot see one on as an image
     * picker that should have opened the Media Library. So the attribute is
     * written out as a literal and never concatenated from a variable.
     *
     * MUTATION NOTE. Build the accept from a variable — accept="'+types+'" —
     * and this is red here AND in AdminMediaPickerEverywhereTest, which reports
     * it as a raw image picker. RUN: red in both.
     */
    $code = addClipCode();

    expect(str_contains($code, '\'<input type="file" accept="video/mp4,video/webm" class="ugs-file"\''))
        ->toBeTrue('the drop zone lost its literal accept attribute');

    // No click forwarding: the label IS the activator.
    expect(preg_match('/\binput\s*\.\s*click\s*\(/', $code))
        ->toBe(0, 'the screen forwards a click to a file input from script');

    // A drop really is handled, and only inside a zone this screen drew.
    foreach (["addEventListener('drop'", "addEventListener('dragover'", 'e.dataTransfer.files'] as $needle) {
        expect(str_contains($code, $needle))->toBeTrue("drag and drop is incomplete: {$needle} is missing");
    }
});

it('takes a dragged cover through the Media Library rather than round it', function () {
    /*
     * "on any upload media on the whole backend, the media library is a must to
     * show." A dropped picture is still an upload, so it goes to
     * /admin-api/media/upload and joins the library, and the clip adopts the URL
     * that comes back — which is exactly what the console's own shared image
     * field does with a dropped file.
     *
     * MUTATION NOTE. Point dropPoster() at /ugc-videos/{id}/media with
     * kind=poster and this is red — with a cover that never reaches the library
     * and can never be re-used on a second clip. RUN: red.
     */
    $code = addClipCode();

    expect(str_contains($code, "api('/media/upload', data)"))
        ->toBeTrue('a dragged cover does not go through the Media Library');

    expect(str_contains($code, "folder', 'posters'"))
        ->toBeTrue('a dragged cover is not filed under the posters folder');

    // And the picker is still the primary way in, guarded before it is called.
    expect(str_contains($code, "typeof window.kbbPickMedia !== 'function'"))
        ->toBeTrue('the screen calls the Media Library without checking it is loaded');
});

/* ══════════════════════════════════════════════════ rule 4, on this screen ══ */

it('measures no layout in script, anywhere on this screen', function () {
    /*
     * Rule 4, and the same list CheckoutFloatingBarGateTest and CartPageSqueezeTest
     * name. This screen sizes with clamp(), aspect-ratio, scroll-snap and a media
     * query, and a stepper is a tempting place to reach for a rect.
     *
     * scrollIntoView is deliberately NOT on this list: it is a scroll command
     * that reads nothing back, and the rail needs it to bring the current step
     * into view on a phone.
     *
     * MUTATION NOTE. Add `host.getBoundingClientRect()` anywhere in the script
     * and this is red. RUN: red.
     */
    $code = addClipCode();

    foreach ([
        'getBoundingClientRect', 'offsetTop', 'offsetHeight', 'offsetWidth',
        'clientHeight', 'clientWidth', 'window.scrollY', 'requestAnimationFrame',
    ] as $api) {
        expect(str_contains($code, $api))
            ->toBeFalse("this screen measures layout in script: {$api}");
    }
});

/* ═══════════════════════════════════════════ the steps ARE the publish gate ══ */

it('locks the five steps to the three things the server really refuses on', function () {
    /*
     * The step sequence is not a design choice dressed as one: steps 2 and 3
     * are UgcVideo::publishBlockers()' three clauses, and step 1 exists because
     * the row has to exist before /ugc-videos/{id}/media has an {id}.
     *
     * This case reads the gate off the MODEL and then asserts the screen tests
     * the same three columns, so adding a fourth blocker without teaching the
     * screen about it is caught here rather than by an owner who cannot work out
     * why Publish is refused.
     *
     * MUTATION NOTE. Drop the poster clause from publishBlockers() and the
     * count below is 2, which is red. Drop `v.poster_path` from the screen's
     * step-2 test and the second half is red. RUN: red on both.
     */
    $clip = new UgcVideo;

    expect($clip->publishBlockers())->toHaveCount(3);

    $clip->file_path = '/uploads/ugc/x.mp4';
    $clip->poster_path = '/uploads/ugc/x.jpg';
    $clip->rights_status = 'granted';

    expect($clip->publishBlockers())->toBe([])
        ->and($clip->canPublish())->toBeTrue();

    $code = addClipCode();

    // Step 2 is the two media clauses, step 3 is the rights clause.
    expect(str_contains($code, "if (n === 2) return (v.file_path && v.poster_path) ? 'done' : 'todo';"))
        ->toBeTrue('step 2 no longer tracks the two media blockers');
    expect(str_contains($code, "if (n === 3) return v.rights_status === 'granted' ? 'done' : 'todo';"))
        ->toBeTrue('step 3 no longer tracks the rights blocker');

    // Five steps, named, and the step rail is drawn from that one list.
    expect(preg_match_all('/\{ n: [1-5], label:/', $code))->toBe(5);
});

it('prints the reasons the SERVER gave rather than a list of its own', function () {
    /*
     * The checklist on step 5 is this screen's reading of three columns, and it
     * is drawn beside — never instead of — the blockers the 422 actually
     * carried. A screen that invented its own reasons would go quietly wrong the
     * day the gate grew a fourth one.
     *
     * MUTATION NOTE. Replace the blockers list in publishPanel() with a
     * hard-coded three-line message and this is red. RUN: red.
     */
    $code = addClipCode();

    expect(str_contains($code, "editing._blockers = e.body.blockers;"))
        ->toBeTrue('a refusal\'s reasons are thrown away');

    expect(str_contains($code, "var blockers = v._blockers || v.blockers || [];"))
        ->toBeTrue('the publish step does not read the blockers the server sent');
});

/* ════════════════════════════════════════ a poster-only clip is not broken ══ */

it('badges a clip with no teaser as one that loops, not as one that is missing a file', function () {
    /*
     * UgcVideo::mediaState() answers MEDIA_POSTER_ONLY for a clip with a video
     * and a cover and no separate teaser — which is EVERY clip on a server with
     * no ffmpeg, and this shop has no ffmpeg. Badged "Poster only" it read as a
     * defect, and the owner read it as one.
     *
     * The state is unchanged and still publishable (UgcPublicationGateTest owns
     * that); what changed is the word beside it.
     *
     * MUTATION NOTE. Put 'poster only' back into stateWords() and this is red.
     * RUN: red.
     */
    $clip = new UgcVideo([
        'file_path' => '/uploads/ugc/x.mp4',
        'poster_path' => '/uploads/ugc/x.jpg',
        'rights_status' => 'granted',
        'status' => 'publish',
    ]);

    expect($clip->mediaState())->toBe(UgcVideo::MEDIA_POSTER_ONLY)
        // ...and nothing about that state blocks publication.
        ->and($clip->canPublish())->toBeTrue();

    $code = addClipCode();

    expect(str_contains($code, "if (v.media_state === 'poster_only') return ['Loops the full clip', 'info'];"))
        ->toBeTrue('the no-teaser state is not badged as the looping state it is');

    expect(stripos($code, 'poster only'))
        ->toBeFalse('"Poster only" is back on the screen, and it reads as a fault');
});

/* ══════════════════════════════════════════ a URL-only clip cannot publish ══ */

it('cannot publish a clip that has only a platform link, and the screen says so', function () {
    /*
     * The honest answer to "the video must be preview after upload from all
     * sources": a file this shop serves can be previewed, and a platform address
     * cannot, because this module embeds nothing. scopePublished() requires a
     * file_path, so a clip with only an Instagram URL is a credit line with no
     * video — and the screen says that rather than drawing an empty frame.
     *
     * MUTATION NOTE. Remove whereNotNull('file_path') from scopePublished() and
     * the first half is red, with a rail tile that plays nothing. RUN: red.
     */
    $clip = UgcVideo::create([
        'slug' => 'url-only-'.uniqid(),
        'title' => 'Credited, not hosted',
        'status' => 'publish',
        'rights_status' => 'granted',
        'source_platform' => 'instagram',
        'source_url' => 'https://www.instagram.com/p/Cabc123/',
        'poster_path' => '/uploads/ugc/x.jpg',
    ]);

    expect($clip->publishBlockers())->toHaveCount(1)
        ->and($clip->publishBlockers()[0])->toContain('No video file')
        ->and(UgcVideo::published()->whereKey($clip->id)->exists())->toBeFalse();

    $code = addClipCode();

    expect(str_contains($code, 'credit line only'))
        ->toBeTrue('the media step does not say a platform address is a credit line and nothing else');

    expect(str_contains($code, 'embeds nothing from anybody'))
        ->toBeTrue('the media step does not say this module embeds nothing');
});

/* ═══════════════════════════════════════════════ the caps the screen quotes ══ */

it('quotes the server\'s own size caps rather than numbers typed into the screen', function () {
    /*
     * The three caps come down in the index payload and the screen falls back to
     * the shipped numbers only if it is asked before the first read. A number
     * typed into the markup is a number that says 64 MB after somebody raises
     * the cap to 128 — and the operator finds out by having an upload refused
     * after it finished going up.
     *
     * MUTATION NOTE. Replace cap('clip') with a literal 64 in mediaPanel() and
     * this is red. RUN: red.
     */
    $code = addClipCode();

    expect(str_contains($code, "esc(cap('clip'))"))->toBeTrue('the clip cap is not read from the server');
    expect(str_contains($code, "esc(cap('poster'))"))->toBeTrue('the cover cap is not read from the server');
    expect(str_contains($code, "esc(cap('teaser'))"))->toBeTrue('the teaser cap is not read from the server');

    // And the fallbacks match what the service really ships, so the one render
    // before the first answer is not a lie either.
    expect((int) (UgcMedia::MAX_BYTES[UgcMedia::KIND_CLIP] / 1048576))->toBe(64)
        ->and((int) (UgcMedia::MAX_BYTES[UgcMedia::KIND_POSTER] / 1048576))->toBe(4)
        ->and((int) (UgcMedia::MAX_BYTES[UgcMedia::KIND_TEASER] / 1048576))->toBe(8);

    expect(str_contains($code, "if (kind === 'clip') return limits ? limits.clip_mb : 64;"))
        ->toBeTrue('the clip fallback no longer matches UgcMedia::MAX_BYTES');
});

/* ═══════════════════════════════════════════════════════ the upload itself ══ */

it('signs the progress upload with the cookie this console issues, not a meta tag', function () {
    /*
     * The upload moved from fetch to XMLHttpRequest for the progress bar, and
     * that is exactly the kind of change that quietly loses a header. This admin
     * has no csrf-token meta tag — AdminWritesAreSignedTest asserts that in as
     * many words — so an XHR signed the meta way is refused with 419 on every
     * clip, and the screen reports it as "that file was not accepted".
     *
     * MUTATION NOTE. Drop the setRequestHeader line and every upload 419s. RUN:
     * red here; AdminWritesAreSignedTest stays green either way, because it
     * checks the screen does not use the WRONG scheme rather than that this one
     * request uses the right one.
     */
    $code = addClipCode();

    expect(str_contains($code, "xhr.setRequestHeader('X-XSRF-TOKEN', cookie('XSRF-TOKEN'));"))
        ->toBeTrue('the progress upload is unsigned, so every clip upload is refused with 419');

    // The bar is painted directly rather than through render(), because a
    // repaint per progress event restarts the preview and eats the caret.
    expect(str_contains($code, 'function paintProgress()'))
        ->toBeTrue('the upload has no progress bar');
    expect(str_contains($code, 'xhr.upload.onprogress'))
        ->toBeTrue('nothing listens to the upload\'s progress');
});

it('clears the file input before sending, so the same file can be retried', function () {
    /*
     * A browser fires no change event for a value that did not change, so
     * choosing the SAME file after a refusal — the commonest next thing to do —
     * did nothing on the old screen. The input is cleared before the send rather
     * than after, so the retry works even when the send throws.
     *
     * MUTATION NOTE. Move input.value = '' after the sendFile() call and the
     * retry still works; delete it and this is red. RUN: red on the delete.
     */
    $code = addClipCode();

    $handler = substr($code, (int) strpos($code, "hasAttribute('data-ugs-upload')"));
    $handler = substr($handler, 0, 900);

    $clear = strpos($handler, "input.value = '';");
    $send = strpos($handler, 'sendFile(');

    expect($clear !== false && $send !== false && $clear < $send)
        ->toBeTrue('the file input is not cleared before the send, so the same file cannot be retried');
});
