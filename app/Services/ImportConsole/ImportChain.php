<?php

declare(strict_types=1);

namespace App\Services\ImportConsole;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

use function Illuminate\Support\defer;

/**
 * What keeps the import going after the owner closes the tab — Lane GO.
 *
 * =============================================================================
 * THE ONE CLAUSE THAT WAS NOT ALREADY TRUE
 * =============================================================================
 * The owner asked for four things:
 *
 *   > "import also batch by batch. with real progress and with have pause or
 *   >  stop button. i want this job must be running in background, even i close
 *   >  the tab."
 *
 * Three of them already shipped. ImportDriver does one bounded slice per HTTP
 * request against a checkpoint that commits with its rows (batch by batch);
 * Lane GF gave the bars a denominator out of the manifest that can be checked
 * (real progress); and stop() has been there since the screen was written.
 *
 * The fourth was not true and was the only one he cared about: the loop that
 * calls /import/step lives in the browser, so closing the tab ends the import.
 * This class is that loop, moved onto the server.
 *
 * =============================================================================
 * THE MECHANISM, AND WHY THIS ONE
 * =============================================================================
 * NOTHING RUNS ON THIS HOST UNLESS A REQUEST RUNS IT. No shell, no cron, no
 * queue worker, no supervisor — CLAUDE.md, and App\Services\OutboundTick's
 * header spells out what that forecloses. `queue:work` is not inconvenient
 * here, there is nothing on the server that could execute it, and the owner
 * configures cron through a hosting panel this code must not require him to
 * open.
 *
 * So the question is not "how do we run a background job" but "what makes the
 * NEXT request happen when there is no browser to make it". There is exactly
 * one answer available: THE SERVER ASKS ITSELF. A request does one bounded
 * slice and, on its way out, makes one HTTP request back to this same
 * application. That request does the next slice and calls the next one. The
 * chain runs until the import is finished, the owner stops it, or a bound is
 * reached.
 *
 * This is not an invention. It is what `wp-cron.php` has done on every shared
 * host in the world for fifteen years, including — and this is the part worth
 * saying out loud — on the very WordPress installation this store is being
 * migrated off. The mechanism is already known to work on the owner's hosting,
 * because his old shop depended on it.
 *
 * THE SHAPE THAT MAKES IT SAFE: each link answers 204 BEFORE it does any work.
 * The continue endpoint claims the baton, sends an empty response, and does the
 * slice inside `defer()`. So the caller's request finishes in milliseconds and
 * its process exits while the next one is still working — the chain is a relay,
 * not a stack. Nothing nests, nothing recurses, and at no moment are more than
 * two PHP workers involved. `defer()` and not `app()->terminating()`, for the
 * reason OutboundTick gives: terminating callbacks are never cleared off the
 * Application and fire twice the moment one process handles two requests.
 *
 * -----------------------------------------------------------------------------
 * WHAT WAS REJECTED, AND WHY
 * -----------------------------------------------------------------------------
 *   A QUEUED JOB. Nothing would run it. QUEUE_CONNECTION=sync executes it
 *   inline, which is the browser-driven run with extra steps.
 *
 *   A TICK ON THE TAIL OF EVERY REQUEST, the way OutboundTick sweeps mail. It
 *   is the right shape for ten emails and the wrong shape for an import slice:
 *   OutboundTick's own budget note says a sweep runs "in a PHP-FPM worker that
 *   other requests are waiting for", and a slice is seconds of database writes,
 *   not milliseconds of mail(). It would also make the import's speed a
 *   function of how many strangers happen to be browsing, which on a shop that
 *   is not launched yet is none. What this class DOES borrow from it is the
 *   revival in reviveIfStalled() — a cheap, conditional, once-in-a-while kick,
 *   never a slice.
 *
 *   AN EXTERNAL PINGER (cron-job.org). It works, and it makes the owner's
 *   import depend on a third-party account he must create, must remember and
 *   will not notice has lapsed. The brief forbids requiring him to configure
 *   anything in a hosting panel, and this is the same requirement wearing a
 *   different hat.
 *
 *   REPLAYING THE ADMIN'S SESSION COOKIE on the loopback call, so the continue
 *   endpoint could live inside `auth:admin` with no new authorisation story.
 *   It would mean storing a live session credential on the server for hours,
 *   it would be replayable by anything that could read it, and it would break
 *   the moment the owner logged out — which is one of the things "I closed the
 *   tab" often means. A single-use baton is strictly less powerful and strictly
 *   more robust.
 *
 * =============================================================================
 * EXACTLY ONE CHAIN, AND IT IS ONE SQL STATEMENT
 * =============================================================================
 * Two chains racing is the failure that matters: both read the same checkpoint,
 * both import the same rows, both advance it, and the offset ends up past work
 * nobody did. ImportDriver's per-slice `locked_at`/`lock_token` claim already
 * stops two slices OVERLAPPING. It does not stop two chains ALTERNATING, which
 * would run every slice twice over and take twice as long to be wrong.
 *
 * The baton does. claim() is ONE conditional UPDATE that matches the stored
 * hash and replaces it in the same statement:
 *
 *     UPDATE import_runs
 *        SET chain_token = <hash of a brand-new secret>, ...
 *      WHERE run_key = 'admin'
 *        AND chain_token = <hash of the secret presented>
 *        AND chain_expires_at > now()
 *        AND status = 'running' AND background = 1 AND paused = 0
 *
 * One statement is simultaneously:
 *
 *   THE CREDENTIAL CHECK   — the row matches only for a caller holding the
 *                            secret whose digest is stored;
 *   THE REPLAY GUARD       — presenting it consumed it, so the same secret
 *                            matches nothing ever again;
 *   THE MUTUAL EXCLUSION   — two callers presenting the same secret at the same
 *                            instant produce one affected row and one zero, on
 *                            every store this application runs on;
 *   THE STOP CHECK         — `status = 'running' AND paused = 0` is evaluated
 *                            at the moment of the claim, so a chain in flight
 *                            cannot continue past a Stop pressed a millisecond
 *                            earlier.
 *
 * It is deliberately NOT split into a read, a hash_equals and a write. Each
 * piece would be individually readable and the set of them would have a race
 * between the check and the consumption — which is the one bug this whole
 * design exists to prevent.
 *
 * NO hash_equals() HERE, AND THAT IS NOT AN OVERSIGHT. The column holds a
 * SHA-256 digest, not the secret. A timing side channel on the database's own
 * byte comparison could at most leak the digest, and a digest is not a
 * credential — forging a request still needs its preimage, and the preimage is
 * 256 bits from random_bytes(). Adding a PHP-side hash_equals in front of the
 * UPDATE would compare the same digests a second time, guard nothing the UPDATE
 * does not already guard, and be exactly the dead text CLAUDE.md records this
 * repository paying for twice.
 *
 * -----------------------------------------------------------------------------
 * A CRASH MUST NOT LEAVE A LOCK HELD
 * -----------------------------------------------------------------------------
 * It cannot, because there is no held lock to leave. The baton is rotated at
 * CLAIM time, before any work: the new secret exists only in the memory of the
 * process now working. If that process is killed, the secret dies with it and
 * the token in the table is one nobody holds. Nothing is wedged and nothing is
 * blocked — the chain simply stops, the heartbeat goes stale, and state()
 * reports STALLED rather than a bar frozen at a number that will never move.
 * `chain_expires_at` then retires the orphan digest on its own.
 *
 * The cost of that choice is honest and is stated on the screen: ONE KILLED
 * SLICE ENDS THE CHAIN. It is the right trade. The alternative — a lease that
 * another process may seize once it looks old — is a design in which two
 * processes can both believe they hold it, and that is the duplicate-import bug
 * with a timer on it. reviveIfStalled() picks the run back up instead, safely,
 * by going through begin() like anybody else.
 *
 * =============================================================================
 * A RUNAWAY IS WORSE THAN A STALL
 * =============================================================================
 * A chain that cannot be stopped is a shop importing rows forever. Four bounds,
 * any one of which ends it, each recorded in `chain_note` so the screen can say
 * which:
 *
 *   MAX_SLICES        the counted bound — a chain gets this many slices and
 *                     then asks to be started again;
 *   MAX_CHAIN_SECONDS the wall-clock bound, for a chain whose slices are tiny;
 *   MAX_FAILS         consecutive slices that achieved nothing. A chain that is
 *                     not making progress must stop, not spin;
 *   the owner         Stop and Pause, checked inside the claim.
 *
 * And when it ends, IT SAYS SO. `halted` is a state of its own, distinct from
 * `running` and from `stalled`, for the reason Lane GD's page already
 * distinguishes IDLE / RUNNING / STALLED: a progress bar frozen at a stale
 * number is the silent half-success that makes the owner re-run something that
 * already happened.
 *
 * =============================================================================
 * THE HOST MAY REFUSE ALL OF THIS
 * =============================================================================
 * A shared host that will not open a connection to itself — no loopback, DNS
 * that resolves the domain somewhere this machine cannot reach, an outbound
 * firewall — cannot run a chain, and there is nothing this code can do about
 * it. So the FIRST kick is the test: begin() fires it synchronously and, if it
 * does not come back 204, undoes the background run entirely, leaves
 * `status = 'running'` exactly as it was, and hands back a sentence naming what
 * was tried. The browser-driven loop is still there and still works; the owner
 * loses nothing but the tab-closing. Silently doing nothing is the one answer
 * that is not allowed.
 */
final class ImportChain
{
    /** The header the baton travels in. NEVER a query parameter — see kick(). */
    public const HEADER = 'X-KBB-Import-Chain';

    /** The path the loopback request is made to. Two segments, so the storefront's
     *  root-segment catch-all cannot swallow it. */
    public const PATH = 'import-chain/continue';

    /**
     * How long an unclaimed baton stays valid.
     *
     * It only has to cover the gap between issuing the secret and the far end
     * claiming it, which is one HTTP round trip on the same machine. Ten
     * minutes is absurdly generous for that and is chosen to be forgiving of a
     * host under load rather than tight.
     */
    public const TOKEN_SECONDS = 600;

    /**
     * A background run whose heartbeat is older than this is STALLED.
     *
     * Comfortably above ImportApiController::STEP_SECONDS (110), because the
     * point is to catch a slice that never came back, not one that is taking
     * its time. The heartbeat is written when a slice is CLAIMED as well as
     * when it ends, so a slow slice cannot be mistaken for a dead one.
     */
    public const STALE_SECONDS = 240;

    /** The counted runaway bound. ~55,000 rows at the smallest useful slice. */
    public const MAX_SLICES = 5000;

    /** The wall-clock runaway bound. */
    public const MAX_CHAIN_SECONDS = 6 * 3600;

    /** Consecutive slices that achieved nothing before the chain gives up. */
    public const MAX_FAILS = 5;

    /** What one slice should take. The chain adapts towards this, as the browser does. */
    public const TARGET_MS = 8000;

    /** The loopback call answers in milliseconds — it defers its own work — so these are tight. */
    public const KICK_TIMEOUT = 20;

    public const KICK_CONNECT = 5;

    public function __construct(
        private readonly ImportDriver $driver = new ImportDriver,
        private readonly ImportWorkspace $workspace = new ImportWorkspace,
    ) {}

    /* ==================================================================== */
    /*  SCHEMA                                                              */
    /* ==================================================================== */

    /**
     * Has the migration that carries the chain columns been applied?
     *
     * Asked rather than assumed, and NOT MEMOISED IN A STATIC — the landmine
     * CLAUDE.md names and ImportDriver::runsCarry() already declines. A package
     * applied to a database whose import_runs predates this lane reports the
     * background run as unavailable, in a sentence, and the browser-driven run
     * keeps working untouched.
     */
    public function available(): bool
    {
        return Schema::hasTable(ImportDriver::TABLE)
            && Schema::hasColumn(ImportDriver::TABLE, 'chain_token')
            && Schema::hasColumn(ImportDriver::TABLE, 'background');
    }

    public function run(): ?object
    {
        return DB::table(ImportDriver::TABLE)->where('run_key', ImportDriver::RUN_KEY)->first();
    }

    /* ==================================================================== */
    /*  STARTING, PAUSING, STOPPING                                          */
    /* ==================================================================== */

    /**
     * Ask the server to carry this run on by itself.
     *
     * Called by a signed-in admin and by nothing else. The refusals come back
     * as sentences because the person reading them has no shell and no log.
     *
     * @return array{ok: bool, message: string, via: ?string}
     */
    public function begin(): array
    {
        if (! $this->available()) {
            return $this->no(
                'This copy of the shop has not had the background-import update applied to its database yet, '
                .'so the import can only run while this tab is open. Applying the package again — it carries '
                .'the migration — is the fix.'
            );
        }

        $run = $this->run();

        if ($run === null || $run->status !== 'running') {
            return $this->no('Nothing is running. Press Preview or Import first, then ask for it to carry on by itself.');
        }

        /*
         * The rows-per-slice the chain starts at. Taken from whatever the run
         * was already using if it has one, so a run the browser had already
         * tuned down for a slow host does not go straight back to 400 and time
         * out on its first unattended slice.
         */
        $rows = (int) ($run->chain_rows ?? 0);

        DB::table(ImportDriver::TABLE)->where('id', $run->id)->update([
            'background' => true,
            'paused' => false,
            'chain_started_at' => now(),
            'chain_beat_at' => now(),
            'chain_slices' => 0,
            'chain_fails' => 0,
            'chain_rows' => $rows > 0 ? $rows : ImportDriver::DEFAULT_STEP_ROWS,
            'chain_note' => null,
            'updated_at' => now(),
        ]);

        /*
         * ISSUING ROTATES, so this is also how a second press of the button
         * cannot produce a second chain: whatever was in flight holds a secret
         * that is now stale and will be refused the next time it is presented.
         */
        $secret = $this->issue();

        $kick = $this->kick($secret);

        if (! $kick['ok']) {
            /*
             * THE FIRST KICK IS THE PREFLIGHT. There is no separate probe,
             * because a probe that succeeds and a chain that then fails is two
             * chances to be wrong about the same question.
             *
             * Everything is put back: no baton, no background flag. The run is
             * untouched — same status, same checkpoints, same options — so the
             * console's Continue button carries on exactly as before.
             */
            $this->halt($kick['message'], clearBackground: true);

            return $kick;
        }

        return ['ok' => true, 'message' => 'The import is now running on the server. You can close this tab.', 'via' => $kick['via']];
    }

    /** Stop the chain without ending the run, so Resume can pick it up. */
    public function pause(): void
    {
        $run = $this->run();

        if ($run === null || ! $this->available()) {
            return;
        }

        DB::table(ImportDriver::TABLE)->where('id', $run->id)->update([
            'paused' => true,
            // The baton goes with it. `paused = 0` in the claim is what makes
            // pause authoritative; dropping the token as well means a secret
            // stops existing the moment it stops being wanted.
            'chain_token' => null,
            'chain_note' => 'paused from the progress page',
            'updated_at' => now(),
        ]);
    }

    /**
     * Carry on from where it stopped.
     *
     * RESUMING RE-ENTERS THROUGH begin(), which is the whole point: it is the
     * one place a baton is issued, so there is no second path by which a chain
     * can come into existence. Which row it lands on is not this class's
     * business at all — ImportRunner reads `import_checkpoints.processed`, which
     * was committed in the same transaction as the rows it counts, so a resume
     * starts at the row after the last COMMITTED one whether it was paused,
     * stopped, killed or closed.
     *
     * @return array{ok: bool, message: string, via: ?string}
     */
    public function resume(): array
    {
        $run = $this->run();

        if ($run !== null && $this->available()) {
            DB::table(ImportDriver::TABLE)->where('id', $run->id)->update([
                'paused' => false,
                'chain_fails' => 0,
                'updated_at' => now(),
            ]);
        }

        return $this->begin();
    }

    /**
     * End the chain and say why, leaving the run itself alone.
     *
     * Clearing `chain_token` is what makes it final: there is then no secret in
     * existence that any claim can match.
     */
    public function halt(string $why, bool $clearBackground = false): void
    {
        $run = $this->run();

        if ($run === null || ! $this->available()) {
            return;
        }

        $fields = [
            'chain_token' => null,
            'chain_expires_at' => null,
            'chain_note' => $why,
            'updated_at' => now(),
        ];

        if ($clearBackground) {
            $fields['background'] = false;
        }

        DB::table(ImportDriver::TABLE)->where('id', $run->id)->update($fields);
    }

    /* ==================================================================== */
    /*  THE BATON                                                            */
    /* ==================================================================== */

    /**
     * Mint a new secret, store its digest, and hand the secret back.
     *
     * The secret is returned and never stored. The digest is stored and never
     * returned. Nothing anywhere in this application writes the secret to a
     * log, a URL, a session or a response body.
     */
    private function issue(): string
    {
        $secret = bin2hex(random_bytes(32));

        DB::table(ImportDriver::TABLE)
            ->where('run_key', ImportDriver::RUN_KEY)
            ->update([
                'chain_token' => hash('sha256', $secret),
                'chain_expires_at' => now()->addSeconds(self::TOKEN_SECONDS),
                'chain_beat_at' => now(),
                'updated_at' => now(),
            ]);

        return $secret;
    }

    /**
     * Take the baton, if this caller really holds it, and hand back the next one.
     *
     * Null means "no". It means exactly the same thing for a forged secret, a
     * secret that was already used, a secret that expired, a run that was
     * stopped, a run that was paused, a run that finished and a run that never
     * existed — deliberately, and for the reason CLAUDE.md gives about
     * QuizSubmission::findByPublicToken: branching differently on the cases
     * turns the endpoint into an oracle for which of them is true.
     *
     * ONE STATEMENT. See the class header for what each clause of it is doing;
     * the short version is that splitting it would put a race between the check
     * and the consumption.
     */
    public function claim(string $secret): ?string
    {
        if ($secret === '' || ! $this->available()) {
            return null;
        }

        $next = bin2hex(random_bytes(32));

        $claimed = DB::table(ImportDriver::TABLE)
            ->where('run_key', ImportDriver::RUN_KEY)
            ->where('chain_token', hash('sha256', $secret))
            ->where('chain_expires_at', '>', now())
            ->where('status', 'running')
            ->where('background', true)
            ->where('paused', false)
            ->update([
                'chain_token' => hash('sha256', $next),
                'chain_expires_at' => now()->addSeconds(self::TOKEN_SECONDS),
                'chain_beat_at' => now(),
                'chain_slices' => DB::raw('chain_slices + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return null;
        }

        /*
         * THE RUNAWAY BOUNDS, checked after the claim rather than before it.
         *
         * After, because the claim is what increments the counter, and a bound
         * tested against a number that has not moved yet is a bound that is
         * always one slice out. The slice for which the bound trips is not
         * done: halt() drops the baton, this returns null, and the endpoint
         * answers exactly as it does for a forged secret.
         */
        $run = $this->run();
        $slices = (int) ($run->chain_slices ?? 0);
        $began = $run?->chain_started_at === null ? null : strtotime((string) $run->chain_started_at);
        $elapsed = $began === false || $began === null ? 0 : max(0, time() - $began);

        if ($slices > self::MAX_SLICES) {
            $this->halt(
                'This background run reached its limit of '.self::MAX_SLICES.' pieces and stopped on purpose, '
                .'so that nothing can import forever unattended. Nothing is lost — press “Keep importing in the '
                .'background” to carry on from exactly here.'
            );

            return null;
        }

        if ($elapsed > self::MAX_CHAIN_SECONDS) {
            $this->halt(
                'This background run has been going for '.(int) round($elapsed / 3600).' hours and stopped on '
                .'purpose, so that nothing can import forever unattended. Nothing is lost — press “Keep importing '
                .'in the background” to carry on from exactly here.'
            );

            return null;
        }

        return $next;
    }

    /**
     * One slice, then hand the baton on. Runs AFTER the response has gone.
     *
     * Everything in here is wrapped, because this executes with no browser
     * attached and no person watching: an exception that escapes would end the
     * chain with nothing on any screen saying why, which is the failure the
     * STALLED state was built to make impossible.
     */
    public function advance(string $next): void
    {
        try {
            $this->slice($next);
        } catch (\Throwable $e) {
            $this->halt('The background run stopped with an error from the server: '.$this->firstLine($e->getMessage()));
        }
    }

    private function slice(string $next): void
    {
        $run = $this->run();

        if ($run === null) {
            return;
        }

        $rows = (int) ($run->chain_rows ?? 0);
        $rows = max(ImportDriver::MIN_STEP_ROWS, min(ImportDriver::MAX_STEP_ROWS, $rows > 0 ? $rows : ImportDriver::DEFAULT_STEP_ROWS));

        $began = microtime(true);

        try {
            $result = $this->driver->step($rows);
        } catch (ImportDriverRefused $e) {
            // Includes the case where another slice is somehow still working —
            // ImportDriver's own claim refusing us. Counted as a failure so the
            // chain cannot sit in that state forever.
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        $took = (microtime(true) - $began) * 1000;

        /*
         * SIZED FROM WHAT IT MEASURED, the same arithmetic the console's loop
         * uses — grow slowly, shrink fast. Being half as quick as it could be
         * costs minutes; being one slice too greedy costs a timeout, and a
         * timeout with no browser attached costs the whole chain.
         *
         * It has to live in the table rather than in a variable, because the
         * next slice is a different process. That is the entire reason
         * `chain_rows` is a column.
         */
        if (($result['ok'] ?? false) === true) {
            if ($took < self::TARGET_MS * 0.6) {
                $rows = min(ImportDriver::MAX_STEP_ROWS, (int) ceil($rows * 1.6));
            } elseif ($took > self::TARGET_MS * 1.5) {
                $rows = max(ImportDriver::MIN_STEP_ROWS, (int) floor($rows / 2));
            }
        } else {
            // A failed slice is very often a slice that was too big for this
            // host. Halving is how the chain finds a ceiling nobody can tell it.
            $rows = max(ImportDriver::MIN_STEP_ROWS, (int) floor($rows / 2));
        }

        $ok = ($result['ok'] ?? false) === true;

        DB::table(ImportDriver::TABLE)->where('id', $run->id)->update([
            'chain_rows' => $rows,
            'chain_beat_at' => now(),
            'chain_fails' => $ok ? 0 : DB::raw('chain_fails + 1'),
            'updated_at' => now(),
        ]);

        /*
         * RE-READ. Everything decided from here on is about the state of the
         * world AFTER the slice, and the slice took seconds during which the
         * owner may have pressed Stop, the run may have completed, or somebody
         * may have started a fresh chain.
         */
        $after = $this->run();

        if ($after === null) {
            return;
        }

        if ($after->status !== 'running') {
            // Completed, or stopped by the owner. Either way nothing continues.
            $this->halt($after->status === 'complete'
                ? 'the import finished'
                : 'stopped from the screen');

            return;
        }

        if ((bool) $after->paused) {
            // pause() has already written its own note and dropped the baton.
            return;
        }

        if ((int) $after->chain_fails >= self::MAX_FAILS) {
            $this->halt(
                'The background run stopped after '.self::MAX_FAILS.' pieces in a row that could not be done. '
                .'The last thing the server said was: '.$this->firstLine((string) ($result['message'] ?? 'nothing')).' '
                .'Nothing already imported is lost.'
            );

            return;
        }

        /*
         * HAVE WE BEEN SUPERSEDED? If the stored digest is no longer the one
         * for the secret we are holding, somebody issued a new baton while this
         * slice was running and a different chain now owns the run. Handing our
         * stale secret on would be refused at the far end anyway — this makes
         * it explicit and, more usefully, testable.
         */
        if (! is_string($after->chain_token) || ! hash_equals((string) $after->chain_token, hash('sha256', $next))) {
            return;
        }

        $kick = $this->kick($next);

        if (! $kick['ok']) {
            /*
             * A REFUSED KICK IS NOT ALWAYS A HOST THAT REFUSES LOOPBACK, and
             * saying so when it is not is a lie the owner cannot check.
             *
             * Found in the rehearsal. Stop was pressed during a background run,
             * in the window between this process deciding to kick and the far
             * end answering. The far end then refused the baton — correctly,
             * because the run had just been stopped — and the 404 was reported
             * here as "this host will not let the shop call itself", wiping out
             * the true note ("stopped from the progress page"). Everything
             * BEHAVED correctly; the sentence on the screen accused the hosting
             * of a fault the owner had just caused himself, and pointed him at
             * the one remedy that could not help.
             *
             * So the message is only written when this process is still the
             * current chain of a still-running run. If it is not, somebody took
             * the chain away on purpose while this call was in the air, and
             * their note is the true one.
             */
            $now = $this->run();

            $superseded = $now === null
                || $now->status !== 'running'
                || ! is_string($now->chain_token)
                || ! hash_equals((string) $now->chain_token, hash('sha256', $next));

            if (! $superseded) {
                $this->halt($kick['message']);
            }
        }
    }

    /* ==================================================================== */
    /*  THE LOOPBACK CALL                                                    */
    /* ==================================================================== */

    /**
     * Make this application call itself.
     *
     * THE BATON GOES IN A HEADER AND NOWHERE ELSE. A query parameter would be
     * written verbatim into the host's access log, into any proxy in front of
     * it, and into the Referer of anything the next page loaded — and this
     * application cannot read or rotate any of those. `Http::post($url)` with
     * no query and no body is the whole request; there is not a code path here
     * that can put the secret into a URL.
     *
     * REDIRECTS ARE REFUSED. Guzzle follows them by default and carries custom
     * headers across the hop, so a host that 301s http to https on a different
     * name would forward the secret to that name. withoutRedirecting() means a
     * redirect is reported as a failure the owner can read instead.
     *
     * TWO ATTEMPTS, IN ORDER:
     *
     *   1. THE SHOP'S OWN URL, exactly as this request was addressed. This is
     *      the one that works on a normal host.
     *
     *   2. THE SAME URL, RESOLVED TO 127.0.0.1. Shared hosts routinely publish
     *      a public IP for their own domains that the machine itself cannot
     *      route to — the classic "the site cannot fetch its own pages" fault.
     *      cURL's own resolver override fixes exactly that, and it is the right
     *      tool rather than rewriting the URL to http://127.0.0.1, because the
     *      URL, the Host header and the TLS name all stay correct. TLS
     *      VERIFICATION IS NOT TURNED OFF for it; a certificate that does not
     *      match is a real fault and is reported as one.
     *
     * @return array{ok: bool, message: string, via: ?string}
     */
    public function kick(string $secret): array
    {
        $url = $this->continueUrl();
        $tried = [];

        foreach ($this->attempts($url) as $label => $options) {
            try {
                $response = Http::withHeaders([self::HEADER => $secret])
                    ->withoutRedirecting()
                    ->connectTimeout(self::KICK_CONNECT)
                    ->timeout(self::KICK_TIMEOUT)
                    ->withOptions($options)
                    ->post($url);

                if ($response->status() === 204) {
                    return ['ok' => true, 'message' => 'accepted', 'via' => $label];
                }

                $tried[] = $label.' answered '.$response->status();
            } catch (\Throwable $e) {
                $tried[] = $label.' failed ('.$this->firstLine($e->getMessage()).')';
            }
        }

        return $this->no(
            'This host will not let the shop call itself, so the import cannot keep going on its own. '
            .'It stays exactly where it is and nothing is lost — leave this tab open and press Continue, '
            .'which imports in the same batches and works everywhere. What was tried: '
            .implode('; ', $tried).'. The address was '.$url.'. If this shop was updated a moment ago, the '
            .'compiled route table may still be the old one — applying the package again clears it.'
        );
    }

    /**
     * The attempts kick() makes, in order, as Guzzle option sets.
     *
     * @return array<string, array<string, mixed>>
     */
    private function attempts(string $url): array
    {
        $attempts = ['the shop\'s own address' => []];

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        /*
         * Skipped entirely without cURL — the resolver override is a cURL
         * feature and Guzzle's stream handler ignores unknown options silently,
         * which would make attempt two a second identical attempt one and turn
         * one failure into two lines of the same failure in the message.
         */
        if ($host !== null && extension_loaded('curl') && ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80));

            $attempts['the same address, forced to this machine'] = [
                'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':127.0.0.1']],
            ];
        }

        return $attempts;
    }

    /**
     * Where the loopback call goes.
     *
     * Built with url(), so KBB_BASE_PATH — which prefixes every route on the
     * production host — is included without this class knowing it exists.
     */
    public function continueUrl(): string
    {
        return url(self::PATH);
    }

    /* ==================================================================== */
    /*  REVIVAL                                                              */
    /* ==================================================================== */

    /**
     * Pick a stalled background run back up, if one is stalled.
     *
     * A chain dies when the process holding the baton is killed. Nothing on
     * this host notices that by itself, because nothing on this host runs by
     * itself — so the next best thing is that anybody LOOKING at the import
     * revives it. This is called from the two admin endpoints the progress page
     * touches, which means the owner opening the page, or leaving it polling in
     * a tab, is enough.
     *
     * THE HONEST LIMIT, and it is stated on the page rather than only here: if
     * a slice is killed while every tab is closed, the run stays stalled until
     * somebody opens the shop's admin. It is not lost, nothing is duplicated
     * when it resumes, and the page says STALLED with the reason. On a host
     * with nothing that can run a timer, that is the floor.
     *
     * CHEAP WHEN THERE IS NOTHING TO DO, which is almost always: one row that
     * the caller has already read, and no cache, no lock and no outbound call
     * unless the state really is stalled.
     */
    public function reviveIfStalled(): bool
    {
        if (! $this->available()) {
            return false;
        }

        $state = $this->state();

        if ($state['state'] !== 'stalled') {
            return false;
        }

        /*
         * THE ONE CASE WHERE REVIVING WOULD MAKE THINGS WORSE, and it is the
         * commonest one: a slice killed by the host's execution limit leaves
         * ImportDriver's OWN per-slice claim held, because the `finally` that
         * releases it never ran. That claim ages out by itself after
         * ImportDriver::LOCK_SECONDS — it is not a wedge — but until it does,
         * step() refuses every caller with "this import is already being worked
         * on".
         *
         * A revival inside that window would therefore be refused, would count
         * as a failed slice, and would chain straight into the next one, which
         * would be refused too: five polls of this page — fifteen seconds —
         * would burn the whole MAX_FAILS budget and HALT a run that was about
         * to be perfectly resumable.
         *
         * So the chain is reported stalled the moment it really is stalled,
         * which is what the owner needs to see, and the revival waits for the
         * driver's claim rather than racing it. It costs a few minutes of
         * patience once, against turning a killed request into a halted import.
         *
         * THE TWO CONSTANTS ARE NOT INDEPENDENTLY CHOSEN and must not become
         * so: this reads ImportDriver::LOCK_SECONDS rather than a number of its
         * own, so a future change to the driver's claim cannot silently put
         * this window back.
         */
        $run = $this->run();
        $locked = $run?->locked_at === null ? null : strtotime((string) $run->locked_at);

        if ($locked !== false && $locked !== null && $locked > time() - ImportDriver::LOCK_SECONDS) {
            return false;
        }

        /*
         * Re-issuing goes through begin(), so a revival is subject to every
         * guard a first start is — including, and this is the point, the
         * rotation that makes the dead chain's secret worthless. Two admins
         * refreshing the page at the same instant produce two begin() calls,
         * two rotations and two kicks; the earlier secret is stale by the time
         * its kick lands, so one chain survives. It cannot be two.
         */
        $this->begin();

        return true;
    }

    /* ==================================================================== */
    /*  WHAT THE SCREEN READS                                                */
    /* ==================================================================== */

    /**
     * IDLE, RUNNING, STALLED, PAUSED, HALTED or FINISHED — and never a guess.
     *
     * This is the half of the answer Lane GD's header calls out: "running, 40%"
     * and "stalled at 40% because the last request was killed" are different
     * facts about the same bar, and a screen that draws them identically is the
     * silent half-success the whole design is against.
     *
     * @return array{state: string, note: string, ...}
     */
    public function state(): array
    {
        if (! $this->available()) {
            return [
                'state' => 'unavailable',
                'note' => 'This shop\'s database has not had the background-import update applied, so an '
                    .'import can only run while a tab is open on it.',
                'available' => false,
            ] + $this->emptyState();
        }

        $run = $this->run();

        if ($run === null) {
            return ['state' => 'idle', 'note' => 'nothing has been imported from this screen yet', 'available' => true]
                + $this->emptyState();
        }

        $beat = $run->chain_beat_at === null ? null : strtotime((string) $run->chain_beat_at);
        $since = $beat === false || $beat === null ? null : max(0, time() - $beat);

        $common = [
            'available' => true,
            'background' => (bool) $run->background,
            'paused' => (bool) $run->paused,
            'run_status' => (string) $run->status,
            'mode' => (string) $run->mode,
            'slices' => (int) ($run->chain_slices ?? 0),
            'fails' => (int) ($run->chain_fails ?? 0),
            'rows_per_slice' => (int) ($run->chain_rows ?? 0),
            'started_at' => $run->chain_started_at === null ? null : (string) $run->chain_started_at,
            'beat_at' => $run->chain_beat_at === null ? null : (string) $run->chain_beat_at,
            'seconds_since_beat' => $since,
            'chain_note' => $run->chain_note === null ? null : (string) $run->chain_note,
        ];

        if ($run->status !== 'running') {
            return [
                'state' => 'finished',
                'note' => $run->status === 'complete'
                    ? 'the import finished'
                    : 'the run was stopped; nothing it had already imported was undone',
            ] + $common;
        }

        if (! (bool) $run->background) {
            return [
                'state' => 'idle',
                'note' => 'this run is being driven from a browser tab. Ask for it to carry on by itself and '
                    .'you can close the tab.',
            ] + $common;
        }

        if ((bool) $run->paused) {
            return [
                'state' => 'paused',
                'note' => 'paused. Nothing is being imported and nothing is lost; Resume carries on from the '
                    .'row after the last one that was committed.',
            ] + $common;
        }

        /*
         * NO BATON MEANS NOTHING CAN CONTINUE, whatever the heartbeat says, and
         * it is checked BEFORE the heartbeat for that reason: a chain halted
         * one second ago has a fresh heartbeat and is not running. Reporting it
         * as running for the next four minutes is precisely the bar frozen at a
         * stale number that this state machine exists to prevent.
         */
        if ($run->chain_token === null) {
            return [
                'state' => 'halted',
                'note' => $run->chain_note !== null && (string) $run->chain_note !== ''
                    ? (string) $run->chain_note
                    : 'the background run has stopped. Nothing is lost — “Keep importing in the background” picks it '
                        .'up from the row after the last one committed.',
            ] + $common;
        }

        if ($since === null || $since > self::STALE_SECONDS) {
            return [
                'state' => 'stalled',
                'note' => 'a background run is going but its last piece was '.($since ?? 0).' seconds ago, '
                    .'which is longer than this expects. The request was probably killed by the host\'s '
                    .'execution limit. Nothing is lost, and nothing has to be pressed: this page picks it back '
                    .'up by itself, once the piece that was killed has let go of its claim — which happens on '
                    .'its own '.ImportDriver::LOCK_SECONDS.' seconds after it started.',
            ] + $common;
        }

        return [
            'state' => 'running',
            'note' => 'running on the server. The last piece finished '.$since.' seconds ago. You can close '
                .'this tab.',
        ] + $common;
    }

    /**
     * The bars, and nothing else.
     *
     * SEPARATE FROM ImportDriver::status() ON PURPOSE, for the reason
     * denominators() already gives: status() opens and counts the rejection CSV
     * and rebuilds the whole file list, and the progress page polls every few
     * seconds for hours. A page that needs nine integers must not drag that
     * behind it.
     *
     * @return array<string, mixed>
     */
    public function progress(): array
    {
        $denominators = $this->driver->denominators();
        $checkpoints = [];

        foreach (DB::table('import_checkpoints')->where('run_key', ImportDriver::RUN_KEY)->get() as $row) {
            $checkpoints[(string) $row->entity] = $row;
        }

        $entities = [];
        $barTotal = 0;
        $barDone = 0;
        $barred = 0;
        $everyDenominator = true;

        foreach ($denominators as $entity => $d) {
            $cp = $checkpoints[$entity] ?? null;
            $present = $this->workspace->has($entity);
            $processed = $cp === null ? 0 : (int) $cp->processed;

            $percent = null;

            if ($present && $d['rows'] !== null && $d['rows'] > 0) {
                $percent = (int) min(100, (int) round(100 * $processed / $d['rows']));
                $barred++;
                $barTotal += $d['rows'];
                $barDone += min($processed, $d['rows']);
            } elseif ($present) {
                $everyDenominator = false;
            }

            $entities[] = [
                'entity' => $entity,
                'label' => ImportWorkspace::meta($entity)['label'],
                'present' => $present,
                'processed' => $processed,
                'denominator' => $d['rows'],
                'percent' => $percent,
                'created' => $cp === null ? 0 : (int) $cp->created_rows,
                'updated' => $cp === null ? 0 : (int) $cp->updated_rows,
                'unchanged' => $cp === null ? 0 : (int) $cp->unchanged_rows,
                'rejected' => $cp === null ? 0 : (int) $cp->rejected_rows,
                'finished' => $cp !== null && $cp->finished_at !== null,
            ];
        }

        return [
            'ok' => true,
            'chain' => $this->state(),
            'entities' => $entities,
            'overall' => [
                'done' => $barDone,
                // Same rule as status(): one file short of a trustworthy count
                // is not a smaller total, it is a total that is wrong in the
                // flattering direction.
                'total' => $everyDenominator && $barred > 0 ? $barTotal : null,
                'percent' => $everyDenominator && $barred > 0 && $barTotal > 0
                    ? (int) min(100, (int) round(100 * $barDone / $barTotal))
                    : null,
            ],
            'server_time' => now()->toIso8601String(),
        ];
    }

    /* ==================================================================== */
    /*  ODDS AND ENDS                                                        */
    /* ==================================================================== */

    /** @return array{ok: false, message: string, via: null} */
    private function no(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'via' => null];
    }

    /** @return array<string, mixed> */
    private function emptyState(): array
    {
        return [
            'background' => false,
            'paused' => false,
            'run_status' => null,
            'mode' => null,
            'slices' => 0,
            'fails' => 0,
            'rows_per_slice' => 0,
            'started_at' => null,
            'beat_at' => null,
            'seconds_since_beat' => null,
            'chain_note' => null,
        ];
    }

    private function firstLine(string $message): string
    {
        $line = trim(strtok($message, "\n") ?: $message);

        return mb_strimwidth($line, 0, 400, '…');
    }
}
