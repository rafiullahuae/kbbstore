<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use App\Support\Locale;
use Illuminate\Support\Facades\Log;

/**
 * An Arabic order email that reads right to left in every mail client — Lane AEM.
 *
 * The owner, on a real Arabic confirmation (order 56262) as it arrived: "in the
 * arabic emails to customers, use our arabic logo, and also make it RTL
 * properly." What he received: the English "K-BeautyBliss" masthead; the words
 * right-aligned but laid out left to right ("Halima ،شكرًا لك", the period of
 * ".لديك سؤال؟" on the wrong side); the tracker starting on the LEFT; the order
 * chip, the totals and the contact row in English column order.
 *
 * WHY IT WAS HALF RTL. The only direction an email carried was `<html dir>` in
 * emails/kit/doc.blade.php. Gmail (web, iOS, Android) and Outlook.com drop the
 * <html>, <head> and <body> of a message, so that one attribute never reached
 * the reader; the right alignment came from the client guessing from the
 * script, which aligns text and moves nothing else.
 *
 * WHAT THIS DOES, AND ONLY FOR AN ARABIC TRANSACTIONAL EMAIL:
 *   - every `<table role="presentation">` of the card carries dir="rtl", so its
 *     cells run right to left in each client on its own (Word renders a table
 *     with dir="rtl" as a right-to-left table; it does not reliably inherit one
 *     from an outer table) -- the tracker starts on the right, the chip reads
 *     الطلب / تاريخ الطلب / الإجمالي from the right, the total sits on the
 *     right and its amount on the left;
 *   - left and right swap in every tag's align="" and inline style (padding,
 *     margin, border, text-align, float), so nothing hugs the wrong edge;
 *   - the Arabic logo -- the lotus and كي بيوتي بليس exactly as /ar's header
 *     draws them (HeaderSettings::lockup(), on the name the owner approved) --
 *     as a small PNG, because no mail client can be relied on to load the
 *     site's Arabic face or an inline SVG. Width, height and alt are set, so
 *     with images blocked the reader still sees the name, in Arabic.
 *
 * AND NOTHING ELSE. An English email never reaches any of it: every entry point
 * returns its input, or the same escaped text `{{ }}` would have printed, when
 * the email is not right-to-left -- PrintedEnglishUnchangedTest and the
 * byte-for-byte comparison in ArabicOrderEmailsTest hold that.
 *
 * NEVER THE REASON A MAIL DOES NOT GO. mirror() and logo() catch their own
 * failures, log them at ERROR and hand back what the email was before this
 * class existed; OrderMailer additionally sends in English if an Arabic render
 * throws at all.
 */
final class KitRtl
{
    /** The PNG, 400 x 96 (shown at 200 x 48: sharp on a 2x screen), 6.9 KB. */
    public const SOURCE = 'mail/kbb-logo-ar.png';

    /**
     * The first 8 hex of the PNG's sha1, in its public file name, so a redrawn
     * logo is a new address rather than a stale cached copy. A test holds it to
     * the file.
     */
    public const VERSION = '5ce2b301';

    public const WIDTH = 200;

    public const HEIGHT = 48;

    /** HeaderSettings::lockup()'s Arabic name for the shipped wordmark. */
    public const NAME = ['كي بيوتي', 'بليس'];

    /** The wordmark the header ships with; a name the owner typed is his in every language. */
    public const SHIPPED = ['K-Beauty', 'Bliss'];

    /**
     * Is this email right to left? The LANGUAGE's direction, not the
     * storefront's "mirrored layout" switch: that switch exists because the
     * shop's stylesheet had to be finished before /ar could be mirrored, and
     * an email has no stylesheet to wait for.
     */
    public static function rtl(): bool
    {
        return (Locale::LOCALES[Locale::current()]['dir'] ?? 'ltr') === 'rtl';
    }

    /**
     * The Arabic masthead, or null to keep the email's own.
     *
     * @param  array{0:string,1:string}  $wordmark
     * @return array{logo: array{0:string,1:int,2:int}|null, alt: string, wordmark: array{0:string,1:string}}|null
     */
    public static function masthead(array $wordmark): ?array
    {
        if (! self::rtl() || array_values($wordmark) !== self::SHIPPED) {
            return null;
        }

        return [
            /*
             * TEXT, NOT THE PICTURE (the owner, 23 October 2026: "the arabic
             * logo has white background i need the original arabic logo there
             * as text which we have it, don't need any background"). The PNG
             * carries its own solid #FFF0F4 behind the lettering, so wherever
             * the masthead is not exactly that colour -- dark mode, a client
             * that tints the cell -- it showed as a box. كي بيوتي بليس in the
             * kit's own two-tone wordmark has no background at all and is the
             * same text the footer already prints. logo() stays for anything
             * that asks for the file.
             */
            'logo' => null,
            'alt' => self::NAME[0] . ' ' . self::NAME[1],
            // The space travels with the accent half: the two halves are
            // printed side by side, and two Arabic words with nothing between
            // them join into one.
            'wordmark' => [self::NAME[0], ' ' . self::NAME[1]],
        ];
    }

    /**
     * What the kit's masthead and footer print: the Arabic logo and name on an
     * Arabic transactional email, the email's own otherwise -- the same values
     * MailKit::for() always gave, so an English email prints the same bytes.
     *
     * $template is the email's $kitTemplate. A marketing campaign has none and
     * keeps its own masthead: campaigns are designed, previewed and approved in
     * their own editor, which this lane does not touch.
     *
     * @param  array<string, mixed>  $k
     * @return array{logo: array{0:string,1:int,2:int|null}|null, alt: string, wordmark: array{0:string,1:string}}
     */
    public static function brand(array $k, ?string $template): array
    {
        $mast = $template !== null ? ($k['mast'] ?? null) : null;

        if (is_array($mast)) {
            return $mast;
        }

        return ['logo' => $k['logo'] ?? null, 'alt' => (string) ($k['storeName'] ?? ''), 'wordmark' => $k['wordmark'] ?? ['', '']];
    }

    /**
     * The logo's absolute address, copied into the web root once.
     *
     * Into img-cache/mail/, beside the kit's placeholder pictures and for their
     * reason: the web root is a different directory from the application on
     * this host (CLAUDE.md, usePublicPath), so a file shipped in a package does
     * not land where the web server can see it. One is_file() per email once it
     * is there; the web server serves it without PHP.
     *
     * @return array{0:string,1:int,2:int}|null
     */
    public static function logo(): ?array
    {
        try {
            $origin = MailImage::origin();

            if ($origin === '') {
                return null;
            }

            $rel = 'img-cache/mail/kbb-logo-ar-' . self::VERSION . '.png';
            $dest = public_path($rel);

            if (! is_file($dest)) {
                $dir = \dirname($dest);

                if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                    throw new \RuntimeException('cannot create ' . $dir);
                }

                // Written beside and renamed, so the web server never serves half a file.
                $tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.part';

                if (! @copy(resource_path(self::SOURCE), $tmp) || ! @rename($tmp, $dest)) {
                    @unlink($tmp);

                    throw new \RuntimeException('cannot write ' . $rel);
                }

                @chmod($dest, 0644);
            }

            return [$origin . '/' . $rel, self::WIDTH, self::HEIGHT];
        } catch (\Throwable $e) {
            // The Arabic name in text instead: the masthead is never empty.
            Log::error('arabic email logo unavailable; the Arabic wordmark is printed as text', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Text that must read left to right inside an Arabic email -- a phone
     * number, an email address, an @handle -- escaped, in its own isolate.
     * English: exactly what {{ }} prints.
     */
    public static function ltr(mixed $text): string
    {
        return self::rtl() ? '<span dir="ltr">' . e($text) . '</span>' : e($text);
    }

    /**
     * An order number in the chip: left to right and in one piece. On a 320px
     * phone the Arabic chip's longer labels squeezed "KBB-56262" until it broke
     * at the hyphen into "KBB-" over "56262". English: exactly what {{ }} prints.
     */
    public static function number(mixed $text): string
    {
        return self::rtl() ? '<span dir="ltr" style="white-space:nowrap">' . e($text) . '</span>' : e($text);
    }

    /**
     * Text the customer or the catalogue typed, in either script -- a name, an
     * address line -- escaped, laid out by its own first strong letter. A line
     * with no letters at all (a phone number) reads left to right.
     */
    public static function auto(mixed $text): string
    {
        return self::rtl() ? '<span dir="auto">' . e($text) . '</span>' : e($text);
    }

    /**
     * Mirror a rendered card: dir="rtl" on each layout table, left and right
     * swapped in every tag. Returns its input on any failure, logged at ERROR.
     */
    public static function mirror(string $html): string
    {
        try {
            $out = preg_replace_callback('/<[a-zA-Z][^>]*>/', static function (array $m): string {
                $tag = $m[0];

                if (str_starts_with($tag, '<table role="presentation"') && ! str_contains($tag, ' dir="')) {
                    $tag = '<table role="presentation" dir="rtl"' . substr($tag, strlen('<table role="presentation"'));
                }

                $tag = preg_replace_callback('/\balign="(left|right)"/', static fn (array $a): string => 'align="' . ($a[1] === 'left' ? 'right' : 'left') . '"', $tag) ?? $tag;

                return preg_replace_callback('/\bstyle="([^"]*)"/', static fn (array $s): string => 'style="' . self::mirrorStyle($s[1]) . '"', $tag) ?? $tag;
            }, $html);

            if (! is_string($out)) {
                throw new \RuntimeException('preg error ' . preg_last_error());
            }

            return $out;
        } catch (\Throwable $e) {
            Log::error('arabic email mirroring failed; sent without it', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);

            return $html;
        }
    }

    private static function mirrorStyle(string $style): string
    {
        // Four-value padding/margin: top right bottom left -> top left bottom right.
        $style = preg_replace(
            '/(^|;)(\s*(?:padding|margin)\s*:\s*)([^\s;!]+)\s+([^\s;!]+)\s+([^\s;!]+)\s+([^\s;!]+)/',
            '$1$2$3 $6 $5 $4',
            $style,
        ) ?? $style;

        // Arabic is joined-up writing: tracking pulls its letters apart, which
        // is why /ar sets letter-spacing to 0 on the same eyebrows and names.
        $style = preg_replace('/letter-spacing\s*:\s*[^;]+/', 'letter-spacing:0', $style) ?? $style;

        return strtr($style, [
            'padding-left' => 'padding-right', 'padding-right' => 'padding-left',
            'margin-left' => 'margin-right', 'margin-right' => 'margin-left',
            'border-left' => 'border-right', 'border-right' => 'border-left',
            // One-sided corners (the free gifts box's top corners, Lane FGE).
            'border-top-left-radius' => 'border-top-right-radius', 'border-top-right-radius' => 'border-top-left-radius',
            'border-bottom-left-radius' => 'border-bottom-right-radius', 'border-bottom-right-radius' => 'border-bottom-left-radius',
            'text-align:left' => 'text-align:right', 'text-align:right' => 'text-align:left',
            'float:left' => 'float:right', 'float:right' => 'float:left',
        ]);
    }
}
