<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * The provider a shop with no API key has, which is every shop by default.
 *
 * This is the binding in the container until somebody saves a key, and it is
 * what the test suite gets. available() is false, so the admin screen shows the
 * Translate buttons disabled with a sentence explaining what is missing rather
 * than a button that fails when pressed.
 *
 * translate() throws rather than returning nulls, because a caller that reached
 * it without asking available() first has a bug, and a silent batch of nulls
 * would look exactly like a batch of untranslatable strings.
 */
final class NullProvider implements TranslationProvider
{
    public function name(): string
    {
        return 'None configured';
    }

    public function available(): bool
    {
        return false;
    }

    public function translate(array $texts, string $from, string $to): array
    {
        throw new \RuntimeException(
            'No machine-translation provider is configured. Save an API key in Translation → Language settings, '
            .'or type the translation in by hand — the manual path needs no key.'
        );
    }
}
