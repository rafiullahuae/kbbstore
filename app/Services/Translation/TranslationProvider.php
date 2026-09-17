<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * A machine-translation service, whichever one the owner ends up paying.
 *
 * ── WHY THIS IS AN INTERFACE AND NOT A GOOGLE CLIENT ────────────────────────
 *
 * The owner asked for "translate from Google". He does not actually care which
 * company does it — he cares that he does not have to type 671 product
 * descriptions from scratch. Google Cloud Translation, DeepL and Azure
 * Translator all do this, they differ in price by about 2.5x and in Arabic
 * quality by more than that, and which is best will not be the same next year.
 *
 * Binding the feature to one vendor would also make it untestable: a test that
 * needs an API key is a test that does not run in CI, and a test that calls an
 * API costs money every time the suite runs. NullProvider is the default
 * binding, so the suite never reaches the network and the manual path — the one
 * that must be free to operate — works with nothing configured at all.
 */
interface TranslationProvider
{
    /** For the admin screen: 'Google Cloud Translation'. */
    public function name(): string;

    /**
     * Can this actually run right now — is there a key?
     *
     * The screen asks this to decide whether to OFFER machine translation, and
     * the runner asks it again before spending anything. It must never throw:
     * it is called on a screen the owner opens to find out why the button is
     * greyed out.
     */
    public function available(): bool;

    /**
     * Translate a batch.
     *
     * A batch and not a string, because every one of these vendors bills a
     * request as well as a character and because 671 products one at a time is
     * 671 round trips over a shared host's outbound connection.
     *
     * Returns translations in the SAME ORDER as $texts. An implementation that
     * cannot translate an entry returns null in its place rather than dropping
     * it — a shifted array would silently write every product's description
     * onto the wrong product.
     *
     * @param  list<string>  $texts
     * @return list<string|null>
     */
    public function translate(array $texts, string $from, string $to): array;
}
