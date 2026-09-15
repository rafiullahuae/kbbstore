<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Services\HeaderSettings;
use App\Services\SettingsService;
use App\Support\Url;

/**
 * The store's own face, as an order email prints it.
 *
 * The owner asked for two things that both land here: the confirmation should
 * feel "a little bit colourful, joyful/happy about skincare", and it should
 * "focus more on support by showing our WhatsApp, email, Instagram etc, so the
 * user will feel more trust and comfort". A support block is a promise — chat
 * to us here, write to us there — so every value in it is a real business fact
 * read from settings, and NOTHING in this file or in any template is a
 * hardcoded number or handle. A receipt advertising a WhatsApp number nobody
 * answers does the opposite of building trust.
 *
 * WHERE EACH VALUE COMES FROM, and in what order:
 *
 *   WhatsApp   Store → Mail, else `brand_whatsapp` (the footer and the phone
 *              menu already use it), else `whatsapp` (the header block).
 *   Email      Store → Mail, else the From address — derived from APP_URL when
 *              the owner has not typed one, see MailSettings::fromAddress().
 *   Instagram  Store → Mail, else `social_instagram` from Store → Business
 *              Details, which is where the SEO screen already saves it.
 *   Logo       `org_logo`, the one logo image this store keeps — the same file
 *              the schema.org Organization block publishes. A second upload
 *              would mean two logos to keep in step and one of them wrong.
 *   Wordmark   `logo_text` + `logo_accent` from Header settings, so an email
 *              signs itself with the same two words as the site header.
 *
 * A channel with no value anywhere is simply not printed. There is no
 * placeholder and no "coming soon" — an empty row in a support block is worse
 * than a shorter block.
 *
 * NOTHING HERE IS ESCAPED, and that is deliberate and matches
 * OrderEmailPresenter: escaping belongs at the point of output, where Blade's
 * {{ }} does it in the HTML parts, and a pre-escaped string would render as a
 * literal &amp;amp; in the text parts. The one thing this class does sanitise is
 * the logo URL, because a `src` attribute is not a text node — see logoUrl().
 */
class EmailBranding
{
    /**
     * The module switch for the logo, and the reason it is a module.
     *
     * Store → Mail's form has exactly three field types (text, secret, and a
     * choice whose options come from MailApiController::optionsFor — a file this
     * lane does not own). There is no checkbox, so an on/off that lived in
     * MailSettings::SCHEMA would render as a text box the owner had to type
     * "yes" into. Store → Modules already renders real switches, already has an
     * "Order emails" group holding the five email toggles, and is already the
     * screen the owner knows for turning a piece of an email on and off.
     */
    public const LOGO_MODULE = 'email_show_logo';

    /**
     * The palette, taken from the storefront's own :root block in
     * resources/css/kbb/kbb.css rather than invented here.
     *
     * Named so a template never carries a bare hex, and so the next person can
     * see at a glance that these are the site's colours and not a designer's
     * mood. Restrained on purpose: two pinks, a cream and the site's ink. The
     * owner asked for "a little bit colourful", which is not a licence for a
     * rainbow in a document somebody reads to check what they were charged.
     *
     * Every one is a flat colour. Gradients, shadows and rounded corners are
     * dropped by Outlook's Word renderer without a fallback, so nothing here
     * depends on one to stay legible.
     */
    public const PALETTE = [
        'cream' => '#FFF8F5',
        'pinkSoft' => '#FFF0F4',
        'blush' => '#FCE0E8',
        'pink' => '#E0567B',
        'pinkDeep' => '#C13E63',
        'ink' => '#2A2228',
        'ink2' => '#5E545A',
        'muted' => '#8C828A',
        'line' => '#F0E4E9',
        'green' => '#2E9E6B',
        'white' => '#FFFFFF',
    ];

    public function __construct(
        private SettingsService $settings,
        private MailSettings $mail,
        private HeaderSettings $header,
    ) {}

    /**
     * Everything the templates print, decided once.
     *
     * @return array<string, mixed>
     */
    public function present(bool $customerFacing = true): array
    {
        $support = $customerFacing ? $this->support() : [];

        return [
            'customerFacing' => $customerFacing,
            'storeName' => $this->storeName(),
            'wordmark' => $this->wordmark(),
            'logoUrl' => $this->logoUrl(),
            'support' => $support,
            'hasSupport' => $support !== [],
            'signature' => $customerFacing ? $this->signature() : [],
            'colours' => self::PALETTE,
        ];
    }

    /** The store's name, for the wordmark fallback and the signature. */
    public function storeName(): string
    {
        $name = trim((string) ($this->settings->get('store_name', '') ?? ''));

        return $name !== '' ? $name : (string) config('app.name', 'K Beauty Bliss');
    }

    /**
     * The two-part wordmark the site header shows, as [ink half, accent half].
     *
     * Printed when there is no logo image, and beside it when there is not
     * enough room for one. Read from Header settings so the email and the site
     * cannot drift apart.
     *
     * @return array{0:string,1:string}
     */
    public function wordmark(): array
    {
        $text = trim((string) ($this->header->get('logo_text') ?? ''));
        $accent = trim((string) ($this->header->get('logo_accent') ?? ''));

        if ($text === '' && $accent === '') {
            return [$this->storeName(), ''];
        }

        return [$text, $accent];
    }

    /**
     * The store logo, absolute, or null.
     *
     * Null whenever the switch is off or no logo has been uploaded — which is
     * the ordinary case and not an error. A broken image in a receipt reads as a
     * broken store, so nothing is printed rather than an <img> that 404s.
     *
     * ABSOLUTE, ALWAYS. A mail client has no origin to resolve "/img/logo.png"
     * against; a site-relative src arrives as a dead image in every one of them.
     *
     * AND http(s) ONLY. This is operator input arriving in an attribute rather
     * than a text node, so Blade's {{ }} is not the whole answer: it escapes the
     * quotes but would happily print a `javascript:` or `data:` src. Anything
     * that is not a plain http(s) URL or a site path is dropped.
     */
    public function logoUrl(): ?string
    {
        // Literal key: Phase3ModuleSwitchesTest greps the source for exactly
        // this call to prove the registry's `live` status is not a claim.
        if (! $this->settings->moduleEnabled('email_show_logo', true)) {
            return null;
        }

        $raw = trim((string) ($this->settings->get('org_logo', '') ?? ''));

        if ($raw === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $raw) === 1) {
            return $raw;
        }

        // A site path, the shape the media library stores. Anything else — a
        // scheme we did not name, a bare word — is not a logo.
        if (str_starts_with($raw, '/')) {
            return Url::redirect($raw);
        }

        return null;
    }

    /**
     * The support channels, in the order the owner listed them.
     *
     * @return list<array{kind:string,label:string,value:string,url:string}>
     */
    public function support(): array
    {
        $out = [];

        $whatsapp = $this->firstFilled([
            $this->mail->get('mail_support_whatsapp'),
            (string) ($this->settings->get('brand_whatsapp', '') ?? ''),
            (string) ($this->settings->get('whatsapp', '') ?? ''),
        ]);

        if ($whatsapp !== '') {
            $digits = preg_replace('/\D+/', '', $whatsapp) ?? '';

            if ($digits !== '') {
                $out[] = [
                    'kind' => 'whatsapp',
                    'label' => 'WhatsApp',
                    'value' => $whatsapp,
                    'url' => 'https://wa.me/' . $digits,
                ];
            }
        }

        $email = $this->firstFilled([
            $this->mail->get('mail_support_email'),
            $this->mail->fromAddress(),
        ]);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $out[] = [
                'kind' => 'email',
                'label' => 'Email us',
                'value' => $email,
                'url' => 'mailto:' . $email,
            ];
        }

        $instagram = $this->firstFilled([
            $this->mail->get('mail_support_instagram'),
            (string) ($this->settings->get('social_instagram', '') ?? ''),
        ]);

        if ($instagram !== '') {
            $handle = $this->instagramHandle($instagram);

            if ($handle !== '') {
                $out[] = [
                    'kind' => 'instagram',
                    'label' => 'Instagram',
                    'value' => '@' . $handle,
                    'url' => 'https://www.instagram.com/' . $handle . '/',
                ];
            }
        }

        return $out;
    }

    /**
     * The owner's sign-off, as lines.
     *
     * Lines rather than one string, because the HTML part has to turn a break
     * into a <br> and the text part into a newline, and the only safe way to do
     * that is to split here and let each template join with {{ }} around every
     * piece. Handing a template one string containing "<br>" would mean either
     * an unescaped signature — operator input rendered as markup, which is the
     * hole this project has already patched twice — or a literal "&lt;br&gt;".
     *
     * A pipe is the break character because Store → Mail's fields are
     * single-line inputs (MailApiController renders `text` as <input type=text>)
     * and the owner cannot type a newline into one. Real newlines are honoured
     * too, for a value that arrived from anywhere else.
     *
     * @return list<string>
     */
    public function signature(): array
    {
        $raw = trim($this->mail->get('mail_signature'));

        if ($raw === '') {
            return ['With love,', 'the ' . $this->storeName() . ' team'];
        }

        $lines = preg_split('/\s*[|\r\n]+\s*/', $raw) ?: [];

        $lines = array_values(array_filter(
            array_map(static fn ($line) => trim((string) $line), $lines),
            static fn (string $line) => $line !== '',
        ));

        return $lines === [] ? [$this->storeName()] : $lines;
    }

    /** @param list<string> $candidates */
    private function firstFilled(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * "@kbeauty.bliss", "kbeauty.bliss" and
     * "https://www.instagram.com/kbeauty.bliss/" all reduced to the handle.
     *
     * Reduced rather than passed through, so the block can print the handle and
     * link to the profile whichever form the owner typed — and so a stray query
     * string or tracking parameter on a pasted URL does not end up on the page.
     */
    private function instagramHandle(string $value): string
    {
        // A tilde delimiter, not a hash: the character class has to exclude a
        // literal # (the fragment marker on a pasted profile URL), and a # inside
        // a #-delimited pattern ends the pattern.
        if (preg_match('~instagram\.com/([^/?#\s]+)~i', $value, $m) === 1) {
            $value = $m[1];
        }

        $value = ltrim(trim($value), '@');

        return preg_match('/^[A-Za-z0-9._]{1,30}$/', $value) === 1 ? $value : '';
    }
}
