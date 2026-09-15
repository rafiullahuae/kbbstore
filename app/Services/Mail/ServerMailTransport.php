<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;

/**
 * "Use this server's mail" — PHP's own mail() function, handed to the host's MTA.
 *
 * WHY THIS EXISTS RATHER THAN config/mail.php's `sendmail` MAILER.
 *
 * Symfony's SendmailTransport does not shell out politely; it opens a pipe with
 * proc_open() (Transport/Smtp/Stream/ProcessStream::initialize). proc_open is
 * one of the first functions a shared host puts in `disable_functions`, and this
 * store runs on shared hosting with no shell access — so there is no way to
 * check from here and no way to fix it from there if the guess is wrong. It also
 * needs a correct binary path: the framework default is `/usr/sbin/sendmail -bs`,
 * and `-bs` (a full SMTP conversation over the pipe) is a stricter requirement
 * than `-t` that not every host's sendmail wrapper honours. Two ways to be
 * silently wrong, on a host where "silently wrong" is the failure this whole
 * package exists to end.
 *
 * mail() has neither problem. It is the function every other PHP application on
 * this account already sends through — the live WooCommerce store's wp_mail()
 * ends up here — it needs no path, no port and no password, and PHP hands the
 * message to the local mail program in `-t -i` mode, which is the mode shared
 * hosts support. And where mail() is disabled, that is a fact this class can
 * check and report, which is the difference between an answer and a silence.
 *
 * WHAT IT DOES NOT DO. It is not a queue, it does not retry the network, and it
 * does not know whether the message was delivered: mail() returns true when the
 * local mail program accepted the message for delivery, and nothing more.
 * MailTester's wording is careful about that distinction and so is the failure
 * message below.
 *
 * WHAT IT SENDS IS SYMFONY'S OWN RENDERING, byte for byte. The headers and the
 * MIME body are taken from the message Symfony already built, so the multipart
 * HTML+text structure, the encodings and the Message-ID are exactly what the
 * SMTP transport would have put on the wire. Only three headers are removed:
 * PHP's mail() writes `To:` and `Subject:` itself from its own arguments, and
 * leaving the originals in place would deliver every message with both of them
 * twice; `Bcc:` is re-derived below rather than trusted.
 */
/*
 * Not final: deliver() below is the one seam, and a test subclasses this to
 * capture the five arguments that would reach mail() without sending anything.
 */
class ServerMailTransport extends AbstractTransport
{
    /**
     * The transport name, as it appears in `config('mail.mailers.kbb.transport')`.
     *
     * Hyphenated so it can never collide with one of MailManager's built-in
     * `create<Name>Transport` methods: the manager studly-cases the transport
     * name to find a method, and a custom creator registered under this name is
     * consulted first (MailManager::createSymfonyTransport).
     */
    public const NAME = 'kbb-server';

    public function __toString(): string
    {
        return 'kbb+mail://localhost';
    }

    /**
     * Is PHP's mail() actually callable on this server?
     *
     * Checked, not assumed. `disable_functions` is how a shared host switches it
     * off, and function_exists() alone still returns true for a disabled
     * function on some builds, so both are consulted.
     */
    public static function available(): bool
    {
        if (! function_exists('mail')) {
            return false;
        }

        $disabled = array_map(
            static fn (string $name): string => strtolower(trim($name)),
            explode(',', (string) ini_get('disable_functions')),
        );

        return ! in_array('mail', $disabled, true);
    }

    protected function doSend(SentMessage $message): void
    {
        if (! self::available()) {
            throw new TransportException(
                "This server cannot send mail: PHP's mail() function is disabled by the host "
                . '(disable_functions in php.ini). Ask the host to enable it, or switch '
                . 'Store → Mail to a dedicated SMTP server.'
            );
        }

        $envelope = $message->getEnvelope();

        [$headerBlock, $body] = $this->split($message->toString());

        $headers = $this->unfold($headerBlock);
        [$subject, $toHeader, $ccHeader] = $this->take($headers);
        $to = $this->visibleRecipients($message, $envelope, $toHeader);

        if ($to === []) {
            throw new TransportException('Nothing was sent: the message has no recipient.');
        }

        /*
         * Anyone in the envelope who is on no visible header is a Bcc.
         *
         * Symfony has already stripped the Bcc header from the rendered message
         * (Mime\Message::getPreparedHeaders removes it), so it is put back here
         * as a real header: PHP hands the message to the local mail program in
         * `-t` mode, which reads its recipients from the headers and removes Bcc
         * before delivery. Putting those addresses into the To line instead —
         * the obvious shortcut — would print every blind recipient to every
         * other recipient. Nothing in this app uses Bcc today; whatever does
         * next must not have to discover that the hard way.
         */
        $hidden = $this->hiddenRecipients($envelope, $toHeader . ' ' . $ccHeader);

        if ($hidden !== []) {
            $headers[] = 'Bcc: ' . implode(', ', $hidden);
        }

        $sender = $envelope->getSender()->getEncodedAddress();
        $params = filter_var($sender, FILTER_VALIDATE_EMAIL) !== false ? '-f' . $sender : '';

        $sent = $this->deliver(implode(', ', $to), $subject, $body, implode("\r\n", $headers), $params);

        if (! $sent) {
            throw new TransportException(
                "The server refused the message. PHP's mail() returned false, which means the "
                . 'local mail program would not accept it — commonly because the From address is '
                . 'not a mailbox on this domain, or because the host has no mail program '
                . 'configured. The From address is set in Store → Mail.'
            );
        }
    }

    /**
     * The call itself, and the one retry this class allows.
     *
     * `-f` sets the envelope sender, which is what a receiving server checks SPF
     * against; without it a shared host stamps its own default and the message
     * is markedly more likely to be filed as junk. But a locked-down sendmail
     * wrapper can refuse the flag outright rather than warn about it, and then
     * mail() returns false having sent nothing at all. So: try with it, and if
     * the mail program would not take the message, try once without.
     *
     * The retry cannot double-send. mail() returns false only when the local
     * mail program rejected the message before queuing it; a message that was
     * accepted returns true and never reaches the second call.
     *
     * PROTECTED so a test can subclass and capture exactly what would be handed
     * to mail(). That is the only seam in this class, and it is the one worth
     * having: everything above it is header surgery — dropping the two headers
     * PHP writes itself, unfolding the rest, working out who is a Bcc — and none
     * of it is observable from outside without either sending a real message or
     * looking at these five arguments.
     */
    protected function deliver(string $to, string $subject, string $body, string $headers, string $params): bool
    {
        if ($params !== '' && @mail($to, $subject, $body, $headers, $params)) {
            return true;
        }

        return (bool) @mail($to, $subject, $body, $headers);
    }

    /**
     * Split a rendered message into its header block and its body.
     *
     * Symfony writes the message headers, then the top-level part's headers,
     * then a blank line, then the body — so the first empty line is the boundary
     * and everything before it is one header block.
     *
     * @return array{0:string,1:string}
     */
    private function split(string $raw): array
    {
        $at = strpos($raw, "\r\n\r\n");

        if ($at === false) {
            // No body at all. Still a valid message; do not lose the headers.
            return [rtrim($raw, "\r\n"), ''];
        }

        return [substr($raw, 0, $at), substr($raw, $at + 4)];
    }

    /**
     * Header lines, one per header, with RFC 5322 folding undone.
     *
     * A long Subject or a long recipient list arrives wrapped across several
     * lines. mail() wants each header on one line, and a folded continuation
     * passed through as a line of its own would be read as a malformed header.
     *
     * @return list<string>
     */
    private function unfold(string $block): array
    {
        $out = [];

        foreach (explode("\r\n", $block) as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] === ' ' || $line[0] === "\t") && $out !== []) {
                $out[array_key_last($out)] .= ' ' . trim($line);

                continue;
            }

            $out[] = $line;
        }

        return $out;
    }

    /**
     * Take the three headers PHP's mail() will not accept from the block, and
     * hand back their values.
     *
     * Subject and To because mail() writes both itself from its own arguments —
     * left here they arrive twice, and a duplicated To is on its own enough for
     * several providers to score a message as spam. Bcc because it is rebuilt
     * from the envelope rather than trusted.
     *
     * The subject comes back ENCODED, not decoded: mail() passes it through
     * verbatim, so an order number is fine as it stands and a non-ASCII subject
     * has to keep the RFC 2047 form Symfony already gave it. Decoding here would
     * put raw UTF-8 into a header and produce mojibake in half the world's mail
     * clients.
     *
     * @param  list<string>  $headers  modified in place
     * @return array{0:string,1:string,2:string}  subject, To value, Cc value
     */
    private function take(array &$headers): array
    {
        $subject = '';
        $to = '';
        $cc = '';
        $kept = [];

        foreach ($headers as $line) {
            $name = strtolower((string) strstr($line, ':', true));
            $value = trim((string) substr($line, strlen($name) + 1));

            match ($name) {
                'subject' => $subject = $value,
                'to' => $to = $value,
                'bcc' => null,
                default => $kept[] = $line,
            };

            if ($name === 'cc') {
                $cc = $value;
            }
        }

        $headers = $kept;

        return [$subject, $to, $cc];
    }

    /**
     * The addresses that belong in the To line.
     *
     * THE ORIGINAL MESSAGE, NOT SentMessage::getMessage(). That method hands
     * back a flattened RawMessage — Symfony rebuilds one in SentMessage's
     * constructor — and a RawMessage has no headers to read, so asking it for
     * its To silently returns nothing. The fallback then used the ENVELOPE,
     * which is the delivery list and includes every Bcc, so a blind recipient
     * was printed in the To line of a message that went to somebody else. Found
     * by the test below and not by anything else, because nothing in this app
     * sends a Bcc yet.
     *
     * Three sources, in descending order of how much they know:
     *
     *   1. the original Email's own To header — what the mailable asked for;
     *   2. the rendered To header, for a message that was never an Email
     *      (MailTester's raw send is one) — already correctly encoded, so it is
     *      passed through whole rather than re-split and re-joined;
     *   3. the envelope, which always has somebody in it.
     *
     * @return list<string>
     */
    private function visibleRecipients(SentMessage $message, Envelope $envelope, string $toHeader): array
    {
        $original = $message->getOriginalMessage();

        if ($original instanceof Message) {
            $header = $original->getHeaders()->get('To');

            if ($header !== null && method_exists($header, 'getAddresses')) {
                $addresses = array_map(
                    static fn (Address $address): string => $address->toString(),
                    $header->getAddresses(),
                );

                if ($addresses !== []) {
                    return array_values($addresses);
                }
            }
        }

        if (trim($toHeader) !== '') {
            return [trim($toHeader)];
        }

        return array_values(array_map(
            static fn (Address $address): string => $address->toString(),
            $envelope->getRecipients(),
        ));
    }

    /**
     * Envelope recipients that appear on no visible header — the Bcc list.
     *
     * $visible is the raw text of the To and Cc headers, and every address in it
     * is pulled out with one pattern rather than by splitting on commas: a
     * display name may legitimately contain a comma inside quotes, and a split
     * would tear one address into two and classify both as blind.
     *
     * Compared on the bare address, because the display name differs between the
     * header and the envelope ("Aisha Khan" <a@b> versus a@b) and comparing the
     * rendered strings would make every ordinary recipient look hidden.
     *
     * @return list<string>
     */
    private function hiddenRecipients(Envelope $envelope, string $visible): array
    {
        $seen = [];

        preg_match_all('/[^\s<>,"]+@[^\s<>,"]+/', $visible, $matches);

        foreach ($matches[0] as $address) {
            $seen[strtolower(rtrim($address, '>'))] = true;
        }

        $hidden = [];

        foreach ($envelope->getRecipients() as $address) {
            if (! isset($seen[strtolower($address->getAddress())])) {
                $hidden[] = $address->toString();
            }
        }

        return $hidden;
    }

}
