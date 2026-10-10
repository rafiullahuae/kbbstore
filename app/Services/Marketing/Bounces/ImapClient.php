<?php

declare(strict_types=1);

namespace App\Services\Marketing\Bounces;

/**
 * The smallest IMAP4rev1 client that reads one Gmail label (Lane EB).
 *
 * Eight commands and nothing else: LOGIN, CAPABILITY, SELECT, UID SEARCH,
 * UID FETCH (BODY.PEEK — reading never marks anything read), CREATE,
 * UID MOVE (RFC 6851; Gmail has it), LOGOUT. Every command that names a
 * message names it by the UID the label's own SEARCH returned, and there is
 * deliberately no plain EXPUNGE, no STORE outside the UIDPLUS fallback, and no
 * way to SELECT a mailbox the caller did not validate (BounceMailbox::label()).
 * That is the "never touch any other mail" guarantee, and
 * EmailBounceReaderTest asserts it on the wire.
 */
final class ImapClient
{
    /** A literal larger than this is refused rather than buffered. */
    public const MAX_LITERAL = 1048576;

    private int $n = 0;

    /** @var list<string> */
    private array $caps = [];

    public function __construct(private ImapTransport $t) {}

    public function connect(string $host, int $port, int $timeout = 20): void
    {
        $this->t->open($host, $port, $timeout);
        $greeting = $this->t->readLine();

        if (! str_starts_with($greeting, '* OK')) {
            throw new \RuntimeException('The mailbox server did not greet as IMAP.');
        }
    }

    public function login(string $user, string $password): void
    {
        [$status] = $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password));

        if ($status !== 'OK') {
            throw new \RuntimeException('Google refused the sign-in. Check the address and the app password, and that IMAP is on (Gmail → Settings → Forwarding and POP/IMAP).');
        }

        [, $lines] = $this->command('CAPABILITY');

        foreach ($lines as $line) {
            if (str_starts_with($line, '* CAPABILITY ')) {
                $this->caps = array_map('strtoupper', explode(' ', substr($line, 13)));
            }
        }
    }

    /** SELECT a mailbox; returns how many messages it holds. */
    public function select(string $mailbox): int
    {
        [$status, $lines] = $this->command('SELECT ' . self::quote($mailbox));

        if ($status !== 'OK') {
            throw new \RuntimeException('There is no Gmail label called "' . $mailbox . '" yet. Create it with the filter in step 2.');
        }

        foreach ($lines as $line) {
            if (preg_match('/^\* (\d+) EXISTS/', $line, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /** @return list<int> every UID in the selected mailbox, ascending */
    public function uids(): array
    {
        [$status, $lines] = $this->command('UID SEARCH ALL');
        $out = [];

        foreach ($status === 'OK' ? $lines : [] as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($line, 8))) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $out[] = (int) $uid;
                    }
                }
            }
        }

        sort($out);

        return $out;
    }

    /** The first $max bytes of one message, without marking it read. */
    public function fetch(int $uid, int $max = 262144): string
    {
        [$status, , $literals] = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[]<0.' . $max . '>)');

        return $status === 'OK' ? (string) ($literals[0] ?? '') : '';
    }

    /** Create a label; "already exists" is not an error. */
    public function ensure(string $mailbox): void
    {
        $this->command('CREATE ' . self::quote($mailbox));
    }

    /**
     * Move one message (by UID) out of the selected label into $dest.
     * Without MOVE, COPY + flag + UID EXPUNGE of THAT uid only (UIDPLUS);
     * without either, it stays where it is rather than risk a plain EXPUNGE.
     */
    public function move(int $uid, string $dest): bool
    {
        if (in_array('MOVE', $this->caps, true)) {
            return $this->command('UID MOVE ' . $uid . ' ' . self::quote($dest))[0] === 'OK';
        }

        if (! in_array('UIDPLUS', $this->caps, true) || $this->command('UID COPY ' . $uid . ' ' . self::quote($dest))[0] !== 'OK') {
            return false;
        }

        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Deleted)');

        return $this->command('UID EXPUNGE ' . $uid)[0] === 'OK';
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
        }

        $this->t->close();
    }

    /**
     * One tagged command. Returns [OK|NO|BAD, untagged lines, literals].
     *
     * @return array{0:string, 1:list<string>, 2:list<string>}
     */
    private function command(string $cmd): array
    {
        $tag = sprintf('K%03d', ++$this->n);
        $this->t->write($tag . ' ' . $cmd . "\r\n");
        $lines = [];
        $literals = [];

        for ($guard = 0; $guard < 20000; $guard++) {
            $line = $this->t->readLine();

            while (preg_match('/\{(\d+)\}\r?\n$/', $line, $m) === 1) {
                $size = (int) $m[1];

                if ($size > self::MAX_LITERAL) {
                    throw new \RuntimeException('A message in the bounce label is larger than expected.');
                }

                $literals[] = $this->t->read($size);
                $line = rtrim($line, "\r\n") . ' ' . $this->t->readLine();
            }

            if (str_starts_with($line, $tag . ' ')) {
                $word = strtoupper((string) strtok(substr($line, strlen($tag) + 1), ' '));

                return [trim($word), $lines, $literals];
            }

            $lines[] = rtrim($line, "\r\n");
        }

        throw new \RuntimeException('The mailbox server sent more than expected.');
    }

    /** An IMAP quoted string. CR, LF and NUL cannot be quoted, so they are refused. */
    public static function quote(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new \InvalidArgumentException('A value sent to the mailbox contains a line break.');
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }
}
