<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\UpdateRelease;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * File integrity — Phase 18 item 3, REPORT ONLY.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IT BLOCKS NOTHING AND IT RESTORES NOTHING
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Phase 18's sequencing is report before enforce, in every part: audit trail
 * and reporting screen → INTEGRITY CHECKING IN REPORT-ONLY MODE → CSP
 * report-only → the request gate in observe mode → then, with real traffic
 * observed, enforcement one rule at a time. This file is the second step and
 * only the second step.
 *
 * The plan also records an OPEN QUESTION that belongs to the owner and not to
 * a lane: "Restore automatically, or alert and wait? Automatic restore closes
 * the window fastest and can also undo a legitimate hand-edit on the server.
 * The recommendation is alert-by-default with restore one click away, and
 * automatic restore as a switch for those who want it."
 *
 * So this lane builds the ALERT half and leaves a place for the other. The
 * place is `SecurityModule::SCHEMA['integrity_action']` — a select whose
 * options today are exactly self::ACTIONS, i.e. one. `SecurityModule::cast()`
 * stores a select value only if it is one of that field's own options and
 * otherwise stores the default, so a hand-rolled POST of `restore` is stored
 * as `alert`. The seam exists; the decision has not been taken.
 *
 * That is asserted rather than promised. This class may not name a call that
 * writes to a checked file — no copy(), no file_put_contents(), no unlink(),
 * no rename() — and SecurityIntegrityTest reads this file as TEXT for each of
 * them. A future round that adds restore has to delete that assertion by hand,
 * which is the point of it.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHERE THE TRUTH COMES FROM
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Every package this shop has ever applied arrived as a zip carrying
 * `update.json`, and that manifest holds a SHA-256 FOR EVERY PATH IT SHIPS.
 * UpdatePackage::checkChecksums() already verifies each file against it before
 * a single byte is written, so a package that installs is a package whose
 * bytes matched their hashes. `update_releases` records which packages
 * applied. Between them the shop can say what a shipped file is SUPPOSED to
 * hash to — which is the one thing a host with no shell cannot find out any
 * other way.
 *
 * Two sources, in this order:
 *
 *   1. `update_releases.manifest` — written by UpdateRunner at apply time from
 *      this release forward (2026_12_12_000000_add_manifest_to_update_releases).
 *   2. the archived zip at `update_releases.archive_path`, for every release
 *      that applied BEFORE that column existed. UpdateRunner has kept those
 *      zips since 2.60.x, so the shop can speak about its past as well as its
 *      future. A manifest recovered that way is written into the column on
 *      first read, so the zip is opened once ever and never again.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE EFFECTIVE MANIFEST: NEWEST APPLIED RELEASE WINS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * A file shipped in 2.60.100 and shipped again in 2.60.258 must be compared
 * against the SECOND hash, or every later package would light up the whole
 * screen. So applied releases are overlaid oldest-first, by version rather
 * than by id — the same ordering InstalledVersion::resolve() uses and for the
 * same reason: a rollback followed by a re-apply leaves rows whose id order
 * and version order disagree.
 *
 * ONLY `status = 'applied'`. A rolled-back release had its files put back by
 * BackupService::restoreFiles(), so its hashes describe bytes that are
 * deliberately no longer on disk; counting them would report every successful
 * rollback as an intrusion.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT IT CANNOT SEE, SAID PLAINLY
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The plan asks for these to be recorded now rather than discovered later, and
 * the screen prints them where the owner reads the findings:
 *
 *   - **Only files a package installed.** The original WooCommerce port was
 *     not applied as a package, so most of the tree has no manifest entry.
 *     `storage/`, uploaded media and anything hand-created on the server are
 *     outside it and always will be.
 *   - **It cannot see a file that was ADDED.** There is no manifest entry to
 *     miss, and the shop has no list of what the tree is supposed to contain.
 *     A dropped-in `shell.php` is invisible to this check. Saying so matters:
 *     the owner's ask was "no bot can inject code anywhere", and a check that
 *     let him believe this covered new files would be worse than none.
 *   - **A legitimate hand-edit reads as a finding**, because from here the two
 *     are the same event. That is the design, not a defect — this host has no
 *     shell, so nobody SHOULD be hand-editing, and the whole point is to
 *     notice when somebody did.
 */
final class IntegrityChecker
{
    /**
     * The actions this module can take on a finding.
     *
     * ONE ENTRY, and the second one is the owner's to add. See the class
     * docblock: the plan records "restore automatically, or alert and wait?"
     * as an open question with a recommendation, and a lane does not answer
     * an owner's open question by shipping code for one of the branches.
     */
    public const ACTIONS = ['alert' => 'Alert me and wait'];

    /** Findings this kind of trouble is filed under. */
    public const E_CHANGED = 'integrity.changed';

    public const E_MISSING = 'integrity.missing';

    public const EVENTS = [self::E_CHANGED, self::E_MISSING];

    /**
     * Hard ceilings, so one screen open cannot become the slowest request on
     * the site. Shared hosting, no shell, and the owner opens this on a phone.
     *
     * MAX_FILES bounds the walk; MAX_BYTES leaves a file bigger than 24 MB
     * unhashed and counts it as skipped rather than reading it; MAX_FINDINGS
     * bounds the ROWS one scan may write, so a tree somebody replaced wholesale
     * reports the first fifty and says there were more, instead of inserting
     * thousands of rows into the table this module is also trying to keep
     * bounded.
     */
    public const MAX_FILES = 6000;

    public const MAX_BYTES = 25165824;

    public const MAX_FINDINGS = 50;

    /** Where the last scan's summary lives. See "NOT A TABLE AND NOT A SETTING". */
    public const STATE_KEY = 'kbb.sec.integrity.state';

    /** The summary outlives any sensible scan interval; the findings are rows. */
    public const STATE_TTL = 2592000;

    public function __construct(private SecurityModule $security) {}

    /* ═══════════════════════════════════════════════════ the scan itself ═══ */

    /**
     * Hash what a package installed and compare it with what it said it was.
     *
     * Runs ONLY from App\Http\Controllers\Admin\SecurityController — the
     * owner-only Store → Security screen — and from nowhere else. Nothing here
     * is registered on a request path, on a model event or in a provider, and
     * SecurityIntegrityTest asserts that by reading AppServiceProvider and the
     * middleware directory as text. That is what keeps a filesystem walk off
     * the storefront, and it is also what keeps this class clear of the
     * settings-cache trap that cost this lane five unrelated tests in round
     * one: nothing here can run before an admin is signed in.
     *
     * @return array<string, mixed> the state the screen draws
     */
    public function scan(): array
    {
        $started = microtime(true);
        $survey = $this->survey();
        $expected = $survey['expected'];

        $checked = 0;
        $skipped = 0;
        $findings = [];
        $truncated = false;

        foreach ($expected as $relative => $hash) {
            if ($checked >= self::MAX_FILES) {
                $truncated = true;

                break;
            }

            $checked++;
            $target = $this->targetFor($relative);

            if (! is_file($target)) {
                $findings[] = [
                    'kind' => 'missing',
                    'path' => $relative,
                    'expected' => (string) $hash,
                    'found' => null,
                    'size' => null,
                    'changed_at' => null,
                ];

                continue;
            }

            $size = (int) @filesize($target);

            // Too big to hash on a shared plan's request budget. Counted and
            // named rather than silently passed: "not checked" and "checked and
            // clean" must never render the same, which is the same rule the
            // verdict line follows for "nothing happened".
            if ($size > self::MAX_BYTES) {
                $skipped++;

                continue;
            }

            $actual = @hash_file('sha256', $target);

            if ($actual === false) {
                $skipped++;

                continue;
            }

            if (! hash_equals((string) $hash, (string) $actual)) {
                $findings[] = [
                    'kind' => 'changed',
                    'path' => $relative,
                    'expected' => (string) $hash,
                    'found' => (string) $actual,
                    'size' => $size,
                    'changed_at' => @filemtime($target) ?: null,
                ];
            }
        }

        $recorded = $this->recordFindings($findings);

        $state = [
            'ran_at' => Carbon::now()->toDateTimeString(),
            'expected' => count($expected),
            'checked' => $checked,
            'skipped' => $skipped,
            'findings' => count($findings),
            'recorded' => $recorded,
            'truncated' => $truncated,
            // The first few paths, for the verdict sentence. Capped so the
            // cached blob cannot grow with a catastrophe.
            'paths' => array_slice(array_column($findings, 'path'), 0, 8),
            'releases' => $survey['sources'],
            'applied' => $survey['applied'],
            'took_ms' => (int) round((microtime(true) - $started) * 1000),
            'action' => (string) $this->security->get('integrity_action'),
        ];

        try {
            Cache::put(self::STATE_KEY, $state, self::STATE_TTL);
        } catch (\Throwable) {
            // A cache that will not write costs the screen its "last checked"
            // line and nothing else. The findings are rows and are already in.
        }

        return $state;
    }

    /**
     * The scan the screen runs on open: at most once every `integrity_hours`.
     *
     * Opening a screen must not be the thing that makes the screen slow, and
     * the owner refreshes. `force` is the Check now button, which is a separate
     * endpoint behind a capability of its own.
     *
     * @return array<string, mixed>|null the state, or null when nothing ran
     */
    public function scanIfDue(bool $force = false): ?array
    {
        if (! $this->security->get('integrity_on')) {
            return null;
        }

        if ($force) {
            return $this->scan();
        }

        $state = $this->state();

        if ($state === null) {
            return $this->scan();
        }

        $hours = (int) $this->security->get('integrity_hours');

        try {
            $ranAt = Carbon::parse((string) $state['ran_at']);
        } catch (\Throwable) {
            return $this->scan();
        }

        return $ranAt->lte(Carbon::now()->subHours($hours)) ? $this->scan() : null;
    }

    /**
     * The last scan's summary, or null if there has not been one.
     *
     * NOT A TABLE AND NOT A SETTING. Not a table, because a summary that is
     * overwritten every scan is not evidence and the evidence is already rows
     * in `audit_events`. Not a setting, because `settings` is the table this
     * module audits: writing the scan summary there would fire
     * SecurityModule's own Setting::saved hook and fill the administrative
     * trail with a row saying the security screen was opened, every time the
     * security screen was opened.
     *
     * The cache is cleared by UpdateRunner after every package applies, which
     * is exactly when a stale summary should go.
     *
     * @return array<string, mixed>|null
     */
    public function state(): ?array
    {
        try {
            $state = Cache::get(self::STATE_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_array($state) && isset($state['ran_at']) ? $state : null;
    }

    /* ══════════════════════════════════════════════ the effective manifest ═══ */

    /**
     * path => sha256 for everything an applied package installed, newest wins.
     *
     * @return array<string, string>
     */
    public function expected(): array
    {
        return $this->survey()['expected'];
    }

    /**
     * The effective manifest, and the two counts that say how complete it is.
     *
     * ── WHY ONE PASS AND NOT THREE ──────────────────────────────────────────
     *
     * expected() and sourceCount() each walked the applied releases and each
     * decoded every manifest, which is one query and one json_decode per
     * release more than the job needs — on a shop with sixty applied packages
     * that is sixty redundant decodes of a manifest that was just decoded.
     * Folding them costs nothing and gains the third number below.
     *
     * ── AND WHY `applied` IS WORTH REPORTING ────────────────────────────────
     *
     * Round two left a conditional on this screen: "older releases are read
     * from the archived zip when there is one". Whether there is one is not
     * something the owner should have to find out by reading a lane report, so
     * the screen now states it. `applied` is every release this shop has
     * installed; `sources` is how many of them it can actually speak about. A
     * gap between the two is the honest number, and on this shop it is small
     * and known — see docs/LC-SECURITY-MODULE.md.
     *
     * @return array{expected: array<string, string>, sources: int, applied: int}
     */
    public function survey(): array
    {
        $out = [];
        $sources = 0;
        $applied = 0;

        foreach ($this->appliedReleases() as $release) {
            $applied++;
            $manifest = $this->manifestOf($release);

            if ($manifest === []) {
                continue;
            }

            $sources++;

            foreach ($manifest as $path => $hash) {
                if (! is_string($path) || ! is_string($hash) || $hash === '') {
                    continue;
                }

                $out[$path] = $hash;
            }
        }

        return ['expected' => $out, 'sources' => $sources, 'applied' => $applied];
    }

    /**
     * Applied releases, oldest version first.
     *
     * @return list<UpdateRelease>
     */
    private function appliedReleases(): array
    {
        try {
            $releases = UpdateRelease::query()->where('status', 'applied')->get()->all();
        } catch (\Throwable) {
            // The column or the table may not exist yet — a package lands as
            // files and its migrations run afterwards. Nothing to check is a
            // true answer in that window, and a 500 on the Security screen is
            // not.
            return [];
        }

        usort($releases, static function (UpdateRelease $a, UpdateRelease $b): int {
            $byVersion = version_compare((string) $a->version, (string) $b->version);

            return $byVersion !== 0 ? $byVersion : ((int) $a->getKey() <=> (int) $b->getKey());
        });

        return $releases;
    }

    /**
     * One release's manifest: the column if it has one, else its archived zip.
     *
     * @return array<string, string>
     */
    public function manifestOf(UpdateRelease $release): array
    {
        $stored = $release->manifest ?? null;

        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);

            return is_array($decoded) ? $decoded : [];
        }

        $recovered = $this->manifestFromArchive((string) ($release->archive_path ?? ''));

        if ($recovered === []) {
            return [];
        }

        /*
         * Written back, so the zip is opened once ever. This is a write inside
         * a read, which is worth one sentence: it is idempotent, it is bounded
         * by the number of releases that predate the column, it happens only on
         * an owner-only screen, and it records something already true rather
         * than deciding anything. It also cannot write an audit row —
         * SecurityModule's UpdateRelease::updated hook returns unless `status`
         * changed, and this changes `manifest`.
         */
        try {
            $release->forceFill(['manifest' => json_encode($recovered, JSON_UNESCAPED_SLASHES)])->save();
        } catch (\Throwable) {
            // Then it is read from the zip again next time, which costs one
            // file read and is not worth failing the screen over.
        }

        return $recovered;
    }

    /**
     * `update.json`'s `files` map, out of an archived package.
     *
     * Read through the Storage facade's own path, not a hand-built one: the
     * 'local' disk's real root is storage/app/private rather than storage/app
     * on this install, which UpdateRunner::archivePackage() already had to
     * learn once.
     *
     * @return array<string, string>
     */
    private function manifestFromArchive(string $archivePath): array
    {
        if ($archivePath === '') {
            return [];
        }

        try {
            $disk = Storage::disk('local');

            if (! $disk->exists($archivePath)) {
                return [];
            }

            $zip = new ZipArchive();

            if ($zip->open($disk->path($archivePath)) !== true) {
                return [];
            }

            $json = $zip->getFromName('update.json');
            $zip->close();

            if ($json === false) {
                return [];
            }

            $manifest = json_decode((string) $json, true);
            $files = is_array($manifest) ? ($manifest['files'] ?? null) : null;

            return is_array($files) ? $files : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /* ═══════════════════════════════════════════════════════ the findings ═══ */

    /**
     * Where a packaged file belongs on disk.
     *
     * ▲ THIS MUST AGREE WITH UpdateRunner::targetFor(), WHICH IS WHERE THE FILE
     * WAS ACTUALLY WRITTEN. `public/` lives OUTSIDE the application root on
     * this install — bootstrap/app.php ends with
     * usePublicPath('/home/.../public_html/kbb-upgrade') — so a checker that
     * joined every path onto base_path() would hash nothing for every asset a
     * package ships and report the whole of public/ as missing.
     *
     * It is restated here rather than shared, because making UpdateRunner's
     * private method public to borrow it would edit the one class in this shop
     * that writes files during an update, for a read-only feature's
     * convenience. SecurityIntegrityTest closes the duplication the other way:
     * it reflects UpdateRunner::targetFor() and asserts the two return the same
     * string for every shape of path, so the day somebody changes one, the test
     * names the other.
     */
    public function targetFor(string $relative): string
    {
        if (str_starts_with($relative, 'public/')) {
            return rtrim(public_path(), '/').'/'.substr($relative, strlen('public/'));
        }

        return rtrim(base_path(), '/').'/'.$relative;
    }

    /**
     * Turn findings into rows on the Security screen, without repeating.
     *
     * A file that is still modified on the next scan is the SAME finding, so it
     * bumps `hits` and `last_seen_at` on the row that already says so — the
     * collapse `not_found_log` uses for a repeated 404 and this module already
     * uses for a repeated rate-limit trip. Without it, an owner who opens the
     * screen every morning would have thirty identical rows by the end of the
     * month and the thirtieth would look like thirty separate intrusions.
     *
     * The identity of a finding is (event, path, found-hash). A file that
     * changes AGAIN gets a new row, because a second edit is a second event and
     * the first row's evidence must not be overwritten by it.
     *
     * @param  list<array<string, mixed>>  $findings
     */
    private function recordFindings(array $findings): int
    {
        $written = 0;

        foreach (array_slice($findings, 0, self::MAX_FINDINGS) as $finding) {
            try {
                $written += $this->recordOne($finding) ? 1 : 0;
            } catch (\Throwable) {
                // One unwritable row is not worth the other forty-nine, nor
                // worth a 500 on the screen that was trying to show them.
            }
        }

        return $written;
    }

    /** @param array<string, mixed> $finding */
    private function recordOne(array $finding): bool
    {
        $missing = $finding['kind'] === 'missing';
        $event = $missing ? self::E_MISSING : self::E_CHANGED;
        $path = (string) $finding['path'];

        $before = 'the package shipped sha256 '.substr((string) $finding['expected'], 0, 16).'…';
        $after = $missing
            ? 'the file is not on the server'
            : 'the server holds sha256 '.substr((string) $finding['found'], 0, 16).'…'
                .' ('.(int) $finding['size'].' bytes'
                .($finding['changed_at'] ? ', last written '.Carbon::createFromTimestamp((int) $finding['changed_at'])->toDateTimeString() : '')
                .')';

        /*
         * Matched against the CLIPPED path, because that is what record()
         * stored: `subject` is 191 characters and a longer path would be cut on
         * the way in, so a lookup with the full one could never find the row it
         * wrote a moment ago and every scan would insert another.
         */
        $existing = AuditEvent::query()
            ->where('event', $event)
            ->where('subject', mb_substr($path, 0, 191))
            ->where('after', mb_substr($after, 0, SecurityModule::VALUE_CAP))
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            AuditEvent::query()->whereKey($existing->getKey())->update([
                'hits' => DB::raw('hits + 1'),
                'last_seen_at' => Carbon::now(),
            ]);

            return false;
        }

        $summary = $missing
            ? $path.' is missing — a package installed it and it is not there'
            : $path.' differs from the package that installed it';

        return $this->security->record($event, $summary, [
            'group' => 'integrity',
            'subject' => $path,
            'before' => $before,
            'after' => $after,
            'severity' => 'alert',
            /*
             * NO ACTOR, NO ADDRESS, NO REQUEST PATH. The admin who opened the
             * screen is not the person who changed the file — that is the whole
             * reason this check exists — and a row that printed "by <owner>,
             * from 127.0.0.1, GET /admin-api/security" would name the one
             * person it can prove is innocent. What is known is the file, the
             * two hashes and the time; what is not known is who, and the row
             * says nothing rather than something false.
             */
            'anonymous' => true,
        ]) !== null;
    }
}
