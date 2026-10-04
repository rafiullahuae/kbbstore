<?php

declare(strict_types=1);

/**
 * Lane EK — Growth & Marketing → Email Marketing: groups (m3), the builder
 * (m2), Review & send (m4), the report (m5), and sending at volume with no
 * queue worker and no cron.
 *
 * The owner's decisions held here (master plan rows 53 and 54): customers and
 * subscribers are SEPARATE lists; groups by total spent, number of orders,
 * emirate, month / year / custom date range and brands bought; Send for Owner
 * and Administrator only (`campaign.send` = owner + manager).
 *
 * Every case says what the defect would have looked like and carries a
 * MUTATION note.
 */

use App\Mail\CampaignMail;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Marketing\CampaignAudience;
use App\Services\Marketing\CampaignReport;
use App\Services\Marketing\CampaignSender;
use App\Services\Marketing\CampaignTick;
use App\Services\Marketing\CampaignTracking;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\EmailMarketingRoutes;

beforeEach(function () {
    EmailMarketingRoutes::wire(app());
    Cache::forget(CampaignSender::LOCK);
});

function emAdmin(string $role = 'owner'): AdminUser
{
    $admin = AdminUser::create(['name' => 'Rafi ' . $role, 'email' => 'em-' . $role . '-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'role' => $role]);
    test()->actingAs($admin, 'admin');

    return $admin;
}

/** A customer with paid orders: [brand => fils] per order, delivered to $state. */
function emCustomer(string $email, array $orders = [], string $state = 'Dubai', bool $consent = false, ?string $at = null): Customer
{
    $c = Customer::create(['email' => $email, 'name' => ucfirst(strtok($email, '@')) . ' Test', 'first_name' => ucfirst(strtok($email, '@'))]);

    if ($consent) {
        DB::table('customers')->where('id', $c->id)->update(['marketing_consent_at' => now(), 'marketing_consent_source' => 'test']);
    }

    foreach ($orders as $i => $lines) {
        $total = array_sum($lines);
        $address = ['first_name' => 'X', 'state' => $state, 'country' => $state === 'Riyadh' ? 'SA' : 'AE'];
        $o = Order::create([
            'order_number' => 'EM-' . $c->id . '-' . $i . '-' . uniqid(), 'customer_id' => $c->id, 'email' => $email, 'status' => 'completed',
            'currency' => 'AED', 'billing_address' => $address, 'shipping_address' => $address,
            'subtotal' => $total, 'total' => $total, 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
        ]);

        if ($at !== null) {
            DB::table('orders')->where('id', $o->id)->update(['created_at' => $at]);
        }

        foreach ($lines as $brand => $fils) {
            $o->items()->create(['name' => $brand . ' thing', 'brand' => $brand, 'quantity' => 1, 'unit_price' => $fils, 'subtotal' => $fils, 'total' => $fils]);
        }
    }

    return $c;
}

function emSubscriber(string $email, bool $confirmed = true): int
{
    DB::table('subscribers')->insert(['email' => $email, 'source' => 'homepage', 'status' => $confirmed ? 'subscribed' : 'pending',
        'confirmed_at' => $confirmed ? now() : null, 'created_at' => now(), 'updated_at' => now()]);

    return (int) DB::table('subscribers')->where('email', $email)->value('id');
}

function emProduct(string $brand, string $name, int $price = 9900, string $slug = ''): Product
{
    $b = Brand::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($brand)], ['name' => $brand]);

    return Product::create(['name' => $name, 'slug' => $slug !== '' ? $slug : \Illuminate\Support\Str::slug($name) . '-' . uniqid(), 'brand_id' => $b->id,
        'status' => 'publish', 'is_visible' => 1, 'price' => $price, 'stock_status' => 'instock', 'type' => 'simple', 'total_sales' => 5]);
}

function emCampaign(array $attrs = []): Campaign
{
    return Campaign::create($attrs + [
        'name' => 'Autumn Glow Edit', 'subject' => 'Hi {first_name}, your glow edit', 'preheader' => '15% inside', 'audience' => 'customers',
        'blocks' => [
            ['type' => 'heading', 'icon' => 'spark', 'eyebrow' => 'Picked for you', 'title' => 'Hi {first_name}, more Medicube', 'lead' => 'You keep coming back.'],
            ['type' => 'button', 'label' => 'Shop all Medicube', 'url' => '/brand/medicube/'],
            ['type' => 'button', 'label' => 'The sale', 'url' => 'https://extrabeauty.ae/sale/'],
        ],
        'status' => 'draft',
    ]);
}

/* ================================================================== groups */

it('finds customers by total spent, number of orders, emirate, date and brand — combinable, ALL or ANY', function () {
    /*
     * THE DEFECT a group screen invites: rules that look combined and are not.
     * MUTATION: make Match ALL union instead of intersect in
     * CampaignAudience::matching() and the ALL figures are red.
     */
    $brands = ['Medicube' => Brand::create(['name' => 'Medicube', 'slug' => 'medicube']), 'COSRX' => Brand::create(['name' => 'COSRX', 'slug' => 'cosrx'])];
    emCustomer('mostly-medi@x.test', [['Medicube' => 40000, 'COSRX' => 10000], ['Medicube' => 20000]], 'Dubai', true, '2026-03-10 12:00:00');
    emCustomer('some-medi@x.test', [['Medicube' => 5000, 'COSRX' => 30000]], 'DU', true, '2026-09-05 12:00:00');      // WooCommerce's code for Dubai
    emCustomer('abu@x.test', [['COSRX' => 120000]], 'Abu Dhabi', true, '2025-12-31 12:00:00');
    emCustomer('never@x.test', [], 'Dubai', true);
    emCustomer('abroad@x.test', [['COSRX' => 1000]], 'Riyadh', true, '2024-05-01 12:00:00');

    $a = new CampaignAudience;
    $n = fn (array $rules, string $match = 'all') => (new CampaignAudience)->count('customers', $rules, $match)['matched'];

    // Spent: 700, 350, 1,200, 0 and 10 AED.
    expect($n([['field' => 'spent', 'op' => 'at_least', 'value' => 500]]))->toBe(2)
        ->and($n([['field' => 'spent', 'op' => 'at_most', 'value' => 350]]))->toBe(3)
        ->and($n([['field' => 'orders', 'op' => 'at_least', 'value' => 2]]))->toBe(1)
        ->and($n([['field' => 'never_ordered', 'op' => 'is']]))->toBe(1)
        ->and($n([['field' => 'emirate', 'op' => 'any_of', 'value' => ['dubai']]]))->toBe(2)
        ->and($n([['field' => 'emirate', 'op' => 'any_of', 'value' => ['dubai', 'abu_dhabi']]]))->toBe(3)
        ->and($n([['field' => 'emirate', 'op' => 'any_of', 'value' => ['outside']]]))->toBe(1)
        ->and($n([['field' => 'order_date', 'op' => 'in_year', 'value' => 2026]]))->toBe(2)
        ->and($n([['field' => 'order_date', 'op' => 'in_month', 'value' => '2026-09']]))->toBe(1)
        ->and($n([['field' => 'order_date', 'op' => 'between', 'value' => ['2025-12-01', '2026-03-31']]]))->toBe(2)
        ->and($n([['field' => 'brand', 'op' => 'mostly', 'value' => $brands['Medicube']->id]]))->toBe(1)
        ->and($n([['field' => 'brand', 'op' => 'ever', 'value' => $brands['Medicube']->id]]))->toBe(2)
        // Combined: ALL narrows, ANY widens.
        ->and($n([['field' => 'brand', 'op' => 'ever', 'value' => $brands['Medicube']->id], ['field' => 'order_date', 'op' => 'in_month', 'value' => '2026-09']]))->toBe(1)
        ->and($n([['field' => 'brand', 'op' => 'mostly', 'value' => $brands['Medicube']->id], ['field' => 'never_ordered', 'op' => 'is']], 'any'))->toBe(2);

    // The group's top brand fills "This group's top brand (auto)".
    expect($a->topBrand('customers', [['field' => 'brand', 'op' => 'mostly', 'value' => $brands['Medicube']->id]]))->toBe([$brands['Medicube']->id, 'Medicube']);
});

it('takes rules only from its own vocabulary: no field, operator or value outside it reaches a query', function () {
    /*
     * CLAUDE.md rule 5: "group filters are whitelisted fields and operators,
     * never raw SQL". MUTATION: let clean() keep an unknown field and the
     * dropped list is empty.
     */
    $clean = CampaignAudience::clean('customers', [
        ['field' => 'spent', 'op' => 'at_least', 'value' => '500'],
        ['field' => 'email; DROP TABLE customers', 'op' => 'is', 'value' => 1],
        ['field' => 'spent', 'op' => 'like', 'value' => '%'],
        ['field' => 'spent', 'op' => 'at_least', 'value' => "1 OR 1=1"],
        ['field' => 'order_date', 'op' => 'in_month', 'value' => "2026-13"],
        ['field' => 'emirate', 'op' => 'any_of', 'value' => ['dubai', "x') OR ('1'='1"]],
        ['field' => 'signed_up', 'op' => 'in_year', 'value' => 2026],   // a subscribers rule on the customers list
    ], $dropped);

    expect($clean)->toBe([
        ['field' => 'spent', 'op' => 'at_least', 'value' => 50000],
        ['field' => 'emirate', 'op' => 'any_of', 'value' => ['dubai']],
    ])->and($dropped)->toBe([1, 2, 3, 4, 6]);

    expect(DB::table('customers')->count())->toBeGreaterThanOrEqual(0);   // the table is still there
});

it('keeps customers and subscribers as two lists, and emails a customer only with consent', function () {
    /*
     * THE DEFECT: a "customers" campaign that mails everyone who ever bought —
     * nobody agreed to that — or a subscriber who left still on the list.
     * MUTATION: make `consent` true for every customer in
     * CampaignAudience::rows() and the no-consent figure is red.
     */
    emCustomer('buyer-only@x.test', [['COSRX' => 9000]]);                    // bought, never said yes
    emCustomer('said-yes@x.test', [['COSRX' => 9000]], 'Dubai', true);         // consent at checkout/account
    emCustomer('also-subscribed@x.test', [['COSRX' => 9000]]);
    emSubscriber('also-subscribed@x.test');                                    // confirmed newsletter = consent
    emSubscriber('subscriber-only@x.test');
    emSubscriber('pending@x.test', false);
    DB::table('marketing_optouts')->insert(['email' => 'said-yes@x.test', 'created_at' => now(), 'updated_at' => now()]);

    $a = new CampaignAudience;
    $customers = $a->count('customers', []);
    $subscribers = $a->count('subscribers', []);

    expect($customers)->toBe(['matched' => 3, 'emailable' => 1, 'unsubscribed' => 1, 'no_consent' => 1])
        ->and(array_column($a->recipients('customers', []), 'email'))->toBe(['also-subscribed@x.test'])
        ->and($subscribers['matched'])->toBe(3)
        ->and(array_column($a->recipients('subscribers', []), 'email'))->toBe(['also-subscribed@x.test', 'subscriber-only@x.test'])
        // A customers group never contains a subscriber who never bought.
        ->and(array_column($a->recipients('customers', []), 'email'))->not->toContain('subscriber-only@x.test');
});

it('counts live as the filters change, and saves a group', function () {
    emAdmin('manager');
    $brand = Brand::create(['name' => 'Medicube', 'slug' => 'medicube']);
    emCustomer('a@x.test', [['Medicube' => 60000]], 'Dubai', true);
    emCustomer('b@x.test', [['Medicube' => 10000]], 'Sharjah', true);
    emCustomer('c@x.test', [['COSRX' => 90000]], 'Dubai', true);

    $count = fn (array $rules) => $this->postJson('/admin-api/email-marketing/groups/count', ['audience' => 'customers', 'rules' => $rules])->assertOk()->json();

    expect($count([])['matched'])->toBe(3)
        ->and($count([['field' => 'brand', 'op' => 'ever', 'value' => $brand->id]])['matched'])->toBe(2)
        ->and($count([['field' => 'brand', 'op' => 'ever', 'value' => $brand->id], ['field' => 'emirate', 'op' => 'any_of', 'value' => ['dubai']]])['emailable'])->toBe(1)
        ->and($count([['field' => 'nonsense', 'op' => 'x']])['ignored'])->toBe([0]);

    $this->postJson('/admin-api/email-marketing/groups', ['name' => 'Mostly bought Medicube · Dubai', 'audience' => 'customers',
        'rules' => [['field' => 'brand', 'op' => 'mostly', 'value' => $brand->id], ['field' => 'emirate', 'op' => 'any_of', 'value' => ['dubai']]]])->assertOk();

    $this->getJson('/admin-api/email-marketing/groups')->assertOk()
        ->assertJsonPath('groups.0.name', 'Mostly bought Medicube · Dubai')
        ->assertJsonPath('groups.0.can_email', 1)
        ->assertJsonPath('groups.0.list', 'Customers');

    // An incomplete rule is refused, not saved as "everyone".
    $this->postJson('/admin-api/email-marketing/groups', ['name' => 'Broken', 'audience' => 'customers', 'rules' => [['field' => 'spent', 'op' => 'at_least', 'value' => '']]])
        ->assertStatus(422);
});

/* ============================================================ the builder */

it('builds the email only from kit blocks: escaped text, checked links, no HTML block', function () {
    /*
     * CLAUDE.md rule 5: "the builder's HTML is built only from kit blocks".
     * MUTATION: print a text block with {!! !!}, or accept 'html' in
     * KitBlocks::TYPES, and red.
     */
    emAdmin('owner');
    $c = emCampaign();

    $this->postJson("/admin-api/email-marketing/campaigns/{$c->id}", ['blocks' => [
        ['type' => 'text', 'text' => '<img src=x onerror=alert(1)> Hello'],
        ['type' => 'html', 'html' => '<script>alert(2)</script>'],
        ['type' => 'button', 'label' => 'Click', 'url' => 'javascript:alert(3)'],
        ['type' => 'button', 'label' => 'Data', 'url' => 'data:text/html,<b>x</b>'],
        ['type' => 'image', 'src' => 'javascript:alert(4)', 'alt' => 'x'],
        ['type' => 'heading', 'icon' => '"><script>', 'title' => '<i>Title</i>', 'lead' => ''],
    ]])->assertOk();

    $html = $this->post("/admin-api/email-marketing/campaigns/{$c->id}/preview")->assertOk()->getContent();

    expect($html)->toContain('&lt;img src=x onerror=alert(1)&gt; Hello')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->not->toContain('<script>alert')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('data:text/html')
        ->and($html)->toContain('&lt;i&gt;Title&lt;/i&gt;')
        ->and(collect($c->fresh()->blocks)->pluck('type')->all())->toBe(['text', 'button', 'button', 'image', 'heading']);
});

it('fills a product grid from the catalogue as it is, skipping what cannot be bought', function () {
    $medi = emProduct('Medicube', 'Zero Pore Pad', 3900);
    emProduct('Medicube', 'Sold Out Serum')->forceFill(['stock_status' => 'outofstock'])->save();
    emProduct('COSRX', 'Snail Essence');

    $c = emCampaign(['blocks' => [['type' => 'products', 'fill' => 'brand', 'brand_id' => $medi->brand_id, 'count' => 4, 'cols' => 2, 'cta' => 'Shop now']]]);
    $html = app(\App\Services\Marketing\CampaignRenderer::class)->preview($c);

    expect($html)->toContain('Zero Pore Pad')
        ->and($html)->not->toContain('Sold Out Serum')
        ->and($html)->not->toContain('Snail Essence')
        ->and($html)->toContain('/product/' . $medi->slug . '/');
});

/* ======================================================== review & send */

it('lets only Owner and Administrator (manager) press Send, and fails closed for everyone else', function () {
    /*
     * The owner, row 53: "campaign Send for Owner and Administrator only".
     * MUTATION: add 'editor' to CAPABILITIES['campaign.send'], or drop the
     * campaign.send RULES lines (the route falls to campaign.manage), and red.
     */
    expect(AdminCapabilities::CAPABILITIES['campaign.send'])->toBe(['owner', 'manager'])
        ->and(AdminCapabilities::forPath('POST', 'admin-api/email-marketing/campaigns/{id}/send'))->toBe('campaign.send')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/email-marketing/campaigns/{id}/cancel'))->toBe('campaign.send')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/email-marketing/pump'))->toBe('campaign.send')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/email-marketing/campaigns/{id}'))->toBe('campaign.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/email-marketing/overview'))->toBe('campaign.view');

    Mail::fake();
    emCustomer('yes@x.test', [['COSRX' => 9000]], 'Dubai', true);
    $c = emCampaign();

    foreach (['editor', 'support'] as $role) {
        emAdmin($role);
        $this->postJson("/admin-api/email-marketing/campaigns/{$c->id}/send", ['mode' => 'now', 'confirm' => 1])->assertForbidden();
        $this->getJson('/admin-api/email-marketing/overview')->assertForbidden();
    }

    expect($c->fresh()->status)->toBe('draft');
    Mail::assertNothingSent();

    emAdmin('manager');
    $this->postJson("/admin-api/email-marketing/campaigns/{$c->id}/send", ['mode' => 'now', 'confirm' => 1])->assertOk();
    Mail::assertSent(CampaignMail::class, 1);
});

it('sends only after the exact recipient count is confirmed, and refuses a count that went stale', function () {
    /*
     * THE DEFECT: a confirmation that says "214 will receive it" while the
     * list has moved — the owner agrees to one thing and the shop does another.
     * MUTATION: drop the `$now !== confirm` check in send() and red.
     */
    Mail::fake();
    emAdmin('owner');
    emCustomer('one@x.test', [['COSRX' => 9000]], 'Dubai', true);
    emCustomer('two@x.test', [['COSRX' => 9000]], 'Dubai', true);
    $c = emCampaign();

    $this->getJson("/admin-api/email-marketing/campaigns/{$c->id}/review")->assertOk()->assertJsonPath('recipients', 2);

    // Somebody unsubscribes between the review and the press.
    DB::table('marketing_optouts')->insert(['email' => 'two@x.test', 'created_at' => now(), 'updated_at' => now()]);

    $this->postJson("/admin-api/email-marketing/campaigns/{$c->id}/send", ['mode' => 'now', 'confirm' => 2])
        ->assertStatus(409)->assertJsonPath('recipients', 1);
    Mail::assertNothingSent();

    $this->postJson("/admin-api/email-marketing/campaigns/{$c->id}/send", ['mode' => 'now', 'confirm' => 1])->assertOk();
    Mail::assertSent(CampaignMail::class, fn ($m) => $m->hasTo('one@x.test'));
    Mail::assertNotSent(CampaignMail::class, fn ($m) => $m->hasTo('two@x.test'));
});

it('schedules for a time in Dubai, and the heartbeat starts it when the time comes', function () {
    Mail::fake();
    emAdmin('owner');
    emCustomer('one@x.test', [['COSRX' => 9000]], 'Dubai', true);
    $c = emCampaign();
    $at = now()->setTimezone('Asia/Dubai')->addDay();

    $this->postJson("/admin-api/email-marketing/campaigns/{$c->id}/send", ['mode' => 'schedule', 'confirm' => 1, 'date' => $at->format('Y-m-d'), 'time' => $at->format('H:i')])
        ->assertOk()->assertJsonPath('campaign.status', 'scheduled');

    app(CampaignSender::class)->sweep();
    Mail::assertNothingSent();

    $this->travel(2)->days();
    Cache::forget(CampaignSender::LOCK);
    app(CampaignSender::class)->sweep();
    Mail::assertSent(CampaignMail::class, 1);
    expect($c->fresh()->status)->toBe('sent');
});

it('carries List-Unsubscribe and one-click List-Unsubscribe-Post, the footer link, and a name in "Hi {first_name}"', function () {
    /*
     * Gmail and Yahoo refuse bulk mail without one-click unsubscribe (RFC
     * 8058). MUTATION: drop headers() from CampaignMail and red.
     */
    config(['mail.mailers.kbb' => ['transport' => 'array']]);
    $customer = emCustomer('aisha@x.test', [['COSRX' => 9000]], 'Dubai', true);
    $c = emCampaign();
    app(CampaignSender::class)->start($c);

    $mail = null;
    Mail::fake();
    Cache::forget(CampaignSender::LOCK);
    app(CampaignSender::class)->sweep();
    Mail::assertSent(CampaignMail::class, function (CampaignMail $m) use (&$mail) {
        $mail = $m;

        return true;
    });

    $token = CampaignTracking::token($c->id, 'aisha@x.test');
    $headers = $mail->headers()->text;
    $html = $mail->render();

    expect($headers['List-Unsubscribe'])->toBe('<' . \App\Support\Url::external('/m/u/' . $token) . '>')
        ->and($headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($html)->toContain('/m/u/' . $token . '"')
        ->and($html)->toContain('Hi Aisha, more Medicube')
        ->and($html)->toContain('/m/o/' . $token)
        // No address anywhere in a URL.
        ->and($html)->not->toMatch('~href="[^"]*aisha%40x~')
        ->and($html)->not->toMatch('~href="[^"]*aisha@x~')
        ->and($mail->envelope()->subject)->toBe('Hi Aisha, your glow edit');
});

/* ========================================================== at volume */

it('never sends the same campaign to an address twice: every row is claimed before it is sent', function () {
    /*
     * THE DEFECT: two requests run the heartbeat at the same moment and both
     * send the batch. MUTATION: drop the `where('status', 'queued')` from the
     * claim UPDATE in CampaignSender::batch() and the duplicate goes out.
     */
    Mail::fake();
    foreach (range(1, 5) as $i) {
        emCustomer("p{$i}@x.test", [['COSRX' => 9000]], 'Dubai', true);
    }

    $c = emCampaign();
    $sender = app(CampaignSender::class);
    expect($sender->start($c))->toBe(5)
        // Starting again is a no-op, not a second list.
        ->and($sender->start($c->fresh()))->toBeNull()
        ->and(DB::table('campaign_recipients')->where('campaign_id', $c->id)->count())->toBe(5);

    // Another process has already claimed one row and is mid-send.
    $taken = DB::table('campaign_recipients')->where('campaign_id', $c->id)->orderBy('id')->value('id');
    DB::table('campaign_recipients')->where('id', $taken)->update(['status' => 'sending', 'claimed_at' => now()]);

    Cache::forget(CampaignSender::LOCK);
    $sender->sweep();
    Cache::forget(CampaignSender::LOCK);
    $sender->sweep();

    Mail::assertSent(CampaignMail::class, 4);
    expect(DB::table('campaign_recipients')->where('campaign_id', $c->id)->where('status', 'sent')->count())->toBe(4);

    // A claim that never finished is retired as failed, never re-sent.
    DB::table('campaign_recipients')->where('id', $taken)->update(['claimed_at' => now()->subHour()]);
    Cache::forget(CampaignSender::LOCK);
    $sender->sweep();
    Mail::assertSent(CampaignMail::class, 4);
    expect(DB::table('campaign_recipients')->where('id', $taken)->value('status'))->toBe('failed')
        ->and($c->fresh()->status)->toBe('sent');
});

it('stops at the daily cap and carries on tomorrow; the cap defaults to 1,500 on Google Workspace', function () {
    /*
     * Google Workspace suspends an account that sends past its daily limit.
     * MUTATION: drop remainingToday() from sweep() and all five go today.
     */
    Mail::fake();
    foreach (range(1, 5) as $i) {
        emCustomer("q{$i}@x.test", [['COSRX' => 9000]], 'Dubai', true);
    }

    $sender = app(CampaignSender::class);
    expect($sender->cap())->toBe(CampaignSender::CAP_SERVER);
    app(\App\Services\Mail\MailSettings::class)->save(['mail_transport' => 'gmail']);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    expect($sender->cap())->toBe(1500);

    app(SettingsService::class)->set(CampaignSender::CAP_KEY, '2');
    SettingsService::forgetMemo();

    $c = emCampaign();
    $sender->start($c);
    Cache::forget(CampaignSender::LOCK);
    $sender->sweep();
    Cache::forget(CampaignSender::LOCK);
    $sender->sweep();
    Mail::assertSent(CampaignMail::class, 2);

    $this->travel(1)->days();
    Cache::forget(CampaignSender::LOCK);
    $sender->sweep();
    Mail::assertSent(CampaignMail::class, 4);
});

it('runs from the heartbeat after a page request, at most once per interval', function () {
    Mail::fake();
    emCustomer('tick@x.test', [['COSRX' => 9000]], 'Dubai', true);
    $c = emCampaign();
    app(CampaignSender::class)->start($c);

    // Held off: tests/Pest.php touched the marker.
    expect((new CampaignTick)->onRequest())->toBeFalse();

    @unlink(CampaignTick::markerPath());
    expect((new CampaignTick)->onRequest())->toBeTrue()
        ->and((new CampaignTick)->onRequest())->toBeFalse();

    app()->terminate();
    Mail::assertSent(CampaignMail::class, 1);
});

/* ============================================================== tracking */

it('serves the same blank pixel for any token, and counts a real open', function () {
    $c = emCampaign();
    emCustomer('o@x.test', [['COSRX' => 9000]], 'Dubai', true);
    app(CampaignSender::class)->start($c);
    $token = CampaignTracking::token($c->id, 'o@x.test');

    $real = $this->get('/m/o/' . $token)->assertOk();
    $fake = $this->get('/m/o/' . str_repeat('A', 43))->assertOk();

    expect($real->getContent())->toBe($fake->getContent())
        ->and($real->headers->get('Content-Type'))->toBe('image/gif')
        ->and($real->headers->get('Set-Cookie'))->toBeNull()
        ->and(DB::table('campaign_recipients')->where('email', 'o@x.test')->value('open_count'))->toBe(1);
});

it('redirects a click only to a link the campaign itself contains, never anywhere a URL says', function () {
    /*
     * NO OPEN REDIRECT: a valid token with a forged signature, an index
     * outside the list, or a URL appended to the path all land on the shop's
     * home page. MUTATION: redirect to a `?u=` from the request, or drop the
     * hash_equals() in CampaignTracking::click(), and red.
     */
    Mail::fake();
    emCustomer('k@x.test', [['COSRX' => 9000]], 'Dubai', true);
    $c = emCampaign();
    app(CampaignSender::class)->start($c);
    $c->refresh();
    $token = CampaignTracking::token($c->id, 'k@x.test');
    $links = $c->links;
    $i = collect($links)->search(fn ($l) => $l['url'] === 'https://extrabeauty.ae/sale/');
    $sig = CampaignTracking::linkSignature($c->id, $i, 'https://extrabeauty.ae/sale/');
    $home = \App\Support\Url::external('/');

    $this->get("/m/c/{$token}/{$i}/{$sig}")->assertRedirect('https://extrabeauty.ae/sale/');
    $this->get("/m/c/{$token}/{$i}/" . str_repeat('0', 32))->assertRedirect($home);
    $this->get("/m/c/{$token}/99/{$sig}")->assertRedirect($home);
    $this->get('/m/c/' . str_repeat('B', 43) . "/{$i}/{$sig}")->assertRedirect($home);
    $this->get("/m/c/{$token}/{$i}/{$sig}?u=https://evil.test/")->assertRedirect('https://extrabeauty.ae/sale/');

    expect(DB::table('campaign_clicks')->count())->toBe(2)
        ->and(DB::table('campaign_recipients')->where('email', 'k@x.test')->value('click_count'))->toBe(2);

    // The sent email's links are the tracked ones, carrying no address.
    Cache::forget(CampaignSender::LOCK);
    app(CampaignSender::class)->sweep();
    Mail::assertSent(CampaignMail::class, function (CampaignMail $m) use ($token, $i, $sig) {
        return str_contains($m->render(), "/m/c/{$token}/{$i}/{$sig}");
    });
});

it('unsubscribes with one click, at once — a GET changes nothing, the POST does, and the rest of the campaign skips them', function () {
    /*
     * "honoured immediately": the address is checked again at the moment of
     * sending. MUTATION: drop mayNotSend() from CampaignSender::batch() and
     * the unsubscribed address still gets the email.
     */
    Mail::fake();
    $sub = emSubscriber('leave@x.test');
    emSubscriber('stay@x.test');
    $c = emCampaign(['audience' => 'subscribers']);
    app(CampaignSender::class)->start($c);
    $token = CampaignTracking::token($c->id, 'leave@x.test');

    // A mail scanner fetching the link does nothing.
    $this->get('/m/u/' . $token)->assertOk()->assertSee(__('store.newsletter.unsub_button'));
    expect(DB::table('marketing_optouts')->count())->toBe(0);

    // The mail client's RFC 8058 one-click POST — no CSRF token, no session.
    $this->call('POST', '/m/u/' . $token, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('Unsubscribed.');

    expect(DB::table('marketing_optouts')->where('email', 'leave@x.test')->exists())->toBeTrue()
        ->and(DB::table('subscribers')->where('id', $sub)->value('status'))->toBe('unsubscribed');

    Cache::forget(CampaignSender::LOCK);
    app(CampaignSender::class)->sweep();
    Mail::assertSent(CampaignMail::class, fn ($m) => $m->hasTo('stay@x.test'));
    Mail::assertNotSent(CampaignMail::class, fn ($m) => $m->hasTo('leave@x.test'));
    expect(DB::table('campaign_recipients')->where('email', 'leave@x.test')->value('status'))->toBe('skipped');

    // A forged token: the same "not valid" answer, nothing written.
    $this->call('POST', '/m/u/' . str_repeat('C', 43), ['List-Unsubscribe' => 'One-Click'])->assertNotFound();
    expect(DB::table('marketing_optouts')->count())->toBe(1);
});

/* ================================================================ report */

it('reports accepted, refused, opened (at least), clicked and orders within seven days', function () {
    Mail::fake();
    $a = emCustomer('r1@x.test', [['COSRX' => 9000]], 'Dubai', true);
    emCustomer('r2@x.test', [['COSRX' => 9000]], 'Dubai', true);
    $c = emCampaign();
    app(CampaignSender::class)->start($c);
    Cache::forget(CampaignSender::LOCK);
    app(CampaignSender::class)->sweep();

    $c->refresh();
    $token = CampaignTracking::token($c->id, 'r1@x.test');
    $this->get('/m/o/' . $token);
    $i = 0;
    $this->get("/m/c/{$token}/{$i}/" . CampaignTracking::linkSignature($c->id, $i, $c->links[$i]['url']));

    // r1 orders two days later; an order a month later is not the campaign's.
    $this->travel(2)->days();
    Order::create(['order_number' => 'EM-R1', 'customer_id' => $a->id, 'email' => 'r1@x.test', 'status' => 'processing', 'currency' => 'AED', 'subtotal' => 19400, 'total' => 19400]);
    $this->travel(30)->days();
    Order::create(['order_number' => 'EM-R1-late', 'customer_id' => $a->id, 'email' => 'r1@x.test', 'status' => 'processing', 'currency' => 'AED', 'subtotal' => 5000, 'total' => 5000]);

    $r = app(CampaignReport::class)->for($c->fresh());

    expect($r['sent'])->toBe(2)
        ->and($r['opened_at_least'])->toBe(1)
        ->and($r['clicked'])->toBe(1)
        ->and($r['click_rate'])->toBe(50.0)
        ->and($r['orders'])->toBe(1)
        ->and($r['revenue_fils'])->toBe(19400)
        ->and($r['top_links'][0]['clicks'])->toBe(1);

    emAdmin('manager');
    $this->getJson("/admin-api/email-marketing/campaigns/{$c->id}/report")->assertOk()->assertJsonPath('report.orders', 1);
    $this->getJson('/admin-api/email-marketing/overview')->assertOk()->assertJsonPath('campaigns.0.orders.n', 1);
});
