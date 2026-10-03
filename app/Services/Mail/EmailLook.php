<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Services\SettingsService;
use App\Support\Url;

/**
 * The fonts and brand colours every email will be drawn with (Lane RK, E1).
 *
 * The owner: "change the font to our website heading font in all emails
 * templates ... by default... also give control of everything to change".
 *
 * STORED NOW, DRAWN LATER. This package stores the choice and exposes it
 * through EmailBranding::look(); the restyle of every template to Look A is
 * the next package, after he approves its previews, and it reads these values
 * rather than hard-coding its own. No template reads them yet, so applying
 * this package moves no email by a byte.
 *
 * DEFAULTS ARE WHAT HE ASKED FOR AND WHAT THE PREVIEWS SHOW: the shop's own
 * Outfit for headings and body (docs/rj-email-previews, commit 8420728), the
 * brand pink as the accent and the deep pink as the button — the two
 * EmailBranding::PALETTE already carries, so nothing is retyped.
 *
 * Every value is closed: a font is one of FONTS' keys or the default, a colour
 * is #rrggbb or the default. A stored value is re-checked on the way OUT as
 * well, so a row written by anything else still cannot put an arbitrary
 * string into a style attribute.
 */
final class EmailLook
{
    public const HEADING_FONT = 'email_font_heading';

    public const BODY_FONT = 'email_font_body';

    public const ACCENT = 'email_accent';

    public const BUTTON = 'email_button';

    public const BACKGROUND = 'email_background';

    public const TEXT = 'email_text';

    /**
     * The email logo: a picture chosen from the Media Library on Emails →
     * Design & branding. Blank means "the wordmark in text", which shows even
     * when a mail client blocks pictures. EmailBranding::logoUrl() prefers it
     * over Store → Business Details' org_logo.
     */
    public const LOGO = 'email_logo';

    /** key => [label, CSS font stack]. Constants, so a stack is never operator text. */
    public const FONTS = [
        'outfit' => ['Outfit (the shop’s heading font)', "'Outfit',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif"],
        'system' => ['System sans', "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif"],
        'georgia' => ['Georgia serif', "Georgia,'Times New Roman',Times,serif"],
    ];

    public const DEFAULTS = [
        self::HEADING_FONT => 'outfit',
        self::BODY_FONT => 'outfit',
        self::ACCENT => '#e0567b',      // EmailBranding::PALETTE['pink'], as stored (lower case)
        self::BUTTON => '#c13e63',      // EmailBranding::PALETTE['pinkDeep']
        self::BACKGROUND => '#fff8f5',  // EmailBranding::PALETTE['cream']
        self::TEXT => '#2a2228',        // EmailBranding::PALETTE['ink']
        self::LOGO => '',
    ];

    /**
     * The STABLE public address of the font file. The storefront serves Outfit
     * from public/build/assets under a hashed name that changes with every
     * asset build; an email sent today is opened next month, so it needs a
     * path that never moves. Shipped by this package as public/fonts/email/.
     */
    public const FONT_PATH = '/fonts/email/outfit-latin.woff2';

    public function __construct(private SettingsService $settings) {}

    /** @return array{email_font_heading: string, email_font_body: string, email_accent: string, email_button: string} */
    public function values(): array
    {
        $out = [];

        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = self::clean($key, $this->settings->get($key, $default)) ?? $default;
        }

        return $out;
    }

    /**
     * Validate and store. Returns the keys that were refused (and NOT
     * written); everything else in $values is saved. Unknown keys are refused.
     *
     * @return list<string>
     */
    public function save(array $values): array
    {
        $refused = [];

        foreach ($values as $key => $raw) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                $refused[] = (string) $key;

                continue;
            }

            $clean = self::clean($key, $raw);

            if ($clean === null) {
                $refused[] = $key;

                continue;
            }

            $this->settings->set($key, $clean);
        }

        return $refused;
    }

    /** One of a font key or #rrggbb (lower-cased), or null when it is neither. */
    public static function clean(string $key, mixed $raw): ?string
    {
        $value = is_scalar($raw) ? trim((string) $raw) : '';

        if ($key === self::HEADING_FONT || $key === self::BODY_FONT) {
            return array_key_exists($value, self::FONTS) ? $value : null;
        }

        /*
         * A logo is a site-relative path (what the Media Library hands back)
         * or an http(s) URL — scheme-checked before it can become a src
         * (CLAUDE.md rule 5). Blank clears it. `//host` (scheme-relative) and
         * anything with a quote, a bracket or whitespace are refused.
         */
        if ($key === self::LOGO) {
            if ($value === '') {
                return '';
            }

            $ok = (str_starts_with($value, '/') && ! str_starts_with($value, '//'))
                || preg_match('#^https?://#i', $value) === 1;

            return $ok && strlen($value) <= 500 && preg_match('/[\s"\'<>()]/', $value) !== 1 ? $value : null;
        }

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : null;
    }

    /**
     * What a template will read: the stacks, the colours, and the @font-face
     * rule (empty when neither font is Outfit). Gmail ignores web fonts and
     * falls through to the system stack, which is the behaviour wanted.
     */
    public function present(): array
    {
        $v = $this->values();
        $usesOutfit = $v[self::HEADING_FONT] === 'outfit' || $v[self::BODY_FONT] === 'outfit';

        return [
            'headingFont' => self::FONTS[$v[self::HEADING_FONT]][1],
            'bodyFont' => self::FONTS[$v[self::BODY_FONT]][1],
            'accent' => $v[self::ACCENT],
            'button' => $v[self::BUTTON],
            'background' => $v[self::BACKGROUND],
            'text' => $v[self::TEXT],
            'fontFaceCss' => $usesOutfit
                ? "@font-face{font-family:'Outfit';src:url('" . Url::external(self::FONT_PATH)
                    . "') format('woff2');font-weight:100 900;font-style:normal;font-display:swap}"
                : '',
        ];
    }
}
