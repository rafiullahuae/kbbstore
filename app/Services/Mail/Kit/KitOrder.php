<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use App\Services\Mail\OrderEmailPresenter;
use Illuminate\Support\HtmlString;

/**
 * An order, as the approved kit's blocks want it — Lane EM.
 *
 * Every order email (confirmation, the two reminders, every status email, the
 * refund, the merchant alert) is handed OrderEmailPresenter::present() as
 * `$order`. This class turns that one array into what the kit partials in
 * resources/views/emails/kit/ print: product lines with their pictures, the
 * money rows and the grand total, the delivery address, the four tracker
 * steps. It reads nothing else off the order, so the kit cannot disagree with
 * the presenter about what was bought or what it cost — the receipt's facts
 * are still decided in exactly one place.
 *
 * Plain strings throughout: the partials print with {{ }}. The one HtmlString
 * (the address) is built here from escaped lines joined with <br>, which is
 * the only markup in it.
 *
 * ONE QUERY for the pictures, whatever the number of lines
 * (KitProducts::imagesForIds), and none at all when no line has a product id.
 */
final class KitOrder
{
    /**
     * Placed → Confirmed → Shipped → Delivered, the approved tracker's labels.
     *
     * @return list<string>
     */
    public static function steps(): array
    {
        return [
            __('email.kit.step_placed'),
            __('email.kit.step_confirmed'),
            __('email.kit.step_shipped'),
            __('email.kit.step_delivered'),
        ];
    }

    /**
     * The kit's product lines, each with the product's own picture.
     *
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    public static function lines(array $order, ?array $products = null): array
    {
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $products ??= self::products($order);
        $images = array_map(static fn (array $p) => $p['img'], $products);

        $out = [];

        foreach ($items as $item) {
            $id = (int) ($item['productId'] ?? 0);

            $out[] = [
                'img' => $images[$id] ?? null,
                'brand' => (string) ($item['brand'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'variant' => (string) ($item['variant'] ?? ''),
                'qty' => (int) ($item['quantity'] ?? 0),
                'unit' => (string) ($item['unitPlain'] ?? ''),
                'total' => (string) ($item['linePlain'] ?? ''),
                'sub' => array_values(array_map('strval', (array) ($item['setContents'] ?? []))),
            ];
        }

        return $out;
    }

    /**
     * The order's products' pictures and routine steps, by id — the one
     * catalogue statement an order email makes. Computed once per render by
     * emails/kit/order.blade.php and handed to lines() and howTo().
     *
     * @param  array<string, mixed>  $order
     * @return array<int, array{img: string|null, role: string|null}>
     */
    public static function products(array $order): array
    {
        return KitProducts::forIds(array_map(
            static fn (array $item): int => (int) ($item['productId'] ?? 0),
            is_array($order['items'] ?? null) ? $order['items'] : [],
        ));
    }

    /**
     * "How to use them together: Cleanse (COSRX) → Tone (Anua) → Treat
     * (Beauty of Joseon), in this order." — the delivered email's last line in
     * the approved preview 06, built from the routine step the owner tagged
     * each product with (Catalog → Build my routine, RoutineRoles). Null
     * unless at least two DIFFERENT steps are tagged: one product is not a
     * routine, and an untagged basket gets no invented order.
     *
     * @param  array<string, mixed>  $order
     * @param  array<int, array{img: string|null, role: string|null}>  $products
     */
    public static function howTo(array $order, array $products): ?\Illuminate\Support\HtmlString
    {
        $steps = [];

        foreach ((array) ($order['items'] ?? []) as $item) {
            $role = $products[(int) ($item['productId'] ?? 0)]['role'] ?? null;

            if ($role === null || isset($steps[$role])) {
                continue;
            }

            $who = trim((string) ($item['brand'] ?? '')) !== '' ? (string) $item['brand'] : (string) ($item['name'] ?? '');
            $steps[$role] = e(__(\App\Support\RoutineRoles::labelKey($role))) . ' (' . e($who) . ')';
        }

        if (count($steps) < 2) {
            return null;
        }

        uksort($steps, static fn (string $a, string $b) => \App\Support\RoutineRoles::position($a) <=> \App\Support\RoutineRoles::position($b));

        return new \Illuminate\Support\HtmlString('<b>' . e(__('email.kit.howto_heading')) . '</b> '
            . implode(' &rarr; ', $steps) . e(__('email.kit.howto_tail')));
    }

    /**
     * The money rows above the grand total, as [label, value, accent].
     *
     * The presenter's own rows, minus its final "Total" (the kit draws that
     * one larger, in the brand pink). A row that came OFF the bill — a coupon,
     * a bundle — is printed "− AED 35.55" in green, as the approved design
     * does; a delivery of nothing is "Free".
     *
     * @param  array<string, mixed>  $order
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public static function rows(array $order): array
    {
        $width = isset($order['ledgerWidth']) ? (int) $order['ledgerWidth'] : null;
        $out = [];

        foreach ((array) ($order['totals'] ?? []) as $row) {
            if (! empty($row['strong'])) {
                continue;
            }

            $fils = (int) ($row['fils'] ?? 0);
            $label = (string) ($row['label'] ?? '');

            if ($fils < 0) {
                $out[] = [$label, '− ' . OrderEmailPresenter::plain(abs($fils), $width), true];

                continue;
            }

            if ($fils === 0 && $label === __('email.totals.delivery')) {
                $out[] = [$label, __('email.kit.free'), false];

                continue;
            }

            $out[] = [$label, (string) ($row['plain'] ?? ''), false];
        }

        return $out;
    }

    /**
     * The grand total row: [label, amount, the small line under it].
     *
     * $due is the unpaid shape the two reminders and the payment-failed email
     * use ("Total to pay", "Not paid yet"). Otherwise the note says how it was
     * paid — only when it WAS paid; a cash-on-delivery order names its method.
     * An included-VAT note rides on the same small line.
     *
     * @param  array<string, mixed>  $order
     * @return array{0: string, 1: string, 2: string}
     */
    public static function grand(array $order, bool $due = false, bool $paid = false): array
    {
        $method = trim((string) ($order['paymentLabel'] ?? ''));

        if ($due) {
            return [__('email.kit.total_to_pay'), (string) ($order['totalPlain'] ?? ''), __('email.reminder.not_paid')];
        }

        $note = $paid && $method !== '' ? __('email.kit.paid_with', ['method' => $method]) : $method;

        $vat = $order['vatNote'] ?? null;

        if (is_array($vat) && ($vat['label'] ?? '') !== '') {
            $note = trim($note . ($note !== '' ? ' · ' : '') . $vat['label'] . ' ' . ($vat['plain'] ?? ''));
        }

        return [__('email.totals.total'), (string) ($order['totalPlain'] ?? ''), $note];
    }

    /**
     * The delivery address, one escaped line per <br>.
     *
     * @param  array<string, mixed>  $order
     */
    public static function address(array $order): HtmlString
    {
        $lines = array_map(
            static fn ($line): string => e((string) $line),
            (array) ($order['address'] ?? []),
        );

        return new HtmlString($lines === [] ? '&mdash;' : implode('<br>', $lines));
    }

    /**
     * The infoPair the approved order emails carry: where it is going on the
     * left; how it is coming and how it was paid on the right.
     *
     * @param  array<string, mixed>  $order
     * @return array{left: array<int, mixed>, right: array<int, mixed>}
     */
    public static function info(array $order): array
    {
        return [
            'left' => [__('email.kit.delivering_to'), self::address($order)],
            'right' => [
                __('email.totals.delivery'),
                (string) ($order['deliveryMethod'] ?? ''),
                __('email.kit.payment'),
                (string) ($order['paymentLabel'] ?? ''),
            ],
        ];
    }

    /** The customer's first name, or ''. */
    public static function firstName(array $order): string
    {
        $name = trim((string) ($order['customerName'] ?? ''));

        return $name === '' ? '' : (string) strtok($name, ' ');
    }
}
