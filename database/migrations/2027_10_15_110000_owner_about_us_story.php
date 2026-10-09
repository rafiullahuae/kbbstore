<?php

declare(strict_types=1);

use App\Support\Url;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The owner's About us story, set nicely. (Lane AB)
 *
 * The owner: "also input this text nicely in about us page:" followed by the
 * text in STORY below. His words are kept verbatim, curly apostrophes
 * included; the markup around them is the only thing this lane wrote.
 *
 * ── WHAT IT WRITES ──────────────────────────────────────────────────────────
 *
 * The English `content` of the `pages` row slugged `about`, unconditionally:
 * he asked for this text, so it is the page's new state (CLAUDE.md, "what he
 * did ask for ships on"). The title ("About Us") is not touched — the page
 * header prints it as the page's one <h1>, and the story's own heading is an
 * <h2> under it.
 *
 * Every tag and class here survives App\Support\RichText::clean(), so the
 * owner can open it in Pages → User pages → About Us → Edit and save it again
 * without losing the layout. The classes are styled by the `.kbb-about` block
 * in resources/css/kbb/kbb.css, which content pages already load. No script,
 * no style attribute, no image, no new request.
 *
 * ── HOW TO PUT THE OLD WORDING BACK ─────────────────────────────────────────
 *
 * There is no revisions table for pages. So before writing, the row's current
 * title and content are copied into a DRAFT page slugged `about-previous`
 * ("About Us — previous wording"). A draft is never served — show() requires
 * `published` — and the seven content routes and the sitemap name their slugs
 * explicitly, so the copy is invisible to shoppers. It IS listed in Pages →
 * User pages, where the owner can open it and copy from it. down() writes it
 * back over `about` and deletes the copy, but only while `about` still holds
 * the text this migration wrote: a page the owner has since edited is his and
 * is not overwritten by a rollback. A re-run finds the story already in place
 * and changes nothing, and never overwrites an existing copy.
 *
 * ── ARABIC ──────────────────────────────────────────────────────────────────
 *
 * Not written. An Arabic content, if one was ever entered at Pages → User
 * pages → About Us → Edit → Arabic, lives in `translations` and keeps showing
 * on /ar/about/. With none entered (no migration in this repo seeds one) the
 * Arabic shop falls back to this English story, laid out right-to-left; the
 * CSS uses logical properties only, so it reads correctly there.
 *
 * ── CACHES ──────────────────────────────────────────────────────────────────
 *
 * None to clear. Page content is read from the row on every request (no
 * response cache; HTML is sent `private, no-cache`), and no route or view
 * changes, so there is no clear_caches_* companion.
 */
return new class extends Migration
{
    public const SLUG = 'about';

    public const BACKUP_SLUG = 'about-previous';

    public const BACKUP_TITLE = 'About Us — previous wording';

    public const STORY = <<<'HTML'
<section class="kbb-about">
<p class="kbb-about-eyebrow">Our story</p>
<h2>About K-Beauty Bliss UAE</h2>
<p class="kbb-about-lead">At K-Beauty Bliss UAE, we are passionate about bringing the best of Korean beauty to skincare enthusiasts across the UAE. Our mission is to provide you with authentic, high-quality K-beauty products that deliver real results. Whether you’re looking to enhance your skincare routine, discover the latest beauty trends, or find effective solutions for your skin concerns, we’ve got you covered.</p>
<p>We carefully curate our product range, ensuring that every item fulfills the highest standards of quality and effectiveness. From skincare and hair care to makeup and beauty sets, we offer a wide variety of products created to cater to all your beauty needs.</p>
<p>Customer satisfaction is at the heart of everything we do. With fast delivery options, including 1-3 day delivery across the UAE and free shipping on orders over 199 AED, we make it easy and convenient for you to get the products you love.</p>
<ul class="kbb-about-facts">
<li><strong>1-3 day delivery</strong> across the UAE</li>
<li><strong>Free shipping</strong> on orders over 199 AED</li>
</ul>
<p class="kbb-about-close">Join the K-Beauty Bliss community and experience the beauty revolution that’s taking the world by storm. Your journey to flawless, radiant skin starts here!</p>
<p class="kbb-about-cta"><a href="/shop/">Shop now</a></p>
</section>
HTML;

    /** The story as stored: links rooted at the install's base path, as 2027_09_05_200000 does. */
    public static function storyHtml(): string
    {
        $base = rtrim(Url::raw('/'), '/');

        return str_replace('href="/', 'href="' . $base . '/', self::STORY);
    }

    public function up(): void
    {
        $story = self::storyHtml();
        $row = DB::table('pages')->where('slug', self::SLUG)->first();

        if ($row === null) {
            DB::table('pages')->insert([
                'slug' => self::SLUG,
                'title' => 'About Us',
                'content' => $story,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ((string) $row->content === $story) {
            return;
        }

        if (! DB::table('pages')->where('slug', self::BACKUP_SLUG)->exists()) {
            DB::table('pages')->insert([
                'slug' => self::BACKUP_SLUG,
                'title' => self::BACKUP_TITLE,
                'content' => $row->content,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('pages')->where('id', $row->id)->update([
            'content' => $story,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('pages')->where('slug', self::SLUG)->first();
        $backup = DB::table('pages')->where('slug', self::BACKUP_SLUG)->first();

        if ($row === null || $backup === null || (string) $row->content !== self::storyHtml()) {
            return;
        }

        DB::table('pages')->where('id', $row->id)->update([
            'content' => $backup->content,
            'updated_at' => now(),
        ]);

        DB::table('pages')->where('id', $backup->id)->delete();
    }
};
