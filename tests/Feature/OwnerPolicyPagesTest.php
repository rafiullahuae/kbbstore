<?php

declare(strict_types=1);

/**
 * The old shop's policy pages, in the owner's pasted words. (Lane TP)
 *
 * THE OWNER, 6 October 2026: "I need the terms etc pages from the original
 * site … 100% same content and layout. also fix any paragraph etc issue." He
 * pasted each page; docs/tp-source/<slug>.txt is the paste, verbatim, and
 * 2027_09_05_200000_owner_policy_pages writes it. The defects pinned here, as
 * they would look on the shop:
 *
 *   1. /delivery/ still reading "This is placeholder wording…" — or worse, a
 *      word of his changed on the way ("K-Beauty Bliss ." tidied, a sentence
 *      dropped, a bullet merged into a paragraph).
 *   2. The bullets of "Customs / Import" printed as lines starting "* ", the
 *      e-mail address as plain text, or the privacy page's last line showing
 *      Markdown brackets "[info@…](mailto:…)" to a shopper.
 *   3. A page he had already rewritten in Pages → User pages replaced.
 *   4. An Arabic translation invented for him.
 *
 * MUTATION NOTES, RUN:
 *   · change one word in the migration's delivery text → RED (case 1, both
 *     the generator check and the word-for-word check).
 *   · drop the `* ` branch in PastedPolicyText::toHtml() → RED (case 2).
 *   · drop the Markdown str_replace in PastedPolicyText::inline() → RED (2).
 *   · replace the isSeeded() guard in the migration with `false` → RED (3).
 *   · change a hash in SeededPolicyPages::SEEDED → RED (the seed pin).
 */

use App\Models\Page;
use App\Models\Translation;
use App\Support\PastedPolicyText;
use App\Support\SeededPolicyPages;
use Illuminate\Support\Facades\DB;

function tpMigration(): object
{
    return require database_path('migrations/2027_09_05_200000_owner_policy_pages.php');
}

/** @return array<string, array{title: string, content: string}> */
function tpPages(): array
{
    return (new ReflectionClassConstant(tpMigration(), 'PAGES'))->getValue();
}

/** The words of a page, whitespace collapsed — what a reader reads. */
function tpWords(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

it('pins the placeholder fingerprints to the seed migrations that wrote them', function () {
    $seeded = [];

    foreach (['2026_08_29_140000_seed_policy_pages.php', '2026_11_06_000000_seed_footer_content_pages.php'] as $file) {
        $migration = require database_path('migrations/'.$file);

        foreach ((new ReflectionMethod($migration, 'pages'))->invoke($migration) as $page) {
            $seeded[$page['slug']] = SeededPolicyPages::fingerprint($page['content']);
        }
    }

    foreach (SeededPolicyPages::SEEDED as $slug => $hash) {
        expect($seeded[$slug] ?? null)->toBe($hash, "the {$slug} seed no longer matches SeededPolicyPages::SEEDED");
    }

    // The editor note deleted, or the markup re-serialised: still the seed.
    $note = '<p><em>This is placeholder wording. Edit this page in Store &rarr; Pages to publish your own answers.</em></p>';
    $seed = require database_path('migrations/2026_11_06_000000_seed_footer_content_pages.php');
    $faqs = (string) collect((new ReflectionMethod($seed, 'pages'))->invoke($seed))->firstWhere('slug', 'faqs')['content'];
    expect(SeededPolicyPages::isSeeded('faqs', $faqs))->toBeTrue()
        ->and(SeededPolicyPages::isSeeded('faqs', str_replace($note, '', $faqs)))->toBeTrue()
        ->and(SeededPolicyPages::isSeeded('faqs', str_replace("\n", '  ', $faqs)))->toBeTrue()
        ->and(SeededPolicyPages::isSeeded('faqs', $faqs.'<p>One more answer.</p>'))->toBeFalse()
        ->and(SeededPolicyPages::isSeeded('about', $faqs))->toBeFalse();
});

it('ships exactly what PastedPolicyText makes of each paste, for every paste', function () {
    $pages = tpPages();

    expect(array_keys($pages))->toBe(array_keys(PastedPolicyText::SOURCES));

    foreach (PastedPolicyText::SOURCES as $slug => $headings) {
        $made = PastedPolicyText::toHtml((string) file_get_contents(base_path("docs/tp-source/{$slug}.txt")), $headings);

        expect($pages[$slug])->toBe(['title' => $made['title'], 'content' => $made['html']], "{$slug}: regenerate with php tools/tp-make-migration.php");
    }
});

it('changes no word of the owner\'s paste — only its layout', function () {
    $markers = 0;

    foreach (tpPages() as $slug => $page) {
        $paste = (string) file_get_contents(base_path("docs/tp-source/{$slug}.txt"));
        $lines = preg_split('/\R/u', $paste) ?: [];
        $title = array_shift($lines);

        $body = implode("\n", array_map(static fn (string $l): string => (string) preg_replace('/^\* /', '', $l), $lines));
        $body = str_replace('[info@kbeautybliss.com](mailto:info@kbeautybliss.com)', 'info@kbeautybliss.com', $body);
        // The run-in question numbers — "…through Tabby. 2: Can I change…?" —
        // are the one thing removed, and they are counted below.
        $body = (string) preg_replace('/(?<=[.!])\s+\d{1,2}:\s+|^\s*\d{1,2}:\s+/mu', ' ', $body, -1, $n);
        $markers += $n;

        expect(html_entity_decode($page['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))->toBe(trim((string) $title))
            ->and(tpWords($page['content']))->toBe(trim((string) preg_replace('/\s+/u', ' ', $body)), $slug);
    }

    // Exactly the numbers the FAQ paste carries ("2:", "2:", "2:", "3:" when
    // this was written) and nothing else anywhere.
    $pasted = 0;
    foreach (array_keys(tpPages()) as $slug) {
        $pasted += preg_match_all('/\d{1,2}:\s/u', (string) file_get_contents(base_path("docs/tp-source/{$slug}.txt")));
    }
    expect($markers)->toBe($pasted)->and($markers)->toBeGreaterThanOrEqual(4);

    // The spots a tidy-minded edit would reach for, kept as he wrote them.
    expect(tpPages()['delivery']['content'])->toContain('Thank you for choosing K-Beauty Bliss . We look forward')
        ->and(tpPages()['faqs']['content'])->toContain('<h3>What should i do if the product i want to buy is out of stock?</h3>');
});

it('splits the FAQ questions that were run into the answer before them, and every question pairs for FAQPage', function () {
    $faqs = $this->get('/faqs/')->assertOk()->getContent();

    // The defect as it read on the old page: the next question glued to the
    // end of an answer, with a stray number.
    expect($faqs)->toContain('<p>We accept all major credit/debit cards, and provide Cash On Delivery within the UAE. Additionally, we have flexible payment option through Tabby.</p>')
        ->toContain('<h3>Can I change or cancel my order once placed?</h3>')
        ->not->toMatch('/\d: (Can I|What|Which)/')
        ->toContain('<h2>PAYMENT OPTIONS</h2>')
        ->toContain('Please check our <a href="/refund_returns/">Returns Policy page</a> for more information.');

    $body = (string) Page::where('slug', 'faqs')->value('content');
    $questions = array_column(\App\Services\Seo\FaqSchema::pairs($body), 0);
    preg_match_all('#<h3>(.*?)</h3>#', $body, $h3);

    expect($questions)->toBe(array_map(fn ($q) => html_entity_decode($q, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $h3[1]))
        ->and(count($questions))->toBeGreaterThanOrEqual(8)
        ->and(array_values(array_intersect(['Which payment methods do you accept?', 'Can I change or cancel my order once placed?', 'What are your delivery charges?', 'Which K-Beauty brands do you carry?'], $questions)))->toHaveCount(4)
        ->and(\App\Services\Seo\FaqSchema::node($body, null)['@type'] ?? null)->toBe('FAQPage');
});

it('serves the pages with real headings, bullets, paragraphs and mailto links', function () {
    $delivery = $this->get('/delivery/')->assertOk()->getContent();

    // Lane PH: the page's one h1 is the brand-design header's (Appearance ->
    // Site layout -> Page header), carrying the same words.
    expect($delivery)->toContain('id="brw-ph-title">Shipping &amp; Delivery</h1>')
        ->toContain('<h3>Local Delivery:</h3>')
        ->toContain('<h3>International Delivery:</h3>')
        ->toContain('<h3>Customs / Import</h3>')
        ->not->toContain('placeholder wording')
        ->not->toContain('<li>* ')
        ->and(preg_match('#<h3>Customs / Import</h3>\s*<ul>((?:\s*<li>.*?</li>)+)\s*</ul>#s', $delivery, $m))->toBe(1)
        ->and(substr_count($m[1], '<li>'))->toBe(5)
        // The opening sentence ends in ":" and is still a paragraph.
        ->and($delivery)->toContain('<p>At K-Beauty Bliss, we are committed');

    $this->get('/refund_returns/')->assertOk()
        ->assertSee('id="brw-ph-title">Refund and Returns Policy</h1>', false)
        ->assertSee('please email us at <a href="mailto:info@kbeautybliss.com">info@kbeautybliss.com</a></p>', false);

    $privacy = $this->get('/privacy-policy/')->assertOk()->getContent();
    expect($privacy)->toContain('sent to us at <a href="mailto:info@kbeautybliss.com">info@kbeautybliss.com</a></p>')
        ->not->toContain('[info@kbeautybliss.com]')
        ->not->toContain('](mailto:')
        ->toContain('<li>To register and service your account.</li>');
});

it('writes only a page still holding the seeded placeholder, and no Arabic', function () {
    $seed = require database_path('migrations/2026_11_06_000000_seed_footer_content_pages.php');
    $seeded = collect((new ReflectionMethod($seed, 'pages'))->invoke($seed))->keyBy('slug');

    // Delivery is back to the seed; Returns has the owner's own words.
    Page::where('slug', 'delivery')->update(['content' => $seeded['delivery']['content'], 'title' => 'Shipping &amp; Delivery']);
    Page::where('slug', 'refund_returns')->update(['content' => '<p>His own returns words.</p>']);
    $arabicBefore = DB::table('translations')->where('locale', 'ar')->where('group', '!=', Translation::GROUP_UI)->count();

    tpMigration()->up();

    expect(Page::where('slug', 'delivery')->value('content'))->toBe(tpPages()['delivery']['content'])
        ->and(Page::where('slug', 'refund_returns')->value('content'))->toBe('<p>His own returns words.</p>')
        ->and(DB::table('translations')->where('locale', 'ar')->where('group', '!=', Translation::GROUP_UI)->count())->toBe($arabicBefore);

    // A second run changes nothing: the page is no longer the placeholder.
    Page::where('slug', 'delivery')->update(['content' => '<p>Edited after.</p>']);
    tpMigration()->up();
    expect(Page::where('slug', 'delivery')->value('content'))->toBe('<p>Edited after.</p>');
});

it('escapes the paste, so no line of it can become markup', function () {
    $made = PastedPolicyText::toHtml("Title & Co\n<script>alert(1)</script>\n* <b>x</b>\nMail info@kbeautybliss.com now");

    expect($made['title'])->toBe('Title &amp; Co')
        ->and($made['html'])->toBe("<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>\n<ul>\n<li>&lt;b&gt;x&lt;/b&gt;</li>\n</ul>\n<p>Mail <a href=\"mailto:info@kbeautybliss.com\">info@kbeautybliss.com</a> now</p>");
});
