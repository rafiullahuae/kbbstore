<?php

declare(strict_types=1);

namespace App\Services\Quiqup;

/**
 * Turns a last-mile error answer into Quiqup's own words plus our hint
 * (QUIQUP-PLAN §1.7, docs/plans/quiqup/api-docs/qq-docs_errors.md).
 *
 * The envelope is `api_error {message, human, attribute_errors[{detail}]}`
 * with `error`, `errors[0].detail`, `error_details[0].detail` and
 * `error_detail` repeating it; a 401 is a bare `{"error":"unauthorized"}`; an
 * invalid kind/payment_mode is a 422 with EVERY message empty. Phones that
 * appear in a message are masked before it is stored or logged.
 */
final class QuiqupErrors
{
    public const POLICY_HINT = 'Quiqup refused the booking for a commercial reason, not a mistake in the order: a customer segmentation rule or a closed tier (ask your account manager; retrying will not help), Quiqdash closed for high demand or a 3PL not accepting orders (retry later), or the hourly order limit (retry after the hour).';

    /** @param array<string, mixed>|null $json */
    public static function describe(int $status, ?array $json, string $raw = ''): QuiqupResult
    {
        $text = self::text($json, $raw);
        $hint = self::hint($status, $text);
        $error = 'HTTP ' . $status . ' — ' . ($text === '' ? '(Quiqup sent no message)' : $text);

        return new QuiqupResult($status, $json, '', self::mask(mb_substr($error, 0, 420)), $hint);
    }

    public static function transport(string $what): QuiqupResult
    {
        return new QuiqupResult(0, null, '', 'Quiqup did not answer (' . self::mask(mb_substr($what, 0, 160)) . ')', 'It will be tried again with the same booking key, so it cannot be booked twice.');
    }

    /** @param array<string, mixed>|null $json */
    public static function text(?array $json, string $raw = ''): string
    {
        if (! is_array($json)) {
            $raw = trim(strip_tags($raw));

            return $raw === '' ? '' : mb_substr(preg_replace('/\s+/', ' ', $raw) ?? '', 0, 200);
        }

        $api = is_array($json['api_error'] ?? null) ? $json['api_error'] : [];
        $parts = [];

        foreach ([$api['human'] ?? null, $api['message'] ?? null, $json['error'] ?? null, $json['errors'][0]['detail'] ?? null, $json['error_detail'] ?? null, $json['message'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $parts[] = trim($candidate);
                break;
            }
        }

        foreach (is_array($api['attribute_errors'] ?? null) ? $api['attribute_errors'] : [] as $a) {
            $d = is_array($a) ? ($a['detail'] ?? null) : null;

            if (is_string($d) && trim($d) !== '' && ! in_array(trim($d), $parts, true)) {
                $parts[] = trim($d);
            }
        }

        return implode('; ', array_slice($parts, 0, 4));
    }

    public static function hint(int $status, string $text): string
    {
        if ($status === 401) {
            return 'Quiqup rejected the API key, or it has expired. Paste a new key (expiry: Never) in Store → Quiqup → Connection.';
        }

        if (str_contains($text, 'CanSubmitJobPolicy')) {
            return self::POLICY_HINT;
        }

        if ($status === 422 && $text === '') {
            return 'Quiqup refused the service type or the payment mode without saying which. Check the service is switched on for your account.';
        }

        if (stripos($text, 'Same day service is not enabled') !== false) {
            return 'Same Day is not switched on for your Quiqup account. Ask your account manager, or untick Same Day in Store → Quiqup → Pickup & defaults.';
        }

        if ($status === 403) {
            return 'Quiqup answers 403 for an order id it does not know.';
        }

        if ($status >= 500) {
            return 'Quiqup had a fault. It will be tried again with the same booking key.';
        }

        if ($status === 429) {
            return 'Quiqup asked us to slow down. It will be tried again.';
        }

        return '';
    }

    /** +971 50 123 4567 / 0501234567 -> +97150•••4567. */
    public static function mask(string $text): string
    {
        return preg_replace_callback('/(?:\+|\b0|\b971)\d[\d\s\-]{6,}\d/', static function (array $m): string {
            $d = preg_replace('/\D/', '', $m[0]) ?? '';

            return strlen($d) < 9 ? $m[0] : substr($m[0], 0, 4) . '•••' . substr($d, -4);
        }, $text) ?? $text;
    }
}
