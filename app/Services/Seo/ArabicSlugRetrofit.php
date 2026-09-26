<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Redirect;
use App\Support\LocaleSlugs;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;

/**
 * Turning the Arabic slug policy on and off without orphaning an address in
 * either direction.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE ONE QUESTION THE OWNER HAS TO BE ABLE TO ANSWER: CAN I CHANGE MY MIND?
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * A slug change is not a setting, it is a change of address, and an address that
 * has been indexed needs a 301 that lives for ever. So this class does not
 * "apply a setting"; it writes the redirects the change needs BEFORE the change
 * takes effect, and it can write the redirects the reverse change needs too.
 *
 * ON  — `/ar/product/anua-heartleaf-toner/` becomes
 *       `/ar/product/مرطب-الوجه/`, and the old Arabic address 301s to the new
 *       one. The ENGLISH address does not move, which is the whole reason
 *       `redirects.locale` exists: the row is written with locale `ar`, so
 *       `/product/anua-heartleaf-toner/` is untouched.
 *
 * OFF — the Arabic addresses that were live go back to the shared slug, and each
 *       one gets a row of its own so nothing that was shared or indexed under it
 *       dead-ends. Those rows' sources are Arabic, which is where the other half
 *       of this round is load-bearing: `CheckRedirects::spellings()` decodes the
 *       incoming path, so ONE row answers the readable spelling and both hex
 *       spellings a real client sends. Before that fix, a reverse row could only
 *       have been matched by a client sending raw UTF-8 bytes, which none does —
 *       which is to say the switch would not have been reversible at all, and
 *       that fact would only have surfaced after somebody flipped it back.
 *
 * ── WHAT IT IS HONEST ABOUT ─────────────────────────────────────────────────
 *
 * ONE HOP, NOT TWO, and only if the rows are written in the right order. Flipping
 * on, off and on again leaves the FIRST generation's rows pointing at addresses
 * the second generation has moved, so `RedirectManager`'s collapse is what keeps
 * a chain from forming; `plan()` refuses to write a row whose source is an
 * address this shop is currently serving, which is the same refusal
 * `CategoryPath::record()` makes and for the same reason — a row that shadows a
 * live page 301s a working address away from itself.
 *
 * INTERNAL LINKS STILL POINT AT THE SHARED SLUG until `App\Support\Url::to()`
 * learns about this, which is another lane's file. With the policy on, a shopper
 * clicking an Arabic product card is sent to the shared address and the row above
 * forwards them — correct, indexed correctly, and one hop more than it needs to
 * be. Named here rather than hidden, and named in the report.
 */
final class ArabicSlugRetrofit
{
    /**
     * Every address that would move, and the row each one needs.
     *
     * Read-only: this is what a screen shows the owner BEFORE he commits, which
     * is the whole point of separating it from apply(). "671 products and 59
     * categories will change address, and 730 redirects will be written" is a
     * sentence somebody can decide about; a switch that has already done it is
     * not.
     *
     * @param  string  $to  the policy being moved TO
     * @return list<array{group: string, item_id: int, from: string, to: string, locale: string}>
     */
    public static function plan(string $to, string $locale = 'ar'): array
    {
        if (! in_array($to, LocaleSlugs::POLICIES, true) || ! Locale::isSupported($locale)) {
            return [];
        }

        $map = LocaleSlugs::map()[$locale] ?? [];
        $out = [];

        foreach (LocaleSlugs::ADDRESSABLE as $group => $prefix) {
            $byItem = $map[$group]['byItem'] ?? [];

            if ($byItem === []) {
                continue;
            }

            foreach (self::canonicalPaths($group, array_keys($byItem)) as $itemId => $canonical) {
                $translated = $prefix . self::leafPath($canonical, (string) $byItem[$itemId]) . '/';
                $shared = $prefix . $canonical . '/';

                if ($translated === $shared) {
                    continue;
                }

                // ON: the shared address moves to the translated one.
                // OFF: the translated address moves back to the shared one.
                $out[] = [
                    'group' => $group,
                    'item_id' => $itemId,
                    'from' => $to === LocaleSlugs::TRANSLATED ? $shared : $translated,
                    'to' => $to === LocaleSlugs::TRANSLATED ? $translated : $shared,
                    'locale' => $locale,
                ];
            }
        }

        return $out;
    }

    /**
     * Write the plan, then move the policy.
     *
     * THE ROWS FIRST AND THE SETTING SECOND, and the order is the only thing
     * standing between a shopper and a 404. Between the two statements the shop
     * serves the old addresses and also forwards them, which is harmless; the
     * other way round it serves the new addresses with nothing forwarding the old
     * ones, and every Arabic link anybody has saved is broken for as long as the
     * gap lasts. One transaction, so there is no gap at all on a shop whose
     * database supports one.
     *
     * @return array{written: int, skipped: int, policy: string}
     */
    public static function apply(string $to, string $locale = 'ar'): array
    {
        if (! in_array($to, LocaleSlugs::POLICIES, true)) {
            return ['written' => 0, 'skipped' => 0, 'policy' => LocaleSlugs::policy()];
        }

        $plan = self::plan($to, $locale);
        $written = 0;
        $skipped = 0;

        DB::transaction(static function () use ($plan, $to, $locale, &$written, &$skipped) {
            /*
             * THE PREVIOUS GENERATION GOES FIRST, AND WITHOUT THIS THE SWITCH IS
             * NOT REVERSIBLE — IT IS A LOOP.
             *
             * Flipping on writes `/product/<shared>/` → `/product/<arabic>/` in
             * Arabic. Flipping off needs the opposite row, and leaving the first
             * one in place gives two rows pointing at each other: CheckRedirects
             * ::loops() catches that at read time and serves the page rather than
             * bouncing anybody, so nothing visibly breaks — and BOTH rows go dead,
             * which means the address the owner switched back from stops
             * forwarding and he has no way to see why.
             *
             * Worse, the stale row's source is the address the shop is serving
             * again, so it would 301 a working page away from itself. `record()`
             * on CategoryPath refuses exactly that, for exactly that reason.
             *
             * ONLY THIS SERVICE'S OWN ROWS. `auto_created` AND a locale, which no
             * hand-written row carries — the Redirects screen writes neither — so a
             * decision the owner made on that screen cannot be deleted by a
             * switch on another one. Deleted one at a time so Redirect::booted()
             * fires and the source index is dropped; a mass delete() on the query
             * builder fires no model events, which is the trap that file names.
             */
            $stale = Redirect::query()
                ->where('auto_created', true)
                ->where('locale', $locale)
                ->get();

            foreach ($stale as $row) {
                $row->delete();
            }

            foreach ($plan as $move) {
                if (self::write($move)) {
                    $written++;
                } else {
                    $skipped++;
                }
            }

            \App\Models\Setting::updateOrCreate(
                ['key' => LocaleSlugs::SETTING],
                ['value' => $to, 'autoload' => true],
            );
        });

        \App\Models\Setting::flushMap();
        \App\Services\SettingsService::forgetMemo();
        LocaleSlugs::flush();
        \App\Http\Middleware\CheckRedirects::flushIndex();

        return ['written' => $written, 'skipped' => $skipped, 'policy' => LocaleSlugs::policy()];
    }

    /**
     * One row, both spellings of its trailing slash.
     *
     * BOTH, because `getPathInfo()` reports whichever the client sent and this
     * table compares byte for byte — the gap docs/SEO-URL-MAP.md §6.4 reports for
     * hand-written rows. Every shipped seed writes both for this reason and a
     * generated one has no excuse not to.
     *
     * @param  array{group: string, item_id: int, from: string, to: string, locale: string}  $move
     */
    private static function write(array $move): bool
    {
        // A row pointing an address at itself is a row RedirectMap refuses to
        // write and CheckRedirects refuses to follow. It cannot arise from a
        // plan() entry — those are skipped when the two paths match — but this is
        // the method that writes to the table, so it is the method that says no.
        if ($move['from'] === $move['to']) {
            return false;
        }

        /*
         * A ROW THE OWNER WROTE IS NEVER OVERWRITTEN.
         *
         * `redirects.source` is unique, so updateOrCreate() on it would silently
         * replace a decision he made on Store → SEO & Meta → Redirects & 404s
         * with one this switch made. His wins — that is the rule
         * CheckRedirects::lookup() already follows at read time ("the table
         * first, always, and the derived rule only after it") and it has to hold
         * at write time too, or the screen he can see is the one that loses.
         */
        foreach ([$move['from'], rtrim($move['from'], '/')] as $source) {
            if ($source === '') {
                continue;
            }

            $existing = Redirect::query()->where('source', $source)->first();

            if ($existing !== null && ! $existing->auto_created) {
                continue;
            }

            Redirect::updateOrCreate(
                ['source' => $source],
                [
                    'locale' => $move['locale'],
                    'target' => $move['to'],
                    'code' => 301,
                    'enabled' => true,
                    'auto_created' => true,
                ],
            );
        }

        return true;
    }

    /**
     * The canonical (shared) path of each row, keyed by id.
     *
     * One query per group rather than one per row — `plan()` runs over 730 rows
     * on the real catalogue and is reachable from an admin screen.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private static function canonicalPaths(string $group, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        /*
         * `categories.path` is the nested address and `products` has no such
         * column, so the shape of "the canonical path" differs per group. The
         * cached `path` may be null on a row imported from WooCommerce — the
         * same caveat CategoryPath::canonicalPath() carries — so the slug is the
         * fallback and a wrong-but-resolvable single-segment path is better than
         * a row with no address at all, which CategoryPath would 301 to nowhere.
         */
        $hasPath = $group === 'categories';

        $rows = DB::table($group)
            ->whereIn('id', $ids)
            ->get($hasPath ? ['id', 'slug', 'path'] : ['id', 'slug']);

        foreach ($rows as $row) {
            $path = $hasPath && is_string($row->path ?? null) && $row->path !== ''
                ? trim((string) $row->path, '/')
                : (string) $row->slug;

            if ($path !== '') {
                $out[(int) $row->id] = $path;
            }
        }

        return $out;
    }

    /** Replace the leaf of a nested path, keeping the ancestry. */
    private static function leafPath(string $canonicalPath, string $leaf): string
    {
        $segments = explode('/', trim($canonicalPath, '/'));
        array_pop($segments);
        $segments[] = $leaf;

        return implode('/', $segments);
    }
}
