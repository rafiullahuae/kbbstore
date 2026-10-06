<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The owner's pasted policy text, laid out as the page's HTML. (Lane TP)
 *
 * He pasted the old shop's pages himself, one at a time; they are kept
 * verbatim in docs/tp-source/<slug>.txt. The WORDS are his and are never
 * changed here — not a typo, not "K-Beauty Bliss ." with its space. Only the
 * structure is decided, by rules that read off the paste itself:
 *
 *   - the first line is the page's title (returned apart, not in the body);
 *   - a short line in CAPITALS ("PAYMENT OPTIONS") is a section heading, h2;
 *   - a SHORT line ending in ":" or "?" (at most HEADING_MAX characters), or
 *     one named in $headings, is a subheading, h3 — the level every content
 *     page on this shop already uses, and the level a FAQ question has to be
 *     for App\Services\Seo\FaqSchema to pair it with the answer under it.
 *     Short, because the delivery page's opening sentence also ends "…our
 *     delivery services:" and is a paragraph, not a heading;
 *   - consecutive lines starting "* " are one bulleted list;
 *   - every other non-blank line is a paragraph.
 *
 * THE ONE PLACE A LINE IS SPLIT — the "paragraph issue" the owner asked to be
 * fixed. On the old FAQ page the next question was run into the end of the
 * previous answer: "…flexible payment option through Tabby. 2: Can I change
 * or cancel my order once placed?". A line that ends in "?" and carries
 * ". N: " before that question is two lines: the answer ends at its full stop,
 * the question becomes its own heading, and the stray "N: " — a number only
 * that question carried — is dropped. A question on its own line that still
 * carries such a number (" 3: What should i do…?") loses the number and the
 * stray space the same way. No word is changed or lost.
 *
 * Text is escaped, so nothing in a paste can become markup, and the one thing
 * that becomes a link is the shop's own e-mail address, as a mailto: (written
 * bare, or as the Markdown link `[info@…](mailto:info@…)` the privacy paste
 * carries — never literal brackets on the page).
 * Trailing spaces are not words and are trimmed.
 */
final class PastedPolicyText
{
    public const EMAIL = 'info@kbeautybliss.com';

    /**
     * The pasted pages, by slug, and the lines in each that are subheadings
     * although they do not end in ":". docs/tp-source/<slug>.txt is the paste.
     */
    public const SOURCES = [
        'delivery' => ['Customs / Import'],
        'refund_returns' => [],
        'privacy-policy' => [],
        'faqs' => [],
    ];

    /**
     * Words in a paste that name a page of this shop, linked to it (the words
     * stay exactly as written). Root-relative here; the migration that stores
     * the page writes them through Url::raw(), so a shop under a base path
     * gets links that carry it.
     */
    public const LINKS = [
        'Returns Policy page' => '/refund_returns/',
    ];

    /** The longest line read as a heading. The longest real one, the out-of-stock question, is 62; the shortest paragraph that ends in ":" is 200. */
    public const HEADING_MAX = 80;

    /** "…through Tabby. 2: Can I change or cancel my order once placed?" */
    private const RUN_IN = '/^(.*[.!])\s+\d{1,2}:\s+(\S.*\?)$/u';

    /** " 3: What should i do if the product i want to buy is out of stock?" */
    private const NUMBERED = '/^\s*\d{1,2}:\s+(\S.*\?)$/u';

    /**
     * @param  list<string>  $headings  extra lines (exact text) to draw as subheadings
     * @return array{title: string, html: string}  title as stored (escaped), body HTML
     */
    public static function toHtml(string $text, array $headings = []): array
    {
        $lines = preg_split('/\R/u', str_replace("\u{FEFF}", '', $text)) ?: [];
        $title = '';
        $out = [];
        $list = [];

        $flush = static function () use (&$list, &$out): void {
            if ($list !== []) {
                $out[] = "<ul>\n".implode("\n", $list)."\n</ul>";
                $list = [];
            }
        };

        $split = [];

        foreach ($lines as $raw) {
            $line = rtrim($raw);

            if (preg_match(self::RUN_IN, $line, $m) === 1) {
                array_push($split, $m[1], $m[2]);
            } elseif (preg_match(self::NUMBERED, $line, $m) === 1) {
                $split[] = $m[1];
            } else {
                $split[] = $line;
            }
        }

        foreach ($split as $line) {
            if (trim($line) === '') {
                continue;
            }

            if ($title === '') {
                $title = PageTitle::stored($line);

                continue;
            }

            if (str_starts_with($line, '* ')) {
                $list[] = '<li>'.self::inline(substr($line, 2)).'</li>';

                continue;
            }

            $flush();

            $out[] = match (true) {
                self::isSection($line) => '<h2>'.self::inline($line).'</h2>',
                self::isHeading($line) || in_array($line, $headings, true) => '<h3>'.self::inline($line).'</h3>',
                default => '<p>'.self::inline($line).'</p>',
            };
        }

        $flush();

        return ['title' => $title, 'html' => implode("\n", $out)];
    }

    private static function isHeading(string $line): bool
    {
        return (str_ends_with($line, ':') || str_ends_with($line, '?')) && mb_strlen($line) <= self::HEADING_MAX;
    }

    private static function isSection(string $line): bool
    {
        return mb_strlen($line) <= self::HEADING_MAX
            && preg_match('/\p{Lu}.*\p{Lu}/u', $line) === 1
            && mb_strtoupper($line) === $line;
    }

    /** One run of text: escaped, with the shop's address made a mailto link. */
    private static function inline(string $text): string
    {
        // The privacy paste ends with the address as a Markdown link; it is the
        // same mailto, so it collapses to the bare address and is linked below.
        $text = str_replace('['.self::EMAIL.'](mailto:'.self::EMAIL.')', self::EMAIL, $text);
        $safe = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
        $email = htmlspecialchars(self::EMAIL, ENT_NOQUOTES, 'UTF-8');

        $safe = str_replace($email, '<a href="mailto:'.$email.'">'.$email.'</a>', $safe);

        foreach (self::LINKS as $words => $path) {
            $safe = str_replace($words, '<a href="'.$path.'">'.$words.'</a>', $safe);
        }

        return $safe;
    }
}
