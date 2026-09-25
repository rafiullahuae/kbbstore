<?php

/**
 * The proof that moving Store → Mail onto the shared schema moved nothing —
 * Lane M4, round 4 of the per-module settings schema.
 *
 * ── WHY A THIRD FIXTURE ─────────────────────────────────────────────────────
 *
 * Round 1 recorded 4,653 calls of thirteen modules' `cast($key, $raw)`. Round 3
 * recorded 345 calls of three classes' static `normalise($key, $value)`. Both
 * are pure functions of their arguments, so both fixtures could be produced with
 * no database.
 *
 * MailSettings is not a pure function and cannot be recorded as one. `save()`
 * writes — to `settings` for fifteen keys and to the encrypted
 * `mail_credentials` row for the sixteenth — and `all()` reads back through a
 * cache. What this round is migrating is the ROUND TRIP, so the round trip is
 * what is recorded: plant, save, read back through all THREE readers that
 * disagree today, and write one line per call.
 *
 * ── THE DISAGREEMENT THIS FIXTURE EXISTS TO WATCH ───────────────────────────
 *
 * Round 3 §5 named it and declined the round for it:
 *
 *   > MailSettings::all() returns the LABEL where every other reader on this
 *   > schema returns the stored key … Migrating all() therefore moves what the
 *   > Mail screen's <select> is given, on a screen whose failure mode is a shop
 *   > that stops sending order emails.
 *
 * So `all=` and `raw=` are recorded SEPARATELY on every line. `raw` is read
 * straight off the settings table, past every cache. "The stored bytes did not
 * change" is then a measurement rather than an inference, and it is the
 * assertion the whole round rests on: a migration that rewrote a stored
 * transport key is precisely the shape that stops a shop sending.
 *
 * ── THE ONE ALLOWED DIFFERENCE, AND THE DEFECT BEHIND IT ────────────────────
 *
 * `all=` moves for `mail_transport` and for nothing else. That is this round's
 * Task 2 and it is a defect being fixed, not a regression being waved through:
 * `all()` handed back a display label where every other reader in this
 * application hands back the stored key, so `get('mail_transport')` could not be
 * compared against `transport()` or against TRANSPORT_KEYS. The label is now
 * derived at the display boundary — MailApiController::show() — which is the one
 * place it is wanted, and MailScreenPayloadTest proves the screen receives the
 * same bytes it received before.
 *
 * `raw=` may not move on ANY line, including that one. If it does, an install's
 * saved choice has been rewritten and the exemption below does not cover it.
 */

use App\Models\MailCredential;
use App\Models\Setting;
use App\Services\Mail\MailCredentials;
use App\Services\Mail\MailSettings;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\SettingsCorpus;

/** Every reader reset, so one recorded line cannot be the leftovers of the last. */
function m4Reset(): void
{
    Setting::query()->delete();
    MailCredential::query()->delete();

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(MailCredentials::class)->forget();
}

function m4Rows(): array
{
    return SettingsCorpus::mailRows(
        MailSettings::SCHEMA,
        function (array $payload) {
            m4Reset();
            app(MailSettings::class)->save($payload);
            // Past every cache: the next read must see the table, not a memo.
            app(SettingsService::class)->flush();
            SettingsService::forgetMemo();
            Setting::flushMap();
            app(MailCredentials::class)->forget();
        },
        fn () => app(MailSettings::class)->all(),
        fn (string $key) => DB::table('settings')->where('key', $key)->value('value'),
        fn () => app(MailSettings::class)->hasPassword(),
        [
            'mail_transport' => MailSettings::TRANSPORT_LABELS,
            'mail_encryption' => array_combine(MailSettings::ENCRYPTIONS, MailSettings::ENCRYPTIONS),
        ],
    );
}

it('answers every recorded mail call exactly as it did before the shared schema', function () {
    $path = base_path('tests/Fixtures/module-mail-baseline.txt');

    $now = m4Rows();

    if (getenv('KBB_M4_RECORD') === '1') {
        file_put_contents($path, implode("\n", $now)."\n");
        fwrite(STDERR, "\nRECORDED ".count($now)." mail calls to {$path}\n");
    }

    $recorded = file($path, FILE_IGNORE_NEW_LINES);

    expect(count($now))->toBe(count($recorded), 'the corpus changed shape; the fixture no longer describes it');

    $moved = [];

    foreach ($recorded as $i => $before) {
        if ($before !== $now[$i]) {
            $moved[] = $before.'   ==>   '.$now[$i];
        }
    }

    /*
     * THE ONLY EXEMPTION, and it is narrower than a field: `all=` on
     * `mail_transport`, and nothing else on that line. `raw=` is compared on
     * every line including this one, so the exemption cannot widen to cover a
     * stored value that moved.
     */
    $unexpected = [];

    foreach ($moved as $line) {
        [$before, $after] = explode('   ==>   ', $line, 2);

        $exempt = str_starts_with($before, 'mail_transport|')
            && m4Part($before, 'raw=') === m4Part($after, 'raw=')
            && m4Part($before, 'has=') === m4Part($after, 'has=');

        if (! $exempt) {
            $unexpected[] = $line;
        }
    }

    expect($unexpected)->toBe([], "These mail settings would store or read a different value than they did before:\n".implode("\n", $unexpected));

    // A guard on the guard: an emptied fixture makes the loop pass by doing
    // nothing. 231 when recorded, across sixteen keys.
    expect(count($recorded))->toBe(231, 'the recorded corpus changed size');
});

/** One `name=value` part of a recorded line. */
function m4Part(string $line, string $name): string
{
    foreach (explode('|', $line) as $part) {
        if (str_starts_with($part, $name)) {
            return $part;
        }
    }

    return '';
}
