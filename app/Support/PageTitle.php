<?php

declare(strict_types=1);

namespace App\Support;

/**
 * `pages.title` is an HTML column holding one line of text. (Lane S9)
 *
 * ── WHAT THE COLUMN ACTUALLY HOLDS, MEASURED ────────────────────────────────
 *
 * resources/views/store/page.blade.php prints it UNESCAPED —
 * `<h1>{!! $page->t('title') !!}</h1>` and the same in the breadcrumb — and the
 * seeders agree with the view: `seed_policy_pages` stores the literal
 * `Terms &amp; Conditions` and `seed_footer_content_pages` stores
 * `Shipping &amp; Delivery` and `Returns &amp; Refunds`.
 *
 * So it is HTML, not text. And Store\DemoContentController disagrees: Demo Pages
 * writes `Shipping & Delivery (Demo)` with a bare ampersand. One column, two
 * conventions, and no screen had ever written to it.
 *
 * ── WHY THE TWO FUNCTIONS, AND WHY BOTH HALVES ARE NEEDED ───────────────────
 *
 * An editor box holds text. The column holds HTML. So the pair here is the only
 * honest mapping between them, and each direction is load-bearing:
 *
 *   decoded()  for the box. Without it, `Terms &amp; Conditions` is what the
 *              owner SEES in the box, and pressing Save with nothing changed
 *              would store `Terms &amp;amp; Conditions` — the page's own heading
 *              corrupted by opening the screen.
 *
 *   stored()   for the column. Without it, a typed `<script>` lands in an `<h1>`
 *              that is printed unescaped. MEASURED on a running shop, with
 *              `<script>alert(1)</script>Hi` written into `pages.title`:
 *              `/faqs/` served `<h1><script>alert(1)</script>Hi</h1>` and the
 *              `<title>` read `alert(1)Hi`. docs/f2-operator-authored-html.md §5
 *              counted the dialogs firing.
 *
 * Together they ROUND-TRIP the shipped rows byte-for-byte:
 * `Terms &amp; Conditions` → decoded → `Terms & Conditions` → stored →
 * `Terms &amp; Conditions`. That is rule 1: opening a page and saving it with
 * nothing changed must leave the storefront byte-identical.
 *
 * ── ENT_NOQUOTES, DELIBERATELY ──────────────────────────────────────────────
 *
 * The value is printed in TEXT position only — the `<h1>`, the breadcrumb
 * `<span>`, and `strip_tags()` into `@section('title')`. It never becomes an
 * attribute, so a quote cannot escape one, and encoding it would turn the
 * apostrophe in "Delivery & What You'll Pay" into `&#039;` in the heading a
 * shopper reads.
 *
 * ── A DEFECT THIS FILE DOES *NOT* FIX, AND THE ONE IT DOES ──────────────────
 *
 * Three of the seven content pages publish a DOUBLE-ESCAPED `<title>` today, and
 * it is nothing to do with the column's convention. Measured over all 24
 * storefront pages that emit a `<title>`:
 *
 *     /terms-and-conditions/   <title>Terms &amp;amp;amp; Conditions · K-Beauty Bliss</title>
 *     /delivery/               <title>Shipping &amp;amp;amp; Delivery · K-Beauty Bliss</title>
 *     /refund_returns/         <title>Returns &amp;amp;amp; Refunds · K-Beauty Bliss</title>
 *
 * and no other page. The mechanism is Blade, not this shop: `@section('title',
 * <expression>)` runs its second argument through `e()` inside
 * Factory::startSection(), and layouts/store.blade.php then reads that section
 * back as `$kbbRawTitle` — a variable whose own name says it should be raw — and
 * hands it to Support\Seo, which escapes it again. A plain-text title would come
 * out `&amp;amp;`; the column's `&amp;` makes it a third layer. Every page shape
 * that builds its title from `$seoCtx['title']` instead — products, categories,
 * brands, articles — is correct, which is why this is three pages and not
 * twenty-four.
 *
 * The fix is one `html_entity_decode()` around that `strip_tags()` in
 * layouts/store.blade.php, it is correct for every caller, and it changes the
 * `<title>` bytes of three live pages — so it is a rule-1 decision with a pin to
 * advance, on a shared layout every lane renders through, and it is reported
 * rather than taken here.
 *
 * WHAT IS FIXED HERE is the half that belongs to pages and moves nothing today:
 * `Store\PageController::show()` passed the RAW column as `title_token`, the
 * substitution for Yoast's `%%title%%`. So an owner who typed the shipped Yoast
 * template into the new SEO title box on /terms-and-conditions/ would have
 * published `Terms &amp;amp; Conditions | K-Beauty Bliss` — the editor's own
 * box reintroducing the defect it is the workaround for. It reads decoded() now.
 * No shipped row carries an override, so no page moves.
 */
final class PageTitle
{
    /** The title as a human typed it: tags gone, entities back to characters. */
    public static function decoded(?string $stored): string
    {
        return html_entity_decode(strip_tags((string) $stored), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * One line of text, encoded for a column the storefront prints unescaped.
     *
     * Whitespace collapses to single spaces: it is one line, and a newline in a
     * title breaks `<title>`.
     */
    public static function stored(string $typed): string
    {
        $text = self::decoded($typed);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8', true);
    }
}
