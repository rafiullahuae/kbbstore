<?php

declare(strict_types=1);

namespace App\Services\Marketing\Bounces;

use App\Services\Marketing\UnsubscribeToken;

/**
 * Reads one message from the bounce mailbox and says what it is (Lane EB).
 *
 * No dependency: PHP's imap extension (which carried a MIME parser) left core
 * in PHP 8.4 and is not on this machine, and symfony/mime builds messages but
 * does not parse them. A bounce needs very little MIME — find the parts, undo
 * base64 / quoted-printable, read "Field: value" lines — so it is done here.
 *
 *   bounce       multipart/report; report-type=delivery-status (RFC 3464), or
 *                a non-standard report from mailer-daemon/postmaster carrying
 *                X-Failed-Recipients or the original's To and a status code.
 *                One entry per recipient with Action failed or delayed:
 *                  failed  → hard | soft (BounceCodes::kindFor)
 *                  delayed → delay, NEVER counted: the message is still being
 *                            retried, and if it finally fails a second,
 *                            "failed" report arrives and that one counts.
 *   complaint    an abuse report (RFC 5965, report-type=feedback-report).
 *   unsubscribe  the List-Unsubscribe mailto: (…+unsubscribe@…, subject
 *                "unsubscribe <token>"), when the bounce mailbox is on.
 *   autoreply    out-of-office and other automatic replies. NOT A BOUNCE —
 *                the mailbox exists and a person reads it. Checked AFTER the
 *                report types, because Google's own bounce reports also carry
 *                "Auto-Submitted: auto-replied".
 *   unknown      anything else: recorded nowhere.
 */
final class DsnParser
{
    /** At most this many parts are walked, whatever the message claims. */
    private const MAX_PARTS = 40;

    /**
     * @return array{type:string, report_id:string, subject:string, recipients:list<array{email:string, status:string, action:string, diagnostic:string, kind:string}>, refs:list<string>, token:?string}
     */
    public static function parse(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $parts = [];
        $top = self::part($raw, $parts, 0);
        $h = $top['headers'];

        $subject = self::decodeWords($h['subject'] ?? '');
        $reportId = trim((string) ($h['message-id'] ?? ''), " <>\t");
        $out = [
            'type' => 'unknown',
            'report_id' => mb_substr($reportId !== '' ? $reportId : 'sha1:' . sha1($raw), 0, 191),
            'subject' => mb_substr($subject, 0, 200),
            'recipients' => [],
            'refs' => BounceRef::findAll($raw),
            'token' => null,
        ];

        $type = strtolower($top['type']);
        $reportType = strtolower((string) ($top['params']['report-type'] ?? ''));

        // 1. Abuse / spam complaint (ARF, RFC 5965).
        $feedback = self::firstOfType($parts, 'message/feedback-report');

        if ($reportType === 'feedback-report' || $feedback !== null) {
            $fields = self::fields((string) $feedback);
            $email = self::address($fields['original-rcpt-to'] ?? '')
                ?? self::address(self::embeddedHeader($parts, 'to') ?? '');

            if ($email !== null) {
                $out['type'] = 'complaint';
                $out['recipients'][] = ['email' => $email, 'status' => '', 'action' => 'complaint', 'diagnostic' => mb_substr((string) ($fields['feedback-type'] ?? 'abuse'), 0, 60), 'kind' => 'complaint'];
            }

            return $out;
        }

        // 2. A standard delivery status notification (RFC 3464).
        $status = self::firstOfType($parts, 'message/delivery-status');

        if ($status !== null || ($type === 'multipart/report' && $reportType === 'delivery-status')) {
            foreach (self::groups((string) $status) as $g) {
                $email = self::address($g['final-recipient'] ?? '') ?? self::address($g['original-recipient'] ?? '');
                $action = strtolower(trim((string) ($g['action'] ?? '')));

                if ($email === null || ! in_array($action, ['failed', 'delayed'], true)) {
                    continue;   // delivered / relayed / expanded are not bounces
                }

                $code = trim(strtok((string) ($g['status'] ?? ''), " \t(") ?: '');
                $diag = self::clean((string) ($g['diagnostic-code'] ?? ''));

                $out['recipients'][] = [
                    'email' => $email,
                    'status' => mb_substr($code, 0, 12),
                    'action' => $action,
                    'diagnostic' => $diag,
                    'kind' => $action === 'delayed' ? 'delay' : BounceCodes::kindFor($code, $diag),
                ];
            }

            $out['type'] = $out['recipients'] === [] ? 'unknown' : 'bounce';

            return $out;
        }

        $from = strtolower((string) ($h['from'] ?? ''));
        $daemon = preg_match('/mailer-daemon@|postmaster@/', $from) === 1;

        // 3. A non-standard bounce from a daemon (Exim, older Exchange).
        if ($daemon) {
            $text = self::allText($parts);
            $failed = (string) ($h['x-failed-recipients'] ?? '');
            $emails = $failed !== '' ? self::addresses($failed) : array_filter([self::address(self::embeddedHeader($parts, 'to') ?? '')]);
            $code = preg_match('/\b([45]\.\d{1,3}\.\d{1,3})\b/', $text, $m) === 1 ? $m[1] : '';
            $delay = preg_match('/delay|will (keep|continue) trying|not yet been delivered/i', $subject) === 1;

            if ($emails !== [] && ($code !== '' || preg_match('/\b5\d\d\b/', $text) === 1)) {
                $diag = preg_match('/^.*\b(?:5\d\d|4\d\d)\b.*$/m', $text, $d) === 1 ? self::clean($d[0]) : '';

                foreach (array_values(array_unique($emails)) as $email) {
                    $out['recipients'][] = [
                        'email' => $email, 'status' => $code, 'action' => $delay ? 'delayed' : 'failed', 'diagnostic' => $diag,
                        'kind' => $delay ? 'delay' : BounceCodes::kindFor($code, $diag),
                    ];
                }

                $out['type'] = 'bounce';

                return $out;
            }
        }

        // 4. The List-Unsubscribe mailto:.
        if (preg_match('/\+unsubscribe@/i', (string) ($h['to'] ?? '')) === 1
            && preg_match('/\b(' . UnsubscribeToken::PATTERN . ')\b/', $subject . ' ' . self::allText($parts), $t) === 1) {
            $out['type'] = 'unsubscribe';
            $out['token'] = $t[1];

            return $out;
        }

        // 5. An automatic reply — explicitly NOT a bounce.
        if (preg_match('/auto-replied|auto-generated/i', (string) ($h['auto-submitted'] ?? '')) === 1
            || isset($h['x-autoreply']) || isset($h['x-autorespond'])
            || preg_match('/auto_reply|auto-reply/i', (string) ($h['precedence'] ?? '')) === 1
            || preg_match('/^(automatic reply|auto(matic)?[- ]?(reply|response)|out of (the )?office|autoreply)/i', $subject) === 1) {
            $out['type'] = 'autoreply';
        }

        return $out;
    }

    /* ------------------------------------------------------------- MIME */

    /**
     * Parse one entity, append its leaves to $parts.
     *
     * @param  list<array{type:string, body:string, headers:array<string,string>}>  $parts
     * @return array{headers:array<string,string>, type:string, params:array<string,string>}
     */
    private static function part(string $raw, array &$parts, int $depth): array
    {
        // A part with no headers starts with its blank line (RFC 2046 §5.1.1).
        [$head, $body] = str_starts_with($raw, "\n")
            ? ['', substr($raw, 1)]
            : array_pad(preg_split("/\n\n/", $raw, 2) ?: [], 2, '');
        $headers = self::headers($head);
        [$type, $params] = self::contentType($headers['content-type'] ?? 'text/plain');

        if (count($parts) >= self::MAX_PARTS || $depth > 6) {
            return ['headers' => $headers, 'type' => $type, 'params' => $params];
        }

        if (str_starts_with($type, 'multipart/') && ($params['boundary'] ?? '') !== '') {
            $b = preg_quote($params['boundary'], '/');
            $chunks = preg_split('/^--' . $b . '(?:--)?[ \t]*$/m', $body) ?: [];
            array_shift($chunks);   // the preamble

            foreach ($chunks as $chunk) {
                $chunk = str_starts_with($chunk, "\n") ? substr($chunk, 1) : $chunk;   // the boundary line's own newline

                if (trim($chunk) !== '') {
                    self::part($chunk, $parts, $depth + 1);
                }
            }

            return ['headers' => $headers, 'type' => $type, 'params' => $params];
        }

        $decoded = self::decodeBody($body, strtolower(trim($headers['content-transfer-encoding'] ?? '')));
        $parts[] = ['type' => $type, 'body' => $decoded, 'headers' => $headers];

        if ($type === 'message/rfc822' || $type === 'text/rfc822-headers') {
            $inner = [];
            $innerTop = self::part($type === 'text/rfc822-headers' ? $decoded . "\n\n" : $decoded, $inner, $depth + 1);
            $parts[] = ['type' => 'x-embedded', 'body' => '', 'headers' => $innerTop['headers']];

            foreach ($inner as $p) {
                if (count($parts) < self::MAX_PARTS) {
                    $parts[] = $p;
                }
            }
        }

        return ['headers' => $headers, 'type' => $type, 'params' => $params];
    }

    /** @return array<string,string> lower-cased names, unfolded, first wins */
    private static function headers(string $head): array
    {
        $out = [];
        $head = preg_replace("/\n[ \t]+/", ' ', $head) ?? $head;

        foreach (explode("\n", $head) as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m) === 1) {
                $k = strtolower($m[1]);
                $out[$k] ??= trim($m[2]);
            }
        }

        return $out;
    }

    /** @return array{0:string, 1:array<string,string>} */
    private static function contentType(string $value): array
    {
        $bits = explode(';', $value);
        $type = strtolower(trim((string) array_shift($bits)));
        $params = [];

        foreach ($bits as $bit) {
            if (preg_match('/^\s*([A-Za-z0-9*-]+)\s*=\s*"?([^"]*)"?\s*$/', $bit, $m) === 1) {
                $params[strtolower($m[1])] = $m[2];
            }
        }

        return [$type, $params];
    }

    private static function decodeBody(string $body, string $cte): string
    {
        return match ($cte) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private static function decodeWords(string $value): string
    {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return is_string($decoded) ? $decoded : $value;
    }

    /** @param list<array{type:string, body:string, headers:array<string,string>}> $parts */
    private static function firstOfType(array $parts, string $type): ?string
    {
        foreach ($parts as $p) {
            if ($p['type'] === $type) {
                return $p['body'];
            }
        }

        return null;
    }

    /** @param list<array{type:string, body:string, headers:array<string,string>}> $parts */
    private static function embeddedHeader(array $parts, string $name): ?string
    {
        foreach ($parts as $p) {
            if ($p['type'] === 'x-embedded' && isset($p['headers'][$name])) {
                return $p['headers'][$name];
            }
        }

        return null;
    }

    /** @param list<array{type:string, body:string, headers:array<string,string>}> $parts */
    private static function allText(array $parts): string
    {
        $text = '';

        foreach ($parts as $p) {
            if ($p['type'] === 'text/plain') {
                $text .= $p['body'] . "\n";
            }
        }

        return mb_substr($text, 0, 20000);
    }

    /* ----------------------------------------------------- DSN fields */

    /**
     * The per-recipient groups of a message/delivery-status body: blank-line
     * separated blocks of "Field: value". The first block is per-message.
     *
     * @return list<array<string,string>>
     */
    private static function groups(string $body): array
    {
        $out = [];

        foreach (preg_split("/\n[ \t]*\n/", trim($body)) ?: [] as $block) {
            $fields = self::fields($block);

            if (isset($fields['final-recipient']) || isset($fields['original-recipient'])) {
                $out[] = $fields;
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    private static function fields(string $block): array
    {
        return self::headers(trim($block));
    }

    /** "rfc822; a@b.c" or "Name <a@b.c>" → "a@b.c", lower-cased, or null. */
    private static function address(string $value): ?string
    {
        $all = self::addresses($value);

        return $all[0] ?? null;
    }

    /** @return list<string> */
    private static function addresses(string $value): array
    {
        preg_match_all('/[A-Za-z0-9._%+\'=-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $value, $m);
        $out = [];

        foreach ($m[0] ?? [] as $a) {
            $a = mb_strtolower(trim($a));

            if (mb_strlen($a) <= 191 && filter_var($a, FILTER_VALIDATE_EMAIL) !== false) {
                $out[] = $a;
            }
        }

        return $out;
    }

    private static function clean(string $value): string
    {
        $value = preg_replace('/^(smtp|x-[a-z-]+);\s*/i', '', trim($value)) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = preg_replace('/[^\P{C}]+/u', '', $value) ?? $value;   // control characters

        return mb_substr(trim($value), 0, 250);
    }
}
