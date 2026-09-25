<?php

/**
 * The `secret` type — Lane M4, round 4 of the per-module settings schema.
 *
 * Round 3 §5 wrote the specification and declined to build it:
 *
 *   > `secret` is the first type whose contract is about what may NOT come
 *   > back, and `fields()` emits `'value'` for every field unconditionally — so
 *   > a schema that can express "encrypted credential" and still uses that loop
 *   > has already been asked to print one and answered. Minimum: a type `read()`
 *   > refuses, `fields()` emits with no `value`, `write()` routes elsewhere,
 *   > plus `"-"` as a third write state.
 *
 * All four are here, each with the mutation that removes it quoted in its own
 * comment. Everything below is driven against a REAL value — `CANARY` — and the
 * assertions are that it is not in the answer, rather than that the answer has
 * some shape. A shape test passes on a payload that happens to carry the bytes
 * under a key nobody thought of.
 */

use App\Services\ModuleSchema;
use App\Services\SecretStore;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/** Distinctive enough that finding it anywhere is unambiguous. */
const M4_SECRET_CANARY = 'KBBM4-SECRET-CANARY-7f3a';

/** A schema with one of everything, so the secret arm is tested beside the rest. */
function m4Schema(): array
{
    return [
        'api_key' => ['type' => 'secret', 'label' => 'API key', 'help' => 'Stored encrypted.'],
        'endpoint' => ['type' => 'text', 'label' => 'Endpoint', 'default' => 'https://example.test'],
        'enabled' => ['type' => 'bool', 'label' => 'On'],
    ];
}

/**
 * A vault that records what it was asked to do.
 *
 * It deliberately implements NOTHING but the interface, which is the point: if
 * ModuleSchema ever needs to read a secret back, this class stops compiling
 * against it and the change is visible in a diff.
 */
final class M4Vault implements SecretStore
{
    /** @var array<string, string|null> */
    public array $calls = [];

    /** @var array<string, string> */
    public array $stored = [];

    public function put(string $key, ?string $value): void
    {
        $this->calls[] = [$key, $value];

        if ($value === null) {
            unset($this->stored[$key]);

            return;
        }

        $this->stored[$key] = $value;
    }

    public function has(string $key): bool
    {
        return ($this->stored[$key] ?? '') !== '';
    }
}

it('gives the schema no way to read a secret back', function () {
    /*
     * ── SECURE BY CONSTRUCTION, NOT BY INTENTION (rule 5) ───────────────────
     *
     * The guarantee is not "ModuleSchema does not call get()". It is that the
     * type it is handed HAS no get(): App\Services\SecretStore is put() and
     * has() and nothing else, and write() types its parameter as that
     * interface. MailCredentials keeps its own get() for MailConfigurator,
     * which has to build a transport out of the password — the narrowing is at
     * the seam, not at the store.
     *
     * MUTATION ACTUALLY RUN: add `public function get(string $key): string;` to
     * SecretStore. It goes red BEFORE this assertion, which is louder than the
     * assertion and worth recording as what really happens:
     *
     *   Pest\Exceptions\FatalException
     *   Class M4Vault contains 1 abstract method and must therefore be declared
     *   abstract or implement the remaining methods (App\Services\SecretStore::get)
     *
     * — every implementation of the interface stops compiling the moment a
     * reader is added to it, which is the guarantee this case is about. The
     * assertion below is the backstop for a reader added with a default
     * implementation, which an interface cannot have today and a trait could.
     */
    $methods = array_map(
        static fn (ReflectionMethod $m) => $m->getName(),
        (new ReflectionClass(SecretStore::class))->getMethods(),
    );

    sort($methods);

    expect($methods)->toBe(['has', 'put'], 'SecretStore grew a reader: '.implode(', ', array_diff($methods, ['has', 'put'])));

    // And that interface really is what write() asks for, rather than a
    // concrete class that happens to have more on it.
    $vaultParam = (new ReflectionMethod(ModuleSchema::class, 'write'))->getParameters()[4];

    expect((string) $vaultParam->getType())->toBe('?'.SecretStore::class);
});

it('does not read a secret back at all — the key is absent, not empty', function () {
    $vault = new M4Vault;
    $settings = app(SettingsService::class);

    ModuleSchema::write($settings, 'm4', m4Schema(), [
        'api_key' => M4_SECRET_CANARY,
        'endpoint' => 'https://real.test',
    ], $vault);

    $read = ModuleSchema::read($settings, 'm4', m4Schema());

    /*
     * ── ABSENT, NOT '' ──────────────────────────────────────────────────────
     *
     * An empty string is a value: a caller can carry it, put it in a payload,
     * log it, and eventually render it, and the day the store behind it starts
     * answering something other than '' none of those call sites changes. A
     * missing key fails at the place somebody reaches for it, which is the only
     * place a mistake here can be fixed.
     *
     * MUTATION ACTUALLY RUN: delete the `if ($f['type'] === 'secret') continue;`
     * arm from ModuleSchema::read() and this goes red with
     *
     *   FAILED … it does not…   LogicException
     *   Module setting “api_key” is a secret; it is written to a SecretStore
     *   and never cast.
     *   at app/Services/ModuleSchema.php:884
     *
     * — read() falls through to `$settings->get('api_key', '')` and then to
     * coerceRead(), which hits cast()'s refusal, so the page 500s rather than
     * leaking. The throw is the BACKSTOP and it fires first; the absence
     * asserted below is the contract, and both are wanted.
     */
    /*
     * `array_key_exists`, NOT `toHaveKey('api_key', '…')`. Pest's toHaveKey
     * takes a KEY and an expected VALUE, so a message in the second slot makes
     * the assertion "does not have this key holding that sentence", which is
     * trivially true and passes on a payload that carries the credential. That
     * is not a hypothetical: it was written that way here, and mutation M3
     * below is what found it — the branch was deleted and the assertion stayed
     * green.
     */
    expect(array_key_exists('api_key', $read))->toBeFalse('ModuleSchema::read() answered a secret');

    expect(json_encode($read))->not->toContain(M4_SECRET_CANARY)
        // ...and the rest of the module reads exactly as it always did.
        ->and($read['endpoint'])->toBe('https://real.test')
        ->and($read['enabled'])->toBeFalse();
});

it('renders a secret with no value key and a boolean presence flag', function () {
    $vault = new M4Vault;
    $vault->put('api_key', M4_SECRET_CANARY);

    /*
     * The presence flags are passed as their own argument, NOT inside
     * `$values`, so a stored credential is never a candidate for the `value`
     * key — and the `(bool)` in fields() means even a store that answered the
     * password itself would emit `true`. This call passes the password AS the
     * flag to prove that.
     *
     * MUTATION ACTUALLY RUN: remove the `secret` branch from
     * ModuleSchema::fields() and this goes red with
     *   a secret was rendered with a value key
     *   Failed asserting that true is false.
     * — the generic line `'value' => $values[$key] ?? $f['default']` runs and
     * the field is emitted with one. That line is the exact thing round 3 §5
     * named as the reason this type could not exist.
     *
     * THAT MUTATION ALSO FOUND A DEFECT IN THIS TEST, which is the argument for
     * running them rather than describing them: the assertion used to be
     * `expect($fields['api_key'])->not->toHaveKey('value', '…message…')`, and
     * Pest's toHaveKey takes a key and an expected VALUE — so the message sat
     * in the value slot and the assertion read "does not have `value` holding
     * that sentence", which is true of a payload carrying the credential. It
     * stayed GREEN with the branch deleted.
     */
    $fields = ModuleSchema::fields(m4Schema(), [], [], [], ['api_key' => M4_SECRET_CANARY]);

    expect(array_key_exists('value', $fields['api_key']))
        ->toBeFalse('a secret was rendered with a value key');
    expect(array_key_exists('default', $fields['api_key']))
        ->toBeFalse('a secret was rendered with a default the console could prefill');

    expect($fields['api_key']['has_value'] ?? null)->toBeTrue()
        ->and($fields['api_key']['has_value'])->toBeBool()
        ->and(json_encode($fields))->not->toContain(M4_SECRET_CANARY);

    // A field with nothing stored says so, and still carries no value key.
    $empty = ModuleSchema::fields(m4Schema(), [], [], [], []);

    expect($empty['api_key']['has_value'] ?? null)->toBeFalse();
    expect(array_key_exists('value', $empty['api_key']))->toBeFalse();

    // And an ordinary field is unaffected, so the branch is a branch and not a
    // change of shape for everybody.
    expect($fields['endpoint']['value'])->toBe('https://example.test');
});

it('writes a secret to the vault and never to a settings table', function () {
    $vault = new M4Vault;
    $settings = app(SettingsService::class);

    $result = ModuleSchema::write($settings, 'm4', m4Schema(), ['api_key' => M4_SECRET_CANARY], $vault);

    expect($result['written'])->toBe(['api_key'])
        ->and($vault->stored['api_key'])->toBe(M4_SECRET_CANARY);

    /*
     * ── THE TABLE THAT MUST NOT HOLD IT ─────────────────────────────────────
     *
     * `GET /admin-api/settings` returns `Setting::map()` — the whole settings
     * table, with no allowlist of any kind. A credential put there is a
     * credential handed to the admin bundle on every page load and into every
     * database backup. That is why MailCredentials exists and why this is
     * asserted against the raw columns rather than against a reader.
     *
     * MUTATION ACTUALLY RUN: change the secret arm of ModuleSchema::write() to
     * `$settings->set($f['alias'], $posted)` and TWO cases go red —
     *
     *   FAILED … it writes a…   ErrorException
     *   Undefined array key "api_key"        (the vault was never asked)
     *
     *   FAILED … it keeps the three write sta…
     *   Failed asserting that false is true. (nothing is stored to forget)
     *
     * The vault assertion fires before the table sweep below, which is the
     * right order — "it did not go to the vault" is the same defect seen one
     * step earlier — and the sweep is what remains true if the vault is ever
     * given a second implementation.
     */
    foreach (['settings', 'module_settings'] as $table) {
        $dump = json_encode(DB::table($table)->get());

        /*
         * `str_contains` and not `->not->toContain($needle, $message)`: Pest's
         * toContain takes NEEDLES, so a message in the second slot is a second
         * needle and the expectation cannot fail. ExpectationsThatCannotFailTest
         * caught this line in the full run, which is the second test-shaped
         * defect this round's own mutations and guards turned up — see the note
         * at the `has_value` assertion above for the first.
         */
        expect(str_contains((string) $dump, M4_SECRET_CANARY))
            ->toBeFalse("the credential reached the {$table} table");
    }
});

it('keeps the three write states a blank box cannot express', function () {
    $vault = new M4Vault;
    $settings = app(SettingsService::class);

    $write = fn (array $values) => ModuleSchema::write($settings, 'm4', m4Schema(), $values, $vault);

    $write(['api_key' => M4_SECRET_CANARY]);
    expect($vault->has('api_key'))->toBeTrue();

    /*
     * 1 · ABSENT — the payload does not mention the key. Nothing happens.
     */
    $write(['endpoint' => 'https://other.test']);
    expect($vault->stored['api_key'])->toBe(M4_SECRET_CANARY, 'a save that never mentioned the key changed it');

    /*
     * 2 · BLANK — the box was rendered empty (it always is) and came back
     * empty. UNCHANGED, and nothing is written at all. This is the state that
     * keeps a save of the SMTP host from wiping the password, which is the
     * commonest thing anybody does on that screen.
     */
    $before = count($vault->calls);
    $write(['api_key' => '']);
    $write(['api_key' => '   ']);

    expect($vault->stored['api_key'])->toBe(M4_SECRET_CANARY, 'a blank box wiped the stored credential')
        ->and(count($vault->calls))->toBe($before, 'a blank box reached the vault at all');

    /*
     * 3 · THE SENTINEL — "forget it". A value cannot say this, because the
     * empty box already means "leave it alone", so the literal carries it.
     *
     * ── THE DEFECT THIS WOULD BE, AND IT IS THE ONE THE BRIEF NAMES ─────────
     *
     * `-` is a sentinel, and a sentinel is exactly what a normalising layer
     * swallows. Put the secret through this schema's own text arm with any
     * ordinary policy and watch what happens to it — the two lines below do
     * exactly that, and they are the reason cast() THROWS on a secret rather
     * than quietly doing something reasonable. Under `blank => 'default'` the
     * `-` is not even preserved as a `-`; under `markup => 'strip'` it survives
     * today and would not the first time that strip grew a punctuation rule.
     *
     * MUTATION ACTUALLY RUN: drop the `trim()` in write()'s secret arm — the
     * smallest plausible normalising change, and exactly the kind a cast
     * introduces — and this goes red with
     *
     *   a blank box wiped the stored credential
     *   Failed asserting that two strings are identical.
     *   -'KBBM4-SECRET-CANARY-7f3a'
     *   +'   '
     *
     * — three spaces become the SMTP password, and Store → Mail goes on saying
     * a password is stored. The sentinel half of the same mutation is one line
     * further on: without the trim, ` - ` is a two-character password rather
     * than a command to forget.
     *
     * Routing the secret through castText() instead was also run, and is
     * recorded on the case below: it leaves `-` intact under this policy, which
     * is why the throw exists rather than a "safe" cast — the next policy would
     * not leave it intact and nothing would say so.
     */
    $write(['api_key' => ' - ']);   // padded, because write() trims before comparing

    expect($vault->has('api_key'))->toBeFalse('the forget sentinel was swallowed by a cast')
        ->and(array_key_exists('api_key', $vault->stored))->toBeFalse();

    // And a value that merely LOOKS like the sentinel is a password, not a
    // command. `--` is two characters and is stored.
    $write(['api_key' => '--']);
    expect($vault->stored['api_key'])->toBe('--');

    /*
     * 4 · ANYTHING ELSE — stored verbatim. A credential is bytes the owner was
     * given by a mail host; it is not trimmed to a cap, not stripped of tags,
     * and not substituted for a default.
     */
    $write(['api_key' => '<b>p@ss</b> '.str_repeat('x', 400)]);
    expect($vault->stored['api_key'])->toBe('<b>p@ss</b> '.str_repeat('x', 400));
});

it('refuses to cast a secret, which is what stops a future normaliser', function () {
    /*
     * The backstop for everything above. Every other type has a cast that
     * trims, caps and substitutes; all three are wrong for a credential, and
     * the `blank => 'default'` one would turn the forget sentinel into the
     * shipped default. So there is no secret arm in the match — there is a
     * throw before it.
     *
     * MUTATION ACTUALLY RUN: delete the throw and let a secret fall through to
     * `default => self::castText(...)`, and this goes red with
     *   Failed asserting that exception of type "\LogicException" is thrown
     * — and the round-trip case above goes red too, which is the pair that
     * matters: the throw alone proves nothing, and the sentinel alone would
     * pass on a cast that happened not to eat a hyphen.
     */
    $field = ModuleSchema::normalise(m4Schema())['api_key'];

    expect(fn () => ModuleSchema::cast($field, M4_SECRET_CANARY))->toThrow(LogicException::class);
    expect(fn () => ModuleSchema::cast($field, '-'))->toThrow(LogicException::class);

    // describe() answers a constant and never looks at the value, so a screen
    // summarising a module cannot print one.
    expect(ModuleSchema::describe($field, M4_SECRET_CANARY))->toBe('stored, and not shown');
});

it('fails closed when a secret is declared without somewhere to put it', function () {
    /*
     * A schema that declares a credential and a caller that forgot the vault is
     * a save that reports success and writes the password nowhere — or, under a
     * later refactor, into `settings`. An exception at the call site is the
     * cheap version of finding that out.
     *
     * MUTATION ACTUALLY RUN: drop the `$vault === null` guard and this goes red
     * with
     *   Failed asserting that an instance of class Error is an instance of
     *   class LogicException.
     * — PHP's own "Call to a member function put() on null", which is a stack
     * trace on Store → Mail rather than a sentence naming the schema and the
     * key. Red either way; the guard is what makes it readable.
     */
    expect(fn () => ModuleSchema::write(app(SettingsService::class), 'm4', m4Schema(), ['api_key' => 'x']))
        ->toThrow(LogicException::class);

    // A payload that does not mention the secret needs no vault, so an
    // ordinary tab save is unaffected.
    expect(fn () => ModuleSchema::write(app(SettingsService::class), 'm4', m4Schema(), ['endpoint' => 'https://ok.test']))
        ->not->toThrow(LogicException::class);
});

it('ties the secret type and the vault store together in both directions', function () {
    /*
     * The two halves of the contract have to travel together or each one is a
     * way to lose the other:
     *
     *   a secret NOT in the vault  would be written into `settings` by write()'s
     *   ordinary arms — the exact trap MailSecretsTest exists for.
     *
     *   a non-secret IN the vault  would be routed to the credential store by
     *   write() and then emitted WITH ITS VALUE by fields(), because fields()
     *   only withholds a value from a `secret`. This is the likelier mistake of
     *   the two and the one a reader would not see.
     *
     * MUTATION ACTUALLY RUN: delete the cross-check in ModuleSchema::field()
     * and this goes red with
     *   Failed asserting that exception of type "\InvalidArgumentException" is thrown
     * on both cases — and a `text` field declared `store: vault` then renders
     * its stored credential into the payload.
     */
    expect(fn () => ModuleSchema::normalise([
        'k' => ['type' => 'secret', 'label' => 'k', 'store' => ModuleSchema::STORE_SETTING],
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => ModuleSchema::normalise([
        'k' => ['type' => 'text', 'label' => 'k', 'store' => ModuleSchema::STORE_VAULT],
    ]))->toThrow(InvalidArgumentException::class);

    // A rule may not replace a secret's handling either — the same closure
    // round 2 shut on colours and selects, for the same reason: the guarantee
    // is the type's, and it may not be handed to a module-local function.
    expect(fn () => ModuleSchema::normalise([
        'k' => ['type' => 'secret', 'label' => 'k', 'rule' => static fn ($v) => $v],
    ]))->toThrow(InvalidArgumentException::class);

    // And the declaration that IS right needs no `store` at all: a secret
    // defaults to the vault, so the two cannot drift apart by omission.
    $field = ModuleSchema::normalise(['k' => ['type' => 'secret', 'label' => 'k']])['k'];

    expect($field['store'])->toBe(ModuleSchema::STORE_VAULT);
});
