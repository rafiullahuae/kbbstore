<?php

declare(strict_types=1);

/**
 * Store → Mail: a value the writer refuses is SAID OUT LOUD — Lane Q11.
 *
 * ── THE DEFECT, AS IT BEHAVED ON THE SHOP ───────────────────────────────────
 *
 * Driven first-hand against the parent revision before a line of this was
 * written, through the real endpoint with a real owner session:
 *
 *     POST /admin-api/mail {"settings":{"mail_reply_to":"not an address"}}
 *     → HTTP 200   {"ok":true,"configured":true,"missing":[]}
 *     → settings.mail_reply_to still 'good@example.com'
 *
 * The screen said Saved. The address did not move. A shop owner who believes he
 * has changed where customer replies land finds out from a customer who replied
 * into a void — which is the failure mode this whole screen is afraid of, in its
 * quietest form.
 *
 * MailSettings::addressOrDrop() returns null to refuse; ModuleSchema::write()
 * reports the refusal in its `rejected` map; MailSettings::save() threw that map
 * away. It is returned now, and MailApiController::save() answers 422 with the
 * owner-facing label.
 *
 * ── THE PART THAT MAKES THIS A CLASS AND NOT AN INSTANCE ────────────────────
 *
 * Round 4 §11 proposed the fix as "one line plus an error on the screen": add an
 * `email` entry for mail_reply_to to MailApiController::$checks, the way
 * mail_merchant_address already has one. That is NOT the fix, and the corpus
 * case below is why — measured, not reasoned:
 *
 *   `$checks` is Laravel's `email` rule. The writer is
 *   `filter_var(FILTER_VALIDATE_EMAIL)`. THEY DISAGREE.
 *
 *   Six spellings pass `email` and are refused by the writer — `a@b`,
 *   `a@example`, `a@127.0.0.1`, a quoted local part, and two with non-ASCII. So
 *   mail_merchant_address, the field round 4 believed was covered BECAUSE it is
 *   in `$checks`, had the identical silent drop for every one of them. The
 *   proposed line would have moved the silence from one address box to the
 *   other, not removed it.
 *
 * Returning the writer's own verdict cannot drift from what the writer stored,
 * because it IS what the writer stored. That is the whole argument for the shape
 * of this fix.
 *
 * ▲ AND THE OBVIOUS SECOND ARGUMENT IS WRONG, which is recorded because it was
 * believed for an hour. The disagreement runs the other way too — `'a@b.co '`,
 * `' a@b.co'` and `"a@b.co\n"` are refused by Laravel's rule and
 * trimmed-and-stored by the writer — so the rule looks as though it would ALSO
 * have broken a save that works. It would not. TrimStrings runs before the
 * controller and all three arrive already trimmed; driven through the real
 * endpoint they answer 200 and store `a@b.co` with the rule and without it.
 * Measured, not reasoned: the case below drives them anyway, as a rule-1 pin on
 * what this screen stores, but it is NOT evidence against the rule.
 *
 * ── WHAT IS DELIBERATELY UNCHANGED ──────────────────────────────────────────
 *
 * Nothing about what is STORED. A refused value is still dropped and the
 * previous working mailbox still left alone; the other keys in the same payload
 * are still written; `$checks` still refuses before anything is written for the
 * cases it catches. Only the answer moved.
 *
 * MUTATION NOTES are at the foot of the file: fourteen run, with what each
 * printed, including the three that came back GREEN.
 */

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\Mail\MailSettings;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

/**
 * The owner, made fresh each test — RefreshDatabase rolls the row back between
 * tests and a memoised static would authenticate as a user that is gone.
 */
function q11Admin(): AdminUser
{
    return AdminUser::firstOrCreate(
        ['email' => 'q11-owner@example.com'],
        ['name' => 'Owner', 'password' => 'secret-secret', 'role' => 'owner'],
    );
}

/** Post to the real Store → Mail endpoint and hand back the response. */
function q11Save(array $settings): Illuminate\Testing\TestResponse
{
    $r = test()->actingAs(q11Admin(), 'admin')
        ->postJson('/admin-api/mail', ['settings' => $settings]);

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    return $r;
}

/** The stored row, read past every cache and every re-derivation. */
function q11Stored(string $key): mixed
{
    return app(SettingsService::class)->get($key);
}

/**
 * The nine addresses the two validators disagree about, and which way.
 *
 * Derived here rather than listed, so the sets below are what the two functions
 * really do on THIS Laravel and THIS libc — not what they did when this file was
 * written. `q11Disagreements()['writer_refuses']` is the set that was silently
 * dropped; `['rule_refuses']` is the set an `email` entry in $checks would have
 * broken.
 *
 * @return array{writer_refuses: list<string>, rule_refuses: list<string>}
 */
function q11Disagreements(): array
{
    $corpus = [
        // ordinary, and accepted by both — the control group
        'plain@example.com', 'first.last@sub.domain.co.uk', 'x+tag@example.com',
        'UPPER@Example.COM', 'a@b.c', "o'brien@example.com",
        // refused by both
        'no-at-sign', 'two@@example.com', 'space in@example.com',
        '@example.com', 'a@', 'a@.com', 'a@b..co',
        // the interesting ones
        'a@b', 'a@example', 'a@127.0.0.1', '"quoted local"@example.com',
        'ünïcode@example.com', 'a@exämple.com',
        'a@b.co ', ' a@b.co', "a@b.co\n",
    ];

    $field = MailSettings::schema()['mail_reply_to'];
    $out = ['writer_refuses' => [], 'rule_refuses' => []];

    foreach ($corpus as $value) {
        $rulePasses = ! Validator::make(['x' => $value], ['x' => ['string', 'email', 'max:255']])->fails();
        $writerKeeps = MailSettings::addressOrDrop($value, $field) !== null;

        if ($rulePasses && ! $writerKeeps) {
            $out['writer_refuses'][] = $value;
        }

        if (! $rulePasses && $writerKeeps) {
            $out['rule_refuses'][] = $value;
        }
    }

    return $out;
}

/* ═══════════════════════════════════════════════════════════════════════════
   1 · THE DEFECT ITSELF
   ═════════════════════════════════════════════════════════════════════════ */

it('tells the owner a mistyped Reply-To was not saved, instead of answering ok', function () {
    /*
     * Before this fix this whole case answered 200 {"ok":true}. The stored-value
     * assertion passed then too — which is the point: the row was right and the
     * ANSWER was a lie.
     */
    q11Save(['mail_reply_to' => 'replies@example.com'])->assertOk();
    expect(q11Stored('mail_reply_to'))->toBe('replies@example.com');

    $r = q11Save(['mail_reply_to' => 'not an address']);

    expect($r->status())->toBe(422, 'a mistyped Reply-To still answered success');
    expect($r->json('ok'))->toBeFalse();
    expect($r->json('rejected'))->toBe(['mail_reply_to']);

    // The owner has to be able to tell WHICH box, in the words on the screen.
    expect($r->json('error'))->toContain('Reply-To address');
    // …and that the old one is still in force, or he will assume it was wiped.
    expect($r->json('error'))->toContain('unchanged');

    // And the behaviour that must NOT have moved: the previous working mailbox.
    expect(q11Stored('mail_reply_to'))->toBe('replies@example.com');
    expect(app(MailSettings::class)->all()['mail_reply_to'])->toBe('replies@example.com');
});

it('reports the refusal without changing what a refused save stores', function () {
    /*
     * NOT ATOMIC, on purpose. A refused key leaves its own row alone and every
     * other key in the payload is written, which is exactly what happened before
     * this change. Only the answer moved. Making it atomic would have changed
     * what is stored for a payload that is accepted today.
     */
    q11Save(['mail_reply_to' => 'replies@example.com', 'mail_from_name' => 'Before'])->assertOk();

    $r = q11Save(['mail_reply_to' => 'not an address', 'mail_from_name' => 'After']);

    expect($r->status())->toBe(422);
    expect(q11Stored('mail_from_name'))->toBe('After', 'the siblings of a refused key stopped being written');
    expect(q11Stored('mail_reply_to'))->toBe('replies@example.com');
});

/* ═══════════════════════════════════════════════════════════════════════════
   2 · THE CLASS — the same shape in the field that was supposed to be fixed
   ═════════════════════════════════════════════════════════════════════════ */

it('finds the two validators disagreeing in both directions', function () {
    /*
     * The premise of the two cases below, measured rather than asserted. If this
     * ever comes back empty the cases after it are proving nothing, and they say
     * so by failing here first.
     */
    $d = q11Disagreements();

    expect($d['writer_refuses'])->not->toBeEmpty(
        'no address passes Laravel email and is refused by the writer, so $checks WOULD have been the fix'
    );
    expect($d['rule_refuses'])->not->toBeEmpty(
        'the two validators no longer disagree the other way, so the whitespace pin below drives nothing'
    );

    // Named, so a change in either validator reads as a change rather than as
    // this file quietly covering fewer spellings.
    expect($d['writer_refuses'])->toContain('a@b', 'a@example', 'a@127.0.0.1');
    expect($d['rule_refuses'])->toContain('a@b.co ', ' a@b.co');
});

it('reports the spellings $checks lets through, on BOTH addresses', function () {
    /*
     * ── THIS IS THE ONE THAT SHOWS IT WAS NEVER ONE FIELD ──────────────────
     *
     * mail_merchant_address IS in MailApiController::$checks and round 4
     * therefore treated it as the covered one. It is not: `a@b` and `a@example`
     * pass Laravel's `email` rule, reach the writer, are refused by
     * filter_var, and — before this fix — came back 200 {"ok":true} with the
     * previous address still in the row. Identical defect, different field.
     */
    $spellings = q11Disagreements()['writer_refuses'];

    /*
     * THE FLOOR, AND IT IS NOT DECORATION. Mutation 7 below turns
     * addressOrDrop() into something that refuses nothing; this set then comes
     * back empty, the loop runs zero times and this case passed GREEN against a
     * writer that had stopped refusing anything at all. A derived fixture that
     * derives nothing is flat and cheap and proves nothing.
     */
    expect(count($spellings))->toBeGreaterThanOrEqual(3, 'the corpus derived no spelling to drive');

    foreach (['mail_reply_to' => 'Reply-To address', 'mail_merchant_address' => 'New-order alerts to'] as $key => $label) {
        q11Save([$key => 'known-good@example.com'])->assertOk();

        foreach ($spellings as $bad) {
            $r = q11Save([$key => $bad]);

            expect($r->status())->toBe(422, "{$key} answered success for " . var_export($bad, true));
            expect($r->json('rejected'))->toBe([$key]);
            expect($r->json('error'))->toContain($label);
            expect(q11Stored($key))->toBe('known-good@example.com', "{$key} moved for " . var_export($bad, true));
        }
    }
});

/* ═══════════════════════════════════════════════════════════════════════════
   3 · RULE 1 — what is accepted today is still accepted, and still stored
   ═════════════════════════════════════════════════════════════════════════ */

it('still stores every address it stored before, including the three an email rule would refuse', function () {
    /*
     * Rule 1: every address the screen stored before this change is still
     * stored, byte for byte, and still answers 200 with no `rejected` key.
     *
     * The three whitespace spellings are driven because addressOrDrop() and
     * Laravel's `email` rule disagree about them at the FUNCTION level. They
     * are not evidence against adding that rule: TrimStrings trims them before
     * the controller sees them, measured through this same endpoint, so the
     * rule would never have met one. They are here as a pin on the trimming,
     * which is the behaviour a refusal report must not disturb.
     */
    $accepted = array_merge(
        ['plain@example.com', 'first.last@sub.domain.co.uk', 'x+tag@example.com', "o'brien@example.com"],
        q11Disagreements()['rule_refuses'],
    );

    // Same floor as above: if the disagreement set empties, this case must say
    // so rather than quietly shrinking to the four literals.
    expect(count($accepted))->toBeGreaterThanOrEqual(7, 'the corpus derived no whitespace spelling to drive');

    foreach ($accepted as $value) {
        foreach (['mail_reply_to', 'mail_merchant_address'] as $key) {
            $r = q11Save([$key => $value]);

            expect($r->status())->toBe(200, "{$key} refused " . var_export($value, true) . ', which it stored before');
            expect($r->json('ok'))->toBeTrue();
            expect($r->json())->not->toHaveKey('rejected');
            expect(q11Stored($key))->toBe(trim($value), "{$key} stored something else for " . var_export($value, true));
        }
    }
});

it('leaves a blank box clearing the address, which is what blank means here', function () {
    // `blank => keep` on this schema: an emptied box means "there is no such
    // address", and every reader downstream falls back for itself. A refusal
    // report must not turn an empty box into an error.
    q11Save(['mail_reply_to' => 'replies@example.com'])->assertOk();

    $r = q11Save(['mail_reply_to' => '']);

    expect($r->status())->toBe(200);
    expect($r->json('ok'))->toBeTrue();
    expect(q11Stored('mail_reply_to'))->toBe('');
});

/* ═══════════════════════════════════════════════════════════════════════════
   4 · THE WHOLE SCREEN — which keys can be refused at all
   ═════════════════════════════════════════════════════════════════════════ */

it('sorts every key on this screen into who refuses it, and reports the ones only the writer does', function () {
    /*
     * ── THE WHOLE SCREEN, DERIVED RATHER THAN LISTED ───────────────────────
     *
     * Every key in MailSettings::SCHEMA is driven twice and its answer sorted
     * into one of three buckets:
     *
     *   checks   MailApiController::$checks refused it — Laravel's own 422,
     *            and NOTHING in the payload is written. Unchanged by this lane.
     *   writer   it got past $checks and MailSettings::save() refused it. THIS
     *            is the bucket that was silent: before this lane every key in
     *            it answered 200 {"ok":true} and kept its previous value.
     *   stored   accepted.
     *
     * Two probes, because one cannot reach both bucket boundaries: a generic
     * junk string, and an address that passes Laravel's `email` rule and is
     * refused by filter_var, which is the only way to reach the writer on a key
     * $checks guards. The second probe is taken from the measured disagreement
     * above, not typed in here.
     *
     * A field that gains a rule later lands in `writer` and this case reddens
     * naming it — which is how a third silent drop is noticed rather than
     * shipped. A field that loses one reddens it too.
     */
    $passesEmail = q11Disagreements()['writer_refuses'];

    expect($passesEmail)->not->toBeEmpty('no probe exists that reaches the writer past $checks');

    $probes = [
        'junk' => 'not an address — and far too long ' . str_repeat('x', 40),
        'passes_email' => $passesEmail[0],
    ];

    $buckets = [];

    foreach ($probes as $name => $probe) {
        $bucket = ['checks' => [], 'writer' => [], 'stored' => []];

        foreach (array_keys(MailSettings::SCHEMA) as $key) {
            if ($key === 'mail_password') {
                continue;   // a secret is never cast; write() routes it to the vault
            }

            $r = q11Save([$key => $probe]);

            if ($r->status() === 200) {
                $bucket['stored'][] = $key;
            } elseif (is_array($r->json('rejected'))) {
                $bucket['writer'][] = $key;
            } else {
                $bucket['checks'][] = $key;
            }
        }

        $buckets[$name] = $bucket;
    }

    /*
     * With a generic junk string only mail_reply_to reaches the writer, because
     * mail_merchant_address is caught by $checks first — which is precisely why
     * round 4 believed that field was covered.
     */
    expect($buckets['junk']['writer'])->toBe(['mail_reply_to']);
    expect($buckets['junk']['checks'])->toContain('mail_merchant_address');

    /*
     * With an address $checks waves through, BOTH reach the writer and both are
     * now reported. Before this lane both answered 200.
     */
    expect($buckets['passes_email']['writer'])->toBe(['mail_merchant_address', 'mail_reply_to']);

    // And nothing else on the screen can be refused by either, on either probe.
    foreach ($buckets as $name => $b) {
        expect(array_diff($b['writer'], ['mail_merchant_address', 'mail_reply_to']))
            ->toBe([], "a key outside the two addresses was refused by the writer on the {$name} probe");
    }
});

/* ═══════════════════════════════════════════════════════════════════════════
   5 · THE CLASS GUARD — no caller may drop the report again
   ═════════════════════════════════════════════════════════════════════════ */

it('makes every ModuleSchema::write() caller hand the refusal back', function () {
    /*
     * ── WHY A SCAN AND NOT FOUR ASSERTIONS ─────────────────────────────────
     *
     * The defect was not "MailSettings forgot". It was that ModuleSchema::write()
     * returns a report which a caller is free to discard, silently, by writing
     * one statement instead of two — and MailSettings was the ONE caller of four
     * that did. PayShipRules, BuildMyRoutine and HomepageContent all pass it on.
     *
     * So the guard is over the callers this application HAS, found by reading
     * the source, rather than over a list somebody has to remember to extend.
     * A fifth caller written next month is scanned on the day it is written.
     */
    $roots = [__DIR__ . '/../../app'];
    $callers = [];

    foreach ($roots as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $src = file_get_contents($file->getPathname());

            // The CALL, not a mention of it in a comment — `::write(` with an
            // argument list, at the start of a statement or of an assignment.
            if (! preg_match_all('/([^\n]*)ModuleSchema::write\(/', $src, $m)) {
                continue;
            }

            foreach ($m[1] as $lead) {
                // A doc comment or a `//` line that merely names the method.
                if (preg_match('/^\s*(\*|\/\/|#)/', $lead)) {
                    continue;
                }

                $callers[] = [str_replace($root, 'app', $file->getPathname()), trim($lead)];
            }
        }
    }

    expect($callers)->not->toBeEmpty('the scan found no ModuleSchema::write() call at all');
    expect(count($callers))->toBeGreaterThanOrEqual(4, 'a caller disappeared from the scan');

    foreach ($callers as [$path, $lead]) {
        /*
         * Two honest shapes, and one dishonest one:
         *   `return ModuleSchema::write(...)`   hands the whole result back
         *   `$x = ModuleSchema::write(...)`     keeps it to hand back below
         *   `ModuleSchema::write(...);`         DROPS IT — the defect
         */
        expect($lead)->toMatch(
            '/(return|=)\s*$/',
            "{$path} calls ModuleSchema::write() and throws the refusal report away — "
            . 'a value the schema refused is then dropped in silence, which is exactly '
            . 'what a mistyped Reply-To did on Store → Mail'
        );
    }
});

it('keeps the two callers that take a report actually returning one', function () {
    // The scan above is textual. This is the behavioural half: the two services
    // this lane touched or leaned on really do answer a map.
    expect(app(MailSettings::class)->save(['mail_reply_to' => 'nope']))
        ->toBe(['mail_reply_to' => 'Reply-To address']);

    expect(app(MailSettings::class)->save(['mail_reply_to' => 'yes@example.com']))
        ->toBe([]);
});

/* ═══════════════════════════════════════════════════════════════════════════
   MUTATIONS — fourteen, every one applied, run and reverted. What each
   PRINTED is quoted, including the two that came back GREEN, which are the
   two that found something.
   ═══════════════════════════════════════════════════════════════════════════

    1  MailSettings::save() throws the report away again (call write(), then
       `$result = ['rejected' => []]`) — the defect, restored at the service.
       RUN: 6 failed, 3 passed. Cases 1, 2, 4, 7, the class scan
       ("Failed asserting that '' matches PCRE pattern /(return|=)\s*$/") and
       the service case. Case 1 prints
       "a mistyped Reply-To still answered success / Failed asserting that 200
       is identical to 422."

    2  MailApiController::save() drops the whole 422 branch and answers ok.
       RUN: 4 failed, 5 passed — cases 1, 2, 4, 7. The class scan stays green,
       which is the pair working as intended: the scan catches a SERVICE that
       drops the report, the endpoint cases catch a CONTROLLER that ignores it.
       Neither alone covers both.

    3  The 422 branch answers 200, body unchanged.
       RUN: 4 failed — every one on the status, three of them printing
       "Failed asserting that 200 is identical to 422." The console branches on
       `if(!r.ok||!d.ok)`, so the status is half of what makes the toast appear.

    4  The 422 branch drops `'ok' => false`, keeping the status.
       RUN: 1 failed — "Failed asserting that null is false." Worth its own pin:
       the console would still toast (it tests `!r.ok` first), so this is a
       silent hole for any other caller.

    5  The error sentence drops the label, keeping the keys.
       RUN: 2 failed — cases 1 and 4. The owner would be told something was
       refused without being told WHICH of the seventeen boxes.

    6  The error sentence drops "unchanged".
       RUN: 1 failed — case 1. An owner told only that it failed assumes the
       address was wiped and retypes it.

    7  addressOrDrop() returns '' instead of null for a malformed address —
       the ORIGINAL defect restored from the other end: the writer stops
       refusing, so there is nothing to report and the bad value is stored OVER
       the working mailbox.
       RUN: 7 failed, 2 passed. Case 1 prints -'replies@example.com' +''.
       ▲ Before the derived-fixture floors were added this mutation left case 4
       GREEN — `writer_refuses` came back empty, its loop ran zero times and the
       case asserted nothing against a writer that had stopped refusing
       anything. The floors in cases 4, 5 and 7 exist because of this run.

    8  Round 4 §11's PROPOSED FIX, applied INSTEAD of this one: the 422 branch
       removed and `'mail_reply_to' => ['string','email','max:255']` added to
       MailApiController::$checks.
       RUN: 4 failed, 5 passed. The decisive line:
           "mail_reply_to answered success for 'a@b'"
       and the same for 'a@example', 'a@127.0.0.1' and the three non-ASCII
       spellings, on mail_merchant_address as well — with the previous address
       still in the row. This is the measurement that the proposed one-liner
       moves the silence rather than removing it.

    9  The class scan skips every file whose path contains `Mail`.
       RUN: 1 failed — "Failed asserting that 3 is equal to 4 or is greater than
       4." Predicted green; the count floor caught it, which is the floor
       earning its place a second time.

   10  The scan's regex requires `return` and drops `|=`.
       RUN: 1 failed, naming app/Services/PayShipRules.php and printing
       "Failed asserting that '$result =' matches PCRE pattern /return\s*$/".
       Proof the scan reads the real leading text rather than matching anything.

   11  The scan's regex points at `ModuleSchema::writeNothing(`, a method that
       does not exist, AND the floors are removed.
       RUN: 9 passed — GREEN. The loop runs over zero callers and the case
       asserts nothing. This is the shape that produces a guard nobody can
       silence because there is nothing to silence.

   11b The same wrong method with the floors KEPT.
       RUN: 1 failed — "Expecting [] not to be empty 'the scan found no
       ModuleSchema::write() call at all'." 11 and 11b together are the proof
       that the floor, not the loop, is what makes the scan an assertion.

   12  Both probes in case 7 set to the junk string.
       RUN: 1 failed — the `writer` bucket comes back ['mail_reply_to'] against
       the expected two. The case cannot silently degrade into driving a value
       that never reaches the writer on the $checks-guarded field.

   13  The blank-box case posts null instead of ''.
       RUN: 9 passed — GREEN, and not a defect. ConvertEmptyStringsToNull makes
       both spellings the same thing by the time the controller runs, and
       addressOrDrop(null) answers '' rather than null, so an empty box clears
       the address either way. Recorded because it was checked rather than
       assumed — a refusal report that turned an empty box into an error would
       be the worst possible regression on this screen.

   14  MailSettings::save()'s return type widened back to `void`.
       RUN: 7 failed, 2 passed — five cases printing "Failed asserting that 500
       is identical to 200" (PHP's "Cannot use return value of void function"
       reaching the endpoint as a 500) and the service case printing "Failed
       asserting that null is identical to Array". Named because it is the shape
       a careless revert takes, and because it fails loudly rather than quietly.
   ═════════════════════════════════════════════════════════════════════════ */
