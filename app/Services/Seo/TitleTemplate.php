<?php

declare(strict_types=1);

namespace App\Services\Seo;

/**
 * Resolves the placeholder tokens the SEO screens hand out, and cleans up
 * after the ones that resolve to nothing.
 *
 * Two token syntaxes are in play, because two screens write them:
 *
 *   - the sitewide title template field offers {title} {sep} {sitename};
 *   - the per-product SEO panel inserts Yoast-style %%title%% %%sitename%%
 *     %%sep%% %%page%% chips, and the product's stored seo.title reaches the
 *     storefront verbatim -- so those have to resolve here too, or a product
 *     that used a chip ships "%%sitename%%" in its <title>.
 *
 * Anything left over is removed rather than printed. A misspelled {sitname}
 * used to reach the browser tab and the search result as literal braces;
 * dropping it then leaves the separator it was attached to dangling, so the
 * separator is tidied afterwards -- no leading, trailing or doubled separator
 * survives.
 */
final class TitleTemplate
{
    /**
     * A token that is deliberately shaped like a token: an identifier in
     * braces. "{50ml}" in a product name is not one and is left alone.
     */
    private const TOKEN = '/\{[A-Za-z][A-Za-z0-9_\-]*\}|%%[A-Za-z][A-Za-z0-9_\-]*%%/';

    /**
     * @param  array<string,string>  $tokens  bare names, e.g. ['title' => 'Cream', 'sep' => '|']
     */
    public static function render(string $template, array $tokens, string $separator = ''): string
    {
        $replacements = [];

        foreach ($tokens as $name => $value) {
            $name = strtolower(trim((string) $name, '{}%'));

            if ($name === '') {
                continue;
            }

            $value = (string) $value;

            // Both cases, because the admin's chips and a hand-typed template
            // do not agree on case and neither should have to.
            $replacements['{' . $name . '}'] = $value;
            $replacements['{' . strtoupper($name) . '}'] = $value;
            $replacements['%%' . $name . '%%'] = $value;
            $replacements['%%' . strtoupper($name) . '%%'] = $value;
        }

        $out = strtr($template, $replacements);

        // Any token that is still standing is one nothing knows about.
        $out = (string) preg_replace(self::TOKEN, '', $out);

        return self::tidy($out, $separator);
    }

    /**
     * Collapse whitespace and drop separators that no longer sit between two
     * pieces of text.
     */
    public static function tidy(string $text, string $separator = ''): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        $separator = trim($separator);

        if ($separator !== '' && $text !== '') {
            $q = preg_quote($separator, '/');

            // "Cream | | Site" -> "Cream | Site"
            $text = (string) preg_replace('/(?:' . $q . '\s*){2,}/u', $separator . ' ', $text);
            // "| Site" -> "Site", "Cream |" -> "Cream"
            $text = (string) preg_replace('/^(?:\s*' . $q . '\s*)+/u', '', $text);
            $text = (string) preg_replace('/(?:\s*' . $q . '\s*)+$/u', '', $text);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
