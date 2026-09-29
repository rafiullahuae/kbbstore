<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Gateways\TamaraGateway;
use Illuminate\Console\Command;

/**
 * Register, remove or report this shop's Tamara webhook, from a shell.
 *
 *     php artisan payments:tamara-webhook              # what is registered now
 *     php artisan payments:tamara-webhook --register
 *     php artisan payments:tamara-webhook --remove
 *     php artisan payments:tamara-webhook --remove --force   # no question asked
 *
 * ── WHY THIS EXISTS WHEN THERE IS A BUTTON ─────────────────────────────────
 *
 * Because the button is the thing most likely to be broken on the day this is
 * needed. Tamara sends `order_declined` and `order_expired` ONLY to an endpoint
 * registered through its own `POST /webhooks`, and its merchant portal has no
 * screen for it — so if the admin console will not load, or the compiled route
 * cache on the host does not know `/admin-api/payments/tamara/webhook` (which
 * is exactly what a package applied without its clear_caches migration looks
 * like), there is no other way to do it at all. Cloudways gives this project a
 * shell; CLAUDE.md records that using it is the normal case and not the
 * fallback.
 *
 * `payments:tamara-sweep` already made that argument for the recovery sweep.
 * This makes it for the registration the sweep is a consequence of.
 *
 * ── IT IS THE SAME CODE THE SCREEN RUNS ────────────────────────────────────
 *
 * TamaraGateway::registerWebhook() and ::unregisterWebhook(), called directly —
 * not a second implementation. Two copies of a provider call drift, and the
 * direction they drift in is a command that reports success for work the screen
 * does differently.
 *
 * ── IDEMPOTENT AT BOTH ENDS ────────────────────────────────────────────────
 *
 * `--register` on a shop that already has a registration reports the existing
 * one and creates nothing: two registrations mean every expiry delivered twice
 * and Tamara offers no "replace" call. `--remove` on a shop with none is a
 * success, because the caller asked for there to be no webhook and there is
 * none.
 *
 * ── THE REMOVAL ASKS FIRST ─────────────────────────────────────────────────
 *
 * Removing it silently stops Tamara telling this shop about declines, and the
 * symptom is an absence: orders sit `pending` holding stock and a coupon use
 * until somebody audits them. So it asks, and `--force` is the answer for a
 * script. A non-interactive run without --force refuses rather than assuming
 * yes — `confirm()` returns the default (false) with --no-interaction, which is
 * the safe direction, but saying so out loud is cheaper than the reader having
 * to know that.
 *
 * ── EXIT CODES ─────────────────────────────────────────────────────────────
 *
 * 0 when the thing asked for is true afterwards, 1 when it is not. A status
 * read exits 0 whatever it finds, because "there is no webhook" is a report and
 * not a failure of the command.
 *
 * NO CREDENTIAL IS PRINTED. The webhook id is opaque and useless without the
 * API token and it is the only way to tell one registration from another when
 * asking Tamara's support about it; the webhook URL, which embeds the webhook
 * secret, is never printed here for the reason TamaraAdminController sets out.
 */
class TamaraWebhookCommand extends Command
{
    protected $signature = 'payments:tamara-webhook
                            {--register : Register this shop\'s endpoint with Tamara}
                            {--remove : Remove the registration again}
                            {--force : Do not ask before removing}';

    protected $description = 'Register, remove or report the Tamara webhook that carries declines and expiries';

    public function handle(GatewayRegistry $registry, GatewayCredentials $credentials): int
    {
        $gateway = $registry->find('tamara');

        if (! $gateway instanceof TamaraGateway) {
            $this->error('This build does not ship the Tamara gateway.');

            return self::FAILURE;
        }

        $register = (bool) $this->option('register');
        $remove = (bool) $this->option('remove');

        if ($register && $remove) {
            $this->error('--register and --remove ask for opposite things. Pass one.');

            return self::FAILURE;
        }

        // Read the stored credentials fresh. GatewayCredentials memoises per
        // instance and a long-lived process — a queue worker, a second command
        // in the same tinker session — would otherwise answer from before the
        // last save. Same reason TamaraAdminController::show() does it.
        $credentials->forget($gateway->id());

        if ($register) {
            return $this->register($gateway, $credentials);
        }

        if ($remove) {
            return $this->remove($gateway, $credentials);
        }

        $this->report($gateway, $credentials);

        return self::SUCCESS;
    }

    private function register(TamaraGateway $gateway, GatewayCredentials $credentials): int
    {
        $result = $gateway->registerWebhook();

        $credentials->forget($gateway->id());

        if (! $result['ok']) {
            $this->error($this->explain((string) ($result['error'] ?? '')));
            $this->report($gateway, $credentials);

            return self::FAILURE;
        }

        if ($result['created']) {
            $this->info('Registered. Tamara will now send expiry and decline notices to this shop.');
        } else {
            $this->line('A webhook was already registered; nothing was changed.');
        }

        $this->report($gateway, $credentials);

        return self::SUCCESS;
    }

    private function remove(TamaraGateway $gateway, GatewayCredentials $credentials): int
    {
        if (! (bool) $this->option('force')) {
            $this->warn(
                'Removing the webhook stops Tamara telling this shop when an order is declined or expires. '
                . 'Those orders stay `pending`, holding their stock and their coupon use, until somebody audits them.'
            );

            /*
             * A NON-INTERACTIVE RUN IS NOT A YES. With --no-interaction (or no
             * TTY at all — a cron line, a deploy script) there is nobody to ask,
             * and Symfony's confirm() would answer with the default. Relying on
             * that default being `false` works and is invisible: the next reader
             * changing it to true would silently turn every scripted run of this
             * command into a removal. So the absence of a person is refused
             * explicitly, and --force is the way a script says it meant it.
             */
            if (! $this->input->isInteractive()) {
                $this->line('Left alone — nothing was changed. Nobody could be asked, and this is not a question to '
                    . 'answer by default. Pass --force if you meant it.');

                return self::SUCCESS;
            }

            if (! $this->confirm('Remove the registration?', false)) {
                $this->line('Left alone. Nothing was changed.');

                // Not a failure: the operator answered the question that was
                // asked. Exiting 1 here would make a --no-interaction run in a
                // deployment script look like a broken removal rather than a
                // refused one, which is the opposite of what happened.
                return self::SUCCESS;
            }
        }

        $result = $gateway->unregisterWebhook();

        $credentials->forget($gateway->id());

        if (! $result['ok']) {
            $this->error($this->explain((string) ($result['error'] ?? '')));
            $this->report($gateway, $credentials);

            return self::FAILURE;
        }

        $this->info('The registration was removed.');
        $this->report($gateway, $credentials);

        return self::SUCCESS;
    }

    private function report(TamaraGateway $gateway, GatewayCredentials $credentials): void
    {
        $id = $credentials->get($gateway->id(), 'webhook_id');

        $this->newLine();
        $this->line(sprintf('  Keys stored        %s', $gateway->configured() ? 'yes' : 'no'));
        $this->line(sprintf('  Webhook            %s', $id !== '' ? 'registered (' . $id . ')' : 'NOT registered'));
        $this->line(sprintf('  Events it carries  %s', implode(', ', TamaraGateway::WEBHOOK_EVENTS)));

        if ($id === '') {
            $this->newLine();
            $this->warn('No webhook is registered, so a declined or expired Tamara order is invisible to this shop.');
        }
    }

    /** A sentence per machine code. Never the provider's own text. */
    private function explain(string $code): string
    {
        return match ($code) {
            'not_configured' => 'Tamara has no API token stored yet. Paste the keys on Store -> Payments -> Tamara and save first.',
            'no_webhook_secret' => 'This gateway has no webhook secret yet. Save the Tamara settings once — that generates it — then try again.',
            default => 'Tamara refused the request. Nothing was changed.',
        };
    }
}
