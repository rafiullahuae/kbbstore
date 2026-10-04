<?php

declare(strict_types=1);

namespace App\Services\CartTracking;

use App\Services\SettingsService;

/**
 * Growth & Marketing → Cart Tracking → Settings.                   (Lane CT)
 *
 * Every value is held to its own bounds when it is SAVED and again when it is
 * READ, so a hand-rolled POST or a hand-edited row cannot set "keep events for
 * 0 days" (which would turn the retention sweep into "delete everything") or
 * a block scope that does not exist. A select stores one of its own options or
 * the default; a range is clamped; a switch is a bool.
 *
 * ▲ TWO DEFAULTS ARE ON BECAUSE THE OWNER ASKED FOR THEM, 4 October:
 *
 *   - `track` — "I want a super functional Cart Tracking Functionality … to
 *     track every cart". A tracker that ships off tracks nothing.
 *   - `bots_leave` — "a lot of un-friendly bots. and we need to block those
 *     too." On for cart and checkout only: a scripted client, a headless
 *     browser or a scraper is answered 403 there. Real search engines are on an
 *     allowlist and are never refused, and no page a shopper BROWSES is
 *     affected by this switch at all. See BotSignals::leaveReason().
 *
 * Neither changes a single byte of any page a person reads.
 */
final class CartTrackingSettings
{
    public const PREFIX = 'ct_';

    /**
     * key => [type, label, default, help, bounds|options]
     */
    public const SCHEMA = [
        'track' => ['bool', 'Track carts', true,
            'Records every add, remove and quantity change with the time, product, price, address and country. Off, nothing new is written; what is already recorded stays.'],
        'bots_leave' => ['bool', 'Ask bots to leave', true,
            'Scripted clients (curl, python), headless browsers and scrapers are answered "403 Forbidden" on the cart and checkout. Google, Bing, Apple, Facebook and WhatsApp previews are never refused, and no page a shopper browses is affected.'],
        'block_scope' => ['select', 'What a blocked address cannot do', 'commerce',
            'Cart & checkout: a blocked address can still read the shop, but cannot add to cart, check out, place any order (cash on delivery included) or submit any form. Whole shop: every storefront page answers 403. The admin is never blocked.',
            ['commerce' => 'Cart, checkout and forms', 'site' => 'The whole storefront']],
        'keep_days' => ['range', 'Keep cart events for', 180,
            'Events older than this are deleted in small batches. Carts, their totals and their orders are kept for ever; the product counts on the Added/Removed tabs keep counting what was deleted.',
            ['min' => 30, 'max' => 730, 'step' => 10, 'unit' => ' days']],
        'bot_threshold' => ['range', 'Call it a bot from a score of', 50,
            'Each signal adds points (shown on every cart). At or above this score the cart reads Bot: Yes.',
            ['min' => 20, 'max' => 100, 'step' => 5, 'unit' => '']],
        'speed_ms' => ['range', 'Too fast: added within', 1500,
            'An add-to-cart this soon after the page opened is faster than a person reads a product.',
            ['min' => 200, 'max' => 5000, 'step' => 100, 'unit' => ' ms']],
        'burst_minutes' => ['range', 'Count carts from one place over', 60,
            'The window for the two "many carts" rules below.',
            ['min' => 5, 'max' => 1440, 'step' => 5, 'unit' => ' min']],
        'burst_ip' => ['range', 'Many carts from one address', 5,
            'This many carts (or more) from the same address inside the window above.',
            ['min' => 2, 'max' => 50, 'step' => 1, 'unit' => ' carts']],
        'burst_net' => ['range', 'Many carts from one range (/24)', 15,
            'This many carts (or more) from the same /24 (IPv6 /64) inside the window above.',
            ['min' => 3, 'max' => 200, 'step' => 1, 'unit' => ' carts']],
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (array_keys(self::SCHEMA) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        $def = self::SCHEMA[$key] ?? null;

        if ($def === null) {
            return null;
        }

        $saved = $this->settings->get(self::PREFIX.$key, null);

        return $saved === null ? $def[2] : self::cast($key, $saved);
    }

    /**
     * Every value, read from the `settings` table itself rather than through
     * SettingsService's cache — for IpBlockList::rebuild(), which runs from a
     * Setting::saved hook: SettingsService::set() fires that hook BEFORE it
     * forgets its cached copy, so reading through the cache there compiles the
     * value from before the save. One query, on a write, never on a request.
     *
     * @return array<string, mixed>
     */
    public static function fromDatabase(): array
    {
        $saved = \App\Models\Setting::query()
            ->whereIn('key', array_map(fn ($k) => self::PREFIX.$k, array_keys(self::SCHEMA)))
            ->pluck('value', 'key')
            ->all();

        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $raw = $saved[self::PREFIX.$key] ?? null;
            $out[$key] = $raw === null ? $def[2] : self::cast($key, $raw);
        }

        return $out;
    }

    /** @param array<string, mixed> $values  unknown keys are ignored */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set(self::PREFIX.$key, self::cast((string) $key, $value));
            }
        }
    }

    /** The bounds a value must sit inside, applied on the way in AND out. */
    public static function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key];

        return match ($def[0]) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $def[2],
            'select' => is_string($value) && isset($def[4][$value]) ? $value : $def[2],
            'range' => is_numeric($value)
                ? max($def[4]['min'], min($def[4]['max'], (int) round((float) $value)))
                : $def[2],
            default => $def[2],
        };
    }

    /** The schema as the screen draws it. @return list<array<string, mixed>> */
    public static function fields(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $row = ['key' => $key, 'type' => $def[0], 'label' => $def[1], 'default' => $def[2], 'help' => $def[3]];

            if ($def[0] === 'range') {
                $row += $def[4];
            } elseif ($def[0] === 'select') {
                $row['options'] = $def[4];
            }

            $out[] = $row;
        }

        return $out;
    }
}
