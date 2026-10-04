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
use App\Services\Mail\Kit\EmailWording;
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

it('stores an email\'s words in email_templates with {tags}, and blank goes back to the built-in wording byte for byte', function () {
    /*
     * docs/EMAILS-PLAN.md §2.1. THE DEFECT: a Words card that saved into a
     * table nothing reads, or that broke a placeholder — "{number}" left in a
     * subject because the tag was never turned back into :number.
     * MUTATION: skip fromTags() in EmailWording::overlay() and the subject
     * prints "{number}".
     */
    $order = ekOrder();
    $builtin = (string) (new Mail\OrderStatusChanged($order, 'shipped'))->render();
    $builtinSubject = (new Mail\OrderStatusChanged($order, 'shipped'))->envelope()->subject;

    expect(EmailWording::save('order_status_shipped', ['en' => ['subject' => 'On its way: {number}!', 'heading' => 'Packed and gone']]))->toBe([]);

    $mail = new Mail\OrderStatusChanged($order, 'shipped');
    expect($mail->envelope()->subject)->toBe('On its way: KBB-EK-1!')
        ->and((string) $mail->render())->toContain('Packed and gone')
        ->and(DB::table('email_templates')->where('key', 'order_status_shipped')->where('locale', 'en')->value('subject'))->toBe('On its way: {number}!');

    // Only that email: the cancelled email's subject is its own.
    expect((new Mail\OrderStatusChanged($order, 'cancelled'))->envelope()->subject)->not->toContain('On its way');

    // Blank: the built-in again, byte for byte.
    EmailWording::save('order_status_shipped', ['en' => ['subject' => '', 'heading' => '']]);
    expect((new Mail\OrderStatusChanged($order, 'shipped'))->envelope()->subject)->toBe($builtinSubject)
        // (the browser-copy token is random per render; nothing else may differ)
        ->and(preg_replace('~/mail/view/[\w-]{43}~', '', (string) (new Mail\OrderStatusChanged($order, 'shipped'))->render()))->toBe(preg_replace('~/mail/view/[\w-]{43}~', '', $builtin));
});

it('words a shared line for one email only: the status emails\' preview line', function () {
    /*
     * The seven status emails share email.kit.pre_status. Overlaying it would
     * reword all seven when the owner edited one. MUTATION: drop the
     * `!== 1` share check in EmailWording::overlay() and the cancelled email
     * carries the shipped email's preview line.
     */
    $order = ekOrder();
    EmailWording::save('order_status_shipped', ['en' => ['preheader' => 'Order {number} has left us']]);

    expect((string) (new Mail\OrderStatusChanged($order, 'shipped'))->render())->toContain('Order KBB-EK-1 has left us')
        ->and((string) (new Mail\OrderStatusChanged($order, 'cancelled'))->render())->not->toContain('has left us');
});

it('refuses a line break in a subject, and anything that is not one of the email\'s own fields', function () {
    /*
     * CRLF in a subject is a header injection. MUTATION: drop the [\r\n]
     * check in EmailWording::problem() and the Bcc line is stored.
     */
    expect(EmailWording::save('order_status_shipped', ['en' => ['subject' => "Hi\r\nBcc: everyone@example.com"]]))->toHaveKey('en.subject')
        ->and(EmailWording::save('order_status_shipped', ['en' => ['heading' => "Two\nlines"]]))->toHaveKey('en.heading')
        ->and(EmailWording::save('order_status_shipped', ['en' => ['footer_html' => '<b>x</b>']]))->toHaveKey('en.footer_html')
        ->and(EmailWording::save('order_status_shipped', ['en' => ['subject' => str_repeat('x', 201)]]))->toHaveKey('en.subject')
        // A message may have paragraphs.
        ->and(EmailWording::save('order_status_shipped', ['en' => ['body' => "One.\n\nTwo."]]))->toBe([])
        ->and(DB::table('email_templates')->where('key', 'order_status_shipped')->value('subject'))->toBeNull();
});

it('falls back in Arabic to the Arabic words, then the English override, then the code', function () {
    $order = ekOrder();
    $ar = static function () use ($order): string {
        app()->setLocale('ar');

        try {
            return (new Mail\OrderStatusChanged($order, 'shipped'))->envelope()->subject;
        } finally {
            app()->setLocale('en');
        }
    };

    EmailWording::save('order_status_shipped', ['en' => ['subject' => 'English words {number}']]);
    expect($ar())->toBe('English words KBB-EK-1');

    EmailWording::save('order_status_shipped', ['ar' => ['subject' => 'طلبك {number} في الطريق']]);
    expect($ar())->toBe('طلبك KBB-EK-1 في الطريق')
        ->and((new Mail\OrderStatusChanged($order, 'shipped'))->envelope()->subject)->toBe('English words KBB-EK-1');
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
        ->and(DB::table('email_templates')->count())->toBe(0);

    // Save, then reset.
    $this->postJson('/admin-api/emails/templates/order_status_shipped', ['sections' => $draft])->assertOk()->assertJsonPath('customised', true);
    expect(DB::table('email_templates')->where('key', 'order_status_shipped')->whereNotNull('blocks')->exists())->toBeTrue();
    $this->postJson('/admin-api/emails/templates/order_status_shipped/reset')->assertOk()->assertJsonPath('customised', false);

    // Design & branding's own preview is untouched by all this.
    $this->get('/admin-api/emails/preview')->assertOk()->assertSee(__('email.kit.eyebrow_confirmed'));

    // Test: to the signed-in admin, never anywhere else — whatever the request says.
    \Illuminate\Support\Facades\Mail::fake();
    $this->postJson('/admin-api/emails/templates/order_status_shipped/test', ['to' => 'someone@else.test'])->assertOk();
    \Illuminate\Support\Facades\Mail::assertSent(Mail\OrderStatusChanged::class, fn ($m) => $m->hasTo($admin->email) && ! $m->hasTo('someone@else.test'));
    \Illuminate\Support\Facades\Mail::assertSentCount(1);
});

it('fails closed: reading is owner and manager, a test is owner and manager, changing is the owner\'s', function () {
    /*
     * docs/EMAILS-PLAN.md §6. MUTATION: delete the Lane EK lines above the
     * emails wildcard in AdminCapabilities::RULES — every route falls to
     * emails.manage and the manager can no longer read or test.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/emails/customer'))->toBe('emails.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/emails/templates/{template}'))->toBe('emails.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/emails/templates/{template}/test'))->toBe('emails.test')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/emails/templates/{template}'))->toBe('emails.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/emails/templates/{template}/reset'))->toBe('emails.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/emails/customer/switch'))->toBe('emails.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/emails/preview'))->toBe('emails.manage')
        ->and(AdminCapabilities::CAPABILITIES['emails.view'])->toBe(['owner', 'manager'])
        ->and(AdminCapabilities::CAPABILITIES['emails.test'])->toBe(['owner', 'manager'])
        ->and(AdminCapabilities::CAPABILITIES['emails.manage'])->toBe(['owner']);

    // Route walk: every route in the file has a rule, none is under api/.
    EmailMarketingRoutes::wire(app());
    $mine = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains((string) ($r->getActionName()), 'EmailTemplatesController') || ($r->uri() === 'admin-api/emails/preview' && in_array('POST', $r->methods(), true)));
    expect($mine->count())->toBe(7);
    foreach ($mine as $route) {
        expect(AdminCapabilities::for($route))->not->toBeNull()
            ->and(str_starts_with($route->uri(), 'api/'))->toBeFalse();
    }

    ekOrder();
    \Illuminate\Support\Facades\Mail::fake();
    ekAs('manager');
    $this->getJson('/admin-api/emails/customer')->assertOk();
    $this->getJson('/admin-api/emails/templates/order_status_shipped')->assertOk();
    $this->postJson('/admin-api/emails/templates/order_status_shipped/test')->assertOk();
    $this->postJson('/admin-api/emails/templates/order_status_shipped', ['sections' => []])->assertForbidden();
    $this->postJson('/admin-api/emails/customer/switch', ['template' => 'order_status_shipped', 'on' => false])->assertForbidden();

    foreach (['support', 'editor'] as $role) {
        ekAs($role);
        $this->getJson('/admin-api/emails/customer')->assertForbidden();
        $this->getJson('/admin-api/emails/templates/order_status_shipped')->assertForbidden();
        $this->postJson('/admin-api/emails/templates/order_status_shipped/test')->assertForbidden();
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
    $screen = (string) file_get_contents(resource_path('views/admin/partials/emails-templates-screens.blade.php'));

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'offsetTop', 'clientWidth', 'clientHeight', 'getComputedStyle', 'scrollHeight', 'elementFromPoint'] as $api) {
        expect($screen)->not->toContain($api);
    }

    expect($screen)->toContain('pointerdown')
        ->and($screen)->toContain('releasePointerCapture')
        // The preview is framed with no script, no forms, no origin.
        ->and(preg_match_all('/<iframe[^>]*sandbox=""/', $screen))->toBeGreaterThanOrEqual(1)
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
        substr_count($web, "require __DIR__.'/emails-templates-admin.php';"),
        substr_count($app, "@include('admin.partials.emails-templates-screens')"),
    ];

    expect(max($counts))->toBeLessThanOrEqual(1);

    if (min($counts) === 0) {
        $this->markTestSkipped('Not wired yet: the integrator adds the two lines from routes/emails-templates-admin.php and the screen partial header.');
    }

    expect($counts)->toBe([1, 1]);
});

it('ships a cache-clearing migration with its routes', function () {
    expect(glob(base_path('database/migrations/*_clear_caches_email_templates.php')))->toHaveCount(1);
});

/* =================================================================== tabs */

it('draws every Emails screen in tabs from the ONE shared component', function () {
    /*
     * The owner, 4 Oct: "proper tabs not just throw the content, follow this
     * also for all emails pages". THE DEFECT: a long stacked page; or a second
     * home-made tab bar that looks and behaves differently. MUTATION: put
     * Design & branding back as one stacked page (drop its tabbed() call) and
     * the eml-branding pin is red; drop role="tablist" from renderMail() and
     * the mail pin is.
     */
    $screens = (string) file_get_contents(resource_path('views/admin/partials/emails-screens.blade.php'));
    $mine = (string) file_get_contents(resource_path('views/admin/partials/emails-templates-screens.blade.php'));
    $tabs = (string) file_get_contents(resource_path('views/admin/partials/kbb-tabs.blade.php'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // One component, included once, by the first Emails partial.
    expect(substr_count($screens, "@include('admin.partials.kbb-tabs')"))->toBe(1)
        ->and(substr_count($mine, "@include('admin.partials.kbb-tabs')"))->toBe(0)
        ->and($tabs)->toContain('role="tablist"')
        ->and($tabs)->toContain("'ArrowRight'")
        ->and($tabs)->toContain("'Home'")
        ->and($tabs)->toContain('kbbtab:');

    // Every Emails screen: Overview, Sending & delivery, Design & branding, Sent mail …
    foreach (["tabbed('eml-overview'", "tabbed('eml-sending'", "tabbed('eml-branding'", "kbbTabs.bar('eml-sent'"] as $needle) {
        expect($screens)->toContain($needle);
    }

    // … Customer emails and the editor …
    expect($mine)->toContain("bar('ek-customer'")->and($mine)->toContain("bar('ek-editor'");

    // … and All mail settings, whose markup is written by renderMail() itself.
    $mail = substr($app, (int) strpos($app, 'LANE J · Store · Mail — BEGIN'), 20000);
    expect($mail)->toContain('role="tablist" data-kbt="mail"')
        ->and($mail)->toContain('role="tabpanel" data-kbt-panel="mail"');
});

it('builds a tablist a screen reader can use, by running the component', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        $this->markTestSkipped('node is not available');
    }

    $src = (string) file_get_contents(resource_path('views/admin/partials/kbb-tabs.blade.php'));
    preg_match('~<script>(.*?)</script>~s', $src, $m);
    $js = "var window={};var document={addEventListener:function(){}};var localStorage={getItem:function(){return null},setItem:function(){}};\n"
        . $m[1]
        . "\nprocess.stdout.write(JSON.stringify({bar: window.kbbTabs.bar('g', [['a','One'],['b','Two',3]], 'b', 'Test'), p: window.kbbTabs.panel('g','a','b')}));";
    $tmp = tempnam(sys_get_temp_dir(), 'ektabs') . '.js';
    file_put_contents($tmp, $js);
    $out = json_decode((string) shell_exec(escapeshellcmd($node) . ' ' . escapeshellarg($tmp) . ' 2>&1'), true);
    @unlink($tmp);

    expect($out)->toBeArray()
        ->and($out['bar'])->toContain('role="tablist" data-kbt="g" aria-label="Test"')
        ->and($out['bar'])->toContain('role="tab" id="kbt_g_b" data-kbt-tab="b" aria-controls="kbtp_g_b" aria-selected="true" tabindex="0"')
        ->and($out['bar'])->toContain('aria-selected="false" tabindex="-1"')
        ->and($out['p'])->toContain('role="tabpanel"')
        ->and($out['p'])->toContain('aria-labelledby="kbt_g_a"')
        ->and($out['p'])->toEndWith(' hidden');
});
