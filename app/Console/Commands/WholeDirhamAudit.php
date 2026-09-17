<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\WholeDirhams;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What in this shop still carries fils, what it would become, and what that
 * costs — reported. Changed only when asked, out loud, with a flag.
 *
 * ── WHY THIS IS A REPORT AND NOT A MIGRATION ───────────────────────────────
 *
 * The whole-dirham policy (App\Support\WholeDirhams) stops new fils arriving.
 * It says nothing about the fils already in the database, and there are some:
 * this catalogue came out of WooCommerce, where 99.80 was an ordinary price.
 *
 * A migration that rounded them on the day the package landed would be the
 * owner waking up to prices he did not set, on a live shop, with no record of
 * what they used to be and no way to tell which had moved. That is a worse
 * outcome than a price with fils in it — a price with fils in it is merely
 * untidy, and the display side of this lane prints it honestly.
 *
 * So the default is a report. `--fix` is the second command, typed
 * deliberately, and even then it never touches an ORDER: see below.
 *
 * ── WHAT IS NEVER TOUCHED, ON ANY FLAG ─────────────────────────────────────
 *
 * ORDERS, ORDER ITEMS, REFUNDS AND PAYMENTS. An order is a record of what a
 * customer was actually charged and what the shop actually took. Rounding a
 * historical total would make the order disagree with the card capture, the
 * invoice already emailed, the payment provider's own record and the shop's
 * books, all at once, and none of those can be rounded to match. They are
 * COUNTED here, because the owner should know how many receipts will print
 * with fils in them for ever, and they are never written.
 *
 * ── WHAT --fix DOES TOUCH ──────────────────────────────────────────────────
 *
 * The configuration and the catalogue: prices, sale prices, variant prices,
 * fixed coupon amounts and spend thresholds, delivery rates and free-delivery
 * thresholds, and the money settings. Every one of those is a figure the owner
 * sets and can set again; none of them is a record of something that already
 * happened.
 *
 * NEAREST, half away from zero, and the same direction everywhere. The lane's
 * other roundings pick a side on purpose — a coupon discount rounds up because
 * it is a promise to a shopper, a bundle unit rounds down because it is a
 * discount off a shelf price — but this one is a bulk correction of the
 * owner's own figures with no counterparty on the other side of any single
 * row, and the honest rounding of a number with no claim on it is the closest
 * one. It is also the only direction that does not move the whole price list
 * one way, which matters when it is applied to a catalogue rather than to a
 * basket. The net effect is reported in both directions so the owner can see
 * what it did to his prices in total.
 */
class WholeDirhamAudit extends Command
{
    protected $signature = 'kbb:whole-dirhams
                            {--fix : Adjust what is listed to the nearest whole dirham. Orders are never touched.}
                            {--limit=20 : How many example rows to print per group.}';

    protected $description = 'Report every stored amount that carries fils, and what making it whole would cost.';

    /** The `settings` rows that hold money, and what they hold it in. */
    private const SETTING_KEYS = [
        'free_ship' => ['Free-shipping threshold', 'minor'],
        'delivery_flat' => ['Flat delivery charge', 'minor'],
        'cod_fee' => ['Cash-on-delivery fee', 'minor'],
        'gift_fee' => ['Gift-wrap fee', 'minor'],
        'merchant_ship_cost' => ['Merchant shipping cost', 'major'],
        'merchant_ship_free_over' => ['Merchant free-shipping threshold', 'major'],
    ];

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $limit = max(1, (int) $this->option('limit'));

        if (WholeDirhams::unit() <= 1) {
            $this->info('This shop\'s currency (' . Money::currency() . ') has no minor unit, so every amount is already whole.');

            return self::SUCCESS;
        }

        $this->line('Whole-' . WholeDirhams::plural() . ' audit — ' . Money::currency()
            . ', ' . WholeDirhams::unit() . ' minor units to the ' . rtrim(WholeDirhams::plural(), 's') . '.');
        $this->newLine();

        $movement = 0;
        $rows = 0;

        /*
         * Counted separately because it is reported separately below: a coupon
         * carrying fils is the one entry on this list that is costing money
         * right now rather than merely printing oddly. Keyed off the group's
         * own label so it cannot drift from what couponGroup() returns.
         */
        $couponsWithFils = 0;

        foreach ($this->groups() as $group) {
            [$found, $delta] = $this->report($group, $fix, $limit);
            $rows += $found;
            $movement += $delta;

            if ($group['label'] === 'Coupons') {
                $couponsWithFils = $found;
            }
        }

        $this->newLine();
        $this->reportOrders($limit);

        $this->newLine();

        if ($rows === 0) {
            $this->info('Nothing carries fils. Every configured amount in this shop is a whole ' . rtrim(WholeDirhams::plural(), 's') . '.');

            return self::SUCCESS;
        }

        $this->line($rows . ' ' . ($rows === 1 ? 'value carries' : 'values carry') . ' fils.');
        $this->line('Making them whole would move ' . Money::currency() . ' '
            . Money::decimalString(abs($movement))
            . ($movement >= 0 ? ' UP' : ' DOWN') . ' in total.');

        /*
         * A FIXED-AMOUNT COUPON CARRYING FILS IS ALREADY COSTING MONEY, and
         * that has to be said louder than the rest of the list.
         *
         * Everything else here is a figure that merely prints oddly until it is
         * fixed. A coupon is not: CouponService::discountFor() rounds a
         * discount UP, so an imported fixed_cart coupon of AED 99.50 hands back
         * AED 100.00 on every single order while Store -> Coupons shows 99.50.
         * The owner is paying the difference on every use, and the screen he
         * would check to find out disagrees with the basket.
         *
         * Only when there is one, and only on the reporting path — a --fix run
         * has just settled them.
         */
        if ($couponsWithFils > 0 && ! $fix) {
            $this->newLine();
            $this->warn($couponsWithFils . ' of those ' . ($couponsWithFils === 1 ? 'is a coupon' : 'are coupons')
                . ', which is the expensive kind.');
            $this->line('A fixed-amount coupon carrying fils is rounded UP when it is spent, so it gives away more');
            $this->line('than Store -> Coupons shows — on every order it is used on, until it is made whole.');
        }

        if (! $fix) {
            $this->newLine();
            $this->comment('Nothing was changed. Run again with --fix to adjust everything above.');
            $this->comment('Orders are never adjusted, on any flag.');
        }

        return self::SUCCESS;
    }

    /**
     * Every group this command knows how to look at.
     *
     * A group is a label, a list of [description, current fils] pairs, and a
     * closure that writes one back. Expressed this way so that reporting and
     * fixing are the same walk over the same rows — a --fix that visited a
     * different set from the report it printed would be the worst possible
     * version of this command.
     *
     * @return list<array{label:string, rows:list<array{what:string, fils:int, write:callable}>}>
     */
    private function groups(): array
    {
        return [
            $this->modelGroup('Product prices', Product::query(), ['price' => 'price', 'sale_price' => 'sale price']),
            $this->modelGroup('Variant prices', ProductVariant::query(), ['price' => 'price', 'sale_price' => 'sale price']),
            $this->couponGroup(),
            $this->shippingGroup(),
            $this->settingsGroup(),
            $this->moduleGroup(),
        ];
    }

    /**
     * A group built from a model's money columns.
     *
     * @param  array<string, string>  $columns  column => how to name it
     */
    private function modelGroup(string $label, $query, array $columns): array
    {
        $rows = [];

        // Filtered in PHP rather than with a modulo in SQL: MySQL and SQLite
        // both have `%`, but the catalogue is a few thousand rows and a walk
        // here is one query with no dialect risk. See docs/MYSQL-PARITY.md for
        // why this repository does not spend cleverness it does not need.
        foreach ($query->clone()->get() as $model) {
            foreach ($columns as $column => $noun) {
                $value = $model->{$column};

                if ($value === null || WholeDirhams::isWhole((int) $value)) {
                    continue;
                }

                $rows[] = [
                    'what' => '#' . $model->id . ' ' . ($model->name ?? $model->sku ?? '') . ' — ' . $noun,
                    'fils' => (int) $value,
                    'write' => function (int $to) use ($model, $column): void {
                        $model->{$column} = $to;
                        $model->save();
                    },
                ];
            }
        }

        return ['label' => $label, 'rows' => $rows];
    }

    /**
     * Coupons — the fixed types only.
     *
     * `coupons.amount` is hundredths of a PERCENT on a percentage coupon, so
     * reading it as money there would report 10.5% as "AED 0.105 carries fils"
     * and --fix would turn a 10.5% sale into an 11% one. The thresholds are
     * money on every type.
     */
    private function couponGroup(): array
    {
        $rows = [];

        foreach (Coupon::query()->get() as $coupon) {
            $fields = [
                'minimum_amount' => 'minimum spend',
                'maximum_amount' => 'maximum spend',
            ];

            if ($coupon->type !== 'percent') {
                $fields['amount'] = 'amount';
            }

            foreach ($fields as $column => $noun) {
                $value = $coupon->{$column};

                if ($value === null || WholeDirhams::isWhole((int) $value)) {
                    continue;
                }

                $rows[] = [
                    'what' => $coupon->code . ' — ' . $noun,
                    'fils' => (int) $value,
                    'write' => function (int $to) use ($coupon, $column): void {
                        $coupon->{$column} = $to;
                        $coupon->save();
                    },
                ];
            }
        }

        return ['label' => 'Coupons', 'rows' => $rows];
    }

    private function shippingGroup(): array
    {
        $rows = [];

        foreach (ShippingMethod::query()->get() as $method) {
            foreach (['cost' => 'delivery charge', 'min_amount' => 'free-delivery threshold'] as $column => $noun) {
                $value = $method->{$column};

                if ($value === null || WholeDirhams::isWhole((int) $value)) {
                    continue;
                }

                $rows[] = [
                    'what' => $method->title . ' — ' . $noun,
                    'fils' => (int) $value,
                    'write' => function (int $to) use ($method, $column): void {
                        $method->{$column} = $to;
                        $method->save();
                    },
                ];
            }
        }

        return ['label' => 'Delivery rates', 'rows' => $rows];
    }

    /**
     * The `settings` money rows.
     *
     * Two conventions, and the difference is a hundredfold error if it is got
     * wrong: `free_ship` and friends are stored in MINOR units (the screen
     * multiplies before posting), while `merchant_ship_cost` and
     * `merchant_ship_free_over` are stored as a MAJOR-unit decimal string,
     * because App\Support\Seo compares them against a price in major units.
     * AdminController::SETTING_RULES records both; this reads them the same
     * way round.
     */
    private function settingsGroup(): array
    {
        $rows = [];
        $stored = Setting::query()->whereIn('key', array_keys(self::SETTING_KEYS))->pluck('value', 'key');

        foreach (self::SETTING_KEYS as $key => [$label, $scale]) {
            $raw = $stored[$key] ?? null;

            if ($raw === null || trim((string) $raw) === '') {
                continue;
            }

            $fils = $scale === 'major'
                ? $this->majorTextToFils((string) $raw)
                : (int) $raw;

            if ($fils === null || WholeDirhams::isWhole($fils)) {
                continue;
            }

            $rows[] = [
                'what' => $label . ' (' . $key . ')',
                'fils' => $fils,
                'write' => function (int $to) use ($key, $scale): void {
                    app(SettingsService::class)->set(
                        $key,
                        $scale === 'major' ? Money::decimalString($to) : (string) $to
                    );
                },
            ];
        }

        return ['label' => 'Store settings', 'rows' => $rows];
    }

    /** PayShipRules' two Cash-on-delivery bounds, in `module_settings`. */
    private function moduleGroup(): array
    {
        $rows = [];

        $stored = DB::table('module_settings')
            ->where('module', 'pay_ship_rules')
            ->whereIn('key', ['cod_min', 'cod_max'])
            ->pluck('value', 'key');

        foreach (['cod_min' => 'Hide Cash on delivery below', 'cod_max' => 'Hide Cash on delivery above'] as $key => $label) {
            $raw = $stored[$key] ?? null;

            if ($raw === null || trim((string) $raw) === '' || WholeDirhams::isWhole((int) $raw)) {
                continue;
            }

            $rows[] = [
                'what' => $label . ' (' . $key . ')',
                'fils' => (int) $raw,
                'write' => function (int $to) use ($key): void {
                    app(SettingsService::class)->setModuleSetting('pay_ship_rules', $key, $to);
                },
            ];
        }

        return ['label' => 'Payment & shipping rules', 'rows' => $rows];
    }

    /**
     * Print one group, and write it when asked.
     *
     * @param  array{label:string, rows:list<array{what:string, fils:int, write:callable}>}  $group
     * @return array{0:int, 1:int}  how many rows, and the net movement in fils
     */
    private function report(array $group, bool $fix, int $limit): array
    {
        $rows = $group['rows'];

        if ($rows === []) {
            $this->line('  ' . str_pad($group['label'], 28) . ' clean');

            return [0, 0];
        }

        $this->newLine();
        $this->line('  ' . $group['label'] . ' — ' . count($rows) . ' carrying fils');

        $movement = 0;
        $shown = 0;

        foreach ($rows as $row) {
            $to = WholeDirhams::nearest($row['fils']);
            $movement += $to - $row['fils'];

            if ($shown < $limit) {
                $this->line(sprintf(
                    '    %-52s %12s -> %-12s (%s%s)',
                    mb_strimwidth($row['what'], 0, 52, '…'),
                    Money::decimalString($row['fils']),
                    Money::decimalString($to),
                    $to >= $row['fils'] ? '+' : '-',
                    Money::decimalString(abs($to - $row['fils']))
                ));
                $shown++;
            }

            if ($fix) {
                ($row['write'])($to);
            }
        }

        if (count($rows) > $shown) {
            $this->line('    … and ' . (count($rows) - $shown) . ' more.');
        }

        if ($fix) {
            $this->info('    adjusted.');
        }

        return [count($rows), $movement];
    }

    /**
     * Orders: counted and never written. See the class header.
     *
     * Reported as a separate section rather than as a "clean/not clean" group
     * so it cannot be read as something --fix forgot to do.
     */
    private function reportOrders(int $limit): void
    {
        $unit = WholeDirhams::unit();

        $orders = DB::table('orders')
            ->whereRaw('total % ? <> 0', [$unit])
            ->count();

        $items = DB::table('order_items')
            ->whereRaw('unit_price % ? <> 0', [$unit])
            ->count();

        if ($orders === 0 && $items === 0) {
            $this->line('  ' . str_pad('Orders (never adjusted)', 28) . ' clean');

            return;
        }

        $this->newLine();
        $this->line('  Orders — ' . $orders . ' ' . ($orders === 1 ? 'total' : 'totals')
            . ' and ' . $items . ' line ' . ($items === 1 ? 'price' : 'prices') . ' carry fils.');
        $this->comment('    These are records of what customers were actually charged.');
        $this->comment('    They are NEVER adjusted, on any flag — an order rounded to match a policy');
        $this->comment('    would disagree with the card capture and the invoice already sent.');
        $this->comment('    Their receipts print at full precision, which is the honest outcome.');
    }

    /** "12.50" -> 1250, by integer arithmetic. Null when it is not an amount. */
    private function majorTextToFils(string $text): ?int
    {
        $text = trim($text);

        if (preg_match('/^\d+(?:\.\d{1,' . Money::minorExponent() . '})?$/', $text) !== 1) {
            return null;
        }

        $parts = explode('.', $text, 2);
        $fraction = str_pad($parts[1] ?? '', Money::minorExponent(), '0');

        return ((int) $parts[0]) * WholeDirhams::unit() + (int) ($fraction === '' ? '0' : $fraction);
    }
}
