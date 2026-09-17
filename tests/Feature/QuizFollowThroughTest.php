<?php

declare(strict_types=1);

use App\Mail\QuizPlanEmail;
use App\Models\Product;
use App\Models\QuizSubmission;
use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;
use App\Support\QuizRoutineLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Lane FT — the two things the skin quiz promised and did not do.
 *
 * ── 1. THE HAND-OFF TO BUILD MY ROUTINE ────────────────────────────────────
 *
 * Lane FM built /routines/{concern} and wrote App\Support\RoutineConcerns with
 * the quiz's own eight concern strings in it, character for character, so that
 * joining the two would be a LOOKUP. Nothing did the joining. The module ships
 * OFF and /routines 404s in that state, so the one property that matters more
 * than the link existing is the link NOT existing until somebody switches the
 * feature on: a quiz that offers a dead end is worse than one that offers none.
 * Both states are proved here by fetching, not by reading a flag.
 *
 * ── 2. THE EMAIL THE CONTACT STEP PROMISED ─────────────────────────────────
 *
 * "We'll save your results & email your plan. No spam, ever." sits directly
 * under the box this shop asks for an address in, and the results screen says
 * it again ("emailed to :email"). The shop saved the results and emailed
 * nothing. That was re-established here by running before anything was built —
 * an unmodified POST /api/quiz passed Mail::assertNothingOutgoing() — and the
 * reason the risk register gave for it being unbuildable does not hold: the
 * default transport needs no SMTP credentials at all (MailSettings::transport()
 * is 'server' on a shop with nothing filled in), and this shop already sends
 * order confirmations and newsletter confirmations through it.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ──────────────────────────────────────────
 *
 * Nothing in this file relaxes what the quiz stores. The allergy free text and
 * the allergen list are health data with no column and no permission, and
 * ApiSecurityTest's "stores nothing the contact form did not ask permission to
 * keep" is the line; the case at the bottom of this file adds a second lock on
 * the same door from the mail side, because an email is a copy of the record
 * that leaves the building.
 */
beforeEach(function () {
    /*
     * The migration set seeds a demo catalogue of untagged products, and an
     * UNTAGGED product suits every routine — BuildMyRoutine's rule is that a
     * product naming no concern fits any of them. Left in place, "this shop
     * stocks nothing for that concern" could not be stated by any test in this
     * file, because it would never be true. Cleared, so each case below states
     * its own catalogue in full.
     */
    Product::query()->forceDelete();
});

/** One product the routine engine will actually offer. */
function ftProduct(string $role, array $concerns = []): Product
{
    return Product::create([
        'slug' => Str::slug('ft-' . $role) . '-' . Str::random(6),
        'name' => 'FT ' . ucfirst($role),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'routine_role' => $role,
        'routine_concerns' => $concerns === [] ? null : json_encode($concerns),
    ]);
}

function ftRoutinesOn(): void
{
    app(SettingsService::class)->setModule('build_my_routine', true);
}

/** The table the quiz emits for its own script, decoded, or null. */
function ftRoutineTable(string $html): ?array
{
    if (preg_match('/window\.KBB_ROUTINES = (\{.*?\});<\/script>/s', $html, $m) !== 1) {
        return null;
    }

    return json_decode($m[1], true);
}

/** The body a completed quiz posts, reduced to what this file needs. */
function ftPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'skinType' => 'Dry',
        'concerns' => ['Hydration', 'Dullness & glow'],
        'contact' => ['name' => 'Aisha', 'phone' => '+971500000000', 'email' => 'aisha@example.test'],
        'recommendedRoutines' => [
            ['name' => 'Everyday Essentials', 'steps' => ['Cleanse (oil first)', 'Treat', 'Moisturise (richer)', 'Protect']],
        ],
        'consent' => true,
    ], $overrides);
}

/* ───────────────── 1. the hand-off, in both of its two states ────────────── */

it('offers no routine link at all while the module is off, because /routines is a 404', function () {
    /*
     * THE SHIPPED STATE, PROVED BY FETCHING BOTH ENDS. The 404 is the reason
     * the absence matters: a link rendered here would be a link into nothing.
     *
     * A STOCKED CATALOGUE, and this is not decoration. With no products every
     * routine is empty, the table would be null for that reason instead, and
     * this case would pass against a build with no module check in it at all —
     * measured: deleting the `enabled()` guard left all thirteen cases green
     * until these two products were added. The only thing keeping the link off
     * the page below is the switch.
     */
    ftProduct('cleanse');
    ftProduct('treat');

    $this->get('/routines')->assertNotFound();
    $this->get('/routines/hydration/')->assertNotFound();

    $html = $this->get('/skin-quiz')->assertOk()->getContent();

    /*
     * The ASSIGNMENT, not the name. `window.KBB_ROUTINES` is READ by the
     * page's script on every render — that is how the feature switches itself
     * off in the browser — so the identifier is in the document either way and
     * a test looking for the bare word would fail against a page that is
     * behaving perfectly. What must not be there is the table.
     */
    expect(str_contains($html, 'window.KBB_ROUTINES = '))
        ->toBeFalse('The quiz emitted its routine table with the module switched off.');
    expect(str_contains($html, '/routines/'))
        ->toBeFalse('The quiz linked to a routine page that answers 404.');

    // And the decision itself, asked directly, so a page that happened not to
    // print the string for some other reason cannot make this case pass.
    expect(QuizRoutineLink::map())->toBeNull();
});

it('links the concern the shopper picked once the module is on', function () {
    ftRoutinesOn();
    ftProduct('cleanse');
    ftProduct('treat');

    $html = $this->get('/skin-quiz')->assertOk()->getContent();
    $table = ftRoutineTable($html);

    expect($table)->toBeArray();

    /*
     * KEYED BY THE QUIZ'S OWN ENGLISH, ampersand and all. This is the whole
     * point of App\Support\RoutineConcerns carrying the quiz's strings
     * character for character: the lookup is `table[state.concerns[i]]`, so a
     * key spelled any other way is a link that never appears.
     */
    expect($table['Hydration'] ?? null)->toContain('/routines/hydration/');
    expect($table['Dark spots & tone'] ?? null)->toContain('/routines/dark-spots/');

    // Every URL in the table is a page that answers.
    $this->get($table['Hydration'])->assertOk();

    // And the concern the shopper picked FIRST is the one chosen, with no
    // fallback to a routine they did not ask for.
    expect(QuizRoutineLink::forConcerns(['Sun protection', 'Hydration']))
        ->toBe($table['Sun protection']);
    expect(QuizRoutineLink::forConcerns(['not a concern anybody offers']))->toBeNull();
});

it('leaves out a concern this shop stocks nothing for', function () {
    /*
     * Lane FM's own rule, applied to the link: RoutineController::index() drops
     * a routine no step of which can be filled, because a card that opens onto
     * five "we don't stock this" rows is worse than no card. A link out of the
     * quiz is a stronger promise than a card on a list.
     *
     * One product, tagged for acne only, so acne is the single concern with
     * anything behind it.
     */
    ftRoutinesOn();
    ftProduct('cleanse', ['acne']);

    $table = QuizRoutineLink::map();

    expect(array_keys($table ?? []))->toBe(['Acne & blemishes']);

    $html = $this->get('/skin-quiz')->assertOk()->getContent();

    expect(str_contains($html, '/routines/hydration/'))
        ->toBeFalse('The quiz linked to a routine with nothing in it.');
});

it('says nothing about routines when the module is on and the shop is empty', function () {
    ftRoutinesOn();

    expect(QuizRoutineLink::map())->toBeNull();

    $html = $this->get('/skin-quiz')->assertOk()->getContent();

    expect(str_contains($html, 'window.KBB_ROUTINES = '))
        ->toBeFalse('The quiz emitted an empty routine table.');
});

/* ─────────────────── 2. the email the contact step promises ──────────────── */

it('emails the plan the contact step promised', function () {
    Mail::fake();

    $this->postJson('/api/quiz', ftPayload())->assertCreated();

    Mail::assertSent(QuizPlanEmail::class, 1);

    Mail::assertSent(QuizPlanEmail::class, function (QuizPlanEmail $mail) {
        expect($mail->hasTo('aisha@example.test'))->toBeTrue();

        $rendered = $mail->render();

        // The plan itself: the routine the page worked out and its steps, in
        // order. Nothing else is a plan.
        expect($rendered)->toContain('Everyday Essentials')
            ->toContain('Cleanse (oil first)')
            ->toContain('Protect');

        // The shopper's own answers, said back to them.
        expect($rendered)->toContain('Dry')->toContain('Hydration');

        return true;
    });
});

it('names no product and quotes no price, because the page it describes names none', function () {
    /*
     * The quiz used to recommend seventeen products this shop does not sell, at
     * prices nobody set, under a 15% bundle saving that never existed. Lane FB
     * deleted all of it. An email is the worst place for it to come back,
     * because it is kept and forwarded and quoted at the shop months later.
     */
    Mail::fake();

    $this->postJson('/api/quiz', ftPayload([
        'recommendedRoutines' => [[
            'name' => 'Everyday Essentials',
            'steps' => ['Cleanse'],
            // Posted, and dropped by Api\QuizController::routinesFrom() before
            // it is ever stored — so it cannot reach the email either.
            'products' => [['name' => 'Beauty of Joseon Relief Sun', 'price' => 4900]],
            'price' => 19900,
            'saving' => '15%',
        ]],
    ]))->assertCreated();

    Mail::assertSent(QuizPlanEmail::class, function (QuizPlanEmail $mail) {
        $rendered = $mail->render();

        expect(str_contains($rendered, 'Beauty of Joseon'))
            ->toBeFalse('An invented product reached the plan email.');
        expect(str_contains($rendered, '15%'))
            ->toBeFalse('An invented saving reached the plan email.');
        expect(str_contains($rendered, '199'))
            ->toBeFalse('A price nobody set reached the plan email.');

        return true;
    });
});

it('points the email at the shopper\'s routine when there is one, and at the shop when there is not', function () {
    Mail::fake();

    $this->postJson('/api/quiz', ftPayload())->assertCreated();

    Mail::assertSent(QuizPlanEmail::class, function (QuizPlanEmail $mail) {
        expect(str_contains($mail->render(), '/routines/'))
            ->toBeFalse('The plan email linked to a routine page that answers 404.');

        return true;
    });

    ftRoutinesOn();
    ftProduct('cleanse');

    $this->postJson('/api/quiz', ftPayload())->assertCreated();

    Mail::assertSent(QuizPlanEmail::class, 2);

    $withRoutine = collect(Mail::sent(QuizPlanEmail::class))->last();

    expect($withRoutine->render())->toContain('/routines/hydration/');
});

it('sends nothing when there is no address, and nothing when there is no plan', function () {
    Mail::fake();

    // No address: there is nobody to send to, and the promise was made to the
    // box they left empty.
    $this->postJson('/api/quiz', ftPayload(['contact' => ['email' => null]]))->assertCreated();

    // No routines: an email whose whole body is a greeting is not the plan that
    // was promised, and sending it would make the delivery log claim one went.
    $this->postJson('/api/quiz', [
        'contact' => ['email' => 'empty@example.test'],
        'concerns' => ['Hydration'],
    ])->assertCreated();

    expect(QuizSubmission::count())->toBe(2);

    Mail::assertNothingOutgoing();
});

it('refuses an address this shop will not put in a mail header', function () {
    /*
     * CLAUDE.md records CRLF injection in the framework's `email` rule as an
     * open advisory. Rules\StorefrontEmail's header names the condition that
     * makes it reachable — an unauthenticated stranger hands us an address and
     * we put an address into a message — and this endpoint became that the
     * moment it started sending. Both payloads below are the ones
     * CrlfMailSinkTest proves the installed rule lets through.
     */
    Mail::fake();

    foreach (["\"us\r\ner\"@example.com", "user\r\n @example.com"] as $address) {
        $this->postJson('/api/quiz', ftPayload(['contact' => ['email' => $address]]))
            ->assertStatus(422);
    }

    expect(QuizSubmission::count())->toBe(0);

    Mail::assertNothingOutgoing();
});

it('sends the plan in the language the quiz was read in', function () {
    /*
     * THE PAGE POSTS TO AN UNPREFIXED ENDPOINT. `const API=''` and a literal
     * '/api/quiz', so an Arabic shopper on /ar/skin-quiz submits to the English
     * URL and app()->getLocale() is 'en' for somebody who has been reading
     * Arabic for a minute and a half. The Referer is the only thing in the
     * request that knows, and this row already trusts it for `source_url`.
     */
    Setting::query()->updateOrCreate(
        ['key' => Locale::SETTING_ENABLED],
        ['value' => '1', 'autoload' => true]
    );

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();

    TranslationStore::put('ar', 'ui', 0, 'email.quiz_plan.lead', 'هذه خطتك.',
        Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL);

    Mail::fake();

    $this->withHeaders(['referer' => 'https://kbeautybliss.com/ar/skin-quiz/'])
        ->postJson('/api/quiz', ftPayload())
        ->assertCreated();

    Mail::assertSent(QuizPlanEmail::class, function (QuizPlanEmail $mail) {
        expect($mail->render())->toContain('هذه خطتك.');

        return true;
    });

    // And the English page still gets the English plan, so the Referer is a
    // hint and not a switch that can only be thrown one way.
    $this->withHeaders(['referer' => 'https://kbeautybliss.com/skin-quiz/'])
        ->postJson('/api/quiz', ftPayload())
        ->assertCreated();

    $english = collect(Mail::sent(QuizPlanEmail::class))->last();

    expect(str_contains($english->render(), 'هذه خطتك.'))
        ->toBeFalse('An English shopper was sent the Arabic plan.');
});

it('labels the send, so the owner can find it on Sent mail', function () {
    /*
     * NOT Mail::fake() — the delivery log is written by the mail events, and a
     * fake never sends, so a faked send records nothing. The array transport
     * the test environment configures goes all the way through the events and
     * delivers nowhere, which is exactly what this needs.
     */
    $this->postJson('/api/quiz', ftPayload())->assertCreated();

    $row = DB::table('mail_deliveries')->latest('id')->first();

    expect($row)->not->toBeNull();
    expect($row->kind)->toBe('quiz.plan');
    expect($row->recipient)->toContain('aisha@example.test');
});

it('captures the lead even when the mail server is down, and says so where it can be read', function () {
    /*
     * The email is a consequence of the capture, never a precondition for it.
     * A shopper who finished the quiz must not lose their lead — or see a 500 —
     * because a mail host is refusing connections.
     *
     * THE LOG LINE IS ASSERTED AS WELL AS THE 201, and that is what makes this
     * case bite. The 201 alone survives deleting the catch block outright:
     * the send runs in a deferred callback after the response has already been
     * built, so the response is a 201 either way — measured, by replacing
     * `catch (\Throwable)` with a class that cannot match and watching every
     * case stay green. What the catch block actually buys is that the failure
     * is RECORDED rather than lost, which is the whole of CLAUDE.md's standing
     * complaint about swallowed mail failures on a host with no shell.
     */
    Log::spy();

    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('smtp is down'));

    $this->postJson('/api/quiz', ftPayload())->assertCreated();

    expect(QuizSubmission::count())->toBe(1);
    expect(QuizSubmission::latest('id')->first()->email)->toBe('aisha@example.test');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []) => $message === 'A skin-quiz plan could not be emailed.'
            && ($context['exception'] ?? null) === RuntimeException::class
    );
});

it('puts none of the allergy answers in the email either', function () {
    /*
     * THE SAME LINE ApiSecurityTest DRAWS, HELD FROM THE OTHER SIDE. The
     * allergy step promises only to steer around ingredients while the quiz is
     * on screen; the free-text box beside it is where somebody types
     * "pregnant"; there is no column and no permission. An email is a copy of
     * the record that leaves the building, so it is worth asserting separately
     * that the health answers cannot ride out in one — a later lane that added
     * a column would turn this red as well as that one.
     */
    Mail::fake();

    $this->postJson('/api/quiz', ftPayload([
        'answers' => ['allergies' => ['Fragrance / parfum'], 'allergyNote' => 'pregnant, on tretinoin'],
    ]))->assertCreated();

    Mail::assertSent(QuizPlanEmail::class, function (QuizPlanEmail $mail) {
        $rendered = $mail->render();

        expect(str_contains($rendered, 'pregnant'))->toBeFalse('The allergy note reached the email.');
        expect(str_contains($rendered, 'tretinoin'))->toBeFalse('The allergy note reached the email.');
        expect(str_contains($rendered, 'Fragrance'))->toBeFalse('The allergen list reached the email.');

        return true;
    });
});
