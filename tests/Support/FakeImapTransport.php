<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Marketing\Bounces\ImapTransport;

/**
 * A scripted Gmail IMAP server for Lane EB's reader tests. It holds named
 * mailboxes of [uid => raw message], answers the commands ImapClient sends the
 * way imap.gmail.com does, and keeps every command line it received in $log,
 * so a test can assert what the client SAID — which mailbox it opened, which
 * UIDs it fetched and moved — rather than only what it recorded.
 */
final class FakeImapTransport implements ImapTransport
{
    /** @var list<string> every command line received, password masked */
    public array $log = [];

    public string $password = 'abcdefghijklmnop';

    public bool $move = true;

    private string $buffer = '';

    private ?string $selected = null;

    /** @param array<string, array<int, string>> $boxes */
    public function __construct(public array $boxes = []) {}

    public function open(string $host, int $port, int $timeout): void
    {
        $this->log[] = "OPEN {$host}:{$port}";
        $this->buffer .= "* OK Gimap ready for requests\r\n";
    }

    public function write(string $data): void
    {
        $line = rtrim($data, "\r\n");
        [$tag, $rest] = explode(' ', $line, 2);
        $this->log[] = preg_replace('/^LOGIN ("[^"]*") "[^"]*"$/', 'LOGIN $1 "***"', $rest) ?? $rest;
        $out = '';

        if (preg_match('/^LOGIN "([^"]*)" "([^"]*)"$/', $rest, $m) === 1) {
            $out = $m[2] === $this->password ? "{$tag} OK user authenticated\r\n" : "{$tag} NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)\r\n";
        } elseif ($rest === 'CAPABILITY') {
            $out = '* CAPABILITY IMAP4rev1 UNSELECT IDLE NAMESPACE QUOTA ID XLIST CHILDREN X-GM-EXT-1 UIDPLUS COMPRESS=DEFLATE ENABLE' . ($this->move ? ' MOVE' : '') . " CONDSTORE ESEARCH UTF8=ACCEPT LIST-EXTENDED\r\n{$tag} OK Success\r\n";
        } elseif (preg_match('/^SELECT "(.*)"$/', $rest, $m) === 1) {
            $name = stripcslashes($m[1]);
            if (! isset($this->boxes[$name])) {
                $out = "{$tag} NO [NONEXISTENT] Unknown Mailbox: {$name} (Failure)\r\n";
            } else {
                $this->selected = $name;
                $out = '* ' . count($this->boxes[$name]) . " EXISTS\r\n* 0 RECENT\r\n{$tag} OK [READ-WRITE] {$name} selected. (Success)\r\n";
            }
        } elseif ($rest === 'UID SEARCH ALL') {
            $out = '* SEARCH' . ($this->boxes[$this->selected] ?? [] ? ' ' . implode(' ', array_keys($this->boxes[$this->selected])) : '') . "\r\n{$tag} OK SEARCH completed (Success)\r\n";
        } elseif (preg_match('/^UID FETCH (\d+) \(BODY\.PEEK\[\]<0\.(\d+)>\)$/', $rest, $m) === 1) {
            $raw = substr((string) ($this->boxes[$this->selected][(int) $m[1]] ?? ''), 0, (int) $m[2]);
            $out = '* 1 FETCH (UID ' . $m[1] . ' BODY[]<0> {' . strlen($raw) . "}\r\n" . $raw . ")\r\n{$tag} OK Success\r\n";
        } elseif (preg_match('/^CREATE "(.*)"$/', $rest, $m) === 1) {
            $name = stripcslashes($m[1]);
            $out = isset($this->boxes[$name]) ? "{$tag} NO [ALREADYEXISTS] Duplicate folder name (Failure)\r\n" : "{$tag} OK Success\r\n";
            $this->boxes[$name] ??= [];
        } elseif (preg_match('/^UID MOVE (\d+) "(.*)"$/', $rest, $m) === 1) {
            $uid = (int) $m[1];
            $dest = stripcslashes($m[2]);
            $this->boxes[$dest][$uid] = $this->boxes[$this->selected][$uid];
            unset($this->boxes[$this->selected][$uid]);
            $out = "{$tag} OK [COPYUID 1 {$uid} {$uid}] (Success)\r\n";
        } elseif ($rest === 'LOGOUT') {
            $out = "* BYE LOGOUT Requested\r\n{$tag} OK 73 good day (Success)\r\n";
        } else {
            $out = "{$tag} BAD Unknown command\r\n";
        }

        $this->buffer .= $out;
    }

    public function readLine(): string
    {
        $at = strpos($this->buffer, "\n");

        if ($at === false) {
            throw new \RuntimeException('fake: nothing to read');
        }

        $line = substr($this->buffer, 0, $at + 1);
        $this->buffer = substr($this->buffer, $at + 1);

        return $line;
    }

    public function read(int $n): string
    {
        $out = substr($this->buffer, 0, $n);
        $this->buffer = substr($this->buffer, $n);

        return $out;
    }

    public function close(): void
    {
        $this->log[] = 'CLOSE';
    }
}
