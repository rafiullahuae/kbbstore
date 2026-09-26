<?php

/**
 * What the SEO editing screens receive, pinned — Lane S7.
 *
 * ── WHY THIS FIXTURE EXISTS ─────────────────────────────────────────────────
 *
 * This lane adds a Google-result PREVIEW to four screens that had none, and a
 * read-only Overview tab. Rule 1 of the project notes is the thing that can go
 * wrong: a back-office reorganisation that silently alters a published title
 * would not be discovered for weeks, because the shop keeps rendering happily
 * and the only symptom is in Google's index.
 *
 * So the payload of every screen this lane draws on was captured from the
 * endpoints BEFORE a line of it was written — tests/Fixtures/
 * s7-seo-screen-payloads.json, recorded off the parent revision — and this
 * requires the endpoints to still produce it, key for key and value for value.
 *
 * ── WHY OUTRIGHT AND NOT A WALK ─────────────────────────────────────────────
 *
 * Rounds 1–4 of the settings work permitted exactly one additive difference
 * (`name` beside `key`) because those rounds MOVED a render loop. This lane
 * moves nothing: every field on these screens is the field that was there, with
 * the same id, storing the same key. There is therefore no difference to
 * justify, and the comparison is `===` on the whole decoded body. A single
 * reordered category, one moved default, one extra key and this is red.
 *
 * ── RE-RECORDING ────────────────────────────────────────────────────────────
 *
 *     KBB_S7_RECORD=1 KBB_WP_DB=kbb_wp_s7 \
 *       vendor/bin/pest tests/Feature/SeoBackOfficePayloadTest.php
 *
 * Re-recording is how a real change is admitted, and it must be a deliberate
 * act with a sentence beside it in the commit — never the way a red test is
 * cleared.
 *
 * MUTATION ACTUALLY RUN: adding one `'seo_preview_hint' => 'x'` key to
 * AdminController::settings()'s response fails this with
 *   settings: the recorded body and the live body differ
 * and names the key. Run, with the output quoted, in this lane's report.
 */

use App\Models\AdminUser;
use Illuminate\Support\Facades\Hash;

/**
 * The endpoints behind every screen this lane draws a preview on.
 *
 * `settings` is the SEO & Meta screen's own payload — thirty-eight SEO keys
 * among the rest, and the `title_template_basis` block the Title template box
 * prints its help from. `categories` and `brands` are what the two taxonomy
 * editors hydrate their SEO tabs from; each row carries its `seo` blob, which
 * is the thing a preview must read and must not write.
 *
 * WHY NOT post-editor-load. It takes an article id, so pinning it means seeding
 * an article and the recorded body then carries that row's autoincrement id and
 * its timestamps — a fixture that fails on the clock rather than on a change,
 * which is the kind of red that gets a test deleted (the same argument
 * ModuleScreenPayloadTest makes for leaving Security's `report` out). The
 * article editor's SEO fields are pinned instead by
 * SeoRowPreviewScreenTest, which reads the partial and requires that the
 * three ids it posts are exactly the three it posted before.
 */
function s7PayloadUrls(): array
{
    return ['settings', 'categories', 'brands'];
}

it('sends every SEO editing screen the payload it sent before the previews', function () {
    $owner = AdminUser::create([
        'name' => 'S7 Payload Owner',
        'email' => 's7-payload-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');

    $path = base_path('tests/Fixtures/s7-seo-screen-payloads.json');
    $recording = getenv('KBB_S7_RECORD') === '1';

    $live = [];

    foreach (s7PayloadUrls() as $url) {
        $response = test()->getJson('/admin-api/'.$url);

        expect($response->status())->toBe(200, "/admin-api/{$url} did not answer");

        $live[$url] = $response->json();
    }

    if ($recording) {
        file_put_contents(
            $path,
            json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }

    $expected = json_decode((string) file_get_contents($path), true);

    expect($expected)->toBeArray()->not->toBeEmpty();

    $moved = [];

    foreach (s7PayloadUrls() as $url) {
        expect($expected[$url] ?? null)->not->toBeNull("no recorded payload for {$url}");

        if ($expected[$url] !== $live[$url]) {
            $moved[] = $url.': the recorded body and the live body differ'
                .' ('.s7FirstDifference($expected[$url], $live[$url]).')';
        }
    }

    expect($moved)->toBe([], implode(' · ', $moved));
});

/** The first key that differs, so a failure names it rather than printing two blobs. */
function s7FirstDifference(mixed $was, mixed $now, string $at = ''): string
{
    if (! is_array($was) || ! is_array($now)) {
        return $at === '' ? 'the whole body' : $at;
    }

    foreach ($was as $key => $value) {
        if (! array_key_exists($key, $now)) {
            return trim($at.'.'.$key, '.').' is missing now';
        }

        if ($value !== $now[$key]) {
            return s7FirstDifference($value, $now[$key], trim($at.'.'.$key, '.'));
        }
    }

    foreach (array_keys($now) as $key) {
        if (! array_key_exists($key, $was)) {
            return trim($at.'.'.$key, '.').' is new';
        }
    }

    return $at === '' ? 'nothing' : $at;
}
