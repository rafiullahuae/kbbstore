<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\PaymentProvider;
use App\Services\Payments\Gateways\CashOnDelivery;
use App\Services\Payments\Gateways\StripeGateway;
use App\Services\Payments\Gateways\TabbyGateway;
use App\Services\Payments\Gateways\TamaraGateway;
use Illuminate\Support\Collection;

/**
 * The gateways this store knows how to run, and which of them a given basket
 * may actually use.
 *
 * Enablement and ordering are NOT stored here — they are rows in
 * `payment_providers`, which is where they already were. This class answers
 * "what code handles `tabby`" and "given a total, what may the shopper pick".
 */
class GatewayRegistry
{
    /** Registration order is the fallback display order. */
    private const CLASSES = [
        'cod' => CashOnDelivery::class,
        'tabby' => TabbyGateway::class,
        'tamara' => TamaraGateway::class,
        'stripe' => StripeGateway::class,
    ];

    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    /** Every gateway this build ships, configured or not. */
    public function all(): Collection
    {
        return collect(array_keys(self::CLASSES))
            ->map(fn (string $id) => $this->find($id))
            ->filter()
            ->values();
    }

    public function find(?string $id): ?PaymentGateway
    {
        if (! $this->supports($id)) {
            return null;
        }

        return $this->resolved[$id] ??= app(self::CLASSES[$id]);
    }

    /**
     * class_exists as well as the map, so a package that ships the registry
     * without one of the gateway files degrades to "that method is not
     * offered" instead of fatalling the checkout page. Three files went
     * missing from a package on this project once already.
     */
    public function supports(?string $id): bool
    {
        return $id !== null
            && isset(self::CLASSES[$id])
            && class_exists(self::CLASSES[$id]);
    }

    /**
     * The gateways a shopper may choose for this basket.
     *
     * Four gates, and all four have to pass:
     *
     *   - the `payment_providers` row says enabled
     *   - the gateway class says its credentials are present
     *   - the gateway class says the basket qualifies (the COD window, a BNPL
     *     minimum, a country restriction)
     *   - we have code for it at all — a stray row for a gateway this build
     *     does not ship is ignored rather than offered and then 500ing on
     *     submit
     */
    public function availableFor(int $totalFils, ?string $country = null): Collection
    {
        $rows = PaymentProvider::query()
            ->where('enabled', true)
            ->orderBy('position')
            ->get()
            ->keyBy('id');

        return $rows->keys()
            ->map(fn ($id) => $this->find((string) $id))
            ->filter()
            ->filter(fn (PaymentGateway $g) => $g->configured())
            ->filter(fn (PaymentGateway $g) => $g->availableFor($totalFils, $country))
            ->values();
    }

    /**
     * The exact array shape store.checkout has always iterated — id, title,
     * description, fee_html, fee_fils. The blade is not touched; only where
     * the list comes from changed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function checkoutList(int $totalFils, ?string $country = null): array
    {
        return $this->availableFor($totalFils, $country)
            ->map(function (PaymentGateway $g) use ($totalFils) {
                $fee = $g->feeFils($totalFils);

                return [
                    'id' => $g->id(),
                    'title' => $this->titleFor($g),
                    'description' => $g->description($totalFils),
                    'fee_html' => $fee > 0 ? '+' . \App\Support\Money::format($fee) : null,
                    'fee_fils' => $fee,
                ];
            })
            ->all();
    }

    /** The merchant's own wording from the provider row wins over the default. */
    private function titleFor(PaymentGateway $gateway): string
    {
        $title = PaymentProvider::find($gateway->id())?->title;

        return is_string($title) && trim($title) !== '' ? trim($title) : $gateway->title();
    }
}
