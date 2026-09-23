<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Address;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use Closure;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * The storefront, rendered, for the pages a shopper actually reads.
 *
 * WHY THIS IS NOT tests/Feature/StorefrontRouteWalkTest.php.
 *
 * That file walks the ROUTER and asserts a status code. This one needs the
 * BYTES, and it needs to render the same page twice inside one process with two
 * different copies of resources/views. The two have different jobs and
 * different failure modes, so the second is not bolted onto the first — but the
 * coverage guard below means this file cannot fall behind the router either: a
 * storefront GET route with no entry here is a red test, exactly as it is
 * there.
 *
 * Only pages whose body is rendered from a Blade template are listed. A JSON
 * endpoint, a redirect and a file served off disk have no interface strings in
 * them, and asserting on their bytes would be asserting on nothing.
 */
final class EnglishRenderWalk
{
    /**
     * The tree whose English output is the contract: the commit this
     * conversion is applied ON TOP OF, not the one the lane happened to branch
     * from. Update it only together with a reviewed copy change — and only to
     * the merge's own first parent, never to the merge itself, which would
     * compare the new text with itself and assert nothing.
     *
     * MOVED AT MERGE, from 78b110ef. The lane branched before the bilingual-SEO
     * lane landed, and that lane moved hreflang out of layouts/store.blade.php
     * into App\Support\Seo — leaving exactly one blank line where the old
     * block stood, in the <head> of every page that uses the layout. The walk
     * saw it, correctly, as a byte difference and blamed this conversion for it.
     *
     * It is NOT masked and NOT approved as a reflow, because it is not this
     * lane's difference to approve: the fix is to compare against the tree this
     * lane is actually being applied to, which is the merge's first parent.
     * Every page then differs by nothing at all, which is the claim this test
     * exists to make.
     */
    /*
     * MOVED AGAIN, and this time for a REVIEWED COPY CHANGE rather than a
     * neighbouring lane's whitespace — which is the one reason this docblock
     * permits.
     *
     * The owner settled the rounding question: whole dirhams, and if a price
     * would carry fils, adjust the price. The four receipt surfaces had just
     * been widened to full precision to stop them disagreeing with the emailed
     * copy, so under his answer they printed AED 220.00 where he asked for
     * AED 220. They now ask Money::receiptDecimals(), which prints whole
     * dirhams whenever every figure on that receipt truthfully is one, and
     * widens only when one is not.
     *
     * That changes the bytes of the account pages ON PURPOSE, so the contract
     * moves with it. What the previous base guaranteed is not lost: the text
     * conversion was proved byte-identical against 77149bd at the moment it
     * merged, and that proof is recorded in its merge commit. From here the
     * guard answers the next question — has anything since changed the English?
     */
    /*
     * MOVED AGAIN, for the other half of the same reviewed copy change — Lane FA.
     *
     * 0a0f77f made the four ACCOUNT receipts print whole dirhams. The owner's
     * rule applies to the basket he is looking at as well: the cart summary and
     * the checkout ledger ask Money::receiptDecimals() too now, through
     * CartService::totals()' `decimals` key, so a whole-dirham basket prints
     * whole dirhams and one still holding a price from before the policy widens
     * as a whole column and adds up. That changes the bytes of /cart and
     * /checkout on purpose, so the contract moves with it.
     *
     * What the earlier bases guaranteed is not lost. The text conversion was
     * proved byte-identical against 77149bd at the moment it merged and that
     * proof is in its merge commit; 0a0f77f's receipt change is in this one's
     * history. From here the guard answers the next question — has anything
     * SINCE changed the English?
     *
     * ── MOVED AGAIN — LANE FJ ───────────────────────────────────────────────
     *
     * TWO PAGES, AND THE DIFF WAS READ BEFORE IT WAS APPROVED. Both changes are
     * inside @verbatim, which is why they show up here at all — a Blade comment
     * is stripped and a JavaScript comment is shipped to the browser:
     *
     *   skincare-guide  the Journal's tag filter was rewritten. setTag() matched
     *                   the active chip on its RENDERED TEXT, so the first
     *                   translated label would have killed the highlight for
     *                   every chip, and 'All' was a word used as a sentinel in
     *                   three places. The page also gained one line above the
     *                   filter carrying the translated "All" label.
     *
     *   skin-quiz       buildPayload()'s comment said `recommended_routines` is
     *                   never stored. Api\QuizController writes it now, so the
     *                   comment said something untrue about the code beside it.
     *
     * Not a byte of shopper-visible COPY moved on either page: the one English
     * row this lane did change on purpose is on the order-received summary,
     * which this walk cannot see — it renders /checkout/success with no order in
     * the session, so the summary partial is never included. That row is pinned
     * in OrderPaperworkLabelsAreKeyedTest instead, by the case named for it.
     *
     * MOVED BY MERGE, NOT BY REBASE. This constant is a SHA, and a rebase
     * rewrites every SHA behind it — repinning to a commit and then rebasing
     * leaves the guard pointing at an object that is not in the branch, where
     * it fails with "no such commit" rather than with a diff. The commit named
     * below is reachable from this branch's history and stays reachable.
     *
     * Lane FO repinned, rebased when the base branch moved under it, and then
     * REPINNED AGAIN to the rewritten SHA — which is the note above working as
     * intended rather than around it. The value here must always be a commit
     * `git archive` can resolve from the branch it is read on; anything else
     * fails with "no such commit" instead of with a diff, and a guard that
     * cannot run is indistinguishable from one that passes.
     *
     * ── MOVED AGAIN FOR LANE FO (Phase 15, the homepage hero) ───────────────
     *
     * NOT ONE BYTE OF SHOPPER-VISIBLE COPY MOVED, and the diff this reported
     * was an artefact of how the comparison is built rather than a change to
     * the page. Worth setting out, because the same shape will recur.
     *
     * The hero's three slides used to be a literal array in HomeController,
     * with a `<br>` inside each headline, echoed through {!! !!}. They are
     * HomepageContent::DEFAULT_SLIDES now — the same words, with the `<br>`
     * stored as a NEWLINE so that store/home.blade.php can escape what an owner
     * types into the new Appearance → Homepage content screen and convert the
     * newline itself. Rendered, it is the same bytes: str_replace over e()'s
     * result puts back exactly `<br>`, which is why it is not nl2br(), whose
     * output keeps the newline as well and defaults to the XHTML form.
     *
     * THIS WALK CANNOT SEE THAT, by construction. It rolls resources/views back
     * to BASE_COMMIT and leaves the PHP in the working tree, so its "before" is
     * the OLD template echoing the NEW default raw — a page that has never
     * existed and never will. It reported `Age-R Booster Pro⏎with a free gift
     * set` against `Age-R Booster Pro<br>with a free gift set`, where the page
     * the server is serving today is the second of those.
     *
     * The compensating pin is in tests/Feature/HomepageContentEditorTest.php,
     * which asserts those exact bytes off the rendered hero — including the
     * three gradients, the three buttons and that the first slide carries the
     * page's only <h1>. That assertion does the work this constant cannot do
     * for its own move, which is the honest cost of moving it and the reason it
     * is named here rather than left to be inferred.
     *
     * Everything else on every page is byte-identical, which is what let the
     * hero's wrapper be written the way it is: the new @if shares a line with
     * the div and the comments above it close on the markup, so the slider is
     * conditional without moving a single space. See the comments in
     * store/home.blade.php, which say so at each of the three places.
     *
     * ── MOVED AGAIN — LANE FK (the five standalone documents) ──────────────
     *
     * FOUR PAGES, ONE ATTRIBUTE, AND THE DIFF WAS READ BEFORE IT WAS APPROVED.
     * The whole of what changed on skincare-guide, a post page, skin-quiz and
     * reviews is this, at byte 16 of each document and nowhere else:
     *
     *     before   <html lang="en">
     *     after    <html lang="en" dir="ltr">
     *
     * Not one other byte moved on any of the four — no whitespace, no reflow,
     * no copy. The five pages that carry their own <html> (store/app is the
     * fifth and is admin-only, so this walk does not reach it) never picked up
     * the lang and dir that layouts/store.blade.php has emitted since the
     * bilingual foundation landed, so /ar/skincare-guide/ served Arabic chrome
     * under lang="en" and stated no direction at all. Both attributes come from
     * Locale now, which on an English page resolves to exactly what the first
     * of them was hard-coded to and makes the second one explicit.
     *
     * `dir="ltr"` IS AN ADDITION TO THE ENGLISH PAGE and that is the point: a
     * document that states its direction is a document the mirrored layout can
     * be switched on under. It is what the shared layout already prints on
     * every other page of the shop, so the four are now consistent with it
     * rather than exceptions to it. Pinned from the other side in
     * tests/Feature/StandaloneDocumentsDeclareTheirLanguageTest.php, which
     * asserts the exact tag on all six documents in all three switch states —
     * that assertion is what this constant cannot do for its own move. See
     * docs/rtl-standalone-documents.md.
     *
     * It carries the RTL manual half's moves and Lane FO's hero as well as this
     * one; all three had landed by the commit named below.
     *
     * AND ONE CSS DECLARATION, in the same lane and for the same reason. Giving
     * those documents a real `dir` is what unblocked T6 §11.4's deferred
     * `.mnav` conversion, so blog and post also moved `left: 0` to
     * `inset-inline-start: 0` and gained one `[dir="rtl"]` rule that cannot
     * match in an English document. In LTR `inset-inline-start` IS `left`, so
     * the four pages render identically — what moved is the bytes of the
     * <style> block the browser is sent, which is exactly the kind of change
     * this walk exists to put in front of someone.
     *
     * The commit named below is the one that made both moves.
     *
     * ── MOVED AGAIN FOR LANE FT (the quiz's follow-through) ─────────────────
     *
     * ONE PAGE, THIRTY-NINE ADDED LINES, NOTHING REMOVED AND NOTHING CHANGED.
     * The diff was read line by line before it was approved and it is entirely
     * INERT ON THE SHIPPED SHOP. `/skin-quiz` grew, in three places and nowhere
     * else:
     *
     *   1. a `.rtnlink` rule in the inline <style> block — three declarations
     *      that style an element the shipped page never draws;
     *   2. `routinePick()` and `routineLinkHTML()` in the inline <script>;
     *   3. one `${routineLinkHTML()}` in the results template.
     *
     * `routineLinkHTML()` returns the EMPTY STRING unless `window.KBB_ROUTINES`
     * is on the page, and that table is emitted only when Store → Modules →
     * Build my routine is on, which is not how it ships — the module is off by
     * default and /routines answers 404 in that state. So on the live shop the
     * third item renders nothing, the second is two functions nobody calls into
     * and the first styles nothing that exists.
     *
     * NOT ONE BYTE OF SHOPPER-VISIBLE COPY MOVED. The `diff` has no removed and
     * no changed lines at all, only additions: nothing on the page shifted,
     * reflowed or was reworded. The two new English sentences in the file are
     * `t()` fallbacks inside `routineLinkHTML()`, which the page cannot reach
     * with the module off; they are pinned as copy by
     * QuizScriptStringsAreKeyedTest's drift guard, which compares every quiz
     * call site against InterfaceStrings, and as behaviour by
     * tests/Feature/QuizFollowThroughTest.php, which fetches the page in BOTH
     * switch states and fails if the link appears in the wrong one. Those two
     * are the assertions this constant cannot make for its own move.
     *
     * WHAT THIS WALK COULD NOT HAVE SEEN, said plainly: it renders with the
     * module off, because that is the shipped state, so the ON state is not
     * covered by this file at all. QuizFollowThroughTest covers it.
     *
     * The quiz's plan email, which is the other half of that lane, does not
     * appear here in any form — an email is not a storefront page, and
     * `/skin-quiz` renders identically whether or not one is ever sent.
     *
     * MOVED BY MERGE, NOT BY REBASE — see the paragraph above. The commit named
     * below is the one that made the three additions, and it is this branch's
     * own tip at the time of writing rather than a commit on the base: a walk
     * that compared against the base would report the additions for ever.
     */
    /*
     * MOVED FORWARD FOR LANE FS's SALE BADGE, and the whole diff is two tags.
     *
     *     -<span class="lbl" ...>-30% OFF</span>
     *     +<span class="lbl" ...><bdi>-30% OFF</bdi></span>
     *
     * on /shop, a category and a product page. Nothing removed, nothing
     * reworded, no whitespace moved. <bdi> renders nothing of its own and the
     * badge was measured painting identically in English with and without it;
     * what it buys is the Arabic page, where -30% otherwise paints 30%- and
     * inside Arabic text %30-.
     *
     * MOVED FORWARD AGAIN FOR THE MOBILE-HEADER LANE, and this diff is three
     * tags. The account, wishlist and cart marks in partials/header.blade.php
     * are now drawn from App\Support\HeaderIcons instead of being pasted into
     * the template, so the shop and the two admin previews cannot go on
     * disagreeing about what the header looks like:
     *
     *     -<svg … stroke-width="1.8"><circle cx="12" cy="8" r="4"/>…
     *     +<svg … fill="currentColor" aria-hidden="true"><path d="M12 12.4…
     *
     * plus `mh-signedin` on the account link for a signed-in shopper, which is
     * what turns that mark green. The owner asked for both in as many words.
     * Read on every rendered page in the walk: the first difference is the
     * account mark and there is no other kind of difference — no text, no
     * attribute order, no whitespace. The Blade comment that would have moved
     * whitespace on thirty pages was deliberately written inside the template's
     * @php block instead, and the note there says why.
     *
     * AND A WORD FOR WHOEVER MERGES THIS. The value below has to be a commit
     * that CARRIES the change, so a rebase or a squash of that lane invalidates
     * it — `git show <sha>:resources/views` then resurrects the old marks and
     * this walk goes red on every page again. Repoint it at whatever commit the
     * merge produces; it is this one line and nothing else.
     *
     * MOVED FORWARD AGAIN FOR THE CART-FOOTER LANE, and this diff is one
     * element on one page. The owner asked for a cart page with no footer --
     * "on cart there will be no footer! ... by default keep the footer turned
     * off on the cart page completely" -- so `cartpage_footer_on` ships false
     * and store/cart.blade.php declares the `no-footer` section that
     * layouts/store.blade.php reads.
     *
     * READ BEFORE IT WAS APPROVED, and worth stating precisely, because this
     * is the one control on the cart page screen that was ALLOWED to move
     * bytes. Exactly two entries in the walk moved -- `cart` and `(with a
     * basket) /cart` -- and in both the first difference is at the close of
     * <main>:
     *
     *     -</main><nl><nl>    <footer><div class="wrap">...</footer><nl><nl><div class="mscrim"...
     *     +</main><nl><nl><nl><div class="mscrim"...
     *
     * The whole of partials/footer.blade.php and nothing else. The newline
     * that remains is the blank line that has always sat after the @endunless.
     *
     * NO OTHER PAGE IN THE WALK MOVED A BYTE, which is the half worth checking
     * rather than assuming: the switch belongs to the cart page, and
     * layouts/store.blade.php is extended by every page in the shop. The note
     * added to that layout is a PHP comment inside an @php block for the same
     * reason -- a Blade comment there leaves its newline behind, and that one
     * byte would have landed on all thirty pages.
     */
    /*
     * MOVED FORWARD for the checkout's Shipping address section, which is a
     * picker over the cart page's addresses instead of five typed fields, and
     * for the contact row before it.
     *
     * The owner asked for it before the work started: section 2 is becoming an
     * address picker, and a saved address carries no name, so a name field
     * above a list of addresses would read as naming the address rather than
     * the person.
     *
     * The diff this test printed was that move and nothing else -- ONE page,
     * /checkout, at one byte offset, and no other page in the walk moved. That
     * is the half worth checking before advancing the pin rather than assuming:
     * a base commit moved forward over an unread diff is a guard switched off.
     */
    public const BASE_COMMIT = 'da28793eb18811216e5aa4f18ae6d02f2988be85';

    /** resources/views as of $commit, materialised under a temp directory. */
    public static function baseViews(string $commit = self::BASE_COMMIT): string
    {
        $root = base_path();
        $dir = sys_get_temp_dir() . '/kbb-english-base-' . substr($commit, 0, 12) . '-' . getmypid();

        if (is_dir($dir . '/resources/views')) {
            return $dir . '/resources/views';
        }

        // `.git` is a directory in a clone and a FILE in a git worktree, which is
        // how several lanes on this repo run the suite.
        if (! file_exists($root . '/.git')) {
            throw new RuntimeException(
                'This test reads the pre-conversion templates out of git and this checkout has no .git. '
                . 'It cannot be run against an exported tree.'
            );
        }

        @mkdir($dir, 0777, true);

        $cmd = sprintf(
            'git -C %s archive %s resources/views 2>&1 | tar -x -C %s 2>&1',
            escapeshellarg($root),
            escapeshellarg($commit),
            escapeshellarg($dir)
        );

        exec($cmd, $out, $code);

        if ($code !== 0 || ! is_dir($dir . '/resources/views')) {
            throw new RuntimeException(
                "Could not extract resources/views at {$commit}: " . implode("\n", $out)
            );
        }

        return $dir . '/resources/views';
    }

    /** Point the view finder at one root and forget everything it had resolved. */
    public static function useViewPath(string $path): void
    {
        $factory = app('view');
        $factory->getFinder()->setPaths([$path]);
        $factory->flushFinderCache();
        $factory->flushState();

        \Illuminate\View\Component::flushCache();
        \Illuminate\View\Component::forgetComponentsResolver();
        \Illuminate\View\Component::forgetFactory();

        config(['view.paths' => [$path]]);
    }

    /** A readable "here is where they part" for a failure message. */
    public static function firstDifference(string $a, string $b): string
    {
        if ($a === $b) {
            return 'identical';
        }

        $len = min(strlen($a), strlen($b));
        $i = 0;
        while ($i < $len && $a[$i] === $b[$i]) {
            $i++;
        }

        $from = max(0, $i - 60);

        return sprintf(
            "at byte %d\n        before: …%s…\n        after:  …%s…",
            $i,
            str_replace("\n", '⏎', substr($a, $from, 160)),
            str_replace("\n", '⏎', substr($b, $from, 160))
        );
    }

    /** The session key Illuminate's session guard reads for the `customer` guard. */
    public static function customerSessionKey(): string
    {
        return 'login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class);
    }

    /**
     * Realistic storefront data. Deliberately the same shape as
     * StorefrontRouteWalkTest's seed, because the pages are the same pages.
     *
     * @return array<string, mixed>
     */
    public static function seed(\Tests\TestCase $test): array
    {
        $test->seed(\Database\Seeders\DatabaseSeeder::class);
        $test->seed(\Database\Seeders\DemoReviewsSeeder::class);

        $post = Post::create([
            'slug' => 'walk-article',
            'title' => 'Walk Article',
            'body' => '<p>Body copy.</p>',
            'excerpt' => 'An article, so the journal and the root-slug route have one.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        foreach (['delivery', 'refund_returns', 'faqs', 'about', 'contact-us'] as $slug) {
            Page::firstOrCreate(
                ['slug' => $slug],
                ['title' => ucfirst($slug), 'content' => '<p>Placeholder.</p>', 'status' => 'published']
            );
        }

        $customer = Customer::create([
            'name' => 'Ada Shopper',
            'email' => 'walk@example.com',
            'password' => 'password123',
        ]);

        $product = Product::query()->visible()->first();

        $order = Order::create([
            'order_number' => 'WALK00001',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency' => 'AED',
            'subtotal' => 20000,
            'discount_total' => 0,
            'shipping_total' => 2000,
            'fee_total' => 0,
            'gift_fee' => 0,
            'tax_total' => 0,
            'total' => 22000,
            'shipping_method' => 'Standard delivery',
            'payment_method' => 'cod',
            'payment_method_title' => 'Cash on delivery',
            'shipping_address' => [
                'first_name' => 'Ada', 'last_name' => 'Shopper',
                'line1' => '12 Marina Walk', 'city' => 'Dubai',
                'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
            ],
        ]);

        $order->items()->create([
            'name' => $product?->name ?? 'Rice Toner',
            'product_id' => $product?->id,
            'brand' => 'Beauty of Joseon',
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal' => 20000,
            'total' => 20000,
        ]);

        $address = $customer->addresses()->create([
            'first_name' => 'Ada',
            'last_name' => 'Shopper',
            'line1' => '1 Test Street',
            'city' => 'Dubai',
            'country' => 'AE',
        ]);

        /*
         * A CART WITH SOMETHING IN IT, because the two pages with the most
         * interface text on them are both empty-state pages otherwise: /cart/
         * renders "Your bag is empty" and /checkout/ does not render at all, it
         * 302s back to the cart. Neither would have exercised the summary rows,
         * the quantity controls, the free-delivery bar, the four checkout steps
         * or the order block — which is most of this conversion.
         *
         * Two lines rather than one, and one of them on sale, so the struck
         * "was" price and the discount row both draw.
         */
        $cart = \App\Models\Cart::create([
            'token' => 'english-render-walk-cart',
            'currency' => 'AED',
            'status' => 'active',
            'shipping_country' => 'AE',
            'last_activity_at' => now(),
        ]);

        foreach (Product::query()->visible()->take(2)->get() as $line) {
            $cart->items()->create([
                'product_id' => $line->id,
                'quantity' => 2,
                'unit_price' => $line->effectivePrice(),
            ]);
        }

        config(['kbb.health_token' => 'walk-health-token']);
        Setting::updateOrCreate(['key' => 'indexnow_key'], ['value' => 'walkindexnowkey123']);
        Setting::flushMap();

        return compact('post', 'customer', 'product', 'order', 'address', 'cart');
    }

    /**
     * Router URI => how to request it, for every storefront GET route.
     *
     * `render` is true for the pages whose body comes out of a Blade template.
     * Everything else is listed with `render => false` and a reason, so the
     * coverage guard still sees it and nothing can be dropped silently.
     *
     * @param  array<string, mixed>  $seed
     * @return array<string, array{params?: array<string, mixed>, query?: array<string, string>, render: bool, auth?: bool, why?: string}>
     */
    public static function expectations(array $seed): array
    {
        $product = $seed['product'];
        $order = $seed['order'];
        $address = $seed['address'];
        $customer = $seed['customer'];
        $brandSlug = Brand::query()->value('slug');
        $catSlug = Category::query()->value('slug');

        $json = ['render' => false, 'why' => 'JSON, no interface strings in the body'];
        $redirect = ['render' => false, 'why' => 'redirect, no body'];
        $file = ['render' => false, 'why' => 'served off disk / machine-facing file'];

        return [
            // --- machine-facing, never read by a shopper ---------------------
            'up' => $file,
            'sitemap.xml' => $file,
            'robots.txt' => $file,
            'llms.txt' => $file,
            '{key}.txt' => ['params' => ['key' => fn () => \App\Services\Seo\IndexNow::key()]] + $file,
            '_kbb-health' => ['query' => ['token' => 'walk-health-token']] + $json,
            'storage/{path}' => ['params' => ['path' => 'kbb/app.css']] + $file,
            // A developer preview, not a storefront page. See below.
            '_design-check' => ['render' => false, 'why' => 'developer preview, not shopper-facing (see the exclusions list)'],
            'app' => ['render' => false, 'why' => '404 to a shopper; admin-only developer preview'],

            // --- catalogue ---------------------------------------------------
            '/' => ['render' => true],
            'shop' => ['render' => true],
            'shop/page/{page}' => ['params' => ['page' => '2']] + $redirect,
            'product-category/{path}' => ['params' => ['path' => $catSlug], 'render' => true],
            'product/{slug}' => ['params' => ['slug' => $product->slug], 'render' => true],
            'product' => $redirect,
            'quick-view/{id}' => ['params' => ['id' => (string) $product->id], 'render' => true],
            'new-in' => ['render' => true],
            'best-sellers' => ['render' => true],
            'super-sale' => ['render' => true],
            'everything-under-54-aed' => ['render' => true],

            // --- build my routine (Lane FM) ----------------------------------
            /*
             * BOTH 404 IN A SHOP'S DEFAULT STATE, and that is the point rather
             * than a gap. The module ships OFF, this walk seeds a default shop,
             * and with it off the controller answers 404 — so there is no
             * English body here to pin against the base commit. The pages
             * switched ON are covered by BuildMyRoutineTest, which also holds
             * the byte-identical check on every other storefront page in both
             * states. Listing them as `render => true` would pin a 404 page and
             * then go red the day the owner switches the module on, which is
             * the wrong way round.
             */
            'routines' => ['render' => false, 'why' => '404 while the Build my routine module is off, which is how it ships'],
            'routines/{concern}' => [
                'params' => ['concern' => 'acne'],
                'render' => false,
                'why' => '404 while the Build my routine module is off, which is how it ships',
            ],

            // --- brands ------------------------------------------------------
            'korean-skincare-brands' => ['render' => true],
            'korean-skincare-brands/{slug}' => ['params' => ['slug' => $brandSlug], 'render' => true],
            'brands' => $redirect,
            'brand/{slug}' => ['params' => ['slug' => $brandSlug]] + $redirect,

            // --- journal -----------------------------------------------------
            'skincare-guide' => ['render' => true],
            'skincare-guide/{slug}' => ['params' => ['slug' => 'walk-article']] + $redirect,
            '{slug}' => ['params' => ['slug' => 'walk-article'], 'render' => true],
            'blog' => $redirect,
            'post/{slug?}' => ['params' => ['slug' => 'walk-article']] + $redirect,

            // --- editable content pages --------------------------------------
            'privacy-policy' => ['render' => true],
            'terms-and-conditions' => ['render' => true],
            'delivery' => ['render' => true],
            'refund_returns' => ['render' => true],
            'faqs' => ['render' => true],
            'about' => ['render' => true],
            'contact-us' => ['render' => true],

            // --- standalone pages --------------------------------------------
            /*
             * COMPARED AGAIN, because BASE_COMMIT has moved past Lane FB's
             * quiz change.
             *
             * An earlier pass turned this page off, on the reasoning that Lane
             * FB deliberately changed its English — the seventeen invented
             * products, their prices and the bundle saving are gone — so the
             * pre-conversion template renders a page that is SUPPOSED to
             * differ, and the only outcomes were a permanent failure or an
             * exclusion.
             *
             * That was the wrong of the two available answers. The docblock on
             * StorefrontEnglishUnchangedTest sets out what to do when a later
             * lane changes copy on purpose, and it is not to stop looking: read
             * the diff, approve it, and move BASE_COMMIT forward to the commit
             * that carries the change. Turning the page off instead costs the
             * guard for every FUTURE lane that touches /skin-quiz, which is the
             * expensive half and the half nobody would notice had gone.
             */
            'skin-quiz' => ['render' => true],
            'reviews' => ['render' => true],

            // --- cart and checkout -------------------------------------------
            'cart' => ['render' => true],
            'api/cart/drawer' => $json,
            'api/cart/debug' => $redirect,
            'checkout' => $redirect,
            'checkout/success' => ['render' => true],
            'checkout/pending' => $redirect,

            // --- wishlist -----------------------------------------------------
            'my-wishlist' => ['render' => true],
            'wishlist' => $redirect,
            'wishlist/ids' => $json,
            // JSON, not a rendered page -- there is no English in it to hold.
            'cart/address' => $json,

            // --- account -------------------------------------------------------
            'my-account' => ['render' => true],
            'track-my-order' => ['render' => true],
            'my-account/forgot' => ['render' => true],
            'my-account/orders' => ['render' => true, 'auth' => true],
            'my-account/orders/{id}' => ['params' => ['id' => fn () => (string) $order->id], 'render' => true, 'auth' => true],
            'my-account/edit-address' => ['render' => true, 'auth' => true],
            'my-account/edit-address/{id}' => ['params' => ['id' => fn () => (string) $address->id], 'render' => true, 'auth' => true],
            'my-account/verify' => $redirect,
            'newsletter/confirm/{id}' => ['params' => ['id' => '999999'], 'render' => true],
            'newsletter/unsubscribe/{id}' => ['params' => ['id' => '999999'], 'render' => true],
            'mail-preferences/{kind}/{id}' => ['params' => ['kind' => 'stock', 'id' => '999999'], 'render' => true],
            'my-account/verify/{id}/{hash}' => [
                'params' => ['id' => (string) $customer->id, 'hash' => 'not-the-hash'],
                'render' => false,
                'why' => '404, no storefront body',
            ],
            'my-account/reset/{id}/{token}' => [
                'params' => ['id' => (string) $customer->id, 'token' => str_repeat('a1b2c3d4', 8)],
                'render' => true,
            ],

            // --- storefront JSON endpoints -------------------------------------
            'api/search' => ['query' => ['q' => 'serum']] + $json,
            'api/search/starter' => $json,
            'api/human-check' => $json,
            'reviews/captcha' => ['render' => false, 'why' => 'an SVG image'],

            // --- public API ------------------------------------------------------
            'api/products' => $json,
            'api/products/{slug}' => ['params' => ['slug' => $product->slug]] + $json,
            'api/products/{slug}/reviews' => ['params' => ['slug' => $product->slug]] + $json,
            'api/posts' => $json,
            'api/posts/{slug}' => ['params' => ['slug' => 'walk-article']] + $json,
            'api/reviews' => $json,
            'api/settings' => $json,

            // --- catch-all --------------------------------------------------------
            '{fallbackPlaceholder}' => [
                'params' => ['fallbackPlaceholder' => 'no-such-page-at-all'],
                'render' => false,
                'why' => '404 page; rendered below under its own name',
            ],
        ];
    }

    /**
     * The order the emails and the printed documents are a picture of.
     *
     * Deliberately awkward rather than tidy — two lines, a variant, a coupon
     * discount, gift wrapping, a cash-on-delivery surcharge, a gift message and
     * an order note — for the same reason
     * tests/Feature/OrderEmailPreviewsTest.php builds the same shape: a
     * template whose discount row nobody has ever rendered is a template whose
     * discount row nobody has ever checked.
     *
     * Fixed dates and a fixed id, because the documents print both.
     */
    public static function documentOrder(): \App\Models\Order
    {
        $order = Order::create([
            'id' => 10427,
            'order_number' => 'KBB-10427',
            'email' => 'aisha.khan@example.com',
            'phone' => '+971 50 123 4567',
            'status' => 'processing',
            'currency' => 'AED',
            'billing_address' => [
                'first_name' => 'Aisha', 'last_name' => 'Khan',
                'line1' => 'Apartment 1204, Marina Heights', 'city' => 'Dubai',
                'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 50 123 4567',
            ],
            'shipping_address' => [
                'first_name' => 'Noura', 'last_name' => 'Al Mansoori',
                'line1' => 'Villa 7, Street 21', 'line2' => 'Al Barsha South 2',
                'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE',
                'phone' => '+971 55 987 6543',
            ],
            'subtotal' => 46300,
            'discount_total' => 4000,
            'coupon_code' => 'GLOW10',
            'shipping_total' => 2000,
            'fee_total' => 3050,
            'gift_fee' => 1500,
            'is_gift' => true,
            'gift_note' => "Happy birthday, Mama.\nLove from all of us x",
            'customer_note' => 'Please ring the doorbell twice — the buzzer is broken.',
            'tax_total' => 0,
            'total' => 47350,
            'shipping_method' => 'Standard delivery (1–3 working days)',
            'payment_method' => 'cod',
            'payment_method_title' => 'Cash on delivery',
            'paid_at' => '2026-09-14 09:44:00',
            'created_at' => '2026-09-14 09:41:00',
        ]);

        $order->items()->create([
            'name' => 'Rice Daily Moisturizing Toner 150ml',
            'brand' => 'Haruharu Wonder', 'sku' => 'HH-RT-150',
            'quantity' => 2, 'unit_price' => 19900, 'subtotal' => 39800, 'total' => 39800,
        ]);

        $order->items()->create([
            'name' => 'Centella Ampoule',
            'brand' => 'SKIN1004', 'sku' => 'SK-CA-030',
            'variant_attributes' => ['30ml'],
            'quantity' => 1, 'unit_price' => 6500, 'subtotal' => 6500, 'total' => 6500,
        ]);

        return $order->fresh('items');
    }

    /** The shop's own details, so the masthead, the support block and the invoice header all draw. */
    public static function documentSettings(): void
    {
        $settings = app(\App\Services\SettingsService::class);

        foreach ([
            'store_name' => 'K Beauty Bliss',
            'support_whatsapp' => '+971 58 505 2611',
            'support_email' => 'hello@kbeautybliss.com',
            'social_instagram' => 'https://www.instagram.com/kbeauty.bliss/',
            'mail_signature' => "Warmly,\nthe K Beauty Bliss team",
            'invoice_business_name' => 'K Beauty Bliss Trading LLC',
            'invoice_address' => "Office 1902, Burlington Tower\nBusiness Bay, Dubai\nUnited Arab Emirates",
            'invoice_trn' => '100123456700003',
            'invoice_email' => 'info@kbeautybliss.com',
            'invoice_phone' => '+971 58 505 2611',
            'invoice_website' => 'kbeautybliss.com',
            'invoice_footer' => "Payment received in full — no further amount is due.\nReturns accepted within 14 days on unopened items.",
        ] as $key => $value) {
            $settings->set($key, $value);
        }

        \App\Models\Setting::flushMap();
    }

    /** Every storefront GET route URI the router has registered. */
    public static function registeredUris(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if ($uri === 'admin' || str_starts_with($uri, 'admin/') || str_starts_with($uri, 'admin-api/')) {
                continue;
            }

            $uris[] = $uri;
        }

        return array_values(array_unique($uris));
    }

    /** Turn a router URI plus parameters into a requestable path. */
    public static function pathFor(string $uri, array $spec): string
    {
        $params = $spec['params'] ?? [];

        $path = preg_replace_callback('/\{([a-zA-Z_]+)\??\}/', function ($m) use ($params, $uri) {
            $value = $params[$m[1]] ?? null;
            $value = $value instanceof Closure ? $value() : $value;

            if ($value === null) {
                throw new RuntimeException("No parameter '{$m[1]}' given for route '{$uri}'.");
            }

            return (string) $value;
        }, $uri);

        $path = '/' . ltrim($path, '/');

        if (! empty($spec['query'])) {
            $path .= '?' . http_build_query($spec['query']);
        }

        return $path;
    }

    /**
     * Mask the handful of values that genuinely differ between two renders of
     * the SAME template, so the comparison is about the words and not about a
     * token.
     *
     * KEPT AS NARROW AS IT IS DELIBERATELY. Every entry below is proved
     * necessary AND proved sufficient by the control pass in the test: the same
     * views are rendered twice and the two must be identical AFTER masking. If
     * an entry here were unnecessary the control would pass without it; if the
     * set were incomplete the control would fail. And the mutation check in the
     * same file proves the masking is not so broad that it swallows a real
     * change to the words.
     */

    /**
     * The two paragraphs whose INDENTATION changed, and nothing else.
     *
     * Both were prose written across four or five lines of template with a
     * <b> run or a link in the middle of the sentence. A sentence cut into
     * "before the link" and "after the link" is the one shape a translator
     * cannot reorder, and Arabic reorders — so each is now a single __() with
     * the markup passed in as a placeholder. The words are identical, the tags
     * are identical, and the line breaks and leading spaces that used to sit
     * between them are gone: HTML collapses them to one space either way, so
     * nothing a reader sees has moved.
     *
     * Expressed as a rule over the ELEMENT rather than as a wall of literal
     * text, so it cannot silently stop matching when the shop URL in the middle
     * of the second one changes. Each rule is required to fire exactly once.
     *
     * @return array<string, string> a name => the pattern that selects the element's inner text
     */
    public static function approvedReflows(): array
    {
        return [
            // resources/views/store/review-wall.blade.php, the empty state
            'review wall: empty state body' => [
                'pattern' => '#(<div class="sr-empty">\s*<h2>[^<]*</h2>\s*<p>)(.*?)(</p>)#s',
                'hits' => 1,
            ],
            // resources/views/store/review-wall.blade.php, the "not built" note
            'review wall: what is not built' => [
                'pattern' => '#(<p class="sr-unbuilt">)(.*?)(</p>)#s',
                'hits' => 1,
            ],
            /*
             * resources/views/store/newsletter/unsubscribe.blade.php and
             * resources/views/store/mail-preferences/confirm.blade.php — the same
             * shape of note on two pages, two or three lines of template each and
             * now one pair of sentences.
             */
            'newsletter unsubscribe: the note under the button' => [
                'pattern' => '#(<p class="muted" style="margin-top:16px;font-size:13px;">)(\s*Order confirmations,.*?)(</p>)#s',
                'hits' => 1,
            ],
            'email preferences: the note under the button' => [
                'pattern' => '#(<p class="muted" style="margin-top:16px;font-size:13px;">)(\s*This stops both.*?)(</p>)#s',
                'hits' => 1,
            ],
        ];
    }

    /** One element's inner text, with its template indentation collapsed away. */
    public static function collapseInner(string $html, string $pattern, int &$hits): string
    {
        return (string) preg_replace_callback($pattern, function (array $m) use (&$hits): string {
            $hits++;

            return $m[1] . trim(preg_replace('/\s*\n\s*/', ' ', $m[2])) . $m[3];
        }, $html);
    }

    /**
     * The approved differences in the EMAILS and the printed documents.
     *
     * Same rule as the pages: applied to the BEFORE side, counted, and each one
     * has to keep matching or it is removed.
     *
     * All of them are REFLOWS: paragraphs written across two to four lines of
     * template with a link or an @if in the middle of the sentence. Each is now
     * one __() with the markup passed in as a placeholder, so the line breaks
     * between the words are gone. HTML collapses them to a single space either
     * way, so nothing a reader sees has moved.
     *
     * There is no escaping entry here, and there was: the seven sentences on
     * this shop that contain an apostrophe would have gained an &#039; from
     * Blade's {{ }}. App\Support\Phrase::inline() is why they did not.
     *
     * @return array<string, array{pattern: string, with: string, hits: int}>
     */
    public static function approvedDocumentDifferences(): array
    {
        return [
            // emails/order-confirmation.blade.php — the receipt's opening line
            'confirmation lead' => [
                'pattern' => '/Everything you chose is listed below,\s+exactly as it was when you ordered/',
                'with' => 'Everything you chose is listed below, exactly as it was when you ordered',
                'hits' => 1,
            ],
            // emails/order-confirmation.blade.php + emails/order-status.blade.php
            'device note: before the link' => [
                'pattern' => '/Anywhere else,\s+<a href=/',
                'with' => 'Anywhere else, <a href=',
                'hits' => 3,
            ],
            'device note: after the link (confirmation)' => [
                'pattern' => '/<\/a>\s+and your orders are all listed there under/',
                'with' => '</a> and your orders are all listed there under',
                'hits' => 1,
            ],
            'device note: after the link (status)' => [
                'pattern' => '/<\/a>\s+and look for/',
                'with' => '</a> and look for',
                'hits' => 2,
            ],
            // emails/layout.blade.php — the merchant alert's own footer
            'merchant alert footer' => [
                'pattern' => '/It goes to the address set under Store → Mail,\s+and you can switch it off/',
                'with' => 'It goes to the address set under Store → Mail, and you can switch it off',
                'hits' => 1,
            ],
            // emails/order-refunded.blade.php — the manual-refund paragraph
            'refund: arranged by hand' => [
                'pattern' => '/so we will\s+arrange the money with you directly\. If you have not heard from us, reply to this message\s+and we will sort it out\./',
                'with' => 'so we will arrange the money with you directly. If you have not heard from us, reply to this message and we will sort it out.',
                'hits' => 1,
            ],
        ];
    }

    /**
     * Apply a set of approved differences to the BEFORE side and report how
     * often each one fired, so a rule that has stopped matching is a failure
     * rather than a silent no-op.
     *
     * @param  array<string, array{pattern: string, with: string, hits: int}>  $rules
     * @param  array<string, string>  $documents
     * @return array<string, array{expected: int, actual: int}>
     */
    public static function applyApproved(array $rules, array &$documents): array
    {
        $report = [];

        foreach ($rules as $name => $rule) {
            $fired = 0;

            foreach ($documents as $key => $body) {
                $count = 0;
                $documents[$key] = (string) preg_replace($rule['pattern'], $rule['with'], $body, -1, $count);
                $fired += $count;
            }

            $report[$name] = ['expected' => $rule['hits'], 'actual' => $fired];
        }

        return $report;
    }

    public static function mask(string $html): string
    {
        // Laravel's per-session CSRF token: 40 chars of Str::random.
        $html = preg_replace('/(name="_token" value=")[A-Za-z0-9]{40}(")/', '$1TOKEN$2', $html);
        $html = preg_replace('/(<meta name="csrf-token" content=")[A-Za-z0-9]{40}(")/', '$1TOKEN$2', $html);
        // The same token again, handed to the front-end script as JSON.
        $html = preg_replace('/("csrf":")[A-Za-z0-9]{40}(")/', '$1TOKEN$2', $html);
        // The sign-up sum's one-time token and its question, issued per render.
        $html = preg_replace('/(name="hc_token" value=")[^"]*(")/', '$1TOKEN$2', $html);
        $html = preg_replace('/(data-hc-q>)[^<]*(<)/', '$1SUM$2', $html);

        return $html;
    }
}
