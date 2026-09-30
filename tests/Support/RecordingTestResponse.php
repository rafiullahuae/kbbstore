<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * A TestResponse that says what its assertSee was matched against.
 *
 * Built ONLY while a needle scan is running — see Tests\Support\NeedleScan and
 * Tests\TestCase::createTestResponse(). On every ordinary run of this suite the
 * framework's own TestResponse is used and this class is never loaded.
 *
 * It changes no behaviour: it records and then calls the parent, so the
 * assertion that runs is Laravel's, including its escaping and its exception
 * decoration.
 *
 * assertSee ESCAPES BY DEFAULT and the recorded needle is the raw argument, not
 * the escaped one — which is what the survey wants. The flag travels with it so
 * a row whose needle contains `&`, `<` or a quote can be read correctly rather
 * than being reported as absent from a page that does contain it.
 */
final class RecordingTestResponse extends TestResponse
{
    /**
     * @param  array<int, string>|string  $value
     * @param  bool  $escape
     * @return $this
     */
    public function assertSee($value, $escape = true)
    {
        foreach ((array) $value as $one) {
            if (is_string($one)) {
                NeedleScan::see($one, (string) $this->getContent(), (bool) $escape);
            }
        }

        return parent::assertSee($value, $escape);
    }
}
