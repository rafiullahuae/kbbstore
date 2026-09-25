<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Newsletter signup — wording, behaviour and appearance.
 *
 * Deliberately no on/off switch. Whether the block appears, and on which
 * devices, is already owned by Appearance → Homepage, which holds `newsletter`
 * as one of its sections. A second switch here would be a control that saves
 * cleanly and sometimes changes nothing, depending on which one you touched
 * last — which is indistinguishable from a broken one.
 *
 * Double opt-in was specified and is not here either: it needs outbound mail,
 * and nothing in this build has been shown to send an email yet. A confirmation
 * toggle that silently never confirms is worse than no toggle. It goes in with
 * password reset, when mail is proven.
 */
class NewsletterSettings
{
    public const SCHEMA = [
        // ── Content ──
        'nl_eyebrow'     => ['text',   'Eyebrow', 'Join the list', 'The small line above the heading.'],
        'nl_heading'     => ['text',   'Heading', 'Ten percent off your first order', ''],
        'nl_subheading'  => ['text',   'Sub-heading', 'Routines, restocks and members-only drops. One email a week, never more.', ''],
        'nl_placeholder' => ['text',   'Field placeholder', 'Your email address', ''],
        'nl_button'      => ['text',   'Button wording', 'Subscribe', ''],

        // ── Messages ──
        'nl_success'     => ['text',   'After signing up', 'You are on the list — check your inbox for the code.', ''],
        'nl_duplicate'   => ['text',   'Already signed up', 'You are already on the list.', 'Shown when the address is one already held.'],
        'nl_error'       => ['text',   'Bad address', 'That does not look like an email address.', ''],
        'nl_source_tag'  => ['bool',   'Record where each signup came from', true, 'Stores the page the form was on, so a homepage signup can be told from a footer one later.'],

        // ── Appearance ──
        'nl_bg_from'     => ['colour', 'Panel gradient · from', '#FFF1F5', ''],
        'nl_bg_to'       => ['colour', 'Panel gradient · to', '#FFE3EC', ''],
        'nl_btn_bg'      => ['colour', 'Button background', '#2A2228', ''],
        'nl_btn_fg'      => ['colour', 'Button text', '#FFFFFF', ''],
        'nl_note_colour' => ['colour', 'Confirmation text', '#C13E63', ''],
    ];

    public const TABS = [
        'content'  => ['Content', 'The wording on the panel.',
                       ['nl_eyebrow', 'nl_heading', 'nl_subheading', 'nl_placeholder', 'nl_button']],
        'messages' => ['Messages', 'What a shopper is told after submitting.',
                       ['nl_success', 'nl_duplicate', 'nl_error', 'nl_source_tag']],
        'style'    => ['Appearance', 'Colours of the panel and its button.',
                       ['nl_bg_from', 'nl_bg_to', 'nl_btn_bg', 'nl_btn_fg', 'nl_note_colour']],
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get($key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $out;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (! isset(self::SCHEMA[$key])) {
            return $default;
        }

        $saved = $this->settings->get($key, null);

        return $saved === null ? self::SCHEMA[$key][2] : $this->cast($key, $saved);
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set($key, $this->cast($key, $value));
            }
        }
    }

    /**
     * Values are cast and clamped on the way in, never on the way out — a bad
     * colour is rejected once at save rather than defended against on every
     * page render.
     */
    /** This screen's point on ModuleSchema's four policy axes. Five colour
     *  fields here stored a hex with no `#` until this moved to the shared
     *  cast; see ModuleSchema::cast(). No range control on this screen, so
     *  clamping never applies. */
    public const POLICY = [
        'max' => 240,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'cast',
    ];

    private function cast(string $key, mixed $value): mixed
    {
        return ModuleSchema::cast(
            ModuleSchema::field($key, self::SCHEMA[$key], self::POLICY),
            $value,
        );
    }

    /** Inline custom properties for the panel, in the shape the other services use. */
    public function cssVariables(): string
    {
        $c = $this->all();

        return implode(';', [
            '--nl-from:' . $c['nl_bg_from'],
            '--nl-to:' . $c['nl_bg_to'],
            '--nl-btn-bg:' . $c['nl_btn_bg'],
            '--nl-btn-fg:' . $c['nl_btn_fg'],
            '--nl-note:' . $c['nl_note_colour'],
        ]);
    }
}
