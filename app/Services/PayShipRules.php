<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Payment & Shipping Rules — ported from KBB Modules v2.39.0, lines 423–442.
 *
 * Two rules:
 *
 *  1. Hide Cash on delivery when the order total falls outside a window. Zero
 *     means no limit at that end, which is the plugin's convention: `$min > 0 &&
 *     $total < $min`, so a min of zero never hides anything.
 *  2. When free delivery is available, offer only free delivery.
 *
 * The second rule already existed here as the `hide_paid_when_free` setting,
 * read by ShippingService::ratesFor(). This module edits **that same key**
 * rather than introducing a second one — two controls for one value is how a
 * setting ends up half working, and this app already has four switch systems
 * without adding a fifth key for something it can already do.
 */
class PayShipRules
{
    public const SCHEMA = [
        'cod_min'        => ['money', 'Hide Cash on delivery below', 0, 'Zero means no lower limit.'],
        'cod_max'        => ['money', 'Hide Cash on delivery above', 0, 'Zero means no upper limit.'],
        'hide_paid_free' => ['bool',  'Only offer free delivery when it is available', true, 'The same setting as Store → Ecommerce → Delivery. Changing it here changes it there.'],
    ];

    public const TABS = [
        'rules' => ['Rules', 'When to hide Cash on delivery, and what to do when free delivery applies.',
                    ['cod_min', 'cod_max', 'hide_paid_free']],
    ];

    /** The pre-existing key the shipping service reads. Not a new one. */
    private const FREE_KEY = 'hide_paid_when_free';

    private ?array $cache = null;

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        return $this->cache = [
            'cod_min' => (int) $this->settings->moduleSetting('pay_ship_rules', 'cod_min', 0),
            'cod_max' => (int) $this->settings->moduleSetting('pay_ship_rules', 'cod_max', 0),
            'hide_paid_free' => (bool) $this->settings->get(self::FREE_KEY, true),
        ];
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach (['cod_min', 'cod_max'] as $key) {
            if (array_key_exists($key, $values)) {
                $this->settings->setModuleSetting('pay_ship_rules', $key, max(0, (int) $values[$key]));
            }
        }

        if (array_key_exists('hide_paid_free', $values)) {
            $this->settings->set(self::FREE_KEY, (bool) $values['hide_paid_free']);
        }

        $this->cache = null;
    }

    /**
     * Should Cash on delivery be offered for this order total?
     *
     * Total in fils, as everything else in this app. Returns true when the
     * module is off, so a shop that never turns it on is unaffected.
     */
    public function codAllowed(int $totalFils): bool
    {
        if (! $this->settings->moduleEnabled('pay_ship_rules', false)) {
            return true;
        }

        $c = $this->all();

        if ($c['cod_min'] > 0 && $totalFils < $c['cod_min']) {
            return false;
        }

        return ! ($c['cod_max'] > 0 && $totalFils > $c['cod_max']);
    }

    /** Why COD is missing, for the checkout to explain rather than silently drop it. */
    public function codHiddenReason(int $totalFils): ?string
    {
        if ($this->codAllowed($totalFils)) {
            return null;
        }

        $c = $this->all();

        if ($c['cod_min'] > 0 && $totalFils < $c['cod_min']) {
            return 'Cash on delivery is available on orders over ' . \App\Support\Money::format($c['cod_min']) . '.';
        }

        return 'Cash on delivery is not available on orders over ' . \App\Support\Money::format($c['cod_max']) . '.';
    }
}
