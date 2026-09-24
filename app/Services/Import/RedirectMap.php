<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Redirect;
use App\Support\LegacyCategoryUrls;

/**
 * The URL map: old WordPress addresses to this shop's.
 *
 * =============================================================================
 * READ THIS FIRST — TWO THINGS BELOW THIS BANNER ARE WRONG, AND ARE KEPT
 * =============================================================================
 *
 * Lane GB checked this class against a running server rather than against its
 * own reasoning, and two of its stated premises did not survive that. Both are
 * left in place, immediately below, because they are exactly what a reader will
 * reach for next time and each one needs its refutation attached to it. The
 * full transcript is `docs/GB-MEDIA-AND-REDIRECTS.md`.
 *
 * 1. "THE ONE RULE THE DATA ACTUALLY PROVES is the category one" —
 *    `/product-category/{leaf}/` → `/product-category/{nested/path}/`. Every
 *    row that rule puts in the `migrate` bucket is INERT. Measured: with a
 *    category `toners` nested under `skincare`, `GET /product-category/toners/`
 *    answers 301 to the nested path with NO redirect row in the database at
 *    all, because `CategoryArchiveController` → `CategoryPath::resolve()` does
 *    it. A row was then written for that source pointing at `/PROOF-INERT/`
 *    and the same request still answered 301 to the nested path. The redirect
 *    table is only consulted from the 404 handler (`CheckRedirects` is not
 *    registered as middleware — see its own comment), so an address that does
 *    not 404 can never be redirected by a row. THAT PREMISE IS GONE --
 *    CheckRedirects is registered in the global pipeline and runs before the
 *    router, so a row for an address this shop answers fires. `reachable()`
 *    below no longer discards on those grounds; it asks. The original note is
 *    kept because the reasoning it records is still how this file thinks, and
 *    only its conclusion moved.
 *
 *    (A sentence used to follow here saying `reachable()` "now demotes these to
 *    `discard`, per row and with the reason". That was the behaviour BEFORE the
 *    registration, it contradicted the four lines above it, and it is deleted
 *    rather than annotated because there is no reading of the file in which it
 *    is true.)
 *
 * 2. "WooCommerce commonly publishes a category at its leaf slug" — the default,
 *    but not what kbeautybliss.com ran. `App\Support\LegacyCategoryUrls` says in
 *    as many words that it "served its category archives at the site root —
 *    /toners/, /sunscreens/, /cleansing-oils/", lists fifteen of them off the
 *    live navigation, and `2026_09_14_160000_seed_phase9_post_url_redirects`
 *    records the owner confirming the same root-flat shape for articles. Those
 *    root addresses 404 today — measured — and nothing proposed a redirect for
 *    a single one of them. `fromLegacyRootCategories()` below does.
 *
 * What did NOT change: the prefix rule, the self-redirect rule, the collision
 * handling and the chain collapsing are all still right, and the class is worth
 * more with its two bad premises annotated than it would be rewritten clean.
 *
 * Phase 13 asks for this beside the row import and it is a genuinely different
 * job, which is why it is not an entity in `ImportRunner`. The entities read a
 * CSV and write rows. This reads the ROWS THAT WERE JUST IMPORTED and works out
 * which addresses Google already has that would now 404.
 *
 * WHY THIS IS NOT HYPOTHETICAL. Fourteen category links in the menu carried
 * WooCommerce-era flat URLs and 404'd until two packages ago. Those were the
 * ones somebody noticed because they were in the menu. Every other flat
 * category URL in Google's index is the same bug with nobody looking at it.
 *
 * THE ONE RULE THE DATA ACTUALLY PROVES is the category one. WooCommerce sites
 * commonly publish a category at its leaf slug — `/product-category/serums/` —
 * while this shop's URL contract U-03 is the full nested path,
 * `/product-category/skincare/treatments/serums/`. `Category::buildPath()` is
 * where that nesting comes from and `CategoryImporter::recomputeTree()` is what
 * fills it in, so after an import the two forms are both derivable and the
 * difference between them is a redirect nobody has written.
 *
 * WHAT THIS DELIBERATELY DOES NOT GUESS:
 *
 *  - BRAND ARCHIVES. U-05 says this shop has no brand archive path at all —
 *    brands are a query parameter on /shop/. What the OLD shop used depends on
 *    which brand plugin it ran (`/brand/`, `/product-brand/`, `/marca/` …) and
 *    inventing one writes 93 redirects from an address that may never have
 *    existed. Asked once, as a question the owner can answer, rather than
 *    guessed 93 times.
 *
 *  - QUERY-STRING PERMALINKS. `/?p=123` and `/?post_type=product&p=123` are
 *    real WordPress addresses and this shop CANNOT redirect them:
 *    `CheckRedirects::findMatch()` matches `source` against
 *    `$request->getPathInfo()`, which excludes the query string entirely. A row
 *    stored for `/?p=123` would never match. Reported as unreachable rather
 *    than written and quietly ineffective.
 *
 *  - PRODUCTS, unless a permalink file says otherwise. WooCommerce's default
 *    product base and this shop's U-01 are both `/product/{slug}/`, and
 *    SlugGuard never rewrites a slug — it adopts or refuses — so an imported
 *    product's address is byte-for-byte the one it had. That is a FINDING and
 *    is reported with its count, because "we checked 671 products and none of
 *    them moved" is the answer, and silence is not.
 *
 * THE PREFIX TRAP, which is the thing most likely to be silently wrong here.
 * `redirects.source` is compared against `getPathInfo()`, which EXCLUDES the
 * `KBB_BASE_PATH` the site is served under, and the one shipped seed
 * (2026_09_14_160000_seed_phase9_post_url_redirects) stores its targets the
 * same prefix-free way. `Category::url()` and `Product::url()` go through
 * `Url::to()`, which ADDS that prefix. So building a redirect out of the
 * models' own url() methods bakes `/kbb-upgrade` into every row and every one
 * of them breaks the day the site moves to the domain root. Every path in here
 * is assembled from raw strings for that reason; `RedirectMapTest` pins it.
 */
final class RedirectMap
{
    /** Decisions, in the three buckets Phase 13 asks every row to land in. */
    public const MIGRATE = 'migrate';

    public const DISCARD = 'discard';

    public const ASK = 'ask';

    /*
     * ═══════════════════════════════════════════════════════════════════════
     * WHY EVERY ASK ROW NOW CARRIES A CODE AS WELL AS A SENTENCE
     * ═══════════════════════════════════════════════════════════════════════
     *
     * The ask bucket is the owner's work list and it got much bigger: three
     * branches of reachable() that used to DISCARD or that rested on "the table
     * only fires on a 404" now ask, because CheckRedirects is registered in the
     * global pipeline (docs/GP-ADDRESSES-LAND.md §13.7). On a real export that
     * is most of the category rule — hundreds of rows where there were tens.
     *
     * A list of hundreds of free-text sentences is a list nobody finishes, and
     * the repository already says so in as many words: docs/FV-IMPORT-AT-VOLUME
     * §10, and resolve() below, where twenty-seven questions containing no
     * disagreement were collapsed for exactly this reason.
     *
     * But those rows are not hundreds of DIFFERENT questions. They are a
     * handful of questions asked hundreds of times, and the owner's answer to
     * "this address still answers on the shop — do you want the old URL to win"
     * is the same answer for every category in the list. So the question gets a
     * CODE, the code gets one sentence in QUESTIONS, and the screen can offer
     * "accept all 312" instead of 312 checkboxes.
     *
     * THE CODE IS NOT THE REASON. `reason` stays per row and keeps naming the
     * specific address, destination and rule — losing that would be trading a
     * list nobody finishes for a list nobody can check. The code is what makes
     * the list SORTABLE; the reason is what makes one row ANSWERABLE.
     */

    /** The shop already 301s this address somewhere of its own accord. */
    public const Q_ALREADY_REDIRECTS = 'already-redirects';

    /** A real page answers here today. */
    public const Q_STILL_ANSWERS = 'still-answers';

    /** A parameterised route claims it and this cannot say what it will find. */
    public const Q_CANNOT_TELL = 'cannot-tell';

    /** The destination itself 404s. */
    public const Q_TARGET_MISSING = 'target-missing';

    /** Two rules claim one old address and send it to two different places. */
    public const Q_TWO_RULES_DISAGREE = 'two-rules-disagree';

    /** Following the chain comes back to where it started. */
    public const Q_LOOP = 'loop';

    /** This shop has no address to send the old one to. */
    public const Q_NO_TARGET = 'no-target';

    /** Nothing in this shop carries the id the permalink export names. */
    public const Q_NOT_IMPORTED = 'not-imported';

    /** CheckRedirects could never match it, whatever row were written. */
    public const Q_UNMATCHABLE = 'unmatchable';

    /**
     * The old site published a KIND of address this shop does not have at all.
     *
     * Not "the row was not imported" — the row may well have been imported.
     * The taxonomy it belongs to has no archive here: this shop has no tag
     * archive and no attribute archive, by design, the way U-05 says it has no
     * brand archive. Told apart from Q_NOT_IMPORTED because the two are a
     * completely different job for the owner, and because saying "it was never
     * imported" about a tag that was imported is a false sentence on the one
     * screen he is asked to make decisions from.
     */
    public const Q_NO_EQUIVALENT = 'no-equivalent';

    /**
     * Every question this map asks, in the order a person should work through
     * them: the ones that are a decision first, the ones that are somebody
     * else's job last.
     *
     * `heading` is what the screen prints above the group. `decidable` says
     * whether "accept" is a thing that can be done at all — see decidable().
     *
     * @var array<string, array{heading: string, decidable: bool}>
     */
    public const QUESTIONS = [
        self::Q_STILL_ANSWERS => [
            'heading' => 'This shop answers this address today. Should the old address win instead?',
            'decidable' => true,
        ],
        self::Q_ALREADY_REDIRECTS => [
            'heading' => 'This shop already sends this address somewhere. Should it go here instead?',
            'decidable' => true,
        ],
        self::Q_TWO_RULES_DISAGREE => [
            'heading' => 'Two rules want to send this old address to different places.',
            'decidable' => true,
        ],
        self::Q_CANNOT_TELL => [
            'heading' => 'Cannot tell what this address does on this shop today.',
            'decidable' => true,
        ],
        self::Q_TARGET_MISSING => [
            'heading' => 'The destination does not exist on this shop, so this would be a 301 to a 404.',
            'decidable' => true,
        ],
        self::Q_LOOP => [
            'heading' => 'Following this redirect leads back to where it started.',
            'decidable' => false,
        ],
        self::Q_NO_TARGET => [
            'heading' => 'This shop has no address to send the old one to.',
            'decidable' => false,
        ],
        self::Q_NOT_IMPORTED => [
            'heading' => 'Nothing in this shop carries what the old address named.',
            'decidable' => false,
        ],
        self::Q_UNMATCHABLE => [
            'heading' => 'This shop could never match this address, whatever row were written.',
            'decidable' => false,
        ],
        /*
         * LAST, like the others that are not a yes/no. `decidable` is false
         * because there is no destination to accept: this shop has nowhere of
         * this kind to send the address. The owner's move is to write a row by
         * hand pointing it at whatever he considers the nearest page, or to let
         * it 404 — and both are outside what this map may guess.
         */
        self::Q_NO_EQUIVALENT => [
            'heading' => 'The old site published a kind of address this shop does not have.',
            'decidable' => false,
        ],
    ];

    /**
     * Can this proposal be turned into a redirect by saying yes to it?
     *
     * TWO REFUSALS, and both are the difference between a question and a
     * button that writes a row nobody can use:
     *
     *   - NO DESTINATION. `target` is '' on the three questions that are really
     *     "fix something else first" — a category stranded in a parent cycle, a
     *     permalink for a row that was never imported, an address whose whole
     *     identity is in its query string. Accepting one would write
     *     `redirects.target = ''`, which sends a visitor to nowhere.
     *
     *   - A LOOP. It has a destination and the destination leads back here.
     *     `CheckRedirects::loops()` refuses to FOLLOW one at read time, which
     *     is a guard and not a licence to write one: a row that is refused on
     *     every request is a row that silently does nothing, and this map does
     *     not offer to write those.
     *
     * The check is made HERE and again in the endpoint that records a decision,
     * because a screen deciding what may be approved is a screen, and the rule
     * has to hold for anything that calls the endpoint.
     *
     * @param  array{target?: string, question?: string}  $proposal
     */
    public static function decidable(array $proposal): bool
    {
        $question = (string) ($proposal['question'] ?? '');

        if (! (self::QUESTIONS[$question]['decidable'] ?? false)) {
            return false;
        }

        return trim((string) ($proposal['target'] ?? '')) !== '';
    }

    /**
     * Injectable so a caller can hand in the two collaborators rather than have
     * them constructed here.
     *
     * ▲ THE NOTE THIS REPLACES SAID "injectable only so a test can pin the
     * reachability verdicts it depends on without standing up the route it is
     * describing", AND THAT IS NOT POSSIBLE. `SourceReachability` is `final`,
     * so the only thing that can be passed for it is another real instance —
     * a test reaching for the seam gets `cannot extend final class`. Found by
     * reaching for it.
     *
     * Left `final` deliberately rather than opened up for a test's
     * convenience: every verdict it gives is about the real router and the real
     * tables, and a stub is exactly the thing that would let this map be
     * asserted against a shop that does not exist. The disagreement cases in
     * `tests/Feature/SeoImportSensesUrlsTest.php` are built out of a
     * `category_redirects` row instead, which is a situation a real merge
     * produces and is better evidence than a stub would have been.
     */
    public function __construct(
        private ?SourceReachability $reachability = null,
        private ?RedirectDecisions $decisions = null,
    ) {
        $this->reachability ??= new SourceReachability;
        $this->decisions ??= new RedirectDecisions;
    }

    /**
     * Work out the whole map without writing any of it.
     *
     * @param  iterable<int, array<string, string>>  $permalinks  rows from an optional
     *          permalink export: type + wc_id + the address the old site published.
     * @return list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>
     */
    public function propose(iterable $permalinks = []): array
    {
        $proposals = [];

        foreach ($this->fromCategoryNesting() as $proposal) {
            $proposals[] = $proposal;
        }

        foreach ($this->fromLegacyRootCategories() as $proposal) {
            $proposals[] = $proposal;
        }

        foreach ($this->fromPermalinks($permalinks) as $proposal) {
            $proposals[] = $proposal;
        }

        return $this->answered($this->reachable($this->resolve($proposals)));
    }

    /**
     * Normalise every row, then let the owner's recorded answers move it.
     *
     * =========================================================================
     * WHY THE ANSWERS ARE APPLIED HERE AND NOT BY EACH CALLER
     * =========================================================================
     *
     * Four things read this map: the screen, the CSV the owner approves from,
     * the write that creates the rows, and `MigrationProgress`, which is what
     * the dashboard counts. A layer applied by some of them and not others
     * would mean the screen showing an approved row in `migrate` while the
     * dashboard still counted it as a question — two answers to "how much is
     * left", which is the number the whole migration is judged by.
     *
     * =========================================================================
     * AND WHY AFTER reachable() RATHER THAN BEFORE
     * =========================================================================
     *
     * `reachable()` is what ASKS. Running the answers first would let it demote
     * an approved row straight back to a question on the very grounds the owner
     * has just overruled — "this address still answers on this shop" is the
     * question, and "yes, redirect it anyway" is the answer to it.
     *
     * The cost of being last is that an approved row does not go through
     * resolve()'s chain collapsing, so an approval whose destination is itself
     * another row's source is written as TWO hops rather than one. That is
     * honest rather than ideal: it is byte for byte the redirect he was shown
     * and said yes to, `CheckRedirects` follows an honest chain and refuses
     * only cycles (docs/GP-ADDRESSES-LAND.md §13.5), and collapsing it would
     * write a destination that was on no screen he ever read.
     *
     * @param  list<array<string, mixed>>  $proposals
     * @return list<array<string, mixed>>
     */
    private function answered(array $proposals): array
    {
        foreach ($proposals as $index => $proposal) {
            // Every row carries the key, so a reader never has to ask whether
            // the absence of a question means "no question" or "old shape".
            $proposals[$index] += ['question' => ''];
        }

        return $this->decisions->apply($proposals);
    }

    /**
     * The address a kbeautybliss.com category archive was REALLY published at:
     * flat at the site root, `/toners/`, with no base of any kind.
     *
     * =========================================================================
     * WHY THIS RULE EXISTS AND fromCategoryNesting() BELOW DOES NOT COVER IT
     * =========================================================================
     *
     * That rule is built on "WooCommerce commonly publishes a category at its
     * leaf slug, `/product-category/serums/`". That is the WooCommerce DEFAULT.
     * It is not what this shop ran, and the repository says so in three places
     * written by people who had looked at the live site:
     *
     *   - `App\Support\LegacyCategoryUrls`: "kbeautybliss.com served its
     *     category archives at the site root — /toners/, /sunscreens/,
     *     /cleansing-oils/ — because that is what its WooCommerce permalink
     *     settings produced", with fifteen exact addresses listed.
     *
     *   - `2026_09_09_040000_seed_kbeautybliss_menu` and
     *     `..._070000_fix_kbeautybliss_menu_structure` seed the LIVE site's own
     *     navigation, and every category row in it is a flat root path.
     *
     *   - `2026_09_14_160000_seed_phase9_post_url_redirects`: "The owner
     *     confirmed that blog posts live at the site root, one slug per post,
     *     with no prefix." One permalink setting, one shape, and the articles
     *     half of it is confirmed by the owner himself.
     *
     * So `/product-category/serums/` is an address the old site most likely
     * never served, and `/serums/` is one it did. A map that writes the first
     * and not the second redirects nothing Google actually holds.
     *
     * =========================================================================
     * WHAT IS EVIDENCE HERE AND WHAT IS INFERENCE, kept apart on the row
     * =========================================================================
     *
     * CORROBORATED: the fifteen paths in `LegacyCategoryUrls::PATHS` were
     * copied off the live navigation. For those the shape is not a guess.
     *
     * INFERRED: every other imported category. WooCommerce has ONE product
     * category base for the whole site, so a shop that served `/toners/` served
     * `/serums/` too — but the inference is written on the row rather than
     * hidden, and the command counts the two separately.
     *
     * The asymmetry is what makes writing the inferred ones the right call.
     * These addresses 404 today, per row, proved by `reachable()` and not
     * assumed. A redirect written for an address the old site never served gets
     * no traffic and costs one row; an address the old site DID serve and that
     * carries no redirect loses every visitor and every link still pointing at
     * it. This takes the cheap error.
     *
     * NOT EXTENDED BEYOND CATEGORIES. A product's old address cannot be derived
     * from anything in this repository — see `docs/GB-MEDIA-AND-REDIRECTS.md`,
     * where it is the question put to the owner — and this rule does not guess
     * at one.
     *
     * @return list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>
     */
    private function fromLegacyRootCategories(): array
    {
        $out = [];

        $categories = Category::query()
            ->select(['id', 'slug', 'path'])
            ->orderBy('id')
            ->get();

        foreach ($categories as $category) {
            $slug = (string) $category->slug;
            $path = (string) ($category->path ?? '');

            if ($slug === '' || $path === '') {
                // A slugless row has no old address, and a pathless one is the
                // parent cycle fromCategoryNesting() already asks about. One
                // question about it is enough.
                continue;
            }

            $source = '/'.$slug.'/';

            $reason = LegacyCategoryUrls::isLegacy($source)
                ? 'kbeautybliss.com published its category archives flat at the site root, and this exact '
                    .'address is one of the fifteen in LegacyCategoryUrls::PATHS, copied off the live navigation'
                : 'kbeautybliss.com published its category archives flat at the site root '
                    .'(App\Support\LegacyCategoryUrls); WooCommerce has one category base for the whole site, '
                    .'so this address is inferred from that setting rather than corroborated row by row';

            /*
             * BOTH SLASH FORMS, and this is not belt and braces.
             *
             * `CheckRedirects::findMatch()` compares `source` against
             * `getPathInfo()` with a plain equality, and `getPathInfo()` keeps
             * exactly the spelling the client sent. U-01 says this site's URLs
             * carry a trailing slash and that is the form that was indexed —
             * but a link somebody pasted into Instagram without one arrives as
             * `/toners`, matches no row, and 404s.
             *
             * This is the same call `2026_09_14_160000_seed_phase9_post_url_redirects`
             * made for the five confirmed articles, in the same words: "one
             * extra row per article is a much smaller price than a 404 on a URL
             * somebody actually posted." Following the precedent rather than
             * inventing a second policy for the same table.
             */
            foreach ([$source, rtrim($source, '/')] as $spelling) {
                $out[] = [
                    'source' => $spelling,
                    'target' => $this->categoryPath($path),
                    'rule' => 'legacy-root-category',
                    'decision' => self::MIGRATE,
                    'reason' => $reason.($spelling === $source
                        ? ''
                        : '; this is the same address without its trailing slash, which getPathInfo() reports '
                            .'verbatim and the table matches exactly'),
                    'subject' => 'category '.$category->id.' ('.$path.')',
                ];
            }
        }

        return $out;
    }

    /**
     * Ask about every proposal this shop already answers, and say why.
     *
     * =========================================================================
     * THE REDIRECT TABLE IS READ BEFORE THE ROUTER, NOT ONLY ON A 404
     * =========================================================================
     *
     * This heading used to read "THE REDIRECT TABLE IS ONLY READ ON A 404" and
     * the paragraph under it described `CheckRedirects` as written-but-not-
     * registered, with the 404 closure in `AppServiceProvider` as the only live
     * reader. That was true, and every verdict below was built on it.
     *
     * IT IS NOT TRUE NOW. `CheckRedirects` is registered in the global pipeline
     * and runs BEFORE the router, so a row for an address this shop answers
     * fires. Measured by the lane that registered it: `/shop/` and
     * `/product/tx-serum/` went 200 → 301. `docs/GP-ADDRESSES-LAND.md` §13 is
     * the transcript, and §13.7 is the list of what that broke in this file.
     *
     * The consequence for this method is the whole of it: a verdict here is no
     * longer "can a row fire" — a row can always fire — it is **what a row
     * would DO to the address**, which is a question with an owner.
     *
     * THREE OUTCOMES, AND ALL THREE ARE NOW QUESTIONS:
     *
     *  - MOVED. The shop already 301s this address on its own, from inside the
     *    controller. ASK. This was DISCARD, and that is the dangerous one to
     *    have left alone: it threw the proposal away silently, on the grounds
     *    that a row could never be read, and a discard is not on the list the
     *    owner approves. A row here now OVERRIDES the shop's own hop — worth
     *    writing if the shop sends it to the wrong place, worth leaving alone
     *    if it does not, and either way not this map's call.
     *
     *  - SERVED. A real page answers here. ASK, as before, but for the opposite
     *    reason: it used to be "a row cannot move this", and it is now "a row
     *    WILL move this" — the page that answers today would 301 away instead.
     *
     *  - UNKNOWN. A parameterised route claims it and this cannot say what its
     *    controller will find. ASK, stated as such rather than rounded off.
     *
     * Only a source nothing answers stays MIGRATE.
     *
     * =========================================================================
     * THE TARGET IS CHECKED ON EVERY PROPOSAL, NOT ONLY THE ONES THAT MIGRATE
     * =========================================================================
     *
     * A redirect whose destination 404s moves a visitor from one not-found page
     * to another and tells a search engine the address was replaced by nothing.
     * That check used to run only after the three branches above had fallen
     * through — so a proposal routed to ASK never had its destination checked
     * at all.
     *
     * That was survivable while an ASK row was a dead end. It is not now:
     * `RedirectDecisions` lets the owner APPROVE one, and "yes to all 312" over
     * a question could have written a row pointing at a 404 with nothing on the
     * screen saying so. So the destination is checked FIRST, and a dead one
     * wins the question whatever the source's verdict is — because it is the
     * reason NOT to approve, and it has to be the heading he reads rather than
     * a sentence buried under a different one.
     *
     * A proposal with no destination at all is skipped: the three questions
     * that have none already say so, and `SourceReachability` answers UNKNOWN
     * for an empty path rather than NOT_FOUND, so checking would mislabel them.
     *
     * @param  list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>  $proposals
     * @return list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>
     */
    private function reachable(array $proposals): array
    {
        foreach ($proposals as $index => $proposal) {
            if ($proposal['decision'] !== self::MIGRATE) {
                continue;
            }

            /*
             * THE DESTINATION FIRST. See the docblock: an ASK row is approvable
             * now, so "this would be a 301 to a 404" has to be the question the
             * owner is asked rather than a check three branches never reached.
             */
            if (trim($proposal['target']) !== '') {
                $target = $this->reachability->verdict($proposal['target']);

                if ($target['status'] === SourceReachability::NOT_FOUND) {
                    $proposals[$index]['decision'] = self::ASK;
                    $proposals[$index]['question'] = self::Q_TARGET_MISSING;
                    $proposals[$index]['reason'] = 'the destination "'.$proposal['target'].'" does not exist on '
                        .'this shop — '.$target['why'].'. A 301 to a 404 is worse than the 404 it replaces: it '
                        .'tells a search engine the address was replaced by nothing';

                    continue;
                }
            }

            $verdict = $this->reachability->verdict($proposal['source']);

            /*
             * ── THE PREMISE UNDER THESE THREE BRANCHES CHANGED ─────────────
             *
             * All three used to end "because the table is only consulted from
             * the 404 handler". That was true, and it is not any more:
             * CheckRedirects is registered in the global pipeline and now runs
             * BEFORE the router, so a row for an address this shop answers
             * fires. Measured by the lane that registered it: /shop/ and
             * /product/tx-serum/ went 200 → 301.
             *
             * WHICH MAKES THE FIRST BRANCH THE DANGEROUS ONE. It DISCARDED —
             * threw the proposal away silently, on the grounds that a row could
             * never be read. Left as it was, the import would drop exactly the
             * redirects the registration exists to enable, and the owner would
             * never see them: a discard is not on the list he approves, because
             * the whole point of the discard bucket is "there was nothing here
             * worth deciding".
             *
             * So MOVED becomes ASK, and the other two keep ASK and lose a
             * reason that is no longer true. Nothing here is decided for him:
             * redirecting an address the shop serves is a real choice with a
             * real cost, and the three-bucket rule in Phase 13 says who makes
             * it.
             */
            if ($verdict['status'] === SourceReachability::MOVED) {
                /*
                 * ═══════════════════════════════════════════════════════════
                 * WHERE IT ALREADY GOES, COMPARED WITH WHERE THIS WOULD SEND
                 * IT — AND THIS IS THE §13.7 DECISION, SETTLED
                 * ═══════════════════════════════════════════════════════════
                 *
                 * docs/GP-ADDRESSES-LAND.md §13.7 handed this to a later lane
                 * as "the one that needs a decision, not just an edit: some of
                 * those discards are still right, and some are now wrong."
                 * Neither blanket answer is right, and the reason is that
                 * MOVED is two different situations wearing one word:
                 *
                 *   THE SHOP ALREADY SENDS IT EXACTLY HERE. There is nothing
                 *   to decide. A row would restate, in the table, a 301 the
                 *   application already makes for itself — and restating it is
                 *   not free, which is the part that matters: the shop's own
                 *   answer is DERIVED, so it follows the category when the
                 *   owner re-parents or renames it. A written row does not. It
                 *   goes on pointing at the path the category had on import
                 *   day, and that path is then a 404. So the row is not merely
                 *   redundant, it is the only one of the two that can rot.
                 *
                 *   THE SHOP SENDS IT SOMEWHERE ELSE. That IS a decision, and
                 *   it is the owner's: a row here overrides the application's
                 *   own hop, because the table is consulted before the router.
                 *
                 * MEASURED, on a tree with all fifteen categories imported and
                 * before this branch: 38 proposals in `migrate`, of which 30
                 * were the first case — every legacy root address and every
                 * nesting rule whose destination the shop already produces.
                 * Writing them was not wrong on the day, and every one of them
                 * was a row that could later disagree with the shop that wrote
                 * it.
                 *
                 * Compared as RAW PATHS. `$verdict['to']` is prefix-free and
                 * locale-free by construction, for the reason the class
                 * comment gives about `Category::url()` baking `/kbb-upgrade`
                 * into a row.
                 */
                $already = trim((string) ($verdict['to'] ?? ''));

                if ($already !== '' && $already === $proposal['target']) {
                    $proposals[$index]['decision'] = self::DISCARD;
                    $proposals[$index]['reason'] = 'this shop already sends this address to exactly this '
                        .'destination without being told to — '.$verdict['why'].'. A row would restate it, and '
                        .'unlike the shop\'s own answer a row does not follow the category if it is renamed or '
                        .'re-parented: it would go on pointing at today\'s path after that path had become a 404';

                    continue;
                }

                $proposals[$index]['decision'] = self::ASK;
                $proposals[$index]['question'] = self::Q_ALREADY_REDIRECTS;
                $proposals[$index]['reason'] = 'this address already redirects somewhere on this shop, and NOT to '
                    .'where this rule would send it — '.$verdict['why'].'. A row here would OVERRIDE that hop, '
                    .'because the redirects table is consulted before the router. Worth writing if the shop sends '
                    .'it to the wrong place, and worth leaving alone if it does not';

                continue;
            }

            if ($verdict['status'] === SourceReachability::SERVED) {
                $proposals[$index]['decision'] = self::ASK;
                $proposals[$index]['question'] = self::Q_STILL_ANSWERS;
                $proposals[$index]['reason'] = 'this address still answers on this shop — '.$verdict['why']
                    .'. A row here WILL move it: the redirects table is consulted before the router, so the page '
                    .'that answers today would 301 away instead. That is a real change to a working page';

                continue;
            }

            if ($verdict['status'] === SourceReachability::UNKNOWN) {
                $proposals[$index]['decision'] = self::ASK;
                $proposals[$index]['question'] = self::Q_CANNOT_TELL;
                $proposals[$index]['reason'] = 'cannot tell what this address does today, and a row here is now '
                    .'read whether it 404s or not — '.$verdict['why'];

                continue;
            }
        }

        return $proposals;
    }

    /**
     * The flat leaf address a WooCommerce category was commonly published at,
     * pointed at the nested one this shop serves.
     *
     * @return list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>
     */
    private function fromCategoryNesting(): array
    {
        $out = [];

        /*
         * WHY THERE IS NO AMBIGUITY CHECK HERE, which is worth stating because
         * the obvious worry is real-sounding and the guard for it would be dead
         * code.
         *
         * The flat address is built from the leaf slug alone, so the question
         * is whether two categories can end in the same slug and both claim
         * `/product-category/serums/`. They cannot: `categories.slug` is
         * `string('slug')->unique()` in the Phase 0 schema, so one slug belongs
         * to exactly one row, and a source built from it is unique by
         * construction. A `$leafCounts[$slug] > 1` branch was written here
         * first, and the database refuses to produce the row that would reach
         * it — a filter that matches nothing, which this repository has already
         * paid for once in `Api\ProductController`. `ImportUrlAndMediaTest`
         * pins the invariant instead: every source in the migrate bucket is
         * distinct.
         *
         * Two DIFFERENT RULES can still collide on one source — a permalink
         * export naming an address this rule also derived — and that is handled
         * in resolve(), where it can actually happen.
         */
        $categories = Category::query()
            ->select(['id', 'slug', 'path', 'depth'])
            ->orderBy('id')
            ->get();

        foreach ($categories as $category) {
            $slug = (string) $category->slug;
            $path = (string) ($category->path ?? '');

            if ($slug === '') {
                continue;
            }

            if ($path === '') {
                /*
                 * No computed path. `CategoryImporter::recomputeTree()` leaves
                 * exactly one kind of row like this: one stranded in a parent
                 * cycle. Its real address is unknown, so it is a question and
                 * not a redirect.
                 */
                $out[] = [
                    'source' => $this->categoryPath($slug),
                    'target' => '',
                    'rule' => 'category-nesting',
                    'decision' => self::ASK,
                    'question' => self::Q_NO_TARGET,
                    'reason' => 'this category has no computed path, so this shop has no address to send the old '
                        .'one to — it is the parent cycle CategoryImporter reports; fix the parent and re-run',
                    'subject' => 'category '.$category->id.' ('.$slug.')',
                ];

                continue;
            }

            $source = $this->categoryPath($slug);
            $target = $this->categoryPath($path);

            if ($source === $target) {
                /*
                 * A top-level category: its flat address and its nested address
                 * are the same string. Writing this would be a redirect from a
                 * page to itself, which CheckRedirects would serve as an
                 * infinite loop. Discarded, and counted, so the report can say
                 * how many categories needed nothing.
                 */
                $out[] = [
                    'source' => $source,
                    'target' => $target,
                    'rule' => 'category-nesting',
                    'decision' => self::DISCARD,
                    'reason' => 'top-level category — its old address and its new one are the same, so a redirect '
                        .'would point at itself',
                    'subject' => 'category '.$category->id.' ('.$slug.')',
                ];

                continue;
            }

            $out[] = [
                'source' => $source,
                'target' => $target,
                'rule' => 'category-nesting',
                'decision' => self::MIGRATE,
                'reason' => 'WooCommerce published this category at its leaf slug; this shop serves it at its full '
                    .'nested path (U-03)',
                'subject' => 'category '.$category->id.' ('.$path.')',
            ];
        }

        return $out;
    }

    /**
     * Addresses the old site really published, read from an export rather than
     * derived — the only source that can be right about a site whose permalink
     * settings nobody now remembers.
     *
     * @param  iterable<int, array<string, string>>  $rows
     * @return list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>
     */
    private function fromPermalinks(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            $rawUrl = trim((string) ($row['permalink'] ?? $row['url'] ?? $row['old_url'] ?? ''));
            $wcId = (int) trim((string) ($row['wc_id'] ?? $row['id'] ?? ''));

            if ($rawUrl === '' || $wcId <= 0) {
                continue;
            }

            $subject = $type.' '.$wcId;

            /*
             * Only the path survives. A permalink carries scheme, host and
             * possibly a query string; `getPathInfo()` is only ever the path,
             * so anything else in the source would make the row unmatchable.
             */
            $source = (string) (parse_url($rawUrl, PHP_URL_PATH) ?: '');
            $query = (string) (parse_url($rawUrl, PHP_URL_QUERY) ?: '');

            if ($source === '' || $source === '/') {
                $out[] = [
                    'source' => $rawUrl,
                    'target' => '',
                    'rule' => 'permalink',
                    'decision' => self::ASK,
                    'question' => self::Q_UNMATCHABLE,
                    'reason' => 'this address carries no path of its own'
                        .($query === '' ? '' : ' — it identifies the page in its query string ("?'.$query.'"), '
                            .'which CheckRedirects cannot match because it compares against getPathInfo()')
                        .', so this shop cannot redirect it',
                    'subject' => $subject,
                ];

                continue;
            }

            $target = $this->currentPathFor($type, $wcId);

            if ($target === null) {
                /*
                 * ═══════════════════════════════════════════════════════════
                 * TWO DIFFERENT ANSWERS WERE WEARING ONE SENTENCE
                 * ═══════════════════════════════════════════════════════════
                 *
                 * `currentPathFor()` answers for products, categories, posts,
                 * pages and brands. It returns null for everything else, and
                 * everything else got "nothing in this shop carries {type} id
                 * {id} — either it was never imported, or it is in the discard
                 * bucket and this address should 404 on purpose."
                 *
                 * For a PRODUCT that is true and useful. For a `product_tag` or
                 * a `pa_*` attribute term it is FALSE, and falsely in the
                 * direction that costs the owner work: the term very probably
                 * WAS imported. What this shop does not have is a tag archive
                 * or an attribute archive — no route, by design, exactly as
                 * U-05 says of brands. No amount of importing will produce one.
                 *
                 * AND IT IS A VOLUME PROBLEM, which is what makes it worth a
                 * question of its own rather than a better sentence. The
                 * exporter writes one permalinks row per term of every `pa_*`
                 * taxonomy plus every `product_tag`; on a six-year-old shop
                 * that is hundreds. docs/FV-IMPORT-AT-VOLUME.md §10 is explicit
                 * that a question list which is mostly noise is a question list
                 * nobody finishes, and hundreds of rows each giving a false
                 * reason is the purest form of that.
                 *
                 * WHAT THE EXPORT ALREADY SETTLES, so that none of this is a
                 * guess about the old install: a taxonomy with no public
                 * archive produces an EMPTY `permalink` and a note saying
                 * WordPress returned no archive URL for it — and an empty
                 * permalink never reaches this method, because the loop above
                 * skips it. So a row arriving here with a real URL is the old
                 * site stating that it DID serve this address. The question is
                 * then genuine, and it is the only one left: this shop has
                 * nowhere of that kind to send it.
                 */
                $out[] = [
                    'source' => $source,
                    'target' => '',
                    'rule' => 'permalink',
                    'decision' => self::ASK,
                    'question' => self::hasNoArchiveHere($type) ? self::Q_NO_EQUIVALENT : self::Q_NOT_IMPORTED,
                    'reason' => self::hasNoArchiveHere($type)
                        ? 'the old site served a '.$type.' archive at this address, and this shop has no '
                            .$type.' archive at all — there is no route for one, the same way U-05 says there is '
                            .'no brand archive. This is not a row that failed to import: it is an address with no '
                            .'equivalent here. Point it somewhere by hand on Store → SEO & Meta → Redirects & '
                            .'404s, or let it 404'
                        : 'nothing in this shop carries '.($type === '' ? 'that' : $type).' id '.$wcId
                            .' — either it was never imported, or it is in the discard bucket and this address '
                            .'should 404 on purpose',
                    'subject' => $subject,
                ];

                continue;
            }

            if ($source === $target) {
                $out[] = [
                    'source' => $source,
                    'target' => $target,
                    'rule' => 'permalink',
                    'decision' => self::DISCARD,
                    'reason' => 'the old address and the new one are identical, so there is nothing to redirect',
                    'subject' => $subject,
                ];

                continue;
            }

            $note = $query === ''
                ? 'the old site published this at a different address'
                : 'the old site published this at a different address; its query string ("?'.$query.'") is '
                    .'dropped, because CheckRedirects matches on the path alone';

            $out[] = [
                'source' => $source,
                'target' => $target,
                'rule' => 'permalink',
                'decision' => self::MIGRATE,
                'reason' => $note,
                'subject' => $subject,
            ];
        }

        return $out;
    }

    /**
     * Where this shop serves an imported thing today, as a raw prefix-free
     * path. See the class comment on why this does not call the models' url().
     */
    private function currentPathFor(string $type, int $wcId): ?string
    {
        if (in_array($type, ['product', 'products', 'simple', 'variable'], true)) {
            $product = Product::query()->where('wc_id', $wcId)->first(['slug']);

            return $product === null ? null : '/product/'.$product->slug.'/';
        }

        if (in_array($type, ['category', 'categories', 'product_cat'], true)) {
            $category = Category::query()->where('source_term_id', $wcId)->first(['slug', 'path']);

            if ($category === null) {
                return null;
            }

            $path = (string) ($category->path ?? '');

            return $this->categoryPath($path === '' ? (string) $category->slug : $path);
        }

        /*
         * =====================================================================
         * ARTICLES AND PAGES, WHICH THIS DID NOT ANSWER AND HAD TO
         * =====================================================================
         *
         * Until permalinks.csv could reach this class there was nothing to
         * notice: the only caller was `kbb:import-redirects --permalinks=…`,
         * a command the owner cannot run. The first time a real permalink file
         * was fed through the import screen, EVERY post and page row in it
         * landed in the ASK bucket saying "nothing in this shop carries post id
         * 501 — either it was never imported, or it is in the discard bucket".
         * Measured: all thirteen of them, about addresses this shop was already
         * answering 200 on.
         *
         * Two separate costs, and the second is the one that matters:
         *
         *   THE SENTENCE WAS FALSE. The post was imported and the address does
         *   resolve. A row whose reason is wrong is worse than a row missing.
         *
         *   THE ASK BUCKET IS THE OWNER'S WORK LIST. Phase 13 has him approve
         *   it by hand. On the real export permalinks.csv carries one row per
         *   post and one per page — the blog is the largest post type after
         *   products — so his list of decisions would have been mostly rows
         *   needing no decision, which docs/FV-IMPORT-AT-VOLUME.md §10 is
         *   explicit is a list nobody finishes.
         *
         * Both tables key on `source_post_id`, which is what PostImporter and
         * the page import match on. The address is the SITE ROOT with no
         * prefix, which is not an inference: 2026_09_14_160000_seed_phase9_post_
         * url_redirects records the owner confirming it for articles, and the
         * seven WordPress pages are literal routes at the root already.
         *
         * Nearly all of these then land in DISCARD — old address and new
         * address identical — which is the correct answer and the one the shop
         * could not previously give. The ones that do NOT are the rows worth
         * his attention: a post whose slug changed on import (SlugGuard
         * refusing a collision with a reserved slug is the real case), which is
         * exactly a redirect that needs writing.
         */
        if (in_array($type, ['post', 'posts'], true)) {
            $post = Post::query()->where('source_post_id', $wcId)->first(['slug']);

            return $post === null ? null : '/'.$post->slug.'/';
        }

        if (in_array($type, ['page', 'pages'], true)) {
            $page = Page::query()->where('source_post_id', $wcId)->first(['slug']);

            return $page === null ? null : '/'.$page->slug.'/';
        }

        if (in_array($type, ['brand', 'brands', 'product_brand', 'pa_brands'], true)) {
            $brand = Brand::query()->where('source_term_id', $wcId)->first(['slug']);

            /*
             * U-05: no brand archive path exists to send them to. The shop page
             * filtered by the brand is the closest real address, and it is a
             * query parameter, which is fine in a TARGET — only the source has
             * to be a bare path.
             */
            return $brand === null ? null : '/shop/?filter_brands='.$brand->slug;
        }

        return null;
    }

    /**
     * Is this a taxonomy the OLD site could publish an archive for and this one
     * cannot?
     *
     * The spellings are the exporter's own labels (`class-kbb-export-stage-
     * permalinks.php` — `product_tag`, `post_tag_blog`, `attribute`,
     * `post_category`) plus the raw taxonomy names, because a permalinks file
     * hand-made from another export is a shape this has to survive.
     *
     * Brands are NOT in this list and that is deliberate: `currentPathFor()`
     * answers for a brand with `/shop/?filter_brands=…`, which is a real
     * destination, so a brand row never reaches the branch this feeds.
     */
    private static function hasNoArchiveHere(string $type): bool
    {
        return in_array($type, [
            'product_tag', 'product_tags', 'tag', 'tags', 'post_tag', 'post_tag_blog',
            'attribute', 'attributes', 'post_category',
        ], true) || str_starts_with($type, 'pa_');
    }

    /** `/product-category/{path}/` — U-03, trailing slash and all. */
    private function categoryPath(string $path): string
    {
        return '/product-category/'.trim($path, '/').'/';
    }

    /**
     * Last pass over the whole set, for the problems that only exist between
     * proposals rather than inside one.
     *
     * @param  list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>  $proposals
     * @return list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>
     */
    private function resolve(array $proposals): array
    {
        /*
         * Two proposals claiming one source. The permalink file wins over the
         * derived rule, because it is a record of what the site really served
         * and the derived rule is an inference about what it probably served.
         * Both are kept in the output — the loser as a question — because an
         * importer that silently drops one of two conflicting instructions is
         * the thing this whole file exists to avoid.
         */
        $bySource = [];

        foreach ($proposals as $index => $proposal) {
            if ($proposal['decision'] !== self::MIGRATE) {
                continue;
            }

            $bySource[$proposal['source']][] = $index;
        }

        foreach ($bySource as $source => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            /*
             * TWO RULES THAT AGREE ARE NOT A CONFLICT, and this is the case
             * that actually happens rather than the one the code was written
             * for.
             *
             * Both rules here describe the SAME category from two directions.
             * fromCategoryNesting() derives "/product-category/serums/ ->
             * /product-category/skincare/serums/" from the tree that was just
             * imported; fromPermalinks() reads the same move out of the old
             * site's own permalink export. On a nested category that the
             * permalink file also covers -- which, on a real export, is every
             * nested category -- both fire, with byte-identical source AND
             * byte-identical target, and the loser was demoted to ASK with the
             * reason "another rule claims the same old address and points it
             * somewhere else, at X" where X is the address it also points to.
             *
             * Measured on a 671-product, 29-category fixture: 27 of the 52
             * non-discard rows were questions containing no disagreement. The
             * owner has to read every one to discover that, and the ones that
             * are real disagreements are mixed in among them. A question list
             * that is mostly noise is a question list nobody finishes, which is
             * the same failure as not asking.
             *
             * So agreement collapses: one proposal stays, the duplicates become
             * DISCARD with the reason stated, and ASK keeps only the rows where
             * two rules genuinely send one old address to two different places.
             */
            $targets = array_unique(array_map(
                static fn (int $i): string => $proposals[$i]['target'],
                $indexes,
            ));

            if (count($targets) === 1) {
                $keep = $indexes[0];

                foreach ($indexes as $index) {
                    // The permalink export is the record of what was really
                    // served, so it is the one kept where it is present.
                    if ($proposals[$index]['rule'] === 'permalink') {
                        $keep = $index;

                        break;
                    }
                }

                foreach ($indexes as $index) {
                    if ($index === $keep) {
                        continue;
                    }

                    $proposals[$index]['decision'] = self::DISCARD;
                    $proposals[$index]['reason'] = 'a second rule proposes exactly this redirect, to exactly '
                        .'this destination — one of them is enough, and two identical rows are not a question '
                        .'for anybody';
                }

                continue;
            }

            $winner = null;

            foreach ($indexes as $index) {
                if ($proposals[$index]['rule'] === 'permalink') {
                    $winner = $index;

                    break;
                }
            }

            foreach ($indexes as $index) {
                if ($index === $winner) {
                    continue;
                }

                $proposals[$index]['decision'] = self::ASK;
                $proposals[$index]['question'] = self::Q_TWO_RULES_DISAGREE;
                $proposals[$index]['reason'] = 'another rule claims the same old address "'.$source.'" and points '
                    .'it somewhere else'
                    .($winner === null
                        ? ' — neither is from a permalink export, so neither can be preferred automatically'
                        : ', at "'.$proposals[$winner]['target'].'", which came from the permalink export and '
                            .'is what the old site really served');
            }
        }

        /*
         * A redirect whose target is itself somebody else's source. A browser
         * follows A -> B -> C, but two hops is worse for SEO than one and a
         * slug that moves twice grows the chain a link at a time —
         * RedirectManager collapses these for slug edits and the same rule
         * applies here. Collapsed to the final destination, and said out loud.
         */
        $targets = [];

        foreach ($proposals as $index => $proposal) {
            if ($proposal['decision'] === self::MIGRATE) {
                $targets[$proposal['source']] = $index;
            }
        }

        foreach ($proposals as $index => $proposal) {
            if ($proposal['decision'] !== self::MIGRATE) {
                continue;
            }

            $seen = [$proposal['source'] => true];
            $target = $proposal['target'];
            $hops = 0;

            while (isset($targets[$target]) && ! isset($seen[$target]) && $hops < 10) {
                $seen[$target] = true;
                $target = $proposals[$targets[$target]]['target'];
                $hops++;
            }

            if ($hops === 0) {
                continue;
            }

            if ($target === $proposal['source']) {
                $proposals[$index]['decision'] = self::ASK;
                $proposals[$index]['question'] = self::Q_LOOP;
                $proposals[$index]['reason'] = 'following this redirect leads back to where it started — a loop, '
                    .'which would make the address unreachable rather than moved';

                continue;
            }

            $proposals[$index]['target'] = $target;
            $proposals[$index]['reason'] .= '; collapsed through '.$hops.' intermediate '
                .($hops === 1 ? 'redirect' : 'redirects').' so the visitor makes one hop, not '.($hops + 1);
        }

        return $proposals;
    }

    /**
     * Compare the map against `redirects` as it stands, so the report can say
     * what a write would actually change rather than what it would send.
     *
     * @param  list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>  $proposals
     * @return array{create: list<array<string, string>>, update: list<array<string, string>>, unchanged: list<array<string, string>>, conflict: list<array<string, string>>}
     */
    public function diff(array $proposals): array
    {
        $out = ['create' => [], 'update' => [], 'unchanged' => [], 'conflict' => []];

        $existing = Redirect::query()
            ->whereIn('source', array_values(array_unique(array_column($proposals, 'source'))))
            ->get(['source', 'target', 'auto_created'])
            ->keyBy('source');

        foreach ($proposals as $proposal) {
            if ($proposal['decision'] !== self::MIGRATE) {
                continue;
            }

            $current = $existing->get($proposal['source']);

            if ($current === null) {
                $out['create'][] = $proposal;

                continue;
            }

            if ((string) $current->target === $proposal['target']) {
                $out['unchanged'][] = $proposal;

                continue;
            }

            /*
             * Somebody already pointed this address somewhere else. If the
             * existing row was written by hand it is a decision a person made
             * and this map does not get to overrule it; if it was written by
             * automatic bookkeeping it can be corrected.
             */
            if ((bool) $current->auto_created === false) {
                $out['conflict'][] = $proposal + ['current' => (string) $current->target];

                continue;
            }

            $out['update'][] = $proposal + ['current' => (string) $current->target];
        }

        return $out;
    }
}
