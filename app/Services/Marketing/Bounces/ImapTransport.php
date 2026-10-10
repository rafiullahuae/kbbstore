<?php

declare(strict_types=1);

namespace App\Services\Marketing\Bounces;

/**
 * The bytes under ImapClient (Lane EB). One real implementation, a TLS socket
 * to imap.gmail.com:993; the tests give a scripted fake, so what the client
 * SAYS to the mailbox is asserted line by line without a network.
 */
interface ImapTransport
{
    public function open(string $host, int $port, int $timeout): void;

    public function write(string $data): void;

    /** One line including its CRLF; throws when the connection is gone. */
    public function readLine(): string;

    /** Exactly $n bytes (an IMAP literal). */
    public function read(int $n): string;

    public function close(): void;
}
