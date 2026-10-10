<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Mail\CampaignMail;
use App\Models\Post;
use App\Services\Mail\EmailBranding;
use App\Services\Mail\Kit\MailKit;
use App\Support\Url;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Blocks → the email (HTML and its text part) — Lane MK.
 *
 * THREE STAGES, so the expensive part happens once per campaign and the
 * per-message part is a template fill:
 *
 *   materialize()  resolve what fills itself: each product block's ids (the
 *                  catalogue right now, or this group's top brand), and the
 *                  latest journal posts for a columns block. Run for every
 *                  preview, and ONCE when a campaign's send starts — its
 *                  output is the frozen blocks_snapshot.
 *   data()         the cards and the coupon rows those blocks point at: ONE
 *                  products query and ONE coupons query, however many blocks.
 *                  Run per preview, and once per sending step (25 messages).
 *                  Anything sold out, unpublished, deleted or expired since
 *                  is simply absent, and its block shrinks or drops.
 *   render()       one message: the Blade view, the text part, the size.
 *
 * LINKS. Every href a block prints goes through $ctx['href'], which maps a
 * checked address to what is printed — the address itself in a preview, the
 * click tracker /email/c/{token}/{n} when sending (CampaignSender), or a
 * recorder when the send starts (links()). The footer's own links —
 * unsubscribe, the policy pages, "View this email in your browser" — are
 * never tracked: an unsubscribe link that bounced through a redirect is one
 * more thing that can fail.
 */
final class CampaignRenderer
{
    /** The builder refuses to send above this (plan §8.6: Gmail clips at 102 KB). */
    public const MAX_BYTES = 95 * 1024;

    public const CLIP_BYTES = 102 * 1024;

    /* -------------------------------------------------------- materialize */

    /**
     * @param  list<array{type:string, props:array<string,mixed>}>  $blocks  Blocks::clean() output
     * @return list<array{type:string, props:array<string,mixed>}>
     */
    public function materialize(array $blocks, ?int $topBrandId = null): array
    {
        $posts = null;

        foreach ($blocks as $i => $b) {
            if (in_array($b['type'], ['product_row', 'product_grid'], true)) {
                $blocks[$i]['props']['_ids'] = ProductFill::ids($b['props'], $topBrandId);
            }

            if ($b['type'] === 'columns' && ($b['props']['source'] ?? 'manual') === 'latest_posts') {
                $posts ??= $this->latestPosts(3);
                $n = (int) ($b['props']['count'] ?? 2);
                $blocks[$i]['props']['items'] = array_slice($posts, 0, $n);
                $blocks[$i]['props']['source'] = 'manual';
                $blocks[$i]['props']['_posts'] = true;
            }
        }

        return $blocks;
    }

    /**
     * @return list<array{image:string, title:string, text:string, href:string}>
     */
    private function latestPosts(int $n): array
    {
        try {
            $rows = Post::query()->where('status', 'published')
                ->latest('published_at')->orderByDesc('id')->limit($n)
                ->get(['id', 'slug', 'title', 'excerpt', 'cover']);
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(fn ($p) => [
            'image' => (string) (MailKit::image($p->cover, 400) ?? ''),
            'title' => mb_substr(trim((string) $p->title), 0, 80),
            'text' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $p->excerpt))), 0, 140),
            'href' => '/' . $p->slug . '/',
        ])->all();
    }

    /**
     * The rows every message of a step shares: ONE products query and ONE
     * coupons query for all the blocks.
     *
     * @return array{cards: array<int, array<string, mixed>>, coupons: array<int, object>}
     */
    public function data(array $blocks): array
    {
        $ids = [];
        $couponIds = [];

        foreach ($blocks as $b) {
            foreach ((array) ($b['props']['_ids'] ?? []) as $id) {
                $ids[] = (int) $id;
            }

            if ($b['type'] === 'coupon' && ! empty($b['props']['coupon_id'])) {
                $couponIds[] = (int) $b['props']['coupon_id'];
            }
        }

        $coupons = [];

        if ($couponIds !== []) {
            foreach (DB::table('coupons')->whereIn('id', array_unique($couponIds))->get() as $c) {
                if (self::couponLive($c)) {
                    $coupons[(int) $c->id] = $c;
                }
            }
        }

        return ['cards' => ProductFill::cards($ids), 'coupons' => $coupons];
    }

    /** Started, not expired, not used up — CouponService::validate()'s first three checks. */
    public static function couponLive(object $c): bool
    {
        $now = now();

        if ($c->starts_at !== null && $now->lt($c->starts_at)) {
            return false;
        }

        if ($c->expires_at !== null && $now->gt($c->expires_at)) {
            return false;
        }

        return ! ($c->usage_limit !== null && (int) $c->usage_count >= (int) $c->usage_limit);
    }

    /* -------------------------------------------------------------- render */

    /**
     * One message.
     *
     * $ctx:
     *   href        callable(string $url, string $label): string — see the header
     *   first_name  for {first_name}; '' prints the tag's fallback
     *   audience    customers | subscribers, for the footer's why-line
     *   unsubscribe the signed unsubscribe URL, or null in a preview
     *   markers     true in the builder's preview (tbody per block)
     *   subject, preheader
     *   data        data() for these blocks (computed here when absent)
     *   theme       standard | playful (EmailTheme), the campaign's look
     *   locale      en | ar, the campaign's language: ar renders right to left
     *
     * THE LANGUAGE IS SET FOR THE RENDER AND PUT BACK AFTER, whatever happens:
     * one Arabic campaign must not leave the next English one (or the request
     * that previewed it) in Arabic.
     *
     * @return array{html:string, text:string, bytes:int}
     */
    public function render(array $blocks, array $ctx): array
    {
        $locale = EmailTheme::cleanLocale($ctx['locale'] ?? 'en');
        $was = \Illuminate\Support\Facades\App::getLocale();

        if ($locale !== $was) {
            \Illuminate\Support\Facades\App::setLocale($locale);
        }

        try {
            return $this->renderIn($blocks, $ctx, $locale);
        } finally {
            if ($locale !== $was) {
                \Illuminate\Support\Facades\App::setLocale($was);
            }
        }
    }

    /** @return array{html:string, text:string, bytes:int} */
    private function renderIn(array $blocks, array $ctx, string $locale): array
    {
        $theme = EmailTheme::cleanTheme($ctx['theme'] ?? 'standard');

        if (PersonalLetter::is($theme)) {
            return $this->renderLetter($blocks, $ctx, $locale);
        }
        $playful = $theme === 'playful';
        $href = $ctx['href'] ?? fn (string $url) => $url;
        $first = ['first_name' => (string) ($ctx['first_name'] ?? ''), 'top_brand' => (string) ($ctx['top_brand'] ?? '')];
        $data = $ctx['data'] ?? $this->data($blocks);
        $brand = EmailBranding::forMailable(true, CampaignMail::class);
        $k = MailKit::for($brand);

        $rows = [];
        $text = [];
        $topbar = null;
        $footer = null;
        $why = '';
        $whyKey = 'customers';
        $note = '';

        foreach ($blocks as $i => $b) {
            $p = $b['props'];

            switch ($b['type']) {
                case 'mini_header':
                    if (! empty($p['topbar'])) {
                        $topbar = $i;
                    }
                    $rows[] = ['i' => $i, 'type' => 'mini_header', 'nav' => (bool) ($p['nav'] ?? true), 'tagline' => Blocks::mergeName((string) ($p['tagline'] ?? ''), $first)];
                    break;

                case 'hero_image':
                    $art = Blocks::ART[$p['art'] ?? ''] ?? null;
                    $src = $art !== null ? Url::external('/email/art/' . $p['art'] . '.jpg') : Blocks::safeImage((string) ($p['src'] ?? ''));

                    if ($src === null || $src === '') {
                        break;
                    }

                    $link = Blocks::safeUrl((string) ($p['href'] ?? ''));
                    $alt = (string) (($p['alt'] ?? '') !== '' ? $p['alt'] : ($art['alt'] ?? ''));
                    $rows[] = [
                        'i' => $i, 'type' => 'hero_image', 'src' => $src, 'alt' => $alt,
                        'h' => $art !== null ? (int) round(600 * $art['h'] / $art['w']) : null,
                        'href' => $link === null ? null : $href($link, $alt !== '' ? $alt : 'Hero image'),
                    ];
                    $text[] = $alt;
                    break;

                case 'heading':
                    $highlight = Blocks::mergeName((string) ($p['highlight'] ?? ''), $first);
                    // The standard look has no highlighter: the line joins the
                    // title, so nothing the owner typed goes missing.
                    $titleText = ! $playful && $highlight !== '' ? trim((string) $p['title'] . ' ' . $highlight) : (string) $p['title'];
                    $plainTitle = Blocks::plain($titleText, $first);
                    $plainLead = Blocks::plain((string) $p['lead'], $first);

                    if ($plainTitle === '' && $plainLead === '' && $highlight === '') {
                        break;
                    }

                    $rows[] = [
                        'i' => $i, 'type' => 'heading', 'style' => $p['style'], 'icon' => $p['icon'], 'tone' => $p['tone'],
                        'align' => $p['align'], 'eyebrow' => Blocks::mergeName((string) $p['eyebrow'], $first),
                        'title' => Blocks::marks($titleText, $href, $first),
                        'lead' => Blocks::marks((string) $p['lead'], $href, $first),
                        'plainTitle' => $plainTitle, 'plainLead' => $plainLead,
                        'highlight' => $playful ? $highlight : '',
                    ];
                    $text[] = trim(($p['eyebrow'] !== '' ? mb_strtoupper((string) $p['eyebrow']) . "\n" : '') . $plainTitle . ($playful && $highlight !== '' ? "\n" . $highlight : '') . ($plainLead !== '' ? "\n\n" . Blocks::plain((string) $p['lead'], $first, $href) : ''));
                    break;

                case 'text':
                    if (trim((string) $p['body']) === '') {
                        break;
                    }

                    $rows[] = [
                        'i' => $i, 'type' => 'text', 'html' => Blocks::marks((string) $p['body'], $href, $first),
                        'pad' => '16px 32px 0', 'size' => (int) $p['size'], 'center' => $p['align'] === 'center',
                    ];
                    $text[] = Blocks::plain((string) $p['body'], $first, $href);
                    break;

                case 'button':
                    $link = $p['href'] === Blocks::TOP_BRAND_URL
                        ? (string) ($ctx['top_brand_url'] ?? Url::external('/brands/'))
                        : Blocks::safeUrl((string) $p['href']);
                    $label = Blocks::mergeName((string) $p['label'], $first);

                    if ($link === null || trim($label) === '') {
                        break;
                    }

                    $printed = $href($link, $label);
                    $rows[] = ['i' => $i, 'type' => 'button', 'label' => $label, 'href' => $printed, 'align' => $p['align'], 'ghost' => $p['style'] === 'ghost', 'dark' => $p['style'] === 'dark'];
                    $text[] = $label . ': ' . $printed;
                    break;

                case 'product_row':
                case 'product_grid':
                    $cards = [];

                    foreach ((array) ($p['_ids'] ?? []) as $id) {
                        $card = $data['cards'][(int) $id] ?? null;

                        if ($card === null) {
                            continue;
                        }

                        if (empty($p['show_sale'])) {
                            $card['was'] = null;
                        }

                        $card['href'] = $href($card['href'], $card['name']);
                        // The playful card (Lane EC): a type sticker, the name
                        // under its brand line without the brand again, the
                        // old price as a bare number, and the picture's height
                        // at the card's 138px.
                        $card['kind'] = EmailTheme::kindWord(EmailTheme::kind($card['name'], (string) ($card['type'] ?? '')), $locale);
                        $card['short'] = EmailTheme::shortName($card['name'], (string) $card['brand']);
                        $card['wasNum'] = EmailTheme::bareAmount($card['was'] ?? null);
                        $card['h138'] = max(1, (int) round(138 * ((int) ($card['h'] ?? 200)) / 200));
                        $cards[] = $card;
                    }

                    if ($cards === []) {
                        break;
                    }

                    $rows[] = [
                        'i' => $i, 'type' => $b['type'], 'title' => Blocks::mergeName((string) $p['title'], $first), 'products' => $cards,
                        'cols' => $b['type'] === 'product_grid' ? (int) ($p['columns'] ?? 2) : 1, 'cta' => Blocks::mergeName((string) $p['cta'], $first),
                        'layout' => $b['type'] === 'product_grid' && ($p['layout'] ?? 'standard') === 'playful' ? 'playful' : 'standard',
                    ];
                    $text[] = ($p['title'] !== '' ? mb_strtoupper((string) $p['title']) . "\n" : '')
                        . implode("\n", array_map(fn ($c) => '- ' . trim(($c['brand'] !== '' ? $c['brand'] . ' ' : '') . ($playful ? $c['short'] : $c['name'])) . ' — ' . $c['price'] . "\n  " . $c['href'], $cards));
                    break;

                case 'coupon':
                    $c = $data['coupons'][(int) ($p['coupon_id'] ?? 0)] ?? null;

                    if ($c === null) {
                        break;
                    }

                    $expires = (string) $p['expires'];

                    if ($expires === '' && $c->expires_at !== null) {
                        $expires = __('email.mkt.coupon_ends', ['date' => \App\Support\StoreTime::formatDate($c->expires_at, 'j F Y')]);
                    }

                    $line = Blocks::mergeName((string) $p['line'], $first);
                    $rows[] = ['i' => $i, 'type' => 'coupon', 'code' => mb_strtoupper((string) $c->code), 'line' => $line, 'expires' => $expires];
                    $text[] = __('email.kit.coupon_label') . ': ' . mb_strtoupper((string) $c->code) . ($line !== '' ? "\n" . $line : '') . ($expires !== '' ? "\n" . $expires : '');
                    break;

                case 'image':
                    $src = Blocks::safeImage((string) $p['src']);

                    if ($src === null) {
                        break;
                    }

                    $link = Blocks::safeUrl((string) $p['href']);
                    $rows[] = [
                        'i' => $i, 'type' => 'image', 'src' => $src, 'alt' => (string) $p['alt'], 'full' => $p['width'] === 'full',
                        'href' => $link === null ? null : $href($link, $p['alt'] !== '' ? (string) $p['alt'] : 'Image'),
                    ];
                    break;

                case 'columns':
                    $cells = [];

                    foreach (array_slice((array) $p['items'], 0, (int) $p['count']) as $cell) {
                        $link = Blocks::safeUrl((string) ($cell['href'] ?? ''));
                        $title = (string) ($cell['title'] ?? '');
                        $cells[] = [
                            'img' => Blocks::safeImage((string) ($cell['image'] ?? '')),
                            'title' => $title,
                            'html' => Blocks::marks((string) ($cell['text'] ?? ''), $href, $first),
                            'plain' => Blocks::plain((string) ($cell['text'] ?? ''), $first),
                            'href' => $link === null ? null : $href($link, $title !== '' ? $title : 'Column'),
                        ];
                    }

                    $cells = array_values(array_filter($cells, fn ($c) => $c['title'] !== '' || $c['plain'] !== '' || $c['img'] !== null));

                    if ($cells === []) {
                        break;
                    }

                    $rows[] = ['i' => $i, 'type' => 'columns', 'items' => $cells, 'cta' => (string) $p['cta']];
                    $text[] = implode("\n\n", array_map(fn ($c) => trim($c['title'] . "\n" . $c['plain'] . ($c['href'] ? "\n" . $c['href'] : '')), $cells));
                    break;

                case 'divider':
                    $rows[] = ['i' => $i, 'type' => 'divider'];
                    $text[] = '— — —';
                    break;

                case 'spacer':
                    $rows[] = ['i' => $i, 'type' => 'spacer', 'h' => (int) $p['height']];
                    break;

                case 'social':
                    $links = [];

                    foreach (Blocks::SOCIAL as $key => $label) {
                        $link = Blocks::safeUrl((string) ($p[$key] ?? ''));

                        if ($link !== null) {
                            $links[] = ['label' => $label, 'href' => $href($link, $label)];
                        }
                    }

                    if ($links !== []) {
                        $rows[] = ['i' => $i, 'type' => 'social', 'links' => $links];
                        $text[] = implode("\n", array_map(fn ($l) => $l['label'] . ': ' . $l['href'], $links));
                    }
                    break;

                case 'badges':
                    $chips = [];

                    foreach ((array) ($p['items'] ?? []) as $chip) {
                        $bold = Blocks::mergeName((string) ($chip['bold'] ?? ''), $first);
                        $line = Blocks::mergeName((string) ($chip['text'] ?? ''), $first);

                        if (trim($bold . $line) === '') {
                            continue;
                        }

                        // "Tabby, Tamara" + ", card or cash": no space before a comma.
                        $sep = $bold !== '' && $line !== '' && preg_match('/^[,.;:،]/u', $line) !== 1 ? ' ' : '';
                        $chips[] = ['icon' => (string) ($chip['icon'] ?? 'sparkles'), 'bold' => $bold, 'sep' => $sep, 'text' => $line];
                    }

                    if ($chips === []) {
                        break;
                    }

                    $rows[] = ['i' => $i, 'type' => 'badges', 'items' => $chips];
                    $text[] = implode("\n", array_map(fn ($c) => '* ' . trim($c['bold'] . $c['sep'] . $c['text']), $chips));
                    break;

                case 'footer':
                    $footer = $i;
                    $note = Blocks::mergeName((string) ($p['note'] ?? ''), $first);
                    $audience = ($p['why'] ?? 'auto') === 'auto' ? (string) ($ctx['audience'] ?? 'customers') : (string) $p['why'];
                    /*
                     * Why this arrived, true for THIS recipient: a subscriber
                     * subscribed; a customer bought before — or, for one who
                     * has an account and never ordered (the "Never ordered"
                     * group), has an account. CampaignSender passes 'account'
                     * per recipient.
                     */
                    $whyKey = in_array($audience, ['subscribers', 'account'], true) ? $audience : 'customers';
                    $why = $playful
                        ? EmailTheme::word($locale, 'why_' . $whyKey, ['store' => $k['storeName']])
                        : match ($audience) {
                            'subscribers' => __('email.mkt.why_subscribers', ['store' => $k['storeName']]),
                            'account' => __('email.mkt.why_account', ['store' => $k['storeName']]),
                            default => __('email.mkt.why_customers', ['store' => $k['storeName']]),
                        };
                    break;
            }
        }

        $preheader = Blocks::mergeName((string) ($ctx['preheader'] ?? ''), $first);

        $html = view($playful ? 'emails.marketing.playful' : 'emails.marketing.campaign', [
            'k' => $k,
            'locale' => $locale,
            'note' => $note,
            'kitTitle' => Blocks::mergeName((string) ($ctx['subject'] ?? ''), $first),
            'kitPreheader' => $preheader,
            'rows' => $rows,
            'topbar' => $topbar,
            'footer' => $footer,
            'why' => $why,
            'unsubscribe' => $ctx['unsubscribe'] ?? null,
            'markers' => (bool) ($ctx['markers'] ?? false),
        ])->render();

        $unsub = $ctx['unsubscribe'] ?? null;
        $plain = trim(implode("\n\n", array_filter(array_map('trim', $text), fn ($t) => $t !== '')))
            . "\n\n— " . $k['storeName']
            . "\n\n" . $why
            . ($unsub ? "\n" . ($playful ? EmailTheme::word($locale, 'text_unsubscribe') : __('email.mkt.text_unsubscribe')) . "\n" . $unsub : '');

        return ['html' => $html, 'text' => $plain, 'bytes' => strlen($html)];
    }

    /**
     * The personal letter (Lane EP, PersonalLetter): the same blocks, printed
     * as a letter. Only PersonalLetter::KEEPS print; a heading is a bold
     * line, a button is a plain link in its own sentence, the first picture
     * is small and unlinked and any other is left out, and only the first
     * MAX_LINKS links in the body stay links — the rest print as their words.
     * The text part is built from the same strings, so it says the same thing.
     *
     * $ctx also takes `signer` (PersonalLetter::signer()): the name the letter
     * is signed with; blank signs it from the shop's team.
     *
     * @return array{html:string, text:string, bytes:int}
     */
    private function renderLetter(array $blocks, array $ctx, string $locale): array
    {
        $href = $ctx['href'] ?? fn (string $url) => $url;
        $first = ['first_name' => (string) ($ctx['first_name'] ?? ''), 'top_brand' => (string) ($ctx['top_brand'] ?? '')];
        $brand = EmailBranding::forMailable(true, CampaignMail::class);
        $k = MailKit::for($brand);
        $store = (string) $k['storeName'];
        $signer = PersonalLetter::signer($ctx['signer'] ?? '');
        $budget = PersonalLetter::MAX_LINKS;
        $images = 0;
        $rows = [];
        $text = [];
        $footer = null;
        $audience = (string) ($ctx['audience'] ?? 'customers');
        $linkRe = '/\[([^\]\n]{1,200})\]\(([^)\n]{1,500})\)/u';

        // Links past the budget lose their address and keep their words.
        $cap = function (string $s) use (&$budget, $linkRe): string {
            return (string) preg_replace_callback($linkRe, function ($m) use (&$budget) {
                if (Blocks::safeUrl($m[2]) === null) {
                    return $m[0];
                }

                if ($budget > 0) {
                    $budget--;

                    return $m[0];
                }

                return $m[1];
            }, $s);
        };

        $paragraphs = function (int $i, string $body) use (&$rows, &$text, $href, $first): void {
            foreach (preg_split('/\n[ \t]*\n+/u', trim($body)) ?: [] as $para) {
                if (trim($para) === '') {
                    continue;
                }

                $rows[] = ['i' => $i, 'type' => 'p', 'html' => Blocks::marks(trim($para), $href, $first, PersonalLetter::LINK)];
                $text[] = Blocks::plain(trim($para), $first, $href);
            }
        };

        foreach ($blocks as $i => $b) {
            $p = $b['props'];

            switch ($b['type']) {
                case 'heading':
                    $title = trim((string) ($p['title'] ?? '') . ' ' . Blocks::mergeName((string) ($p['highlight'] ?? ''), $first));

                    if (Blocks::plain($title, $first) !== '') {
                        $title = $cap($title);
                        $rows[] = ['i' => $i, 'type' => 'p', 'bold' => true, 'html' => Blocks::marks($title, $href, $first, PersonalLetter::LINK)];
                        $text[] = Blocks::plain($title, $first, $href);
                    }

                    if (trim((string) ($p['lead'] ?? '')) !== '') {
                        $paragraphs($i, $cap((string) $p['lead']));
                    }
                    break;

                case 'text':
                    if (trim((string) ($p['body'] ?? '')) !== '') {
                        $paragraphs($i, $cap((string) $p['body']));
                    }
                    break;

                case 'button':
                    $link = $p['href'] === Blocks::TOP_BRAND_URL
                        ? (string) ($ctx['top_brand_url'] ?? Url::external('/brands/'))
                        : Blocks::safeUrl((string) $p['href']);
                    $label = trim((string) preg_replace('/\s*[→←]\s*/u', ' ', Blocks::mergeName((string) $p['label'], $first)));

                    if ($link === null || $label === '') {
                        break;
                    }

                    if ($budget <= 0) {
                        break;   // a button with no link left is a sentence with nothing to say
                    }

                    $budget--;
                    $printed = $href($link, $label);
                    $rows[] = ['i' => $i, 'type' => 'link', 'label' => $label, 'href' => $printed];
                    $text[] = $label . ': ' . $printed;
                    break;

                case 'image':
                case 'hero_image':
                    $art = $b['type'] === 'hero_image' ? (Blocks::ART[$p['art'] ?? ''] ?? null) : null;
                    $src = $art !== null ? Url::external('/email/art/' . $p['art'] . '.jpg') : Blocks::safeImage((string) ($p['src'] ?? ''));

                    if ($src === null || $src === '' || $images >= PersonalLetter::MAX_IMAGES) {
                        break;
                    }

                    $images++;
                    $alt = (string) (($p['alt'] ?? '') !== '' ? $p['alt'] : ($art['alt'] ?? ''));
                    $rows[] = [
                        'i' => $i, 'type' => 'img', 'src' => $src, 'alt' => $alt, 'w' => PersonalLetter::IMAGE_WIDTH,
                        'h' => $art !== null ? (int) round(PersonalLetter::IMAGE_WIDTH * $art['h'] / $art['w']) : null,
                    ];
                    break;

                case 'footer':
                    $footer = $i;
                    $why = ($p['why'] ?? 'auto') === 'auto' ? $audience : (string) $p['why'];
                    $audience = in_array($why, ['subscribers', 'account'], true) ? $why : 'customers';
                    break;
            }
        }

        $why = match ($audience) {
            'subscribers' => __('email.mkt.why_subscribers', ['store' => $store]),
            'account' => __('email.mkt.why_account', ['store' => $store]),
            default => __('email.mkt.why_customers', ['store' => $store]),
        };
        $name = trim($first['first_name']);
        $hi = $name !== '' ? PersonalLetter::word($locale, 'hi', ['name' => $name]) : PersonalLetter::word($locale, 'hi_blank');
        $reply = PersonalLetter::word($locale, $signer !== '' ? 'reply' : 'reply_team');
        $sign = PersonalLetter::word($locale, 'sign');
        $by = $signer !== '' ? [$signer, $store] : [PersonalLetter::word($locale, 'team', ['store' => $store])];
        $unsub = $ctx['unsubscribe'] ?? null;
        $addresses = [];

        foreach ((array) ($k['addresses'] ?? []) as $place) {
            $lines = array_values(array_filter(array_map('trim', (array) ($place['lines'] ?? []))));

            if ($lines !== []) {
                $addresses[] = implode(', ', $lines);
            }
        }

        $html = view('emails.marketing.letter', [
            'locale' => $locale,
            'dir' => EmailTheme::dir($locale),
            'title' => Blocks::mergeName((string) ($ctx['subject'] ?? ''), $first),
            'preheader' => Blocks::mergeName((string) ($ctx['preheader'] ?? ''), $first),
            'hi' => $hi,
            'rows' => $rows,
            'reply' => $reply,
            'sign' => $sign,
            'by' => $by,
            'footer' => $footer,
            'why' => $why,
            'addresses' => $addresses,
            'unsubLead' => PersonalLetter::word($locale, 'unsub_lead'),
            'unsubWord' => PersonalLetter::word($locale, 'unsub'),
            'unsubscribe' => $unsub,
            'link' => PersonalLetter::LINK,
            'markers' => (bool) ($ctx['markers'] ?? false),
        ])->render();

        $plain = $hi . "\n\n"
            . implode("\n\n", array_filter(array_map('trim', $text), fn ($t) => $t !== ''))
            . "\n\n" . $reply
            . "\n\n" . $sign . "\n" . implode("\n", $by)
            . "\n\n--\n" . $why
            . ($addresses !== [] ? "\n" . implode("\n", $addresses) : '')
            . ($unsub ? "\n" . PersonalLetter::word($locale, 'unsub_lead') . ' ' . PersonalLetter::word($locale, 'unsub') . ': ' . $unsub : '');

        return ['html' => $html, 'text' => $plain, 'bytes' => strlen($html)];
    }

    /**
     * Every trackable address the email carries, in the order it prints them:
     * the rows mkt_links is written from when a send starts.
     *
     * @return list<array{url:string, label:string}>
     */
    public function links(array $materialized, array $data, string $audience, array $vars = []): array
    {
        $seen = [];
        $out = [];

        $this->render($materialized, $vars + [
            'data' => $data,
            'audience' => $audience,
            'href' => function (string $url, string $label) use (&$seen, &$out): string {
                if (! isset($seen[$url])) {
                    $seen[$url] = true;
                    $out[] = ['url' => mb_substr($url, 0, 500), 'label' => mb_substr($label, 0, 160)];
                }

                return $url;
            },
        ]);

        return $out;
    }

    /** "Products filled: Medicube, 4 in stock" and friends, for Review & send. */
    public function fillSummary(array $materialized, array $data): array
    {
        $out = [];

        foreach ($materialized as $b) {
            if (in_array($b['type'], ['product_row', 'product_grid'], true)) {
                $live = array_values(array_filter((array) ($b['props']['_ids'] ?? []), fn ($id) => isset($data['cards'][(int) $id])));
                $out[] = ['kind' => 'products', 'fill' => $b['props']['fill'], 'count' => count($live), 'wanted' => (int) $b['props']['count']];
            }

            if ($b['type'] === 'coupon') {
                $id = (int) ($b['props']['coupon_id'] ?? 0);
                $c = $data['coupons'][$id] ?? null;
                $out[] = ['kind' => 'coupon', 'ok' => $c !== null, 'code' => $c !== null ? mb_strtoupper((string) $c->code) : null,
                    'restricted' => $c !== null && ! empty($c->allowed_emails) && $c->allowed_emails !== '[]' && $c->allowed_emails !== 'null'];
            }
        }

        return $out;
    }

    /**
     * A campaign's or template's look and language (Lane EC), cleaned: what
     * every render of that row passes as ctx. A row from before the columns
     * existed is standard English.
     *
     * @return array{theme:string, locale:string}
     */
    public static function look(?object $row): array
    {
        return [
            'theme' => EmailTheme::cleanTheme($row->theme ?? null),
            'locale' => EmailTheme::cleanLocale($row->locale ?? null),
        ];
    }

    /** HtmlString passthrough for views that print a marks() result. */
    public static function html(string $s): HtmlString
    {
        return new HtmlString($s);
    }
}
