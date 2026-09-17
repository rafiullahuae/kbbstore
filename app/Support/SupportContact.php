<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;
use Illuminate\Container\Container;

/**
 * How a customer reaches this shop — one answer, read from settings.
 *
 * WHAT THIS REPLACES. The shop's phone number was a LITERAL in three templates
 * and none of them agreed about which setting it came from:
 *
 *   partials/header.blade.php       get('whatsapp',       '+971 58 505 2611')
 *   partials/footer.blade.php       get('brand_whatsapp', '+971585052611')
 *   partials/footer.blade.php       get('support_phone',  '+971 58 505 2611')
 *   partials/mobile-chrome.blade.php get('brand_whatsapp','+971585052611')
 *
 * `brand_whatsapp` is seeded, so its default never fired. `whatsapp` and
 * `support_phone` are seeded by nothing and written by nothing, so THEIR
 * defaults fired on every request of every shop — the owner's real number,
 * printed on every page of the storefront, reachable only by editing Blade.
 *
 * THE NUMBER IS ONE FACT WRITTEN TWO WAYS, and that is why there are two
 * accessors rather than one. Today, untouched, this shop prints
 * `+971 58 505 2611` in the header chip and the footer contact line and
 * `+971585052611` in the mobile menu. Both are the same number. Collapsing them
 * would change what a shop that has never opened the new box displays, so each
 * surface keeps asking for the form it already printed and the two shipped
 * strings live here, once, instead of in four template lines.
 *
 *   phone()     what the chrome PRINTS      support_phone, else whatsapp
 *   whatsapp()  what the chat buttons DIAL  brand_whatsapp, else whatsapp,
 *                                           else support_phone
 *
 * Each chain ends in the shipped constant below, so a shop that has set
 * nothing is byte-for-byte what it was.
 *
 * BLANK IS NOT EMPTY. SettingsService::get() returns its default only when the
 * ROW IS ABSENT, so a row holding '' — which is what clearing the admin box
 * stores — would otherwise print nothing at all where a phone number used to
 * be. Every value here is trimmed and an empty one falls through to the next
 * candidate, which makes clearing a box mean "go back to what the shop shipped
 * with" rather than "put a blank line on every page".
 */
final class SupportContact
{
    /**
     * The number as the header chip and the footer contact line print it.
     *
     * Spaced, because that is the string those two surfaces have always shown
     * and this class exists to keep them showing it.
     */
    public const SHIPPED_PHONE = '+971 58 505 2611';

    /**
     * The number the WhatsApp buttons dial and the mobile menu prints.
     *
     * Unspaced, because that is the value SettingsSeeder writes to
     * `brand_whatsapp` and therefore what those surfaces show today. Kept as a
     * last resort for a shop whose row was never seeded or has been cleared.
     */
    public const SHIPPED_WHATSAPP = '+971585052611';

    /**
     * What the chrome prints beside the phone glyph.
     *
     * LTR-ISOLATED ON AN ARABIC PAGE, and this accessor is the right place for
     * it precisely because of the split this class already draws: phone() is
     * defined as what a surface PRINTS, while whatsappDigits() is what a link
     * DIALS. A dialling string must never carry a formatting character; a
     * printed one must, because `+971 58 505 2611` paints as
     * `971 58 505 2611+` in a right-to-left run — the leading PLUS SIGN is a
     * weak bidi class and is resolved from the run around it, exactly as the
     * sale ribbon's HYPHEN-MINUS is. Found by rendering the Arabic storefront
     * and reading the text nodes back, not by looking for it.
     *
     * Doing it here rather than at the four printing sites also reaches the one
     * of them this lane may not edit: store/home.blade.php passes this value
     * into a translated sentence as `:phone`.
     *
     * whatsapp() is deliberately left alone. Its docblock calls it the DIALLED
     * form, and the mobile menu isolates it at the point where it prints it.
     *
     * No-op in English — see App\Support\Bidi for the measurement.
     */
    public static function phone(): string
    {
        return Bidi::number(self::firstFilled(['support_phone', 'whatsapp']) ?: self::SHIPPED_PHONE);
    }

    /** What a wa.me link dials, and what the mobile menu prints. */
    public static function whatsapp(): string
    {
        return self::firstFilled(['brand_whatsapp', 'whatsapp', 'support_phone'])
            ?: self::SHIPPED_WHATSAPP;
    }

    /**
     * Just the digits, for a wa.me URL.
     *
     * wa.me takes an international number with no punctuation, so every form an
     * owner might type — spaces, brackets, a leading + — reduces to the same
     * link. This is the reason the two accessors above may differ in spacing
     * without the buttons differing at all.
     */
    public static function whatsappDigits(): string
    {
        return (string) preg_replace('/\D+/', '', self::whatsapp());
    }

    /**
     * The shop's support address.
     *
     * NO SHIPPED FALLBACK, deliberately, unlike the two numbers. Its only
     * reader is InvoiceDocument::seller(), which already falls back from
     * `invoice_email` to this and then prints nothing when both are empty — and
     * an address is not a thing to invent a default for: a wrong one is a
     * customer writing to a mailbox nobody reads. The seeded value stands until
     * the owner changes it, and clearing the box takes the line off the
     * invoice, which is what clearing a box on that screen means everywhere
     * else.
     */
    public static function email(): string
    {
        return self::firstFilled(['support_email']);
    }

    /**
     * The first of these settings that holds something, trimmed.
     *
     * @param  list<string>  $keys
     */
    private static function firstFilled(array $keys): string
    {
        $settings = self::settings();

        if ($settings === null) {
            return '';
        }

        foreach ($keys as $key) {
            $value = trim((string) ($settings->get($key, '') ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * The request's own settings service, or null when there is not one.
     *
     * Resolved from the container rather than newed up, so this shares the
     * scoped instance the view composer and every other reader already hold
     * instead of building a second snapshot per call. The guard is the same
     * contract EmailBranding::forMailable() keeps: a phone number is chrome,
     * and chrome that throws must not be why a page 500s — an early console
     * command or a boot before the database exists gets the shipped value.
     */
    private static function settings(): ?SettingsService
    {
        try {
            return Container::getInstance()->make(SettingsService::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
