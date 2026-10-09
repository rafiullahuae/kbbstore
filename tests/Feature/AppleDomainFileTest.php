<?php

declare(strict_types=1);

/*
 * Apple's domain association file: Apple's file or nothing. (Lane WL.)
 *
 * THE DEFECT, ON THE SHOP. 9 October 2026: the owner tested on an iPhone and
 * an Android phone and neither wallet button appeared. kbeautybliss.com served
 * /.well-known/apple-developer-merchantid-domain-association as 28 bytes,
 * `pmd_1UNuE9LD8AK6OnidAz8Neu5t` — Stripe's ID for the payment-method-domain
 * row, pasted into the box meant for Apple's file. The box accepted it, the
 * route served it, and nothing on any screen said it was wrong. Apple cannot
 * verify a domain from it, so Stripe never offered Apple Pay there.
 */

use App\Http\Controllers\Store\AppleDomainController;
use App\Models\AdminUser;
use App\Models\PaymentProvider;
use App\Services\Payments\AppleDomainFile;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Wallets;
use Tests\Support\WalletRoutes;

/** Apple's real file is one long line of hex: a hex-encoded JSON document. */
function adfAppleFile(int $length = 9000): string
{
    return substr('7B227073704964223A22'.str_repeat('3941363341384542', (int) ceil($length / 16)), 0, $length);
}

function adfStripe(array $extra = []): void
{
    $row = PaymentProvider::firstOrNew(['id' => 'stripe']);
    $row->fill(['title' => 'Credit or debit card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $row->config = array_merge([
        'publishable_key_test' => 'pk_test_adf_key',
        'secret_key_test' => 'sk_test_adf_key',
        'wallet_apple_pay' => '1',
        'wallet_google_pay' => '1',
    ], $extra);
    $row->save();

    app(GatewayCredentials::class)->forget();
    app(Wallets::class)->forget();
}

function adfOwner(): AdminUser
{
    return AdminUser::create(['name' => 'ADF owner', 'email' => 'adf-'.uniqid().'@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
}

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    app(Wallets::class)->forget();
    WalletRoutes::wire(app());
    config(['app.url' => 'https://kbeautybliss.com']);
});

it('does not serve a pmd_ Stripe domain ID as Apple’s file — the live shop’s exact defect', function () {
    adfStripe([AppleDomainController::CONFIG_KEY => 'pmd_1UNuE9LD8AK6OnidAz8Neu5t']);

    $this->get(AppleDomainFile::PATH)->assertNotFound();
    $this->get(AppleDomainFile::PATH.'.txt')->assertNotFound();
});
// MUTATION, run: make AppleDomainFile::problem() return null for everything
// (the old "any text without < or >" rule). RED — 200 with the 28-byte pmd_
// body, which is what kbeautybliss.com served on 9 October.

it('serves a full-size Apple file byte for byte, larger than the old 8 KB cap', function () {
    $file = adfAppleFile(9000);
    adfStripe([AppleDomainController::CONFIG_KEY => $file."\n"]);

    expect($this->get(AppleDomainFile::PATH)->assertOk()->getContent())->toBe($file);
});
// MUTATION, run: set AppleDomainFile::MAX_BYTES back to 8192. RED — a real
// signed file over 8 KB would 404 and Apple's verification would fail with
// nothing on the shop to say why.

it('names the mistake and says what to paste, for each kind of wrong value', function () {
    expect(AppleDomainFile::problem('pmd_1UNuE9LD8AK6OnidAz8Neu5t'))
        ->toContain('Stripe’s ID for your domain')
        ->toContain('pmd_1UNu…')
        ->not->toContain('LD8AK6OnidAz8Neu5t')
        ->toContain('Apple Pay domain file');

    expect(AppleDomainFile::problem('apwc_1ABCdef'))->toContain('looks like an ID');
    expect(AppleDomainFile::problem('<html>'.str_repeat('a', 300)))->toContain('only digits and the letters A–F');
    expect(AppleDomainFile::problem(str_repeat('7B', 20)))->toContain('too short');
    expect(AppleDomainFile::problem(str_repeat('7B', AppleDomainFile::MAX_BYTES)))->toContain('far longer');
    expect(AppleDomainFile::problem(''))->toContain('Nothing has been pasted');
    expect(AppleDomainFile::problem(adfAppleFile()))->toBeNull();
});
// MUTATION, run: delete the pmd_ branch. RED on the first expectation — the
// owner would get "only digits" instead of being told he pasted the domain ID.

it('refuses a pmd_ paste on save, with the fix in the message', function () {
    $this->actingAs(adfOwner(), 'admin');
    adfStripe();

    $res = $this->postJson('/admin-api/payments', ['id' => 'stripe', 'settings' => [
        AppleDomainController::CONFIG_KEY => 'pmd_1UNuE9LD8AK6OnidAz8Neu5t',
    ]])->assertStatus(422);

    expect($res->json('errors.'.AppleDomainController::CONFIG_KEY))->toContain('not Apple’s file');
    app(GatewayCredentials::class)->forget();
    expect(app(GatewayCredentials::class)->get('stripe', AppleDomainController::CONFIG_KEY))->toBe('');

    $this->postJson('/admin-api/payments', ['id' => 'stripe', 'settings' => [
        AppleDomainController::CONFIG_KEY => adfAppleFile(),
    ]])->assertOk();
});
// MUTATION, run: delete the AppleDomainFile block from
// StripeGateway::validateConfig(). RED — the pmd_ is stored, as it was live.

it('does not hold up an unrelated save because a bad value is ALREADY stored', function () {
    /*
     * The live shop has the pmd_ stored today. Turning cards off, or switching
     * Mode, must never wait on the Apple Pay box: the stored value is shown
     * red on the status block instead.
     */
    $this->actingAs(adfOwner(), 'admin');
    adfStripe([AppleDomainController::CONFIG_KEY => 'pmd_1UNuE9LD8AK6OnidAz8Neu5t']);

    $this->postJson('/admin-api/payments', ['id' => 'stripe', 'enabled' => false, 'settings' => [
        AppleDomainController::CONFIG_KEY => 'pmd_1UNuE9LD8AK6OnidAz8Neu5t',
        'wallet_google_pay' => '1',
    ]])->assertOk();

    expect(PaymentProvider::find('stripe')->enabled)->toBeFalse();
});
// MUTATION, run: drop the "unchanged" condition in validateConfig(). RED —
// a 422 on a save that only switched the card gateway off.

it('shows the wallets, Apple’s file red or green, and the domain to register on the Stripe status block', function () {
    $this->actingAs(adfOwner(), 'admin');
    adfStripe([AppleDomainController::CONFIG_KEY => 'pmd_1UNuE9LD8AK6OnidAz8Neu5t']);

    $w = $this->getJson('/admin-api/payments/stripe/webhook')->assertOk()->json('wallets');

    expect($w['apple_pay'])->toBeTrue()
        ->and($w['google_pay'])->toBeTrue()
        ->and($w['offered'])->toBe(['apple_pay' => true, 'google_pay' => true])
        ->and($w['file']['ok'])->toBeFalse()
        ->and($w['file']['problem'])->toContain('Stripe’s ID for your domain')
        ->and($w['file_url'])->toBe('https://kbeautybliss.com/.well-known/apple-developer-merchantid-domain-association')
        ->and($w['register'])->toBe(['kbeautybliss.com', 'www.kbeautybliss.com']);

    adfStripe([AppleDomainController::CONFIG_KEY => adfAppleFile(), 'wallet_google_pay' => '']);
    $w = $this->getJson('/admin-api/payments/stripe/webhook')->assertOk()->json('wallets');

    expect($w['file'])->toBe(['source' => 'pasted', 'ok' => true, 'problem' => null])
        ->and($w['google_pay'])->toBeFalse()
        ->and($w['offered']['google_pay'])->toBeFalse();

    // The panel draws it, escaped.
    $panel = file_get_contents(resource_path('views/admin/partials/stripe-settings-panel.blade.php'));
    expect(substr_count($panel, 'html += wallets(s.wallets);'))->toBe(1)
        ->and($panel)->toContain("esc(file.problem || '')");
});
// MUTATION, run: drop 'wallets' from StripeConnect::settingsStatus(). RED —
// and the owner is back to a screen that cannot say why no button shows.

it('builds the Apple file address from the shop’s own domain, never a hard-coded one', function () {
    config(['app.url' => 'https://example-shop.test']);

    expect(AppleDomainFile::url())->toBe('https://example-shop.test/.well-known/apple-developer-merchantid-domain-association');

    config(['app.url' => 'https://www.example-shop.test']);
    expect(AppleDomainFile::domainsToRegister())->toBe(['example-shop.test', 'www.example-shop.test']);

    foreach (['routes/wallet-domain.php', 'app/Http/Controllers/Store/AppleDomainController.php', 'docs/WALLETS-APPLE-GOOGLE-PAY.md'] as $file) {
        expect(substr_count((string) file_get_contents(base_path($file)), 'https://extrabeauty.ae'))->toBe(0, $file);
    }
});
// MUTATION, run: put `https://extrabeauty.ae` back in the route comment. RED.

it('shows a gateway’s refusal sentence whole on the payments screen, not its first letter', function () {
    /*
     * THE DEFECT, ON THE ADMIN. validateConfig() answers one sentence per
     * field; paySave() read `d.errors[k][0]`, Laravel's list shape, so every
     * such refusal — the statement descriptor's, the wrong-key's, and now the
     * pmd_ one — toasted "Could not save — T". Measured in Chromium before the
     * fix; after it the toast carries the whole sentence.
     */
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $start = strpos($console, 'async function paySave(');
    $body = substr($console, $start, 4000);

    expect($start)->not->toBeFalse()
        ->and($body)->toContain('Array.isArray(v)?v[0]:v')
        // Negative over the WHOLE console, not a window in front of it.
        ->and($console)->not->toContain('return d.errors[k][0];');
});
// MUTATION, run: put `return d.errors[k][0];` back in paySave(). RED.
