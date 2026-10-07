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
    /*
     * MOVED FORWARD for the phone-only floating Place order bar, which the
     * checkout now renders where it did not before.
     *
     * The owner asked for it in as many words: "the Place order should float
     * only when on page place order disappear by scroll". A bar that only ever
     * appeared for a shop that had found `mobile_sticky_bar` on a different
     * screen would not be that, so the default draws it and the new control on
     * Appearance -> Checkout page -> Mobile - Layout turns it off.
     *
     * The diff this test printed was that and nothing else -- ONE page,
     * (with a basket) /checkout, at byte 57313, `<div class="mpbar">` where
     * the slim footer used to follow the form directly, and no other page in
     * the walk moved a byte. Read before advancing, because a base commit
     * moved forward over an unread diff is a guard switched off.
     */
    /*
     * MOVED FORWARD for the slim footer's content width, 1040 -> 1240.
     *
     * Not a tidy-up: the owner chose "Spread to both edges" for the desktop
     * bar, and at 1040 the content did not fit on one line -- measured, the
     * bar was 82.3px tall with the links and the arrow wrapped onto a second
     * row. At 1240 it is 55px and one line, which is the shape he picked.
     *
     * The diff this test printed was that and nothing else -- ONE page,
     * (with a basket) /checkout, at byte 8759, inside the footer's own
     * `--sf-max` fallback. The cart page does not carry this footer (cart_on
     * ships off), and no other page in the walk moved a byte.
     */
    /*
     * MOVED FORWARD for the checkout footer wearing the site header's own
     * wordmark, and for the spacing controls beside it.
     *
     * The owner asked twice for the header's logo in the footer: it was drawing
     * a flat "K-BEAUTY BLISS" text box, and now reads Appearance -> Header's
     * Wordmark, Accent word and colours live.
     *
     * The diff this test printed was that and nothing else -- ONE page,
     * (with a basket) /checkout, at byte 8749, inside the footer's own inline
     * stylesheet. The cart page does not carry this footer (cart_on ships off),
     * and no other page in the walk moved a byte.
     */
    /*
     * MOVED FORWARD for the footer lining up with the page and its links
     * moving under the wordmark, and for the floating bar's readiness gate.
     *
     * The diff this test printed was ONE page, (with a basket) /checkout, at
     * byte 10446 -- the footer's own inline stylesheet and the links block
     * moving inside .sf-brand. No other page in the walk moved a byte; the cart
     * page does not carry this footer, cart_on ships off.
     */
    /*
     * MOVED FORWARD for the back-to-top arrow, which now waits until the page
     * has been scrolled and is no longer treated as a ruled row.
     *
     * The diff this test printed was ONE page, (with a basket) /checkout, at
     * byte 16152 -- the footer's own inline stylesheet. No other page in the
     * walk moved a byte.
     */
    /*
     * MOVED FORWARD for the footer's top and bottom padding becoming two
     * controls instead of one.
     *
     * The owner asked for it: "the mobile checkout footer there's no control
     * for inside footer block top padding". The bar now reads --sf-padt /
     * --sf-padb, each declared as var(--sf-pady) so a bar nobody has touched
     * computes the same padding it computed yesterday; the service emits the
     * split pair only once one of the two has been moved off the shared value.
     *
     * The diff this test printed was that and nothing else -- ONE page,
     * (with a basket) /checkout, at byte 8753, inside the footer's own inline
     * stylesheet: the two new custom properties, their comment, and
     * `padding:var(--sf-pady) var(--sf-padx)` becoming
     * `padding:var(--sf-padt) var(--sf-padx) var(--sf-padb)`. No other page in
     * the walk moved a byte. The header's side-padding control that shipped in
     * the same commit lives in resources/css/kbb/kbb-checkout.css, which this
     * walk does not read, so it is not in this diff by construction.
     */
    /*
     * MOVED FORWARD for the skinned grid refusing to offer an Add to cart it
     * cannot honour — and this one is a CHANGE THE OWNER ASKED FOR rather than
     * a side effect, so it is called out here as well as in the commit.
     *
     * components/product-grid.blade.php drew `Add to cart` on every tile. It
     * had no copy of the guard components/product-card.blade.php has carried
     * since it was written, so the skinned grid armed the button for a VARIABLE
     * product — which CartService::add() then priced at AED 0, because a
     * variable parent has no price of its own — and for a SOLD-OUT one, which
     * the server answered with "That product is sold out." Both tiles now read
     * the one expression, Product::isDirectlyBuyable().
     *
     * The diff this test printed was ONE page, /korean-skincare-brands/{slug},
     * at byte 27310, and one element on it:
     *
     *   before: <span class="kbb-card-cart" data-kbb-add="19"
     *                 data-price="127.00" data-name="Zinc Sunscreen SPF50+">Add to cart</span>
     *   after:  <span class="kbb-card-cart">View product</span>
     *
     * That product is the walk's sold-out one — DemoCatalogueSeeder marks every
     * ninth `outofstock` — so the byte that moved is the second case above, not
     * the first: this walk seeds no variable products at all. No other page in
     * the walk moved a byte. `data-price` and `data-kbb-add` go with the label
     * deliberately: the first carried the zero, and the second is what cart.js
     * binds and what MarketingPixels counts an add on.
     *
     * The whole tile is already wrapped in a link to the product page, so
     * nothing became unreachable; `View product` is the word product-card has
     * always used for this case, so no new string was introduced either.
     *
     * ── ADVANCED AGAIN BY LANE Q, AND FOR EXACTLY ONE PAGE ─────────────────
     *
     * skin-quiz, and the whole of the change is INSIDE its inline script. The
     * quiz gained a second hand-off destination: /concern/{slug}/, which unlike
     * the routine pages is not behind the build_my_routine module, so it is the
     * only way out of the quiz the shipped shop can ever offer. The script grew
     * pickFrom() and concernPick(), and routineLinkHTML() grew a second branch.
     *
     * NOTHING A SHOPPER SEES MOVED, and that was measured rather than asserted.
     * The rendered page was compared against the base commit with every
     * <script> block removed and the two are IDENTICAL -- 15,349 bytes outside
     * the scripts on both sides, six script blocks on both sides. The new table
     * is emitted only when a concern page actually exists, nothing in this
     * repository is tagged for any concern, and the shipped document contains
     * neither `window.KBB_CONCERN_PAGES = ` nor the string `/concern/`.
     * tests/Feature/QuizConcernHandoffTest.php's first case fetches all of it.
     *
     * No other page in the walk moved a byte.
     *
     * ── ADVANCED AGAIN, FOR ONE PAGE AND ONE DECLARATION ──────────────────
     *
     * `{slug}`, the article page, and the whole of the change is this, in its
     * inline stylesheet:
     *
     *     .abody img{max-width:100%;height:auto}
     *
     * plus the CSS comment above it explaining why. Nothing else in the walk
     * moved a byte, and the failure message named exactly this one page and this
     * one byte offset before the pin was touched.
     *
     * THE RULE DID NOT EXIST AT ALL, and a plain `<img>` in an article body
     * therefore ran off the page: measured at scrollWidth 1220 against a 390px
     * viewport and 1500 against 1280 -- a sideways scrollbar on every article
     * carrying a photograph, at every width. With the declaration: 390 and 1280,
     * the image rendering 350x233 and 680x453.
     *
     * The image used for that measurement is one `RichText::clean()` passes
     * through BYTE-IDENTICALLY before and after Lane U4's sanitiser change, so
     * the overflow is a pre-existing defect on this page and not a consequence
     * of that lane's work.
     *
     * WHY IT WAS NEVER SEEN. `posts` is empty on a fresh shop, and the one thing
     * that fills it -- the import -- was itself removing every `<picture>` block
     * before it reached the column, because libxml parses `<source>` as a
     * container and DROP_WHOLE took the subtree with it. Both halves changed in
     * the same release, so the first article to arrive with a photograph in it
     * would have been the first one to overflow.
     *
     * `height:auto` is half the declaration and not decoration: WordPress writes
     * `width=` and `height=` attributes on an imported `<img>`, and constraining
     * the width alone against a fixed height attribute squashes the picture
     * rather than scaling it.
     */
    /*
     * ── MOVED AGAIN — LANE W1 (the site width system) ──────────────────────
     *
     * SIX PAGES MOVED, THIRTY-FIVE DID NOT, AND THE DIFF WAS READ PAGE BY PAGE
     * BEFORE THIS CONSTANT WAS TOUCHED.
     *
     * The change that lane exists for — one site width of 1680px, and a product
     * grid whose column count is derived from the row it has rather than from a
     * viewport breakpoint — is ENTIRELY IN resources/css/kbb/*.css, and this walk
     * rolls resources/views back and renders. So it cannot see the width change
     * at all, and every byte that moved below is a view change, each of them a
     * declaration in an inline <style> or an attribute on a control:
     *
     *   shop                    the column selector's default `class="on"` on the
     *                           4 button, and `data-cols="4"` on the grid. Both
     *                           are now emitted only when the SHOPPER has chosen.
     *                           Facets::columns() answers '4' whether or not
     *                           `?cols` is in the URL, and `data-cols` is a PIN,
     *                           so every visitor was pinned at four columns at
     *                           every screen size including 1680 and 2560 — which
     *                           is the thing the owner asked to have removed. A
     *                           highlighted "4" above a five-column grid is the
     *                           control lying about the page, so the highlight
     *                           follows the same fact.
     *
     *   product-category/{path} the same page template, the same two attributes.
     *
     *   korean-skincare-brands  `.brw{max-width:1180px;margin:0 auto;padding:22px
     *                           18px 60px}` became the shared token and the shared
     *                           gutter. One declaration and its comment.
     *
     *   korean-skincare-brands/{slug}
     *                           <x-product-grid> stopped emitting
     *                           `style="--kbb-cols:4;--kbb-cols-m:2"`. Nothing
     *                           reads either name any more, and neither reached
     *                           four of the five product grids when it did.
     *
     *   skincare-guide          `.wrap{max-width:1160px;margin:0 auto;padding:0
     *   {slug} (an article)     20px}` became the shared token in both standalone
     *                           documents. One declaration and its comment each.
     *
     * NOT ONE BYTE OF SHOPPER-VISIBLE COPY MOVED on any of the six. Four are
     * inside an inline stylesheet; two are attributes on the shop's own control.
     *
     * ▲ AND THIRTY-FIVE PAGES THAT NEARLY MOVED FOR NOTHING. The new
     * <style id="kbb-layout"> block, written the obvious way with each directive
     * on its own line, added FOUR BLANK LINES to the <head> of thirty-five
     * storefront pages — for a block that emits nothing at all on a shop at its
     * defaults. The whitespace was the entire diff. Advancing this constant there
     * would have been advancing it for nothing and would have buried the six real
     * changes in thirty-five fake ones, which is exactly the shape rule 1's
     * instrument exists to prevent. It is zero now: PHP eats a newline
     * immediately after `?>`, so the raw-PHP block and the conditional both close
     * at end of line, and the opening directive shares a line with the comment
     * above it and with the tag it guards. Rearranging it back costs four blank
     * lines on every page of the shop, and the comment at the site says so.
     *
     * The commit named below is reachable from this branch and stays reachable,
     * per the note above about rebases rewriting every SHA behind this constant.
     */
    /*
     * ── MOVED AGAIN — LANE URL (the address scheme) ────────────────────────
     *
     * FIVE PAGES MOVED, THIRTY-SIX DID NOT, AND THE DIFF WAS READ PAGE BY PAGE
     * BEFORE THIS CONSTANT WAS TOUCHED. Every byte of it is an ADDRESS. Not one
     * word of shopper-visible copy changed on any page of the shop:
     *
     *   /                       the Journal link in the routine strip,
     *                           href="/skincare-guide/" -> href="/blog/".
     *
     *   brands                  each directory tile,
     *                           href="/korean-skincare-brands/anua/" ->
     *                           href="/brands/anua/".
     *
     *   brands/{slug}           the breadcrumb's "Brands" crumb, the same move.
     *
     *   blog                    the standalone document's own nav,
     *   blog/{slug}             href="/skincare-guide/" -> href="/blog/", once
     *                           on each. Both documents are also reached at a
     *                           new URI, which is why they are listed under
     *                           `blog` and `blog/{slug}` rather than
     *                           `skincare-guide` and `{slug}`.
     *
     * ▲ AND THE CATEGORY ARCHIVE IS NOT IN THAT LIST, which is worth stating
     * because it moved further than any of them. This walk rolls
     * resources/views back and renders; the archive's own links come from
     * Category::url(), which is PHP, so the old views render the new addresses
     * and the page is byte-identical. The archive's move is pinned by
     * UrlSchemeTest against the router instead, which is where it can be seen.
     *
     * The commit named below is this lane's own and is reachable from this
     * branch, per the note above about rebases rewriting every SHA behind this
     * constant.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * ── MOVED AGAIN — LANE PG (one product card, five columns, hidden
     *    filters). THIRTY-THREE PAGES MOVED AND EIGHT DID NOT, and every one
     *    of the thirty-three was read before this constant was advanced.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * The owner asked for three things in one sentence — the reference card
     * everywhere, five columns on a desktop and two on a phone, and the filter
     * rail shut by default — and all three are rule-1 exceptions: defaults he
     * asked for in as many words. Each is called out in its own commit.
     *
     * The COLUMN COUNT is invisible to this walk, exactly as Lane W1's width
     * change was: it is one number in `resources/css/kbb/kbb.css`, and this
     * walk rolls `resources/views` back and renders. Nothing below is about it.
     *
     * ── (a) TWENTY-ONE PAGES WITH NO PRODUCT GRID ON THEM, two lines each ───
     *
     *     privacy-policy, terms-and-conditions, delivery, refund_returns,
     *     faqs, about, contact-us, cart, checkout/success, my-wishlist,
     *     my-account, my-account/forgot, my-account/orders,
     *     my-account/orders/{id}, my-account/edit-address,
     *     my-account/edit-address/{id}, my-account/reset/{id}/{token},
     *     track-my-order, newsletter/confirm/{id},
     *     newsletter/unsubscribe/{id}, mail-preferences/{kind}/{id},
     *     and both (with a basket) variants of /cart and /checkout
     *
     * layouts/store.blade.php emits the quick-view style block on EVERY page,
     * and it opened with two rules naming the card that no longer exists:
     *
     *     before   <style>⏎.pc .ph{position:relative}⏎/* RTL-PHYSICAL…
     *              .pc:hover .qv-btn,.qv-btn:focus-visible{…}
     *     after    <style>⏎/* RTL-PHYSICAL…
     *              .kbb-tile:hover .qv-btn,.qv-btn:focus-visible{…}
     *
     * The hover selector could NOT be left alone — with `.pc:hover` the
     * quick-view button would never have appeared on any tile again — so these
     * pages move whatever is done. The positioning rule is deleted rather than
     * renamed because kbb.css already declares it. Not one other byte moved on
     * any of the twenty-one.
     *
     * ── (b) TEN PAGES THAT DRAW A PRODUCT GRID ─────────────────────────────
     *
     *     /                        the four homepage rails
     *     new-in, best-sellers, super-sale, everything-under-54-aed
     *     my-wishlist              (also in (a); it moves for both reasons)
     *     brands/{slug}            a brand page's popular grid
     *     product/{slug}           the "you may also like" rail
     *     brands                   the brand DIRECTORY, which draws no product
     *                              grid at all — it is in (a)'s list too and
     *                              moves only by those two lines. `.brw-grid`
     *                              lists BRANDS, not products, and is untouched.
     *
     * The tile itself:
     *
     *     before   <a class="kbb-card" href="/product/…/">   (the skinned card)
     *     before   <div class="pc">                          (the /shop card)
     *     after    <div class="kbb-card kbb-tile">           (the one card)
     *
     * The root is a <div> because the tile now holds a real `?add-to-cart=`
     * link and two buttons, and `<a>` inside `<a>` is the one nesting the HTML
     * parser breaks apart. Within the tile: the name is a `.cn` link wrapping
     * `.kbb-card-nm`, an unreviewed product has NO `.kbb-card-rate` row at all
     * (it drew five hollow stars and "(0)" — the defect the owner reported),
     * the theme's sale badge is the reference grid's `-N%` pill instead of the
     * old `-N% OFF` label, and the homepage rails gained the wishlist heart,
     * the quick-view button and Product Labels support they never had.
     *
     * ── (c) /shop AND collections/{path} ───────────────────────────────────
     *
     * The tile change above, plus two more, both of them the filter default:
     *
     *     before   <body class=" dvs-ticks"
     *     after    <body class="filters-hidden dvs-ticks"
     *
     *     before   <div class="grid" id="grid">
     *     after    <div class="grid kbb-pgrid" id="grid" data-skin="classic">
     *
     * and the two filter buttons gained a `document.cookie` write beside the
     * class they toggle, plus a `.fcount` badge when filters are applied. The
     * category archive's tiles also gained the category's name as the eyebrow
     * line, which is a caller's string and costs no query.
     *
     * ── (d) THE EIGHT THAT DID NOT MOVE, and why that is the useful half ────
     *
     * Every page carrying its own <html> rather than layouts/store: the
     * Journal, an article, the review wall, the skin quiz, and the four
     * standalone documents. None of them draws a product grid and none of them
     * emits the quick-view block, so none of them moved — which is the evidence
     * that (a) really is the style block and not something wider.
     *
     * ▲ ADVANCED AGAIN WITHIN THE SAME LANE, FOR THE OWNER'S FOLLOW-UP.
     *
     * He sent a second screenshot ("i need the same, with square image
     * thumbnail… by default 5 columns will be there and hidden filter sidebar
     * by default") and TWO pages moved for it, by exactly one element each:
     *
     *     shop, collections/{path}
     *         before   …rx="1"/></svg></button>⏎                </div>
     *         after    …rx="1"/></svg></button>⏎                    <button
     *                  type="button" data-c="5" title="5 columns">…
     *
     * The column switcher offered 2, 3 and 4 while the grid derives five, so
     * every position on it was a step down from the page the shopper was
     * already looking at. It offers five now. Nothing else on either page
     * moved, and no other page moved at all — the square thumbnail is a
     * stylesheet fallback (1/1.02 → 1/1) and this walk rolls `resources/views`
     * back and renders, so it is invisible here exactly as the column count
     * was.
     */
    /*
     * ── MOVED AGAIN — LANE SF (the set's contents into the buy column) ─────
     *
     * ONE PAGE MOVED, AND BY WHITESPACE ONLY. The diff was read before this
     * constant was touched, and it is this, in its entirety:
     *
     *   product/{slug}   at byte 42926
     *     before:  </div>⏎        ⏎                ⏎        <div class=" stockline out">
     *     after:   </div>⏎        ⏎        ⏎        ⏎                ⏎        <div class=" stockline out">
     *
     * Two blank lines of indentation between two block elements. NOT ONE WORD
     * of shopper-visible copy changed, no attribute changed, no element moved.
     *
     * WHERE IT COMES FROM. This lane moved one `@include` from the foot of
     * store/product.blade.php into the buy column, where the quantity-bundle
     * strip used to be. On a product that is NOT a set the panel renders
     * nothing at all -- which is why the only trace of the move on an ordinary
     * product page is the newline the include's own line contributes, lost at
     * the foot and gained in the buy column.
     *
     * WHY THE PIN MOVED RATHER THAN THE TEMPLATE. Making this byte-neutral
     * would mean writing the directive hard against the `@endif` above it,
     * and CLAUDE.md records that a directive written hard against a closing
     * directive is not compiled at all. Two blank lines between block elements
     * is not worth that risk on the page every product in the catalogue is.
     *
     * WHAT A SET'S OWN PAGE DOES is not in this walk at all: the seeders make
     * no sets, so `Product::query()->visible()->first()` is an ordinary
     * product. The set page's change -- which is large, and deliberate, and
     * what the owner asked for -- is pinned by SetBuyColumnTest instead, by
     * position rather than by bytes.
     *
     * The commit named below is this lane's own and is reachable from this
     * branch, per the note above about rebases rewriting every SHA behind this
     * constant.
     */
    /*
     * ── ADVANCED ONCE MORE, AT THE MERGE OF THE TWO PINS ABOVE ────────────
     *
     * Lane PG and Lane SF each advanced this constant, on separate branches,
     * for the separate sets of pages each of them moved. Merging the two put
     * both blocks of prose above this line and left ONE value to choose, and
     * neither lane's own commit is the right one: PG's views do not contain
     * SF's include move, and SF's do not contain PG's one card. Whichever of
     * the two was kept, the walk would re-report the OTHER lane's already-read,
     * already-approved diff as though it were new.
     *
     * SO THE PIN SITS AT THE MERGE, which is the first commit that contains
     * both. It was not chosen blind: the walk was run at the merge with PG's
     * value still in place, and it reported EXACTLY ONE page, which is exactly
     * the one SF's block above describes, to the byte --
     *
     *   product/{slug}   at byte 42926
     *     before:  </div>⏎        ⏎                ⏎        <div class=" stockline out">
     *     after:   </div>⏎        ⏎        ⏎        ⏎                ⏎        <div class=" stockline out">
     *
     * -- and nothing else. Not one of the thirty-three pages PG's own advance
     * listed came back, which is the useful half: it says the merge took both
     * lanes' view changes intact rather than dropping one side's. The two
     * blocks of prose above are kept in full because they, not this value, are
     * the record of what moved and why.
     */
    /*
     * ── ADVANCED AGAIN, FOR ONE LINE THE INTEGRATOR ADDED ──────────────────
     *
     * The related rail on a product page gained `kbb-pgrid` and a `data-skin`
     * so that it answers Appearance -> Product styles -> Grid skin like the
     * other four grids do. Lane PG could not make that change itself --
     * store/product.blade.php was another lane's for the round -- and reported
     * it as optional polish, which it is: the rail renders correctly without
     * it. It was taken because the whole point of the round is that every grid
     * on the shop is now ONE grid, and a related rail that ignores the skin
     * setting is the last place that is not true.
     *
     * The walk was run at the merge and reported EXACTLY that, one page, one
     * byte range, no attribute of substance moved:
     *
     *   product/{slug}   at byte 50657
     *     before:  <h2>You may also like</h2>⏎    <div class=" rel" id="related">
     *     after:   <h2>You may also like</h2>⏎    <div class=" rel kbb-pgrid"
     *              data-skin="classic" id="related">
     *
     * -- and nothing else. Not one of the pages the two blocks above list came
     * back, which is what says the merge and this edit both took cleanly.
     */
    /*
     * ADVANCED BY LANE AD, and here is the diff it was advanced for.
     *
     * The previous pin rendered a <body> whose style attribute was
     *
     *     --kbb-name-lines:99;--kbb-name-min:0;--dv-col:#C13E63;...
     *
     * because layouts/store.blade.php emitted ProductStyles::cardVariables()
     * and nothing else. It now emits ::cssVariables() and ::bodyClass(), so it
     * is
     *
     *     --kbb-radius:14px;--kbb-ratio:1/1;--kbb-sale:#E23B57;
     *     --kbb-new:#1F9D55;--kbb-price:#2A2228;--kbb-star:#E8A33D;
     *     --kbb-cart-bg:#E0567B;--kbb-cart-fg:#FFFFFF;
     *     --kbb-name-lines:99;--kbb-name-min:0;--dv-col:#C13E63;...
     *
     * THE CLASS ATTRIBUTE IS BYTE-IDENTICAL and that is the half worth saying:
     * all seven "what the card shows" toggles ship on, so bodyClass() is the
     * empty string and no `.pc-no*` class appears. NO OTHER BYTE OF ANY PAGE
     * CHANGED - the diff was read page by page before this line moved, and it
     * was this attribute on every one of them and nothing else.
     *
     * AND NO PIXEL MOVED EITHER, which is the point of the change. Every
     * property written here is the value kbb-grid-skins.css was ALREADY falling
     * back to (14px, 1/1, #E23B57, #1F9D55, #2A2228, #E8A33D, #E0567B, #FFFFFF)
     * - it has declared var(--kbb-radius,14px) and the rest for releases while
     * nothing wrote them. ProductStylesReachTheShopTest reads those fallbacks
     * out of the stylesheet and asserts the schema still ships them, so a later
     * lane changing one without the other is red there rather than here.
     *
     * The reason the attribute exists at all: twenty controls on Appearance ->
     * Product styles moved no byte of the shop, because the only caller of
     * cssVariables() and bodyClass() was the admin preview.
     *
     * ── MOVED AGAIN FOR LANE AR (the wordmark, and RTL bidi) ───────────────
     *
     * ONE ELEMENT, ON EVERY PAGE THAT DRAWS THE HEADER, AND THE DIFF WAS READ
     * BEFORE THIS LINE MOVED. Every reported page's entire diff is:
     *
     *   before:  <a class="logo" href="/">K-Beauty<span>Bliss</span></a>
     *   after:   <a class="logo" href="/"><bdi>K-Beauty<span>Bliss</span></bdi></a>
     *
     * and nothing else — same byte offset shape on each, no second hunk on any
     * page, no change to any other element, attribute or whitespace run. The
     * same wrapper went onto the seven other templates that draw the wordmark
     * (the slim header, the drawer, the footer, the checkout, the order-received
     * page and the two standalone blog layouts).
     *
     * WHY IT HAD TO MOVE. At 390px with the mirrored layout on, the header read
     * `BlissK-Beauty`. `.logo` is given `display:inline-flex` by the 44px
     * touch-target rule, which turns `K-Beauty` into an anonymous flex item and
     * the accent `<span>` into a second one; flex lays items along the inline
     * axis IN THE DOCUMENT'S DIRECTION and is not bidi, so `dir="rtl"` reversed
     * the pair. `<bdi>` leaves the container one item, with nothing to reorder.
     *
     * AND NO PIXEL MOVED ON THE ENGLISH SHOP, which is why this is a repin and
     * not a revert. `<bdi>` carries `unicode-bidi: isolate` and no box of its
     * own; in a left-to-right document it isolates a run that was already in
     * document order, so it is inert by construction. The photographs either
     * side of it agree: eleven of the fourteen English pages in
     * docs/lane-ar-shots/ are byte-identical across two passes, and the three
     * that are not differ by the live dispatch countdown and one hairline of
     * anti-aliasing at a mean of 0.0011 of full scale.
     *
     * Tests\Feature\WordmarkSurvivesRtlTest is the assertion this constant
     * cannot make for its own move: it pins the shape on all eight templates
     * and is red on any one of them losing the wrapper.
     */
    /*
     * ADVANCED BY LANE WAL, and the diff it was advanced for is one <span>.
     *
     * The shop printed "Apple Pay" in the footer, in the basket and on the
     * product page, unconditionally, on a build that had no Apple Pay anywhere
     * — no gateway class, no button with a listener behind it, no way to take
     * the payment. Those chips are now drawn only when the shop can actually
     * take the payment (App\Services\Payments\Wallets), and both wallet
     * switches ship OFF, so on a shop that has not switched one on the chip is
     * gone. That is the change, and it is the one the owner asked for in as
     * many words: "the mark art stops lying".
     *
     * THE DIFF WAS READ PAGE BY PAGE BEFORE THIS LINE MOVED. On every page
     * carrying the footer, and on the product page and the basket, it is
     * exactly this and nothing else:
     *
     *   before: …<span>Mastercard</span><span>Apple Pay</span><span>COD</span>…
     *   after:  …<span>Mastercard</span><span>COD</span>…
     *
     * Not a space, not an indent, not an attribute. The comment that now
     * stands above each of those three rows closes onto the markup — `--}}<div`
     * — precisely so that moving the names into PHP moved no whitespace; the
     * first attempt left a blank line on eleven pages and was corrected rather
     * than approved.
     *
     * ── AND ONE PAGE WHERE MORE THAN A SPAN CHANGED ───────────────────────
     *
     * /checkout, where two buttons were deleted. What stood there was
     *
     *   <div class="express" aria-hidden="true">
     *     <button type="button" class="xbtn xapple" tabindex="-1"> Apple&nbsp;Pay</button>
     *     <button type="button" class="xbtn xgoogle" tabindex="-1">…&nbsp;Pay</button>
     *   </div>
     *   <div class="ordiv">or pay with</div>
     *
     * drawn on every checkout, on every browser, with no listener behind them
     * and no gateway behind that — `aria-hidden` and `tabindex="-1"` kept a
     * screen reader and a keyboard away from them, which is a fair description
     * of what they were. In their place is
     * partials/checkout/express-wallets, which renders NOTHING while the
     * wallets are off — so the payment step of a shop that has not switched one
     * on is the payment step it always had, minus the two buttons that did
     * nothing. The "or pay with" divider moved inside the partial with them,
     * because a divider is a claim that there is something above it.
     *
     * Nothing else on any page moved. tests/Feature/WalletPaymentsTest.php
     * pins both halves from the other end: no `.xbtn` anywhere, and the
     * express row present, hidden, only once a wallet is switched on.
     *
     * ── RE-PINNED BY LANE AR2, AND THE REBASE IS THE WHOLE REASON ─────────
     *
     * Lane AR advanced this to c1fb494 on lane/ar for the <bdi> wordmark
     * wrapper, and said in that commit's own message that the constant is a
     * SHA and a rebase invalidates it. lane/ar was rebased onto the
     * integration branch, so c1fb494 no longer exists: a guard that cannot
     * resolve its commit fails with "no such commit" rather than with a diff,
     * which is indistinguishable from one that passes.
     *
     * 98853a0 is that same <bdi> commit, rewritten onto this branch, so it
     * carries BOTH halves: Lane WAL's wallet chips above and the wordmark
     * wrapper. Neither baseline alone is correct here -- 386a5de predates the
     * <bdi> wrapper and c1fb494 predates the wallet work, and pinning either
     * one reports the other lane's finished change as a regression.
     */
    public const BASE_COMMIT = '98853a0c6d4a88e8cf80fb553f68ed04f7559195';

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

        // Quick view is OFF by default since 2.60.354 (owner: "by default off
        // this function everywhere"). The walk's baseline was written with the
        // button on every card and serves /quick-view/{id}, so it switches the
        // module on; QuickViewCentredTest pins the default-off page itself.
        app(\App\Services\SettingsService::class)->setModule('quick_view', true);

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

        /*
         * THE WALK'S LISTINGS STAY ONE PAGE LONG.                   (Lane PR)
         *
         * Appearance → Site layout → Loading more products ships at "Load more
         * on scroll" now — the owner asked for it in as many words — and that
         * makes a listing's page ONE BATCH of twelve where it was the shop's
         * twenty-four. This fixture puts more than twelve products on /shop/,
         * /new-in and /best-sellers, so the default alone turned three
         * one-page listings into two-page ones, and a two-page listing draws
         * Lane PI-B's pager where the BASE_COMMIT template drew Laravel's
         * Tailwind one or none: a template change that was already read and
         * approved, on the condition — recorded in approvedRemovals() — that
         * "the walk's four curated listings are one page each".
         *
         * So the walk keeps "Arrows", which is the page size it was approved
         * at, and compares what it was built to compare: the templates. What
         * the default does to a listing — a batch of twelve, the pager taken
         * over by the loader, a brand page that pages at all — is pinned by
         * ListingLoadTest and GridCardOwnerAsksTest, which assert it directly.
         */
        app(\App\Services\SiteLayout::class)->save(['load_mode' => 'arrows']);
        Setting::flushMap();
        \App\Services\SettingsService::forgetMemo();

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
            // Apple's domain-association document (Lane WAL). Machine-facing in
            // the strictest sense: Apple's own fetcher reads it to verify this
            // domain, and Apple Pay draws no sheet until it has. Both spellings
            // are served because Apple has published the path with and without
            // the .txt suffix; neither renders Blade or carries a shopper string.
            '.well-known/apple-developer-merchantid-domain-association' => $file,
            '.well-known/apple-developer-merchantid-domain-association.txt' => $file,
            '{key}.txt' => ['params' => ['key' => fn () => \App\Services\Seo\IndexNow::key()]] + $file,
            '_kbb-health' => ['query' => ['token' => 'walk-health-token']] + $json,
            'storage/{path}' => ['params' => ['path' => 'kbb/app.css']] + $file,
            // A developer preview, not a storefront page. See below.
            'app' => ['render' => false, 'why' => '404 to a shopper; admin-only developer preview'],

            // --- catalogue ---------------------------------------------------
            '/' => ['render' => true],
            'shop' => ['render' => true],
            'shop/page/{page}' => ['params' => ['page' => '2']] + $redirect,
            /*
             * The address scheme moved the category archive to
             * /collections/{path}/ and made the old base a 301 that resolves
             * the canonical path first. Same controller, same template, same
             * English — only the door changed, which is why the archive is
             * still `render => true` and the retired address is now a redirect
             * with no body to pin.
             */
            'collections/{path}' => ['params' => ['path' => $catSlug], 'render' => true],
            'product-category/{path}' => ['params' => ['path' => $catSlug]] + $redirect,
            'product/{slug}' => ['params' => ['slug' => $product->slug], 'render' => true],
            'product' => $redirect,
            'quick-view/{id}' => ['params' => ['id' => (string) $product->id], 'render' => true],
            'new-in' => ['render' => true],
            'best-sellers' => ['render' => true],
            'super-sale' => ['render' => true],
            'everything-under-54-aed' => ['render' => true],

            /*
             * #KBeautyBliss Spotted (Lane HB). Listed exactly when the router has
             * it, because routes/spotted.php reaches the router only when the
             * integrator requires it -- and this walk checks the table in BOTH
             * directions. Written this way the walk is green on both sides of
             * that one-line change, instead of red on one of them.
             *
             * Not rendered: the page was added after BASE_COMMIT, so there is no
             * pre-conversion template to compare it against. SpottedPageTest
             * pins its title, H1, canonical, breadcrumb and cards.
             */
            /*
             * Marketing Emails' public end (Lane MK), listed exactly when the
             * router has it (the integrator's require), like Spotted below.
             * Not rendered: the pages were added after BASE_COMMIT, so there is
             * no pre-conversion template to compare against; their words are
             * keyed (store.mkt_unsub.*) and MarketingEmailsPublicTest pins them.
             */
            // App → Site App (Lane NT): whether the installed shop app asks for
            // notifications, the VAPID key and four strings. SiteAppPushTest pins it.
            'api/site-app/push' => $json,
            ...(Route::has('marketing.unsubscribe') ? [
                'email/u/{token}' => ['render' => false, 'why' => 'added after BASE_COMMIT (Lane MK): the campaign unsubscribe page; MarketingEmailsPublicTest pins it'],
                'email/c/{token}/{n}' => ['render' => false, 'why' => 'a redirect (Lane MK): to that campaign\'s own link n, or the home page'],
                'email/art/{name}.jpg' => ['render' => false, 'why' => 'a JPEG that ships with the shop (Lane MK), no interface strings'],
            ] : []),

            /*
             * The shop as a Home Screen app (Lane PW), listed exactly when the
             * router has it (the integrator's require of routes/site-app.php),
             * like Spotted below. Machine-facing files, plus the offline page,
             * which was added after BASE_COMMIT and is pinned by SiteAppTest.
             */
            ...(Route::has('site-app.manifest') ? [
                'manifest.webmanifest' => $file,
                'sw.js' => $file,
                'site-app.js' => $file,
                'site-app/icons/{name}.png' => $file,
                'offline' => ['render' => false, 'why' => 'added after BASE_COMMIT (Lane PW): the offline page the service worker shows; SiteAppTest pins it'],
            ] : []),

            ...(Route::has('spotted.page') ? ['kbeautybliss-spotted' => [
                'render' => false,
                'why' => 'added after BASE_COMMIT (Lane HB), so there is no pre-conversion template to compare against; SpottedPageTest pins it',
            ]] : []),

            /*
             * The concern pages, which land in the same commit as the require
             * in routes/web.php -- this walk checks the route table in BOTH
             * directions, so neither half can go first.
             *
             * render => false because a concern page does not exist until the
             * owner has both written its copy and tagged at least
             * ConcernCollections::MIN_PRODUCTS live products for it, and this
             * walk seeds a shop where nothing is tagged. Pinning it as
             * render => true would pin a 404 body and go red the day he tags
             * his third acne product. The page's own English is pinned by
             * ConcernCollectionsTest, which builds the rows that make it exist.
             */
            'concern/{concern}' => [
                'params' => ['concern' => 'acne'],
                'render' => false,
                'why' => 'a concern page does not exist until the owner has tagged products for it; see ConcernCollectionsTest',
            ],

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
            /*
             * The scheme swapped which way round these go: /brands/ is the
             * directory now — a listing page, so the address is plural and
             * short — and /korean-skincare-brands/ is the 301. The PAGES are
             * unchanged, which is what these two `render => true` entries are
             * here to keep true: the same controller actions reached through a
             * different route.
             */
            'brands' => ['render' => true],
            'brands/{slug}' => ['params' => ['slug' => $brandSlug], 'render' => true],
            'korean-skincare-brands' => $redirect,
            'korean-skincare-brands/{slug}' => ['params' => ['slug' => $brandSlug]] + $redirect,
            'brand/{slug}' => ['params' => ['slug' => $brandSlug]] + $redirect,

            // --- journal -----------------------------------------------------
            /*
             * Articles moved off the site root and under /blog/, which closes
             * the defect that RESERVED_SLUGS owned the first segment there — an
             * article slugged `about` or `feed` was an address this shop could
             * never serve. The root form is now the redirect and carries no
             * body; the index and the article are the same two templates.
             */
            'blog' => ['render' => true],
            'blog/{slug}' => ['params' => ['slug' => 'walk-article'], 'render' => true],
            'skincare-guide' => $redirect,
            'skincare-guide/{slug}' => ['params' => ['slug' => 'walk-article']] + $redirect,
            '{slug}' => ['params' => ['slug' => 'walk-article']] + $redirect,
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
            // 2.60.371, Lane RL: the page the reminder and payment-failed emails open. With no
            // signed ?order=&t= it is the same 404 a forged link gets, so there is no shop page to hold.
            'checkout/order-pay' => ['render' => false, 'why' => '404 without a signed order link; reached only from an email'],
            // Lane EM: "View this email in your browser" serves the stored HTML
            // of one SENT email, byte for byte -- not a storefront page, and a
            // token nobody was sent is a 404. The font is a binary file.
            'mail/view/{token}' => ['render' => false, 'why' => 'the stored copy of a sent email (WebCopy); 404 for any token never sent'],
            'mail/font/outfit-latin.woff2' => ['render' => false, 'why' => 'a woff2 font file, no interface strings'],

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
            // Lane PQ: the account-invite landing page. NOT rendered here: this
            // walk compares each page against the templates at BASE_COMMIT, and
            // store/account/welcome.blade.php did not exist then, so the "before"
            // pass is an exception page. It was written keyed from the start --
            // StorefrontStringsAreKeyedTest scans it, and CustomerInvitesTest
            // asserts the page itself.
            'my-account/welcome/{token}' => [
                'params' => ['token' => str_repeat('a1b2c3d4', 8)],
                'render' => false,
                'why' => 'added after BASE_COMMIT, so there is no pre-conversion template to compare against',
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
            /*
             * Shoppable video, Phase 20. JSON, and a 404 on every install until
             * somebody switches the module on — so there is no body for a
             * storefront string to appear in either way. The POST sibling
             * (api/ugc/{slug}/like) is not here because this walk lists GET routes.
             */
            'api/ugc/{section}' => ['params' => ['section' => 'not-a-section']] + $json,

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

            // The owner app (Lane MAC) is back office at a secret, generated
            // address — PIN-locked, noindex, never linked — and not a page a
            // shopper reads. Its own tests (OwnerApp*Test) cover every route.
            if (str_starts_with((string) $route->getName(), 'owner-app.')) {
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
    /**
     * The approved STOREFRONT differences — one, and it is the card style.
     *
     * ── WHAT CHANGED AND WHY IT IS ALLOWED ──────────────────────────────────
     *
     * The owner, verbatim: "apply this design on the whole website everywhere.
     * exept cart and checkout pages. keep this design by default from backend."
     * CLAUDE.md rule 1 has exactly one exception — "a default the owner asked
     * for in as many words" — and this is it, so `GridSkins::DEFAULT` moved
     * from `classic` to the showcase card and every grid that does not choose
     * its own skin follows it.
     *
     * ── WHY A RULE AND NOT A BASE_COMMIT MOVE ───────────────────────────────
     *
     * The same argument the printed sheet's rules make above: moving the
     * constant would blind this walk to every OTHER lane's change as well, for
     * one attribute. A rule stays narrow, and applyApproved() counts the hits —
     * so when the base does move past this work it fails as a rule that has
     * stopped excusing anything, which is the signal to delete it.
     *
     * ── FOUR HITS, AND THEY ARE NAMED ───────────────────────────────────────
     *
     * The four curated collection listings — /new-in, /best-sellers,
     * /super-sale and /everything-under-54-aed — are the only pages in this
     * walk that BOTH render a product grid and have products in the fixture to
     * put in it. One grid each, one attribute each. Measured, not predicted:
     * before this rule existed the test named those four pages and no others.
     *
     * The REPLACEMENT names the constant rather than repeating a skin name, so
     * the day the owner picks a different treatment of the same card this rule
     * still excuses exactly the difference it was written for and the count
     * does not move.
     *
     * @return array<string, array{pattern: string, with: string, hits: int}>
     */
    public static function approvedStorefrontChanges(): array
    {
        return [
            /*
             * THE TOTALS CARD ABOVE PLACE ORDER (Lane CD). The owner: "ON
             * DESKTOP checkout: the summary bar should have only products,
             * rest of the sub total, delivery fees etc rows should be above the
             * place order button." Appearance -> Checkout page -> Fields &
             * attention -> "Desktop: totals above Place order" ships ON: the
             * order block, drawn twice on the checkout (summary column and the
             * phone box), wraps its rows from the free-delivery bar to the VAT
             * note in ONE div. Applied to the before side as exactly that
             * wrapper, so every row inside is still compared byte for byte.
             * Off, neither fires and the page is as it was.
             */
            'the checkout totals card opens (Lane CD)' => [
                'pattern' => '#<div class="kbb-freeship-slot">#',
                'with' => '<div class="cotot"><div class="kbb-freeship-slot">',
                'hits' => 2,
            ],
            'the checkout totals card closes (Lane CD)' => [
                'pattern' => '#(<div class="sumrow vat js-vat-row vat-note"[^\n]*</div>\n)#',
                'with' => '$1</div>'."\n",
                'hits' => 2,
            ],
            /*
             * THE SITE FOOTER, ON EVERY PAGE: THE APPROVED NEW DESIGN. (Lane HB)
             *
             * The owner approved docs/home-preview/footer-final.html (master
             * plan row 55, "Owner's picks, 3 Oct") and asked for it on the shop:
             * WhatsApp-green help strip with "24/7 available", no word "return"
             * anywhere, the very big name at the bottom. It ships ON, as he
             * asked (Appearance -> Footer -> Site footer · design -> Previous
             * puts the old one back).
             *
             * THE OLD FOOTER BLOCK AND NOTHING ELSE: the pattern opens on the
             * old footer's own first two lines and stops at its own close, and
             * it must fire exactly once on each of the 29 rendered pages that
             * carry a footer. Every byte outside it is still compared as
             * before. The replacement is the new footer exactly as the walk's
             * seed renders it (CSRF token masked), so a change to it is a change
             * somebody has to approve here. SiteFooterTest pins its behaviour.
             *
             * ▲ ADVANCED BY LANE HF (4 October), INSIDE THE BLOCK ONLY: the
             * <footer> now carries the owner's settings as classes and --kft-*
             * properties (logo off and the brand block and strip centred on
             * phones, the shine on the bottom bar, the pink strip), and the
             * third column is "Account" — My account, My orders, Wishlist,
             * Addresses — with the old "Discover" links moved: #KBeautyBliss and
             * Journal to Shop, About us to Help. The pattern and the 29 hits are
             * unchanged, so still nothing outside the footer may move.
             * SiteFooterControlsTest pins the new behaviour.
             */
            'the site footer: the approved new design (Lane HB)' => [
                'pattern' => '#<footer><div class="wrap">\n    <div class="fcols">.*?</div></footer>#s',
                'with' => str_replace(['\\', '$'], ['\\\\', '\\$'], self::laneHbFooter()),
                'hits' => 29,
            ],

            /*
             * BIG SAVINGS BUNDLES BECOME A CAROUSEL. (Integrator, 2.60.370)
             *
             * The owner: "on homepage, i need the bundle section to be carousel
             * with proper beautiful arrows, give controls of everything for
             * desktop mobile both. center the heading, redesign the All Sets
             * button beautifully" and "i want this button in mobile at bottom
             * of carsousel". One page, the home page: the section opens with
             * its classes and --bndl-* values, the heading carries an id, the
             * button is the new pill, the grid becomes the carousel track
             * between two arrows, and a bottom button follows. The cards in
             * between are byte-identical -- partials/home/grid prints them.
             *
             * ▲ AND THE "8 sets" BADGE GOES (Lane PF). The owner crossed it out
             * on his screenshot, 4 October; Appearance → Homepage content → Big
             * savings bundles → "Show how many sets beside the heading" ships
             * off and brings it back. So the badge and the space before it are
             * matched OUTSIDE the group and not put back: the heading closes
             * straight after its words. Everything else in this rule is as it
             * was.
             *
             * ▲ AND THE PHONE CAROUSEL PEEKS (Lane PF). The owner, 4 October:
             * "show 2.2, 2.3 2.5 etc products … keep [the arrows] off in
             * mobile". So the section's classes gain `bndl-peek-m` (a
             * fractional count runs the phone track to the screen edge) and
             * `bndl-noarr-m`, and --bndl-per-m is 2.3 where it was 2.
             */
            /*
             * THE PHONE LISTING TOOLBAR (2.60.393). The owner: "turn off the
             * filters for now. and make number of columns to select 1 or 2".
             * The toolbar carries the two phone switches as classes (Appearance
             * -> Site layout -> Product grid); laptops ignore both. Shop and
             * category listing, once each.
             */
            'the phone listing toolbar: Filters off, 1 / 2 columns (2.60.393)' => [
                'pattern' => '#<div class="gtop">#',
                'with' => '<div class="gtop kbb-nofilt-m kbb-mcols">',
                'hits' => 2,
            ],
            'Big savings bundles: the section head and the carousel track (2.60.370)' => [
                'pattern' => '#<section class="sec dv" style="padding-top:8px"><div class="wrap">\n  <div class="sh"><div><h2>Big savings bundles <span class="cnt">8 sets</span>(</h2>\n    <p>Complete routines, priced below the sum of their parts\.</p></div>)\n    <a class="lnk" href="/shop/\?cat=skincare-sets">All sets</a></div>\n  <div class="kbb-pgrid" data-skin="showcase">#',
                'with' => '<section class="sec bndl bndl-car-d bndl-car-m bndl-peek-m bndl-noarr-m bndl-center bndl-btn-d-top bndl-btn-m-bottom dv" style="--bndl-per-d:4;--bndl-per-m:2.3;--bndl-pad-d:8px;--bndl-pad-m:8px;--bndl-hg-d:24px;--bndl-hg-m:12px;--bndl-bg-d:24px;--bndl-bg-m:16px" data-ymal data-ymal-auto="0" aria-labelledby="bndl-h"><div class="wrap">'."\n".'  <div class="sh bndl-head"><div><h2 id="bndl-h">Big savings bundles$1'."\n".'    <a class="bndl-all bndl-all-top" href="/shop/?cat=skincare-sets">All sets<i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></i></a></div>'."\n".'  <div class="bndl-stage">'."\n".'    <button type="button" class="bndl-arr bndl-prev" data-ymal-prev aria-controls="bndl-track" aria-label="Previous products" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg></button>'."\n".'  <div class="kbb-pgrid bndl-track" data-skin="showcase" id="bndl-track" data-ymal-track tabindex="0" role="region" aria-label="Big savings bundles">',
                'hits' => 1,
            ],
            'Big savings bundles: the second arrow and the bottom button (2.60.370)' => [
                /*
                 * ▲ Row 55 (Lane HA): the lookahead named "Recommended for
                 * you", the section that followed the bundles — and that
                 * section ships switched off now, so the strip after the
                 * bundles is whatever the next rule leaves. Anchored instead
                 * on what IS unique to the bundles on this page: the only grid
                 * on the BEFORE side that closes straight into its section
                 * (the old rails that did the same are off), which the
                 * required single hit still proves.
                 */
                'pattern' => '#(Add to cart</a>\n            </div>\n</div>\n    </div>)\n</div></section>#',
                'with' => '$1'."\n".'    <button type="button" class="bndl-arr bndl-next" data-ymal-next aria-controls="bndl-track" aria-label="More products"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.5 5.5 6.5 6.5-6.5 6.5"/></svg></button>'."\n".'  </div>'."\n".'  <div class="bndl-foot"><a class="bndl-all bndl-all-bottom" href="/shop/?cat=skincare-sets">All sets<i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></i></a></div>'."\n".'</div></section>',
                'hits' => 1,
            ],

            /*
             * THE SORT SELECT ACTUALLY SORTS. (Integrator, 2.60.358)
             *
             * The owner: "the selection of low to high etc, is absolutely not
             * working." Its inline onchange said `new URL(location.href)`, and
             * inside an inline handler `URL` is looked up on the document
             * first -- `document.URL`, a string -- so every choice threw "URL
             * is not a constructor" and the page never moved. `window.URL` is
             * the constructor. Two pages, the shop and a category.
             */
            'the sort select names window.URL (2.60.358)' => [
                'pattern' => '#<select id="sort" onchange="var u=new URL\(location\.href\);#',
                'with' => '<select id="sort" onchange="var u=new window.URL(location.href);',
                'hits' => 2,
            ],

            /*
             * "SORT" BECOMES THE SELECT'S LABEL. (Lane PI-B)
             *
             * The owner's phone drew Filters and the count on one row and Sort
             * on a second. Fitting them on one row hides the word "Sort"
             * visually under 411px, and a word that disappears must still
             * name the control — it did not before (a bare text node in a
             * <div>, so the select had no accessible name at all). So the
             * wrapper becomes `<label for="sort">` and the word a span the
             * stylesheet can hide. Same element in the same place with the same
             * class, so nothing moves on screen at any width this walk renders;
             * two pages, the shop and a category, open and close.
             */
            'the sort control is a label, not a div (Lane PI-B)' => [
                'pattern' => '#<div class="sortsel">([^<\n]*)\n#',
                'with' => '<label class="sortsel" for="sort"><span class="sortlbl">$1</span>'."\n",
                'hits' => 2,
            ],
            'the sort control\'s closing tag (Lane PI-B)' => [
                'pattern' => '#(</select>\n {16})</div>#',
                'with' => '$1</label>',
                'hits' => 2,
            ],

            /*
             * THE DESKTOP MENU FILLS ITS ROW. (Lane NV)
             *
             * The owner, 4 October: "the top desktop menu items font size
             * should auto adjust if empty area there ... this formula will
             * apply only if the menu item has at least 9 menu items." The
             * walk's seeded primary menu is his twelve-entry one, so it is
             * fitted: `.mbar` gains the class `nav-fill` and a style of integer
             * custom properties (App\Support\NavRowFit), and the "Super Sale"
             * pill's side padding follows the scale. Appearance → Header →
             * Navigation → "Fit the menu to the row" ships ON because he asked;
             * off puts both bytes back. A menu under nine items is untouched,
             * which NavRowFitTest pins byte for byte.
             *
             * TWO ATTRIBUTES, NOTHING ELSE: the bar's opening tag and the
             * pill's padding, once each on every page that draws the header —
             * 31 of the walk's pages, counted, not predicted.
             *
             * 2.60.376 — "How it fills the row" ships at "Spread the items (keep
             * my text size)" because he asked for it ("i want to keep the font
             * size control but the space will auto adjust"): the ceiling is now
             * the Menu text size, 14px, instead of 18px.
             */
            'the desktop menu fills its row: the bar (Lane NV)' => [
                'pattern' => '#<div class="mbar"><div class="wrap">#',
                'with' => '<div class="mbar nav-fill" style="--nav-n:12;--nav-np:11;--nav-hl:1;--nav-gx:2;--nav-bx:0;--nav-w:6530;--nav-min:10px;--nav-max:14px"><div class="wrap">',
                'hits' => 31,
            ],
            'the desktop menu fills its row: the pill\'s padding (Lane NV)' => [
                'pattern' => '#(<a class="navlink" href="[^"]*"\s+style="background:\#[0-9A-Fa-f]{6};color:\#fff;border-radius:8px;padding:4px) 10px"#',
                'with' => '$1 calc(10px * var(--nav-scale))"',
                'hits' => 31,
            ],

            /*
             * MEGA MENUS FIT THE SITE WIDTH. (Lane MG)
             *
             * The owner, over the "All Brands" panel running off the right edge
             * of the screen: a panel must never leave the site width, its
             * columns and text squeeze, a panel of more than four columns
             * starts at the far left, and "a nice edge type to show that this
             * mega menu is for this parent item". Appearance → Header →
             * Navigation → "Fit mega menus to the site width" ships ON because
             * he asked; off puts every byte back (MegaMenuFitTest pins the
             * whole bar against the pre-lane template).
             *
             * WHAT MOVES, AND NOTHING ELSE: an item WITH a panel gains its
             * placement class (mg-s one column, mg-a two to four, mg-l more)
             * and mg-p for the pointer; a mega item also gains a style of its
             * column count and two px values; a mega panel's columns sit in
             * one `.mg-cols` box, opened right after the panel's tag and closed
             * right before the panel's own `</div>`. Items without a panel stay
             * `<div class="navitem">`. The walk's menu has no panel past four
             * columns, so the mg-l case has no rule here: MegaMenuFitTest pins
             * it on a menu of the owner's shape.
             */
            'mega menus fit the site width: one-column items (Lane MG)' => [
                'pattern' => '#<div class="navitem">(\s*<a class="navlink"(?:(?!</a>).)*</a>\s*<div class="drop" style="">)#sU',
                'with' => '<div class="navitem mg-s mg-p">$1',
                'hits' => 31,
            ],
            'mega menus fit the site width: two-to-four-column items (Lane MG)' => [
                'pattern' => '#<div class="navitem">(\s*<a class="navlink"(?:(?!</a>).)*</a>\s*<div class="drop mega" style="--mega-cols:([2-4])">)#sU',
                'with' => '<div class="navitem mg-a mg-p" style="--mega-cols:$2;--mg-min:130px;--mg-fmin:11.5px">$1',
                'hits' => 31,
            ],
            'mega menus fit the site width: the columns box (Lane MG)' => [
                'pattern' => '#(<div class="drop mega" style="--mega-cols:\d+">)(.*\n {60}</div>)#sU',
                'with' => '$1<div class="mg-cols">$2</div>',
                'hits' => 31,
            ],

            'the shipped card style, which the owner asked to change' => [
                'pattern' => '#<div class="kbb-pgrid" data-skin="classic">#',
                'with' => '<div class="kbb-pgrid" data-skin="'.\App\Support\GridSkins::DEFAULT.'">',
                'hits' => 4,
            ],

            /*
             * THE TYPEFACE, WHICH THE OWNER ASKED TO CHANGE.       (Lane PLC)
             *
             * "can u plz match the font of overal site to 'Outfit'". Every
             * literal occurrence of the family name in rendered HTML — the
             * `--sans` custom property on the four standalone documents, the
             * `font:` shorthands inlined by the layout, and the @font-face
             * blocks — is the same string with a different family in it.
             *
             * ONE RULE AND NOT SEVEN, deliberately. The alternative is a
             * pattern per shape (`--sans:'…'`, `--sans:"…"`, `font:400 14px/1.6
             * …`) and they would all say the same thing: this shop stopped
             * naming Poppins.
             *
             * FOUR PAGES, and it is worth knowing which. They are the four
             * standalone documents — the journal, an article, the review wall
             * and the skin quiz — because those carry their own <head> with the
             * `--sans` custom property written into it. The other 33 pages name
             * the family only inside the <style id="kbb-outfit"> block, which
             * the insertion rule above already owns, and in the built
             * stylesheet, which is linked rather than inlined and so is not
             * part of this comparison at all.
             *
             * ARABIC IS NOT IN THIS RULE AND MUST NOT BE. Cairo is untouched;
             * `/ar` keeps its Arabic face exactly as it was, measured with two
             * rulers at 441.81px and 449.45px before and after.
             */
            'the typeface, which the owner asked to change (Lane PLC)' => [
                'pattern' => '#Poppins#',
                'with' => 'Outfit',
                'hits' => 2, // 4 -> 2 (Lane BH): the journal and an article are on the layout now and their old head is cut whole; the review wall and the skin quiz remain.
            ],

            /*
             * "YOU MAY ALSO LIKE" BECOMES A CAROUSEL, WHICH THE OWNER ASKED FOR.
             *                                                       (Lane PS)
             *
             * "You may also like should be a slider on each product page, I
             * need it carousel by suggesting products from the same brand and
             * category mixed."
             *
             * ONE PAGE, ONE OPENING, AND THE CARDS UNTOUCHED. The rule rewrites
             * only the wrapper around the cards — the section gains its carousel
             * hooks and the two custom properties that size a card, the heading
             * an id the region is labelled by, the arrows arrive, and the track
             * keeps `rel kbb-pgrid` + the skin and gains `ymal-track` and its
             * keyboard focus. Every card after it is compared BYTE FOR BYTE: the
             * controller hands the pre-conversion template the very collection
             * the carousel draws, so the twelve cards on both sides are the same
             * <x-product-card> renders of the same rows. A card that moved by a
             * byte would still be red.
             *
             * WHICH cards they are — brand and category mixed, rather than four
             * from the category — is the owner's other half of the request and
             * is AlsoLikeCarouselTest's to pin; this walk proves only that the
             * carousel did not change a card. The closing pair moves by
             * whitespace only (the partial's `@endif` sits at the left margin).
             */
            'You may also like, the carousel the owner asked for (Lane PS)' => [
                'pattern' => '#(<!-- related -->\n)\s*<section class="sec">\n    <div class="eyebrow">(Complete your routine)</div>\n    <h2>(You may also like)</h2>\n    <div class="([^"]*?) ?rel kbb-pgrid" data-skin="([a-z0-9-]+)" id="related">#',
                'with' => '$1<section class="sec ymal $4" data-ymal data-ymal-auto="0" aria-labelledby="ymal-h" style="--ymal-d:5;--ymal-m:2">'."\n"
                    .'    <div class="eyebrow">$2</div>'."\n"
                    .'    <h2 id="ymal-h">$3</h2>'."\n"
                    .'    <div class="ymal-nav">'."\n"
                    .'      <button type="button" class="ymal-btn" data-ymal-prev aria-controls="related" aria-label="Previous products" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg></button>'."\n"
                    .'      <button type="button" class="ymal-btn" data-ymal-next aria-controls="related" aria-label="More products"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.5 5.5 6.5 6.5-6.5 6.5"/></svg></button>'."\n"
                    .'    </div>'."\n"
                    .'    <div class="rel kbb-pgrid ymal-track" data-skin="$5" id="related" data-ymal-track tabindex="0" role="region" aria-label="$3">',
                'hits' => 1,
            ],

            /*
             * THE DISCOUNT BADGE ON THE PHOTO, WHICH THE OWNER ASKED TO FIX.
             *                                                     (Lane PV)
             *
             * "The discount label has a white box and white text ... It should
             * be a green box with white text." The gallery's plain "-N%" span
             * was the only badge branch that wrote no colour, and `.lbl` sets
             * none, so it drew white on white. It gains one class, `lbl-off`,
             * which `.gmain .lbl-off` in kbb-product.css colours. Same element,
             * same text, same inline style; ONE page in this walk carries a
             * product on sale, and the diff was read before it was approved.
             */
            'the photo\'s discount badge gains its colour class (Lane PV)' => [
                'pattern' => '#<span class=" lbl" style="top:14px;left:14px">#',
                'with' => '<span class=" lbl lbl-off" style="top:14px;left:14px">',
                'hits' => 1,
            ],

            /*
             * THREE SECTIONS OF THE PHONE PAGE THAT ARE ONE ELEMENT EACH, so
             * they carry the section's class themselves rather than a wrapper.
             *                                                       (Lane QA)
             *
             * Same element, same place, same contents: the trust lines, the
             * payment chips and the Product details section each gain
             * `pm-sec pm-<key>`, which kbb-product.css orders and switches
             * inside its phone breakpoint only. One page.
             */
            'the trust lines are a phone section (Lane QA)' => [
                'pattern' => '#<div class="([^"]*) trust">#',
                'with' => '<div class="$1 trust pm-sec pm-trust">',
                'hits' => 1,
            ],
            'the payment chips are a phone section (Lane QA)' => [
                'pattern' => '#<div class="([^"]*) paychips">#',
                'with' => '<div class="$1 paychips pm-sec pm-paychips">',
                'hits' => 1,
            ],
            'Product details is a phone section (Lane QA)' => [
                'pattern' => '#<section class="sec">(\n    <div class="eyebrow">)#',
                'with' => '<section class="sec pm-sec pm-details">$1',
                'hits' => 1,
            ],

            /*
             * PRESS FEEDBACK ON <html>, WHICH THE OWNER ASKED FOR.  (Lane RD)
             *
             * "click on any icon / button should give feeling live that user
             * is clicking on some live thing" -- and, shown five styles, "set
             * C · Ripple by default". The layout's <html> tag gains ONE
             * attribute, ` data-press="c"`, and that is the whole of the
             * change to the markup: every press rule in kbb.css and every line
             * of resources/js/kbb/press.js is keyed by it.
             *
             * THIRTY-THREE PAGES: every page in this walk that extends
             * layouts/store.blade.php, and no other. The four standalone
             * documents (the journal, an article, the review wall, the skin
             * quiz) carry their own <html> and do not load kbb.css or the
             * storefront bundle, so they are unchanged -- which is why the
             * pattern is anchored on the layout's own head, where the viewport
             * line is followed by exactly one blank line and then <title>. The
             * standalone documents leave two blank lines there, or write
             * `initial-scale=1.0`, and the pattern does not reach them.
             *
             * The REPLACEMENT names the constant and the schema default rather
             * than repeating "c", so it follows the shipped default if it ever
             * moves and the count does not.
             */
            'press feedback: the token on <html>, which the owner asked for (Lane RD)' => [
                'pattern' => '#<html lang="en" dir="ltr">(\n<head>\n<meta charset="UTF-8">\n<meta name="viewport" content="width=device-width,initial-scale=1">\n\n<title>)#',
                'with' => '<html lang="en" dir="ltr"'
                    .\App\Services\SiteLayout::PRESS_ATTR[\App\Services\SiteLayout::SCHEMA['press'][2]].'>$1',
                'hits' => 33,
            ],

            /*
             * THE BRAND PAGE. (2.60.376)
             *
             * The owner, 4 October: "for Brand Page, remove the Shop all
             * button, and keep Name, along with description ... remove also
             * popular right now, and view all." He asked, so it ships that way;
             * Appearance → Site layout → Brand page puts each back. Three
             * changes and nothing else: the button goes from the hero (its
             * blank lines move with the new @if), the grid's heading row goes,
             * and one CSS line spaces a description's paragraphs on both brand
             * views. BrandPageOwnerAsksTest pins the behaviour.
             */
            'the brand page: no Shop all button (2.60.376)' => [
                'pattern' => '#<h1 class="brw-h1">([^<]*)</h1>\n {52}\n {16}<a class="brw-cta" href="[^"]*">[^<]*</a>\n {12}</div>#',
                'with' => '<h1 class="brw-h1">$1</h1>'."\n".str_repeat(' ', 32)."\n".str_repeat(' ', 32)."\n".str_repeat(' ', 28).'</div>',
                'hits' => 1,
            ],
            'the brand page: no Popular right now / View all (2.60.376)' => [
                'pattern' => '#\n {20}<div class="kbb-gridhead">\n {8}<div>\n {12}<h2>Popular right now</h2> {20}</div>\n {8}<a class="lnk" href="[^"]*">View all</a> {4}</div>\n\n<div class="kbb-pgrid"#',
                'with' => "\n".str_repeat(' ', 20).'<div class="kbb-pgrid"',
                'hits' => 1,
            ],
            'the brand page: a description\'s paragraphs are spaced (2.60.376)' => [
                'pattern' => '#(\.brw-hero \.brw-sub\{margin-bottom:14px;max-width:62ch\}\n)(\.brw-cta\{)#',
                'with' => '$1.brw-desc p{margin:0 0 6px}.brw-desc p:last-child{margin-bottom:0}'."\n".'$2',
                'hits' => 2,
            ],

            /*
             * THE COMPACT BRAND HEADER AND THE LOGO RING.              (Lane BH)
             *
             * The owner: "logo + name in one row, then description in another,
             * that's it. need same less heighted banner in mobile as like on
             * desktop ... it will be auto circled with outer brand color
             * border." He asked, so it ships on; Appearance → Site layout →
             * Brand page → Brand header style (Classic) and "Ring round the
             * brand logo" put the old page back. Two changes: the brand page's
             * hero becomes row (logo + name) then description, the logo carrying
             * the ring class; and the shared stylesheet gains the ring and
             * compact rules on BOTH brand views (the directory's tiles do not
             * use either class, so they draw as before). BrandHeaderCompactTest
             * pins the behaviour.
             */
            'the brand page: compact header, logo and name in one row (Lane BH)' => [
                'pattern' => '#\n {8}<div class="brw-hero">\n {12}<span class="brw-logo brw-logo--lg">\n {36}<span class="brw-initial">([^<]*)</span>\n {28}</span>\n {12}<div class="brw-hero-txt">\n {36}<h1 class="brw-h1">([^<]*)</h1>\n {32}\n {32}\n {28}</div>\n {8}</div>\n\n#',
                'with' => "\n\n".str_repeat(' ', 8).'<div class="brw-hero brw-hero--compact">'."\n".str_repeat(' ', 12).'<div class="brw-hero-row">'
                    ."\n".'<span class="brw-logo brw-logo--lg brw-logo--ring"><span class="brw-initial">$1</span></span>'
                    ."\n".str_repeat(' ', 16).'<h1 class="brw-h1">$2</h1>'."\n".str_repeat(' ', 12).'</div>'."\n".str_repeat(' ', 8).'</div>'."\n",
                'hits' => 1,
            ],
            'the brand pages: the ring and compact header rules (Lane BH)' => [
                'pattern' => '#(  \.brw-hero\{flex-direction:column;align-items:flex-start;gap:14px\}\n\}\n)(</style>)#',
                'with' => '$1'.self::laneBhBrandCss()."\n".'$2',
                'hits' => 2,
            ],

            /*
             * THE PANEL BRAND HEADER.                                (Lane BR2)
             *
             * The owner: "logo will be on th background image, beside logo,
             * brand name, and downside brand description. and put a nice
             * background of the content ... almost 60% of the page width" and
             * "in mobile logo and name with capsule type or rectangle
             * background". He asked, so it ships on; Appearance → Site layout
             * → Brand page → Brand header style (Compact) puts the row above
             * back. Two changes, on the brand page alone -- the directory does
             * not move: its own stylesheet in the <head>, and the compact row
             * becoming the panel header around the SAME logo and the SAME name
             * (this walk's brand has no banner, no colour and no description,
             * so it is the no-picture ground in the shop pink's shades).
             * BrandPanelHeaderTest pins the behaviour.
             *
             * LANE BR4 takes the logo OUT of it, as the owner asked: "i want to
             * turn off the logo by default". The panel holds the SAME name and
             * nothing where the logo was -- no empty circle -- so the logo's span
             * is matched below and no longer written back. The <section>'s
             * classes do not move: off, centred and Bottom centre are each the
             * stylesheet's own layout and print nothing, and this walk's brand
             * has no description, so no Read more. Appearance → Site layout →
             * Brand page → "Panel · laptop · show the brand logo" puts it back.
             * BrandPanelEveryBrandTest pins the behaviour.
             */
            'the brand page: the Panel header\'s stylesheet (Lane BR2)' => [
                'pattern' => '#(<link rel="canonical" href="[^"]*/brands/[^"/]+/">.*?src="/build/assets/app-[^"]+\.js"></script> {4}\n {4})(\n)#s',
                'with' => '$1'.self::laneBr2Link().'$2',
                'hits' => 1,
            ],
            'the brand page: the Panel header around the logo and the name (Lane BR2)' => [
                'pattern' => '#\n {8}<div class="brw-hero brw-hero--compact">\n {12}<div class="brw-hero-row">\n(<span class="brw-logo[^\n]*</span>)\n {16}<h1 class="brw-h1">([^<]*)</h1>\n {12}</div>\n {8}</div>\n#',
                'with' => "\n".'<div class="brw-phw" data-kbb-brand-header>'
                    ."\n".'<section class="brw-ph brw-ph--frost brw-ph--pill-capsule brw-ph--logo-circle brw-ph--pos-center brw-ph--noimg" style="--brw-ph-w:100%;--brw-ph-h:270px;--brw-ph-hm:165px;--brw-ph-cw:60%;--brw-ph-dk:#431a25;--brw-ph-lt:#fceef2" aria-labelledby="brw-ph-title">'
                    ."\n".'<div class="brw-ph__media">'."\n".'</div>'."\n".'<div class="brw-ph__panel">'."\n".'<div class="brw-ph__id">'
                    ."\n".'<h1 class="brw-ph__name" id="brw-ph-title">$2</h1>'
                    ."\n".'</div>'."\n".'</div>'."\n".'</section>'."\n".'</div>'."\n",
                'hits' => 1,
            ],

            /*
             * SOLD OUT, SAID ON THE CARD.                             (Lane PX)
             *
             * The owner: "the sold out product should have proper sold out
             * label somewhere on the grid." He asked, so it ships on; Appearance
             * → Product styles → Card content → Sold-out label (also on
             * Appearance → Product grid) puts the old card back. Only a sold-out
             * card moves, and only in two places: a `.kbb-soldout` pill printed
             * in the photo frame just before the quick-view button, and its
             * button — "View product" before — saying "Sold out" with the
             * `kbb-card-soldout` class. Every other card, and every other byte
             * of these pages, is still compared.
             *
             * WHY THE IDS ARE NAMED. DemoCatalogueSeeder marks every ninth
             * product `outofstock` ($i % 9 === 0, so ids 1, 10 and 19) and this
             * walk seeds no variable product (see the Lane note above on
             * Product::isDirectlyBuyable()), so those three are the only cards
             * that can carry the label; the hit counts are every place one of
             * them is drawn. A variable product, in stock, keeps "View product"
             * and no pill — SoldOutCardTest pins that half.
             */
            'a sold-out card: the Sold out pill on the photo (Lane PX)' => [
                'pattern' => '#( {8})( {8}<button class="qv-btn" type="button" aria-label="Quick view" data-kbb-qv="(?:1|10|19)">)#',
                'with' => '$1<span class="kbb-soldout">Sold out</span>$2',
                'hits' => 13,
            ],
            'a sold-out card: the button says Sold out (Lane PX)' => [
                'pattern' => '#(data-kbb-qv="(?:1|10|19)">(?:(?!kbb-card-cart).)*?<a class="kbb-card-cart)(" href="[^"]*">)View product</a>#s',
                'with' => '$1 kbb-card-soldout$2Sold out</a>',
                'hits' => 13,
            ],

            /*
             * THE REVIEW SCORE BOX AT HALF HEIGHT.                    (Lane PX)
             *
             * "The review header box i need less heighted, almost less to half.
             * and keep the same design and elements positions." The markup is
             * the same box; the section gains `sr-compact`, which
             * sorina-reviews.css reads (156px -> 78px at 390). Store → Reviews →
             * Review Settings → Compact summary, on because he asked.
             */
            /*
             * YOU MAY ALSO LIKE: 2.3 CARDS ON A PHONE.                (Lane PX)
             *
             * "on product page, i want the same 2.3 cards to display by
             * default" — the homepage carousels' treatment. One value in one
             * style attribute: the phone count the calc() divides by. The
             * laptop count (--ymal-d:5) and every card are unchanged, so the
             * desktop page draws exactly what it drew. Appearance → Product
             * page → You may also like → Cards in view on a phone (2.3) and
             * Arrows on a phone (off) are the controls.
             */
            'you may also like: 2.3 cards on a phone (Lane PX)' => [
                'pattern' => '#aria-labelledby="ymal-h" style="--ymal-d:5;--ymal-m:2">#',
                'with' => 'aria-labelledby="ymal-h" style="--ymal-d:5;--ymal-m:2.3">',
                'hits' => 1,
            ],
            'the review section: compact summary (Lane PX)' => [
                // `style=` because the review wall's own <section class="sr"
                // id="sr"> is a different page and does not move.
                'pattern' => '#<section class="sr" id="sr" style=#',
                'with' => '<section class="sr sr-compact" id="sr" style=',
                'hits' => 1,
            ],
            /*
             * THE FILTER PANEL'S FACET TITLES ANNOUNCE LEVEL 2.        (Lane PS)
             *
             * Lighthouse heading-order on /collections/sunscreens/ while the 5 Oct
             * PageSpeed report was reproduced across the shop: an <h4> straight
             * after the page's <h1>. One attribute on each facet title; the tag,
             * its CSS (.fgroup h4) and every word are unchanged, so nothing
             * moves. ShopFacetHeadingLevelTest pins it.
             */
            'the shop facet titles: aria-level 2 (Lane PS)' => [
                'pattern' => '#<div class="fgroup"><h4>#',
                // /shop and the category page, four facet titles each.
                'with' => '<div class="fgroup"><h4 aria-level="2">',
                // 0 since Lane SO: with the filters off (shipped, the owner
                // asked) no rail is drawn, and approvedRemovals' "the filter
                // rail" cuts it from the before side first. The rule stays for
                // the shop that turns a Filters switch back on.
                'hits' => 0,
            ],

            /*
             * THE APPROVED CONTRAST COLOURS, IN THE FOUR DOCUMENTS THAT DECLARE
             * THEIR OWN :root. (Lane CT, 5 October)
             *
             * The owner approved docs/contrast-preview/ ("okay proceed", every
             * row but the gold card). The journal, an article, the skin quiz
             * and the review wall each carry their own copy of the shop's
             * tokens in an inline <style>, so the two that moved — --pink
             * #E0567B -> #C6395F and --muted #8C828A -> #756C74 — move in their
             * bytes too. The diff was read page by page before this entry was
             * written: on each of the four it is those two values and nothing
             * else. Every other storefront page is unchanged byte for byte: the
             * rest of the change is in the stylesheets, and the PHP defaults
             * render the same on both sides of this walk.
             */
            'contrast: the brand pink in a standalone document (Lane CT)' => [
                'pattern' => '#(--pink:\s?)\#E0567B(;\s?--pink-deep:\#C13E63;)#',
                'with' => '${1}#C6395F$2',
                'hits' => 2, // 4 -> 2 (Lane BH): the journal and an article are on the layout now and their old head is cut whole; the review wall and the skin quiz remain.
            ],
            'contrast: the secondary grey in a standalone document (Lane CT)' => [
                'pattern' => '#(--ink-2:\#5E545A;\s?--muted:)\#8C828A;#',
                'with' => '${1}#756C74;',
                'hits' => 2, // 4 -> 2 (Lane BH): the journal and an article are on the layout now and their old head is cut whole; the review wall and the skin quiz remain.
            ],
            /*
             * The slim footer's wordmark accent falls back to the header's
             * wordmark accent DEFAULT (SlimFooterTest pins the two equal), and
             * that default moved with the brand pink so the header, the site
             * footer and the slim footer still draw "Bliss" in one colour.
             */
            'contrast: the slim footer wordmark follows the header (Lane CT)' => [
                'pattern' => '#\.kbb-slimfoot \.sf-brand b\.sf-wm span\{color:var\(--sf-wm-a,\#E0567B\)\}#',
                'with' => '.kbb-slimfoot .sf-brand b.sf-wm span{color:var(--sf-wm-a,#C6395F)}',
                'hits' => 1,
            ],
            /*
             * THE GALLERY THUMBNAILS ARE FETCHED AT ONCE, AT LOW PRIORITY (Lane
             * PG2). The owner: "product gallery thumbnails take slight delay".
             * They sit on a phone's first screen (~530px down at 390x844) and
             * were loading="lazy", so not even requested until layout. Measured
             * throttled: painted 2603 ms lazy -> 1451 ms eager+low, the main
             * photo (the LCP, still fetchpriority="high") not delayed. One
             * attribute pair per thumbnail, on the product page only; nothing
             * else in the walk moved.
             */
            'the gallery thumbnails load eagerly at low priority (Lane PG2)' => [
                'pattern' => '#width="66" height="66" loading="lazy" decoding="async">#',
                'with' => 'width="66" height="66" loading="eager" fetchpriority="low" decoding="async">',
                'hits' => 5, // the fixture product's five shots, one page
            ],
        ];
    }

    /**
     * The Panel header's stylesheet tags as Vite prints them (Lane BR2), named
     * from the build manifest so a rebuilt stylesheet does not stale the rule.
     */
    private static function laneBr2Link(): string
    {
        $manifest = json_decode((string) @file_get_contents(public_path('build/manifest.json')), true);
        $file = '/build/'.($manifest['resources/css/kbb/kbb-brand-header.css']['file'] ?? 'missing.css');

        return '<link rel="preload" as="style" href="'.$file.'" /><link rel="stylesheet" href="'.$file.'" />';
    }

    /** The stylesheet lines Lane BH adds to both brand views, verbatim. */
    private static function laneBhBrandCss(): string
    {
        return <<<'KBB_BH_CSS'
/* The ring (Lane BH): a white gap, then 3px of the brand's colour, drawn with
   box-shadow so the circle keeps its size and nothing around it moves. The
   colour is --brw-ring from the logo's own style attribute, or the shop pink. */
.brw-logo--ring{box-shadow:0 0 0 2px #fff,0 0 0 5px var(--brw-ring,var(--pink));margin:0 5px}
/* Compact (Lane BH): row 1 the logo and the name, row 2 the description. A
   block, not a flex column, at every width -- there is no stacking rule. */
.brw-hero--compact{display:block;margin-bottom:18px}
.brw-hero-row{display:flex;align-items:center;gap:12px;min-width:0}
.brw-hero--compact .brw-logo--lg{width:56px;height:56px}
.brw-hero--compact .brw-logo--lg .brw-initial{font-size:22px}
.brw-hero--compact .brw-logo img{padding:5px}
.brw-hero--compact .brw-h1{margin:0;font-size:24px;line-height:1.2;min-width:0;overflow-wrap:anywhere}
.brw-hero--compact .brw-sub{margin:12px 0 0;max-width:none;line-height:1.55}
.brw-hero--compact .brw-cta{margin-top:12px}
@media (max-width:520px){
  .brw-hero--compact{margin-bottom:14px}
  .brw-hero-row{gap:10px}
  .brw-hero--compact .brw-logo--lg{width:44px;height:44px}
  .brw-hero--compact .brw-logo--lg .brw-initial{font-size:18px}
  .brw-hero--compact .brw-logo img{padding:4px}
  .brw-hero--compact .brw-h1{font-size:20px}
  .brw-hero--compact .brw-sub{margin-top:10px;font-size:12.5px;line-height:1.5}
}
KBB_BH_CSS;
    }

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

    /**
     * WHOLE ELEMENTS ADDED TO EVERY PAGE, ON PURPOSE, AND APPROVED ONE BY ONE.
     *
     * A reflow rewrites text that is already there. This is the other shape a
     * deliberate change takes on this shop: a piece of chrome that did not
     * exist, added to the layout, appearing at one point on every page that
     * draws it. `approvedReflows()` cannot express it — collapseInner() edits
     * an element's inner text and this has no "before" to edit.
     *
     * ── WHY A RULE AND NOT A MOVE OF BASE_COMMIT ────────────────────────────
     *
     * The same argument the printed sheet's group makes at greater length, and
     * it is stronger here rather than weaker. Moving the constant blinds this
     * walk to every OTHER lane's change as well, and it does more than that:
     * BASE_COMMIT is read by PrintedEnglishUnchangedTest too, so a move past
     * one lane's work fails ten of approvedDocumentDifferences() as rules that
     * have stopped excusing anything and the correct response is to delete
     * them — which takes the printed walk's coverage of Lane CX's stylesheet
     * conversion with it. One new element on the storefront is not worth that.
     *
     * A rule here stays narrow: the element is cut out of the AFTER side, the
     * cuts are counted, and everything else on all thirty-nine pages is still
     * compared byte for byte. If the element stops appearing the count falls
     * and this is red; if a second copy appears on one page the count rises and
     * this is red; and when a later lane adds a storefront page that draws it,
     * the count rises by one and the number below moves with it, deliberately.
     *
     * Applied to the AFTER side, which is the opposite of every other rule in
     * this class, because the element is in the new tree and not in the old.
     *
     * @return array<string, array{pattern: string, hits: int, perPage?: int}>
     */
    public static function approvedInsertions(): array
    {
        return [
            /*
             * THE JOURNAL AND AN ARTICLE ON THE SHOP'S OWN LAYOUT (Lane BH).
             * The owner: "on blog page, and on article page, the main header is
             * not coming correct. i need the same as we have on all other
             * pages. fix it on desktop + mobile both."
             *
             * store/blog and store/post were standalone documents with their
             * own head, a hand-drawn header and a footer strip; they extend
             * layouts/store.blade.php now. Their CHROME is replaced wholesale,
             * so it is cut from both sides -- here everything the layout draws
             * around the content (head, header, phone chrome, footer, drawers,
             * scripts), and in approvedRemovals() everything the old documents
             * drew around it -- and the CONTENT between, the hero, the grid of
             * cards, the article and its related row, is still compared byte
             * for byte. The layout's chrome is the same chrome every other page
             * in this walk compares; JournalSharedHeaderTest pins that the two
             * pages draw it, and that the SEO tags in the head did not move.
             *
             * FIRST IN THE LIST, so no rule below counts the layout's chrome on
             * these two pages. Each fires on exactly 'blog' and 'blog/{slug}'.
             */
            'the journal and an article on the shared layout: head and header (Lane BH)' => [
                'pattern' => '#\A.*?<main id="content">\n    <div class="kbb-journal">\n#s',
                'hits' => 2,
            ],
            'the journal and an article on the shared layout: footer and scripts (Lane BH)' => [
                // Anchored on the content's first element at the very start, which
                // only these two pages have once the rule above has cut their head.
                'pattern' => '#\A(?=<section class="hero">|<article id="article">).*?\K</div>\n</main>.*\z#s',
                'hits' => 2,
            ],
            // The shop's 404 page in its place (Lane NF): see the removal of
            // the same name. The whole '(404)' document, design B, once.
            'the bare framework 404 page (Lane NF)' => [
                'pattern' => '#\A.*?<div class="nf nf-b"[^>]* data-nf="b">.*\z#s',
                'hits' => 1,
            ],
            /*
             * The shop as a Home Screen app (Lane PW, App -> Site App): the
             * owner asked for it ("just build the app for the site"), so it
             * ships ON. Eight tags in the head of every storefront page -- the
             * layout's 33 and the four standalone documents (blog, article,
             * review wall, skin quiz), 37 in all: the manifest, the apple-touch-icon, the two
             * "capable" metas, the iOS status bar and title, and the deferred
             * script that registers the worker after load. Nothing else on the
             * page moves; everything around it is still compared byte for byte.
             */
            'the Home Screen app head tags (Lane PW)' => [
                'pattern' => '#<link rel="manifest" href="/manifest\.webmanifest">\n'
                    .'<link rel="apple-touch-icon" href="/site-app/icons/apple-180\.png\?v=[0-9a-f]{10}">\n'
                    .'<meta name="mobile-web-app-capable" content="yes">\n'
                    .'<meta name="apple-mobile-web-app-capable" content="yes">\n'
                    .'<meta name="apple-mobile-web-app-status-bar-style" content="default">\n'
                    // Lane IC, deliberately: the installed app's status bar takes the
                    // header's colour ("extend the background color to the top end").
                    // Standalone only, so a shopper in the browser sees nothing new.
                    .'<meta name="theme-color" media="\(display-mode: standalone\)" content="\#FFFFFF">\n'
                    .'<meta name="apple-mobile-web-app-title" content="K-Beauty Bliss">\n'
                    .'<script src="/site-app\.js\?v=[0-9a-f]{10}" data-sw="/sw\.js" data-scope="/" defer></script>\n'
                    // Lane IN, deliberately: the owner asked for the Install button
                    // to install directly ("i don't want popup"). While the footer's
                    // app row is switched on, one inline line catches Chrome's install
                    // offer should it fire before the deferred script, and takes it only
                    // on a page that drew the row. Nothing visible.
                    .'<script>addEventListener\(\'beforeinstallprompt\',function\(e\)\{if\(document\.querySelector\(\'\.kfa\[data-kfa\]\'\)\)\{e\.preventDefault\(\);window\.__kbbBip=e\}\}\)</script>\n#',
                'hits' => 35, // 37 -> 35 (Lane BH): the journal and an article are on the layout now, and their chrome is cut whole by the first rule in this list.
            ],
            /*
             * THE FOOTER'S APP ROW (Lane FB, 6 October). The owner asked for it
             * — "write something, Get orders update, restock alerts & coupons …
             * the frosted glass design is fine, but i don't want to cover the
             * logo, it should downside the big logo" — so it ships ON (CLAUDE.md
             * rule 1, the 30 September reversal), on every page that draws the
             * new footer: one <section class="kfa"> between the big name and the
             * bottom bar, its install sheets in a <template> inside it. Exactly
             * this element and nothing else; the footer around it is still
             * compared byte for byte. 29 pages: every one
             * with the new footer (the cart and checkout draw the slim bar, the
             * four standalone documents no footer). Switch: Appearance → Footer → App row.
             */
            'the footer app row (Lane FB)' => [
                'pattern' => '#  <section class="kfa" aria-labelledby="kfa-h" data-kfa="[^"]*"><div class="kft-wrap"><div class="kfa-g">\n.*?  </template></section>\n#s',
                'hits' => 29,
            ],
            /*
             * INSTANT PAGE CHANGES (Lane SP, Appearance -> Site layout -> Page
             * speed). The owner asked for speed ("super blazing speed with
             * super fast shifting layout from one page to another"), so "Open
             * pages instantly" ships ON: one line in the head of every page that
             * carries the Site App tags -- the script that adds the
             * prefetch-on-hover rules. The fade ships off (measured to cost
             * speed), so it adds nothing here. Nothing else moves.
             */
            'the instant page change script (Lane SP)' => [
                'pattern' => '#<script data-r="[^"]*">\(function\(d,w\)\{[^<]*</script>\n#',
                'hits' => 35, // 37 -> 35 (Lane BH): the journal and an article are on the layout now, and their chrome is cut whole by the first rule in this list.
            ],
            // 2.60.393: the phone's 1 / 2 column buttons, beside Sort, on the
            // shop and category listing (hidden on a laptop).
            'the phone 1 / 2 column buttons (2.60.393)' => [
                'pattern' => '#                <div class="colsel colsel-m" id="colselm"><button type="button" data-m="1" title="1 column"><svg [^<>]*><rect [^<>]*/></svg></button><button type="button" data-m="2" class="on" title="2 columns"><svg [^<>]*><rect [^<>]*/><rect [^<>]*/></svg></button></div>\n#',
                'hits' => 2,
            ],
            /*
             * THE FILTERS DRAWER'S BACKDROP (Lane FP). The owner: "when clicked
             * on empty area, the filter panel must be need to hide auto." One
             * empty element after the filter rail on every listing that draws
             * it -- /shop/ and a collection in this walk -- hidden on a laptop
             * and dimmed beside the open drawer on a phone. Nothing else in the
             * markup moves; the rest of the patch is stylesheet and script.
             */
            // Lane SO: 0. The owner, 5 October: "remove the filter at all ...
            // keep turned off completely on all pages by default." With both
            // Filters switches off (shipped) no drawer is drawn, so neither is
            // its backdrop; the filter rail itself is in approvedRemovals().
            'the Filters drawer backdrop (Lane FP)' => [
                'pattern' => '#    <div class="fscrim" data-kbb-close></div>\n#',
                'hits' => 0,
            ],

            /*
             * THE SUPER SALE STRIP (Lane SS) IS NO LONGER HERE: Lane SP3 turned
             * it off on every page, because the owner asked ("turned off the
             * strip by default on all pages"). Its rule was removed with it;
             * the banner stays in the library, and PageBannersTest draws it
             * once switched on.
             */
            /*
             * THE SUPER SALE TITLE BLOCK, CONFIGURED (Lane PH). Paired with
             * the removal of the same name in approvedRemovals(). The pattern
             * names the shipped state: no dot on either device (kbb-ph-nod,
             * -nom), the count and the "All products" button hidden on both
             * (kbb-ph-hd kbb-ph-hm), the intro hidden on a phone as it always
             * was, the title SHOWN — he asked for the
             * control to hide it, not for a page without a visible heading.
             * `[^<]*` for the stylesheet: PageHeaders::CSS has no `<`.
             */
            'the Super Sale title block, configured (Lane PH)' => [
                'pattern' => '#<style id="kbb-ph-css">[^<]*</style>\n'
                    .'<div class="sh kbb-ph kbb-ph-nod kbb-ph-nom" style="[^"]*" data-kbb-ph="collection:super-sale">\n'
                    .'<nav class="crumb kbb-ph-c kbb-ph-hd kbb-ph-hm"><a href="[^"]*">Home</a> / <span>Super Sale</span></nav>\n'   // Breadcrumbs ship off
                    .'<h1 class="kbb-ph-t">Super Sale <span class="cnt kbb-ph-hd kbb-ph-hm">[^<]*</span></h1>\n'
                    .'<p class="kbb-ph-p kbb-ph-hm">[^<]*</p>\n'   // hidden on a phone, as .sh p always was
                    // Lane SO: no "All products" link to /shop/ any more -- it
                    // was hidden by CSS, now it is not sent ("the app should
                    // not display any external link"; Site layout → Product
                    // grid → "Links to the whole shop ..." brings it back).
                    .'</div>\n#',
                'hits' => 1,
            ],

            /*
             * THE TOP AREA OF /super-sale/ (Lane SP3). The owner: "also remove
             * any space between header area and main site header. and give
             * option to control to spacings." The configured title block above
             * (cut first, by its own rule) now stands in a top area of its own
             * before the listing's section: the area's <style>, the <div> whose
             * style carries his spacing — 0 above and between, the header's own
             * 22/12px below, as it was — and the header's .sec > .wrap. Only a
             * page that draws a configured header or a strip has one; no other
             * page in this walk does. Must stay AFTER the title block's rule.
             */
            'the Super Sale top area, its spacing at 0 above (Lane SP3)' => [
                'pattern' => '#<style id="kbb-pt-css">[^<]*</style>\n'
                    .'<div class="kbb-pt" style="--pt-td:0px;--pt-gd:0px;--pt-bd:22px;--pt-tm:0px;--pt-gm:0px;--pt-bm:12px" data-kbb-pt="collection:super-sale">\n'
                    .'<div class="sec kbb-pt-s"><div class="wrap">\n'
                    .'</div></div>\n'
                    .'</div>\n#',
                'hits' => 1,
            ],

            /*
             * THE SIDE TAB ON THE CART AND THE CHECKOUT, PHONES ONLY. (Lane WS)
             *
             * The owner: "on cart and checkout mobile pages, i want the
             * whatsapp floating to move to the left side of the screen, stiky
             * type vertical bar, having 24/7 Support + whatsapp animated icon".
             * It ships ON (rule 1 since 30 September), so the two pages that
             * declare WhatsAppButton::TAB_SECTION gain ONE element, cut here:
             * the tab, between the round button and its script. Its stylesheet
             * and the bubble's one-statement guard land inside the <style> and
             * <script> the rule below already cuts whole (both carry no `<`),
             * and WhatsAppButtonTabTest pins those bytes.
             *
             * MUST STAY ABOVE THE WHATSAPP RULE: that pattern expects the
             * button's </div> to be followed by its <script>, and on these two
             * pages it is not until this element is gone.
             *
             * THE PATTERN NAMES THE SHIPPED DEFAULTS — 26px (--q:1), centred
             * (--y:50), the blush palette, animated, "24/7 Support" — so a
             * default that moves without this walk being told is red here.
             * 3: the cart with a basket, the empty cart (still the cart page,
             * so still the tab), and the checkout with a basket. Every other
             * page in the walk carries none of it.
             */
            'the WhatsApp side tab on the cart and checkout (Lane WS)' => [
                'pattern' => '~<div class="kbt-z" style="--q:1;--y:50;--c1:#FFE1EA;--c2:#FFF0D9;--c3:#E2F6EA">'
                    .'<a class="kbt" href="[^"]*" target="_blank" rel="noopener" aria-label="24/7 Support · Chat with us on WhatsApp">'
                    .'<span class="kbt-i"><svg class="kbw-i"[^>]*>.*?</svg></span><span class="kbt-l">24/7 Support</span></a></div>\n~s',
                'hits' => 3,
            ],

            /*
             * THE FLOATING WHATSAPP BUTTON, ON EVERY STOREFRONT PAGE. (Lane WA)
             *
             * The owner: "i need a floating whatsapp icon with outer layers
             * animation type circle continues. on the bottom right side" — and
             * he picked design G, "Orbit team", off the preview. It ships ON
             * (rule 1 since 30 September: what he asked for is the shop's new
             * state), so every page that draws the store layout, plus the
             * journal, an article, the review wall and the skin quiz, gains
             * ONE block: the button's <style>, the button, and the bubble's
             * one-line script. Nothing else on any page moves.
             *
             * THE PATTERN NAMES THE SHIPPED DEFAULTS — design G, 60px (--k:1),
             * phone 16 from the right and 20 from the bottom, desktop 24 and
             * 24, the calm 14s orbit, the bubble on — so a default that moves
             * without this walk being told is red here and not only in
             * WhatsAppButtonTest. `[^<]*` for the stylesheet: it carries no `<`
             * (it is a constant of App\Services\WhatsAppButton), so the match
             * cannot run past its own close.
             */
            'the floating WhatsApp button (Lane WA)' => [
                'pattern' => '#<style id="kbb-wa">[^<]*</style>\n'
                    .'<div class="kbw kbw-G" style="--k:1;--mt:auto;--mr:16px;--mb:20px;--ml:auto;--dt:auto;--dr:24px;--db:24px;--dl:auto;--spd:14s" id="kbbWa">'
                    .'.*?</div></div>\n'
                    .'<script>\(function\(\)\{var b=document\.getElementById\(\'kbbWaB\'\)[^<]*</script>\n#s',
                // 37: every storefront document the walk renders — the same
                // set the Outfit faces reach (Lane PLC's 37): the 33 that draw
                // the store layout and the four that carry their own <html>.
                'hits' => 35, // 37 -> 35 (Lane BH): the journal and an article are on the layout now, and their chrome is cut whole by the first rule in this list.
            ],

            /*
             * ROW 55 — THE OWNER'S NEW HOMEPAGE, SECTIONS 2, 3 AND 5–9. (Lane HA)
             *
             * The owner, 3 October: the banner, then Big savings bundles, Best
             * Sellers, Brands, #KBeautyBliss Spotted, Trending, the Blog, Under
             * AED 54, a two-column feature and About us — "don't include
             * anything from our existing homepage on extreabeauty, except
             * banner". ONE PAGE, the homepage. Each new section is cut from the
             * AFTER side here, whole, with the blank lines Blade leaves around
             * it; the three old sections they replace (the brand strip, the
             * journal rail and the About band) are cut from the BEFORE side in
             * approvedRemovals(). What is left on both sides — the hero, the
             * bundles carousel and the old Spotted strip until Lane HB's
             * partial lands — must still compare byte for byte, and does.
             *
             * One rule per section, each required to fire exactly once, and
             * each anchored on the section's own class — `hs hs-<key>` is
             * printed by App\Support\HomeSections and by nothing else on the
             * shop. The cards inside the three product rails are NOT compared
             * here, and they do not need to be: they are <x-product-card>
             * through partials/home/grid, the same renders as every listing in
             * this walk, and HomeRow55SectionsTest pins that the card partial
             * is the one these sections use.
             */
            'Row 55: Best Sellers, section 2 (Lane HA)' => [
                'pattern' => '#\n*<section class="sec hs hs-rail hs-bestselling\b[^"]*"[^>]*>.*?</div></section>\n*#s',
                'hits' => 1,
            ],
            'Row 55: Shop Top Korean Beauty Brands, section 3 (Lane HA)' => [
                'pattern' => '#\n*<section class="sec hs hs-brands\b[^"]*"[^>]*>.*?</div></section>\n*#s',
                'hits' => 1,
            ],
            'Row 55: Trending K-Beauty This Week, section 5 (Lane HA)' => [
                'pattern' => '#\n*<section class="sec hs hs-rail hs-trending\b[^"]*"[^>]*>.*?</div></section>\n*#s',
                'hits' => 1,
            ],
            'Row 55: Korean Skincare Tips & Guides, section 6 (Lane HA)' => [
                'pattern' => '#\n*<section class="sec hs hs-blog\b[^"]*"[^>]*>.*?</div></section>\n*#s',
                'hits' => 1,
            ],
            'Row 55: K-Beauty Under AED 54, section 7 (Lane HA)' => [
                'pattern' => '#\n*<section class="sec hs hs-rail hs-under54\b[^"]*"[^>]*>.*?</div></section>\n*#s',
                'hits' => 1,
            ],
            'Row 55: the two-column feature, section 8 (Lane HA)' => [
                'pattern' => '#\n*<section class="sec hs hs-feature\b[^"]*"[^>]*>.*?</div></div></section>\n*#s',
                'hits' => 1,
            ],
            'Row 55: About K-Beauty Bliss UAE, section 9 (Lane HA)' => [
                'pattern' => '#\n*<section class="sec hs hs-about\b[^"]*"[^>]*>.*?</div></div></section>\n*#s',
                'hits' => 1,
            ],

            /*
             * THE CATEGORY TITLE HEADER, ON A CATEGORY WITH NO PICTURE.
             *                                                       (Lane PY)
             *
             * The owner, on Lane PT's preview: "by default make the title name
             * left side as before, i just wanted the background image or light
             * colored box containing skincare makeup etc products icons. if no
             * image." The walk's category has no picture, so it now opens on
             * the LIGHT BOX: the header's stylesheet in <head>, and the
             * <section> in place of the plain eyebrow / <h1> / line, which
             * 'the plain category title' in approvedRemovals() cuts from the
             * other side. ONE page, the category archive; /shop/, the brand
             * page and every other page are compared byte for byte as before.
             *
             * THE PATTERN NAMES THE SHIPPED DEFAULTS -- box, dark words, Start,
             * no treatment, Blush icons -- so a default that moves without
             * this walk being told is red here, not just in a unit test.
             * PyCategoryHeaderOptionsTest pins what is inside the section.
             */
            'the category title header stylesheet (Lane PY)' => [
                'pattern' => '#<link rel="preload" as="style" href="/build/assets/kbb-title-header-[A-Za-z0-9_-]+\.css" /><link rel="stylesheet" href="/build/assets/kbb-title-header-[A-Za-z0-9_-]+\.css" />#',
                'hits' => 1,
            ],
            'the category title header, the light box (Lane PY)' => [
                // 2.60.350: `kbb-th--v-bottom` -- the words at the foot of the box,
                // as the owner asked.
                'pattern' => '#<section class="kbb-th kbb-th--box kbb-th--dark kbb-th--a-start kbb-th--v-bottom kbb-th--t-none kbb-th--box-blush" style="[^"<>]*" data-kbb-title-header aria-labelledby="kbb-th-title">\n.*?</section>\n#s',
                'hits' => 1,
            ],

            /*
             * THE BRAND LINE, OFF ON PHONES, ON THE CART AND CHECKOUT ROWS.
             *                                                      (2.60.348)
             *
             * The owner: "give option to hide un-hide the brands names. on
             * desktop and mobile seperate options. keep for desktop ON by
             * default, and OFF for mobile devices. same on checkout rows." The
             * cart page gains the one phone rule CartPage::rowCss() prints at
             * those defaults; the checkout summary gains the brand line its
             * rows never had, marked off for a phone. Nothing else moves.
             * CartCheckoutBrandSwitchTest pins both.
             */
            'the cart rows phone brand rule (2.60.348)' => [
                'pattern' => '#<style id="kbb-cartrows">@media \(max-width:600px\)\{\.kbb-cartpage \.items \.ci \.cbrand\{display:none\}\}</style>\n#',
                'hits' => 2,
            ],
            'the checkout rows brand line (2.60.348)' => [
                'pattern' => '#                <div class="b co-rb-nom">[^<\n]*</div>\n#',
                'hits' => 2,
                'perPage' => 9,
            ],

            /*
             * THE SEARCH PANEL CARRIES ITS TRENDING WORDS.         (2.60.346)
             *
             * The owner: "the default search tags box showing with little delays
             * upon click on search box. it must be shown immidiately". The
             * header's #kbbSuggest gains data-starter, the words the panel
             * paints on focus without waiting for /api/search/starter. An
             * attribute, never visible text; one per page that has a header.
             * SearchStarterInstantTest pins what it carries.
             */
            'the search panel seed (2.60.346)' => [
                'pattern' => '#\n\s*data-starter="[^"]*"#',
                'hits' => 31,
            ],

            /*
             * THE BRAND PAGE'S GRID GAINS AN ID.                      (Lane PR)
             *
             * The owner: "Remove pagination from the categories and brands; it
             * should load more products via scroll ... keep this on by
             * default." A brand page loads its next batch into its grid, and
             * the pager names that grid by id (`data-grid="#brandGrid"`) — see
             * Store\BrandController::show(). The walk's brand carries fewer
             * products than one batch, so there is no pager to cut here; the
             * paged state is GridCardOwnerAsksTest's to pin.
             */
            'the brand page grid id (Lane PR)' => [
                'pattern' => '# id="brandGrid"#',
                'hits' => 1,
            ],

            /*
             * THE BRAND NAME BECOMES A LINK TO ITS BRAND PAGE.      (2.60.336)
             *
             * The owner drew an arrow at the brand eyebrow on his own product
             * page. It was a bare <div>, so the one word on that page naming a
             * brand went nowhere, while /brands/{slug}/ -- a real page with
             * that brand's copy, logo and product grid, and in the sitemap --
             * sat unlinked from every product it sells.
             *
             * PAIRED, and that is the point of the pattern below: the REMOVED
             * side is the bare text node and the INSERTED side is the same text
             * wrapped in an anchor, so the brand's own NAME is still compared
             * byte for byte on both sides. An approval that swallowed the whole
             * element would stop noticing if the name itself changed, or
             * vanished.
             *
             * The element, its class and its id are outside the cut on both
             * sides for the same reason: `.bb-brand` carries the eyebrow's type
             * and the spacing above the product name, and `#bbBrand` is read
             * elsewhere, so a change to either must still be caught.
             */
            'the brand name wrapped in a link to its brand page (2.60.336)' => [
                /* ANCHORED TO THE BRAND ELEMENT, NOT TO "an anchor tag".
                   The first draft read `<a href="/brands/…">|</a>(?=</div>)`
                   and matched FIFTEEN times across the walk -- every brand
                   link anywhere in the shop, and every anchor that happened to
                   close before a </div>. A pattern that wide would forgive a
                   link appearing somewhere nobody approved, which is the whole
                   thing this list exists to stop.
                   `id="bbBrand">` is fixed-width, so it works as a lookbehind;
                   the closing half is pinned to the element that actually
                   follows the eyebrow on this page. */
                'pattern' => '#(?<=id="bbBrand">)<a href="/brands/[^"]+/">#s',
                'hits' => 1,
            ],

            /* The closing half, as its own entry rather than an alternation.
               Written as one alternation first, and the pair reported 1 of 2 --
               which says nothing about WHICH half missed. Two entries name it.

               ▲ `\K` RATHER THAN A LOOKBEHIND, and that is what makes this
                 safe. The tag has to be pinned to the brand eyebrow, but the
                 brand NAME sits between the anchor and it and PCRE lookbehind
                 must be fixed width. A bare `</a>(?=</div>)` was tried and
                 fired EIGHT times on the home page alone -- it would have
                 excused an anchor appearing anywhere nobody approved, which is
                 the whole thing this list exists to stop. `\K` resets the
                 match start, so the pattern reads the eyebrow and its name for
                 context and cuts only the tag. */
            'the brand link\'s closing tag (2.60.336)' => [
                'pattern' => '#id="bbBrand">[^<]*\K</a>#s',
                'hits' => 1,
            ],

            /*
             * THE FLAG BAR — Lane FB, Appearance → Header → Flag bar.
             *
             * The owner: "i need thin bar as same as attached, having uae flat,
             * then text and then korea flag. (This bar is only for mobile, keep
             * this turnef off for desktop by default)." CLAUDE.md rule 1 allows
             * exactly one exception to "a new setting ships at the value the
             * page already has" — a default the owner asked for in as many
             * words — and that sentence is it, so the strip ships on for phones.
             *
             * WHAT IT DOES TO A PAGE, measured across the whole walk: 39 pages
             * render, 31 gain the strip, and on all 39 the rest of the document
             * is identical. The diff on each of the 31 is one insertion at one
             * point:
             *
             *     before   …--dv-in:12px">⏎⏎⏎        <header class="hd-sticky …
             *     after    …--dv-in:12px">⏎⏎⏎<div class="kfb kfb-m kfb-pill" …
             *
             * — the eight spaces of indentation move inside the strip's own
             * markup and <header> follows it unchanged. Nothing on the desktop
             * shop moves at all: `kfb-d` is absent from the class list and
             * `.kfb` is display:none until a class inside a media query says
             * otherwise.
             *
             * The eight pages WITHOUT it are the quick-view fragment, the
             * checkout and the order-received page (both declare `bare`),
             * Laravel's own 404 document, and the four standalone documents —
             * the Journal, an article, the quiz and the review wall — which
             * include neither partials.header nor partials.mobile-chrome and so
             * have no site header for a strip to sit above.
             *
             * THE PATTERN IS THE OPENING TAG AND ITS OWN CLOSE, not `.*?` from
             * one class to the next `</div>`: the strip is `<div class="kfb …">
             * <div class="kfb-in">…</div></div>`, two levels, so a lazy match to
             * the FIRST `</div>` would leave the outer close behind and a rule
             * that cuts more than its element is a rule that hides the next
             * lane's regression.
             */
            /*
             * ▲ 31 -> 0, ON PURPOSE.                              (Lane PI-B)
             *
             * "Turn off the top countries bar entirely for now." Both switches
             * ship off, flagBarOn() is false, and the strip is not drawn on any
             * page of the walk — so every one of the 31 pages that gained it is
             * back to its BASE_COMMIT bytes at this point, which is what this
             * count now says. Kept at 0 rather than deleted because 0 is a pin:
             * a default that flips back on by accident puts the strip on 31
             * pages and this is red at 31, naming the strip.
             */
            /*
             * ▲ 0 -> 1, ON PURPOSE.                                (Lane HC)
             *
             * "ONLY FOR MOBILE: turn this off in laptop by default ... below
             * the main banner, i need that countries strip". The homepage — and
             * ONLY the homepage — draws it again, as the `countries` section:
             * phones on, laptops off by `d-off` in ONE document. Every other
             * page still follows the two Flag bar switches, still off, so the
             * count is the homepage's one strip and a default that put it back
             * above the header everywhere is red at 31.
             */
            'the flag bar above the header (Lane FB)' => [
                'pattern' => '#<div class="kfb [^>]*>\s*<div class="kfb-in">.*?</div>\s*</div>\n#s',
                'hits' => 1,
            ],

            /*
             * THE TOP STRIP — Appearance → Homepage content → Top strip. (Lane HC)
             *
             * The owner's "top bar strip, same color, same text and size",
             * phones only: one constant <style id="kbb-kts"> and one element,
             * directly inside .kbb-home, hidden from 901px by `d-off`. Homepage
             * only, so exactly one hit; cut whole with the newline after it.
             */
            'the homepage Top strip (Lane HC)' => [
                'pattern' => '#<style id="kbb-kts">[^<]*</style><(div|a) class="kts[^"]*"[^>]*>.*?</(div|a)>\n#s',
                'hits' => 1,
            ],

            /*
             * THE BREADCRUMB SWITCHES — Appearance → Header → Breadcrumbs.
             *                                                     (Lane PI-B)
             *
             * The owner: control of the trail's spacing and an on/off,
             * separately for mobile and desktop, "by default keep it off". So
             * every page that extends the layout, plus the journal article
             * (the one standalone document that draws a trail), gains ONE
             * <style id="kbb-crumbs"> in its head, and at the shipped settings
             * it is two media queries of `display:none`. The trail's markup
             * itself is untouched — it is hidden, not removed, so the
             * BreadcrumbList JSON-LD and every byte of the trail are still
             * compared below.
             *
             * WHAT IT DOES TO A PAGE, read off the diff before this rule was
             * written: one insertion, directly after the last stylesheet link
             * (or the article's own </style>), and nothing else on any page.
             * 34 OF THE WALK'S 39: the 31 that used to carry the flag bar, the
             * checkout and the order-received page (they extend the layout as
             * `bare`), and the article. The five WITHOUT it do not extend the
             * layout and draw no trail — the quick-view fragment, Laravel's
             * 404, the Journal index, the quiz and the review wall.
             *
             * `[^<]*` AND NOT `.*?`: the rule is literals and integers with no
             * `<` in it (BreadcrumbControlsTest pins that it prints escaped and
             * byte-identical), so the match cannot run past its own close.
             */
            'the breadcrumb switches in the head (Lane PI-B)' => [
                'pattern' => '#<style id="kbb-crumbs">[^<]*</style>\n#',
                'hits' => 33, // 34 -> 33 (Lane BH): the journal and an article are on the layout now, and their chrome is cut whole by the first rule in this list.
            ],

            /*
             * THE "ADDED" TICK — Appearance → Cart panel → Behaviour → "When
             * something is added". (Lane PI-B)
             *
             * One element inside the cart panel, after the panel's own
             * fragment: hidden (opacity 0) until cart.js puts `on` on it for
             * 450ms after an add. It is in every document that draws the
             * panel. `.*?` is safe: the element holds a span and an svg and no
             * nested <div>, so the lazy match ends at its own close.
             */
            'the added tick inside the cart panel (Lane PI-B)' => [
                'pattern' => '#<div class="kbb-addmark" id="kbbAddMark">.*?</div>\n#s',
                // 33: every page that draws the cart panel. One fewer than the
                // breadcrumb <style> above, because the journal article carries
                // that and has no cart panel of its own.
                'hits' => 33,
            ],

            /*
             * THE SELF-HOSTED OUTFIT FACES — Lane PERF's arrangement, paired
             * with approvedRemovals()' 'the Google Fonts request for Poppins'.
             *
             * ONE <link rel=preload> for the `latin` subset the page actually
             * uses, and one <style id="kbb-outfit"> holding all ten @font-face
             * rules css2 returns.
             *
             * `.*?` IS SAFE HERE AND ONLY BECAUSE OF THE id. The block is
             * <style id="kbb-outfit">…</style> with no nested <style>, so the
             * lazy match ends at its own close; the flag bar's rule above needs
             * two levels of </div> for exactly the reason this one does not.
             *
             * ▲ FOUR PRELOADS BECAME ONE, AND THE COUNT IS UNCHANGED AT 37.
             *                                                       (Lane PLC)
             *
             * The owner asked for Outfit site-wide. Outfit is a VARIABLE font:
             * its five weights are ONE latin file where Poppins needed five, so
             * `{4}` became `{1}` — not because a preload was dropped from the
             * critical path, but because there is one file to preload. Measured
             * on the latin subset: Poppins 39,272 bytes across five files,
             * Outfit 32,292 in one.
             *
             * THE PAGE COUNT DOES NOT MOVE, and that is the half worth
             * watching. 37 pages carried the block before and 37 carry it now:
             * the swap changed what is inside it, not which documents emit it.
             * A lane that preloads a second file, or loses the block on a
             * standalone document, moves this number and says so loudly.
             */
            'the self-hosted Outfit faces (Lane PLC)' => [
                'pattern' => '#(?:<link rel="preload" as="font" type="font/woff2" crossorigin href="[^"]+">\n){1}'
                    .'<style id="kbb-outfit">.*?</style>\n#s',
                'hits' => 35, // 37 -> 35 (Lane BH): the journal and an article are on the layout now, and their chrome is cut whole by the first rule in this list.
            ],

            /*
             * The same three footer headings, as <h2>. See the removal.
             *
             * `perPage` is 3 because the footer draws three columns with a
             * heading and one without, on every page that has a footer. The
             * per-page guard stays armed at that number rather than being
             * switched off: four on one page and two on another would still add
             * to 87.
             */
            /*
             * ▲ NOW 0 (Lane HB): the walk renders the NEW site footer, which has
             * no `.fcol` at all — its column headings are `<h2 class="kft-ch">`,
             * inside the block 'the site footer: the approved new design' in
             * approvedStorefrontChanges() replaces whole. The rule stays, at 0,
             * because Appearance → Footer → "Previous" draws the old footer
             * with exactly these h2s again, and the day the walk renders that
             * design this count is the thing that says so.
             */
            'the footer column headings as h2 (Lane PERF)' => [
                'pattern' => '#<div class="fcol"><h2>[^<]*</h2>#',
                'hits' => 0,
                'perPage' => 3,
            ],

            /*
             * THE PLACE-ORDER OVERLAY — Lane PLC.
             *
             * The owner asked for it in as many words: "when press the PLACE
             * ORDER button, it should freeze the page and come nice real time
             * loading bar or filled circle with text, Placing your order…".
             * CLAUDE.md rule 1 allows exactly one exception to "a new setting
             * ships at the value the page already has" — a default the owner
             * asked for in as many words — and this is a feature rather than a
             * setting: there is no switch, it is simply what the button does
             * now. So it ships on.
             *
             * WHAT IT DOES TO THE WALK, measured across all thirty-nine pages:
             * ONE page moves, and at one point. (with a basket) /checkout, at
             * byte 101353, where partials/checkout/placing-overlay's <style>,
             * its <template> and its <script> are pushed into the scripts stack
             * after partials/checkout/stripe-elements. The diff is one
             * insertion:
             *
             *     before   …})();⏎    </script>⏎    ⏎<style>⏎/* Scoped to …
             *     after    …})();⏎    </script>⏎    ⏎⏎<!--kbb-placing--><style>…
             *
             * — and the page picks up again at that same <style>, unchanged.
             *
             * EVERY OTHER PAGE IS BYTE FOR BYTE WHAT IT WAS, and two of them are
             * worth naming because they draw this lane's other two partials:
             *
             *   /checkout/success  partials/checkout/placed-tick draws NOTHING
             *                      without an order, and the walk requests this
             *                      page with no `order` in the query string.
             *   /cart              partials/checkout/return-notice draws NOTHING
             *                      without a flashed error, and no page in the
             *                      walk has one.
             *
             * Both includes are also GLUED to the markup that follows them —
             * see the comments at each call site — because an @include on a line
             * of its own leaves its own indentation and the newline of the
             * comment above it in the rendered page even when the partial
             * itself renders nothing. That is a real byte change for a partial
             * that drew nothing, and it is how a "this cannot possibly move the
             * page" edit moves the page.
             *
             * THE PATTERN IS THE FENCE AND ITS OWN CLOSE, not `.*?` to the next
             * `</script>`: the block contains a <style>, a <template> and a
             * <script>, so anything that stopped at the first closing tag would
             * leave the rest behind — and a rule that cuts less than its element
             * fails loudly, while one that cuts more hides the next lane's
             * regression. The leading \n is the newline the comment line above
             * the include contributes and is part of the insertion.
             */
            'the Place-order overlay on the checkout (Lane PLC)' => [
                'pattern' => '#\n<!--kbb-placing-->.*?<!--/kbb-placing-->\n#s',
                'hits' => 1,
            ],

            // ('the designed page background on the journal and an article
            // (Lane BG)' is gone with Lane BH: the two pages take the background
            // from kbb.css itself on the shared layout, the copy partial is
            // deleted, and their head is cut whole by the first rule here.)

            /*
             * THE HEAD OF THE PRODUCT PAGE'S BUY COLUMN, REDRAWN AS LEDGER.
             *                                                     (Lane PDP2)
             *
             * The owner, verbatim: "Ledger design is fine for mobile and
             * desktop both. but don't end the page, this desgn + existing
             * reviews section, and related products section and then footer.
             * also in mobile you have used big bold font, whichi dont' want."
             *
             * He is answering docs/PDP-PRODUCT-PAGE-DESIGNS.md, which put five
             * whole product pages in front of him at
             * /admin-api/catalog/pdp-preview/{design}/{slug}. CLAUDE.md rule 1
             * has exactly one exception -- "a default the owner asked for in as
             * many words" -- and a page he picked out of five is it.
             *
             * ── WHAT THE PAIR CUTS, AND WHAT IS STILL COMPARED ──────────────
             *
             * One contiguous region of ONE page: from the product title down to
             * the blurb, stopping at the cart form. Inside it the price joined
             * the title's row, the rating rows gained a hairline and the blurb
             * gained a checkbox and a label. Everything else on that page --
             * the <head>, the header, the breadcrumb, the gallery, the whole
             * cart form, the bundle bars, the set contents panel, the stock
             * line, the buy row, the trust lines, the payment chips, the tabs,
             * the reviews section, the related grid and the footer -- is
             * OUTSIDE the cut and still compared byte for byte, which is what
             * makes "the rest of the page must not end" checkable rather than
             * asserted. Ledger is otherwise a stylesheet: see the block at the
             * foot of resources/css/kbb/kbb-product.css.
             *
             * ── THE TWO PATTERNS BEGIN AND END AT THE SAME BYTES ────────────
             *
             * `(?<=</div>)` is the brand line's closing tag on both sides, and
             * `\s*` then swallows the indentation between it and the region --
             * which is not the same on the two sides, because the @php block
             * that computes the struck price moved UP with the price it belongs
             * to and a Blade directive contributes its own leading whitespace to
             * the rendered page. Both cuts therefore start immediately after
             * `</div>` and both end immediately before `<form class="cart`, so
             * the bytes either side of the pair line up exactly.
             *
             * ── WHAT THIS STOPPED WATCHING, AND WHAT BUYS IT BACK ───────────
             *
             * The cut swallows the product name, both price figures, the VAT
             * sentence and the review-badge label, so this walk no longer sees
             * somebody rewriting any of them. tests/Feature/ProductPageLedgerTest
             * pins every one of those against the shipped English, by element,
             * which is the sharper form of the same check -- a rule that cuts
             * more than its own element has to say what it stopped watching.
             */
            'the Ledger head of the product buy column (Lane PDP2)' => [
                'pattern' => '#(?<=</div>)\s*<div class="bb-head">.*?(?=<form class="cart)#s',
                'hits' => 1,
            ],

            /*
             * THE OPENING TAG OF EVERY VARIANT / BUNDLE ROW, WHICH NOW CARRIES
             * THE PAIR OF FIGURES IT PRINTS.             (Lane PDP2, round 3)
             *
             * PAIRED WITH approvedRemovals()' 'the shipped variant row opening
             * tag', which is the same pattern against the old side. Both cut
             * the OPENING TAG ONLY -- `(?=<span class="vr")` closes the match
             * before the row's contents -- so the label, the struck figure, the
             * live figure and the tag badge are all still compared byte for
             * byte, on both sides. What the pair excuses is the two new
             * attributes and the blank line the `@php` block above them adds.
             *
             * THE DEFECT IT BUYS: pressing the 2-pack left the price block
             * reading `AED 99 / AED 140 / -25%` while the row said
             * `AED 149 / AED 140 / Save 6%` — the strike untidy, the badge a
             * false claim about money on the page where the shopper decides.
             * pdp.js had the tier's total and nothing else to write, so the two
             * figures beside it stayed as the server had rendered them.
             *
             * WHAT STOPPED BEING WATCHED, AND WHERE IT IS WATCHED INSTEAD:
             * `data-i`, `data-qty` and `data-price`, plus the `on` and `oos`
             * classes. tests/Feature/ProductPriceBlockFollowsTierTest reads
             * every one of those attributes out of a real render and asserts
             * what it holds — the 1-unit row's fallback pair, the empty pair on
             * a product that is not on sale, and a full-price variation's empty
             * pair — which is the sharper form of the same check.
             *
             * THE COUNT IS THE WALK'S OWN PRODUCT PAGE, which renders the three
             * default bundle tiers. A fourth would mean a tier table changed
             * under this walk, which is exactly the kind of thing the count is
             * for; `perPage` says the same thing per page rather than in total.
             */
            'the variant row opening tag, with its pair of figures (Lane PDP2)' => [
                'pattern' => '#\s*<div class="variant[^"]*" data-i="\d+"[^>]*>\s*(?=<span class="vr")#s',
                'hits' => 3,
                'perPage' => 3,
            ],

            /*
             * THE PRODUCT PAGE'S DELIVERY BOX, "AUTHENTICITY GUARANTEED" AND
             * SHARE BAR.                                              (Lane PW)
             *
             * The owner: "on product page i want two things further, one is the
             * same yellowish delivery box ... and another under add to cart
             * button, Authenticity guaranteed line ... under this section, i
             * want a nice bar of share it". He asked for all three, so all
             * three ship ON (CLAUDE.md, the 30 September reversal) and the
             * walk's product page gains them.
             *
             * THREE INSERTIONS ON ONE PAGE AND NOTHING ELSE, read off the diff:
             *
             *   <head>   one Vite tag group for kbb-pdp-trust.css and
             *            pdp-trust.js, appended to the `styles` stack after the
             *            sheets that were already there. Pushed by
             *            partials/product/trust-share-assets under @once.
             *   buy box  the delivery box, between the dispatch line and the
             *            buy row, ending in its own `<!--/pts-del-->` marker.
             *   form     the flex wrapper holding the authenticity line and the
             *            share bar, after the button row and before </form>,
             *            ending in `<!--/pts-stack-->`.
             *
             * Each partial ends in a marker comment so these cuts stop at the
             * block's OWN end rather than at "the next </div>", which on markup
             * three levels deep would leave a closing tag behind or swallow a
             * neighbour's. Every byte either side — the stock line, the buy
             * row, Buy it now, the trust lines, the payment chips, the rest of
             * the page and every other page in the walk — is still compared.
             * Quick view draws none of them, by choice: it is a glance at a
             * product, not a place to share it from.
             */
            'the product trust and share assets in <head> (Lane PW)' => [
                'pattern' => '#    <link rel="preload" as="style" href="[^"]*/kbb-pdp-trust-[^"]+" /><link rel="modulepreload" href="[^"]*/pdp-trust-[^"]+" /><link rel="stylesheet" href="[^"]*/kbb-pdp-trust-[^"]+" /><script type="module" src="[^"]*/pdp-trust-[^"]+"></script>#',
                'hits' => 1,
                'perPage' => 1,
            ],
            'the product delivery box (Lane PW)' => [
                'pattern' => '#<div class="pts-del" data-pts="del" .*?<!--/pts-del-->\n#s',
                'hits' => 1,
                'perPage' => 1,
            ],
            /*
             * Lane QB took the share row OUT of this wrapper (the owner: "i want
             * to remove the share row completely") — so it now holds the
             * authenticity line alone, and the cut is the same marker-to-marker
             * cut it always was. Renamed, not re-patterned.
             */
            'the authenticity line under Add to cart (Lane PW; share row removed by Lane QB)' => [
                'pattern' => '#<div class="pts-stack">\n.*?</div><!--/pts-stack-->\n#s',
                'hits' => 1,
                'perPage' => 1,
            ],

            /*
             * THE PHONE PAGE AS SECTIONS — the wrapper and the section boxes.
             *                                                       (Lane QA)
             *
             * The owner: "i want things need to work as sections. [...] give
             * functionality to drag an drop the positioning changing / sorting,
             * and ON/OFF anything. THIS message changes is only for MOBILE."
             *
             * TWO CUTS ON ONE PAGE, read off the diff, and everything around
             * them still compared byte for byte:
             *
             *   wrapper   `.wrap` gains `pdp-page`, the section classes and the
             *             integer custom properties ProductMobileSections
             *             writes. The pattern NAMES THE SHIPPED DEFAULTS — trust
             *             rows and Buy together off, the rating beside the price
             *             at both widths, his order, the 18px gap and the two
             *             named spacing exceptions — so a default that moves
             *             without this walk being told is red here.
             *   sections  the `.pm-sec` boxes that group two or more blocks of
             *             the buy column into one section — title, options,
             *             ready to ship, quantity + Add to cart — opened and
             *             closed with nothing else on their lines. The PDP2 cut
             *             above has already taken the ones inside the head
             *             (the price row, the short description, the pay-later
             *             cards and the share button); ProductMobileSectionsTest
             *             pins those by element.
             */
            'the product page wrapper as a column of sections (Lane QA)' => [
                'pattern' => '#(?<=<div class="wrap) pdp-page pm-off-trust pm-off-buytogether pm-rate-m-beside pd-rate-d-beside" '
                    .'style="--pm-gap:18px;--pm-o-gallery:1;--pm-o-title:2;--pm-o-short:3;--pm-o-price:4;--pm-o-paylater:5;'
                    .'--pm-o-bundles:6;--pm-o-ready:7;--pm-o-cart:8;--pm-o-delivery:9;--pm-o-auth:10;--pm-o-trust:11;'
                    .'--pm-o-paychips:12;--pm-o-buytogether:13;--pm-o-details:14;--pm-o-reviews:15;--pm-o-related:16;'
                    .'--pm-dm-short:-8px;--pm-dm-cart:-6px(?=">)#',
                'hits' => 1,
                'perPage' => 1,
            ],
            'the section boxes of the buy column (Lane QA)' => [
                'pattern' => '#<div class="pm-sec pm-(?:title|bundles|ready|cart)">|</div><!--/pm-->#',
                'hits' => 7,
                'perPage' => 7,
            ],

            /*
             * THE SHARE SHEET.                                        (Lane QB)
             *
             * "upon click it will open popup from bottom side same as attached
             * fro mamazon with same product image carry, title row, and
             * sharing platforms." One closed (`hidden`) dialog, pushed to the
             * `scripts` stack so it lands just before </body>, ending in its own
             * `<!--/pdp-share-->` marker. @once, so ONE per product page however
             * many partials include it, and none on any other page in the walk.
             * The icon that opens it is Lane QA's, beside the title, and is
             * registered by that lane.
             */
            'the share sheet before </body> (Lane QB)' => [
                'pattern' => '#<div class="pdp-share" id="pdpShareSheet" .*?<!--/pdp-share-->\n#s',
                'hits' => 1,
                'perPage' => 1,
            ],

            /*
             * THE HOMEPAGE #KBEAUTYBLISS SPOTTED STATIC GRID.          (Lane HS)
             *
             * The owner, 4 October: "for now homepage, there will be 6 static
             * images, and upon click on any image, it will take the user to the
             * actual page ... for now just put demo cards on homepage". The
             * carousel drew nothing in this walk (no post is ticked Homepage);
             * the grid is the new default and draws from settings alone, so the
             * homepage gains one section. The pattern NAMES THE SHIPPED STATE —
             * his heading, no line under it, and exactly six placeholder cards
             * linking to the Spotted page — so a default that moves, or a
             * seventh card, is red here.
             */
            'the homepage Spotted static grid, six placeholders (Lane HS)' => [
                // 2.60.385: the button to the Spotted page, the owner's "i need
                // the button also on this section" — right of the heading on a
                // laptop (`spt-hbtn`), under the pictures on a phone (`spt-sg-foot`).
                'pattern' => '#<section class="sec spt spt-lilac spt-tilt spt-noarr-m spt-peek-m spt-sg spt-btn-d-top dv" style="[^"<>]*" aria-labelledby="spt-h"><div class="wrap">\n'
                    .'  <div class="sh spt-head"><div><h2 id="spt-h">\#KBEAUTYBLISS Spotted</h2></div><span class="spt-hbtn"><a class="bndl-all spt-all" href="/kbeautybliss-spotted/">See every \#KBeautyBliss look<i><svg [^<>]*><path [^<>]*/></svg></i></a></span></div>\n'
                    .'  <ul class="spt-sgl">\n'
                    .'(?:    <li><a class="spt-sgc" href="/kbeautybliss-spotted/" aria-label="\#KBeautyBliss Spotted photo [1-6]"><span class="spt-sgph"><svg [^<>]*><path [^<>]*/><circle [^<>]*/></svg></span></a></li>\n){6}'
                    .'  </ul>\n'
                    .'  <div class="spt-foot spt-sg-foot"><a class="bndl-all spt-all" href="/kbeautybliss-spotted/">See every \#KBeautyBliss look<i><svg [^<>]*><path [^<>]*/></svg></i></a></div>\n'
                    .'</div></section>#',
                'hits' => 1,
                'perPage' => 1,
            ],
            // The typed address fields in the picker row's place, and the
            // listener that re-prices the delivery when the emirate changes
            // (Lane CK): see the removals of the same lane. Checkout only, once.
            'the checkout typed address fields (Lane CK)' => [
                'pattern' => "#<p class=\"form-row form-row-wide validate-required\" id=\"billing_address_1_field\".*?id=\"billing_country_field\".*?</p>\n#s",
                'hits' => 1,
            ],
            'the checkout typed address fields spacing rule (Lane CK)' => [
                // Either wording: Lane AD's list mode prints the same rule
                // with City and Emirate swapped, plus the list's chevron.
                'pattern' => "#<style>\n/\* One rhythm down the four boxes(?: \(Lane CK\)\.|, as Lane CK set it).*?</style>\n#s",
                'hits' => 1,
            ],
            /*
             * THE ORDER SUMMARY FOLDED TO ONE ROW (Lane CK, part 2). The owner:
             * "replace the whole summary section to this single thin row, with
             * cart icon, Order Summary text, then order total, and then down
             * pink (our color) arrow". Appearance -> Checkout page -> Fields &
             * attention -> "Order summary: collapsed to one row" ships ON: the
             * row and its toggle script at the top of the summary, its
             * stylesheet, and the one class on <aside> that the stylesheet
             * folds under. Nothing in the summary is removed -- it is all still
             * compared byte for byte below the row. Off, none of the three.
             */
            'the checkout order summary row and its toggle (Lane CK)' => [
                'pattern' => "#<button type=\"button\" class=\"cosr\" id=\"kbbSumRow\".*?</button>\n<script>\n.*?\n</script>\n#s",
                'hits' => 1,
            ],
            'the checkout order summary row stylesheet (Lane CK)' => [
                'pattern' => "#<style>\n/\* ── THE ORDER SUMMARY ROW \(Lane CK\) [^\n]*\n.*?</style>\n#s",
                'hits' => 1,
            ],
            'the checkout summary fold class (Lane CK)' => [
                'pattern' => '# cosr-on(?=" id="kbbSummary">)#',
                'hits' => 1,
            ],
            /*
             * FLOATING LABELS ON THE CHECKOUT FIELDS (Lane CD). The owner:
             * "input fields will not dedicated heading, the heading it self
             * will show as place holder, and upon click the placeholder will
             * set as tiny heading inside the input fields, same like we have
             * on Create account page." Appearance -> Checkout page -> Fields &
             * attention -> "Floating labels on checkout fields" ships ON. Each
             * field's inside -- icon, control, label after it -- is cut here,
             * and the label-above shape it replaced is the removal of the same
             * name; the <p class="form-row ..." id="..._field"> row around it is
             * untouched and still compared. The component rows, the discount code, the
             * account password and the gift message. Off, none of them.
             */
            'the checkout floating-label fields (Lane CD)' => [
                'pattern' => '#<span class="woocommerce-input-wrapper fld kbb-fl[^"]*">.*?</label></span>(?=</p>)#s',
                // Three: name, phone, email. The four address rows are inside
                // Lane CK's typed-address insertion above, which cuts them whole.
                'hits' => 3,
                'perPage' => 3,
            ],
            'the checkout floating-label discount code (Lane CD)' => [
                'pattern' => '#<span class="fld kbb-fl kbb-fl-coupon ico">.*?</label></span>#s',
                'hits' => 1,
            ],
            'the checkout floating-label account password (Lane CD)' => [
                'pattern' => '#<span class="woocommerce-input-wrapper kbb-acct-pw fld kbb-fl ico" id="account_password_wrap" hidden>.*?</label></span>#s',
                'hits' => 1,
            ],
            'the checkout floating-label gift message (Lane CD)' => [
                'pattern' => '#<span class="fld kbb-fl ico"><span class="lead" aria-hidden="true"><svg.*?</svg>\s*</span><textarea name="gift_note".*?</label></span>#s',
                'hits' => 1,
            ],
            'the checkout emirate re-price listener (Lane CK)' => [
                // Lane AD's list mode hands the change on from the document
                // rather than the element; same opening words, same place.
                'pattern' => "#<script>\n/\\* THE EMIRATE PRICES THE DELIVERY\\b.*?\n</script>\n#s",
                'hits' => 1,
            ],
            /*
             * THE EMIRATE / STATE LIST (Lane AD). The owner: "if country is
             * UAE, all 7 emirates list should be there, if oman and so on".
             * Appearance -> Checkout page -> Fields & attention -> "Emirate /
             * state as a list" ships ON. The list itself is inside the typed
             * fields cut above; this is its country switch -- the JSON of the
             * lists and the script that swaps them -- on the checkout and on
             * both address-book pages, once each. And the address book's
             * City / Postcode / Emirate / Country with the list, in place of
             * the typed four (the removal of the same name). OFF prints none of
             * it, byte for byte (AddressRegionsCheckoutTest).
             */
            'the emirate list\'s country switch (Lane AD)' => [
                'pattern' => "#<script type=\"application/json\" id=\"kbb-state-lists\" data-for=\"[A-Z]{2}\">[^<]*</script>\n<script>\n\\(function \\(\\) \\{\n  var box = document\\.getElementById\\('kbb-state-lists'\\);.*?\n</script>\n#s",
                'hits' => 3,
            ],
            'the address book\'s emirate list above Country (Lane AD)' => [
                'pattern' => '#      <label class="ab-f ab-wide"><span>[^<]*</span>\n        <input type="text" name="line1"[^\n]*\n      <label class="ab-f ab-wide"><span>[^<]*</span>\n        <input type="text" name="line2"[^\n]*\n      <label class="ab-f"><span>[^<]*</span>\n        <input type="text" name="postcode"[^\n]*\n      <label class="ab-f"><span><span id="ab-state-label">.*?<select name="country" id="ab-country">.*?</select>\n      </label>\n#s',
                'hits' => 2,
            ],

            /*
             * NO PAGE ZOOM WHEN A FIELD TAKES FOCUS.                    (Lane ZM)
             *
             * The owner: "fix this in all type of devices and for all input
             * fields in our whole website". CSS only, and only inside
             * `@media (hover:none),(pointer:coarse)`, so a laptop draws exactly
             * what it did; on a phone fields are 16px and these two pages take
             * 1px of padding back so their boxes stay the height they were.
             * The skin quiz carries its own stylesheet instead of kbb.css, so it
             * also gains partials/no-focus-zoom. NoFocusZoomTest has the rest.
             */
            'the skin quiz: the no-focus-zoom floor (Lane ZM)' => [
                'pattern' => '#'.preg_quote('<style>@media (hover:none),(pointer:coarse){:where(input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]):not([type=file]):not([type=submit]):not([type=button]):not([type=reset]):not([type=image]),select,textarea,[contenteditable]:not([contenteditable=false])){font-size:16px!important}}</style>', '#').'#',
                'hits' => 1,
            ],
            'the skin quiz: its fields keep their 44px height at 16px (Lane ZM)' => [
                'pattern' => '#/\* On a touch screen partials/no-focus-zoom lifts these to 16px[^*]*\(Lane ZM\)[^*]*\*/\n'
                    .preg_quote('@media (hover:none),(pointer:coarse){.field input,.field textarea{padding-top:11px;padding-bottom:11px}}', '#')."\n#",
                'hits' => 1,
            ],
            'the address book: its fields keep their height at 16px (Lane ZM)' => [
                'pattern' => '#/\* On a touch screen kbb\.css lifts these fields to 16px[^*]*\(Lane ZM\)[^*]*\*/\n'
                    .preg_quote('@media (hover:none),(pointer:coarse){.ab-f input,.ab-f select{padding-top:8px;padding-bottom:8px}}', '#')."\n#",
                // The address book and its edit page, one stylesheet each.
                'hits' => 2,
            ],
            /*
             * V4 TWO-TONE'S QUICK LINKS (Lane M4, 6 October). The owner picked
             * V4 from docs/mv-preview ("V4 is fine. please proceed"), so it
             * ships ON (CLAUDE.md rule 1, the 30 September reversal): one
             * <div class="mm-chips"> under the menu's search field, holding the
             * five default links, on every page that draws the phone menu.
             * Exactly this element; the menu around it — rows, sub-items,
             * account links — is still compared byte for byte, and in Classic
             * (Appearance → Mobile menu → Style → Menu style) it is not printed
             * at all. The nav's two new classes come out of
             * MobileMenu::bodyClass() and so are on both sides of this walk.
             */
            'the phone menu quick links (Lane M4)' => [
                'pattern' => '#    <div class="mm-chips"><div class="mm-chipr">(?:<a class="mm-chip(?: a-[a-z]+)?" href="[^"<>]*">[^<]*</a>){5}</div></div>\n#',
                'hits' => 33,
            ],
        ];
    }

    /**
     * WHOLE ELEMENTS TAKEN OFF EVERY PAGE, ON PURPOSE, AND APPROVED ONE BY ONE.
     *
     * The mirror image of approvedInsertions(), and it exists for the same
     * reason at the other end: a lane that REPLACES a piece of chrome has a
     * "before" with no "after" as well as an "after" with no "before", and
     * cutting only one side leaves the walk comparing a page against itself
     * minus half a change.
     *
     * Applied to the BEFORE side, with the same cutApproved() and the same
     * counting discipline: if the element stops being there in the old tree the
     * count falls and this is red, and when a later lane adds a page that drew
     * it the count rises with it, deliberately.
     *
     * Every rule here is PAIRED with one in approvedInsertions(), and the pair
     * has to delete the same surrounding bytes or the two pages stop lining up
     * — which is the whole point: whatever is left on both sides is still
     * compared byte for byte.
     *
     * @return array<string, array{pattern: string, hits: int}>
     */
    public static function approvedRemovals(): array
    {
        return [
            /*
             * THE ADDRESS BOOK'S TYPED STATE BOX (Lane AD): Address line 1 and
             * 2, City, State, Postcode, Country as typed rows, replaced by the
             * insertion of the same name -- Building / Apartment or Villa,
             * Area / Street, Postcode, and the Emirate as a list (it is the
             * city) directly above Country.
             * Both address-book pages, once each.
             */
            'the address book\'s emirate list above Country (Lane AD)' => [
                'pattern' => '#      <label class="ab-f ab-wide"><span>[^<]*</span>\n        <input type="text" name="line1"[^\n]*\n      <label class="ab-f ab-wide"><span>[^<]*</span>\n        <input type="text" name="line2"[^\n]*\n      <label class="ab-f"><span>[^<]*</span>\n        <input type="text" name="city"[^\n]*\n      <label class="ab-f"><span>[^<]*</span>\n        <input type="text" name="state"[^\n]*\n      <label class="ab-f"><span>[^<]*</span>\n        <input type="text" name="postcode"[^\n]*\n      <label class="ab-f"><span>[^<]*</span>\n        <select name="country">.*?</select>\n      </label>\n#s',
                'hits' => 2,
            ],
            /*
             * THE JOURNAL'S AND AN ARTICLE'S OWN CHROME GOES (Lane BH): the
             * standalone head, the hand-drawn 69px header, the four-link drawer
             * and the footer strip, replaced by the shared layout's. The pair of
             * the insertions of the same name; the content between is still
             * compared byte for byte. The old header's `<header class="head"><div
             * class="wrap head-in">` is the anchor because no other page has it.
             */
            'the journal and an article on the shared layout: head and header (Lane BH)' => [
                'pattern' => '#\A<!DOCTYPE html>\n<html[^>]*>\n<head>.*?</head>\s*<body>\s*<header class="head"><div class="wrap head-in">.*?(?=<section class="hero">|<article id="article">)#s',
                'hits' => 2,
            ],
            'the journal and an article on the shared layout: footer and scripts (Lane BH)' => [
                'pattern' => '#<footer><div class="wrap fin">.*\z#s',
                'hits' => 2,
            ],
            /*
             * THE BARE FRAMEWORK 404 GOES (Lane NF). The owner: "i want 404 page
             * for my site so nothing can give not found error page". The
             * before side of '(404)' is Laravel's own "404 | Not Found"
             * document, which has no header, footer or shop markup to compare,
             * so it is cut whole; its replacement — the shop's 404 page — is
             * cut whole from the after side by the insertion of the same name.
             * The chrome that page DOES carry is pinned against /new-in/ byte
             * for byte by NotFoundPageTest, so nothing escapes comparison.
             * Exactly one document, the 404.
             */
            'the bare framework 404 page (Lane NF)' => [
                'pattern' => '#\A<!DOCTYPE html>\n<html lang="en">.*?<title>Not Found</title>.*\z#s',
                'hits' => 1,
            ],
            /*
             * ROW 55 — THE THREE OLD SECTIONS THE NEW ONES REPLACE. (Lane HA)
             *
             * The other half of the "Row 55" insertions: the old brand strip
             * (name tiles with a product count — the owner chose design A
             * WITHOUT the count), the old journal rail and the old About band
             * (heading, one paragraph and three counted figures — his section
             * 9 is the heading and his four paragraphs). Cut from the BEFORE
             * side, whole, on the homepage only. The rails the owner took off
             * the page (Recommended, the old Best sellers, Flash sale, …) need
             * no rule: they ship switched off, the before side is the old
             * Blade over the current PHP, and so they are absent on both.
             */
            'Row 55: the old brand strip (Lane HA)' => [
                'pattern' => '#\n*<section class="sec tinted dv" style="padding-top:0"><div class="wrap">\s*<div class="sh"><div><h2>Top brands <span class="cnt">.*?</div></section>\n*#s',
                'hits' => 1,
            ],
            'Row 55: the old journal rail (Lane HA)' => [
                'pattern' => '#\n*<section class="sec tinted dv" style="padding-top:0"><div class="wrap">\n  <div class="sh"><div><h2>Skincare guide <span class="cnt">.*?</div></section>\n*#s',
                'hits' => 1,
            ],
            // Lane HB's partials/home/spotted exists now (2.60.372), so the old
            // product-photo Spotted strip steps aside, as Lane HA built it to;
            // the new section renders nothing while no post is ticked Homepage.
            'Row 55: the old Spotted strip, replaced by Lane HB\'s section (2.60.372)' => [
                'pattern' => '#\n*<section class="sec dv" style="padding-top:0"><div class="wrap">\n  <div class="sh"><div><h2>\#KBeautyBliss .*?</div></section>\n*#s',
                'hits' => 1,
            ],
            'Row 55: the old About band (Lane HA)' => [
                'pattern' => '#\n*<section class="sec dv" style="padding-top:0"><div class="wrap">\n  <div class="about">.*?</div></section>\n*#s',
                'hits' => 1,
            ],

            /*
             * THE PRODUCT COUNT BESIDE "SHOW FILTERS". (Integrator, 2.60.358)
             *
             * The owner, 2 October: "turn hide by default the products count
             * beside the filter button". Appearance -> Site layout -> Product
             * grid -> "Show the product count beside Filters" ships off, so the
             * `<span class="gcount">` line goes from /shop/ and the category
             * archive, the two listings the walk renders. Line and all: the
             * @if sits at the end of the line before it.
             */
            'the product count beside Show filters (2.60.358)' => [
                'pattern' => '#\n            <span class="gcount"><b>\d+</b> products?</span>(?=\n)#',
                'hits' => 2,
            ],

            /*
             * THE FILTERS, GONE FROM /shop/ AND THE CATEGORY ARCHIVE. (Lane SO)
             *
             * The owner, 5 October: "remove the filter at all. i mean keep
             * turned off completely on all pages by default", and of category
             * and brand pages, "the app should not display any external link or
             * shop filter etc." Appearance → Site layout → Product grid →
             * "Filters · laptop" and "Filters button · phone" ship off, and off
             * means NOT SENT: the rail with its /shop/?cat= / ?brand= / ?price=
             * links, and the two Filters buttons above the grid (the phone's was
             * already hidden by CSS, the laptop's "Show filters" was the way in).
             * Exactly the two listings the walk renders. The page keeps its
             * grid: body.filters-hidden already gave it the whole row.
             */
            'the filter rail (Lane SO)' => [
                'pattern' => '#    <aside class="filtercol" id="fcol">.*?</aside>\n#s',
                'hits' => 2,
            ],
            'the two Filters buttons above the grid (Lane SO)' => [
                'pattern' => '#            <button class="mobi-filter" type="button"[^\n]*</button>\n            <button id="showFilters" type="button"[^\n]*?</button>#',
                'hits' => 2,
            ],

            /*
             * THE PLAIN CATEGORY TITLE, REPLACED BY THE TITLE HEADER. (Lane PY)
             *
             * The other half of 'the category title header, the light box' in
             * approvedInsertions(): the "K-Beauty · Skincare" eyebrow, the
             * plain <h1 class="ptitle"> and the grey line under it, on the
             * category archive only. `(?!Shop all)` keeps /shop/'s own title
             * out of it -- /shop/ is not in the owner's request and must still
             * compare byte for byte.
             */
            'the plain category title (Lane PY)' => [
                'pattern' => '#            <div class="eyebrow">[^<\n]*</div>\n        <h1 class="ptitle">(?!Shop all)[^<\n]*</h1>\n        <p class="psub">[^<\n]*</p>\n#',
                'hits' => 1,
            ],

            /*
             * THE CURATED LISTINGS' EMPTY TAILWIND PAGER WRAPPER. (Lane PI-B)
             *
             * store/collection.blade.php printed `<div class="pager">` around
             * `$products->links()` — Laravel's Tailwind pager, whose chevrons
             * are unsized SVGs that drew 170x170px on this Tailwind-less shop.
             * It is replaced by partials/listing-pager, which prints NOTHING on
             * a one-page listing, where the old wrapper printed an empty div.
             * The walk's four curated listings are one page each, so the whole
             * change visible here is that empty element going: one per page,
             * four pages. The paged state is ListingLoadTest's to pin.
             */
            'the empty Tailwind pager wrapper on the curated listings (Lane PI-B)' => [
                'pattern' => '#<div class="pager"></div>\n#',
                'hits' => 4,
            ],

            /*
             * THE GOOGLE FONTS REQUEST FOR POPPINS — Lane PERF.
             *
             * Three connection hints and a render-blocking stylesheet, on every
             * page of the shop, replaced by the same font served from this
             * origin. The owner's own PageSpeed report is the measurement:
             * those two third-party origins were the whole of a 4,369 ms
             * critical path, and `font-display: swap` repainted every word on
             * the page when the last of the four files finally arrived at 4.4 s.
             * App\Support\WebFonts carries the argument in full.
             *
             * PAIRED WITH 'the self-hosted Poppins faces'. The two patterns end
             * at the same point — the newline that closed the stylesheet link
             * and the newline that closes the <style> — so the blank lines
             * around the block are untouched on both sides and still compared.
             *
             * THE PATTERN NAMES THE WEIGHTS. `family=Poppins[^"]*` would go on
             * matching after somebody quietly dropped weight 800 from the
             * request, which is exactly the regression this file exists to
             * notice.
             */
            'the Google Fonts request for Poppins (Lane PERF)' => [
                'pattern' => '#<link rel="preconnect" href="https://fonts\.googleapis\.com">\n'
                    .'<link rel="dns-prefetch" href="https://fonts\.gstatic\.com">\n'
                    .'<link rel="preconnect" href="https://fonts\.gstatic\.com" crossorigin>\n\n'
                    .'<link href="https://fonts\.googleapis\.com/css2\?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">\n#',
                'hits' => 33,
            ],

            /*
             * THE FOOTER'S THREE COLUMN HEADINGS, as <h5> — Lane PERF.
             *
             * Nine <h2> and then an <h5> is a heading level skipped twice, and
             * axe scores it: it was one of the two audits costing Accessibility
             * its five points. Not one pixel moves — kbb.css line 180 is
             * `*{box-sizing:border-box;margin:0;padding:0}`, so the only
             * User-Agent declaration either tag carries is font-size and
             * `.fcol h2` sets 12px exactly as `.fcol h5` did.
             *
             * THE LABEL IS INSIDE THE CUT, WHICH LOSES COVERAGE, AND IT IS
             * BOUGHT BACK. `[^<]*` swallows "Shop", "Customer Care" and "My
             * Account" on both sides, so this walk would no longer see somebody
             * rewriting them. PerfDeliveryTest pins all three against the
             * shipped English, by tag, which is the sharper form of the same
             * check — a rule that cuts more than its element has to say what it
             * stopped watching.
             */
            'the footer column headings as h5 (Lane PERF)' => [
                'pattern' => '#<div class="fcol"><h5>[^<]*</h5>#',
                'hits' => 87,
            ],

            /*
             * THE GOOGLE FONTS REQUEST ON THE FOUR STANDALONE DOCUMENTS.
             *                                                        (Lane BG)
             *
             * Paired with 'the self-hosted Poppins faces', whose count went
             * from 33 to 37 in the same change. Lane PERF self-hosted Poppins
             * for every page that extends layouts/store.blade.php; these four
             * carry their own <head> and were missed, so the journal, an
             * article, the review wall and the skin quiz were still fetching a
             * render-blocking stylesheet from a third-party origin.
             *
             * THREE RULES FOR FOUR PAGES, and the split is the documents' own:
             * each had hand-written its head, so no two asked Google for the
             * same thing. The journal and an article agree (400;500;600;700 and
             * no connection hints at all); the review wall asked for 800 as
             * well and hinted twice; the skin quiz asked for 300 too and hinted
             * once. Keeping them separate is deliberate — one merged rule with
             * `[^"]*` where the weights go would go on matching after somebody
             * changed a weight list, which is the regression this file exists
             * to notice, and it is the same argument the rule above this one
             * makes for naming the layout's weights.
             *
             * WEIGHT 500 IS THE REASON THIS COULD HAPPEN AT ALL. All four asked
             * for 500 and WebFonts carried 400/600/700/800, so converting them
             * before would have dropped a weight. App\Support\WebFonts' note has
             * the measurement: a target of 500 was resolving to the 400 face on
             * 106 visible elements across the shop, so 500 was added and these
             * four lost nothing. The skin quiz's 300 was measured the same way
             * and dropped: zero elements at font-weight 300 on seven of eight
             * pages, one invisible one on the eighth.
             */
            // ('the Google Fonts request on the journal and an article (Lane BG)'
            // is gone with Lane BH: that link sat in the two documents' own
            // head, which 'the journal and an article on the shared layout'
            // now cuts whole.)

            'the Google Fonts request on the review wall (Lane BG)' => [
                'pattern' => '#\n<link rel="preconnect" href="https://fonts\.googleapis\.com">\n'
                    .'<link rel="preconnect" href="https://fonts\.gstatic\.com" crossorigin>\n'
                    .'<link href="https://fonts\.googleapis\.com/css2\?family=Poppins:'
                    .'wght@400;500;600;700;800&display=swap" rel="stylesheet">#',
                'hits' => 1,
            ],

            'the Google Fonts request on the skin quiz (Lane BG)' => [
                'pattern' => '#\n<link rel="preconnect" href="https://fonts\.googleapis\.com">\n'
                    .'<link href="https://fonts\.googleapis\.com/css2\?family=Poppins:'
                    .'wght@300;400;500;600;700;800&display=swap" rel="stylesheet">\n#',
                'hits' => 1,
            ],

            /*
             * THE SHIPPED HEAD OF THE PRODUCT PAGE'S BUY COLUMN.   (Lane PDP2)
             *
             * PAIRED WITH 'the Ledger head of the product buy column', whose
             * note carries the argument, what the pair gives up and what buys it
             * back. The two patterns are deliberately the same shape: they open
             * on the brand line's `</div>` and close on `<form class="cart`, so
             * the region either side of them is identical on both sides and
             * still compared byte for byte.
             *
             * WHAT IS IN HERE ON THE OLD SIDE: `<h1 class="bb-title">`, the
             * rating capsule, the inline rating row, `.bb-price` in the position
             * it used to occupy BELOW them, the VAT line and the blurb. The
             * price moved up into the title's row, which is the one thing in
             * this change that cannot be expressed as a reflow: it is the same
             * element at a different point in the document.
             */
            'the shipped head of the product buy column (Lane PDP2)' => [
                'pattern' => '#(?<=</div>)\s*<h1 class="bb-title".*?(?=<form class="cart)#s',
                'hits' => 1,
            ],

            /*
             * THE SHIPPED VARIANT ROW OPENING TAG.       (Lane PDP2, round 3)
             *
             * PAIRED WITH approvedInsertions()' 'the variant row opening tag,
             * with its pair of figures', whose note carries the argument, what
             * the pair gives up and where it is asserted instead. The two
             * patterns are deliberately identical and both close on
             * `(?=<span class="vr")`, so the row's visible contents are
             * compared byte for byte on both sides.
             */
            'the shipped variant row opening tag (Lane PDP2)' => [
                'pattern' => '#\s*<div class="variant[^"]*" data-i="\d+"[^>]*>\s*(?=<span class="vr")#s',
                'hits' => 3,
            ],

            /*
             * THE BRAND LINE AND THE CATEGORY EYEBROW ON EVERY PRODUCT TILE.
             *                                                      (Lane CARD)
             *
             * The owner, in as many words: "i want to hide the brand name,
             * category name by default. only name, rating (if any), pricing and
             * cart buttons." CLAUDE.md rule 1 as of 30 September says what he
             * asked for is the shop's new state rather than a switch to go and
             * find, so both ship hidden — and both are still controls, at
             * Appearance → Product styles → Card content.
             *
             * UNPAIRED, because nothing replaces them: the two rows come off
             * the card and the rows beneath move up. The stylesheet reserves
             * their height as a grid track rather than as markup, so there is
             * no "after" element to cut.
             *
             * WHAT IT DOES TO A PAGE, measured across the whole walk: 39 pages
             * render, 9 lose one or both of these — 83 eyebrows and 110 brand lines, and on all 39 the rest of the
             * document is identical. The eyebrow's diff is
             *
             *     before   …<div class="cb">⏎        <div class="kbb-card-cat">Skincare sets</div>        ⏎        <a class="cn"…
             *     after    …<div class="cb">⏎                ⏎        <a class="cn"…
             *
             * — the eight spaces of the eyebrow's own line and the eight of the
             * next line meet, which is why the pattern is the element and
             * nothing around it.
             *
             * THE WORDS ARE INSIDE THE CUT, WHICH LOSES COVERAGE, AND IT IS
             * BOUGHT BACK. `[^<]*` swallows the brand name and the category
             * label on the before side, so this walk would no longer see
             * somebody changing how either is printed. CardEqualHeightTest
             * pins both directly — that the tile draws neither at the shipped
             * defaults, that switching each on brings it back on /shop and on a
             * category archive, and that the brand is still printed
             * upper-cased — which is the sharper form of the same check.
             *
             * THE COUNTS ARE THE CLAIM. If the eyebrow stops being drawn in the
             * old tree the count falls and this is red; if a later lane adds a
             * storefront page with a grid on it the count rises and the number
             * moves with it, deliberately.
             */
            'the category eyebrow on every product tile (Lane CARD)' => [
                'pattern' => '#<div class="kbb-card-cat">[^<]*</div>#',
                /*
                 * ROW 55 (Lane HA): the homepage's old rails — Recommended (4
                 * tiles drawn on this fixture), the old Best sellers rail and the
                 * Flash sale — ship switched OFF now, and the "before" side is
                 * the old Blade over the CURRENT PHP, so their tiles are no longer
                 * on it. Twelve tiles fewer on one page, the homepage; the -N%
                 * count falls by the seven of those twelve that were reduced.
                 */
                'hits' => 71,
            ],
            /*
             * 110 -> 119 (Lane PS). The product page's "You may also like"
             * draws twelve tiles where it drew three — the owner's carousel,
             * and the count moving with it is exactly what the note above says
             * a new grid does. Nine more brand lines cut, on that one page; the
             * category eyebrow's 83 does not move because the rail passes no
             * catLabel, as it never did.
             */
            'the brand line on every product tile (Lane CARD)' => [
                'pattern' => '#<span class="kbb-card-brand">[^<]*</span>#',
                /*
                 * ROW 55 (Lane HA): the homepage's old rails — Recommended (4
                 * tiles drawn on this fixture), the old Best sellers rail and the
                 * Flash sale — ship switched OFF now, and the "before" side is
                 * the old Blade over the CURRENT PHP, so their tiles are no longer
                 * on it. Twelve tiles fewer on one page, the homepage; the -N%
                 * count falls by the seven of those twelve that were reduced.
                 */
                'hits' => 107,
            ],

            /*
             * THE NEW AND -N% PILLS ON EVERY PRODUCT TILE.            (Lane PR)
             *
             * The owner, 2 October 2026: "Turn off by default on the product
             * grid card, new and discount tag." Both ship off and both are
             * still controls, at Appearance → Product styles → Card content →
             * "New badge" / "Discount badge". GridCardOwnerAsksTest pins both
             * directions, on /shop/, a category and a brand page.
             *
             * UNPAIRED, like Lane CARD's pair above: nothing replaces a pill.
             * The pill and nothing around it is cut, so the indentation either
             * side of it still has to line up byte for byte, and it does.
             *
             * `New` AND A SIGN, NOT `[^<]*`, ON PURPOSE. The bestsellers rail's
             * rank pills (`#1`, `#2`…) wear `.kbb-badge-new` too and are NOT
             * removed — the owner did not ask about them — so a pattern that
             * swallowed them would have excused their disappearance as well.
             *
             * THE COUNTS ARE THE CLAIM, as above: measured across the walk, the
             * pills come off nine pages and every other byte of those pages is
             * unchanged.
             */
            'the NEW pill on every product tile (Lane PR)' => [
                'pattern' => '#<span class="kbb-badge kbb-badge-new">New</span>#',
                'hits' => 31,
            ],
            /*
             * THE SUPER SALE TITLE BLOCK, REPLACED (Lane PH). The owner, of
             * /super-sale/: "hide the all products button, count etc." — and
             * the dot, which his screenshot shows. The page's breadcrumb and
             * `.sh` block come out of the BEFORE side here; the configured
             * header that replaces them is cut from the AFTER side by the
             * paired rule in approvedInsertions(). One page: every other
             * custom page keeps its original markup byte for byte.
             */
            'the Super Sale title block, before Pages → Page header (Lane PH)' => [
                'pattern' => '#    <nav class="crumb"><a href="[^"]*">Home</a> / <span>Super Sale</span></nav>\n\n    <div class="sh">\n.*?<a class="lnk" href="[^"]*">All products</a>\n    </div>\n#s',
                'hits' => 1,
            ],
            'the -N% pill on every product tile (Lane PR)' => [
                'pattern' => '#<span class="kbb-badge kbb-badge-sale">-\d+%</span>#',
                // 37 -> 40 (Lane PS): the product page's "You may also like"
                // draws twelve tiles where it drew three, and three of the nine
                // new ones are reduced. One page; the NEW count does not move.
                // 40 -> 33, Row 55 (Lane HA): see the eyebrow's note above.
                'hits' => 33,
            ],
            /*
             * THE CHECKOUT'S ADDRESS PICKER ROW GOES (Lane CK, 6 October). The
             * owner: "turn off the address row completely, from cart and
             * checkout pages, and bring the manual fields under address section
             * on checkout page." Appearance -> Checkout page -> Fields &
             * attention -> "Address picker row on cart and checkout" ships OFF,
             * so the checkout loses five things, each cut once from its before
             * side: the row's stylesheet, the sheet's stylesheet, the row with
             * its four hidden inputs, the sheet's empty portal and the sheet's
             * script. The four typed fields that replace the row, and the
             * emirate's re-price listener, are the insertions of the same name.
             * The cart in this walk is the classic layout, which has no docked
             * bar, so it does not move; CartCheckoutAddressRowOffTest pins the
             * squeezed cart. Switch on, and every one of these comes back
             * byte for byte.
             */
            // The label-above field shapes the floating labels replace (Lane
            // CD): see the insertions of the same name.
            'the checkout floating-label fields (Lane CD)' => [
                'pattern' => '#<label for="[^"]+"(?: class="required_field")?>(?:(?!</label>).)*</label><span class="woocommerce-input-wrapper">.*?</span>(?=</p>)#s',
                // Name, phone, email: the base views predate the typed address.
                'hits' => 3,
            ],
            'the checkout floating-label discount code (Lane CD)' => [
                'pattern' => '#<input type="text" name="coupon_code" class="input-text" id="kbb_coupon_code" placeholder="[^"]*" autocomplete="off">#',
                'hits' => 1,
            ],
            'the checkout floating-label account password (Lane CD)' => [
                'pattern' => '#<span class="woocommerce-input-wrapper kbb-acct-pw" id="account_password_wrap" hidden>\s*<input type="password"[^>]*>\s*</span>#',
                'hits' => 1,
            ],
            'the checkout floating-label gift message (Lane CD)' => [
                'pattern' => '#<textarea name="gift_note" id="gift_note" class="input-text" rows="3" maxlength="600"\s+placeholder="[^"]*">[^<]*</textarea>#',
                'hits' => 1,
            ],
            'the checkout address row stylesheet (Lane CK)' => [
                'pattern' => "#<style>\n/\\* The checkout's address row\\..*?</style>\n#s",
                'hits' => 1,
            ],
            'the address sheet stylesheet on the checkout (Lane CK)' => [
                'pattern' => "#<style>\n/\\* ── the address sheet [^\n]*\n.*?</style>\n#s",
                'hits' => 1,
            ],
            'the checkout address picker row and its hidden inputs (Lane CK)' => [
                'pattern' => "#<div class=\"cka\" id=\"cka\">\n.*?\n</div>\n#s",
                'hits' => 1,
            ],
            'the address sheet portal on the checkout (Lane CK)' => [
                'pattern' => "#<div class=\"cpg-portal\">\n.*?\n</div>\n#s",
                'hits' => 1,
            ],
            'the address sheet script on the checkout (Lane CK)' => [
                'pattern' => "#<script>\n/\\*\n \\* The address sheet\\. .*?\n</script>\n#s",
                'hits' => 1,
            ],
        ];
    }

    /** Cut every match of one pattern out of a page, counting the cuts. */
    public static function cutApproved(string $html, string $pattern, int &$hits): string
    {
        $n = 0;
        $out = (string) preg_replace($pattern, '', $html, -1, $n);
        $hits += $n;

        return $out;
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
     * The first group are REFLOWS: paragraphs written across two to four lines
     * of template with a link or an @if in the middle of the sentence. Each is
     * now one __() with the markup passed in as a placeholder, so the line
     * breaks between the words are gone. HTML collapses them to a single space
     * either way, so nothing a reader sees has moved.
     *
     * The second group -- everything named `printed sheet -- ...` -- is not a
     * reflow. It is the printed documents' stylesheet going from physical sides
     * to logical ones, which is also a change no English reader can see, but for
     * a different reason: the two resolve to the same edge in a left-to-right
     * document. The group carries its own explanation where it starts.
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
            /*
             * RETIRED: 'cart recovery: item link is absolute'.
             *
             * It recorded a BUG FIX rather than a rewrap. Lane U2 found that
             * emails/cart-recovery.blade.php built its per-item link with
             * Url::to(), which returns a root-relative path, so every basket
             * reminder this shop has ever sent carried
             *
             *     <a href="/product/rice-toner/">
             *
             * An inbox has no origin to resolve that against, so the link was
             * dead in every mail client and had been since the message was
             * written. It is Url::external() now -- the out-of-band builder,
             * which puts APP_URL's origin in front of it and never the
             * request's.
             *
             * THE RULE IS GONE BECAUSE BASE_COMMIT MOVED PAST THE FIX, not
             * because the fix was reverted. These rules patch the BEFORE side,
             * which is rendered from BASE_COMMIT's views; the pin now sits at
             * e7645c8, where cart-recovery.blade.php already calls external(),
             * so the pattern matched nothing and applyApproved() failed it as a
             * rule that has stopped excusing anything. That failure is the
             * mechanism working -- a stale rule silently excusing nothing is
             * exactly what it exists to refuse -- and deleting the rule is the
             * correct answer, not weakening it.
             *
             * The behaviour itself is still pinned, and by a test rather than
             * by an exemption: tests/Feature/UrlInBandOutOfBandTest.php holds
             * every mail template to the invariant that a link it prints is
             * absolute.
             */

            /*
             * ═══════════════════════════════════════════════════════════════
             *  THE PRINTED SHEET'S STYLESHEET WENT LOGICAL -- LANE CX
             * ═══════════════════════════════════════════════════════════════
             *
             * These are NOT reflows, which is why they are grouped and why the
             * paragraph above them has been amended. Thirteen declarations in
             * resources/views/invoices/document.blade.php's one <style> block
             * changed name, on all four printed documents at once, and nothing
             * a reader sees moved.
             *
             * WHY IT HAD TO CHANGE. That document already takes its `dir` from
             * Locale::direction(), and its stylesheet was entirely physical. The
             * two are harmless apart and a mess together: the day the owner
             * switches the mirrored layout on, an Arabic invoice would lay
             * right-to-left text over left-to-right rules -- the money column
             * aligned to the middle of the sheet, the totals' 26px gap closed up
             * against its label, the facts strip's dividers on the outside of
             * the row. Half mirrored, which is worse than either whole answer,
             * on a document a customer keeps. docs/GC-BULK-PRINTING.md predicted
             * exactly this and docs/rtl-audit.md Sec. 5 recorded it.
             *
             * WHY NOTHING AN ENGLISH READER SEES MOVED, measured rather than
             * asserted. Every rule below resolves to the same physical edge in a
             * left-to-right document, which is what makes the conversion a no-op
             * today -- and the sheet was put through Chromium's own print path
             * to check it rather than reasoned about. English: `.fact`
             * border-right 1px / left 0, first cell padding-left 0, totals
             * padding-left 26px, exactly as the physical rules produced.
             * Arabic: all three mirror. docs/cx-shots/README.md has the table.
             *
             * WHY RULES AND NOT A BASE_COMMIT MOVE. Moving the constant would
             * blind this walk to every OTHER lane's change as well, for one
             * file's stylesheet. A rule per declaration stays narrow, and
             * applyApproved() counts the hits -- so when the base does move past
             * this work, every one of these fails as a rule that has stopped
             * excusing anything, which is the signal to delete them.
             */
            'printed sheet -- masthead: the document block alignment' => [
                'pattern' => '#\\.head\\ \\.what\\ \\{\\ flex:\\ 0\\ 0\\ auto;\\ text\\-align:\\ right;\\ \\}#',
                'with' => '.head .what { flex: 0 0 auto; text-align: end; }',
                'hits' => 4,
            ],
            'printed sheet -- facts strip: the divider between cells' => [
                'pattern' => '#\\.fact\\ \\{\\ flex:\\ 1\\ 1\\ 130px;\\ padding:\\ 9px\\ 12px;\\ border\\-right:\\ 1px\\ solid\\ var\\(\\-\\-rule\\-soft\\);\\ \\}#',
                'with' => '.fact { flex: 1 1 130px; padding: 9px 12px; border-inline-end: 1px solid var(--rule-soft); }',
                'hits' => 4,
            ],
            'printed sheet -- facts strip: no divider after the last cell' => [
                'pattern' => '#\\.fact:last\\-child\\ \\{\\ border\\-right:\\ 0;\\ \\}#',
                'with' => '.fact:last-child { border-inline-end: 0; }',
                'hits' => 4,
            ],
            'printed sheet -- line items: the column headings' => [
                'pattern' => '#text\\-align:\\ left;\\ padding:\\ 0\\ 8px\\ 7px;\\ border\\-bottom:\\ 1\\.5px\\ solid\\ var\\(\\-\\-ink\\);#',
                'with' => 'text-align: start; padding: 0 8px 7px; border-bottom: 1.5px solid var(--ink);',
                'hits' => 4,
            ],
            'printed sheet -- line items: the money columns' => [
                'pattern' => '#table\\.lines\\ th\\.num,\\ table\\.lines\\ td\\.num\\ \\{\\ text\\-align:\\ right;\\ white\\-space:\\ nowrap;\\ \\}#',
                'with' => 'table.lines th.num, table.lines td.num { text-align: end; white-space: nowrap; }',
                'hits' => 4,
            ],
            'printed sheet -- line items: flush to the leading edge' => [
                'pattern' => '#table\\.lines\\ td:first\\-child,\\ table\\.lines\\ th:first\\-child\\ \\{\\ padding\\-left:\\ 0;\\ \\}#',
                'with' => 'table.lines td:first-child, table.lines th:first-child { padding-inline-start: 0; }',
                'hits' => 4,
            ],
            'printed sheet -- line items: flush to the trailing edge' => [
                'pattern' => '#table\\.lines\\ td:last\\-child,\\ table\\.lines\\ th:last\\-child\\ \\{\\ padding\\-right:\\ 0;\\ \\}#',
                'with' => 'table.lines td:last-child, table.lines th:last-child { padding-inline-end: 0; }',
                'hits' => 4,
            ],
            'printed sheet -- totals: the figure, and the gap between it and its label' => [
                'pattern' => '#table\\.totals\\ td\\.num\\ \\{\\ text\\-align:\\ right;\\ padding\\-left:\\ 26px;\\ white\\-space:\\ nowrap;\\ \\}#',
                'with' => 'table.totals td.num { text-align: end; padding-inline-start: 26px; white-space: nowrap; }',
                'hits' => 4,
            ],
            'printed sheet -- the VAT note under the totals' => [
                'pattern' => '#margin\\-top:\\ 7px;\\ text\\-align:\\ right;#',
                'with' => 'margin-top: 7px; text-align: end;',
                'hits' => 4,
            ],
            'printed sheet -- phone layout: the masthead once it stacks' => [
                'pattern' => '#\\.head\\ \\.what\\ \\{\\ text\\-align:\\ left;\\ \\}#',
                'with' => '.head .what { text-align: start; }',
                'hits' => 4,
            ],
            'printed sheet -- phone layout: the money column once it stacks' => [
                'pattern' => '#table\\.lines\\ tbody\\ td\\.num\\ \\{\\ text\\-align:\\ left;\\ \\}#',
                'with' => 'table.lines tbody td.num { text-align: start; }',
                'hits' => 4,
            ],
            /*
             * The one rule this lane ADDED rather than converted: Arabic letters
             * join, and the tracking on .doctype/.label/.stamp prises the joins
             * open. Scoped to [dir="rtl"], so it changes nothing an English
             * sheet draws -- but it is three lines of CSS text in the document,
             * so the baseline has to know about it.
             */
            'printed sheet -- Arabic furniture keeps its joins' => [
                'pattern' => '#/\* \-\-\-\- the order number, drawn as Code 128 \-\-\-\-#',
                'with' => "[dir=\"rtl\"] .doctype,\n"
                    ."        [dir=\"rtl\"] .label,\n"
                    ."        [dir=\"rtl\"] .stamp { letter-spacing: normal; }\n"
                    ."\n"
                    .'        /* ---- the order number, drawn as Code 128 ----',
                'hits' => 4,
            ],
            /*
             * And the one that must NOT mirror. `.bc` is a flex row and a flex
             * row follows the document's direction, so a right-to-left sheet
             * would lay the Code 128 bars out backwards and the symbol would
             * stop being the order number. Pinned to one direction, which is
             * also what keeps the two `border-left-*` declarations under it
             * correct rather than merely tolerated.
             */
            'printed sheet -- the barcode cannot mirror' => [
                'pattern' => '#(height: 14mm; padding: 0 3\\.4mm;   /\\* 10 modules of quiet zone \\*/)#',
                'with' => "$1\n            direction: ltr;   /* RTL-PHYSICAL: a barcode is data, not text */",
                'hits' => 4,
            ],
        ] + self::laneRlOrderEmailDifferences();
    }

    /**
     * Lane RL — the order emails' approved changes, asked for by the owner.
     *
     * "the order can be tracked on our website ... on any device": the button
     * in the receipt and the status emails goes to the order's own status page
     * through a SIGNED link (App\Support\OrderLinks), where it used to go to
     * the order-received page only the placing browser could open; the device
     * note that apologised for that is replaced by one line saying it opens
     * anywhere. The dispatch email gains "Your tracking number is your order
     * number" and its button reads "Track your order".
     *
     * The signed link is computed here from the fixture order on the test's
     * frozen clock, never written out, so it is the same for any APP_KEY.
     * Nothing else in any document moved; cancelled keeps "View your order".
     *
     * @return array<string, array{pattern: string, with: string, hits: int}>
     */
    private static function laneRlOrderEmailDifferences(): array
    {
        $order = \App\Models\Order::query()->where('order_number', 'KBB-10427')->first();
        $signed = $order !== null ? \App\Support\OrderLinks::trackUrl($order) : 'about:blank';
        $note = 'Opens on any phone or computer — no sign-in needed. The page always shows the latest status.';

        return [
            'lane RL: receipt and status buttons open the signed status page (html)' => [
                'pattern' => '#href="[^"]*/checkout/success\?order=KBB-10427"#',
                'with' => 'href="' . e($signed) . '"',
                'hits' => 3,
            ],
            'lane RL: receipt and status buttons open the signed status page (text)' => [
                'pattern' => '#^\S*/checkout/success\?order=KBB-10427$#m',
                'with' => str_replace(['\\', '$'], ['\\\\', '\\$'], $signed),
                'hits' => 3,
            ],
            'lane RL: the dispatch button says Track your order (html)' => [
                'pattern' => '#(Your order is on its way.*?)>View your order</a>#s',
                'with' => '$1>Track your order</a>',
                'hits' => 1,
            ],
            'lane RL: the dispatch button says Track your order (text)' => [
                'pattern' => '#(YOUR ORDER IS ON ITS WAY.*?)VIEW YOUR ORDER#s',
                'with' => '$1TRACK YOUR ORDER',
                'hits' => 1,
            ],
            'lane RL: the device note becomes "opens anywhere" (html)' => [
                'pattern' => '#That link opens on the device you ordered from\. Anywhere else, <a [^>]*>sign in to your account</a> (and look for|and your orders are all listed there under) KBB-10427\.#',
                'with' => $note,
                'hits' => 3,
            ],
            'lane RL: the device note becomes "opens anywhere" (status text)' => [
                'pattern' => "#That link opens on the device you ordered from\. Anywhere else, sign in to your\naccount and look for KBB-10427:\n\S*/my-account/orders#",
                'with' => wordwrap($note, 72),
                'hits' => 2,
            ],
            'lane RL: the device note becomes "opens anywhere" (receipt text)' => [
                'pattern' => "#That link opens on the device you ordered from\. Anywhere else, sign in to your\naccount and your orders are all listed there under KBB-10427:\n\S*/my-account/orders#",
                'with' => wordwrap($note, 78),
                'hits' => 1,
            ],
            /*
             * The receipt of a PAID order (the fixture is paid) opens with the
             * owner-approved preview's lead (docs/rj-email-previews/after/01,
             * approved 3 October). Cash on delivery keeps the old lead.
             */
            'lane RL: a paid receipt says the payment is in (html)' => [
                'pattern' => '#Your order is in and we are packing it with care\. Everything you chose is listed below, exactly as it was when you ordered — keep this email, it is your receipt\.#',
                'with' => 'Your payment is in and your order is confirmed. We are packing it with care — keep this email, it is your receipt.',
                'hits' => 1,
            ],
            'lane RL: a paid receipt says the payment is in (text)' => [
                'pattern' => '#Your order is in and we are packing it with care\.\s+Everything\s+you\s+chose\s+is\s+listed\s+below,\s+exactly\s+as\s+it\s+was\s+when\s+you\s+ordered\s+—\s+keep\s+this\s+email,\s+it\s+is\s+your\s+receipt\.#',
                'with' => wordwrap('Your payment is in and your order is confirmed. We are packing it with care — keep this email, it is your receipt.', 78),
                'hits' => 1,
            ],
            'lane RL: the dispatch email names the tracking number (html)' => [
                'pattern' => '#(Your order is on its way.*?KBB-10427</span>\s*</td>\s*</tr>\s*</table>\n\n)#s',
                'with' => "$1        <p style=\"margin:14px 0 0;font-size:14px;line-height:1.55;color:#5E545A;\">\n"
                    . "            <b style=\"color:#2A2228;\">Your tracking number is your order number: KBB-10427.</b><br>\n"
                    . "            Follow it on our website — whatever we set (Shipped, Delivered) shows there straight away.\n"
                    . "        </p>\n",
                'hits' => 1,
            ],
            'lane RL: the dispatch email names the tracking number (text)' => [
                'pattern' => '#(YOUR ORDER IS ON ITS WAY.*?\n\n)(Order KBB-10427 — placed)#s',
                'with' => "$1Your tracking number is your order number: KBB-10427.\n"
                    . wordwrap('Follow it on our website — whatever we set (Shipped, Delivered) shows there straight away.', 72)
                    . "\n\n$2",
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

    /**
     * The new site footer as the walk's seed renders it. (Lane HB)
     *
     * A nowdoc, so the bytes below are the bytes compared: nothing in it is
     * interpolated. Used by approvedStorefrontChanges().
     */
    private static function laneHbFooter(): string
    {
        // (Lane TP) The Help column is the old shop's policy links, as the
        // owner asked on 6 October: Shipping & Delivery, Returns Information,
        // Order Tracking (/my-account/orders/), FAQs, Privacy policy, then
        // Contact us and About us. Seven <li>, inside this column only.
        // (Lane FT) `kft-sheen-bar` is gone from the class list: the owner,
        // 4 October, "remove the effect from the very last row of the footer".
        // SiteFooter::SCHEMA['site_sheen'] ships 'off'. One class, one page
        // element, nothing else in this snapshot moved.

        return <<<'KBB_HB_FOOTER'
<footer class="kft kft-motion kft-xm-help-sub kft-xm-logo kft-bc-m kft-hc-m" style="--kft-from:#E0567B;--kft-c2:#C13E63;--kft-c3:#E23A4E;--kft-to:#D9603B;--kft-bg:#FFFFFF;--kft-text:#5E545A;--kft-accent:#C13E63;--kft-dr:14s;--kft-drn:12s;--kft-sh:5s;--kft-pt-d:30px;--kft-pb-d:0px;--kft-gap-d:28px;--kft-fh-d:22px;--kft-fl-d:14px;--kft-fn-d:100;--kft-pt-m:20px;--kft-pb-m:0px;--kft-gap-m:12px;--kft-fh-m:17px;--kft-fl-m:13px;--kft-fn-m:100">
  <div class="kft-help"><div class="kft-wrap kft-help-in">
    <span class="kft-help-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11.5a8 8 0 0 1-11.8 7L4 20l1.5-4A8 8 0 1 1 20 11.5Z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1.2-1.4-1.9-1-1 .9a3.6 3.6 0 0 1-2.4-2.4l.9-1-1-1.9Z" fill="#fff" stroke="none"/></svg></span>
    <div class="kft-help-tx">
      <h2 class="kft-help-h">Find your perfect K-beauty match <span class="kft-avail">24/7 available</span></h2>
      <p class="kft-help-p">Ask us anything about your skin, a product or your order — we reply on WhatsApp.</p>
    </div>
    <div class="kft-help-bt"><a class="kft-bt-p" href="https://wa.me/971585052611" target="_blank" rel="noopener">Chat on WhatsApp</a> <a class="kft-bt-o" href="/track-my-order/">Track my order</a></div>
  </div></div>
  <div class="kft-main"><div class="kft-wrap">
    <div class="kft-grid">
      <div class="kft-brand">
        <a class="kft-logo" href="/"><bdi>K-Beauty<b>Bliss</b></bdi></a>
        <p class="kft-tag">Authentic Korean beauty, curated for the UAE.</p>
        <div class="kft-soc" aria-label="Follow us"><a href="https://www.instagram.com/kbeauty.bliss/" aria-label="Instagram" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg></a><a href="https://www.tiktok.com/@kbeauty.bliss" aria-label="TikTok" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 3h3a4.5 4.5 0 0 0 4 4v3a7.4 7.4 0 0 1-4-1.3V15a6 6 0 1 1-6-6v3.1A3 3 0 1 0 14 15Z"/></svg></a><a href="https://www.facebook.com/kbeautyblissuae" aria-label="Facebook" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 21v-7.5H16l.5-3h-3V8.6c0-.9.3-1.6 1.6-1.6h1.6V4.3A21 21 0 0 0 14.3 4C12 4 10.5 5.4 10.5 8v2.5H8v3h2.5V21Z"/></svg></a></div>
      </div>
      <nav class="kft-col kft-col1" aria-labelledby="kft-shop"><h2 id="kft-shop" class="kft-ch">Shop</h2><ul>
        <li><a href="/new-in/">New in</a></li>
        <li><a href="/best-sellers/">Best sellers</a></li>
        <li><a href="/brands/">Brands</a></li>
        <li><a href="/super-sale/">Super sale</a></li>
        <li><a href="/kbeautybliss-spotted/">#KBeautyBliss</a></li>
        <li><a href="/blog/">Journal</a></li>
      </ul></nav>
      <nav class="kft-col kft-col2" aria-labelledby="kft-help"><h2 id="kft-help" class="kft-ch">Help</h2><ul>
        <li><a href="/delivery/">Shipping &amp; Delivery</a></li>
        <li><a href="/refund_returns/">Returns Information</a></li>
        <li><a href="/my-account/orders/">Order Tracking</a></li>
        <li><a href="/faqs/">FAQs</a></li>
        <li><a href="/privacy-policy/">Privacy policy</a></li>
        <li><a href="/contact-us/">Contact us</a></li>
        <li><a href="/about/">About us</a></li>
      </ul></nav>
      <nav class="kft-col kft-col3" aria-labelledby="kft-acct"><h2 id="kft-acct" class="kft-ch">Account</h2><ul>
        <li><a href="/my-account/">My account</a></li>
        <li><a href="/my-account/orders/">My orders</a></li>
        <li><a href="/my-wishlist/">Wishlist</a></li>
        <li><a href="/my-account/edit-address/">Addresses</a></li>
      </ul></nav>
      <div class="kft-contact">
        <form class="kft-news" method="post" action="/api/subscribe" data-kbb-subscribe><input type="hidden" name="_token" value="TOKEN" autocomplete="off"><input type="email" name="email" required placeholder="Your email for offers" aria-label="Your email" autocomplete="email"><button type="submit">Join</button></form>
      </div>
    </div>
    <p class="kft-name" aria-hidden="true" style="--kft-n:14">K-Beauty Bliss</p>
  </div></div>
  <div class="kft-bot"><div class="kft-wrap kft-bot-in">
    <span>© 2026 K-Beauty Bliss UAE</span>
    <nav class="kft-legal"><a href="/privacy-policy/">Privacy policy</a><a href="/terms-and-conditions/">Terms</a></nav>
    <div class="kft-pay"><span>Tabby</span><span>Tamara</span><span>Visa</span><span>Mastercard</span><span>COD</span></div>
  </div></div>
</footer>
KBB_HB_FOOTER;
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
