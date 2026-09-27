<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentGateway;

/**
 * Store → Payments is two columns now, and this is what keeps a field in the
 * right one.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * THE FAULT THE COLUMNS REPLACE. Every row on that screen was an `.ecopt.wide`
 * — `display:block`, a 520px control under a full-width label — so on a 1670px
 * card the right two thirds of every row was empty, and Tamara's twelve fields
 * ran down one column for roughly 1900px with the API tokens interleaved with
 * the basket limits. The owner sent a screenshot with an arrow drawn up the
 * empty column: "on left all keys fields and on right setting things".
 *
 * THE PART THAT NEEDED A GUARD. "Keys on the left" is only true if something
 * knows which fields are keys, and the console is not allowed to know: its
 * sibling PaymentsGatewayTabsTest forbids this screen naming a gateway at all,
 * because a page that hardcodes `tamara` stops following the registry the
 * moment a gateway is added or renamed. So the answer lives in each gateway's
 * own configSchema, as a fourth element beside the type and the help — and the
 * failure mode of that design is silent: a field added without one lands in
 * whichever column the fallback picks, on a screen nobody re-reads, and the
 * owner is told to paste in a capture window.
 *
 * Hence: every entry states its group, and the fallback is a backstop rather
 * than a default.
 */
function pfgSchemas(): array
{
    $out = [];

    foreach (app(GatewayRegistry::class)->all() as $gateway) {
        /** @var PaymentGateway $gateway */
        $out[$gateway->id()] = $gateway->configSchema();
    }

    return $out;
}

it('has a gateway registry worth auditing', function () {
    $schemas = pfgSchemas();

    // Or every assertion below passes over an empty list and proves nothing.
    expect(count($schemas))->toBeGreaterThanOrEqual(4);

    $withFields = array_filter($schemas, fn ($s) => $s !== []);

    expect(count($withFields))->toBeGreaterThanOrEqual(3, 'at least the three remote gateways carry credential fields');
});

/*
 * MUTATION: drop the 'keys' off any one entry in any gateway's configSchema and
 * this is red, naming the gateway and the key. Run.
 */
it('makes every gateway say which column each of its fields belongs in', function () {
    $missing = [];
    $wrong = [];

    foreach (pfgSchemas() as $id => $schema) {
        foreach ($schema as $key => $def) {
            $group = $def[3] ?? null;

            if ($group === null) {
                $missing[] = $id.'.'.$key;

                continue;
            }

            if (! in_array($group, ['keys', 'settings'], true)) {
                $wrong[] = $id.'.'.$key.' => '.var_export($group, true);
            }
        }
    }

    expect($missing)->toBe([], 'these schema entries do not say whether they are a key or a setting, so the '
        . 'payments screen has to guess which column to draw them in: ' . implode(', ', $missing));

    expect($wrong)->toBe([], 'a schema group must be exactly "keys" or "settings": ' . implode(', ', $wrong));
});

/*
 * The classification itself, checked against the one thing that is not a matter
 * of opinion: a `secret` is ALWAYS a key. There is nothing this shop decides
 * that it would keep in a write-only box — a secret exists because a provider
 * issued it, which is the definition of the left column.
 *
 * Settings are not checked the same way round, deliberately. "Is a capture
 * window a setting" is a judgement, and a test that re-states a judgement is a
 * second copy of it that will disagree with the first. The rule below is the
 * half that follows from the type system.
 *
 * MUTATION: move any `secret` to 'settings' and this is red.
 */
it('never files a secret as a setting', function () {
    $strays = [];

    foreach (pfgSchemas() as $id => $schema) {
        foreach ($schema as $key => $def) {
            if (($def[0] ?? '') === 'secret' && ($def[3] ?? '') !== 'keys') {
                $strays[] = $id.'.'.$key;
            }
        }
    }

    expect($strays)->toBe([], 'a secret is a value a provider issued, so it belongs in the keys column: '
        . implode(', ', $strays));
});

/*
 * And the endpoint really carries it, because the columns are drawn from the
 * JSON and not from the PHP. A group that stopped being serialised would put
 * every field in the settings column with nothing red anywhere else.
 *
 * MUTATION: delete the 'group' key from PaymentsApiController's field array and
 * this is red.
 */
it('carries the group through to the screen on every field', function () {
    $admin = AdminUser::create([
        'name' => 'Groups',
        'email' => 'groups-'.uniqid().'@example.com',
        'password' => bcrypt('x'),
        'role' => 'owner',
    ]);

    $body = test()->actingAs($admin, 'admin')->getJson('/admin-api/payments')->assertOk()->json();

    $gateways = $body['gateways'] ?? $body;

    expect($gateways)->toBeArray()->not->toBeEmpty();

    $seenKeys = 0;
    $seenSettings = 0;

    foreach ($gateways as $gateway) {
        foreach (($gateway['fields'] ?? []) as $field) {
            expect($field)->toHaveKey('group');
            expect($field['group'])->toBeIn(['keys', 'settings']);

            $field['group'] === 'keys' ? $seenKeys++ : $seenSettings++;
        }
    }

    /*
     * BOTH SIDES NON-EMPTY, which is the assertion that would have caught the
     * fallback swallowing everything: a serialiser that dropped the group
     * entirely still answers "keys" or "settings" for every field, because the
     * controller defaults. Only counting both columns notices.
     */
    expect($seenKeys)->toBeGreaterThan(0, 'no field reached the screen as a key, so the left column is empty');
    expect($seenSettings)->toBeGreaterThan(0, 'no field reached the screen as a setting, so the right column is empty');
});
