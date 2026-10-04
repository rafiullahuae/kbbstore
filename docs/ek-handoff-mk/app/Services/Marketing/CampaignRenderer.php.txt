<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\Campaign;
use App\Services\Mail\EmailBranding;
use App\Services\Mail\Kit\KitBlocks;
use App\Services\Mail\Kit\MailKit;
use App\Services\Mail\Kit\WebCopy;
use App\Support\Url;
use Illuminate\Support\HtmlString;

/**
 * A campaign as HTML — Lane EK.
 *
 * The page around the blocks is the approved look A exactly as every customer
 * email has it (emails/campaign.blade.php extends emails/kit/simple): the top
 * line, the header, the blocks, then the footer WITH Unsubscribe · Email
 * preferences and the why-you-got-this line — "The footer (addresses,
 * WhatsApp, email, Unsubscribe) is in every campaign and cannot be removed"
 * (the approved builder mock). The blocks are KitBlocks: kit partials, escaped
 * text, scheme-checked URLs.
 *
 * FOR SENDING it renders ONCE per batch with two sentinels in it —
 * %%KBBTOKEN%% where a recipient's token goes (unsubscribe, pixel, clicks) and
 * %%KBBFIRST%% where {first_name} was typed — and personalise() fills them for
 * each recipient. A thousand recipients is one render and a thousand
 * str_replace()s, not a thousand catalogue reads.
 */
final class CampaignRenderer
{
    public const TOKEN = '%%KBBTOKEN%%';
    public const FIRST = '%%KBBFIRST%%';

    /**
     * The builder's and Review & send's preview: the recipient is a sample
     * ("Aisha"), nothing is tracked, the selected block is outlined.
     */
    public function preview(Campaign $campaign, ?int $selected = null, string $firstName = 'Aisha', array $ctx = []): string
    {
        return $this->html($campaign, ['first_name' => $firstName, 'store' => $this->store()], Url::external('/m/u/preview'), $selected, $ctx);
    }

    /**
     * The batch template: [html with sentinels and tracked links, text, links].
     * $links, when given, is the campaign's fixed list; a URL not in it is left
     * as it is (not tracked) rather than added to a list other processes read.
     *
     * @return array{html:string, text:string, links:list<array{url:string,label:string}>}
     */
    public function forSending(Campaign $campaign, ?array $links = null, array $ctx = []): array
    {
        $html = $this->html($campaign, ['first_name' => self::FIRST, 'store' => $this->store()], Url::external('/m/u/' . self::TOKEN), null, $ctx);

        // The "View in browser" copy: everyone's, so nobody's — no name, no
        // token, untracked links, an unsubscribe link that unsubscribes no one.
        WebCopy::capture(str_replace([' ' . self::FIRST, self::FIRST, self::TOKEN], ['', '', 'view'], $html));

        $found = $this->links($html);
        $links ??= $found;
        $index = [];

        foreach ($links as $i => $link) {
            $index[$link['url']] = $i;
        }

        $html = (string) preg_replace_callback('/href="([^"]*)"/', function (array $m) use ($index, $campaign): string {
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);

            if (! isset($index[$url])) {
                return $m[0];
            }

            $i = $index[$url];

            return 'href="' . e(Url::external('/m/c/' . self::TOKEN . '/' . $i . '/' . CampaignTracking::linkSignature((int) $campaign->id, $i, $url))) . '"';
        }, $html);

        $pixel = '<img src="' . e(Url::external('/m/o/' . self::TOKEN)) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';
        $html = str_contains($html, '</body>') ? str_replace('</body>', $pixel . '</body>', $html) : $html . $pixel;

        return ['html' => $html, 'text' => $this->text($campaign), 'links' => $links];
    }

    /** One recipient's copy of a batch template. */
    public static function personalise(string $template, string $token, string $firstName): string
    {
        $first = e(trim($firstName));

        if ($first === '') {
            // "Hi {first_name}," with no name reads "Hi,".
            $template = str_replace([' ' . self::FIRST, self::FIRST], '', $template);
        }

        return str_replace([self::TOKEN, self::FIRST], [$token, $first], $template);
    }

    public static function subject(Campaign $campaign, string $firstName): string
    {
        return trim(KitBlocks::fill((string) $campaign->subject, ['first_name' => trim($firstName), 'store' => (new self)->store()]));
    }

    /* ------------------------------------------------------------- internals */

    private function html(Campaign $campaign, array $vars, string $unsubscribe, ?int $selected, array $ctx): string
    {
        $brand = EmailBranding::forMailable(true, 'CampaignMail');
        $k = MailKit::for($brand);
        $ctx['vars'] = $vars;
        $blocks = KitBlocks::clean((array) $campaign->blocks);
        $html = '';

        foreach ($blocks as $i => $block) {
            $one = (string) KitBlocks::render([$block], $k, $ctx);

            if ($selected === $i) {
                $one = $this->outline($one, KitBlocks::TYPES[$block['type']] . ($block['type'] === 'products' && $block['fill'] !== 'picked' ? ' · auto-filled' : ''));
            }

            $html .= $one;
        }

        return (string) view('emails.campaign', [
            'brand' => $brand,
            'kitBlocksHtml' => new HtmlString($html),
            'kitTitle' => KitBlocks::fill((string) $campaign->subject, $vars),
            'kitPreheader' => KitBlocks::fill((string) $campaign->preheader, $vars),
            'kitUnsubscribe' => $unsubscribe,
            'kitAudience' => (string) $campaign->audience,
            'storeName' => $vars['store'],
        ])->render();
    }

    /** The builder's selection: a blue frame and a label, drawn in the preview only. */
    private function outline(string $rows, string $label): string
    {
        return '<tr><td style="padding:14px 18px 0;"><span style="display:inline-block;background:#3f6fe0;color:#ffffff;font:700 11px/1 -apple-system,Segoe UI,Roboto,sans-serif;padding:5px 8px;border-radius:7px 7px 0 0;">' . e($label) . '</span>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:2px solid #3f6fe0;border-radius:0 12px 12px 12px;">' . $rows . '<tr><td style="height:14px;font-size:0;line-height:14px;">&nbsp;</td></tr></table></td></tr>';
    }

    /**
     * Every http(s) link in the email that is the owner's content — not the
     * footer's own (unsubscribe, preferences, policies, view in browser), not
     * the header's navigation.
     *
     * @return list<array{url:string,label:string}>
     */
    private function links(string $html): array
    {
        preg_match_all('#<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>#s', $html, $m, PREG_SET_ORDER);
        $skip = ['/m/u/', '/mail/view/', '/terms-and-conditions/', '/privacy-policy/'];
        $out = [];
        $seen = [];

        foreach ($m as [, $href, $inner]) {
            $url = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);

            if (preg_match('#^https?://#i', $url) !== 1 || isset($seen[$url])) {
                continue;
            }

            foreach ($skip as $s) {
                if (str_contains($url, $s)) {
                    continue 2;
                }
            }

            $label = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5));

            if ($label === '' && preg_match('/alt="([^"]*)"/', $inner, $alt) === 1) {
                $label = html_entity_decode($alt[1], ENT_QUOTES | ENT_HTML5);
            }

            $seen[$url] = true;
            $out[] = ['url' => $url, 'label' => mb_substr($label !== '' ? $label : $url, 0, 120)];
        }

        return $out;
    }

    /** The plain-text part: what the blocks say, the links, and how to leave. */
    private function text(Campaign $campaign): string
    {
        $lines = [];

        foreach (KitBlocks::clean((array) $campaign->blocks) as $b) {
            $line = match ($b['type']) {
                'heading' => trim($b['title'] . "\n" . $b['lead']),
                'text' => $b['text'],
                'button' => ($u = MailKit::url($b['url'])) !== null ? $b['label'] . ': ' . $u : '',
                'coupon' => $b['code'] !== '' ? trim($b['code'] . ' — ' . $b['line'] . ' ' . $b['expires']) : '',
                default => '',
            };

            if (trim($line) !== '') {
                $lines[] = KitBlocks::fill($line, ['first_name' => self::FIRST, 'store' => $this->store()]);
            }
        }

        $lines[] = '';
        $lines[] = __('email.campaign.unsubscribe_text') . ' ' . Url::external('/m/u/' . self::TOKEN);

        return implode("\n\n", $lines);
    }

    private function store(): string
    {
        try {
            return app(EmailBranding::class)->storeName();
        } catch (\Throwable) {
            return (string) config('app.name', 'K Beauty Bliss');
        }
    }
}
