<?php

declare(strict_types=1);

/*
 * Saving a payment gateway's settings never erases its stored keys.
 *
 * The owner: "if i save anything in the payment gateway setting page, i need
 * to re-enter the api every single time." Measured before the fix: store keys
 * for Stripe, Tabby and Tamara, then press Save with the key boxes left blank
 * (secrets are never sent back to the browser, so that is every save) -- every
 * secret came back empty, and the webhook URL secret was regenerated, so the
 * provider's registered webhook URL stopped matching. Laravel's global
 * ConvertEmptyStringsToNull turned the blank boxes into null, and
 * GatewayCredentials::save() reads null as "remove".
 *
 * MUTATION NOTES, RUN: delete the "blank secret = unchanged" loop in
 * PaymentsApiController::save() → case 1 RED for all three gateways; delete
 * the `clear` loop → case 3 RED.
 */

use App\Models\AdminUser;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;

function pkOwner(): void
{
    test()->actingAs(AdminUser::create([
        'name' => 'Keys Owner', 'email' => 'keys-owner@example.test',
        'password' => 'password-long-enough', 'role' => 'owner',
    ]), 'admin');
}

/** @return list<string> */
function pkSecrets(string $id): array
{
    $schema = app(GatewayRegistry::class)->find($id)?->configSchema() ?? [];

    return array_keys(array_filter($schema, fn ($d) => ($d[0] ?? '') === 'secret'));
}

function pkStored(string $id, string $key): string
{
    app(GatewayCredentials::class)->forget();

    return (string) app(GatewayCredentials::class)->get($id, $key);
}

it('keeps every stored key when the settings are saved with blank key boxes', function (string $id) {
    pkOwner();
    $secrets = pkSecrets($id);
    expect($secrets)->not->toBeEmpty();

    $stored = [];
    foreach ($secrets as $key) {
        $stored[$key] = 'stored-'.$key.'-value';
    }
    app(GatewayCredentials::class)->save($id, $stored, $secrets);

    // Exactly what the screen posts: every visible key box blank. The
    // webhook URL secret is not a box (PAY_HIDDEN_FIELDS), so it is not sent.
    $blank = array_fill_keys(array_values(array_diff($secrets, ['webhook_secret'])), '');
    $this->postJson('/admin-api/payments', ['id' => $id, 'title' => 'Renamed', 'settings' => $blank])
        ->assertOk();

    foreach ($stored as $key => $value) {
        expect(pkStored($id, $key))->toBe($value, "{$id}.{$key} was erased by a save");
    }
})->with(['stripe', 'tabby', 'tamara']);

it('still saves a key that was typed into its box', function () {
    pkOwner();
    app(GatewayCredentials::class)->save('tabby', ['secret_key' => 'old-key'], pkSecrets('tabby'));

    $this->postJson('/admin-api/payments', ['id' => 'tabby', 'settings' => ['secret_key' => 'new-key']])
        ->assertOk();

    expect(pkStored('tabby', 'secret_key'))->toBe('new-key');
});

it('clears a secret only when it is named in clear (the New webhook URL button)', function () {
    pkOwner();
    app(GatewayCredentials::class)->save('tabby', ['secret_key' => 'keep-me', 'webhook_secret' => 'whsec-old'], pkSecrets('tabby'));

    $this->postJson('/admin-api/payments', ['id' => 'tabby', 'settings' => [], 'clear' => ['webhook_secret']])
        ->assertOk();

    expect(pkStored('tabby', 'webhook_secret'))->not->toBe('whsec-old')->not->toBe('')
        ->and(pkStored('tabby', 'secret_key'))->toBe('keep-me');
});
