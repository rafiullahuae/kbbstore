<?php

declare(strict_types=1);

use App\Http\Controllers\Store\PageController;
use App\Services\Marketing\CampaignReport;
use App\Services\Marketing\CampaignSender;
use App\Services\Marketing\UnsubscribeToken;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Support\MarketingEmailsRoutes;
use Tests\Support\MarketingFixtures as F;

/**
 * Marketing Emails — the public end (Lane MK, docs/EMAILS-PLAN.md §5, and
 * the E3/E4 tests in §7): one-click unsubscribe, the unsubscribe token's
 * oracle check, the click redirect, attribution, and that none of it is on
 * the unauthenticated /api/*.
 */

beforeEach(function () {
    // Every campaign carries a postal address (CampaignSender::hasPostalAddress()).
    app(App\Services\SettingsService::class)->set('mail_address_dubai', 'Office 1, Dubai');
    MarketingEmailsRoutes::wire(app());
});

/** A sent campaign with one send row per address; returns [campaign id, sends by email]. */
function mkSent(array $emails): array
{
    Mail::fake();

    foreach ($emails as $e) {
        $c = F::customer($e);
        F::order($c, [['Anua', 5000]]);
    }

    F::product('Toner', 60, ['total_sales' => 3]);
    $id = F::campaign('best-sellers', F::group('All public', [['field' => 'orders', 'op' => 'gte', 'value' => 1]]));
    $s = app(CampaignSender::class);
    $s->start($id);

    for ($i = 0; $i < 10 && ! $s->step($id)['done']; $i++) {
    }

    return [$id, DB::table('mkt_sends')->where('campaign_id', $id)->get()->keyBy('email')];
}

it('unsubscribes on the RFC 8058 one-click POST with no CSRF token, and is idempotent', function () {
    /*
     * The defect: a one-click unsubscribe that answers 419. Gmail's POST
     * carries no session and no token; behind the web group's CSRF check
     * every one of them fails, the provider shows a button that does nothing,
     * and the recipient presses "Report spam" instead.
     *
     * MUTATION: remove ->withoutMiddleware([ValidateCsrfToken::class]) from
     * routes/marketing-public.php and the first POST is a 419.
     */
    [$id, $sends] = mkSent(['oneclick@example.com']);
    F::subscriber('oneclick@example.com');
    $token = UnsubscribeToken::for((int) $sends['oneclick@example.com']->id, 'oneclick@example.com');

    // Real CSRF on, as on the shop.
    $this->withMiddleware(ValidateCsrfToken::class);

    $first = $this->call('POST', '/email/u/' . $token, ['List-Unsubscribe' => 'One-Click']);
    $first->assertOk();
    expect($first->getContent())->toBe('Unsubscribed.');

    $this->call('POST', '/email/u/' . $token, ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(DB::table('email_suppressions')->where('email', 'oneclick@example.com')->count())->toBe(1)
        ->and(DB::table('email_suppressions')->where('email', 'oneclick@example.com')->value('source'))->toBe('campaign:' . $id)
        ->and(DB::table('subscribers')->where('email', 'oneclick@example.com')->value('status'))->toBe('unsubscribed')
        ->and((int) DB::table('mkt_campaigns')->where('id', $id)->value('unsubscribes'))->toBe(1);
});

it('is the ONLY CSRF-exempt route Marketing Emails adds, listed by its exact path', function () {
    $exempt = [];

    foreach (array_merge(MarketingEmailsRoutes::public(), MarketingEmailsRoutes::admin()) as $route) {
        // A GET is never CSRF-checked; Lane ER's open pixel (GET, no session)
        // drops the middleware only because it would write a session cookie.
        if (in_array(ValidateCsrfToken::class, $route->excludedMiddleware(), true) && array_diff($route->methods(), ['GET', 'HEAD']) !== []) {
            $exempt[] = implode('|', array_diff($route->methods(), ['HEAD'])) . ' ' . $route->uri();
        }
    }

    expect($exempt)->toBe(['POST email/u/{token}']);
});

it('shows the unsubscribe page without changing anything, for any token', function () {
    /*
     * A link scanner fetching the URL must not unsubscribe anybody, and a
     * live token and a dead one must look the same on GET.
     */
    [, $sends] = mkSent(['scanner@example.com']);
    $live = UnsubscribeToken::for((int) $sends['scanner@example.com']->id, 'scanner@example.com');

    $a = $this->get('/email/u/' . $live)->assertOk();
    $b = $this->get('/email/u/zz-' . str_repeat('0', 32))->assertOk();

    expect(DB::table('email_suppressions')->count())->toBe(0)
        ->and($a->getContent())->toContain(__('store.mkt_unsub.button'))
        ->and(str_replace('zz-' . str_repeat('0', 32), 'T', $b->getContent()))
        ->toBe(str_replace($live, 'T', $a->getContent()));
});

it('gives a forged token and a token for a row that was never written the same answer', function () {
    /*
     * The no-oracle rule (QuizSubmission::publicToken): the row is looked up
     * BEFORE the signature is checked and compared with hash_equals, and both
     * failures produce the same page and write nothing.
     *
     * MUTATION: return null from UnsubscribeToken::find() before computing
     * the signature when the row is missing, and the two bodies still match —
     * but the timing does not; the page check below holds the visible half.
     */
    [, $sends] = mkSent(['oracle@example.com']);
    $row = $sends['oracle@example.com'];

    $forged = base_convert((string) $row->id, 10, 36) . '-' . str_repeat('a', 32);
    $unknown = base_convert('999999', 10, 36) . '-' . str_repeat('a', 32);

    $f = $this->post('/email/u/' . $forged)->assertOk();
    $u = $this->post('/email/u/' . $unknown)->assertOk();

    expect($f->getContent())->toBe($u->getContent())
        ->and($f->getContent())->toContain(__('store.newsletter.bad_link_title'))
        ->and(DB::table('email_suppressions')->count())->toBe(0)
        ->and(UnsubscribeToken::find($forged))->toBeNull()
        ->and(UnsubscribeToken::find($unknown))->toBeNull();

    // The real one works, and a token for another address on the same row does not.
    $ok = UnsubscribeToken::for((int) $row->id, 'oracle@example.com');
    expect(UnsubscribeToken::find($ok))->not->toBeNull()
        ->and(UnsubscribeToken::find(UnsubscribeToken::for((int) $row->id, 'someone-else@example.com')))->toBeNull();
});

it('redirects a click only to that campaign\'s own link n, and anything else to the home page', function () {
    /*
     * The defect: an open redirect on the shop's own domain, the phishing
     * kit's favourite tool. The destination is never read from the request.
     *
     * MUTATION: in MarketingEmailController::click(), drop the
     * `where('campaign_id', $send->campaign_id)` and a token from campaign A
     * opens campaign B's links.
     */
    [$id, $sends] = mkSent(['click@example.com']);
    $send = $sends['click@example.com'];
    $link = DB::table('mkt_links')->where('campaign_id', $id)->orderBy('n')->first();
    $home = \App\Support\Url::external('/');

    // Lane ER: a link to the shop lands with its UTM tags (CampaignLinks::tag()).
    $tagged = \App\Services\Marketing\CampaignLinks::tag($link->url, $id, (string) DB::table('mkt_campaigns')->where('id', $id)->value('name'));
    $this->get("/email/c/{$send->token}/{$link->n}")->assertRedirect($tagged);
    expect(DB::table('mkt_clicks')->count())->toBe(1)
        ->and(DB::table('mkt_sends')->where('id', $send->id)->value('first_click_at'))->not->toBeNull();

    $this->get("/email/c/{$send->token}/999")->assertRedirect($home);
    $this->get('/email/c/' . str_repeat('f', 40) . "/{$link->n}")->assertRedirect($home);
    $this->get('/email/c/not-a-token/1')->assertRedirect($home);
    $this->get("/email/c/{$send->token}/1?url=https://evil.example/")->assertRedirect($tagged);

    // Another campaign's link number is not reachable with this token.
    $other = DB::table('mkt_campaigns')->insertGetId(['name' => 'x', 'blocks' => '[]', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('mkt_links')->insert(['campaign_id' => $other, 'n' => 500, 'url' => 'https://evil.example/']);
    $this->get("/email/c/{$send->token}/500")->assertRedirect($home);
});

it('attributes only paid orders placed within 7 days after a click', function () {
    /*
     * MUTATION: drop `->whereIn('status', Order::REAL_STATUSES)` from
     * CampaignReport::attribute() and the unpaid order counts — 3, not 1.
     */
    [$id, $sends] = mkSent(['buyer-a@example.com', 'buyer-b@example.com', 'buyer-c@example.com']);
    $clickAt = now()->subDays(10);
    DB::table('mkt_sends')->where('campaign_id', $id)->update(['first_click_at' => $clickAt]);

    $a = \App\Models\Customer::where('email', 'buyer-a@example.com')->first();
    $b = \App\Models\Customer::where('email', 'buyer-b@example.com')->first();
    $c = \App\Models\Customer::where('email', 'buyer-c@example.com')->first();

    F::order($a, [['Anua', 12000]], 'processing', 'Dubai', $clickAt->copy()->addDays(2)->format('Y-m-d H:i:s'));   // counts
    F::order($b, [['Anua', 50000]], 'pending', 'Dubai', $clickAt->copy()->addDay()->format('Y-m-d H:i:s'));       // unpaid
    F::order($c, [['Anua', 70000]], 'completed', 'Dubai', $clickAt->copy()->addDays(8)->format('Y-m-d H:i:s'));   // too late
    F::order($c, [['Anua', 80000]], 'completed', 'Dubai', $clickAt->copy()->subDay()->format('Y-m-d H:i:s'));     // before the click

    $r = app(CampaignReport::class)->campaign($id);

    expect($r['orders'])->toBe(1)->and($r['revenue_fils'])->toBe(12000)
        ->and($r['clickers'])->toBe(3);
});

it('puts nothing marketing-related under the unauthenticated /api/*', function () {
    $routes = collect(Route::getRoutes()->getRoutes());

    $leaks = $routes->filter(fn ($r) => str_starts_with($r->uri(), 'api/')
        && (str_contains($r->uri(), 'marketing') || str_contains($r->uri(), 'campaign') || str_contains($r->uri(), 'email')
            || str_contains((string) $r->getActionName(), 'Mkt') || str_contains((string) $r->getActionName(), 'MarketingEmail')))
        ->map(fn ($r) => $r->uri())->values()->all();

    expect($leaks)->toBe([])
        ->and(MarketingEmailsRoutes::admin())->not->toBe([]);

    foreach (MarketingEmailsRoutes::admin() as $route) {
        expect($route->uri())->toStartWith('admin-api/email-marketing/')
            ->and($route->gatherMiddleware())->toContain('auth:admin');
    }

    foreach (MarketingEmailsRoutes::public() as $route) {
        expect($route->uri())->toStartWith('email/');
    }
});

it('reserves the email first segment, and serves only the pictures that ship with the shop', function () {
    expect(PageController::RESERVED_SLUGS)->toContain('email');

    $this->get('/email/art/autumn-glow.jpg')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get('/email/art/nothing-here.jpg')->assertNotFound();
});
