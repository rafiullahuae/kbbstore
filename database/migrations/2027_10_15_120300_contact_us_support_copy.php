<?php

declare(strict_types=1);

use App\Support\Url;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Contact Us page's own words, rewritten. (Lane CT; the pattern is Lane
 * AB's 2027_10_15_110000_owner_about_us_story.)
 *
 * The owner, on the first screenshot of the new contact page: "remove the
 * foot line, bcz we have already have the contact details on the contact
 * page, also remove the call option, we don't receive calls, we provide
 * support on whatsapp, instagram and email. write nicely this stuff."
 *
 * The seeded text (2026_11_06_000000_seed_footer_content_pages) opened with an
 * editor's "This is placeholder wording…" line and told the shopper the phone
 * number and the WhatsApp link "are printed at the foot of every page". The
 * cards above it now carry the details, so the text says how support works
 * instead, and repeats none of them: WhatsApp first, then Instagram and email,
 * no phone calls, replies "as quickly as we can" (the shop has no setting for a
 * response time, so none is invented), the order number and Track my order,
 * and the Returns & Refunds page.
 *
 * ── WHAT IT WRITES ──────────────────────────────────────────────────────────
 *
 * The English `content` of the `pages` row slugged `contact-us`. The title
 * ("Contact Us") is not touched: the page's header prints it as the one <h1>,
 * so the text has no h1, only h3s, the headings policy-body already styles.
 * Every tag survives App\Support\RichText::clean() (p, h3, strong, a), so the
 * owner can open it at Pages → User pages → Contact Us → Edit and save it
 * again without losing anything. No class, no style, no script.
 *
 * ── HOW TO PUT THE OLD WORDING BACK ─────────────────────────────────────────
 *
 * Before writing, the row's current title and content are copied into a DRAFT
 * page slugged `contact-us-previous`. A draft is never served, and the seven
 * content routes and the sitemap name their slugs, so shoppers never see it;
 * the owner does, in Pages → User pages. down() writes the content back and
 * deletes the copy, but only while `contact-us` still holds the text written
 * here: a page he has since edited is his. A re-run changes nothing and never
 * overwrites an existing copy.
 *
 * ── ARABIC ──────────────────────────────────────────────────────────────────
 *
 * Not written. An Arabic content entered at Pages → User pages → Contact Us →
 * Edit → Arabic lives in `translations` and keeps showing on /ar/contact-us/.
 * No migration seeds one, so today the Arabic page shows this English text
 * under the cards and the form, right-to-left, until he enters an Arabic one.
 *
 * ── CACHES ──────────────────────────────────────────────────────────────────
 *
 * None. Page content is read from the row on every request, and no route or
 * view changes here.
 */
return new class extends Migration
{
    public const SLUG = 'contact-us';

    public const BACKUP_SLUG = 'contact-us-previous';

    public const BACKUP_SUFFIX = ' — previous wording';

    public const COPY = <<<'HTML'
<p>Have a question about a product, your routine or an order? We’d love to help. Our team looks after every message personally on <strong>WhatsApp</strong>, <strong>Instagram</strong> and <strong>email</strong>.</p>
<p><strong>WhatsApp</strong> is the quickest way to reach us. You can also send us a direct message on Instagram, write to us by email, or use the form on this page. We don’t take phone calls, so that every message gets our full attention, and we reply as quickly as we can.</p>
<h3>About an order</h3>
<p>Please have your order number ready. You’ll find it in your order confirmation email, and you can follow your parcel any time on our <a href="/track-my-order/">Track my order</a> page.</p>
<h3>Returns and refunds</h3>
<p>Please read our <a href="/refund_returns/">Returns &amp; Refunds</a> page first, then message us with your order number and we’ll take it from there.</p>
HTML;

    /** The text as stored: links rooted at the install's base path, as Lane AB's migration does. */
    public static function copyHtml(): string
    {
        $base = rtrim(Url::raw('/'), '/');

        return str_replace('href="/', 'href="'.$base.'/', self::COPY);
    }

    public function up(): void
    {
        $copy = self::copyHtml();
        $row = DB::table('pages')->where('slug', self::SLUG)->first();

        if ($row === null) {
            DB::table('pages')->insert([
                'slug' => self::SLUG,
                'title' => 'Contact Us',
                'content' => $copy,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ((string) $row->content === $copy) {
            return;
        }

        if (! DB::table('pages')->where('slug', self::BACKUP_SLUG)->exists()) {
            DB::table('pages')->insert([
                'slug' => self::BACKUP_SLUG,
                'title' => mb_substr((string) $row->title, 0, 200).self::BACKUP_SUFFIX,
                'content' => $row->content,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('pages')->where('id', $row->id)->update([
            'content' => $copy,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('pages')->where('slug', self::SLUG)->first();
        $backup = DB::table('pages')->where('slug', self::BACKUP_SLUG)->first();

        if ($row === null || $backup === null || (string) $row->content !== self::copyHtml()) {
            return;
        }

        DB::table('pages')->where('id', $row->id)->update([
            'content' => $backup->content,
            'updated_at' => now(),
        ]);

        DB::table('pages')->where('id', $backup->id)->delete();
    }
};
