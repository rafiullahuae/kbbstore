<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Session;

/**
 * A small sum, to keep automated sign-ups out.
 *
 * The question is generated on the server and only its answer is kept in the
 * session, so nothing in the page reveals it. Deliberately easy for a person
 * and awkward for a script filling forms blind — a maths question also avoids
 * sending anything to a third party, which a shop in the UAE may prefer.
 */
class HumanCheck
{
    private const KEY = 'kbb.human';
    private const MAX_AGE = 900;   // fifteen minutes

    /** @return array{question:string,token:string} */
    public function issue(): array
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);

        // Addition or multiplication, never subtraction: a negative answer
        // invites a typo rather than proving anything.
        $times = (bool) random_int(0, 1);

        $answer = $times ? $a * $b : $a + $b;
        $token = bin2hex(random_bytes(8));

        Session::put(self::KEY . '.' . $token, ['answer' => $answer, 'at' => time()]);
        $this->prune();

        return [
            'question' => $times ? "{$a} × {$b}" : "{$a} + {$b}",
            'token' => $token,
        ];
    }

    public function passes(?string $token, mixed $answer): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        $row = Session::get(self::KEY . '.' . $token);

        // One attempt per question, right or wrong, so an answer cannot be
        // guessed by repeating the same token.
        Session::forget(self::KEY . '.' . $token);

        if (! is_array($row) || (time() - (int) $row['at']) > self::MAX_AGE) {
            return false;
        }

        return (int) $row['answer'] === (int) $answer;
    }

    /** Drop anything past its life, so the session does not accumulate. */
    private function prune(): void
    {
        $all = Session::get(self::KEY, []);

        if (! is_array($all)) {
            return;
        }

        foreach ($all as $token => $row) {
            if (! is_array($row) || (time() - (int) ($row['at'] ?? 0)) > self::MAX_AGE) {
                Session::forget(self::KEY . '.' . $token);
            }
        }
    }
}
