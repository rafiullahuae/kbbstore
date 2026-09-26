<?php

declare(strict_types=1);

use App\Services\Seo\FaqSchema;
use App\Services\Seo\SeoSettings;
use App\Support\Seo;

/**
 * =============================================================================
 * FAQPage — THE ONE STRUCTURED-DATA TYPE THIS SHOP DID NOT EMIT
 * =============================================================================
 *
 * Measured before this lane: `grep -rn FAQPage app resources routes tests`
 * returned **nothing**. Every other node `docs/SEO-GAP.md` §3 lists is built —
 * Organization/Store with a postal address, WebSite + SearchAction, Product,
 * Offer, AggregateOffer, UnitPriceSpecification with valueAddedTaxIncluded,
 * AggregateRating, OfferShippingDetails, MerchantReturnPolicy, Article,
 * CollectionPage, ItemList, BreadcrumbList. `FAQPage` was the single row in that
 * table marked **Missing** that was not also marked "do not build".
 *
 * ── WHAT THIS DOES AND DOES NOT CLAIM ──────────────────────────────────────
 *
 * It does NOT claim an FAQ drop-down under the shop's search result. Google
 * stopped showing those on 7 May 2026 and `docs/SEO-COMPETITIVE.md` §1.9 is
 * right about it. What it claims is one machine-readable question/answer PAIR
 * per question, which is the unit an answer engine extracts and which an `<h3>`
 * above a `<p>` is not. The full argument is on Services\Seo\FaqSchema.
 *
 * Every case below is either a property of the node, or a refusal — and the
 * refusals are the half worth reading, because each of them is a way this could
 * publish something the page does not say.
 */
beforeEach(function () {
    \App\Models\Setting::query()->delete();
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
});

/** The body of the FAQ page this shop actually ships (2026_11_06 seed). */
function faqShippedBody(): string
{
    return <<<'HTML'
<h3>How do I check where my order is?</h3>
<p>Use our <a href="/track-my-order/">Track my order</a> page. You will need the order number from your confirmation email and the email address you ordered with.</p>

<h3>How much is delivery?</h3>
<p>Delivery options and charges are calculated for your address at checkout and shown in full before you pay. See <a href="/delivery/">Shipping &amp; Delivery</a>.</p>

<h3>Can I return something?</h3>
<p>Yes — please start by <a href="/contact-us/">contacting us</a> with your order number. See <a href="/refund_returns/">Returns &amp; Refunds</a>.</p>

<h3>How do I reach a person?</h3>
<p>The phone number and WhatsApp link at the bottom of every page reach us directly, or you can use our <a href="/contact-us/">Contact us</a> page.</p>
HTML;
}

/** The body of the RETURNS page this shop ships — statements, not questions. */
function faqReturnsBody(): string
{
    return <<<'HTML'
<h3>What we need from you</h3>
<p>Please keep the order number to hand — it is on the confirmation email you received when the order was placed.</p>

<h3>Refunds</h3>
<p>Where a refund is agreed, it is made to the payment method the order was paid with.</p>

<h3>Your rights</h3>
<p>Nothing on this page affects the rights you have under the consumer law that applies to your purchase.</p>
HTML;
}

/* ─────────────────────────────── rule 1 first ───────────────────────────── */

it('ships switched off, so no page gains a node by applying this', function () {
    /*
     * RULE 1. The flag lives in SeoSettings::DEFAULTS at '0', so a shop that has
     * never seen the control reads it as off. `AdminController::SETTING_RULES`
     * does not carry the key yet — that line and the card on Store → SEO & Meta
     * are written out in docs/SEO-ROUND-5-VERIFICATION.md — so there is not even
     * a way to turn it on by accident.
     *
     * MUTATION NOTE, RUN: changing the default to '1' makes this red -- 1 failed.
     */
    expect(SeoSettings::get(FaqSchema::SETTING))->toBe('0');
    expect(FaqSchema::enabled(SeoSettings::map()))->toBeFalse();
});

it('reads the flag from the settings map the rest of the SEO layer reads', function () {
    \App\Models\Setting::updateOrCreate(['key' => FaqSchema::SETTING], ['value' => '1', 'autoload' => true]);
    \App\Models\Setting::flushMap();

    expect(FaqSchema::enabled(SeoSettings::map()))->toBeTrue();

    // Blank is not on. The SEO screen posts every field on every save, so a
    // control the owner has never touched arrives as '' — the trap SeoSettings
    // itself exists for, re-checked here because this is a new reader of it.
    \App\Models\Setting::updateOrCreate(['key' => FaqSchema::SETTING], ['value' => '', 'autoload' => true]);
    \App\Models\Setting::flushMap();

    expect(FaqSchema::enabled(SeoSettings::map()))->toBeFalse();
});

/* ──────────────────────────── the node it builds ─────────────────────────── */

it('turns the FAQ page this shop already ships into four question and answer pairs', function () {
    /*
     * The fixture is the seeded body, character for character, from
     * database/migrations/2026_11_06_000000_seed_footer_content_pages.php. Not a
     * simplified one: if the mechanism cannot read the page the owner already
     * has, it is worth nothing to him.
     */
    $node = FaqSchema::node(faqShippedBody(), 'https://kbeautybliss.test/faqs/');

    expect($node)->not->toBeNull();
    expect($node['@type'])->toBe('FAQPage');
    expect($node['@context'])->toBe('https://schema.org');
    expect($node['url'])->toBe('https://kbeautybliss.test/faqs/');
    expect($node['mainEntity'])->toHaveCount(4);

    expect($node['mainEntity'][0]['@type'])->toBe('Question');
    expect($node['mainEntity'][0]['name'])->toBe('How do I check where my order is?');
    expect($node['mainEntity'][0]['acceptedAnswer']['@type'])->toBe('Answer');
    expect($node['mainEntity'][0]['acceptedAnswer']['text'])
        ->toBe('Use our Track my order page. You will need the order number from your confirmation email and the email address you ordered with.');

    // The entity reference in the second answer is decoded, not published as
    // "Shipping &amp; Delivery" — the node carries text, and JSON has no
    // entities. It is re-escaped at encode time by the hex flags below.
    expect($node['mainEntity'][1]['acceptedAnswer']['text'])
        ->toBe('Delivery options and charges are calculated for your address at checkout and shown in full before you pay. See Shipping & Delivery.');
});

it('states the language of the document, which is a property an FAQPage really has', function () {
    /*
     * An FAQPage is a WebPage is a CreativeWork, so `inLanguage` is defined on
     * it — the same test Support\Seo applies before putting it on CollectionPage
     * and Article and withholding it from Product, Offer and BreadcrumbList.
     * docs/SEO-ARABIC-PARITY.md §2 is the reasoning.
     */
    $node = FaqSchema::node(faqShippedBody(), 'https://kbeautybliss.test/faqs/');

    expect($node['inLanguage'])->toBe('en');
});

it('omits url rather than publishing a relative identifier', function () {
    /*
     * Google resolves a node's `url` as an identifier and reports a relative one
     * as invalid — the same defect Support\Seo's breadcrumb block records having
     * shipped. No canonical means no `url` key, not an empty one.
     */
    $node = FaqSchema::node(faqShippedBody(), null);

    expect(array_key_exists('url', $node))->toBeFalse('a node with no canonical published a url key anyway');
});

it('reads a question written with any heading level, not just h3', function () {
    // The selection rule is the question mark. The tag name is not, so an FAQ
    // written with <h2> or <h4> is read exactly the same way.
    $html = '<h2>Is this read?</h2><p>Yes, it is read whatever the level.</p>'
        . '<h4>And this one?</h4><p>Also read, because the rule is the question mark.</p>';

    expect(FaqSchema::pairs($html))->toHaveCount(2);
});

/* ──────────────────────────────── refusals ──────────────────────────────── */

it('emits nothing for a content page whose headings are statements', function () {
    /*
     * ── THE CASE THAT MAKES THE WHOLE DESIGN SAFE ───────────────────────────
     *
     * There is no list of page slugs anywhere in FaqSchema. The returns page
     * this shop ships is written as "What we need from you", "Refunds", "Your
     * rights" — and produces no node, because none of those ends in a question
     * mark. The FAQ page produces four. Both follow from what the owner wrote.
     *
     * Without this rule the mechanism would publish FAQPage markup on six pages
     * that are not FAQs, which is a claim about each document that the document
     * does not support.
     *
     * MUTATION NOTE, RUN: deleting the `str_ends_with($question, '?')` guard
     * makes this red and gives the returns page a three-question FAQPage
     * node -- 1 failed.
     */
    expect(FaqSchema::pairs(faqReturnsBody()))->toBe([]);
    expect(FaqSchema::node(faqReturnsBody(), 'https://kbeautybliss.test/refund_returns/'))->toBeNull();
});

it('refuses to call one question an FAQ page', function () {
    /*
     * Same reasoning as Support\BusinessAddress refusing half a postal address:
     * a node is a claim about the document, and "this document is a list of
     * frequently asked questions" is not supported by one of them.
     *
     * MUTATION NOTE, RUN: lowering MIN_QUESTIONS to 1 makes this red -- 1 failed.
     */
    $html = '<h3>Is one question enough?</h3><p>No, it is not enough on its own.</p>';

    expect(FaqSchema::pairs($html))->toHaveCount(1);
    expect(FaqSchema::node($html, 'https://kbeautybliss.test/faqs/'))->toBeNull();
});

it('drops a question whose answer is a heading with nothing under it', function () {
    /*
     * A question with no answer is the half-a-pair case. The pair goes and the
     * others stand — the node is still correct about the page, it just says less.
     *
     * MUTATION NOTE, RUN: removing the MIN_ANSWER_WORDS check makes this red --
     * three pairs come back, one of them with the text "Yes." -- 1 failed.
     */
    $html = '<h3>Does an empty answer count?</h3><h3>Does a two-word answer count?</h3><p>No.</p>'
        . '<h3>Does a real answer count?</h3><p>Yes, and this one is long enough to be useful.</p>'
        . '<h3>And a second real one?</h3><p>Yes, so the node has two pairs and stands.</p>';

    $pairs = FaqSchema::pairs($html);

    expect($pairs)->toHaveCount(2);
    expect($pairs[0][0])->toBe('Does a real answer count?');
    expect($pairs[1][0])->toBe('And a second real one?');
});

it('drops an answer past the ceiling rather than cutting it', function () {
    /*
     * NOT TRUNCATED. A truncated answer is an answer the page does not give, and
     * markup that does not match the visible page is the one structured-data
     * fault that draws a manual action rather than a shrug. Dropping the pair
     * leaves the node saying less and saying it accurately.
     *
     * MUTATION NOTE, RUN: replacing the drop with mb_substr() truncation makes
     * this red -- the long pair comes back with 1200 characters -- 1 failed.
     */
    $long = str_repeat('word ', (int) ceil(FaqSchema::MAX_ANSWER_CHARS / 5) + 20);
    $html = '<h3>Is this answer too long?</h3><p>' . $long . '</p>'
        . '<h3>Is this one fine?</h3><p>Yes, this one is a normal length.</p>'
        . '<h3>And this one?</h3><p>Also a normal length, so two pairs remain.</p>';

    $pairs = FaqSchema::pairs($html);

    expect($pairs)->toHaveCount(2);
    expect($pairs[0][0])->toBe('Is this one fine?');
});

it('says nothing at all about a page with two dozen question headings', function () {
    /*
     * A page with more question headings than MAX_QUESTIONS is a page this rule
     * has misread — a transcript, a glossary, a generated document. The honest
     * answer to "I do not understand this page" is silence, not the first
     * twenty-four of something.
     *
     * MUTATION NOTE, RUN: changing the `return []` to a `break` makes this red
     * -- twenty-four pairs come back -- 1 failed.
     */
    $html = '';

    for ($i = 0; $i <= FaqSchema::MAX_QUESTIONS; $i++) {
        $html .= '<h3>Question number ' . $i . '?</h3><p>An answer of a reasonable length here.</p>';
    }

    expect(FaqSchema::pairs($html))->toBe([]);
});

it('emits nothing for a page with no headings at all', function () {
    expect(FaqSchema::pairs('<p>Just a paragraph of prose with no headings in it.</p>'))->toBe([]);
    expect(FaqSchema::pairs(''))->toBe([]);
    expect(FaqSchema::node('', 'https://kbeautybliss.test/faqs/'))->toBeNull();
});

/* ─────────────────────────── rule 5: the encoder ─────────────────────────── */

it('cannot close the script block it will sit inside', function () {
    /*
     * ── THE INCIDENT THIS FLAG SET EXISTS FOR ───────────────────────────────
     *
     * Support\Seo::encodeJsonLd() carried JSON_UNESCAPED_SLASHES, and an
     * `org_name` of "</script><script>alert(1)</script>" — an ordinary text box
     * on the SEO screen — executed on every page of the site. A page body is the
     * same kind of value: operator-authored, editable over the network, and
     * published inside a <script> element.
     *
     * FaqSchema::encode() carries the identical four HEX flags and NOT
     * JSON_UNESCAPED_SLASHES. The assertion is the strongest available: the
     * bytes contain no `<` at all, so there is nothing for an HTML parser to
     * read as markup whatever the payload was.
     *
     * MUTATION NOTE, RUN: removing JSON_HEX_TAG makes this red with a raw
     * "</script>" in the output -- 1 failed.
     */
    $html = '<h3>Does </script><script>alert(1)</script> close the block?</h3>'
        . '<p>It must not, and the answer text carries the same payload </script><script>alert(2)</script> too.</p>'
        . '<h3>And a second question?</h3><p>Yes, so the node is built at all.</p>';

    $node = FaqSchema::node($html, 'https://kbeautybliss.test/faqs/');
    $json = FaqSchema::encode($node);

    expect($json)->toBeString();
    expect(str_contains($json, '<'))->toBeFalse('the encoded node carries a raw < and can be parsed as markup');
    expect(str_contains($json, '</script>'))->toBeFalse('the encoded node can close the script element it sits in');
    /*
     * And the payload does not merely fail to execute -- it is gone. text()
     * strips a <script> element WITH ITS CONTENTS before strip_tags runs, so
     * "alert(1)" never reaches the node either. strip_tags on its own keeps the
     * body of a script element, which would have published the JavaScript as
     * readable text inside the question. Belt as well as braces: the encoder
     * makes it inert, this makes it absent.
     *
     * MUTATION NOTE, RUN: dropping the script/style preg_replace from text() so
     * only strip_tags runs makes this red -- the question comes back as
     * "Does alert(1) close the block?" -- 1 failed.
     */
    expect(json_decode($json, true)['mainEntity'][0]['name'])
        ->toBe('Does close the block?');
    expect($json)->not->toContain('alert(1)');
});

it('uses the same flag set Support\\Seo uses, proved against Support\\Seo itself', function () {
    /*
     * TWO ENCODERS IS TWO SOURCES OF TRUTH, so this asserts they agree rather
     * than trusting the comment that says they do. Support\Seo's encoder is
     * private, so it is driven through the thing it encodes: a shop whose
     * org_name carries the payload, rendered, and the emitted <script> read back.
     *
     * If Seo's flags are ever "corrected" to match the old brief, this goes red
     * here as well as in SeoRenderTest.
     */
    \App\Models\Setting::updateOrCreate(['key' => 'org_name'], ['value' => '</script><script>alert(1)</script>', 'autoload' => true]);
    \App\Models\Setting::updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbeautybliss.test', 'autoload' => true]);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();

    $html = Seo::render(['title' => 'Anything']);

    expect(preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m))->toBe(1);
    expect(str_contains($m[1], '<'))->toBeFalse('Support\Seo emitted a raw < inside its JSON-LD');

    // The same payload through FaqSchema::encode() comes out the same way.
    expect(str_contains((string) FaqSchema::encode(['name' => '</script><script>alert(1)</script>']), '<'))->toBeFalse();
});

it('keeps Arabic text as Arabic rather than as escape sequences', function () {
    /*
     * JSON_UNESCAPED_UNICODE, matching Support\Seo. Without it an Arabic
     * question is published as كل... — valid JSON that every consumer
     * decodes correctly, and unreadable to the one person who has to check it.
     * This shop is bilingual and the FAQ page will be translated.
     */
    $json = (string) FaqSchema::encode(['name' => 'كم تكلفة التوصيل؟']);

    expect($json)->toContain('كم تكلفة التوصيل؟');
});
