<?php

declare(strict_types=1);

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;

/**
 * Google Cloud Translation, using the OWNER'S OWN key.
 *
 * ── THE CONSENT THIS CLASS EXISTS TO MAKE EXPLICIT ──────────────────────────
 *
 * Pressing Translate sends the shop's product copy to Google. That is the whole
 * transaction and it should be stated in those words, on the screen, before the
 * first press — not buried in a privacy policy. It is a reasonable thing to
 * agree to and an unreasonable thing to do to somebody without asking.
 *
 * The key is the owner's, stored in this shop's own database (encrypted, see
 * TranslationCredentials), billed to his own Google Cloud account. It is not a
 * shared key and there is no key shipped with the application. That matters for
 * a reason beyond tidiness: a shared key would make the developer's account
 * liable for the owner's character volume, and a key in the repository is a key
 * in every zip package and every backup.
 *
 * ── THE MONEY, PLAINLY ──────────────────────────────────────────────────────
 *
 * Google Cloud Translation bills per character of SOURCE text, including
 * spaces and HTML markup, and counts a re-translation as new characters. Basic
 * (v2) list price is USD 20 per million characters, with the first 500,000
 * characters each month free on the Cloud Translation free tier.
 *
 * Nothing here spends anything until TranslationEstimate has shown the owner a
 * character count and a figure. That is not a courtesy: character counts are
 * wildly unintuitive — a product description that reads as a paragraph is
 * 900 characters, and a catalogue of them is a bill.
 *
 * ── format=text, NOT html ───────────────────────────────────────────────────
 *
 * Product descriptions in this shop carry markup. Asked for html, Google
 * translates around the tags and returns them re-encoded, which has to be
 * un-encoded again and which occasionally reorders attributes. Asked for text,
 * it treats the markup as words and mangles it. Neither is acceptable for a
 * description, so the runner sends only PLAIN fields to the machine — names,
 * short descriptions, interface strings — and leaves anything containing a tag
 * for a human. See MachineTranslationRunner::isMachineSafe().
 */
final class GoogleProvider implements TranslationProvider
{
    private const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

    /** Google bills per request as well as per character; 100 is its own limit. */
    public const MAX_PER_REQUEST = 100;

    public function __construct(private readonly ?string $apiKey) {}

    public function name(): string
    {
        return 'Google Cloud Translation';
    }

    public function available(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    public function translate(array $texts, string $from, string $to): array
    {
        if (! $this->available()) {
            throw new \RuntimeException('Google Cloud Translation has no API key saved.');
        }

        if ($texts === []) {
            return [];
        }

        if (count($texts) > self::MAX_PER_REQUEST) {
            throw new \InvalidArgumentException(
                'Batch of '.count($texts).' exceeds the '.self::MAX_PER_REQUEST.' Google accepts in one request.'
            );
        }

        $response = Http::asForm()
            ->timeout(30)
            ->post(self::ENDPOINT.'?key='.urlencode((string) $this->apiKey), [
                'q' => array_values($texts),
                'source' => $from,
                'target' => $to,
                'format' => 'text',
            ]);

        if (! $response->successful()) {
            /*
             * The body is quoted, not the URL. The URL carries the API key as a
             * query parameter, so logging a failed request naively writes the
             * owner's billable credential into storage/logs — the same defect
             * MailLog exists to avoid with the SMTP password.
             */
            throw new \RuntimeException(
                'Google Cloud Translation refused the request ('.$response->status().'): '
                .mb_substr((string) $response->body(), 0, 300)
            );
        }

        $returned = $response->json('data.translations') ?? [];

        $out = [];

        foreach (array_values($texts) as $i => $ignored) {
            $value = $returned[$i]['translatedText'] ?? null;

            // ORDER IS THE CONTRACT. An index Google did not answer becomes
            // null in place rather than collapsing the array, because a shifted
            // batch writes every product's copy onto the next product.
            $out[] = is_string($value) ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
        }

        return $out;
    }
}
