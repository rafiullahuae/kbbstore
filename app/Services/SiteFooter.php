<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SafeUrl;
use App\Support\SupportContact;
use App\Support\Url;

/**
 * The site footer on every storefront page — the new design and the switch
 * back to the old one.                                              (Lane HB)
 *
 * Admin: Appearance → Footer, the three "Site footer" tabs (drawn by the
 * existing Footer screen; App\Http\Controllers\Admin\SlimFooterApiController
 * hands them out beside the slim bar's own tabs and routes their keys here).
 *
 * THE OWNER, 3 October 2026 (master plan row 55), approving
 * docs/home-preview/footer-final.html: C design for the desktop, "reduce the
 * height on mobile. use whatsapp green colors. and the colored strip of need
 * help, will changes colors itself within same color range … replace chat with
 * us [with] 24/7 available … we don't offer returns so don't include any return
 * word … improve that footer design overall more to beauty industry. and bottom
 * we need our name super big center align with light color, and continue
 * changes, bright" — then "proceed with the development".
 *
 * ── FED FROM WHAT THE SHOP ALREADY HAS ──────────────────────────────────────
 *
 * The wordmark is Appearance → Header's; the WhatsApp number is
 * SupportContact's; the profiles are the social_* settings (Store → Search
 * appearance) behind SafeUrl; the Help column is the footer menu when one
 * exists; the payment chips are PaymentChips' 'footer' row; the copyright is
 * the store name. Settings exist here ONLY for what the shop did not have: the
 * help strip's three lines, the two addresses, the big name, and the design
 * switch itself.
 *
 * ── NO WORD "RETURN" ────────────────────────────────────────────────────────
 *
 * helpLinks() drops any footer-menu row whose label or address mentions a
 * return or a refund, and the shipped defaults carry none. The old footer's
 * "Returns Information" link (/refund_returns/) is not drawn by the new design.
 */
final class SiteFooter
{
    /**
     * Stored in `settings` under this prefix, NOT in `module_settings`: the
     * settings map is already loaded on every storefront page, and reading the
     * module map as well cost /shop and the product page one more query each
     * (PageCostBudgetTest measured 19 → 20 and 22 → 23). SlimFooter stores the
     * same way for the same reason.
     */
    public const PREFIX = 'sitefooter_';

    public const MODULE = 'site_footer';

    /** What a footer-menu row may not mention, in the new design. */
    public const BANNED = '/return|refund/i';

    public const SCHEMA = [
        'site_design' => ['type' => 'select', 'label' => 'Footer design', 'default' => 'bliss',
            'options' => [
                'bliss' => 'New — WhatsApp help strip and the big name (approved 3 October)',
                'classic' => 'Previous — the dark four-column footer',
            ],
            'help' => 'Every storefront page. Choose Previous to go back to the footer the shop had before.'],
        'site_motion' => ['type' => 'bool', 'label' => 'Colours drift slowly', 'default' => true,
            'help' => 'The green strip and the big name move gently through their colours. Never for a visitor whose device asks for reduced motion.'],
        'site_help_on' => ['type' => 'bool', 'label' => 'Show the green help strip', 'default' => true, 'help' => ''],
        'site_help_title' => ['type' => 'text', 'label' => 'Strip headline', 'default' => '',
            'help' => 'Empty: “Find your perfect K-beauty match”.'],
        'site_help_chip' => ['type' => 'text', 'label' => 'The white chip', 'default' => '',
            'help' => 'Empty: “24/7 available”.'],
        'site_help_sub' => ['type' => 'text', 'label' => 'Line under the headline (laptop only)', 'default' => '',
            'help' => 'Empty: “Ask us anything about your skin, a product or your order — we reply on WhatsApp.”'],
        'site_track_on' => ['type' => 'bool', 'label' => '“Track my order” button in the strip', 'default' => true,
            'help' => 'Beside “Chat on WhatsApp”, which dials the WhatsApp number on Store → Settings.'],
        'site_addr_dubai' => ['type' => 'text', 'label' => 'Dubai address', 'default' => '',
            'help' => 'Empty: the Dubai line is not drawn. With both addresses empty, the whole “Visit us” block is left out.'],
        'site_addr_korea' => ['type' => 'text', 'label' => 'Korea address', 'default' => '',
            'help' => 'Empty: the Korea line is not drawn.'],
        'site_news_on' => ['type' => 'bool', 'label' => 'Offers sign-up box', 'default' => true,
            'help' => 'Signs the address up to the newsletter, the same list as the homepage form.'],
        'site_name_on' => ['type' => 'bool', 'label' => 'The big name at the bottom', 'default' => true, 'help' => ''],
        'site_name_text' => ['type' => 'text', 'label' => 'The big name', 'default' => 'K-Beauty Bliss',
            'help' => 'Centred, very large, in light colours that slowly change. Keep it short: it is sized to fit one line.'],
    ];

    public const TABS = [
        'site' => ['Site footer · design', 'The footer at the bottom of every storefront page. The new design is on, as the owner asked; Previous puts the old footer back.',
            ['site_design', 'site_motion']],
        'site_help' => ['Site footer · help strip', 'The WhatsApp-green strip across the top of the footer.',
            ['site_help_on', 'site_help_title', 'site_help_chip', 'site_help_sub', 'site_track_on']],
        'site_visit' => ['Site footer · Visit us & name', 'The addresses, the offers box and the big name. An empty address is not drawn — nothing is made up.',
            ['site_addr_dubai', 'site_addr_korea', 'site_news_on', 'site_name_on', 'site_name_text']],
    ];

    public const POLICY = [
        'max' => 160,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'bool' => 'cast',
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (ModuleSchema::normalise(self::SCHEMA, self::POLICY) as $key => $field) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $field['default'] : ModuleSchema::cast($field, $saved);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{written: list<string>, rejected: array<string, string>}
     */
    public function save(array $values): array
    {
        $fields = ModuleSchema::normalise(self::SCHEMA, self::POLICY);
        $written = [];
        $rejected = [];

        foreach ($values as $key => $value) {
            if (! isset($fields[$key])) {
                continue;
            }

            $cast = ModuleSchema::cast($fields[$key], $value);

            if ($cast === null) {
                $rejected[$key] = (string) $fields[$key]['label'];

                continue;
            }

            $this->settings->set(self::PREFIX.$key, $cast);
            $written[] = $key;
        }

        return ['written' => $written, 'rejected' => $rejected];
    }

    /** 'bliss' or 'classic' — one of the select's own keys, never the stored string. */
    public function design(): string
    {
        return ($this->all()['site_design'] ?? 'bliss') === 'classic' ? 'classic' : 'bliss';
    }

    /**
     * The Help column: the footer menu's rows when the owner has built one, the
     * shop's real help pages otherwise — minus anything about returns.
     *
     * @param  iterable<array<string, mixed>>  $nav
     * @return list<array{label: string, url: string}>
     */
    public static function helpLinks(iterable $nav): array
    {
        $out = [];

        foreach ($nav as $link) {
            $label = trim((string) ($link['label'] ?? ''));
            $url = (string) ($link['url'] ?? '/');

            if ($label === '' || preg_match(self::BANNED, $label.' '.$url) === 1) {
                continue;
            }

            $out[] = ['label' => $label, 'url' => Url::to($url)];
        }

        if ($out !== []) {
            return $out;
        }

        return [
            ['label' => __('store.footer.link_track_order'), 'url' => Url::to('/track-my-order/')],
            ['label' => __('store.footer.link_delivery'), 'url' => Url::to('/delivery/')],
            ['label' => __('store.footer.link_faqs'), 'url' => Url::to('/faqs/')],
            ['label' => __('store.footer.link_contact'), 'url' => Url::to('/contact-us/')],
        ];
    }

    /**
     * Everything the new footer prints, reduced to checked values.
     *
     * @param  iterable<array<string, mixed>>  $nav  the footer menu ($kbbFooterNav)
     * @return array<string, mixed>
     */
    public function view(iterable $nav): array
    {
        $c = $this->all();
        $header = app(HeaderSettings::class);
        $text = static fn (string $key): string => trim(\App\Support\RichText::toText((string) ($c[$key] ?? '')));

        $wa = SupportContact::whatsappDigits();

        /*
         * The owner's own profiles, through SafeUrl::href($u, '') exactly as the
         * old footer does: a refused address is '' and the icon is left out,
         * rather than '#', which would draw a control that goes nowhere. The
         * first three keep the shipped fallback the old footer had; YouTube has
         * none, so it appears only once he has entered one.
         */
        $s = app(SettingsService::class);
        $socials = array_values(array_filter([
            ['instagram', 'Instagram', SafeUrl::href((string) $s->get('social_instagram', 'https://www.instagram.com/kbeauty.bliss/'), '')],
            ['tiktok', 'TikTok', SafeUrl::href((string) $s->get('social_tiktok', 'https://www.tiktok.com/@kbeauty.bliss'), '')],
            ['facebook', 'Facebook', SafeUrl::href((string) $s->get('social_facebook', 'https://www.facebook.com/kbeautyblissuae'), '')],
            ['youtube', 'YouTube', SafeUrl::href((string) $s->get('social_youtube', ''), '')],
        ], static fn (array $row): bool => $row[2] !== '' && $row[2] !== '#'));

        return [
            'motion' => (bool) ($c['site_motion'] ?? true),
            'logo' => [(string) $header->get('logo_text'), (string) $header->get('logo_accent')],
            'help_on' => (bool) ($c['site_help_on'] ?? true),
            'help_title' => $text('site_help_title') !== '' ? $text('site_help_title') : __('store.footer.help_headline'),
            'help_chip' => $text('site_help_chip') !== '' ? $text('site_help_chip') : __('store.footer.help_chip'),
            'help_sub' => $text('site_help_sub') !== '' ? $text('site_help_sub') : __('store.footer.help_sub'),
            'wa' => $wa !== '' ? 'https://wa.me/'.$wa : '',
            'track' => (bool) ($c['site_track_on'] ?? true) ? Url::to('/track-my-order/') : '',
            'socials' => $socials,
            // The Brands link follows the brands module, exactly as the header's
            // does (NavigationService): off, /brands/ is a 404 and no chrome
            // may link to it.
            'brands' => $s->moduleEnabled('brands', true),
            'help_links' => self::helpLinks($nav),
            'dubai' => $text('site_addr_dubai'),
            'korea' => $text('site_addr_korea'),
            'news' => (bool) ($c['site_news_on'] ?? true),
            'name' => (bool) ($c['site_name_on'] ?? true) ? $text('site_name_text') : '',
        ];
    }
}
