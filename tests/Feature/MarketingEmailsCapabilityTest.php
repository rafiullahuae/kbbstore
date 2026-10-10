<?php

declare(strict_types=1);

use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Tests\Support\MarketingEmailsRoutes;
use Tests\Support\MarketingFixtures as F;

beforeEach(function () {
    // Every campaign carries a postal address (CampaignSender::hasPostalAddress()).
    app(App\Services\SettingsService::class)->set('mail_address_dubai', 'Office 1, Dubai');
});

/**
 * Marketing Emails — every admin endpoint has its own capability and fails
 * closed (CLAUDE.md rule 5; docs/EMAILS-PLAN.md §6; the owner's D11).
 *
 * The walk is over the routes the file REGISTERS, not a list typed here, so a
 * route added tomorrow without a rule is red by name.
 */

it('maps every Marketing Emails route to a marketing capability, none falling to the closed default', function () {
    /*
     * MUTATION: delete the ['GET', 'admin-api/email-marketing/**', …] rule and
     * every GET here resolves to marketing.email.manage (the wildcard below)
     * — still a rule, so the second half catches it: the reads must be view.
     */
    MarketingEmailsRoutes::wire(app());
    $routes = MarketingEmailsRoutes::admin();

    // 37 of Lane MK's, plus Lane ER's three (routes/marketing-report-admin.php):
    // a campaign's recipients, their CSV, and the Open tracking switch.
    expect(count($routes))->toBe(40);

    $allowed = ['marketing.email.view', 'marketing.email.manage', 'marketing.email.send', 'marketing.export'];

    foreach ($routes as $route) {
        $cap = AdminCapabilities::for($route);
        expect($cap)->toBeIn($allowed, $route->uri() . ' has no Marketing Emails capability');
    }

    $p = fn (string $m, string $u) => AdminCapabilities::forPath($m, $u);

    expect($p('GET', 'admin-api/email-marketing/overview'))->toBe('marketing.email.view')
        ->and($p('GET', 'admin-api/email-marketing/campaigns/{id}/review'))->toBe('marketing.email.view')
        ->and($p('POST', 'admin-api/email-marketing/groups/count'))->toBe('marketing.email.view')
        ->and($p('POST', 'admin-api/email-marketing/groups/people'))->toBe('marketing.email.view')
        ->and($p('PUT', 'admin-api/email-marketing/campaigns/{id}'))->toBe('marketing.email.manage')
        ->and($p('POST', 'admin-api/email-marketing/campaigns/{id}/test'))->toBe('marketing.email.manage')
        ->and($p('POST', 'admin-api/email-marketing/preview'))->toBe('marketing.email.manage')
        ->and($p('POST', 'admin-api/email-marketing/campaigns/{id}/send'))->toBe('marketing.email.send')
        ->and($p('POST', 'admin-api/email-marketing/campaigns/{id}/schedule'))->toBe('marketing.email.send')
        ->and($p('POST', 'admin-api/email-marketing/campaigns/{id}/step'))->toBe('marketing.email.send')
        ->and($p('POST', 'admin-api/email-marketing/campaigns/{id}/cancel'))->toBe('marketing.email.send')
        ->and($p('POST', 'admin-api/email-marketing/limits'))->toBe('marketing.email.send')
        ->and($p('GET', 'admin-api/email-marketing/groups/{id}/export'))->toBe('marketing.export')
        ->and($p('GET', 'admin-api/email-marketing/reports/{id}/recipients'))->toBe('marketing.email.view')
        ->and($p('GET', 'admin-api/email-marketing/reports/{id}/export'))->toBe('marketing.export')
        ->and($p('POST', 'admin-api/email-marketing/open-tracking'))->toBe('marketing.email.manage');
});

it('grants view, manage and send to owner and manager only (D11), never to support or editor', function () {
    expect(AdminCapabilities::CAPABILITIES['marketing.email.view'])->toBe(['owner', 'manager'])
        ->and(AdminCapabilities::CAPABILITIES['marketing.email.manage'])->toBe(['owner', 'manager'])
        ->and(AdminCapabilities::CAPABILITIES['marketing.email.send'])->toBe(['owner', 'manager']);
});

it('answers 403 to support and editor on every Marketing Emails route, and lets a manager send', function () {
    /*
     * MUTATION: add 'support' to marketing.email.view and the walk is red on
     * every GET for that role.
     */
    MarketingEmailsRoutes::wire(app());
    F::customer('cap@example.com');
    $gid = F::group('Cap group', []);
    $cid = F::campaign('best-sellers', $gid);

    foreach (['support', 'editor'] as $role) {
        $this->actingAs(F::admin($role), 'admin');

        foreach (MarketingEmailsRoutes::admin() as $route) {
            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $uri = '/' . str_replace('{id}', (string) $cid, $route->uri());

            $this->json($method, $uri, [])->assertForbidden();
        }
    }

    expect(DB::table('mkt_campaigns')->where('id', $cid)->value('status'))->toBe('draft');

    $this->actingAs(F::admin('manager'), 'admin');
    $this->getJson('/admin-api/email-marketing/overview')->assertOk()->assertJsonPath('can_send', true);
    $this->postJson("/admin-api/email-marketing/campaigns/{$cid}/send", ['confirm' => '0'])->assertStatus(422);
});
