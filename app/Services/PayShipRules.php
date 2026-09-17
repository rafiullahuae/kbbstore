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
    /** The ModuleRegistry key, and the `module_settings.module` value. */
    public const MODULE = 'pay_ship_rules';

    /**
     * MIGRATED ONTO App\Services\ModuleSchema (Lane EH).
     *
     * The positional `[type, label, default, help]` form this constant used is
     * still accepted — ModuleSchema::field() widens it — so what changed here
     * is the addition of `store`, which records the one thing the old shape
     * could not say: WHERE each value lives.
     *
     * It matters on this module more than most, because the three fields do not
     * agree. `cod_min` and `cod_max` are the module's own and live in
     * `module_settings`; `hide_paid_free` is an ALIAS for `hide_paid_when_free`,
     * a pre-existing key in the global `settings` table that
     * ShippingService::ratesFor() has read since before this module existed.
     * That divergence used to live in save(), as a loop over two named keys and
     * an `if` for the third, where nothing could check it. Naming it in the
     * schema is what lets one value keep one control instead of growing a
     * rival key beside it — which is the reasoning in this class's own header,
     * now written where the renderer and the guard can both read it.
     *
     * `setting` and not `admin`: this screen's own endpoint saves it, so
     * AdminController::updateSettings() — and its SETTING_RULES list, which
     * drops anything not on it — is not in the path.
     */
    public const SCHEMA = [
        'cod_min'        => ['type' => 'money', 'label' => 'Hide Cash on delivery below', 'default' => 0, 'help' => 'Zero means no lower limit.', 'store' => ModuleSchema::STORE_MODULE],
        'cod_max'        => ['type' => 'money', 'label' => 'Hide Cash on delivery above', 'default' => 0, 'help' => 'Zero means no upper limit.', 'store' => ModuleSchema::STORE_MODULE],
        /*
         * THE HELP TEXT NAMED A SCREEN THAT DOES NOT EXIST (Lane DN).
         *
         * It read "The same setting as Store → Ecommerce → Delivery. Changing
         * it here changes it there." The first half describes the intent of
         * this class correctly — `hide_paid_when_free` is edited here and
         * nowhere else, deliberately, so that one value never grows two
         * controls. The second half sent the owner looking for a second
         * control to cross-check against, and there is none: Store → Ecommerce
         * has no Delivery tab carrying this key, and nothing in
         * resources/views/admin/app.blade.php writes it but this screen.
         *
         * So the note now says what the switch DOES, in the words a shopper
         * would see it in, which is what an owner deciding whether to turn it
         * on actually needs. Where it is edited is answered by the fact that
         * he is looking at it.
         */
        'hide_paid_free' => ['type' => 'bool', 'label' => 'Only offer free delivery when it is available', 'default' => true, 'help' => 'When an order already qualifies for free delivery, hide the paid delivery options instead of listing them beside it.', 'store' => ModuleSchema::STORE_SETTING, 'alias' => self::FREE_KEY],
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

        // Each field read from wherever its own `store` says it lives, rather
        // than from a hand-written list that has to be kept in step with the
        // schema above. cod_min/cod_max come from module_settings, hide_paid_free
        // from the aliased global key.
        return $this->cache = ModuleSchema::read($this->settings, self::MODULE, self::SCHEMA);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string> the fields refused, label by key
     */
    public function save(array $values): array
    {
        $result = ModuleSchema::write($this->settings, self::MODULE, self::SCHEMA, $values);

        $this->cache = null;

        return $result['rejected'];
    }

    /**
     * Should Cash on delivery be offered for this order total?
     *
     * Total in fils, as everything else in this app. Returns true when the
     * module is off, so a shop that never turns it on is unaffected.
     */
    public function codAllowed(int $totalFils): bool
    {
        /*
         * THE KEY IS SPELLED OUT AND NOT self::MODULE, DELIBERATELY — Lane EH.
         *
         * It was briefly the constant, which reads better and quietly broke the
         * thing that keeps this registry honest: ModuleFrameworkGuardTest finds
         * a module's readers by TOKENISING for `moduleEnabled('<key>')` with a
         * literal argument, because that is all static analysis can see. Behind
         * a constant this gate became invisible to it, and the `live` row for
         * pay_ship_rules was left resting on the admin screen's status flag
         * instead — which is the precise shape of the defect the guard exists
         * to catch, introduced by tidying.
         *
         * A storefront gate is written as a literal here. Where the key is used
         * as DATA rather than as a gate — the module_settings rows read and
         * written through ModuleSchema — the constant is used as normal.
         */
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
