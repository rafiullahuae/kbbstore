<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Gateways\TamaraGateway;
use Illuminate\Console\Command;

/**
 * Pull the agreed basket limits from Tamara, or print the stored ones.
 *
 *     php artisan payments:tamara-limits --show     # print, call nothing
 *     php artisan payments:tamara-limits
 *     php artisan payments:tamara-limits --country=SA --currency=SAR
 *
 * ── WHAT THE LIMITS DECIDE ─────────────────────────────────────────────────
 *
 * Tamara agrees a minimum and a maximum basket value per merchant per market
 * and REFUSES a session outside them. TamaraGateway::available() reads the two
 * stored values and hides the method at checkout for a basket outside the
 * range, so that a shopper is not offered Tamara, made to press Place order and
 * only then told to choose again. With them unset no limit is applied at all
 * and that failure is back.
 *
 * The numbers are in the merchant portal and can be typed into
 * Store -> Payments -> Tamara by hand. `GET /checkout/payment-types` is the
 * authority, and they change.
 *
 * ── MONEY ──────────────────────────────────────────────────────────────────
 *
 * refreshLimits() converts Tamara's amounts to fils and back to the major-unit
 * STRINGS the settings hold ("100.00"), and that is what is stored and what is
 * printed here. Nothing on this path is a float and this command does no
 * arithmetic of its own.
 *
 * ── THE MARKET CANNOT BE INVENTED ──────────────────────────────────────────
 *
 * `--country` is bounded here to two letters and `--currency` to three, and
 * TamaraGateway::refreshLimits() then checks the country against its own list
 * of the six markets Tamara serves and returns null rather than querying one it
 * does not. Both halves are kept: this one gives the operator a sentence
 * instead of a silent "did not return limits", and the gateway's one is what
 * actually decides.
 *
 * ── EXIT CODES ─────────────────────────────────────────────────────────────
 *
 * 0 when limits were read and stored (or, with --show, whatever was already
 * there), 1 when Tamara did not answer with a usable pair — in which case the
 * limits already stored are untouched, which is the whole reason a failure here
 * is safe to retry.
 */
class TamaraLimitsCommand extends Command
{
    protected $signature = 'payments:tamara-limits
                            {--country= : Two-letter market to ask about (default: the shop\'s own)}
                            {--currency= : Three-letter currency (default: the shop\'s own)}
                            {--show : Print what is stored and ask Tamara nothing}';

    protected $description = 'Pull the agreed minimum and maximum Tamara basket totals, or print the stored ones';

    public function handle(GatewayRegistry $registry, GatewayCredentials $credentials): int
    {
        $gateway = $registry->find('tamara');

        if (! $gateway instanceof TamaraGateway) {
            $this->error('This build does not ship the Tamara gateway.');

            return self::FAILURE;
        }

        $credentials->forget($gateway->id());

        if ((bool) $this->option('show')) {
            $this->stored($gateway, $credentials);

            return self::SUCCESS;
        }

        if (! $gateway->configured()) {
            $this->error('Tamara has no API token stored yet. Paste the keys on Store -> Payments -> Tamara and save first.');

            return self::FAILURE;
        }

        $country = $this->code('country', 2);
        $currency = $this->code('currency', 3);

        if ($country === false || $currency === false) {
            $this->error('--country is two letters and --currency is three. Nothing was sent.');

            return self::FAILURE;
        }

        $limits = $gateway->refreshLimits($country, $currency);

        $credentials->forget($gateway->id());

        if ($limits === null) {
            $this->error(
                'Tamara did not return limits for that market and payment type. '
                . 'Nothing was changed — the limits already stored are untouched.'
            );

            $this->stored($gateway, $credentials);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Tamara reports %s to %s for %s in %s (%s).',
            $limits['min'],
            $limits['max'],
            $limits['payment_type'],
            $limits['country'],
            $limits['currency'],
        ));

        return self::SUCCESS;
    }

    private function stored(TamaraGateway $gateway, GatewayCredentials $credentials): void
    {
        $min = $credentials->get($gateway->id(), 'min_limit');
        $max = $credentials->get($gateway->id(), 'max_limit');

        $this->line(sprintf('  Minimum basket  %s', $min !== '' ? $min : '(none stored)'));
        $this->line(sprintf('  Maximum basket  %s', $max !== '' ? $max : '(none stored)'));

        if ($min === '' || $max === '') {
            $this->newLine();
            $this->warn(
                'With no limits stored, Tamara is offered on every basket — including the ones it will refuse '
                . 'after the shopper has pressed Place order.'
            );
        }
    }

    /**
     * An option of exactly $length letters, uppercased; null when absent;
     * false when it is there and is not that.
     *
     * Returning three things rather than throwing, because "absent" and
     * "rubbish" have different answers and an exception here would print a
     * stack trace at an owner who mistyped a country.
     */
    private function code(string $key, int $length): string|false|null
    {
        $raw = trim((string) ($this->option($key) ?? ''));

        if ($raw === '') {
            return null;
        }

        return preg_match('/^[A-Za-z]{' . $length . '}$/', $raw) === 1 ? strtoupper($raw) : false;
    }
}
