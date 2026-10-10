<?php

declare(strict_types=1);

namespace App\Services\Marketing\Bounces;

/**
 * IMAP over TLS with PHP's own stream functions — no extension, no package.
 *
 * WHY NOT ext-imap: it was unbundled from PHP in 8.4 (this machine runs 8.4
 * without it) and is unmaintained upstream; a feature that silently stops on
 * the day the host upgrades PHP is the wrong shape for a shop with no one
 * watching the server. Sockets and OpenSSL are always there.
 *
 * Certificates are verified (verify_peer + verify_peer_name, the defaults made
 * explicit), so the app password is only ever sent to the real Google.
 */
final class SocketImapTransport implements ImapTransport
{
    /** @var resource|null */
    private $socket = null;

    public function open(string $host, int $port, int $timeout): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true,
        ]]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new \RuntimeException('Could not reach ' . $host . ':' . $port . ($errstr !== '' ? ' (' . $errstr . ')' : '') . '.');
        }

        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;
    }

    public function write(string $data): void
    {
        if ($this->socket === null || @fwrite($this->socket, $data) === false) {
            throw new \RuntimeException('The mailbox connection closed.');
        }
    }

    public function readLine(): string
    {
        $line = $this->socket !== null ? @fgets($this->socket, 65536) : false;

        if ($line === false) {
            throw new \RuntimeException('The mailbox did not answer in time.');
        }

        return $line;
    }

    public function read(int $n): string
    {
        $out = '';

        while ($this->socket !== null && strlen($out) < $n) {
            $chunk = @fread($this->socket, min(65536, $n - strlen($out)));

            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('The mailbox connection closed mid-message.');
            }

            $out .= $chunk;
        }

        return $out;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
