<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One translated sentence, escaped for the place it is read: between two tags.
 *
 * ── WHY THIS EXISTS, AND WHY IT IS ONLY USED SEVEN TIMES ────────────────────
 *
 * Blade's {{ }} is htmlspecialchars with ENT_QUOTES, which turns an apostrophe
 * into &#039;. That is correct and necessary inside an attribute, and it is
 * what every other __() on this storefront goes through.
 *
 * It is also a CHANGE to a page that was not supposed to change. Seven
 * sentences on this shop contain an apostrophe — "You've unlocked free
 * delivery!", "Everything you've looked at is already in your bag.", "Don't
 * want these reminders?" and four more — and every one of them was literal text
 * in a template before the interface-string conversion, so the apostrophe
 * reached the browser as U+0027. Rendering them through {{ }} would have moved
 * bytes on the cart, the checkout, the basket reminder and the merchant's order
 * alert, for no reader-visible gain, and one assertion in
 * tests/Feature/CheckoutBrowsedAddTest.php reads the literal apostrophe back.
 *
 * So these seven are escaped for TEXT CONTENT rather than for an attribute:
 * `<`, `>` and `&` are encoded, quotes are left alone, and the page is byte for
 * byte what it was.
 *
 * ── WHERE IT MUST NOT BE USED ───────────────────────────────────────────────
 *
 * Inside an attribute. A value that keeps its quotes cannot safely sit between
 * two of them, and this is not the tool for that — {{ }} is, and every
 * attribute on this storefront uses it. The name says "inline" for that reason:
 * it is for a run of words between two tags.
 */
final class Phrase
{
    /** A translated sentence, safe between two tags, with its quotes intact. */
    public static function inline(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}
