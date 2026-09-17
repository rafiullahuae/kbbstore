<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * A TCP port the preview servers can actually have.
 *
 * WHAT WENT WRONG. Every helper that boots a `php -S` preview picked its port
 * the same way:
 *
 *     $port = 8400 + random_int(30, 120);
 *
 * — one draw from a band of ninety-one, used immediately, with nothing checking
 * whether anything was already on it and no second attempt if there was. The
 * failure is not subtle when it lands, but it names the wrong thing:
 *
 *     RuntimeException: preview server never answered on http://127.0.0.1:8477
 *     [..] Failed to listen on 127.0.0.1:8477 (reason: Address already in use)
 *     at tests/Feature/AdminMobileOverflowTest.php:214
 *
 * which reads as "the preview is broken" and is really "somebody else is on
 * that port".
 *
 * TWO WAYS IT BITES, and the second is the one that made it worth a class.
 *
 * Two lanes running the browser half at once collide on roughly one boot in
 * ninety-one, which is rare enough to be dismissed as flake and re-run.
 *
 * Worse, a preview server OUTLIVES a run that was killed before its $stop()
 * could fire — a SIGKILLed suite, a container going away, a lane interrupted
 * mid-test. That server keeps its port for as long as the machine is up, and
 * from then on every future run in every worktree that draws that number fails,
 * permanently and for everybody. Found exactly that way: ports 8434, 8477, 8492,
 * 8493 and 8577 on this host were all held by leaked `php -S` processes from
 * four OTHER lanes' worktrees, one of them nearly a day old, and 8477 is what
 * broke AdminMobileOverflowTest here. Nothing in this checkout put it there and
 * nothing in this checkout could have avoided it by drawing again at random.
 *
 * THE FIX is to ask the operating system instead of guessing: walk the band from
 * a random start and take the first port that will actually bind. A leaked
 * server is then simply skipped, and two concurrent lanes cannot be handed the
 * same number unless they bind in the same instant.
 *
 * The residual race is the gap between closing the test socket and `php -S`
 * opening its own, which is microseconds and which the caller's existing
 * start-up poll already reports honestly. That is a different and far smaller
 * thing than drawing a number blind, and there is no way to hand an already-open
 * socket to `php -S`.
 *
 * The bands are left exactly where each caller had them. They do not need to be
 * unique — the scan makes overlap harmless — and keeping them puts each test's
 * servers where whoever debugs it has learnt to look.
 */
final class PreviewPort
{
    /**
     * The first port in [$low, $high] that nothing is listening on.
     *
     * @throws RuntimeException if the whole band is occupied
     */
    public static function claim(int $low, int $high): int
    {
        $span = $high - $low + 1;
        $start = random_int(0, $span - 1);

        for ($i = 0; $i < $span; $i++) {
            $port = $low + (($start + $i) % $span);

            /*
             * A real bind, not a connect() probe. Connecting tells you whether
             * something ANSWERS; binding tells you whether `php -S` will be
             * allowed to start, which is the actual question. They differ for a
             * socket held in TIME_WAIT or by a process that is listening but
             * wedged -- which is precisely the leaked-preview case above.
             */
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);

            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
        }

        throw new RuntimeException(
            "no free port in {$low}-{$high}: every one of the {$span} is in use. "
            .'Something is probably leaking `php -S` preview servers; '
            ."`ss -ltnp | grep -E ':8[0-9]{3}'` will say who."
        );
    }
}
