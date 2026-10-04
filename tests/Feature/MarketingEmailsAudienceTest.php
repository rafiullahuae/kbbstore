<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CustomersApiController;
use App\Models\Refund;
use App\Services\Marketing\Audience;
use App\Support\CustomerAggregates;
use Illuminate\Support\Facades\DB;
use Tests\Support\MarketingEmailsRoutes;
use Tests\Support\MarketingFixtures as F;

/**
 * Marketing Emails → Customer groups (Lane MK, docs/EMAILS-PLAN.md §3, D3/D4).
 *
 * The defects these hold off: a group whose "spent AED 500+" is a different
 * list from Store → Customers' (two definitions of spend); a campaign that
 * reaches someone who unsubscribed, bounced or never confirmed; an emirate
 * rule that misses every WooCommerce order because they say "DU"; and a rule
 * value that reaches SQL as text.
 */

function mkIds(array $rows): array
{
    $ids = array_map(fn ($r) => is_array($r) ? $r['id'] : $r->id, $rows);
    sort($ids);

    return $ids;
}

function mkMatchIds(string $audience, array $rules, string $match = 'all'): array
{
    $rows = DB::query()->fromSub(app(Audience::class)->annotated($audience, $rules, $match), 'a')->pluck('a.id')->map(fn ($v) => (int) $v)->all();
    sort($rows);

    return $rows;
}

it('moved the aggregate, it did not copy it: Store → Customers reads CustomerAggregates', function () {
    /*
     * Plan §3: "extracted into a shared CustomerAggregates query builder (it
     * moves and keeps its tests), not copied". Two copies of the spend SQL is
     * two definitions of spend.
     *
     * MUTATION: paste the old rowQuery() body back into the controller and the
     * first count is 1.
     */
    $controller = (string) file_get_contents(base_path('app/Http/Controllers/Admin/CustomersApiController.php'));
    $shared = (string) file_get_contents(base_path('app/Support/CustomerAggregates.php'));

    expect(substr_count($controller, 'SUM(CASE WHEN orders.status IN'))->toBe(0)
        ->and(substr_count($controller, 'CustomerAggregates::query($from)'))->toBe(1)
        ->and(substr_count($shared, 'SUM(CASE WHEN orders.status IN'))->toBe(2);
});

it('counts spend exactly as Store → Customers does: paid orders, net of refunds', function () {
    /*
     * MUTATION: drop the refunds join from CustomerAggregates::ordersSubquery()
     * and BOTH sides move together — which is the point of sharing it — but
     * the refund case below then puts the customer above AED 500.
     */
    $a = F::customer('spend-a@example.com');
    F::order($a, [['Anua', 60000]]);                       // AED 600
    $b = F::customer('spend-b@example.com');
    $o = F::order($b, [['Anua', 80000]]);                  // AED 800, AED 400 refunded
    Refund::create(['order_id' => $o->id, 'amount' => 40000, 'status' => 'succeeded']);
    $c = F::customer('spend-c@example.com');
    F::order($c, [['Anua', 90000]], 'cancelled');          // not spend
    $d = F::customer('spend-d@example.com');
    F::order($d, [['Anua', 30000]]);
    F::order($d, [['Anua', 30000]]);                        // two orders, AED 600

    $group = mkMatchIds('customers', [['field' => 'spent', 'op' => 'gte', 'value' => 500]]);
    $store = app(CustomersApiController::class)->matchingIds(['spend_min' => '500'], 100000);
    sort($store);

    expect($group)->toBe($store)
        ->and($group)->toContain($a->id)->toContain($d->id)
        ->and($group)->not->toContain($b->id)->not->toContain($c->id);

    $repeat = mkMatchIds('customers', [['field' => 'orders', 'op' => 'gte', 'value' => 2]]);
    $storeRepeat = app(CustomersApiController::class)->matchingIds(['filter' => 'repeat'], 100000);
    sort($storeRepeat);
    expect($repeat)->toBe($storeRepeat)->and($repeat)->toContain($d->id);
});

it('never puts a suppressed, opted-out, unsubscribed or unconfirmed address in an audience', function () {
    /*
     * The audience is what a campaign is sent to; each of these people has
     * said no (or never said yes). Counted as matched, never as emailable,
     * and never returned by recipientsAfter().
     *
     * MUTATION: change `ELSE 'ok'` handling so the CASE starts with
     * `WHEN 1 = 1 THEN 'ok'` and every one of them is mailed.
     */
    $ok = F::customer('fine@example.com');
    $sup = F::customer('unsub@example.com');
    $bounce = F::customer('bounce@example.com');
    $opt = F::customer('optout@example.com');
    $news = F::customer('newsletter-no@example.com');
    $pend = F::customer('pending@example.com');
    $bad = F::customer('not-an-address');

    DB::table('email_suppressions')->insert([
        ['email' => 'unsub@example.com', 'reason' => 'unsubscribe', 'source' => 'campaign:1', 'created_at' => now()],
        ['email' => 'bounce@example.com', 'reason' => 'bounce', 'source' => 'campaign:1', 'created_at' => now()],
    ]);
    DB::table('outbound_optouts')->insert(['email' => 'optout@example.com', 'created_at' => now(), 'updated_at' => now()]);
    F::subscriber('newsletter-no@example.com', 'unsubscribed', false);
    F::subscriber('pending@example.com', 'pending', false);

    $n = app(Audience::class)->count('customers', []);
    $emails = array_column(app(Audience::class)->recipientsAfter('customers', [], 'all', 0), 'email');

    expect($emails)->toContain('fine@example.com')
        ->and($emails)->not->toContain('unsub@example.com')
        ->and($emails)->not->toContain('bounce@example.com')
        ->and($emails)->not->toContain('optout@example.com')
        ->and($emails)->not->toContain('newsletter-no@example.com')
        ->and($emails)->not->toContain('pending@example.com')
        ->and($emails)->not->toContain('not-an-address');

    expect($n['reasons']['unsubscribed'])->toBeGreaterThanOrEqual(3)
        ->and($n['reasons']['bounced'])->toBeGreaterThanOrEqual(1)
        ->and($n['reasons']['pending'])->toBeGreaterThanOrEqual(1)
        ->and($n['reasons']['invalid'])->toBeGreaterThanOrEqual(1)
        ->and($n['matched'] - $n['emailable'])->toBe(array_sum($n['reasons']));

    // Subscribers: only confirmed and subscribed (NewsletterList::marketable()).
    F::subscriber('sub-ok@example.com');
    F::subscriber('sub-pending@example.com', 'pending', false);
    F::subscriber('sub-grand@example.com', 'subscribed', false);
    $subs = array_column(app(Audience::class)->recipientsAfter('subscribers', [], 'all', 0), 'email');

    expect($subs)->toContain('sub-ok@example.com')
        ->and($subs)->not->toContain('sub-pending@example.com')
        ->and($subs)->not->toContain('sub-grand@example.com')
        ->and($subs)->not->toContain('newsletter-no@example.com');
});

it('reads the emirate from the delivery address, however it was spelled', function () {
    /*
     * The owner's D3/D4: "region (Dubai, Abu Dhabi, Sharjah…)". Checkout
     * writes "Dubai"; a WooCommerce import writes "DU"; an Arabic address
     * writes "دبي". All three are Dubai.
     *
     * MUTATION: remove 'du' from Audience::EMIRATES['dubai'] and the imported
     * order is missing from the Dubai group.
     */
    $typed = F::customer('dxb-typed@example.com');
    F::order($typed, [['Anua', 1000]], 'processing', 'Dubai');
    $code = F::customer('dxb-code@example.com');
    F::order($code, [['Anua', 1000]], 'completed', 'DU');
    $ar = F::customer('dxb-ar@example.com');
    F::order($ar, [['Anua', 1000]], 'shipped', 'دبي');
    $auh = F::customer('auh@example.com');
    F::order($auh, [['Anua', 1000]], 'processing', 'AZ');
    $ksa = F::customer('ksa@example.com');
    F::order($ksa, [['Anua', 1000]], 'processing', 'Riyadh', null, 'SA');
    $unpaid = F::customer('dxb-unpaid@example.com');
    F::order($unpaid, [['Anua', 1000]], 'pending', 'Dubai');

    $dubai = mkMatchIds('customers', [['field' => 'emirate', 'op' => 'any_of', 'value' => ['dubai']]]);
    expect($dubai)->toContain($typed->id)->toContain($code->id)->toContain($ar->id)
        ->and($dubai)->not->toContain($auh->id)->not->toContain($ksa->id)->not->toContain($unpaid->id);

    $two = mkMatchIds('customers', [['field' => 'emirate', 'op' => 'any_of', 'value' => ['dubai', 'abu_dhabi']]]);
    expect($two)->toContain($auh->id);

    $outside = mkMatchIds('customers', [['field' => 'emirate', 'op' => 'any_of', 'value' => ['outside']]]);
    expect($outside)->toContain($ksa->id)->not->toContain($typed->id);

    $notDubai = mkMatchIds('customers', [['field' => 'emirate', 'op' => 'none_of', 'value' => ['dubai']]]);
    expect($notDubai)->toContain($auh->id)->not->toContain($code->id);

    expect(Audience::emirateOf('Ras Al Khaimah'))->toBe('ras_al_khaimah')
        ->and(Audience::emirateOf('UQ'))->toBe('umm_al_quwain')
        ->and(Audience::emirateOf('Doha', 'QA'))->toBe('outside');
});

it('groups by the brand a customer MOSTLY bought — the biggest share of spend — and by ever bought', function () {
    /*
     * "mostly bought Medicube" (the owner): Medicube is the brand with the
     * largest share of that customer's spend, not merely one they touched.
     *
     * MUTATION: drop the whereNotExists(b2.spend > b1.spend) and the customer
     * who bought one small Medicube item next to a big COSRX order joins the
     * Medicube fans.
     */
    $fan = F::customer('fan@example.com');
    F::order($fan, [['Medicube', 30000], ['COSRX', 5000]]);
    $dabbler = F::customer('dabbler@example.com');
    F::order($dabbler, [['Medicube', 2000], ['COSRX', 40000]]);
    $twoOrders = F::customer('two@example.com');
    F::order($twoOrders, [['medicube ', 9000]]);
    F::order($twoOrders, [['Anua', 8000]]);

    $mostly = mkMatchIds('customers', [['field' => 'brand', 'op' => 'mostly', 'value' => 'Medicube']]);
    expect($mostly)->toContain($fan->id)->toContain($twoOrders->id)->not->toContain($dabbler->id);

    $ever = mkMatchIds('customers', [['field' => 'brand', 'op' => 'ever', 'value' => 'medicube']]);
    expect($ever)->toContain($dabbler->id);

    $top = app(Audience::class)->topBrand('customers', [['field' => 'brand', 'op' => 'mostly', 'value' => 'Medicube']]);
    expect(mb_strtolower(trim($top['name'])))->toBe('medicube');
});

it('groups by order month, year and a custom range on the shop\'s clock', function () {
    $sep = F::customer('sep@example.com');
    F::order($sep, [['Anua', 1000]], 'completed', 'Dubai', '2026-09-15 10:00:00');
    $edge = F::customer('edge@example.com');
    // 30 Sep 21:00 UTC is 1 October 01:00 in Dubai — an October order.
    F::order($edge, [['Anua', 1000]], 'completed', 'Dubai', '2026-09-30 21:00:00');
    $old = F::customer('2025@example.com');
    F::order($old, [['Anua', 1000]], 'completed', 'Dubai', '2025-03-01 10:00:00');

    $month = mkMatchIds('customers', [['field' => 'order_date', 'op' => 'in_month', 'value' => '2026-09']]);
    expect($month)->toContain($sep->id)->not->toContain($edge->id);

    $year = mkMatchIds('customers', [['field' => 'order_date', 'op' => 'in_year', 'value' => 2026]]);
    expect($year)->toContain($sep->id)->toContain($edge->id)->not->toContain($old->id);

    $range = mkMatchIds('customers', [['field' => 'order_date', 'op' => 'between', 'value' => ['2026-10-01', '2026-10-31']]]);
    expect($range)->toBe([$edge->id]);
});

it('combines rules with all and any', function () {
    $a = F::customer('combo-a@example.com');
    F::order($a, [['Anua', 100000]], 'processing', 'Sharjah');
    $b = F::customer('combo-b@example.com');
    F::order($b, [['Anua', 1000]], 'processing', 'Sharjah');

    $rules = [['field' => 'emirate', 'op' => 'any_of', 'value' => ['sharjah']], ['field' => 'spent', 'op' => 'gte', 'value' => 500]];

    expect(array_intersect(mkMatchIds('customers', $rules, 'all'), [$a->id, $b->id]))->toEqualCanonicalizing([$a->id])
        ->and(array_intersect(mkMatchIds('customers', $rules, 'any'), [$a->id, $b->id]))->toEqualCanonicalizing([$a->id, $b->id]);
});

it('accepts only whitelisted fields, operators and value shapes, and binds every value', function () {
    /*
     * MUTATION: in Audience::compile(), interpolate the brand name into the
     * whereRaw string instead of binding it, and the SQL text check below
     * finds the payload.
     */
    Audience::clean('customers', [['field' => 'password', 'op' => 'is', 'value' => 'x']], $errors);
    expect($errors)->not->toBe([]);

    Audience::clean('customers', [['field' => 'spent', 'op' => '; DROP TABLE customers', 'value' => 1]], $errors);
    expect($errors)->not->toBe([]);

    Audience::clean('customers', [['field' => 'spent', 'op' => 'gte', 'value' => '1 OR 1=1']], $errors);
    expect($errors)->not->toBe([]);

    Audience::clean('customers', [['field' => 'signed_up', 'op' => 'in_year', 'value' => 2026]], $errors);
    expect($errors)->not->toBe([], 'a subscribers rule on the customers list');

    $payload = "x') OR 1=1 --";
    $q = app(Audience::class)->annotated('customers', [['field' => 'brand', 'op' => 'ever', 'value' => $payload]], 'all');

    expect($q->toSql())->not->toContain($payload)
        ->and($q->getBindings())->toContain(mb_strtolower($payload));
    expect(DB::query()->fromSub($q, 'a')->count())->toBe(0);
});

it('counts in one statement and pages "See the N" fifty at a time in one statement', function () {
    /*
     * No N+1 (CLAUDE.md rule 4): the count and a page cost one statement each
     * however many customers there are.
     */
    for ($i = 0; $i < 60; $i++) {
        $c = F::customer("page{$i}@example.com");
        F::order($c, [['Anua', 1000]]);
    }

    // Once, so DemoSeed's once-per-process "does the demo table exist" check
    // is not counted against the statement being measured.
    // Likewise page() once, for the money format and the shop's time zone,
    // which are settings read once per process.
    app(Audience::class)->count('customers', []);
    app(Audience::class)->page('customers', [], 'all', 1);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $n = app(Audience::class)->count('customers', [['field' => 'orders', 'op' => 'gte', 'value' => 1]]);
    expect(count(DB::getQueryLog()))->toBe(1);

    DB::flushQueryLog();
    $page = app(Audience::class)->page('customers', [['field' => 'orders', 'op' => 'gte', 'value' => 1]], 'all', 2);
    expect(count(DB::getQueryLog()))->toBe(1);
    DB::disableQueryLog();

    expect($n['matched'])->toBeGreaterThanOrEqual(60)
        ->and(count($page))->toBe(min(50, $n['matched'] - 50));
});

it('answers the live count and the people list over the admin API, and saves a group', function () {
    MarketingEmailsRoutes::wire(app());
    $this->actingAs(F::admin('manager'), 'admin');
    $c = F::customer('live@example.com');
    F::order($c, [['Medicube', 70000]], 'processing', 'Dubai');
    F::subscriber('live-sub@example.com');

    $body = ['audience' => 'customers', 'match' => 'all', 'rules' => [['field' => 'brand', 'op' => 'mostly', 'value' => 'Medicube'], ['field' => 'emirate', 'op' => 'any_of', 'value' => ['dubai']]]];

    $this->postJson('/admin-api/email-marketing/groups/count', $body)->assertOk()
        ->assertJsonPath('matched', 1)->assertJsonPath('emailable', 1)->assertJsonPath('top_brand.name', 'Medicube');

    $this->postJson('/admin-api/email-marketing/groups/people', $body + ['page' => 1])->assertOk()
        ->assertJsonPath('people.0.email', 'live@example.com')->assertJsonPath('people.0.reason', 'ok');

    $this->postJson('/admin-api/email-marketing/groups/count', ['audience' => 'customers', 'rules' => [['field' => 'nope', 'op' => 'is', 'value' => 1]]])
        ->assertStatus(422);

    $id = $this->postJson('/admin-api/email-marketing/groups', $body + ['name' => 'Mostly bought Medicube · Dubai'])->assertOk()->json('id');
    $list = $this->getJson('/admin-api/email-marketing/groups')->assertOk()->json('groups');
    $saved = collect($list)->firstWhere('id', $id);

    expect($saved['name'])->toBe('Mostly bought Medicube · Dubai')->and($saved['emailable'])->toBe(1);

    $presets = collect($list)->where('preset', true)->pluck('name')->all();

    foreach (['Never ordered', 'One order only', 'Repeat buyers (2+ orders)', 'VIP (spent AED 1,000+)', 'Lapsed 90 days', 'All confirmed subscribers'] as $preset) {
        expect(in_array($preset, $presets, true))->toBeTrue("the preset group {$preset} is missing");
    }
});
