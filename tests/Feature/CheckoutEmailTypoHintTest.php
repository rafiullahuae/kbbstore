<?php

declare(strict_types=1);

/*
 * Lane EH — checkout: "Did you mean jovana8b@yahoo.com?" under the email field.
 *
 * The owner: shoppers type jovana8b@yahoo.co and never receive their order
 * emails. He picked style A (docs/checkout-email-hint/): the grey line under
 * the field becomes the hint once the field is LEFT -- "during write no any
 * disturbance" -- one tap on the domain fixes it. "give this option to turn
 * on/off on the backend under Emails > settings somewhere", "keep turned off
 * by default", "hints are fine, but must be accurate".
 *
 * What each defect would have looked like on the shop is said beside its test.
 * The browser half (typing shows nothing, leaving shows the line, the tap
 * fixes the field, place order still works, no console error, no overflow at
 * 320-1280) is tools/eh-walk/walk.mjs, in Chromium, because this suite has no
 * DOM.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Support\CheckoutEmailFix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ArabicShop;
use Tests\Support\EmailsAdminRoutes;
use Tests\Support\EmailTypoCases;

const EH_MIGRATION = '2027_11_04_100000_seed_checkout_email_fix_arabic_draft.php';

beforeEach(function () {
    PaymentProvider::query()->delete();
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 1500, 'enabled' => true, 'position' => 0]);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    $GLOBALS['ehCart'] = null;
    ehForget();
});

function ehForget(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function ehSwitch(bool $on): void
{
    app(SettingsService::class)->set(CheckoutEmailFix::KEY, $on ? '1' : '0');
    ehForget();
}

/** One cart for the whole test, so two renders differ only where the views do. */
function ehCart(): Cart
{
    if (! ($GLOBALS['ehCart'] ?? null) instanceof Cart) {
        $product = Product::create(['slug' => 'eh-'.Str::random(8), 'name' => 'Glass Skin Set', 'status' => 'publish',
            'is_visible' => true, 'price' => 37500, 'stock_status' => 'instock']);
        $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
            'shipping_country' => 'AE', 'last_activity_at' => now()]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 37500]);
        $GLOBALS['ehCart'] = $cart;
    }

    return $GLOBALS['ehCart'];
}

function ehGet(string $path = '/checkout'): string
{
    test()->flushSession();
    app('auth')->forgetGuards();
    app(CartService::class)->forget();

    $html = (string) test()->withCredentials()
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, ehCart()->token)
        ->get($path)->assertOk()->getContent();

    return \Tests\Support\EnglishRenderWalk::mask($html);
}

/**
 * contact-email.blade.php as it was before this lane: the current file with
 * Lane EH's block put back to the three lines it replaced. Built from the
 * current file, so the comparison is exactly "this lane's block" and nothing
 * another lane changes later in the file.
 */
function ehBeforePartialDir(): string
{
    $now = (string) file_get_contents(resource_path('views/partials/checkout/contact-email.blade.php'));
    $start = strpos($now, '{{-- "Did you mean …@yahoo.com?" (Lane EH)');
    $end = strrpos($now, "@endif\n");
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    $before = substr($now, 0, $start)
        ."@if (! empty(\$hint) && (\$kbbEmailHint = \$kbbCoPage->emailHint()) !== '')\n"
        ."                        <p class=\"co-ehint\">{{ \$kbbEmailHint }}</p>\n"
        ."@endif\n"
        .substr($now, $end + strlen("@endif\n"));

    $dir = storage_path('framework/testing/eh-before-'.getmypid());
    @mkdir($dir.'/partials/checkout', 0777, true);
    file_put_contents($dir.'/partials/checkout/contact-email.blade.php', $before);

    return $dir;
}

function ehRenderBefore(string $path = '/checkout'): string
{
    $finder = View::getFinder();
    $paths = $finder->getPaths();
    $finder->prependLocation(ehBeforePartialDir());
    $finder->flush();

    try {
        return ehGet($path);
    } finally {
        $finder->setPaths($paths);
        $finder->flush();
    }
}

function ehNode(string $js): string
{
    $node = new Process(['node', '--input-type=module', '-e', $js], base_path());
    $node->run();

    if (! $node->isSuccessful() && (str_contains($node->getErrorOutput(), 'not found') || $node->getExitCode() === 127)) {
        test()->markTestSkipped('node is not installed here');
    }

    expect($node->isSuccessful())->toBeTrue($node->getErrorOutput());

    return $node->getOutput();
}

function ehBlock(string $file): string
{
    $src = (string) file_get_contents($file);
    $start = strpos($src, '/*kxe-suggest-start*/');
    $end = strpos($src, '/*kxe-suggest-end*/');
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($src, $start, $end - $start);
}

/* ═══════════════════════════════ 1. OFF ═══════════════════════════════════ */

it('ships OFF, and off the checkout is byte-identical to the checkout before this existed', function () {
    // The owner: "keep turned off by default". On the shop, a default of true
    // would have put a new line under every shopper's email field the moment
    // the package was applied, with nobody having turned it on.
    // MUTATION: CheckoutEmailFix::DEFAULT = true -> red; print the marker
    // outside the enabled() @if -> the byte comparison is red.
    expect(CheckoutEmailFix::DEFAULT)->toBeFalse()
        ->and(CheckoutEmailFix::enabled())->toBeFalse()
        ->and(DB::table('settings')->where('key', CheckoutEmailFix::KEY)->exists())->toBeFalse();

    // Three shapes of the field: the shipped 13 October layout (grey line on),
    // the grey line switched off, and the old two-step layout (no grey line).
    $shapes = [
        'default' => fn () => null,
        'grey line off' => fn () => app(CheckoutPage::class)->save(['lo_email_hint_on' => false]),
        'old layout' => fn () => app(\App\Services\CheckoutLayout::class)->restore(),
    ];

    foreach ($shapes as $name => $shape) {
        $shape();
        ehForget();

        foreach ([false, null] as $state) {   // never saved, and saved as off
            if ($state === false) {
                ehSwitch(false);
            }

            $before = ehRenderBefore();
            $control = ehRenderBefore();
            $now = ehGet();

            expect($control)->toBe($before, $name.': the control render differs, so the mask is not enough')
                ->and($now)->toBe($before, $name.': the OFF checkout changed')
                ->and($now)->not->toContain('data-efix')
                ->and($now)->not->toContain('email-fix')
                ->and($now)->not->toContain('email-suggest');
        }

        DB::table('settings')->where('key', CheckoutEmailFix::KEY)->delete();
        ehForget();
    }
});

it('references no hint chunk from any page: checkout.js imports it only when the marker is there', function () {
    // On the shop, a static import would have put the hint's code into every
    // page's bundle, switched on or not. MUTATION: change checkout.js to
    // `import { startEmailFix } from './email-fix.js'` -> red.
    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'));

    expect($js)->toContain("const emailFix = form.querySelector('[data-efix]');")
        ->and($js)->toContain("import('./email-fix.js')")
        ->and(preg_match('/^\s*import\s[^(]*[\'"]\.\/email-(fix|suggest)\.js/m', $js))->toBe(0)
        ->and(preg_match('/\bimport\s*\(\s*[\'"]\.\/email-fix\.js[\'"]\s*\)/', $js))->toBe(1);

    $fix = (string) file_get_contents(resource_path('js/kbb/email-fix.js'));
    // No request of its own, no layout measuring (CLAUDE.md rule 4), no timer.
    foreach (['fetch(', 'XMLHttpRequest', 'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'scrollWidth', 'setInterval', 'setTimeout', 'sendBeacon'] as $banned) {
        expect($fix)->not->toContain($banned);
    }

    // The built bundle: the hint is its own chunk, fetched by import() only.
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    expect($manifest['resources/js/kbb/app.js']['dynamicImports'] ?? [])->toContain('resources/js/kbb/email-fix.js')
        ->and($manifest['resources/js/kbb/app.js']['imports'] ?? [])->not->toContain('resources/js/kbb/email-fix.js')
        ->and($manifest)->toHaveKey('resources/js/kbb/email-fix.js');

    $chunk = $manifest['resources/js/kbb/email-fix.js']['file'];
    expect(ehGet())->not->toContain(basename($chunk));
    ehSwitch(true);
    // Not even on: the marker is printed, the chunk is fetched by the script.
    expect(ehGet())->not->toContain(basename($chunk))->and(ehGet())->toContain('data-efix=');
});

/* ═══════════════════════════════ 2. ON ════════════════════════════════════ */

it('prints one marker carrying the translated sentence, on the grey line or in its place', function () {
    // On the shop, a missing marker is a switch that does nothing; two markers
    // would start the hint twice and draw two lines.
    // MUTATION: drop the @elseif branch -> the old-layout case is red.
    ehSwitch(true);

    $default = ehGet();
    expect(substr_count($default, 'data-efix='))->toBe(1)
        ->and($default)->toContain('<p class="co-ehint" data-efix="Did you mean :email?">We send your order confirmation here.</p>');

    app(CheckoutPage::class)->save(['lo_email_hint_on' => false]);
    ehForget();
    $noGrey = ehGet();
    expect(substr_count($noGrey, 'data-efix='))->toBe(1)
        ->and($noGrey)->toContain('<p class="co-ehint" data-efix="Did you mean :email?" hidden></p>')
        ->and($noGrey)->not->toContain('We send your order confirmation here.');

    app(\App\Services\CheckoutLayout::class)->restore();
    ehForget();
    $old = ehGet();
    expect(substr_count($old, 'data-efix='))->toBe(1)
        ->and($old)->toContain('<p class="co-ehint" data-efix="Did you mean :email?" hidden></p>');

    // The marker sits inside the form, right under the email field.
    expect($default)->toMatch('#id="billing_email"[^>]*/>.*?</p>\s*<p class="co-ehint" data-efix=#s');
});

it('reads in Arabic on /ar once approved, with the address kept left-to-right by the script', function () {
    // On the shop: an English sentence inside the Arabic checkout, or
    // "moc.oohay" mirrored by the bidi algorithm. MUTATION: drop __() for a
    // literal -> red; drop `addr.dir = 'ltr'` in email-fix.js -> red.
    ehSwitch(true);
    ArabicShop::on();
    ArabicShop::string('store.checkout.email_fix', 'هل تقصدين :email؟');

    $ar = ehGet('/ar/checkout');
    expect($ar)->toContain('data-efix="هل تقصدين :email؟"')
        ->and(substr_count($ar, 'data-efix='))->toBe(1);

    $fix = (string) file_get_contents(resource_path('js/kbb/email-fix.js'));
    expect($fix)->toContain("document.createElement('bdi')")
        ->and($fix)->toContain("addr.dir = 'ltr';");

    // English is untouched by the Arabic string.
    expect(ehGet('/checkout'))->toContain('data-efix="Did you mean :email?"');
});

/* ═════════════════════ 3. the suggestion: one function ═════════════════════ */

it('runs the shared table through the exact module checkout imports, and agrees with the admin copy', function () {
    // "hints are fine, but must be accurate." On the shop, a drifted copy is a
    // real yahoo.co.uk customer told to switch to yahoo.com.
    // MUTATION: edit one rule in email-suggest.js only (say, drop 'mail.com'
    // from KXE_KEEP) -> the identical-block check AND the table are red.
    $cases = EmailTypoCases::all();
    expect(count($cases))->toBeGreaterThanOrEqual(60);

    // The two blocks are the same source, indentation aside.
    $norm = fn (string $s) => implode("\n", array_map('trim', explode("\n", $s)));
    $admin = ehBlock(resource_path('views/admin/partials/customer-contact-edit.blade.php'));
    $module = ehBlock(resource_path('js/kbb/email-suggest.js'));
    expect($norm($module))->toBe($norm($admin));

    // Extra probes beyond the table, so the comparison is not only the table.
    $probes = array_merge(array_keys($cases), [
        'x@gmaill.com', 'x@gmail.cm', 'x@hotmail.co', 'x@yahoo.om', 'x@yaoo.com', 'x@icloud.con', 'x@outlook.comm',
        'x@protonmail.co', 'x@ymial.com', 'x@hotmail.co.ukk', 'x@yahoo.co.uk.', 'x@GMAIL.COM', 'a.b+c@gmial.com',
        'x@live.co', 'x@me.co', 'x@aol.con', 'x@mail.co', 'x@gmx.co', 'x@emirates.net', 'x@eim.ae',
    ]);

    $adminJs = $admin."\nprocess.stdout.write(JSON.stringify(".json_encode($probes).'.map(function (e) { return kxeSuggest(e); })));';
    $adminRun = new Process(['node', '-e', $adminJs]);
    $adminRun->run();
    if ($adminRun->getExitCode() === 127) {
        test()->markTestSkipped('node is not installed here');
    }
    expect($adminRun->isSuccessful())->toBeTrue($adminRun->getErrorOutput());
    $fromAdmin = json_decode($adminRun->getOutput(), true);

    $moduleUrl = 'file://'.resource_path('js/kbb/email-suggest.js');
    $fromModule = json_decode(ehNode(
        'import { kxeSuggest } from '.json_encode($moduleUrl).';'
        .'process.stdout.write(JSON.stringify('.json_encode($probes).'.map((e) => kxeSuggest(e))));'
    ), true);

    expect($fromModule)->toBe($fromAdmin);

    $byTyped = array_combine($probes, $fromModule);
    foreach ($cases as $typed => $want) {
        expect($byTyped[$typed])->toBe($want, 'typed: '.var_export($typed, true));
    }

    // And the one the owner named, and the two he must never see a hint for.
    expect($byTyped['jovana8b@yahoo.co'])->toBe('jovana8b@yahoo.com')
        ->and($byTyped['a@yahoo.co.uk'])->toBeNull()
        ->and($byTyped['a@company.co'])->toBeNull();

    // email-fix.js uses that module, not a function of its own.
    $fix = (string) file_get_contents(resource_path('js/kbb/email-fix.js'));
    expect($fix)->toContain("import { kxeSuggest } from './email-suggest.js';")
        ->and(substr_count($fix, 'function kxeSuggest'))->toBe(0);
});

it('only hints: it writes the field on a tap alone, fires the field\'s own events, and decides on blur', function () {
    // The owner: "during write no any disturbance ... once user left the
    // field, the system will check". On the shop: a hint flickering under every
    // keystroke, or an address silently rewritten.
    // MUTATION: listen on 'input' for the suggestion -> red; assign
    // field.value outside the click handler -> red.
    $fix = (string) file_get_contents(resource_path('js/kbb/email-fix.js'));

    expect($fix)->toContain("field.addEventListener('input', restore);")
        ->and($fix)->toContain("field.addEventListener('blur', () => {")
        ->and(substr_count($fix, 'field.value = '))->toBe(1)
        ->and($fix)->toMatch("/button\\.addEventListener\\('click', \\(\\) => \\{\\s*field\\.value = fixed;\\s*field\\.dispatchEvent\\(new Event\\('input', \\{ bubbles: true \\}\\)\\);\\s*field\\.dispatchEvent\\(new Event\\('change', \\{ bubbles: true \\}\\)\\);/")
        ->and($fix)->not->toContain('preventDefault')
        ->and($fix)->not->toContain('.submit(')
        ->and($fix)->not->toContain('requestSubmit');
});

/* ═════════════════════════ 4. the switch in the admin ═════════════════════ */

function ehOwner(string $role = 'owner'): void
{
    EmailsAdminRoutes::wire(app());
    test()->actingAs(AdminUser::create([
        'name' => 'EH '.$role, 'email' => 'eh-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]), 'admin');
}

it('saves the switch only through Emails → Sending & delivery, and refuses anything else', function () {
    // On the shop: an endpoint that took any key would let the console write
    // any setting; one that took any value would store "yes" and read it as on.
    // MUTATION: drop the in_array() check -> 'yes' is stored; drop the key from
    // SENDING_KEYS -> the save is a 422.
    ehOwner();

    $state = $this->getJson('/admin-api/emails/sending')->assertOk();
    expect($state->json(CheckoutEmailFix::KEY))->toBeFalse();

    $this->postJson('/admin-api/emails/sending', ['transport' => 'server', 'settings' => [CheckoutEmailFix::KEY => true]])
        ->assertOk()->assertJsonPath(CheckoutEmailFix::KEY, true);
    ehForget();
    expect(CheckoutEmailFix::enabled())->toBeTrue()
        ->and(Setting::query()->where('key', CheckoutEmailFix::KEY)->value('value'))->toBe('1');

    foreach (['yes', 'on', 2, 'true ', ['1'], null] as $bad) {
        $this->postJson('/admin-api/emails/sending', ['transport' => 'server', 'settings' => [CheckoutEmailFix::KEY => $bad]])
            ->assertStatus(422);
    }
    ehForget();
    expect(CheckoutEmailFix::enabled())->toBeTrue();

    // An unknown key, even one that looks like this one, is refused whole.
    $this->postJson('/admin-api/emails/sending', ['transport' => 'server', 'settings' => ['checkout_email_fixx' => false]])
        ->assertStatus(422);
    $this->postJson('/admin-api/emails/sending', ['transport' => 'server', 'settings' => [CheckoutEmailFix::KEY => false, 'admin_path' => 'x']])
        ->assertStatus(422);
    ehForget();
    expect(CheckoutEmailFix::enabled())->toBeTrue()
        ->and(DB::table('settings')->where('key', 'admin_path')->value('value'))->not->toBe('x');

    $this->postJson('/admin-api/emails/sending', ['transport' => 'server', 'settings' => [CheckoutEmailFix::KEY => false]])
        ->assertOk()->assertJsonPath(CheckoutEmailFix::KEY, false);
    ehForget();
    expect(CheckoutEmailFix::enabled())->toBeFalse();

    // Branding cannot write it.
    $this->postJson('/admin-api/emails/branding', ['settings' => [CheckoutEmailFix::KEY => true]])->assertStatus(422);
});

it('needs the screen\'s own capability (emails.manage, owner only)', function () {
    // MUTATION: mount the route outside auth:admin, or map it to emails.view -> red.
    foreach (['manager', 'support', 'editor'] as $role) {
        ehOwner($role);
        $this->postJson('/admin-api/emails/sending', ['transport' => 'server', 'settings' => [CheckoutEmailFix::KEY => true]])
            ->assertForbidden();
    }
    ehForget();
    expect(CheckoutEmailFix::enabled())->toBeFalse();
});

it('draws the switch on Emails → Sending & delivery → At checkout, with the owner\'s line', function () {
    // MUTATION: drop the tab, or leave the box out of SENDING_KEYS in the
    // screen's script (it would never be sent) -> red.
    $screen = (string) file_get_contents(resource_path('views/admin/partials/emails-screens.blade.php'));

    expect($screen)->toContain("['checkout', 'At checkout', ")
        ->and($screen)->toContain('Checkout: suggest a fix for a mistyped email')
        ->and($screen)->toContain('After a shopper leaves the email field at checkout, a short line offers a fix for a clear typo (e.g. yahoo.co → yahoo.com). Nothing shows while typing; it never changes the address on its own.')
        ->and($screen)->toContain("'mail_gmail_password', 'checkout_email_fix'];")
        ->and($screen)->toContain("out[k] = el.type === 'checkbox' ? el.checked : el.value;")
        ->and($screen)->toContain("(d.checkout_email_fix ? ' checked' : '')");
});

it('leaves the admin customer dialog\'s hint always on: the switch is the checkout\'s only', function () {
    // The brief: "the admin hint stays always on". MUTATION: gate the dialog on
    // checkout_email_fix -> red.
    $dialog = (string) file_get_contents(resource_path('views/admin/partials/customer-contact-edit.blade.php'));
    expect($dialog)->not->toContain('checkout_email_fix')->and($dialog)->not->toContain('CheckoutEmailFix');
});

/* ═══════════════════════ 5. the words, and their migration ════════════════ */

it('adds one interface string with its Arabic draft', function () {
    // MUTATION: rename the key in one place only -> red.
    expect(InterfaceStrings::english('store.checkout.email_fix'))->toBe('Did you mean :email?')
        ->and(ArabicInterfaceDrafts::all()['store.checkout.email_fix'] ?? null)->toBe('هل تقصدين :email؟')
        ->and(__('store.checkout.email_fix'))->toBe('Did you mean :email?');
});

it('ships its Arabic draft in a migration that names no class of the shop', function () {
    // The updater runs migrations while the previous release's classes may
    // still be in opcache; a migration that read InterfaceStrings rolled back
    // 2.60.512 on live. MUTATION: `use App\Services\Translation\...` or any
    // App\ name in the file -> red.
    $file = database_path('migrations/'.EH_MIGRATION);
    $src = (string) file_get_contents($file);

    foreach (token_get_all($src) as $token) {
        if (! is_array($token)) {
            continue;
        }
        [$id, $text] = $token;
        if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            $name = ltrim($text, '\\');
            expect($name)->not->toStartWith('App\\', 'names '.$text);
            if (str_contains($name, '\\')) {   // \Throwable and friends are PHP's own
                expect($name)->toStartWith('Illuminate\\', 'names '.$text);
            }
        }
        if ($id === T_STRING) {
            expect($text)->not->toBeIn(['InterfaceStrings', 'ArabicInterfaceDrafts', 'TranslationStore', 'Translation', 'CheckoutEmailFix']);
        }
    }

    // Its literals are the shipped words, so the two cannot drift apart.
    expect($src)->toContain("'store.checkout.email_fix' => ['Did you mean :email?', 'هل تقصدين :email؟'],");
});

it('inserts the draft only where missing, and never touches a row the owner already has', function () {
    // MUTATION: drop the $existing check -> the owner's approved wording is
    // overwritten by a draft, which /ar would then stop showing.
    $migration = require database_path('migrations/'.EH_MIGRATION);
    $rows = fn () => DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
        ->where('field', 'store.checkout.email_fix');

    $rows()->delete();
    $migration->up();
    expect($rows()->count())->toBe(1)
        ->and($rows()->value('status'))->toBe('draft')
        ->and($rows()->value('value'))->toBe('هل تقصدين :email؟')
        ->and($rows()->value('source_hash'))->toBe(sha1('Did you mean :email?'));

    $rows()->update(['value' => 'هل تقصد :email؟', 'status' => 'published']);
    $migration->up();
    expect($rows()->count())->toBe(1)
        ->and($rows()->value('value'))->toBe('هل تقصد :email؟')
        ->and($rows()->value('status'))->toBe('published');

    // down() removes only a machine draft it could have written.
    $migration->down();
    expect($rows()->count())->toBe(1);
});
