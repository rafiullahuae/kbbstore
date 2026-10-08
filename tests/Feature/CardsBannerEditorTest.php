<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\BannerCard;
use App\Models\BannerSet;
use Illuminate\Support\Facades\Hash;

/**
 * Appearance → Banners: the Save button, and every column having a control —
 * Lane BP, round 7.
 *
 * ── WHY THIS FILE IS NOT PART OF ModuleFrameworkGuardTest ───────────────────
 *
 * `cards_banner` IS enrolled in that guard this round (the line is in
 * ehSchemaModules(), beside Lane MC's four). Enrolling it found nothing: the
 * module has exactly two things in the framework — the section's on/off and
 * which set the homepage draws — one SCHEMA key, one TABS entry, one control,
 * no rule missing, no field drawn twice.
 *
 * AND THAT IS THE WHOLE POINT OF THIS FILE. The guard can only compare two
 * constants, and fifteen of this module's seventeen controls are not in either
 * of them: they are COLUMNS on `banner_sets` and `banner_cards`, because the
 * owner asked for them to live "inside each banner section" and ModuleSchema
 * holds one value per key for the whole shop. So the guarantee the guard buys —
 * "a module cannot ship a value with no control, or a control with no value" —
 * stops exactly where this module's interesting half begins.
 *
 * ── WHAT ASKING THE GUARD'S OWN QUESTION OF THOSE COLUMNS FOUND ─────────────
 *
 * Two values with no control, which is `reassure_auth_text` — "a setting with
 * no control, so its shipped default was the only value it ever had" — twice:
 *
 *   banner_cards.status    A real column. The storefront FILTERS ON IT
 *                          (`where('banner_cards.status','publish')`), the
 *                          controller validated writes to it, the payload
 *                          carried it to the screen, `BannerCard::STATUSES`
 *                          declared `draft` as one of its two options — and no
 *                          control on the screen wrote it. Every card was
 *                          created `publish` and there was no way to take one
 *                          out of the row except to delete it and lose the
 *                          picture, the words and the link with it. An owner
 *                          taking a card down for a week had to rebuild it.
 *
 *   banner_sets.position   Same shape, smaller blast radius: it orders the set
 *                          list and the homepage picker, `updateSet` accepted
 *                          it, and nothing on the screen offered it.
 *
 * Both now have a control, and the cases below are what keep them.
 */
function bpeOwner(): AdminUser
{
    $user = AdminUser::create([
        'name' => 'BP owner',
        'email' => 'bp-owner-'.uniqid().'@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($user, 'admin');

    return $user;
}

function bpeSet(int $cards = 2): BannerSet
{
    $set = BannerSet::create([
        'name' => 'Editor', 'slug' => 'ed-'.uniqid(), 'status' => 'publish', 'position' => 0,
        // ▲ NAMED, BECAUSE THE DEFAULT MOVED. BannerSet::$attributes now
        // ships a set as a picture slider, and this file is about the CARDS
        // treatment — without this the seed silently stopped seeding the
        // thing being measured.                                  (Lane SEC)
        'kind' => 'cards',
    ]);

    foreach (range(1, $cards) as $i) {
        BannerCard::create([
            'banner_set_id' => $set->id,
            'image' => 'uploads/banners/bp-ed-'.$i.'.webp',
            'alt' => 'Alt '.$i, 'heading' => 'Heading '.$i, 'body' => 'Body '.$i,
            'button_label' => 'Shop', 'button_url' => '/shop/',
            'position' => $i, 'status' => 'publish',
        ]);
    }

    return $set->refresh();
}

/** The console file that draws this screen, read whole. */
function bpeScreen(): string
{
    $path = base_path('resources/views/admin/partials/banners-screen.blade.php');

    expect(file_exists($path))->toBeTrue('the Banners screen is gone');

    return (string) file_get_contents($path);
}

/* ═════════════ 1. every column the editor owns has a control ══════════════ */

it('draws a control for every set column the editor can write, exactly once', function () {
    /*
     * ModuleFrameworkGuardTest's own words for the two halves this checks:
     * "A value with no control is `reassure_auth_text`: a default that was the
     * only value it ever had. A control with no value is the opposite failure
     * and is worse, because it reports 'Saved'." And: "Twice in one tab is not
     * a second control, it is the same one drawn twice — and whichever the
     * operator filled in last would appear to win."
     *
     * The list on the screen (SET_KEYS) is what Save and the buffered preview
     * BOTH send, so a key missing from it is a control that saves nothing and a
     * key in it with no control is a value nobody can reach.
     *
     * MUTATION, run: delete `'position'` from SET_KEYS in the screen and this
     * is red naming it; add `'slug'` to SET_KEYS and it is red the other way.
     */
    $screen = bpeScreen();

    preg_match('/var SET_KEYS = \[(.*?)\];/s', $screen, $m);

    expect($m)->not->toBeEmpty('the screen no longer declares SET_KEYS');

    preg_match_all("/'([a-z_]+)'/", $m[1], $keys);
    $sent = $keys[1];

    expect(count($sent))->toBe(count(array_unique($sent)), 'SET_KEYS names the same column twice');

    /*
     * WHAT THE EDITOR OWNS is every fillable column except the two it must not
     * touch: `slug`, which is derived from the name by the controller and is a
     * URL handle rather than a setting, and nothing else. Written as a
     * subtraction from the model's own list so a column added to BannerSet
     * without a control fails HERE rather than on the shop.
     */
    /*
     * ▲ ADVANCED BY LANE HB: `text_box` is one JSON column that the screen
     * edits as the flat `tb_*` keys of TB_KEYS, appended to SET_KEYS after
     * this list. BannerTextBoxTest pins TB_KEYS to BannerTextBox::keys().
     */
    // ▲ ADVANCED BY LANE HB3: the two height modes are appended to SET_KEYS
    // with SET_KEYS.concat() right after this list (BannerHeightExactTest pins it).
    $owned = array_values(array_diff((new BannerSet)->getFillable(), ['slug', 'text_box', 'slider_hmode', 'slider_hmode_m']));

    sort($owned);
    sort($sent);

    expect($sent)->toBe($owned, 'the editor and the table disagree about what a set carries');

    /*
     * And each one has a real control. Two are drawn by a BESPOKE control
     * rather than by num()/pick()/sw()/colour(), and they are named here the
     * way ModuleFrameworkGuardTest names its own exemption — "SO THE EXEMPTION
     * PROVES ITSELF rather than being taken on trust": the element id is
     * asserted below, so an exemption for something the screen does not draw
     * fails here rather than hiding the gap it was meant to explain.
     */
    $bespoke = [
        // The picture goes through window.kbbPickMedia, not a text box, for the
        // reason every other image field in this console does: a path typed by
        // hand is a path nobody checked and a picture nobody has seen.
        'bg_image' => 'id="bns-bgpic"',
        // Mirrored, named and given its own readout — see the speed case below.
        'speed_ms' => 'data-bns-speed="1"',
    ];

    foreach ($sent as $key) {
        if (isset($bespoke[$key])) {
            expect(str_contains($screen, $bespoke[$key]))->toBeTrue(
                "{$key} is exempted as a bespoke control and the screen does not draw it"
            );

            continue;
        }

        $drawn = substr_count($screen, 'data-bns-set="'.$key.'"')
            + substr_count($screen, "('".$key."',")            // num() / pick() / colour() / sw()
            + substr_count($screen, 'data-bns-colour="'.$key.'"');

        expect($drawn)->toBeGreaterThan(0, "{$key} is sent to the server and no control writes it");
    }

    // The two that had none until this round, named so the regression is
    // readable rather than buried in the loop above.
    expect(str_contains($screen, 'data-bns-set="position"'))->toBeTrue('a set can no longer be ordered')
        ->and(str_contains($screen, "speedField(s.speed_ms"))->toBeTrue('the speed control is gone again');
});

it('draws a control for every card column the editor can write, exactly once', function () {
    $screen = bpeScreen();

    preg_match('/var CARD_KEYS = \[(.*?)\];/s', $screen, $m);

    expect($m)->not->toBeEmpty('the screen no longer declares CARD_KEYS');

    preg_match_all("/'([a-z_]+)'/", $m[1], $keys);
    $sent = $keys[1];

    expect(count($sent))->toBe(count(array_unique($sent)), 'CARD_KEYS names the same column twice');

    /*
     * A card's own columns, less the three that are not settings: the foreign
     * key, and the width and height, which are read off the FILE by
     * MediaRegistrar when the picture is chosen. `image_w`/`image_h` are the
     * one pair that must never become a box — a guessed width reserves the
     * wrong space and shifts the page, which is what the partial's own note
     * says about printing neither attribute when they are unknown.
     */
    /*
     * ▲ AND THE PHONE PICTURE'S MEASURED PAIR JOINS THEM, for the identical
     *   reason rather than an analogous one.                        (Lane SEC)
     * `image_m_w`/`image_m_h` are read off the FILE by MediaRegistrar when the
     * phone picture is chosen, exactly as `image_w`/`image_h` are for the
     * desktop one. `image_m` itself is NOT exempt and must not be: it is a
     * setting, it has a control, and it is pinned by name below.
     */
    $owned = array_values(array_diff(
        (new BannerCard)->getFillable(),
        ['banner_set_id', 'image_w', 'image_h', 'image_m_w', 'image_m_h'],
    ));

    sort($owned);
    sort($sent);

    expect($sent)->toBe($owned, 'the editor and the table disagree about what a card carries');

    /*
     * THE DEFECT THIS FILE'S HEADER NAMES. `status` is in the list above, and
     * it is in it because there is now a control:
     *
     * MUTATION, run: delete the `<select … data-bns-k="status">` block from the
     * card row and this is red — `status` would be sent with no control to set
     * it, which is a column the storefront filters on and the owner cannot
     * reach.
     */
    expect(substr_count($screen, "data-bns-k=\"status\""))->toBe(1, 'a card can be hidden twice, or not at all');

    /*
     * THE SAME ARGUMENT FOR THE PHONE PICTURE.                       (Lane SEC)
     *
     * `image_m` is in CARD_KEYS above, so the Save button sends it; if nothing
     * on the screen writes it, the owner has a column he cannot reach and a
     * banner that is soft on his own handset with no way to fix it. The owner
     * asked for two sizes in as many words — "for desktop the size should be
     * 1920 x 550 and in mobile 500 x 600" — so the second picker is the control
     * that answers the second number.
     *
     * ONCE, NOT "AT LEAST ONCE". Two pickers bound to the same card would each
     * overwrite the other's pick and the second thumbnail would disagree with
     * the first; CLAUDE.md's rule about pinning the finished state is exactly
     * this shape. Three counts, because the choose, the clear and the slot the
     * picture lands in are three separate ways to be half-wired:
     *
     * MUTATION, run: delete the `[data-bns-picm]` handler block and this is red
     * at 1 against 2 — the button is drawn and does nothing, which is the
     * worst of the three states because it looks finished.
     */
    expect(substr_count($screen, 'data-bns-picm='))->toBe(1, 'the phone picture button is drawn twice, or not at all')
        ->and(substr_count($screen, "querySelectorAll('[data-bns-picm]')"))->toBe(1, 'the phone picture button has no handler, or two')
        /*
         * THE NEEDLE IS THE MARKUP'S OWN AND NOT `data-bns-thumbm=`, which
         * occurs TWICE: once where the slot is written and once inside the
         * handler's `querySelector('[data-bns-thumbm="' + id + '"]')`. Counted
         * the short way this read 2 for a correct screen — the ambiguous-needle
         * class, found here rather than assumed, and the same one that made a
         * strip count read 2 for one strip earlier in this lane.
         */
        ->and(substr_count($screen, 'data-bns-thumbm="\' + esc(c.id) + \'"'))->toBe(1, 'the phone picture has no slot to land in, or two')
        ->and(substr_count($screen, "querySelectorAll('[data-bns-clearm]')"))->toBe(1, 'a phone picture cannot be removed once chosen');

    /*
     * AND THE TWO SIZES ARE ON THE BUTTONS THEMSELVES. He is uploading two
     * pictures per slide now; a screen that does not say which shape goes where
     * is a screen that gets one of them wrong. Written as the numbers he gave.
     */
    expect(str_contains($screen, '1920 × 550'))->toBeTrue('the desktop size is not on the screen')
        ->and(str_contains($screen, '500 × 600'))->toBeTrue('the phone size is not on the screen');
});

/* ═════════════════════ 2. the Save button, and only it ════════════════════ */

it('no longer writes to the server as the owner types', function () {
    /*
     * THE OWNER'S OWN WORDS: "i don't want auto save, there should b save
     * button, bcz i need multiple edits before save."
     *
     * What it looked like on the screen: every `[data-bns-set]` and every
     * `[data-bns-card]` carried an `onchange` that awaited a PUT and then
     * called `openEditor()` or `load()` — one request per field, and a
     * re-render after each. Three edits were three writes, and the redraw
     * between them is what made it fight him.
     *
     * MUTATION, run: put `await api('/banners/sets/' + openId, 'PUT', payload)`
     * back inside the `[data-bns-set]` handler and this is red.
     */
    $screen = bpeScreen();

    // The set-control and card-field handlers exist...
    expect(str_contains($screen, "document.querySelectorAll('[data-bns-set]')"))->toBeTrue()
        ->and(str_contains($screen, "document.querySelectorAll('[data-bns-card]')"))->toBeTrue();

    /*
     * ...and neither of them reaches the network. The handler is everything
     * between its own selector and the `});` that closes the forEach — bounded
     * by the closing brace rather than by a character count, because a count
     * reaches into the NEXT handler and the one after `[data-bns-card]` is the
     * delete button, which really does call the server and should.
     */
    foreach (["document.querySelectorAll('[data-bns-set]').forEach", "document.querySelectorAll('[data-bns-card]').forEach"] as $needle) {
        $from = (int) strpos($screen, $needle);

        expect($from)->toBeGreaterThan(0, "the {$needle} handler is gone");

        $end = (int) strpos($screen, "\n    });", $from);
        $block = substr($screen, $from, $end - $from);

        expect(str_contains($block, 'api('))->toBeFalse('a field handler still talks to the server on every edit');
        expect(str_contains($block, 'draft.'))->toBeTrue('a field handler writes somewhere other than the draft');
    }

    // And the Save button is the one place a set write happens.
    expect(substr_count($screen, "'/all', 'PUT'"))->toBe(1, 'the editor has more than one, or no, way to save');
});

it('saves the set and every card in one request', function () {
    bpeOwner();
    $set = bpeSet(2);
    $cards = $set->cards()->get();

    $response = test()->putJson('/admin-api/banners/sets/'.$set->id.'/all', [
        'set' => [
            'speed_ms' => 2200,
            'card_radius' => 4,
            'bg_mode' => 'color',
            'bg_color' => '#102030',
            'btn_bg' => '#123456',
            'title_pos' => 'over',
            'position' => 3,
        ],
        'cards' => [
            ['id' => $cards[0]->id, 'heading' => 'First changed', 'status' => 'draft'],
            ['id' => $cards[1]->id, 'heading' => 'Second changed', 'body' => 'And its line'],
        ],
    ])->assertOk()->json();

    expect($response['set']['speed_ms'])->toBe(2200)
        ->and($response['set']['card_radius'])->toBe(4)
        ->and($response['set']['bg_mode'])->toBe('color')
        ->and($response['set']['bg_color'])->toBe('#102030')
        ->and($response['set']['btn_bg'])->toBe('#123456')
        ->and($response['set']['title_pos'])->toBe('over')
        ->and($response['set']['position'])->toBe(3);

    $set->refresh();

    expect($set->speed_ms)->toBe(2200)
        ->and($set->bg_color)->toBe('#102030')
        ->and($cards[0]->fresh()->heading)->toBe('First changed')
        ->and($cards[0]->fresh()->status)->toBe('draft')
        ->and($cards[1]->fresh()->heading)->toBe('Second changed')
        ->and($cards[1]->fresh()->body)->toBe('And its line');
});

it('refuses a card that belongs to another set rather than writing it', function () {
    /*
     * `cards.*.id` ARRIVES FROM THE BROWSER. An editor that wrote whatever id
     * it was handed would let a Save on one set rewrite another set's cards —
     * including a set the owner is not looking at, with no sign on either
     * screen that it had happened.
     *
     * MUTATION, run: delete the `$cards->has(...)` check in saveAll() and this
     * is red, and the intruder's heading really does change.
     */
    bpeOwner();
    $mine = bpeSet(1);
    $theirs = bpeSet(1);
    $intruder = $theirs->cards()->first();

    test()->putJson('/admin-api/banners/sets/'.$mine->id.'/all', [
        'set' => ['speed_ms' => 1200],
        'cards' => [['id' => $intruder->id, 'heading' => 'Stolen']],
    ])->assertStatus(422);

    expect($intruder->fresh()->heading)->toBe('Heading 1')
        // ...and the set write did not half-happen either.
        ->and($mine->fresh()->speed_ms)->toBe(4000);
});

it('writes nothing at all when one card in the batch is refused', function () {
    /*
     * ONE TRANSACTION. A half-applied set — the row saved with its new colours
     * and two of six cards, and no way to tell which — is the shape that costs
     * an afternoon, and it is the shape a loop of individual saves produces on
     * the first bad value.
     *
     * The refusal here is a validation one, which happens before the
     * transaction opens; the assertion that matters is that NOTHING moved,
     * including the set fields that were perfectly valid.
     *
     * MUTATION: drop `Rule::in(array_keys(BannerCard::STATUSES))` from
     * cardRules() and this is red — the bad status would be stored.
     */
    bpeOwner();
    $set = bpeSet(2);
    $cards = $set->cards()->get();

    test()->putJson('/admin-api/banners/sets/'.$set->id.'/all', [
        'set' => ['speed_ms' => 1200, 'card_radius' => 2],
        'cards' => [
            ['id' => $cards[0]->id, 'heading' => 'Fine'],
            ['id' => $cards[1]->id, 'status' => 'wormhole'],
        ],
    ])->assertStatus(422);

    /*
     * ▲ 18 BECAME 0 WITH THE MOVED DEFAULT — Lane BG, and the pin is advanced
     * rather than the number softened. `BannerSet::$attributes` now makes a new
     * set square, because the owner asked for the homepage banner to "remove
     * the corner radius etc." What this case is ACTUALLY about is unchanged: a
     * 422 on one card must leave the SET untouched, and `card_radius` is the
     * untouched value it reads. Both numbers are "the value the set had before
     * the refused write"; only which number that is has moved.
     */
    expect($set->fresh()->speed_ms)->toBe(4000)
        ->and($set->fresh()->card_radius)->toBe(0)
        ->and($cards[0]->fresh()->heading)->toBe('Heading 1');
});

/* ══════════════════ 3. the preview draws the buffer, and writes nothing ═══ */

it('draws the unsaved buffer and leaves the database exactly as it was', function () {
    /*
     * The whole reason the owner wants to batch is to SEE the combination
     * before committing it. A preview that read the database would show him the
     * row he is trying to get away from.
     *
     * MUTATION, run: add `$draftSet->save();` to previewDraft() and this is red
     * on the last three expectations — which is also the exact shape of the
     * defect CLAUDE.md's updater landmine describes, a read that leaves state
     * behind.
     */
    bpeOwner();
    $set = bpeSet(2);
    $cards = $set->cards()->get();

    $body = test()->postJson('/admin-api/banners/sets/'.$set->id.'/preview', [
        'set' => ['title_pos' => 'over', 'bg_mode' => 'color', 'bg_color' => '#102030', 'btn_bg' => '#abcdef'],
        'cards' => [
            ['id' => $cards[0]->id, 'heading' => 'Only in the preview'],
            ['id' => $cards[1]->id, 'heading' => 'Heading 2'],
        ],
    ])->assertOk()->json();

    expect($body['empty'])->toBeFalse();

    $markup = (string) preg_replace('#<style>.*?</style>#s', '', $body['html']);

    expect(str_contains($markup, 'Only in the preview'))->toBeTrue('the preview did not draw what was typed')
        ->and(str_contains($markup, 'is-over'))->toBeTrue()
        ->and(str_contains($markup, 'has-bg'))->toBeTrue()
        ->and(str_contains($markup, '--kbbn-btn-bg:#abcdef'))->toBeTrue();

    // NOTHING was written.
    $set->refresh();

    expect($set->title_pos)->toBe('below')
        ->and($set->bg_mode)->toBe('none')
        ->and((string) $set->btn_bg)->toBe('')
        ->and($cards[0]->fresh()->heading)->toBe('Heading 1');
});

it('reorders the preview from the buffer rather than from the stored positions', function () {
    /*
     * `position` is one of the fields the buffer carries, and the query that
     * loads the rows sorts by the STORED one — so without the re-sort in
     * previewDraft() dragging a card to the front looks like nothing happened
     * until Save, which is precisely the feedback the buffer exists to give.
     *
     * MUTATION: delete the usort() in previewDraft() and this is red.
     */
    bpeOwner();
    $set = bpeSet(2);
    $cards = $set->cards()->get();

    $body = test()->postJson('/admin-api/banners/sets/'.$set->id.'/preview', [
        'cards' => [
            ['id' => $cards[0]->id, 'position' => 9],
            ['id' => $cards[1]->id, 'position' => 1],
        ],
    ])->assertOk()->json();

    $markup = (string) preg_replace('#<style>.*?</style>#s', '', $body['html']);

    expect(strpos($markup, 'Heading 2'))->toBeLessThan((int) strpos($markup, 'Heading 1'));
});

it('answers empty rather than drawing a card the buffer has emptied of its picture', function () {
    // `Banners::load()` filters `image <> ''` for the reason BannerCard::
    // drawable() gives: "a card with no picture is an empty box the width of
    // its neighbours, which is worse than one card fewer". The preview has to
    // apply the same rule to the buffer or it shows a row the shop never draws.
    bpeOwner();
    $set = bpeSet(1);
    $card = $set->cards()->first();

    $body = test()->postJson('/admin-api/banners/sets/'.$set->id.'/preview', [
        'cards' => [['id' => $card->id, 'image' => '']],
    ])->assertOk()->json();

    expect($body['empty'])->toBeTrue();
});

/* ══════════════ 4. the unsaved marker and the guard on leaving ════════════ */

it('marks unsaved work and keeps it, without asking, at every door out of the editor', function () {
    /*
     * A buffered editor without these is worse than auto-save: the owner types
     * for five minutes, clicks another screen, and the work is gone with no
     * warning — which is the one failure mode auto-save did not have.
     *
     * FOUR DOORS, and all four are covered: the sidebar (the go() wrapper),
     * another set in the list, a structural action that reloads the editor, and
     * closing the tab.
     *
     * ── THE DOORS NO LONGER ASK. THE OWNER, 1 OCTOBER 2026 (Lane PM) ───────
     *
     * "when now i leave anything un-saved, it keep giving me weired popup ...
     * it should not give me the weired warning like thing." The three doors
     * used to stop him with window.confirm("You have N unsaved change(s) to
     * this set … Leave them?") and the fourth with the browser's "Leave site?".
     * Now mayLeave() hands the buffer to Unfinished in the top bar
     * (partials/unfinished-drafts.blade.php) and lets him through, and there is
     * no beforeunload at all. The work is still never lost — that half of this
     * test's job is unchanged — it is kept instead of guarded.
     *
     * MUTATIONS, run: remove the `mayLeave();` call from the go() wrapper and
     * this is red on the second expectation; put the window.confirm() back
     * into mayLeave() and it is red on `not->toContain('confirm(')`.
     */
    $screen = bpeScreen();

    expect(str_contains($screen, 'function mayLeave('))->toBeTrue()
        ->and(str_contains($screen, "mayLeave();\n      draft = null;"))->toBeTrue()
        ->and(str_contains($screen, "addEventListener('beforeunload'"))->toBeFalse();

    $from = (int) strpos($screen, 'function mayLeave(');
    $leave = substr($screen, $from, (int) strpos($screen, "\n  }\n", $from) - $from);

    expect($leave)->not->toContain('confirm(')
        ->and($leave)->toContain("window.kbbDrafts.flush('banners')");

    /*
     * Every structural action still goes through mayLeave() first, because
     * performing one reloads the editor and would take the buffer with it —
     * mayLeave() is now what writes the buffer to Unfinished before that
     * happens. Each is located by the line that WIRES it, and the call has to
     * be inside that handler.
     */
    foreach ([
        /*
         * ── THE FIRST ANCHOR MOVED IN ROUND 8 — Lane BN2 ────────────────────
         *
         * It was `var add = document.querySelector('#bns-new');`. There are two
         * "new set" buttons now, one per banner type, so they are wired by one
         * `[data-bns-kind]` loop instead of by an id. THE PROPERTY IS THE SAME
         * AND SO IS THE RISK: creating a set reloads the editor and would take
         * the buffer with it, so the handler has to ask first — and now it has
         * to ask for BOTH buttons, which one loop guarantees and two separate
         * handlers would not.
         *
         * MUTATION, run: delete the `mayLeave(` line from that loop and this is
         * red naming it.
         */
        "document.querySelectorAll('[data-bns-kind]').forEach",
        "document.querySelectorAll('[data-bns-open]').forEach",
        "document.querySelectorAll('[data-bns-dup]').forEach",
        "var newCard = document.querySelector('#bns-newcard');",
    ] as $control) {
        $from = (int) strpos($screen, $control);

        expect($from)->toBeGreaterThan(0, "the handler for {$control} is gone");
        expect(str_contains(substr($screen, $from, 420), 'mayLeave('))->toBeTrue(
            "{$control} can throw away unsaved work without asking"
        );
    }

    // The marker itself counts, rather than merely saying "yes": a count is how
    // the owner tells whether the thing he just changed registered.
    expect(str_contains($screen, "' unsaved change' + (n === 1 ? '' : 's')"))->toBeTrue();

    /*
     * AND A FAILED SAVE KEEPS THE DRAFT. This is the one moment the buffer is
     * the only copy of what was typed, and clearing it here would be the
     * swallowed-write defect CLAUDE.md catalogues wearing different clothes.
     *
     * MUTATION: move `startDraft()` out of the try and into a finally and this
     * is red.
     */
    $saveFrom = (int) strpos($screen, "var saveSet = document.querySelector('#bns-saveset')");
    $saveTo = (int) strpos($screen, "var discard = document.querySelector('#bns-discard')", $saveFrom);

    expect($saveFrom)->toBeGreaterThan(0)->and($saveTo)->toBeGreaterThan($saveFrom);

    // Bounded at Discard, which rebuilds the draft ON PURPOSE — that is what
    // discarding is. The assertion is about the SAVE handler alone.
    $save = substr($screen, $saveFrom, $saveTo - $saveFrom);

    expect(substr_count($save, 'startDraft()'))->toBe(1, 'the draft is rebuilt on a path other than a successful save');

    // And it is inside the try, above the catch — the catch keeps what was
    // typed and says so.
    expect((int) strpos($save, 'startDraft()'))->toBeLessThan((int) strpos($save, '} catch (e) {'));
    expect(str_contains($save, 'your changes are still here'))->toBeTrue();
});

/* ══════════════════════ 5. speed, made findable ═══════════════════════════ */

it('shows the speed as a named band and a loop time, and runs the slider the right way', function () {
    /*
     * ── THE OWNER SAID THE CONTROL DID NOT EXIST; IT DID ────────────────────
     *
     * "you didn't gave controls for speed". It was there: a slider labelled
     * "Speed", helped "milliseconds per card", ninth in a grid of nine numeric
     * boxes. Three things were wrong and none of them was that it was missing —
     * the unit is one nobody thinks in, dragging RIGHT made it SLOWER (the
     * column is a duration), and it was in the wrong place.
     *
     * The column is untouched: `speed_ms` is still milliseconds per card, still
     * what Banners::cssVariables() multiplies by the card count. The slider is
     * mirrored in the SCREEN only.
     *
     * MUTATION, run: change `lim[0] + lim[1] - Number(speed.value)` to
     * `Number(speed.value)` and this is red on the mirror expectation — and on
     * the shop the slider would go back to meaning the opposite of its own
     * "slower / faster" ends.
     */
    $screen = bpeScreen();

    expect(str_contains($screen, 'function speedBands('))->toBeTrue()
        ->and(str_contains($screen, "'Very slow'"))->toBeTrue()
        ->and(str_contains($screen, 's for one full loop of '))->toBeTrue()
        ->and(str_contains($screen, 'ms per card)'))->toBeTrue('the raw number is no longer reachable')
        ->and(str_contains($screen, 'var mirrored = lim[0] + lim[1] - value;'))->toBeTrue()
        ->and(str_contains($screen, 'var ms = lim[0] + lim[1] - Number(speed.value);'))->toBeTrue()
        ->and(str_contains($screen, '<div class="bns-ends"><span>slower</span><span>faster</span></div>'))->toBeTrue();

    // And it is the FIRST control under Motion, not ninth among the sizes.
    $motion = (int) strpos($screen, "html += '<div class=\"bns-sec\">Motion</div>");
    $shape = (int) strpos($screen, 'Cards &amp; shape');

    expect($motion)->toBeGreaterThan(0)
        ->and((int) strpos($screen, 'speedField(s.speed_ms'))->toBeGreaterThan($motion)
        ->and((int) strpos($screen, 'speedField(s.speed_ms'))->toBeLessThan($shape);
});

it('draws the preview in the shop’s own palette, not a lookalike', function () {
    /*
     * ── A SMALL FINDING, AND THE SCREEN WAS ASSERTING THE OPPOSITE ──────────
     *
     * The preview frame declared `--pink:#E8919F`. The storefront's `--pink` is
     * `#E0567B` (resources/css/kbb/kbb.css:2), and the card partial draws its
     * button `background:var(--pink,#E8919F)` — so every button in the preview
     * was painted in the FALLBACK colour while the line above it said "Drawn by
     * the shop's own template ... so what is here is what the homepage draws".
     * `--muted` and `--line` were wrong the same way, and `--line` is the dots.
     *
     * An owner choosing a button colour against that preview is choosing it
     * against the wrong pink.
     *
     * MUTATION: put `--pink:#E8919F` back in paintPreview() and this is red.
     */
    $screen = bpeScreen();
    $css = (string) file_get_contents(base_path('resources/css/kbb/kbb.css'));

    preg_match('/--pink:(#[0-9A-Fa-f]{6})/', $css, $pink);
    preg_match('/--muted:(#[0-9A-Fa-f]{6})/', $css, $muted);

    expect($pink)->not->toBeEmpty();

    expect(str_contains($screen, '--pink:'.$pink[1]))->toBeTrue('the preview is not drawing the shop’s pink')
        ->and(str_contains($screen, '--muted:'.$muted[1]))->toBeTrue('the preview is not drawing the shop’s muted ink');
});
