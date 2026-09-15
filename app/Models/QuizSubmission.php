<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizSubmission extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recommended_routines' => 'array',
            'utm' => 'array',
            'consent' => 'bool',
            'expert_requested' => 'bool',
            'expert_requested_at' => 'datetime',
            'consent_at' => 'datetime',
        ];
    }

    /**
     * The handle this lead is addressed by on the public API.
     *
     * POST /api/quiz answers with this instead of the bare primary key, and
     * POST /api/quiz/{id}/expert-request only accepts this. The row id is a
     * small sequential integer, so on its own it proved nothing: anyone could
     * count upwards and attach an "expert request" -- with an arbitrary 2000
     * character message, and a status change -- to every customer's quiz
     * submission in the table. The signature is the proof that the caller is
     * the browser that filled the quiz in, which is the only ownership this
     * flow has: there is no account behind a skin quiz.
     *
     * The id stays in the clear so the row is still findable; the signature is
     * what cannot be guessed. Keyed on the app key, so a token does not
     * survive a key rotation, and truncated only because 128 bits of HMAC is
     * already far past anything brute-forceable through a throttled endpoint.
     */
    public function publicToken(): string
    {
        return $this->id . '-' . static::signatureFor((int) $this->id);
    }

    /**
     * Resolve a token back to its row, or null.
     *
     * Null covers both "no such lead" and "that signature is not yours", and
     * deliberately cannot tell them apart -- the row is looked up before the
     * signature is checked and the comparison is hash_equals, so a forged
     * token and a lead id that was never issued do the same work and give the
     * same answer. A caller that branched differently on the two would turn
     * this back into the id oracle it replaced.
     */
    public static function findByPublicToken(?string $token): ?static
    {
        [$rawId, $signature] = array_pad(explode('-', (string) $token, 2), 2, '');

        $submission = static::query()->find((int) $rawId);
        $valid = hash_equals(static::signatureFor((int) $rawId), (string) $signature);

        return $valid && $submission ? $submission : null;
    }

    private static function signatureFor(int $id): string
    {
        return substr(hash_hmac('sha256', 'quiz-lead:' . $id, (string) config('app.key')), 0, 32);
    }
}
