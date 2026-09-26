<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Support\Locale;

/**
 * FAQPage JSON-LD, built from the questions a content page already answers.
 *
 * ── WHY THIS EXISTS AT ALL, GIVEN THAT THE RICH RESULT IS GONE ─────────────
 *
 * `docs/SEO-COMPETITIVE.md` §1.9 refused `FAQPage` across three rounds, and the
 * sourcing behind that refusal is correct and is not being overturned: Google
 * restricted FAQ rich results to government and health sites in August 2023 and
 * stopped showing them in Search on 7 May 2026. **Nobody should expect a
 * drop-down of questions under this shop's result. That is not what this is
 * for and the owner should not be told it is.**
 *
 * What the refusal did not weigh, and what the owner asked for by name, is the
 * other consumer. An FAQPage node is the one place on this shop where a
 * question and its answer are published as ONE machine-readable pair — a
 * `Question` with an `acceptedAnswer` — rather than as an `<h3>` that happens to
 * sit above a `<p>`. That pairing is what an answer engine extracts, and it is
 * the shape the whole "write in self-contained chunks" recommendation in
 * `docs/SEO-GAP.md` §12 is reaching for in prose. It costs one node on one page,
 * it is valid schema.org whatever Google renders, and it is off until switched
 * on.
 *
 * ── WHERE THE CONTENT COMES FROM: THE PAGE, AND ONLY THE PAGE ──────────────
 *
 * There is no new editor, no new table and no new box to fill in. The source is
 * the content page's own body, which the owner already writes at
 * Content → Pages. A heading becomes a `Question` and the flow content under it
 * becomes its `Answer`.
 *
 * That is deliberate, and it is the rule Google actually enforces about this
 * markup: the marked-up questions and answers must be the ones a visitor can
 * read on the page. A separate FAQ editor would be a second copy of the same
 * text, and the two would disagree within a month — at which point the shop is
 * publishing to Google something its own page does not say, which is the one
 * category of structured-data fault that draws a manual action rather than a
 * shrug.
 *
 * ── THE QUESTION MARK IS THE WHOLE SELECTION RULE ──────────────────────────
 *
 * A heading counts as a question only if it ends in `?`. Nothing else decides
 * it: not the page's slug, not a setting, not a list of page names in this file.
 *
 * That one rule is what keeps this node off the six content pages that are not
 * FAQs. The shipped `/refund_returns/` is written as "What we need from you",
 * "Refunds", "Your rights" — statements, correctly not questions — and the
 * shipped `/faqs/` is written as "How do I check where my order is?", "How much
 * is delivery?", "Can I return something?", "How do I reach a person?". So the
 * FAQ page produces four pairs and the returns page produces none, with no page
 * named anywhere in this class. Rewrite the returns page as questions and it
 * earns the node; rewrite the FAQ page as statements and it loses it. Both are
 * the right answer, and both follow from the page rather than from code.
 *
 * ── PLAIN TEXT, NOT THE PAGE'S HTML ────────────────────────────────────────
 *
 * Google's documentation allows a small HTML subset inside an answer. This
 * publishes text with every tag removed anyway, and the reason is rule 5 rather
 * than tidiness: an answer carried through verbatim would put operator-authored
 * `<a href="…">` into a document this shop hands to a search engine, and the
 * href is a setting-shaped value reaching a published field. Stripping the tags
 * removes that class of question entirely, at the cost of a link inside an
 * answer — which the visible page still carries, and which the answer's own
 * words already describe ("see our Track my order page").
 *
 * The values that remain are still untrusted at encode time: they are page
 * bodies, editable over the network. They are encoded by the same hex-flag
 * encoder every other node uses — see Support\Seo::encodeJsonLd() and
 * self::encode(), which carries the identical flag set for the callers that
 * need the bytes before Seo gets them (the preview generator, the tests).
 * FaqSchemaTest drives a question containing `</script><script>` through both.
 *
 * ── WHAT IT REFUSES, AND WHY EACH REFUSAL IS THE SAFE DIRECTION ────────────
 *
 *   - Fewer than MIN_QUESTIONS usable pairs: no node. One question with an
 *     answer is not an FAQ page, and an FAQPage node claiming to describe a
 *     page that answers one thing is a claim about the document that the
 *     document does not support. Same reasoning as BusinessAddress refusing
 *     half an address.
 *   - More than MAX_QUESTIONS headings-with-a-question-mark: no node. A page
 *     with two dozen question headings is a page this rule has misread, and the
 *     honest answer to "I do not understand this document" is to say nothing
 *     about it rather than to publish the first twenty-four.
 *   - An answer under MIN_ANSWER_WORDS, or over MAX_ANSWER_CHARS: that pair is
 *     dropped, and the rest stand. NOT truncated — a truncated answer is an
 *     answer the page does not give, which is the mismatch the whole class is
 *     designed to avoid.
 *
 * ── OFF UNTIL SWITCHED ON ──────────────────────────────────────────────────
 *
 * `SeoSettings::DEFAULTS['faq_schema']` is '0', so applying the package that
 * carries this class changes no page by a byte. Turning it on needs the flag on
 * `AdminController::SETTING_RULES` and a card on the SEO & Meta screen; both
 * are one line each and are written out in docs/SEO-ROUND-5-VERIFICATION.md,
 * because neither file is this lane's.
 */
final class FaqSchema
{
    /** The settings key. Ships at '0' — see SeoSettings::DEFAULTS. */
    public const SETTING = 'faq_schema';

    /** Below this many usable pairs, no node at all. */
    public const MIN_QUESTIONS = 2;

    /** Above this many question headings, the rule has misread the page. */
    public const MAX_QUESTIONS = 24;

    /** An answer shorter than this is a heading with nothing under it. */
    public const MIN_ANSWER_WORDS = 4;

    /** One answer's ceiling. A longer one is dropped, never cut. */
    public const MAX_ANSWER_CHARS = 1200;

    /** Is the node switched on for this shop? */
    public static function enabled(array $s): bool
    {
        return SeoSettings::from($s, self::SETTING) === '1';
    }

    /**
     * How many published content pages would emit a FAQPage node, and how many
     * question/answer pairs they hold between them.
     *
     * READ-ONLY AND SIDE-EFFECT FREE, at Lane S7's request. Store -> SEO & Meta
     * -> Overview needs to tell the owner one of two true things, and neither
     * has any other symptom on the shop:
     *
     *   - the flag is OFF and his pages ARE written as questions, so answer
     *     engines are being shown none of them; or
     *   - the flag is ON and NO page is written as questions, so the switch he
     *     ticked publishes nothing and he is entitled to know that rather than
     *     assume it worked.
     *
     * It applies the SAME rule the node does -- self::pairs(), and the same
     * MIN_QUESTIONS floor -- rather than a second count that could disagree with
     * what is actually published. A page with one question is deliberately NOT
     * counted, because one question is a heading, not an FAQ.
     *
     * Cost: one query over the seven published content pages, on one admin
     * screen. It is not called from the storefront.
     *
     * @return array{pages: int, questions: int}
     */
    public static function census(): array
    {
        $pages = 0;
        $questions = 0;

        $rows = \App\Models\Page::query()
            ->where('status', 'published')
            ->select('content')
            ->get();

        foreach ($rows as $row) {
            $pairs = self::pairs((string) $row->content);

            if (count($pairs) < self::MIN_QUESTIONS) {
                continue;
            }

            $pages++;
            $questions += count($pairs);
        }

        return ['pages' => $pages, 'questions' => $questions];
    }

    /**
     * The FAQPage node for a page body, or null.
     *
     * @param  string  $html  the page's own content, as the page prints it
     * @param  string|null  $url  this page's canonical, absolute
     * @return array<string, mixed>|null
     */
    public static function node(string $html, ?string $url): ?array
    {
        $pairs = self::pairs($html);

        if (count($pairs) < self::MIN_QUESTIONS) {
            return null;
        }

        $entities = [];

        foreach ($pairs as [$question, $answer]) {
            $entities[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
        }

        $node = ['@context' => 'https://schema.org', '@type' => 'FAQPage'];

        /*
         * `url` only when there is one, matching every other node in this
         * application: Support\Seo hands over a canonical that is already
         * absolute and base-path aware, or null, and a node carrying a
         * root-relative identifier is one Google reports as invalid.
         */
        if ($url !== null && $url !== '') {
            $node['url'] = $url;
        }

        // A FAQPage is a WebPage is a CreativeWork, so inLanguage is a property
        // it really has — the same test Support\Seo applies before putting it on
        // CollectionPage and Article and withholding it from Product.
        $node['inLanguage'] = Locale::current();
        $node['mainEntity'] = $entities;

        return $node;
    }

    /**
     * Question/answer pairs found in a page body.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function pairs(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        /*
         * Split on every heading, keeping the heading text. PREG_SPLIT_DELIM_CAPTURE
         * hands back [before, h-text, after, h-text, after, …], so a heading and
         * the block that follows it are adjacent in one flat list and no second
         * pass over the document is needed.
         *
         * All six levels, not just h2/h3: a page whose FAQ is written with <h4>
         * is a page whose FAQ is written with <h4>, and a rule that only saw two
         * levels would silently emit nothing on it. The question mark is the
         * selection rule; the tag name is not.
         */
        $parts = preg_split(
            '#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if (! is_array($parts) || count($parts) < 3) {
            return [];
        }

        $questions = 0;
        $pairs = [];

        // Element 0 is whatever preceded the first heading. From there the list
        // alternates heading, body, heading, body.
        for ($i = 1; $i < count($parts); $i += 2) {
            $question = self::text($parts[$i]);

            if (! str_ends_with($question, '?')) {
                continue;
            }

            $questions++;

            if ($questions > self::MAX_QUESTIONS) {
                return [];
            }

            $answer = self::text($parts[$i + 1] ?? '');

            if ($answer === ''
                || mb_strlen($answer) > self::MAX_ANSWER_CHARS
                || str_word_count($answer) < self::MIN_ANSWER_WORDS) {
                continue;
            }

            $pairs[] = [$question, $answer];
        }

        return $pairs;
    }

    /**
     * The node as bytes, escaped exactly as Support\Seo::encodeJsonLd() escapes
     * every other node.
     *
     * The four HEX flags and NOT `JSON_UNESCAPED_SLASHES`, which is the flag
     * that let an `org_name` of "</script><script>alert(1)</script>" execute on
     * every page of this shop. See the note on Support\Seo::encodeJsonLd(); the
     * flags here are the same set for the same reason, and FaqSchemaTest asserts
     * the output of this method carries no `<` at all.
     *
     * @param  array<string, mixed>  $node
     */
    public static function encode(array $node): ?string
    {
        $json = json_encode(
            $node,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return $json === false ? null : $json;
    }

    /** A fragment of page HTML as the plain text a consumer should read. */
    private static function text(string $html): string
    {
        $text = html_entity_decode(
            strip_tags(preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // A non-breaking space is whitespace to a reader and is not \s to PCRE,
        // so a heading typed with one would keep it and the '?' test would still
        // pass while the published text carried U+00A0 in the middle of it.
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
