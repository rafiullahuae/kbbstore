<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Services\SettingsService;

/**
 * Emails → Customer emails → Review request → Schedule & rules (Lane RV).
 *
 * The owner, 10 October: "i need another email template, which will be sent
 * after 2 days of order completion, as feedback ask ... give me full controls
 * also". Every number and switch the review request reads is here, with its
 * default, its bounds and the one place it is cleaned. A value from the outside
 * world (the admin form, a hand-edited settings row) goes through clean() and
 * nothing else, so a box typed "-5" or "999" stores the nearest bound and a
 * select stores one of its own options or the default (CLAUDE.md rule 5).
 *
 * THE ON/OFF IS NOT HERE. It is the module toggle `email_review_request`,
 * the same kind of switch every other customer email has, flipped from the
 * Customer emails list (CustomerEmails::SWITCHES). It ships ON because the
 * owner asked for the email; see ReviewRequests::record() for why that does
 * not mail any order completed before the package.
 *
 * READ THROUGH SettingsService::get(), which reads the settings map once per
 * request (SettingsRequestMemo). Nothing here queries.
 */
final class ReviewRequestSettings
{
    public const MODULE = 'email_review_request';

    /** The colours a star may be drawn in. Constants: printed into a style attribute. */
    public const STAR_COLOURS = [
        'gold' => ['Gold', '#F2A900'],
        'pink' => ['Pink', '#E0567B'],
        'rose' => ['Deep rose', '#C13E63'],
        'ink' => ['Ink', '#2A2228'],
        'green' => ['Green', '#2E9E6B'],
    ];

    /**
     * key => [type, default, min|options, max, label]
     *
     * @var array<string, array{0:string,1:int|bool|string,2?:int|array,3?:int,4?:string}>
     */
    public const FIELDS = [
        'review_request_days' => ['int', 2, 1, 60, 'Days after the order is completed'],
        'review_request_max_products' => ['int', 3, 1, 6, 'Most products in one email'],
        'review_request_link_days' => ['int', 60, 7, 365, 'Days the review link keeps working'],
        'review_request_cooldown_days' => ['int', 0, 0, 365, 'Skip a customer asked in the last … days (0 = ask on every order)'],
        'review_request_skip_refunded' => ['bool', true, 0, 0, 'Skip an order with any refund'],
        'review_request_require_completed' => ['bool', true, 0, 0, 'Skip an order no longer Completed when it is due (cancelled, refunded, reopened)'],
        'review_request_skip_reviewed' => ['bool', true, 0, 0, 'Leave out products this customer has already reviewed'],
        'review_request_star_colour' => ['select', 'gold', 0, 0, 'Star colour'],
    ];

    /**
     * An order whose request has waited this many days past its due time is
     * skipped rather than sent late — the first sweep after a long quiet
     * spell (no traffic, no cron) must not write to last month's customers.
     */
    public const GIVE_UP_DAYS = 14;

    public function __construct(private SettingsService $settings) {}

    public function enabled(): bool
    {
        // The literal, not self::MODULE: ModuleFrameworkGuardTest finds a
        // module's readers by its key spelled out at the call.
        return $this->settings->moduleEnabled('email_review_request', true);
    }

    /** Every value, cleaned. @return array<string, int|bool|string> */
    public function all(): array
    {
        $out = [];

        foreach (array_keys(self::FIELDS) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    public function get(string $key): int|bool|string
    {
        $def = self::FIELDS[$key] ?? null;

        if ($def === null) {
            throw new \InvalidArgumentException('Unknown review request setting.');
        }

        return self::clean($key, $this->settings->get($key, $def[1]));
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** The star colour as #rrggbb — always one of STAR_COLOURS. */
    public function starHex(): string
    {
        return self::STAR_COLOURS[(string) $this->get('review_request_star_colour')][1];
    }

    /**
     * Store what the form sent, cleaned. Keys it does not know are ignored.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, int|bool|string> every value as now stored
     */
    public function save(array $input): array
    {
        foreach (self::FIELDS as $key => $def) {
            if (array_key_exists($key, $input)) {
                $this->settings->set($key, self::clean($key, $input[$key]));
            }
        }

        SettingsService::forgetMemo();

        return $this->all();
    }

    /** One value from anywhere, made safe. */
    public static function clean(string $key, mixed $value): int|bool|string
    {
        [$type, $default] = self::FIELDS[$key];

        if ($type === 'bool') {
            if (is_bool($value)) {
                return $value;
            }

            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            return $parsed ?? (bool) $default;
        }

        if ($type === 'select') {
            return is_string($value) && isset(self::STAR_COLOURS[$value]) ? $value : (string) $default;
        }

        [, , $min, $max] = self::FIELDS[$key];

        if (! is_numeric($value)) {
            return (int) $default;
        }

        return max((int) $min, min((int) $max, (int) $value));
    }

    /** The form's description of itself, for the admin screen. */
    public static function schema(): array
    {
        $out = [];

        foreach (self::FIELDS as $key => [$type, $default, $min, $max, $label]) {
            $row = ['key' => $key, 'type' => $type, 'default' => $default, 'label' => $label];

            if ($type === 'int') {
                $row += ['min' => $min, 'max' => $max];
            }

            if ($type === 'select') {
                $row['options'] = array_map(
                    static fn (string $k, array $v) => ['value' => $k, 'label' => $v[0], 'hex' => $v[1]],
                    array_keys(self::STAR_COLOURS),
                    self::STAR_COLOURS,
                );
            }

            $out[] = $row;
        }

        return $out;
    }
}
