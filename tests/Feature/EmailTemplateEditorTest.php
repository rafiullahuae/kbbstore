<?php

declare(strict_types=1);

/**
 * Lane EK — Emails → Customer emails (e3) and the template editor (e4).
 *
 * The owner, 3 October 2026: "give facility in edit any template, to
 * re-position any section by drag n drop", on the approved mocks
 * docs/rj-email-previews/admin/e3-customer-emails and e4-template-editor.
 *
 * Every case says what the defect looked like in an inbox or on the screen, and
 * carries a MUTATION note: the one-line revert that turns it red.
 */

use App\Mail;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\Translation;
use App\Services\Mail\CustomerEmails;
use App\Services\Mail\Kit\KitBlocks;
use App\Services\Mail\Kit\KitSamples;
use App\Services\Mail\Kit\KitSections;
use App\Services\Mail\Kit\KitWords;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmailMarketingRoutes;

beforeEach(function () {
    KitSections::forget();
});

function ekOrder(): Order
{
    $settings = app(SettingsService::class);
    $settings->set('store_name', 'K Beauty Bliss');
    $settings->set('brand_whatsapp', '+971 58 505 2611');
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();

    $address = ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => 'Marina Heights', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'];
    $order = Order::create([
        'order_number' => 'KBB-EK-1', 'email' => 'aisha@example.com', 'status' => 'processing', 'currency' => 'AED',
        'billing_address' => $address, 'shipping_address' => $address,
        'subtotal' => 17800, 'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0, 'tax_total' => 0, 'total' => 17800,
        'shipping_method' => 'Free UAE delivery', 'payment_method' => 'tabby', 'payment_method_title' => 'Tabby', 'paid_at' => now(),
    ]);
    $brand = Brand::firstOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
    $p = Product::create(['name' => 'Heartleaf Toner', 'slug' => 'ek-toner', 'brand_id' => $brand->id, 'status' => 'publish', 'is_visible' => 1,
        'price' => 8900, 'stock_status' => 'instock', 'type' => 'simple', 'routine_role' => 'tone']);
    $order->items()->create(['product_id' => $p->id, 'name' => 'Heartleaf Toner', 'brand' => 'Anua', 'quantity' => 2, 'unit_price' => 8900, 'subtotal' => 17800, 'total' => 17800]);
    // A second step of a routine, so the Delivered email has its "How to use them together".
    $c = Product::create(['name' => 'Gel Cleanser', 'slug' => 'ek-cleanser', 'brand_id' => $brand->id, 'status' => 'publish', 'is_visible' => 1,
        'price' => 6250, 'stock_status' => 'instock', 'type' => 'simple', 'routine_role' => 'cleanse']);
    $order->items()->create(['product_id' => $c->id, 'name' => 'Gel Cleanser', 'brand' => 'Anua', 'quantity' => 1, 'unit_price' => 6250, 'subtotal' => 6250, 'total' => 6250]);

    return $order->fresh('items');
}

function ekAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'EK ' . $role, 'email' => 'ek-' . $role . '-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function ekAs(string $role = 'owner'): AdminUser
{
    EmailMarketingRoutes::wire(app());
    $admin = ekAdmin($role);
    test()->actingAs($admin, 'admin');

    return $admin;
}

/** The kit rows of an email, in order, by the padding that names each block (MailKitParityTest's measure). */
function ekBlocks(string $html): array
{
    preg_match_all('/<td class="px"[^>]*style="padding:([^;"]*)/', $html, $m);

    return $m[1];
}

/* ================================================================ the order */

it('draws an email in the order the owner dragged its sections into', function () {
    /*
     * THE DEFECT: an editor that stores an order the kit never reads — the
     * owner drags "Help box" to the top, presses Save, and the shipped email
     * is unchanged. MUTATION: in KitSections::arrange() return
     * self::join($glue, …source order…) unconditionally and this is red.
     */
    $order = ekOrder();
    $before = (string) (new Mail\OrderStatusChanged($order, 'shipped'))->render();

    $default = array_column(KitSections::forEditor('order_status_shipped'), 'key');
    $moved = array_values(array_diff($default, ['help']));
    array_splice($moved, 1, 0, ['help']);   // the help box straight after the headline
    KitSections::save('order_status_shipped', ['sections' => array_map(static fn ($k) => ['key' => $k], $moved)]);

    $after = (string) (new Mail\OrderStatusChanged($order->fresh('items'), 'shipped'))->render();

    $help = strpos($after, __('email.kit.help_heading'));
    $tracker = strpos($after, __('email.kit.step_placed'));
    $items = strpos($after, __('email.kit.your_items'));

    expect($help)->toBeLessThan($tracker)
        ->and($tracker)->toBeLessThan($items)
        // Same blocks, different order: nothing added, nothing lost.
        ->and(count(ekBlocks($after)))->toBe(count(ekBlocks($before)))
        ->and(ekBlocks($after))->not->toBe(ekBlocks($before))
        // And the header still opens the card and the footer still closes it.
        ->and(strpos($after, __('email.kit.nav_shop')))->toBeLessThan($help)
        ->and(strrpos($after, __('email.kit.footer_tagline')))->toBeGreaterThan($items)
        // No marker survives into an inbox.
        ->and($after)->not->toContain("\x00");

    // Only that email moved.
    $confirmation = (string) (new Mail\OrderConfirmation($order->fresh('items')))->render();
    expect(strpos($confirmation, __('email.kit.help_heading')))->toBeGreaterThan(strpos($confirmation, __('email.kit.your_items')));
});

it('hides a section switched off and shows one the email leaves out by default', function () {
    /*
     * THE DEFECT: a switch that only ever hides — Totals is off by default in
     * "Order shipped" (the approved table) and switching it ON did nothing.
     * MUTATION: put `$kitShowTotals ?? true` back as the bare condition in
     * emails/kit/order.blade.php and the shipped half is red; drop the
     * `on === false` branch in arrange() and the confirmation half is.
     */
    $order = ekOrder();
    $total = __('email.totals.subtotal');

    expect((string) (new Mail\OrderStatusChanged($order, 'shipped'))->render())->not->toContain($total);

    KitSections::save('order_status_shipped', ['sections' => [['key' => 'totals', 'on' => true]]]);
    expect((string) (new Mail\OrderStatusChanged($order, 'shipped'))->render())->toContain($total);

    $help = __('email.kit.help_heading');
    expect((string) (new Mail\OrderConfirmation($order))->render())->toContain($help);
    KitSections::save('order_confirmation', ['sections' => [['key' => 'help', 'on' => false]]]);
    expect((string) (new Mail\OrderConfirmation($order))->render())->not->toContain($help);
});

it('keeps a stored list honest: only the email\'s own sections, once each, the rest in their default places', function () {
    /*
     * THE DEFECT: a request that names a section twice prints it twice; one
     * that names another email's section, or leaves one out, loses a block
     * from every customer's email. MUTATION: drop the `isset($seen[$key])`
     * check in KitSections::clean() and the duplicate survives.
     */
    $clean = KitSections::clean('order_status_shipped', ['sections' => [
        ['key' => 'help'], ['key' => 'help'], ['key' => 'rates'], ['key' => '<script>'], ['key' => 'hero', 'on' => true],
        ['key' => 'b1', 'block' => ['type' => 'text', 'text' => 'Hi']], ['key' => 'bad key', 'block' => ['type' => 'text', 'text' => 'x']],
    ]]);

    $keys = array_column($clean['sections'], 'key');

    expect(array_count_values($keys)['help'])->toBe(1)
        ->and($keys)->not->toContain('rates')
        ->and($keys)->not->toContain('<script>')
        ->and($keys)->not->toContain('bad key')
        ->and($keys)->toContain('b1')
        // Every catalogue section is in the list.
        ->and(array_values(array_intersect(array_keys(KitSections::TEMPLATES['order_status_shipped']['sections']), $keys)))
        ->toBe(array_keys(KitSections::TEMPLATES['order_status_shipped']['sections']))
        // An `on` equal to the default is not stored: the code keeps deciding.
        ->and($clean['sections'][array_search('hero', $keys, true)])->toBe(['key' => 'hero']);
});

it('lists, for every email, exactly the sections that email draws', function () {
    /*
     * THE DEFECT the catalogue could have: a switch shown "on" for a section
     * the email does not draw (or a drawn section with no row to move). Each
     * email is rendered with this shop's data and the sections that came out
     * are compared with the catalogue's defaults. MUTATION: mark Totals as on
     * by default for order_status_shipped in KitSections::TEMPLATES and red.
     */
    ekOrder();
    \App\Models\Customer::create(['email' => 'aisha@example.com', 'name' => 'Aisha Khan']);
    app(SettingsService::class)->set('social_instagram', 'kbeauty.bliss');
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();

    foreach (KitSections::TEMPLATES as $template => $def) {
        KitSamples::html($template);
        $defaults = array_keys(array_filter($def['sections'], static fn (array $s) => $s[1]));

        expect(KitSections::seen($template))->toBe($defaults, "{$template}: the catalogue's default sections are not what the email draws");
    }
});

/* ============================================================ added blocks */

it('draws an added section from the kit\'s own blocks: text escaped, a hostile link refused', function () {
    /*
     * THE DEFECT this rules out: "+ Add a section" as a door for HTML. A text
     * block is printed through the kit's escape; a button whose link is
     * javascript: is not drawn at all. MUTATION: print the text with {!! !!}
     * in emails/kit/para.blade.php, or let MailKit::url() pass any scheme.
     */
    $order = ekOrder();
    KitSections::save('order_confirmation', ['sections' => [
        ['key' => 'hero'],
        ['key' => 'b1', 'block' => ['type' => 'text', 'text' => "Hello <b>friend</b>\nSecond line"]],
        ['key' => 'b2', 'block' => ['type' => 'button', 'label' => 'Bad', 'url' => 'javascript:alert(1)']],
        ['key' => 'b3', 'block' => ['type' => 'button', 'label' => 'Good', 'url' => '/shop/']],
    ]]);

    $html = (string) (new Mail\OrderConfirmation($order))->render();

    expect($html)->toContain('Hello &lt;b&gt;friend&lt;/b&gt;<br>')
        ->and($html)->not->toContain('<b>friend</b>')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('>Bad<')
        ->and($html)->toContain('/shop/"')
        // Straight after the headline, as stored.
        ->and(strpos($html, 'Hello &lt;b&gt;'))->toBeLessThan(strpos($html, __('email.kit.step_placed')));

    expect(KitBlocks::clean([['type' => 'html', 'html' => '<script>']]))->toBe([]);
});

/* ================================================================== words */

it('edits an email\'s words in the translations table, with {tags}, and blank goes back to the built-in wording', function () {
    /*
     * NO SECOND COPY: the words are the interface strings Translation →
     * Strings edits. THE DEFECT: a Words card that saved into a table nothing
     * reads, or that broke a placeholder — "{order_number}" left in a subject
     * because the tag was never turned back into :number. MUTATION: skip
     * fromTags() in KitWords::save() and the subject prints "{number}".
     */
    $order = ekOrder();

    expect(KitWords::save('order_status_shipped', ['en' => ['email.order_status.shipped_subject' => 'On its way: {number}!']]))->toBe([]);

    $subject = (new Mail\OrderStatusChanged($order, 'shipped'))->envelope()->subject;
    expect($subject)->toContain('On its way: KBB-EK-1!');

    // The row is the ordinary published UI translation, readable by Translation → Strings.
    expect(Translation::query()->where('locale', 'en')->where('field', 'email.order_status.shipped_subject')->value('status'))->toBe(Translation::STATUS_PUBLISHED);

    // Blank: the row goes, and the built-in English is back.
    KitWords::save('order_status_shipped', ['en' => ['email.order_status.shipped_subject' => '']]);
    expect((new Mail\OrderStatusChanged($order, 'shipped'))->envelope()->subject)->not->toContain('On its way: KBB');

    // Another email's key is refused, not written.
    expect(KitWords::save('order_status_shipped', ['en' => ['email.confirmation.subject' => 'x']]))->toHaveKey('email.confirmation.subject');
});

/* ============================================================== endpoints */

it('saves, previews unsaved changes without storing them, resets, and sends a test only to the admin', function () {
    $order = ekOrder();
    $admin = ekAs('owner');

    $this->getJson('/admin-api/emails/templates/order_status_shipped')->assertOk()
        ->assertJsonPath('name', 'Order shipped')
        ->assertJsonPath('sections.0.key', 'hero');

    // The live preview: the draft order is drawn, nothing is stored.
    $draft = array_map(static fn ($k) => ['key' => $k], ['help', 'hero', 'tracker', 'chip', 'before', 'items', 'totals', 'promises', 'info', 'button', 'signoff']);
    $html = $this->post('/admin-api/emails/preview?template=order_status_shipped', ['sections' => $draft])->assertOk()->getContent();
    expect(strpos($html, __('email.kit.help_heading')))->toBeLessThan(strpos($html, __('email.kit.step_placed')))
        ->and(DB::table('email_template_layouts')->count())->toBe(0);

    // Save, then reset.
    $this->postJson('/admin-api/emails/templates/order_status_shipped', ['sections' => $draft])->assertOk()->assertJsonPath('customised', true);
    expect(DB::table('email_template_layouts')->where('template', 'order_status_shipped')->exists())->toBeTrue();
    $this->postJson('/admin-api/emails/templates/order_status_shipped/reset')->assertOk()->assertJsonPath('customised', false);

    // Design & branding's own preview is untouched by all this.
    $this->get('/admin-api/emails/preview')->assertOk()->assertSee(__('email.kit.eyebrow_confirmed'));

    // Test: to the signed-in admin, never anywhere else — whatever the request says.
    \Illuminate\Support\Facades\Mail::fake();
    $this->postJson('/admin-api/emails/templates/order_status_shipped/test', ['to' => 'someone@else.test'])->assertOk();
    \Illuminate\Support\Facades\Mail::assertSent(Mail\OrderStatusChanged::class, fn ($m) => $m->hasTo($admin->email) && ! $m->hasTo('someone@else.test'));
    \Illuminate\Support\Facades\Mail::assertSentCount(1);
});

it('fails closed: Customer emails and the editor are the owner\'s, and an unknown email is a 404', function () {
    /*
     * MUTATION: delete the three emails.templates lines from
     * AdminCapabilities::RULES — the routes fall to the emails.manage
     * wildcard and the capability assertions are red.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/emails/customer'))->toBe('emails.templates')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/emails/customer/switch'))->toBe('emails.templates')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/emails/templates/{template}/test'))->toBe('emails.templates')
        ->and(AdminCapabilities::CAPABILITIES['emails.templates'])->toBe(['owner']);

    foreach (['manager', 'support', 'editor'] as $role) {
        ekAs($role);
        $this->getJson('/admin-api/emails/customer')->assertForbidden();
        $this->postJson('/admin-api/emails/templates/order_status_shipped', ['sections' => []])->assertForbidden();
        $this->post('/admin-api/emails/preview?template=order_status_shipped')->assertForbidden();
    }

    ekAs('owner');
    $this->getJson('/admin-api/emails/templates/not_an_email')->assertNotFound();
    $this->postJson('/admin-api/emails/templates/not_an_email', ['sections' => []])->assertNotFound();
});

/* ========================================================= Customer emails */

it('switches an email with its existing module toggle, and refuses a row that has none', function () {
    /*
     * NO SECOND COPY OF A SETTING: "Order shipped" off here is exactly
     * email_order_shipped off in Store → Modules, and the mailer stops.
     * MUTATION: make CustomerEmails::set() write a new setting key and the
     * mailer assertion is red.
     */
    ekAs('owner');

    $rows = collect($this->getJson('/admin-api/emails/customer')->assertOk()->json('rows'));
    expect($rows->pluck('template')->all())->toContain('order_status_shipped', 'order_confirmation', 'password_reset', 'order_feedback')
        ->and($rows->firstWhere('template', 'order_status_shipped')['on'])->toBeTrue()
        ->and($rows->firstWhere('template', 'password_reset')['state'])->toBe('always');

    $settingsBefore = DB::table('settings')->count();
    $this->postJson('/admin-api/emails/customer/switch', ['template' => 'order_status_shipped', 'on' => false])->assertOk();

    expect(app(\App\Services\Mail\OrderMailer::class)->shippedEnabled())->toBeFalse()
        ->and(DB::table('module_toggles')->where('module', 'email_order_shipped')->value('enabled'))->toBeFalsy()
        ->and(DB::table('settings')->count())->toBe($settingsBefore);

    $this->postJson('/admin-api/emails/customer/switch', ['template' => 'password_reset', 'on' => false])->assertStatus(422);
    $this->postJson('/admin-api/emails/customer/switch', ['template' => 'order_invoice', 'on' => true])->assertStatus(422);
});

/* ================================================================ screens */

it('builds the screens with no layout-measuring JavaScript and every server string escaped', function () {
    /*
     * CLAUDE.md rule 4: the drag and drop works from the LIST ORDER — pointer
     * events on the rows themselves — never from geometry. MUTATION: use
     * getBoundingClientRect() to find the drop row and red.
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/emails-marketing-screens.blade.php'));

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'offsetTop', 'clientWidth', 'clientHeight', 'getComputedStyle', 'scrollHeight', 'elementFromPoint'] as $api) {
        expect($screen)->not->toContain($api);
    }

    expect($screen)->toContain('pointerdown')
        ->and($screen)->toContain('releasePointerCapture')
        // Both previews are framed with no script, no forms, no origin.
        ->and(preg_match_all('/<iframe[^>]*sandbox=""/', $screen))->toBeGreaterThanOrEqual(2)
        ->and($screen)->not->toMatch('/<iframe(?![^>]*sandbox="")/');
});

it('is wired into the console exactly once (once the integrator adds the lines)', function () {
    /*
     * THE FINISHED STATE (CLAUDE.md): one require for each route file, one
     * include of the screen. Never more than one — two would register the
     * sidebar rows twice and wrap window.go around its own wrapper.
     * Skipped, not asserted absent, until the integrator adds the lines.
     */
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $counts = [
        substr_count($web, "require __DIR__.'/emails-marketing-admin.php';"),
        substr_count($web, "require __DIR__.'/emails-marketing-public.php';"),
        substr_count($app, "@include('admin.partials.emails-marketing-screens')"),
    ];

    expect(max($counts))->toBeLessThanOrEqual(1);

    if (min($counts) === 0) {
        $this->markTestSkipped('Not wired yet: the integrator adds the three lines from routes/emails-marketing-admin.php, routes/emails-marketing-public.php and the screen partial header.');
    }

    expect($counts)->toBe([1, 1, 1]);
});

it('ships a cache-clearing migration with its routes', function () {
    expect(glob(base_path('database/migrations/*_clear_caches_email_marketing.php')))->toHaveCount(1);
});
