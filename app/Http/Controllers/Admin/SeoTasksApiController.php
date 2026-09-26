<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Seo\SeoSettings;
use App\Support\BusinessAddress;
use App\Support\ConcernCollections;
use App\Support\RoutineConcerns;
use App\Support\SeoAudit;
use Illuminate\Http\JsonResponse;

/**
 * Store → SEO & Meta → Overview — the two questions this console could not
 * answer.
 *
 * ---------------------------------------------------------------------------
 * 1 · "WHAT DO I STILL HAVE TO DO?"
 * ---------------------------------------------------------------------------
 *
 * Several features in this shop are BUILT AND IDLE pending one thing from the
 * owner, and each of them says so in a different document. The concern
 * collections 404 until three products are tagged for a concern
 * (App\Support\ConcernCollections, and docs/SEO-BUILD-PLAN.md ranks them first
 * of everything). The LocalBusiness node publishes no address until the street
 * and the city are filled in, on a shop whose `org_type` may already say
 * `LocalBusiness` (App\Support\BusinessAddress). There is no screen anywhere
 * that lists them, so the way to find out what is waiting is to read eleven
 * documents, and the owner is not going to.
 *
 * So `tasks` is that list, and every entry is COMPUTED FROM THE SHOP'S OWN
 * STATE rather than written down here. A task list with a hard-coded row is a
 * list that goes on nagging after the work is done, which is how a to-do screen
 * stops being read — so every row below asks a query or a setting, and a
 * finished item is absent from the response rather than marked done.
 *
 * ---------------------------------------------------------------------------
 * 2 · "IS MY SEO HEALTHY RIGHT NOW?"
 * ---------------------------------------------------------------------------
 *
 * App\Support\SeoAudit already answers this and this DOES NOT REWRITE IT. It
 * calls SeoAudit::run() and re-presents it, adding exactly two things the audit
 * deliberately does not carry:
 *
 *   - A RANK. The audit lists its twelve findings in declaration order with an
 *     identical amber pill on each, because the order is the order its own
 *     screen prints and moving it would move a screen that works (that class's
 *     own note says so). On that screen "Canonical points somewhere unsafe (1)"
 *     and "Product image with no alt text (671)" look the same weight, and they
 *     are not remotely: the first hands this shop's ranking to another domain
 *     and the second is six hundred missing sentences. The audit already knows
 *     this much — verdict() excludes its ADVISORY findings from the headline —
 *     but the cards do not.
 *
 *   - A PLACE TO GO. The audit says what is wrong. It does not, for ten of its
 *     twelve findings, say where in this console to fix it.
 *
 * Both live in RANK below, in THIS file, keyed by the audit's finding keys.
 * Neither is added to SeoAudit: that file belongs to another lane this round,
 * and a rank is a back-office presentation decision rather than a fact about
 * the shop.
 *
 * ---------------------------------------------------------------------------
 * WHAT HAPPENS WHEN THE AUDIT GROWS A THIRTEENTH FINDING
 * ---------------------------------------------------------------------------
 *
 * It lands in `next` carrying the audit's own `label` and `why`, so it is drawn
 * and counted rather than silently dropped — the failure mode the audit's own
 * screen was designed against ("a check added on the server would scan, count,
 * and then not be drawn"). And SeoOverviewScreenTest FAILS, naming the key, so
 * the rank is a deliberate decision rather than a default nobody noticed. Those
 * two together are the point: the screen keeps working and somebody is told.
 *
 * ---------------------------------------------------------------------------
 * RULE 5
 * ---------------------------------------------------------------------------
 *
 * Every string this returns is either a constant in this file or a value the
 * audit built; the sample rows are the audit's own four-key allowlist, never a
 * model. The console prints all of it with textContent or through its `sesc()`
 * helper — product, category and brand names out of the database are
 * operator-supplied text and this response is full of them.
 *
 * Nothing here writes. It is a GET, it calls no setter, and it is mapped to
 * system.diagnostics — see routes/seo-back-office.php for why that capability
 * and not a looser one.
 */
class SeoTasksApiController extends Controller
{
    /**
     * Where each of the audit's findings sits, and what it costs.
     *
     * `[band, cost, where, go]`:
     *
     *   band   'now' — it is costing the shop pages in Google's index, or
     *                  handing its ranking somewhere else, today.
     *          'next' — a page is in the index and performing below what it
     *                  would with an hour's work on it.
     *          'later' — real, bounded, and nothing is broken while it waits.
     *   cost   what it costs HIM, in his words. Not the vocabulary: the audit's
     *          own `why` already explains the mechanism well and is shown under
     *          this line, so this sentence is the consequence and nothing else.
     *   where  the admin path in words, per rule 3 of the project notes.
     *   go     the console screen id the Fix button calls window.go() with, or
     *          '' where the fix is not one screen (a duplicate title is two
     *          rows in two different places, and a button that opened one of
     *          them would be choosing for him).
     *
     * ── WHY THE BANDS ARE THESE THREE AND IN THIS ORDER ─────────────────────
     *
     * `bad_canonical` and `duplicate_title` are `now` because both make pages
     * DISAPPEAR: a canonical pointing at another host gives that host the
     * ranking, and duplicate titles are — in the audit's own words — "the most
     * common way a catalogue loses pages from the index". Nothing else on the
     * list removes a page.
     *
     * `title_too_long` is `later` although it sounds urgent: the page ranks
     * perfectly, the end of the title is cut off in the result. `no_description`
     * is `next` and not `now` for the mirror reason: Google writes a sentence
     * from the page body, which is usually adequate and occasionally awful.
     *
     * `product_no_image_alt` and `legacy_url_no_redirect` are `later`, which is
     * where SeoAudit::ADVISORY already puts them for the purpose of the headline
     * — it calls them "an opportunity rather than a fault". That constant is
     * private so this cannot read it; SeoOverviewScreenTest pins that these two
     * are the only findings this file ranks `later` while the audit's verdict
     * skips them, so the two files cannot quietly disagree.
     */
    public const RANK = [
        'bad_canonical' => [
            'now',
            'This page is telling Google that somebody else’s address is the real one. Whatever it earns goes to them.',
            'Catalog → Products / Categories / Brands → the row → SEO → Canonical URL',
            '',
        ],
        'duplicate_title' => [
            'now',
            'Google keeps one of these pages and quietly drops the rest. You are paying to stock products that cannot be found.',
            'Catalog → the row → SEO → Page title',
            '',
        ],
        'no_description' => [
            'next',
            'Google writes the sentence under your link itself, out of whatever is on the page. Sometimes it reads well. Nobody chose it.',
            'Catalog → the row → SEO → Description',
            '',
        ],
        'thin_description' => [
            'next',
            'There is a sentence, but it is too short to say anything, so Google treats it as missing and writes its own anyway.',
            'Catalog → the row → SEO → Description',
            '',
        ],
        'title_too_short' => [
            'next',
            'A one-word title only matches somebody searching that one word. Adding what it is and who it is for widens it.',
            'Catalog → the row → SEO → Page title',
            '',
        ],
        'no_image' => [
            'next',
            'Nothing to show. No picture in the search result, nothing when the link is pasted into WhatsApp, and no entry in the image sitemap.',
            'Catalog → Products → the product → Media',
            'catalog',
        ],
        'product_no_identifier' => [
            'next',
            'Google’s free shopping listings match products on a code. Without a SKU or a barcode this product cannot appear there at all.',
            'Catalog → Products → the product → Basics → SKU',
            'catalog',
        ],
        'product_no_category' => [
            'next',
            'Nothing links to this product but page after page of /shop/. It is the last thing a crawler reaches and the first thing it gives up on.',
            'Catalog → Products → the product → Organise',
            'catalog',
        ],
        'duplicate_description' => [
            'later',
            'Not a penalty. Google rewrites the snippet when several pages share one, so the words you wrote are not the words shown.',
            'Catalog → the row → SEO → Description',
            '',
        ],
        'title_too_long' => [
            'later',
            'The page ranks fine; the end of the title is cut off in the result, so the last few words are never read.',
            'Catalog → the row → SEO → Page title',
            '',
        ],
        'product_no_image_alt' => [
            'later',
            'People shop beauty by looking. A sentence describing what is in the photograph is what wins an image search; the product name is not.',
            'Catalog → Products → the product → Media',
            'catalog',
        ],
        'legacy_url_no_redirect' => [
            'later',
            'An address the old shop published still gets visitors, and they land on a 404. Whatever links and ranking it had are dropped rather than passed on.',
            'Store → SEO & Meta → Redirects & 404s',
            'seo',
        ],
        /*
         * Lane S5's check, ranked by the integrator because it landed after this
         * screen was written and would otherwise fall into the middle band by
         * default -- which the test above this file's RANK exists to catch, and
         * did.
         *
         * `next` and not `now`: no page is lost. What is lost is the panel a
         * brand search draws, because the two halves of the business identity
         * contradict each other and Google drops what it cannot reconcile. For
         * THIS shop the answer is already known -- online only, no shopfront --
         * so the fix is one select, not an address.
         *
         * LAST in this list on purpose: the screen draws its cards in
         * declaration order, so a key inserted anywhere else moves every card
         * already on the screen down one.
         */
        'business_type_mismatch' => [
            'next',
            'You have told Google two things about this business that cannot both be true — what kind of business it is, and where it is. Google drops the pair rather than guessing, so the panel a brand search draws is built from less than you gave it.',
            'Store → Business Details → Business, or Store → SEO & Meta → Settings → Business identity',
            'store-settings',
        ],
    ];

    /** The bands, in the order the screen prints them, with their headings. */
    public const BANDS = [
        'now' => 'Fix these first — they are costing you pages in Google',
        'next' => 'Worth doing next — these pages are in Google and under-performing',
        'later' => 'When you have time — nothing is broken while these wait',
    ];

    public function index(): JsonResponse
    {
        $settings = SeoSettings::map();
        $report = SeoAudit::run();

        return response()->json([
            'ok' => true,
            'health' => $this->health($report),
            'tasks' => $this->tasks($settings),
        ]);
    }

    /**
     * The audit, ranked.
     *
     * `verdict`, `scanned` and `total` are the audit's own strings and numbers,
     * passed through untouched — the one-line verdict is already written in the
     * owner's terms ("Biggest issue: no description at all (37)") and rewriting
     * it here would be a second opinion about the same scan.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function health(array $report): array
    {
        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];

        $bands = [];
        $clear = [];
        $problems = 0;

        foreach (self::BANDS as $band => $heading) {
            $bands[$band] = ['band' => $band, 'heading' => $heading, 'items' => []];
        }

        foreach ($findings as $key => $finding) {
            $count = (int) ($finding['count'] ?? 0);
            $label = (string) ($finding['label'] ?? $key);

            /*
             * A FINDING WITH NOTHING WRONG IS ONE GREEN LINE, NOT A CARD.
             *
             * The audit screen draws twelve cards on every shop, and on a
             * healthy one eleven of them say "None — nothing on the shop has
             * this problem". Twelve cards is a page that has to be read to
             * discover it is fine, and a wall of identical cards is read by
             * nobody — which is how the one card that is NOT fine gets missed.
             * Its own note argues, correctly, that a clean shop must show "0"
             * against every check rather than nothing at all, because an empty
             * audit looks like one that failed to run. Both are satisfied by
             * naming every clear check on one line and giving a card only to a
             * finding with a number on it.
             */
            if ($count === 0) {
                $clear[] = $label;

                continue;
            }

            $problems += $count;

            [$band, $cost, $where, $go] = self::RANK[$key]
                /*
                 * A finding this file has never heard of. It is DRAWN, in the
                 * middle band, with the audit's own words as the cost line —
                 * the alternative is a check that scans, counts and is then not
                 * shown, which is the exact failure the audit screen's own
                 * header was written against. SeoOverviewScreenTest goes red and
                 * names the key, so this is a loud default rather than a silent
                 * one.
                 */
                ?? ['next', (string) ($finding['why'] ?? ''), 'Store → SEO & Meta → SEO Audit', 'seo'];

            $bands[$band]['items'][] = [
                'key' => $key,
                'label' => $label,
                'count' => $count,
                'cost' => $cost,
                'why' => (string) ($finding['why'] ?? ''),
                'where' => $where,
                'go' => $go,
                // The audit's own four-key sample rows, forwarded as they
                // arrive. Never a model — see SeoAuditApiController's note.
                'samples' => array_values((array) ($finding['samples'] ?? [])),
            ];
        }

        return [
            'verdict' => (string) ($report['verdict'] ?? ''),
            'scanned' => (array) ($report['scanned'] ?? []),
            'total' => (int) ($report['total'] ?? 0),
            'problems' => $problems,
            'bands' => array_values($bands),
            'clear' => $clear,
        ];
    }

    /**
     * What is waiting on the owner, computed.
     *
     * Order is severity, then cheapness: the two things that can hide the whole
     * shop from Google come first whatever else is outstanding, and after that
     * the ones that are one box away.
     *
     * @param  array<string, mixed>  $s  SeoSettings::map() — blanks dropped,
     *   defaults applied, which is the map the STOREFRONT reads. Reading
     *   Setting::map() instead would report a saved-then-emptied box as set,
     *   which is exactly the class of shop this list has to be right about.
     * @return list<array<string, mixed>>
     */
    private function tasks(array $s): array
    {
        $tasks = [];

        /*
         * THE WHOLE SHOP HIDDEN FROM GOOGLE. First, unconditionally, and its
         * own severity, because every other line on this screen is worthless
         * while it is true — an audit of a shop nobody can find is a reading
         * of a switched-off machine. It is one select on one screen and it has
         * no other symptom: the shop renders perfectly.
         */
        if (($s['robots_index'] ?? 'index') === 'noindex') {
            $tasks[] = [
                'key' => 'noindex',
                'urgency' => 'stop',
                'title' => 'Your whole shop is hidden from Google',
                'why' => 'Search engines are being told not to list any page of this site. Nothing else on this screen matters until this is changed back — the shop works, it simply cannot be found.',
                'where' => 'Store → SEO & Meta → Settings → Search appearance → Search engines',
                'go' => 'seo',
                'detail' => 'Set it back to “Index (allow ranking)”.',
            ];
        }

        if (($s['sitemap_enabled'] ?? '1') === '0') {
            $tasks[] = [
                'key' => 'sitemap_off',
                'urgency' => 'stop',
                'title' => 'Your sitemap is switched off',
                'why' => 'The sitemap is how Google finds a new product or article without waiting to stumble across a link to it. With it off, new pages can take weeks to appear and some never do.',
                'where' => 'Store → SEO & Meta → Settings → Sitemap & robots → XML sitemap',
                'go' => 'seo',
                'detail' => 'Set it to Enabled.',
            ];
        }

        /*
         * THE CONCERN PAGES — the highest-ranked item in docs/SEO-BUILD-PLAN.md
         * and the clearest case of a built feature idling on the owner's input.
         *
         * "korean skincare for acne" is a search with a buyer behind it and this
         * shop has no page addressed to it. The page exists the moment three
         * live, in-stock products carry the tag, and the tagging is a screen he
         * already has. Until then the address is a 404 and nothing links to it —
         * which is the right behaviour and completely invisible.
         *
         * ONE QUERY FOR EVERY CONCERN. ConcernCollections::counts() tallies all
         * of them in a single pass by construction — its own note says why — so
         * this costs one query however many concerns get copy written.
         */
        $enabled = ConcernCollections::slugs();

        if ($enabled !== []) {
            $counts = ConcernCollections::counts($enabled);
            $short = [];

            foreach ($enabled as $slug) {
                $have = (int) ($counts[$slug] ?? 0);

                if ($have < ConcernCollections::MIN_PRODUCTS) {
                    $short[] = [
                        'slug' => $slug,
                        'label' => RoutineConcerns::adminLabel($slug),
                        'have' => $have,
                        'need' => ConcernCollections::MIN_PRODUCTS,
                    ];
                }
            }

            if ($short !== []) {
                $tasks[] = [
                    'key' => 'concern_pages',
                    'urgency' => 'do',
                    'title' => 'A shopping page per skin concern is built and waiting on you',
                    'why' => 'Somebody searching “korean skincare for acne” is ready to buy; somebody searching “new in” is browsing. This shop sorts its shelves by what a product is, and has no page addressed to what a shopper wants. The page builds itself from products you have tagged — it stays hidden until there are enough of them, because a collection of two is worse than no collection at all.',
                    'where' => 'Catalog → Build my routine — tick the products and press the concern',
                    'go' => 'routines',
                    'detail' => implode(' · ', array_map(
                        static fn (array $r): string => $r['label'].': '.$r['have'].' of '.$r['need'].' tagged',
                        $short
                    )),
                    'rows' => $short,
                ];
            }
        }

        /*
         * THE ADDRESS BEHIND THE ORGANIZATION TYPE.
         *
         * Two different wrong states, and they need different sentences. A shop
         * whose type says LocalBusiness and publishes no address is making a
         * claim it cannot back — BusinessAddress refuses to publish half an
         * address on purpose, so the node goes out with a name and a logo and
         * nothing that places it anywhere. A shop that has typed an address and
         * left the type at Organization has the opposite problem: the address
         * publishes, the map pin and the opening hours do not, because `geo` and
         * `openingHoursSpecification` are properties of a Place and Organization
         * is not one.
         */
        $orgType = (string) ($s['org_type'] ?? 'Organization');
        $isPlace = in_array($orgType, BusinessAddress::PLACE_TYPES, true);
        $hasPostal = BusinessAddress::postal($s) !== null;

        if ($isPlace && ! $hasPostal) {
            $tasks[] = [
                'key' => 'localbusiness_address',
                'urgency' => 'do',
                'title' => 'You have told Google this is a shop with a door, and not where it is',
                'why' => 'The business type is set to “'.$orgType.'”, which is the setting for a shop customers can walk into — and no address is published, so Google cannot put it anywhere. A partly-filled address is worse than none, so nothing at all is sent until the street, the city and the country are there.',
                'where' => 'Store → Business Details → Business → Where the shop is',
                'go' => 'store-settings',
                'detail' => 'Street, city and a two-letter country are the three that are required. The postal code is normally blank in the UAE.',
            ];
        }

        if (! $isPlace && $hasPostal && BusinessAddress::geo($s) !== null) {
            $tasks[] = [
                'key' => 'place_type',
                'urgency' => 'later',
                'title' => 'Your map pin is filled in and is not being published',
                'why' => 'The coordinates and the opening hours only go out when the business type is “Store” or “LocalBusiness” — those are the only two that schema.org lets sit on a map. The type is currently “'.$orgType.'”, so the address is published and the pin is not.',
                'where' => 'Store → SEO & Meta → Settings → Business identity → Type',
                'go' => 'seo',
                'detail' => 'Change it only if customers really can walk in. It is not a way to rank locally without a shopfront.',
            ];
        }

        /*
         * The rest: one box each, each with a consequence that is real and
         * specific. Anything whose only honest description is "best practice"
         * is NOT on this list — a to-do screen that pads itself is a to-do
         * screen nobody finishes.
         */
        if (($s['site_url'] ?? '') === '') {
            $tasks[] = [
                'key' => 'site_url',
                'urgency' => 'do',
                'title' => 'This shop has not been told its own address',
                'why' => 'Every page says which address it really lives at, and the sitemap lists them. With this empty, those come out as paths rather than full addresses, and a search engine that reaches the same page by two routes cannot tell they are one page.',
                'where' => 'Store → SEO & Meta → Settings → Search appearance → Site URL',
                'go' => 'seo',
                'detail' => 'The address customers type, with https:// and no trailing slash.',
            ];
        }

        if (($s['seo_home_description'] ?? '') === '') {
            $tasks[] = [
                'key' => 'home_description',
                'urgency' => 'do',
                'title' => 'Google is writing your homepage sentence for you',
                'why' => 'The line under your shop’s name in a search result is the one piece of sales copy you get for free, and right now it is whatever Google picked off the page. This is the single box that changes it.',
                'where' => 'Store → SEO & Meta → Settings → Search appearance → Homepage meta description',
                'go' => 'seo',
                'detail' => 'Around 155 characters. The preview above the box shows what it will look like as you type.',
            ];
        }

        if (($s['og_default_image'] ?? '') === '') {
            $tasks[] = [
                'key' => 'share_image',
                'urgency' => 'do',
                'title' => 'A link to this shop pasted into WhatsApp shows no picture',
                'why' => 'WhatsApp, Instagram, Facebook and iMessage all read one image off the page. Without a default, any page that has no picture of its own is shared as a bare grey link, which almost nobody taps.',
                'where' => 'Store → SEO & Meta → Settings → Sharing a link → Default share image',
                'go' => 'seo',
                'detail' => '1200 × 630 pixels.',
            ];
        }

        if (($s['google_site_verification'] ?? '') === '') {
            $tasks[] = [
                'key' => 'search_console',
                'urgency' => 'do',
                'title' => 'You cannot see what Google thinks of this shop yet',
                'why' => 'Search Console is where Google tells you which searches brought people here, which pages it refused and why. It is free, it needs one token pasted into one box, and nothing about the shop changes when you do it.',
                'where' => 'Store → SEO & Meta → Settings → Verification & tracking → Google Search Console',
                'go' => 'seo',
                'detail' => 'search.google.com/search-console gives you the token.',
            ];
        }

        if (($s['enable_merchant'] ?? '0') !== '1') {
            $tasks[] = [
                'key' => 'merchant',
                'urgency' => 'later',
                'title' => 'Prices and stars are not showing under your products in Google',
                'why' => 'A product result can carry its price, its availability and its rating instead of just a title and a line of text. It needs four numbers confirmed first — what condition you sell, where you ship, what delivery costs and how long returns are — because publishing terms you do not honour is worse than publishing none.',
                'where' => 'Store → SEO & Meta → Settings → Rich product results',
                'go' => 'seo',
                'detail' => 'Check the four values, then switch on “Enable merchant listing on every product”.',
            ];
        }

        if (($s['org_logo'] ?? '') === '') {
            $tasks[] = [
                'key' => 'org_logo',
                'urgency' => 'later',
                'title' => 'Your logo is not in the information Google holds about the business',
                'why' => 'It is what appears beside the shop’s name in a brand search and in the panel on the right of the results. One image, set once.',
                'where' => 'Store → SEO & Meta → Settings → Business identity → Logo',
                'go' => 'seo',
                'detail' => 'A square-ish image of the logo on a plain background.',
            ];
        }

        $social = array_filter([
            $s['social_facebook'] ?? '', $s['social_instagram'] ?? '',
            $s['social_tiktok'] ?? '', $s['social_pinterest'] ?? '',
            $s['social_linkedin'] ?? '', $s['social_youtube'] ?? '',
        ], static fn (mixed $v): bool => is_string($v) && trim($v) !== '');

        if ($social === []) {
            $tasks[] = [
                'key' => 'social_profiles',
                'urgency' => 'later',
                'title' => 'Google has not been told which social accounts are yours',
                'why' => 'Listing them lets Google confirm the accounts belong to this business, which is what fills in the panel on a brand search. Leave blank any you do not have — a wrong one is worse than a missing one.',
                'where' => 'Store → SEO & Meta → Settings → Social profiles',
                'go' => 'seo',
                'detail' => 'Full addresses, one per network.',
            ];
        }

        /*
         * FAQ MARKUP, IN WHICHEVER OF ITS TWO WRONG STATES THIS SHOP IS IN.
         *
         * Lane S7 asked S6 for a read-only counter so this row could exist, and
         * both halves are now merged, so here it is. Neither state has any
         * symptom on the shop, which is the whole reason the Overview screen
         * exists: a switch that publishes nothing looks exactly like a switch
         * that publishes something.
         *
         * Computed, so it DISAPPEARS the moment the work is done -- either the
         * flag goes on, or a page gets questions, or neither is true and this
         * says nothing. The census applies the same rule the node does, so it
         * cannot promise a node the graph would not emit.
         */
        $faqOn = \App\Services\Seo\FaqSchema::enabled($s);
        $faq = \App\Services\Seo\FaqSchema::census();

        if (! $faqOn && $faq['questions'] > 0) {
            $tasks[] = [
                'key' => 'faq_off_with_questions',
                'urgency' => 'do',
                'title' => $faq['questions'] . ' questions on your pages that Google is not being shown',
                'why' => 'Pages here are already written as questions and answers — the shape an answer engine can quote directly. Switching the markup on tells it which text is the question and which is the answer, instead of leaving it to guess from the layout.',
                'where' => 'Store → SEO & Meta → Settings → Sitemap & robots → FAQ markup on content pages',
                'go' => 'seo',
                'detail' => 'Set it to Published. ' . $faq['questions'] . ' questions across '
                    . $faq['pages'] . ' ' . ($faq['pages'] === 1 ? 'page' : 'pages')
                    . ' would be published. Google no longer shows an FAQ drop-down in results, so this is for the answer engines rather than for the blue links.',
            ];
        }

        if ($faqOn && $faq['questions'] === 0) {
            $tasks[] = [
                'key' => 'faq_on_without_questions',
                'urgency' => 'later',
                'title' => 'FAQ markup is switched on and no page has questions in it',
                'why' => 'The switch is on and publishing nothing, because a heading only counts as a question when it ends in a question mark and at least two are needed on one page. Nothing is broken — it is simply doing nothing, which is worth knowing rather than assuming.',
                'where' => 'Store → Pages → the page → and write the headings as questions',
                'go' => 'seo',
                'detail' => 'Either write a page’s headings as questions, or set the switch back to Not published.',
            ];
        }

        return $tasks;
    }
}
