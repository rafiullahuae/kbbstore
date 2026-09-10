<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The account panel: what it holds, how it looks, and the welcome inside it.
 *
 * Its own screen rather than another tab under Header, because the panel is a
 * surface in its own right — it has a guest state, a signed-in state and an
 * animation, none of which belong in a list of bar heights.
 */
class AccountPanel
{
    public const FONTS = [
        'cormorant' => ['Cormorant Garamond', "'Cormorant Garamond',Georgia,serif", 300],
        'italiana' => ['Italiana', "'Italiana',Georgia,serif", 400],
        'tenor' => ['Tenor Sans', "'Tenor Sans',sans-serif", 400],
        'marcellus' => ['Marcellus', "'Marcellus',Georgia,serif", 400],
        'josefin' => ['Josefin Sans', "'Josefin Sans',sans-serif", 300],
        'playfair' => ['Playfair Display', "'Playfair Display',Georgia,serif", 400],
        'bodoni' => ['Bodoni Moda', "'Bodoni Moda',Georgia,serif", 400],
        'parisienne' => ['Parisienne', "'Parisienne',cursive", 400],
        'inherit' => ['Same as the site', 'inherit', 500],
    ];

    public const SCHEMA = [
        // ── Welcome ──
        'welcome_show'   => ['bool',   'Show a welcome', true, 'Once, when someone signs in or registers.'],
        'welcome_style'  => ['select', 'How it appears', 'fill', '',
                             ['fill' => 'Colour fills the name', 'shimmer' => 'Light passes across',
                              'letters' => 'Letter by letter', 'drift' => 'Gradient drifts', 'focus' => 'Soft to sharp']],
        'welcome_font'   => ['select', 'Typeface', 'cormorant', '', [
                              'cormorant' => 'Cormorant Garamond', 'italiana' => 'Italiana', 'tenor' => 'Tenor Sans',
                              'marcellus' => 'Marcellus', 'josefin' => 'Josefin Sans', 'playfair' => 'Playfair Display',
                              'bodoni' => 'Bodoni Moda', 'parisienne' => 'Parisienne', 'inherit' => 'Same as the site']],
        'welcome_size'   => ['range',  'Name size', 27, '', ['min' => 18, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'welcome_size_m' => ['range',  'Name size · phone', 30, '', ['min' => 18, 'max' => 44, 'step' => 1, 'unit' => 'px']],
        'welcome_hold'   => ['range',  'How long it stays', 2500, 'Then it folds away on its own.', ['min' => 1200, 'max' => 5000, 'step' => 100, 'unit' => 'ms']],
        'welcome_back'   => ['text',   'Returning wording', 'Welcome back', ''],
        'welcome_new'    => ['text',   'New account wording', 'Welcome', ''],

        // ── Panel ──
        'guest_mode'     => ['select', 'For guests', 'page', '',
                             ['page' => 'Go to the sign-in page', 'panel' => 'Open the form in the panel']],
        'panel_width'    => ['range',  'Panel width', 296, '', ['min' => 240, 'max' => 380, 'step' => 4, 'unit' => 'px']],
        'panel_radius'   => ['range',  'Panel roundness', 14, '', ['min' => 0, 'max' => 24, 'step' => 2, 'unit' => 'px']],
        'panel_open'     => ['select', 'Opens on', 'hover', 'On a phone it is always a tap.',
                             ['hover' => 'Hover', 'click' => 'Click']],

        // ── Links ──
        'link_orders'    => ['bool',   'Orders', true, ''],
        'link_wishlist'  => ['bool',   'Wishlist', true, ''],
        'link_address'   => ['bool',   'Addresses', true, ''],
        'link_track'     => ['bool',   'Track my order', false, ''],
        'show_email'     => ['bool',   'Email under the name', true, ''],

        // ── Forms ──
        'form_style'     => ['select', 'Field style', 'grouped',
                             'How the sign-in and register fields are drawn.',
                             ['grouped' => 'Grouped block', 'floating' => 'Floating labels',
                              'filled' => 'Iconed, soft fill', 'generous' => 'Generous']],
        'form_icons'     => ['bool',   'Icons in fields', true, 'A small mark at the start of each field.'],
        'field_gap'      => ['range',  'Label to text gap', 3,
                             'Space between the small label and what is typed beneath it.',
                             ['min' => 0, 'max' => 8, 'step' => 1, 'unit' => 'px']],
        'form_mark'      => ['bool',   'Logo mark', true, 'The rounded KB mark above the heading.'],
        'form_strength'  => ['bool',   'Password strength', true, 'A short bar under the password field.'],
        'form_aside'     => ['bool',   'Reasons panel', false, 'A short list beside the sign-in form on a wide screen.'],

        // ── Sign up ──
        'sum_show'       => ['bool',   'Sum before registering', true, 'A small question that keeps automated sign-ups out.'],
        'sum_label'      => ['text',   'Sum wording', 'What is', ''],
        'terms_show'     => ['bool',   'Terms line', true, 'Under the create-account button.'],
    ];

    public const TABS = [
        'welcome' => ['Welcome', 'The greeting shown once after signing in.',
                      ['welcome_show', 'welcome_style', 'welcome_font', 'welcome_size', 'welcome_size_m',
                       'welcome_hold', 'welcome_back', 'welcome_new']],
        'panel'   => ['Panel', 'Size, and what a guest gets.',
                      ['guest_mode', 'panel_width', 'panel_radius', 'panel_open']],
        'links'   => ['Links', 'What a signed-in customer sees.',
                      ['link_orders', 'link_wishlist', 'link_address', 'link_track', 'show_email']],
        'forms'   => ['Forms', 'The sign-in, register, reset and tracking pages.',
                      ['form_style', 'field_gap', 'form_icons', 'form_mark', 'form_strength', 'form_aside']],
        'signup'  => ['Sign up', 'The create-account form.',
                      ['sum_show', 'sum_label', 'terms_show']],
    ];

    public function __construct(private SettingsService $settings) {}

    public function all(): array
    {
        $saved = $this->settings->get('account_panel');
        $saved = is_array($saved) ? $saved : [];

        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $out[$key] = array_key_exists($key, $saved) ? $this->cast($key, $saved[$key]) : $def[2];
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key] ?? null;

        if ($def === null) {
            return null;
        }

        [$type, , $default] = $def;

        return match ($type) {
            'bool' => (bool) $value,
            'range' => max($def[4]['min'], min($def[4]['max'], (int) $value)),
            'select' => isset($def[4][(string) $value]) ? (string) $value : $default,
            default => trim((string) $value) === '' ? $default : mb_substr(trim((string) $value), 0, 60),
        };
    }

    public function save(array $values): void
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $clean[$key] = $this->cast($key, $value);
            }
        }

        $this->settings->set('account_panel', $clean);
    }

    public function cssVariables(): string
    {
        $c = $this->all();
        [, $stack, $weight] = self::FONTS[$c['welcome_font']] ?? self::FONTS['cormorant'];

        return implode(';', [
            '--ap-w:' . $c['panel_width'] . 'px',
            '--ap-r:' . $c['panel_radius'] . 'px',
            '--ap-font:' . $stack,
            '--ap-weight:' . $weight,
            '--ap-size:' . $c['welcome_size'] . 'px',
            '--ap-size-m:' . $c['welcome_size_m'] . 'px',
            '--ap-hold:' . $c['welcome_hold'] . 'ms',
            '--fld-gap:' . $c['field_gap'] . 'px',
        ]);
    }

    /** The class the account pages carry, so one setting styles every form. */
    public function formClass(): string
    {
        $c = $this->all();

        return trim('fs-' . $c['form_style']
            . ($c['form_icons'] ? '' : ' fs-noicons')
            . ($c['form_strength'] ? '' : ' fs-nometer'));
    }

    /** Only the chosen face is fetched, so an unused one costs nothing. */
    public function fontHref(): ?string
    {
        $key = (string) $this->get('welcome_font');

        return match ($key) {
            'cormorant' => 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400&display=swap',
            'italiana' => 'https://fonts.googleapis.com/css2?family=Italiana&display=swap',
            'tenor' => 'https://fonts.googleapis.com/css2?family=Tenor+Sans&display=swap',
            'marcellus' => 'https://fonts.googleapis.com/css2?family=Marcellus&display=swap',
            'josefin' => 'https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300;400&display=swap',
            'playfair' => 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&display=swap',
            'bodoni' => 'https://fonts.googleapis.com/css2?family=Bodoni+Moda:opsz,wght@6..96,400&display=swap',
            'parisienne' => 'https://fonts.googleapis.com/css2?family=Parisienne&display=swap',
            default => null,
        };
    }
}
