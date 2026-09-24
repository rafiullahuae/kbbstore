<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\AuditEvent;
use App\Services\SecurityModule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * A violation report, which is an unauthenticated POST from somebody's browser.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * EVERY BYTE OF THIS IS ATTACKER-CONTROLLED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * There is no way to make a CSP report endpoint authenticated: the browser
 * posts it with no session, from a page any visitor may be on, and the spec
 * gives it no signature. So this is the same surface `/api/*` is, and it gets
 * the same treatment CLAUDE.md sets out for that surface — an explicit
 * allowlist of what may be read out of the body, and nothing else reaching a
 * row.
 *
 * Anybody can post one. Anybody can post a million. Anybody can post one whose
 * `document-uri` is a page of HTML, whose `blocked-uri` is a megabyte, and
 * whose `effective-directive` is `<script>alert(1)</script>`. Five separate
 * bounds, in the order they apply:
 *
 *   1. THE ROUTE'S THROTTLE. routes/security-csp.php sets throttle:60,1, which
 *      is per-address and well above what a real browser sends for a page
 *      view. Reports above it are answered 429 and never reach this class.
 *   2. THE BODY CAP. MAX_BODY bytes are read and a body over it is dropped
 *      whole, before json_decode is asked to parse it.
 *   3. THE FIELD ALLOWLIST. Exactly four values are read out of the report by
 *      name — the directive, what was blocked, which document, and the browser's
 *      own 40-character sample. Everything else the body carries, in either of
 *      the two report shapes, is discarded unread.
 *   4. THE SHAPE OF EACH ONE. A directive that is not [a-z-] is stored as
 *      `unknown`; a URI is reduced to scheme, host and path, so the query
 *      string — where tokens live, and where an attacker would put a novel
 *      value per request to defeat the collapse below — never reaches a row;
 *      every value is stripped of control characters and clipped.
 *   5. THE ROW CEILING. See enforceCspCap(). This is the one that matters
 *      most, and it is explained there.
 *
 * NOTHING HERE IS EVER PRINTED RAW. The Security screen escapes every value it
 * draws (`esc()` in resources/views/admin/partials/security-screen.blade.php,
 * applied to every field of every row), which is the rule this project already
 * follows for `reviews.author_email` and for a setting's before/after. What is
 * new here is only that the value arrives from a stranger rather than from the
 * owner, and the escaping does not care which.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * A FLOOD OF THESE MUST NOT COST THE OWNER HIS AUDIT TRAIL
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `audit_events` holds one ceiling already — SecurityModule::max_rows, 20,000,
 * enforced on the write path, which deletes the OLDEST rows when the table
 * passes it. Put an unauthenticated, unbounded writer into that table and the
 * ceiling becomes a weapon: a few hours of posted violations would push every
 * failed sign-in, every setting change and every package install out of the
 * trail, and the module would have deleted its own evidence on an attacker's
 * instruction.
 *
 * So violations get a ceiling of their own, `sec_csp_rows` (200 by default),
 * enforced here, on the write path, and scoped to `event = csp.violation` by
 * the query itself. Violations can therefore never occupy more than 200 of the
 * 20,000 rows however many are posted, and the other 19,800 are not reachable
 * from this endpoint at all. SecurityCspTest floods it and asserts the
 * administrative rows written before the flood are all still there.
 */
final class CspViolations
{
    /** The event every violation is filed under. */
    public const EVENT = 'csp.violation';

    /**
     * Longest report body read. A real one is a few hundred bytes.
     *
     * Dropped whole rather than truncated: a truncated JSON document does not
     * parse, so truncating would only move the work to json_decode.
     */
    public const MAX_BODY = 16384;

    /** Longest stored URI. Matches SecurityModule::VALUE_CAP on purpose. */
    public const URI_CAP = 200;

    /** The browser caps its own sample at 40 characters; this is the backstop. */
    public const SAMPLE_CAP = 120;

    /**
     * The directives a report may name.
     *
     * An ALLOWLIST and not a pattern, and that is the fifth time this module
     * has made the same choice: a pattern admits whatever a future browser
     * invents, and the screen groups by this value. Anything not on it is
     * stored as `unknown`, which is a true statement about a report this shop
     * does not recognise — and is one row, not one row per invention.
     *
     * Every directive ContentSecurityPolicy::DIRECTIVES declares, plus the
     * -elem and -attr refinements browsers report instead of the parent, plus
     * default-src, which is what a browser names when the parent is what
     * matched.
     *
     * @var list<string>
     */
    public const DIRECTIVES = [
        'default-src', 'base-uri', 'object-src', 'frame-ancestors', 'form-action',
        'script-src', 'script-src-elem', 'script-src-attr',
        'style-src', 'style-src-elem', 'style-src-attr',
        'font-src', 'img-src', 'connect-src', 'frame-src', 'media-src',
        'worker-src', 'manifest-src', 'child-src', 'prefetch-src',
    ];

    public function __construct(private SecurityModule $security) {}

    /**
     * Take one report, or take nothing. Never throws, never answers.
     *
     * Returns true when a row was written or bumped, which the controller uses
     * for nothing — it answers 204 either way, because what this endpoint did
     * with a report is not something an unauthenticated caller gets to learn.
     */
    public function receive(Request $request): bool
    {
        try {
            $report = $this->parse($request);

            if ($report === null) {
                return false;
            }

            return $this->record($request, $report);
        } catch (\Throwable) {
            // Same rule as everywhere else in this module: losing a report is
            // a gap in a screen, and throwing is a 500 on a public endpoint
            // that anybody can reach at will.
            return false;
        }
    }

    /* ═══════════════════════════════════════════════════════ reading it ═══ */

    /**
     * The four values, out of either report shape.
     *
     * TWO SHAPES, because there are two mechanisms and browsers disagree about
     * which they send. `report-uri` posts `application/csp-report` carrying
     * `{"csp-report": {...}}` with hyphenated keys; the Reporting API posts
     * `application/reports+json` carrying a LIST of `{"type":…, "body":{…}}`
     * with camelCase keys. ContentSecurityPolicy sends `report-uri` only and
     * says why, so today only the first shape can arrive — the second is read
     * anyway because it costs four lines and because the alternative is a
     * silent blank screen on the day that changes.
     *
     * @return array{directive: string, blocked: string, document: string, sample: string}|null
     */
    private function parse(Request $request): ?array
    {
        $raw = $request->getContent();

        if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_BODY) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        // Reporting API: a list of reports. Only the first is read — a browser
        // batches its own, and reading the rest would let one POST write as
        // many rows as it liked.
        if (isset($decoded[0]) && is_array($decoded[0])) {
            $body = $decoded[0]['body'] ?? null;
            $body = is_array($body) ? $body : [];

            return $this->fields([
                'effective-directive' => $body['effectiveDirective'] ?? null,
                'blocked-uri' => $body['blockedURL'] ?? null,
                'document-uri' => $body['documentURL'] ?? null,
                'script-sample' => $body['sample'] ?? null,
            ]);
        }

        $body = $decoded['csp-report'] ?? null;

        if (! is_array($body)) {
            return null;
        }

        return $this->fields($body);
    }

    /**
     * @param  array<mixed>  $body
     * @return array{directive: string, blocked: string, document: string, sample: string}
     */
    private function fields(array $body): array
    {
        /*
         * `effective-directive` is what actually matched; `violated-directive`
         * is the older spelling and carries the whole source list after the
         * name ("script-src 'self' https://…"), so it is cut at the first
         * space before the allowlist sees it. Reading only one of the two
         * would lose every report from one half of the browsers in use.
         */
        $directive = $this->scalar($body['effective-directive'] ?? null);

        if ($directive === '') {
            $directive = $this->scalar($body['violated-directive'] ?? null);
            $directive = explode(' ', $directive)[0];
        }

        return [
            'directive' => in_array($directive, self::DIRECTIVES, true) ? $directive : 'unknown',
            'blocked' => $this->uri($this->scalar($body['blocked-uri'] ?? null)),
            'document' => $this->documentPath($this->scalar($body['document-uri'] ?? null)),
            'sample' => $this->clean($this->scalar($body['script-sample'] ?? null), self::SAMPLE_CAP),
        ];
    }

    /** A value the body claims is a string, or ''. Never an array, never an object. */
    private function scalar(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * What was blocked, reduced to scheme, host and path.
     *
     * The query string is dropped, and that is not tidiness. A blocked URI
     * carrying `?t=<random>` is a different string on every report, which
     * would defeat the collapse below and turn one misconfigured third-party
     * script into one row per page view — and analytics URLs genuinely carry
     * a cache-buster. It also keeps whatever a query string held out of a
     * table somebody reads on a screen.
     *
     * The keywords a browser sends in place of a URL — `inline`, `eval`,
     * `data`, `blob`, `self` — are not URLs and pass through as themselves.
     * `inline` is the one that matters most on this shop: it is what every one
     * of the 121 inline handlers and 24 inline blocks reports as.
     */
    private function uri(string $value): string
    {
        if ($value === '') {
            return 'unknown';
        }

        $parts = @parse_url($value);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return $this->clean($value, self::URI_CAP);
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '//';

        return $this->clean($scheme.$parts['host'].(string) ($parts['path'] ?? ''), self::URI_CAP);
    }

    /**
     * Which page it happened on — the PATH, without the host or the query.
     *
     * The host is this shop's own and adds nothing; the query string on a
     * storefront page carries search terms and filters, which are the
     * shopper's business and not evidence of anything.
     */
    private function documentPath(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $path = @parse_url($value, PHP_URL_PATH);

        return $this->clean(is_string($path) && $path !== '' ? $path : $value, 191);
    }

    /**
     * Strip anything that is not printable, then clip.
     *
     * Control characters are what turn a stored value into a log-injection or
     * a broken CSV export later. The screen escapes for HTML; this is the
     * other half, and it happens before the value is stored rather than after.
     */
    private function clean(string $value, int $length): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return trim(mb_substr($value, 0, $length));
    }

    /* ═══════════════════════════════════════════════════ writing it down ═══ */

    /**
     * One row per (directive, blocked, page) per window, with a count.
     *
     * The same collapse SecurityModule::recordRateLimitTrip() uses on a 429,
     * and for a stronger reason: a rate-limit trip arrives when somebody is
     * hammering the shop, while a violation arrives on EVERY page view of a
     * page whose inline script the policy does not allow. On this shop that is
     * every page, so without the collapse a normal day's traffic would write
     * one row per visitor per inline block — and the screen would be unreadable
     * long before the ceiling caught it.
     *
     * One query either way: an UPDATE on the remembered row, or an INSERT when
     * the window has passed. The row's id is remembered in the cache rather
     * than looked up, because a SELECT to find the row to bump would double the
     * cost of exactly the case this exists to bound.
     *
     * @param  array{directive: string, blocked: string, document: string, sample: string}  $report
     */
    private function record(Request $request, array $report): bool
    {
        if (! $this->security->get('csp_on')) {
            /*
             * The switch is off, so no page carries the policy and no browser
             * should be sending this. Somebody posting one by hand is not a
             * reason to write a row: the endpoint stays cheap and stays quiet.
             */
            return false;
        }

        $window = (int) $this->security->get('csp_window');
        $key = 'kbb.sec.csp.'.sha1($report['directive'].'|'.$report['blocked'].'|'.$report['document']);
        $known = Cache::get($key);

        if (is_int($known) && AuditEvent::query()->whereKey($known)->where('event', self::EVENT)->update([
            'hits' => DB::raw('hits + 1'),
            'last_seen_at' => Carbon::now(),
        ]) === 1) {
            return true;
        }

        $row = $this->security->record(self::EVENT, $this->summary($report), [
            'group' => 'csp',
            'subject' => $report['directive'],
            /*
             * NO ACTOR, AND THE ADDRESS AND PAGE KEPT.
             *
             * An integrity finding is written fully anonymous, because the
             * admin who opened the screen is the one person the check can
             * prove innocent. A violation is the other case: nobody is signed
             * in, so there is no actor to borrow — but the visitor's address
             * and the page it happened on ARE the evidence, and a report with
             * neither says almost nothing. The one thing that must not happen
             * is the owner's email landing in the "by" column of a row a
             * stranger's browser wrote, which is exactly what would happen the
             * day he browses his own shop signed in. `no_actor` is that, and
             * only that.
             */
            'no_actor' => true,
            'path' => $report['document'],
            'method' => null,
            'before' => $report['blocked'],
            'after' => $report['sample'] === '' ? null : $report['sample'],
            'severity' => 'notice',
        ]);

        if ($row === null) {
            return false;
        }

        try {
            Cache::put($key, (int) $row->getKey(), max(60, $window));
        } catch (\Throwable) {
            // Then the next identical report writes a second row instead of
            // bumping this one, and the ceiling below still holds.
        }

        $this->enforceCspCap();

        return true;
    }

    /**
     * The headline, assembled from values that have already been bounded.
     *
     * Read on a screen, so it says what happened in the order somebody reads
     * it: what the policy would have stopped, and what the thing was.
     *
     * @param  array{directive: string, blocked: string, document: string, sample: string}  $report
     */
    private function summary(array $report): string
    {
        $what = $report['blocked'] === 'inline'
            ? 'something written into the page itself'
            : $report['blocked'];

        return 'Report-only policy would have blocked '.$report['directive'].': '.$what;
    }

    /**
     * Keep the newest `csp_rows` violations and drop the rest. Two queries.
     *
     * ── WHY THIS IS NOT THE CEILING THE MODULE ALREADY HAD ──────────────────
     *
     * SecurityModule::enforceCap() keeps the newest `max_rows` of EVERYTHING
     * and deletes the oldest, whatever kind they are. That is the right rule
     * for a table written by signed-in admins and by the shop's own throttle.
     * It is the wrong rule the moment an unauthenticated stranger can write to
     * the same table: the oldest rows are the audit trail, so a long enough
     * flood of violations would delete the record of who changed what — and it
     * would be the module doing the deleting, on the flooder's schedule.
     *
     * So violations are bounded among themselves, `WHERE event = csp.violation`
     * on both queries, and the bound is small (200 by default, floor 20). The
     * arithmetic that matters: violations can occupy at most `csp_rows` rows,
     * so 19,800 of the 20,000 are not reachable from this endpoint at all.
     *
     * ── THE CUT, RATHER THAN A COUNT AND AN OFFSET DELETE ───────────────────
     *
     * `skip($max)->take(1)->value('id')` finds the id of the first violation
     * PAST the ceiling, newest first; every violation at or below it goes.
     * One indexed lookup and one ranged delete. `DELETE … ORDER BY … LIMIT` is
     * not portable to SQLite, which is what the tests run on.
     *
     * ON EVERY INSERT, not one in a hundred like the trail's own ceiling. The
     * collapse above means an insert here is a violation this shop has not
     * seen in the window — rare on a healthy shop, and rate-limited to 60 a
     * minute per address on an unhealthy one. Amortising would buy two queries
     * a minute and cost the tightness of the bound, which is the only thing
     * this method is for.
     */
    private function enforceCspCap(): int
    {
        try {
            $max = (int) $this->security->get('csp_rows');

            $cut = AuditEvent::query()
                ->where('event', self::EVENT)
                ->orderByDesc('id')
                ->skip($max)
                ->take(1)
                ->value('id');

            if ($cut === null) {
                return 0;
            }

            return (int) AuditEvent::query()
                ->where('event', self::EVENT)
                ->where('id', '<=', $cut)
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }
}
