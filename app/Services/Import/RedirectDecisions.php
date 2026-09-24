<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\RedirectDecision;
use Illuminate\Support\Facades\DB;

/**
 * The ask bucket, answered.
 *
 * =============================================================================
 * WHAT WAS WRONG, WHICH IS NOT A BUG IN ANY LINE OF CODE
 * =============================================================================
 *
 * Phase 13 asks for "three-bucket classification: migrate / discard / ask —
 * Rafi approves any discard list". `RedirectMap` built the three buckets and
 * the owner could read them on Store → Import and download them as a CSV. He
 * could not ANSWER one. The ask bucket came back byte-identical on every load,
 * for ever, and the only way to act on a row in it was to copy the address into
 * Store → Redirects by hand and type the destination out again.
 *
 * Then the bucket got bigger. `CheckRedirects` is registered in the global
 * pipeline now (docs/GP-ADDRESSES-LAND.md §13.7), so three branches of
 * `RedirectMap::reachable()` that rested on "the table only fires on a 404"
 * changed their minds — one of them had been DISCARDING the proposals silently,
 * and on a real export that branch is most of the category rule. A list nobody
 * could act on became a longer list nobody could act on.
 *
 * =============================================================================
 * AN ANSWER IS STORED, A PROPOSAL IS NOT
 * =============================================================================
 *
 * `RedirectMap` re-derives everything on every call and must keep doing so: a
 * map cached across a category rename is a map of a shop that no longer exists.
 * An answer cannot be re-derived from anything — there is nothing in the
 * catalogue that records a person's opinion — so it is the one thing here with
 * a table behind it. See the migration for why that table is not just
 * `redirects`.
 *
 * =============================================================================
 * AN ANSWER IS TO A QUESTION, NOT TO AN ADDRESS
 * =============================================================================
 *
 * This is the property the whole class is built around, and the one a simpler
 * implementation gets wrong.
 *
 * `apply()` compares the stored destination with the one the map proposes
 * TODAY. Approving "/toners/ → /product-category/skincare/toners/" is approval
 * of THAT redirect. If somebody re-parents the category afterwards, the map
 * proposes "/toners/ → /product-category/bath/toners/" — a different sentence,
 * which he has never seen. The answer goes stale, the row goes back into the
 * ask bucket with the two destinations named, and he is asked once more.
 *
 * The alternative — keying on the address alone — writes a redirect he never
 * agreed to, and does it silently, on a shop where the only way to notice is to
 * follow the link.
 *
 * A REFUSAL IS STICKIER THAN AN APPROVAL, deliberately. "No" suppresses the
 * proposal wherever it comes back with the same destination, not only while it
 * is still an ASK row — because a proposal he declined reappearing as a
 * migrate row and being written on the next "Write redirects" is exactly the
 * surprise this class exists to prevent. "Yes" only promotes a row that is
 * being asked about; on a row the map already migrates it is a no-op.
 *
 * =============================================================================
 * COST
 * =============================================================================
 *
 * ONE query to read every answer, and the table is bounded by the number of
 * questions the owner has answered — which is bounded by the size of the map.
 * Not a `whereIn` over the proposals' sources: that is the same number of rows
 * back for one bound variable per proposal, and a map with 3,000 rows in it
 * would sail past SQLite's 32,766-variable ceiling and fail rather than being
 * slow.
 *
 * Writing is `upsert()` in chunks, not `updateOrCreate()` in a loop: accepting
 * a question's worth of rows is 300 answers, and 600 queries inside one
 * transaction on a shared host is the shape of request that gets killed halfway.
 */
final class RedirectDecisions
{
    public const ACCEPT = 'accept';

    public const REJECT = 'reject';

    /** No answer has been given about this row. */
    public const UNANSWERED = '';

    /**
     * An answer was given and the map has moved under it.
     *
     * Reported as its own state rather than folded into UNANSWERED, because
     * "you have not looked at this" and "what you agreed to has changed" are
     * different sentences and the screen prints both.
     */
    public const STALE = 'stale';

    /**
     * Rows per `upsert()`. Eight bound columns each, so 200 rows is 1,600
     * placeholders — comfortably inside SQLite's 32,766 and MySQL's 65,535,
     * with room for the ceiling to be lower than documented on a shared host.
     */
    private const CHUNK = 200;

    /**
     * Fold the owner's answers into a freshly derived map.
     *
     * Returns the proposals with `decision` moved where an answer says so, and
     * every row carrying `answered` — one of UNANSWERED, ACCEPT, REJECT or
     * STALE — so a caller can tell a row that was decided from one that never
     * needed deciding.
     *
     * @param  list<array<string, mixed>>  $proposals
     * @return list<array<string, mixed>>
     */
    public function apply(array $proposals): array
    {
        $answers = $this->all();

        foreach ($proposals as $index => $proposal) {
            $proposals[$index]['answered'] = self::UNANSWERED;

            $answer = $answers[(string) $proposal['source']] ?? null;

            if ($answer === null || $proposal['decision'] === RedirectMap::DISCARD) {
                // Nothing to say, or nothing left to overrule: a discarded row
                // is already not being written and answering it changes nothing.
                continue;
            }

            /*
             * THE STALENESS CHECK. A stored answer is about a destination, and
             * this compares it with the destination on offer today.
             *
             * A REFUSAL of an undecidable question stored no destination —
             * there was none — so it is matched on the address alone. That is
             * safe in the one direction that matters: the outcome of honouring
             * it is DISCARD, which writes nothing.
             */
            $stored = (string) ($answer->target ?? '');
            $offered = (string) $proposal['target'];

            if ($answer->decision === self::ACCEPT && $stored !== $offered) {
                $proposals[$index]['answered'] = self::STALE;
                $proposals[$index]['reason'] = 'you approved sending this to "'.$stored.'", and this map now '
                    .'proposes "'.$offered.'" instead — something moved after you answered, so it is asking '
                    .'again. The original question was: '.$proposal['reason'];

                continue;
            }

            if ($answer->decision === self::REJECT) {
                if ($stored !== '' && $stored !== $offered) {
                    $proposals[$index]['answered'] = self::STALE;
                    $proposals[$index]['reason'] = 'you declined sending this to "'.$stored.'", and this map now '
                        .'proposes "'.$offered.'" instead, which is a different question. The original one was: '
                        .$proposal['reason'];

                    continue;
                }

                $proposals[$index]['answered'] = self::REJECT;
                $proposals[$index]['decision'] = RedirectMap::DISCARD;
                $proposals[$index]['reason'] = 'you declined this on '.$this->when($answer)
                    .'. It was asking: '.$proposal['reason'];

                continue;
            }

            if ($proposal['decision'] !== RedirectMap::ASK) {
                // An approval of a row the map already migrates. Nothing to
                // move, and saying "you approved this" on a row that was never
                // a question would be noise.
                continue;
            }

            $proposals[$index]['answered'] = self::ACCEPT;
            $proposals[$index]['decision'] = RedirectMap::MIGRATE;
            $proposals[$index]['reason'] = 'you approved this on '.$this->when($answer)
                .'. It was asking: '.$proposal['reason'];
        }

        return $proposals;
    }

    /**
     * Record an answer against every proposal in $sources that is currently a
     * question.
     *
     * =========================================================================
     * THE PROPOSALS ARE THE ALLOWLIST
     * =========================================================================
     *
     * $proposals is derived by the SERVER on this request. A source the browser
     * names that is not a question in it is refused, and the destination stored
     * is the one the server derived — never one the request carried. Without
     * that, `/admin-api/urls-media/decisions` is an endpoint that writes
     * arbitrary rows into `redirects` by way of an "approval", which is the
     * rule CLAUDE.md states for `/api/*` applied one floor up where the stakes
     * are higher: this endpoint moves every visitor who lands on an address.
     *
     * AN ACCEPT IS REFUSED ON AN UNDECIDABLE QUESTION. `RedirectMap::decidable()`
     * says which, and the check is made here as well as on the screen because a
     * screen is not a guard.
     *
     * @param  list<array<string, mixed>>  $proposals  the map as the server derives it now
     * @param  list<string>  $sources
     * @return array{recorded: int, refused: list<string>}
     */
    public function record(array $proposals, string $action, array $sources, ?string $by = null): array
    {
        if ($action !== self::ACCEPT && $action !== self::REJECT) {
            return ['recorded' => 0, 'refused' => []];
        }

        $wanted = array_flip(array_map(strval(...), $sources));
        $now = now();
        $rows = [];
        $refused = [];
        /*
         * Which addresses have already been answered FOR, kept as a set beside
         * the list. Scanning $refused per requested source would be quadratic,
         * and `sources` is allowed to carry 5,000 of them — a bulk answer would
         * spend longer building the refusal list than writing the answers.
         */
        $handled = [];

        foreach ($proposals as $proposal) {
            $source = (string) $proposal['source'];

            if (! isset($wanted[$source]) || isset($rows[$source])) {
                continue;
            }

            if ($proposal['decision'] !== RedirectMap::ASK) {
                $refused[] = $source.' — this is not a question any more, so there is nothing to answer';
                $handled[$source] = true;

                continue;
            }

            if ($action === self::ACCEPT && ! RedirectMap::decidable($proposal)) {
                $refused[] = $source.' — this question cannot be answered with yes: '
                    .(trim((string) $proposal['target']) === ''
                        ? 'there is no address on this shop to send it to'
                        : 'the redirect it would write is a loop');
                $handled[$source] = true;

                continue;
            }

            $rows[$source] = [
                'source' => $source,
                'decision' => $action,
                'target' => $proposal['target'] === '' ? null : (string) $proposal['target'],
                'question' => (string) ($proposal['question'] ?? '') ?: null,
                'rule' => (string) ($proposal['rule'] ?? '') ?: null,
                'subject' => (string) ($proposal['subject'] ?? '') ?: null,
                'decided_by' => $by,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($sources as $source) {
            $source = (string) $source;

            if (! isset($rows[$source]) && ! isset($handled[$source])) {
                $refused[] = $source.' — this map does not propose anything for that address';
            }
        }

        if ($rows === []) {
            return ['recorded' => 0, 'refused' => $refused];
        }

        $rows = array_values($rows);

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                RedirectDecision::query()->upsert(
                    $chunk,
                    ['source'],
                    ['decision', 'target', 'question', 'rule', 'subject', 'decided_by', 'updated_at'],
                );
            }
        });

        return ['recorded' => count($rows), 'refused' => $refused];
    }

    /**
     * Forget answers, so the rows go back to being questions.
     *
     * The undo for both buttons, and the reason "accept" does not simply write
     * the redirect and forget it happened: an approval that left no trace could
     * only be undone by guessing which rows in `redirects` this screen put
     * there.
     *
     * Not filtered through the proposals. An answer to a question the map no
     * longer asks is exactly the answer somebody wants to clear, and refusing
     * to clear it would leave a row nothing on any screen can reach.
     *
     * @param  list<string>  $sources
     */
    public function clear(array $sources): int
    {
        $sources = array_values(array_unique(array_map(strval(...), $sources)));

        if ($sources === []) {
            return 0;
        }

        $removed = 0;

        DB::transaction(function () use ($sources, &$removed): void {
            foreach (array_chunk($sources, self::CHUNK) as $chunk) {
                $removed += RedirectDecision::query()->whereIn('source', $chunk)->delete();
            }
        });

        return $removed;
    }

    /**
     * Forget every answer given to one question.
     *
     * Read off the STORED question and not off the map, so "undo all 312 of
     * these" reaches answers to rows the map has since stopped proposing. Those
     * are exactly the answers nothing else can reach.
     */
    public function clearQuestion(string $question): int
    {
        $question = trim($question);

        if ($question === '') {
            return 0;
        }

        return RedirectDecision::query()->where('question', $question)->delete();
    }

    /**
     * Every answer, keyed by the address it answers about.
     *
     * ONE QUERY, and the whole table — see the class comment on why this is not
     * a `whereIn` over the proposals.
     *
     * @return array<string, RedirectDecision>
     */
    public function all(): array
    {
        $out = [];

        foreach (RedirectDecision::query()->get(['source', 'decision', 'target', 'question', 'updated_at']) as $row) {
            $out[(string) $row->source] = $row;
        }

        return $out;
    }

    /** The date an answer was given, in the one format this project prints. */
    private function when(RedirectDecision $answer): string
    {
        $at = $answer->updated_at;

        return $at === null ? 'an unrecorded date' : $at->format('j M Y');
    }
}
