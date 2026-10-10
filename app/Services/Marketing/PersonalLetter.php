<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Services\Mail\MailSettings;

/**
 * "Personal letter style (best chance for the Primary tab)" — Lane EP.
 *
 * The owner, 10 October: "The marketing emails are going to promotion folder,
 * we want to send to inbox to our existing customers."
 *
 * WHAT THIS CAN AND CANNOT DO. Gmail's tabs are chosen per recipient by
 * Google's classifier; no header, word or layout forces Primary, and
 * Promotions is part of the inbox, not spam. Google documents what the
 * READER can do (drag a message to Primary and answer "Do this for future
 * messages"), and that its filters learn from how people treat a sender's
 * mail. So this style makes a campaign look and behave like the mail people
 * keep in Primary — a short letter from a person, that invites a reply —
 * and leaves out what makes a message look like a flyer:
 *
 *   From      "<signer> from <shop>" (the address stays the shop's one From
 *             address: a consistent From is a Google bulk-sender requirement)
 *   body      "Hi {first name}," and paragraphs; at most MAX_LINKS plain links
 *             and MAX_IMAGES small picture; no product grid, price card,
 *             coupon box, button, chip or banner — those blocks are left out
 *   text      the same words as the HTML, as a real plain-text letter
 *   reply     an invitation to reply, and a Reply-To that reaches a person
 *   footer    why it arrived, the postal address and an "Unsubscribe" link,
 *             as small plain text
 *   pixel     none unless the campaign asks (letter_opens)
 *   links     straight to the shop with the UTM tags, not through the click
 *             redirect — see CampaignSender::sendOne() for what that costs
 *
 * Everything printed from here is a constant or escaped by the view.
 */
final class PersonalLetter
{
    public const THEME = 'letter';

    /** Links in the body. The footer's unsubscribe link is on top of these. */
    public const MAX_LINKS = 2;

    public const MAX_IMAGES = 1;

    /** A small picture, not a banner: printed at most this wide. */
    public const IMAGE_WIDTH = 280;

    public const SIGNER_MAX = 60;

    /** The blocks a letter prints. Everything else is left out. */
    public const KEEPS = ['heading', 'text', 'button', 'image', 'hero_image', 'footer'];

    /** Gmail's own link colour: a letter's link looks like a link someone typed. */
    public const LINK = '#1155CC';

    public const WORDS = [
        'en' => [
            'hi' => 'Hi :name,',
            'hi_blank' => 'Hi there,',
            'reply' => 'If you have a question, about your skin or about an order, just reply to this email. It comes straight to me.',
            'reply_team' => 'If you have a question, about your skin or about an order, just reply to this email. It comes straight to us.',
            'sign' => 'Warmly,',
            'team' => 'The :store team',
            'unsub_lead' => 'Prefer not to get these letters?',
            'unsub' => 'Unsubscribe',
        ],
        'ar' => [
            'hi' => 'مرحبًا :name،',
            'hi_blank' => 'مرحبًا،',
            'reply' => 'إذا كان لديكِ أي سؤال عن بشرتكِ أو عن طلبكِ، ما عليكِ سوى الرد على هذه الرسالة، فهي تصلني مباشرة.',
            'reply_team' => 'إذا كان لديكِ أي سؤال عن بشرتكِ أو عن طلبكِ، ما عليكِ سوى الرد على هذه الرسالة، فهي تصلنا مباشرة.',
            'sign' => 'مع خالص المودّة،',
            'team' => 'فريق :store',
            'unsub_lead' => 'لا ترغبين في هذه الرسائل؟',
            'unsub' => 'إلغاء الاشتراك',
        ],
    ];

    public static function is(mixed $theme): bool
    {
        return $theme === self::THEME;
    }

    /** @return array<string, string> */
    public static function words(string $locale): array
    {
        return self::WORDS[$locale] ?? self::WORDS['en'];
    }

    public static function word(string $locale, string $key, array $vars = []): string
    {
        $s = self::words($locale)[$key] ?? self::WORDS['en'][$key] ?? '';

        foreach ($vars as $k => $v) {
            $s = str_replace(':' . $k, (string) $v, $s);
        }

        return $s;
    }

    /** One line, no markup characters, at most SIGNER_MAX: what the From name and the signature print. */
    public static function signer(mixed $v): string
    {
        $s = is_string($v) ? (string) preg_replace('/[\x00-\x1F\x7F<>"]+/u', ' ', $v) : '';

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $s)), 0, self::SIGNER_MAX);
    }

    /**
     * The From NAME of a letter: "Rafi from K-Beauty Bliss". Null keeps the
     * campaign's own From name (no signer given, or not a letter).
     */
    public static function fromName(object $c, string $store): ?string
    {
        if (! self::is($c->theme ?? null)) {
            return null;
        }

        $signer = self::signer($c->letter_signer ?? '');

        if ($signer === '') {
            return null;
        }

        $store = trim($store);

        return mb_substr($store !== '' ? $signer . ' from ' . $store : $signer, 0, 120);
    }

    /** Does this letter carry the open pixel? Off unless the campaign asked. */
    public static function tracksOpens(object $c): bool
    {
        return ! self::is($c->theme ?? null) || ! empty($c->letter_opens);
    }

    /**
     * The Reply-To a CAMPAIGN sets itself, or null.
     *
     * A reply is the strongest thing a customer can do for the shop's
     * standing in their Gmail, so it must reach a person. Store → Mail's
     * "Reply-To address" already applies to every message the mailer sends
     * (MailConfigurator puts it in mail.reply_to), so when it is set this
     * returns null: Laravel ADDS a Mailable's Reply-To to the global one, and
     * setting it twice would print the address twice. When it is blank, the
     * campaign names the mailbox the shop's own footer already calls its
     * support address — "Support email address", then the From address the
     * owner TYPED, then the Google Workspace account it signs in as — and
     * never a no-reply@ derived from the site's domain, which nobody reads.
     */
    public static function replyTo(): ?string
    {
        try {
            $mail = app(MailSettings::class);

            if ($mail->replyToAddress() !== '') {
                return null;
            }

            foreach ([$mail->get('mail_support_email'), $mail->get('mail_from_address'), $mail->fromAddress()] as $candidate) {
                $a = trim((string) $candidate);

                if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) !== false && preg_match('/^no-?reply@/i', $a) !== 1) {
                    return $a;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** Where a reply to a campaign lands, whichever way it is set, or '' when nowhere a person reads. */
    public static function replyLandsAt(): string
    {
        try {
            $global = app(MailSettings::class)->replyToAddress();
        } catch (\Throwable) {
            $global = '';
        }

        return $global !== '' ? $global : (string) (self::replyTo() ?? '');
    }

    /**
     * The labels of the blocks a letter leaves out, in order and once each,
     * for the builder's note ("Left out in letter style: Product grid, …").
     *
     * @param  list<array{type:string, props:array<string,mixed>}>  $blocks
     * @return list<string>
     */
    public static function leftOut(array $blocks): array
    {
        $out = [];

        foreach ($blocks as $b) {
            if (! in_array($b['type'], self::KEEPS, true)) {
                $out[$b['type']] = Blocks::LABELS[$b['type']] ?? $b['type'];
            }
        }

        return array_values($out);
    }
}
