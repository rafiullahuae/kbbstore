<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The five footer and header links that 404 — Lane DR.
 *
 * ── WHAT WAS BROKEN ─────────────────────────────────────────────────────────
 *
 * routes/web.php hard-codes seven content-page slugs, each pointed at
 * PageController::show() with ->defaults('slug', …):
 *
 *     about, contact-us, delivery, faqs,
 *     privacy-policy, refund_returns, terms-and-conditions
 *
 * show() does `where('slug', …)->where('status','published')->firstOrFail()`,
 * so each of those seven URLs is a 404 until a published row exists in `pages`.
 * 2026_08_29_140000_seed_policy_pages seeded two of them — privacy-policy and
 * terms-and-conditions. The other five have never had a row.
 *
 * Four of the five are linked from the footer of every page in the shop
 * (Shipping & Delivery, Returns Information, FAQs, Contact us) and the fifth,
 * /about, is linked from the homepage. Every one of them was a 404 to a
 * shopper, on a route the application itself registers: the code promises the
 * page exists and the data does not deliver it.
 *
 * ── WHY A ROW AND NOT A TEMPLATE ────────────────────────────────────────────
 *
 * The same reason the 2026_08_29 migration gives: content the owner has to be
 * able to change without a release. A Blade page for "Shipping & Delivery"
 * would put the shop's delivery promise in a file that only a signed zip can
 * edit. These are rows, so Store → Pages can edit them the day the owner has
 * the real wording. Existing rows are left alone — a page already edited must
 * never be overwritten by a re-run.
 *
 * ── WHY THE COPY SAYS SO LITTLE ─────────────────────────────────────────────
 *
 * This lane exists because the storefront was making claims nothing backs. A
 * placeholder delivery page reading "free returns within 14 days" would be a
 * new one, invented by the migration that was sent to remove them. So the copy
 * here states only things this application actually knows and can be held to:
 *
 *   - delivery       Charges and options are computed by ShippingService at
 *                    checkout from the shopper's address. That is true of every
 *                    install, and it is all that is said. No window, no price,
 *                    no country list.
 *   - refund_returns No window, no restocking fee, no "free". It says a return
 *                    starts by contacting the shop with the order number, which
 *                    is what actually happens, and that the terms are those
 *                    agreed with the shop.
 *   - faqs           Three questions answered from the shop's own behaviour —
 *                    where to track an order, how charges are shown, how to
 *                    reach a human — and no invented fourth.
 *   - contact-us     Points at the contact details the footer already prints
 *                    from App\Support\SupportContact, rather than baking a
 *                    phone number into a row that would then disagree with the
 *                    setting the moment the owner changed it.
 *   - about          Two sentences of what the shop is. No brand count, no
 *                    founding year, no "trusted by N customers".
 *
 * Each page opens with one sentence telling the owner, in the admin, that this
 * is placeholder wording waiting for theirs. It is written as an editor note in
 * a muted line rather than as an HTML comment, because the admin's editor
 * renders HTML and a comment would be invisible in exactly the screen that has
 * to see it. Deleting that line is the first thing the owner does, and the page
 * reads correctly without it.
 *
 * ── THE SIDE EFFECT THAT IS DELIBERATE ──────────────────────────────────────
 *
 * Store\SeoFilesController::sitemap() lists these seven slugs when the row is
 * published, so five thin pages now enter the sitemap. That is the correct
 * trade: they are real, footer-linked pages of the shop, and a sitemap entry
 * for a page that returns 200 is not the error a sitemap entry for a 404 is.
 *
 * No route is added or changed, so no compiled route cache has to be cleared
 * and this ships without a clear_caches_* companion.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->pages() as $page) {
            if (DB::table('pages')->where('slug', $page['slug'])->exists()) {
                continue;
            }

            DB::table('pages')->insert($page + [
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately empty, same as the policy-pages migration: removing
        // content a merchant may have rewritten is worse than leaving it.
    }

    /**
     * One editor-facing line, in the shop's own muted note style.
     */
    private function note(string $what): string
    {
        return '<p><em>This is placeholder wording. Edit this page in '
            . 'Store &rarr; Pages to publish your own ' . $what . '.</em></p>';
    }

    /** @return list<array{slug:string,title:string,content:string}> */
    private function pages(): array
    {
        return [
            [
                'slug' => 'delivery',
                'title' => 'Shipping &amp; Delivery',
                'content' => $this->note('delivery policy') . <<<'HTML'
<h3>How delivery is charged</h3>
<p>Delivery options and charges are worked out for your address when you check out. Add your items to the basket, enter your delivery address, and the available options and their cost are shown before you pay. Nothing is added afterwards.</p>

<h3>Where we deliver</h3>
<p>The delivery options offered at checkout are the ones available for the address you enter. If no option appears for your address, we do not currently deliver there.</p>

<h3>Following your order</h3>
<p>Every order has an order number, sent to you by email when the order is placed. You can look an order up at any time on our <a href="/track-my-order/">Track my order</a> page using that number and the email address you ordered with.</p>

<h3>Questions before you order</h3>
<p>If you need to know about a delivery before you place the order, please <a href="/contact-us/">get in touch</a> and we will answer for your address specifically.</p>
HTML,
            ],
            [
                'slug' => 'refund_returns',
                'title' => 'Returns &amp; Refunds',
                'content' => $this->note('returns policy') . <<<'HTML'
<h3>Starting a return</h3>
<p>If something is not right with your order, please <a href="/contact-us/">contact us</a> with your order number and tell us what the problem is. We will tell you what to do next before you send anything back, so that nothing goes astray.</p>

<h3>What we need from you</h3>
<p>Please keep the order number to hand — it is on the confirmation email you received when the order was placed, and you can find it again on our <a href="/track-my-order/">Track my order</a> page.</p>

<h3>Refunds</h3>
<p>Where a refund is agreed, it is made to the payment method the order was paid with. We will confirm the amount with you before it is processed.</p>

<h3>Your rights</h3>
<p>Nothing on this page affects the rights you have under the consumer law that applies to your purchase. Our full terms are set out in our <a href="/terms-and-conditions/">Terms &amp; Conditions</a>.</p>
HTML,
            ],
            [
                'slug' => 'faqs',
                'title' => 'Frequently Asked Questions',
                'content' => $this->note('answers') . <<<'HTML'
<h3>How do I check where my order is?</h3>
<p>Use our <a href="/track-my-order/">Track my order</a> page. You will need the order number from your confirmation email and the email address you ordered with.</p>

<h3>How much is delivery?</h3>
<p>Delivery options and charges are calculated for your address at checkout and shown in full before you pay. See <a href="/delivery/">Shipping &amp; Delivery</a>.</p>

<h3>Can I return something?</h3>
<p>Yes — please start by <a href="/contact-us/">contacting us</a> with your order number. See <a href="/refund_returns/">Returns &amp; Refunds</a>.</p>

<h3>How do I reach a person?</h3>
<p>The phone number and WhatsApp link at the bottom of every page reach us directly, or you can use our <a href="/contact-us/">Contact us</a> page.</p>
HTML,
            ],
            [
                'slug' => 'contact-us',
                'title' => 'Contact Us',
                'content' => $this->note('contact details and opening hours') . <<<'HTML'
<h3>Talk to us</h3>
<p>Our phone number and our WhatsApp link are printed at the foot of every page on this site, and both reach us directly. They are the quickest way to get an answer.</p>

<h3>About an order you have already placed</h3>
<p>Please have your order number ready — it is on the confirmation email sent when the order was placed, and you can look the order up again on our <a href="/track-my-order/">Track my order</a> page.</p>

<h3>Returns and refunds</h3>
<p>Start with our <a href="/refund_returns/">Returns &amp; Refunds</a> page, then contact us with your order number.</p>
HTML,
            ],
            [
                'slug' => 'about',
                'title' => 'About Us',
                'content' => $this->note('story') . <<<'HTML'
<h3>Korean beauty, in the UAE</h3>
<p>K-Beauty Bliss is an online shop selling Korean skincare and beauty products to customers in the UAE and the wider Gulf.</p>

<h3>What we sell</h3>
<p>Everything we carry is listed in our <a href="/shop/">shop</a>, with its own page, its price and whatever we know about it. If a product is not on the site, we are not selling it.</p>

<h3>Getting in touch</h3>
<p>Our phone number and WhatsApp link are at the foot of every page, and our <a href="/contact-us/">Contact us</a> page lists the ways to reach us.</p>
HTML,
            ],
        ];
    }
};
