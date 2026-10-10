<?php

declare(strict_types=1);

use App\Http\Controllers\Store\MarketingOpenController;
use App\Mail\CampaignMail;
use App\Services\Analytics\Channels;
use App\Services\Analytics\Rollup;
use App\Services\Marketing\CampaignInsights;
use App\Services\Marketing\CampaignLinks;
use App\Services\Marketing\CampaignReport;
use App\Services\Marketing\CampaignSender;
use App\Services\Marketing\OpenPixel;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\StoreTime;
use App\Support\Url;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MarketingFixtures as F;
use Tests\Support\MarketingReportRoutes;

/**
 * Marketing Emails → Reports, the full report (Lane ER).
 *
 * The owner, 10 October: "for marketing emails i need the full report like how
 * many opened, how many clicked and came to the website etc etc." Each case
 * names the defect it is here for, and most carry a MUTATION note: change the
 * line it names back and the case goes red.
 */

beforeEach(function () {
    app(SettingsService::class)->set('mail_address_dubai', 'Office 1, Dubai');
    SettingsService::forgetMemo();
    MarketingReportRoutes::wire(app());
    DB::table('mkt_campaigns')->delete();
});

/** A sent campaign with $n bare send rows; returns [campaign id, list of send rows]. */
function erCampaign(int $n, string $name = 'Autumn glow', ?string $started = null): array
{
    $id = (int) DB::table('mkt_campaigns')->insertGetId([
        'name' => $name, 'blocks' => '[]', 'subject' => 'Hello', 'status' => 'sent',
        'started_at' => $started ?? now()->subHours(3), 'finished_at' => now()->subHours(2),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $rows = [];

    for ($i = 1; $i <= $n; $i++) {
        $rows[] = [
            'campaign_id' => $id, 'email' => "er{$id}-{$i}@example.com", 'token' => bin2hex(random_bytes(20)),
            'status' => 'sent', 'sent_at' => now()->subHours(3),
        ];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('mkt_sends')->insert($chunk);
    }

    return [$id, DB::table('mkt_sends')->where('campaign_id', $id)->orderBy('id')->get()->all()];
}

function erLinks(int $id): array
{
    $base = Url::external('/');
    DB::table('mkt_links')->insert([
        ['campaign_id' => $id, 'n' => 1, 'url' => rtrim($base, '/') . '/product/zero-pore-pad/', 'label' => 'Zero Pore Pad'],
        ['campaign_id' => $id, 'n' => 2, 'url' => rtrim($base, '/') . '/shop/', 'label' => 'Shop now'],
        ['campaign_id' => $id, 'n' => 3, 'url' => 'https://www.instagram.com/kbeautybliss/', 'label' => 'Instagram'],
    ]);

    return DB::table('mkt_links')->where('campaign_id', $id)->orderBy('n')->get()->keyBy('n')->all();
}

function erPixel(object $send): string
{
    return '/email/o/' . OpenPixel::token((int) $send->id, (string) $send->token) . '.gif';
}

/* ------------------------------------------------------------------ pixel */

it('records an open only for a correctly signed token; a tampered, forged or unknown one gets the same GIF and writes nothing', function () {
    /*
     * The defect: a pixel whose token is not checked lets anybody mark any
     * recipient "opened" by counting ids in the URL — and a pixel that answers
     * a bad token differently is an oracle for which sends exist.
     *
     * MUTATION: in OpenPixel::find(), return $row without the hash_equals check
     * and the tampered load writes an open (open_count 2, not 1).
     */
    [, $sends] = erCampaign(2);
    [$a, $b] = $sends;
    $good = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Microsoft Outlook 16.0'])->get(erPixel($a));

    $good->assertOk()->assertHeader('Content-Type', 'image/gif');
    expect($good->getContent())->toBe(base64_decode(OpenPixel::GIF))
        ->and($good->headers->get('Cache-Control'))->toContain('no-store')
        ->and($good->headers->getCookies())->toBe([]);

    $row = DB::table('mkt_sends')->where('id', $a->id)->first();
    expect((int) $row->open_count)->toBe(1)->and($row->first_open_at)->not->toBeNull()->and($row->open_class)->toBe('human');

    $sig = substr(erPixel($a), strpos(erPixel($a), '-') + 1, 32);
    $tampered = [
        '/email/o/' . base_convert((string) $a->id, 10, 36) . '-' . strrev($sig) . '.gif',            // signature changed
        '/email/o/' . base_convert((string) $b->id, 10, 36) . '-' . $sig . '.gif',                    // a's signature on b
        '/email/o/' . base_convert('999999', 10, 36) . '-' . $sig . '.gif',                           // no such send
        '/email/o/zz-nothex.gif',
        '/email/o/' . $a->token . '.gif',                                                             // the click token is not an open token
    ];

    foreach ($tampered as $url) {
        $r = $this->get($url);
        $r->assertOk()->assertHeader('Content-Type', 'image/gif');
        expect($r->getContent())->toBe($good->getContent());
    }

    expect((int) DB::table('mkt_sends')->where('id', $a->id)->value('open_count'))->toBe(1)
        ->and((int) DB::table('mkt_sends')->where('id', $b->id)->value('open_count'))->toBe(0);
});

it('records nothing, and puts no pixel in the message, when Open tracking is off', function () {
    /*
     * The switch is the owner's way back to MK's D10. MUTATION: drop the
     * enabled() check from OpenPixel::record() and the load counts anyway.
     */
    [, $sends] = erCampaign(1);
    app(SettingsService::class)->set(OpenPixel::SETTING, '0');
    SettingsService::forgetMemo();

    $this->get(erPixel($sends[0]))->assertOk()->assertHeader('Content-Type', 'image/gif');

    expect((int) DB::table('mkt_sends')->where('id', $sends[0]->id)->value('open_count'))->toBe(0)
        ->and(OpenPixel::inject('<html><body>x</body></html>', 1, str_repeat('a', 40)))->toBe('<html><body>x</body></html>');
});

it('ships ON by default and puts one pixel before </body> of every real send, never in a test send', function () {
    /*
     * The owner asked for opens, so the switch ships on (CLAUDE.md, 30 Sept).
     * MUTATION: delete the OpenPixel::inject() call in CampaignSender::sendOne()
     * and the sent message has no /email/o/.
     */
    Mail::fake();
    $c = F::customer('pixel@example.com');
    F::order($c, [['Anua', 5000]]);
    F::product('Toner', 60, ['total_sales' => 3]);
    $id = F::campaign('best-sellers', F::group('All', []));
    $s = app(CampaignSender::class);
    $s->start($id);

    for ($i = 0; $i < 10 && ! $s->step($id)['done']; $i++) {
    }

    $html = null;
    Mail::assertSent(CampaignMail::class, function ($m) use (&$html) {
        $html = $m->render();

        return true;
    });

    $send = DB::table('mkt_sends')->where('campaign_id', $id)->first();
    expect(OpenPixel::enabled())->toBeTrue()
        ->and(substr_count($html, '/email/o/'))->toBe(1)
        ->and($html)->toContain(e(OpenPixel::url((int) $send->id, (string) $send->token)))
        ->and(strpos($html, '/email/o/'))->toBeLessThan(strripos($html, '</body>'))
        // No address and no name in the pixel's URL.
        ->and(OpenPixel::url((int) $send->id, (string) $send->token))->not->toContain('pixel')
        ->and(OpenPixel::url((int) $send->id, (string) $send->token))->not->toContain('%40');
});

it('classifies Apple Mail Privacy Protection, Gmail\'s proxy and scanners honestly, and keeps the best evidence per send', function () {
    /*
     * The defect this whole class exists for: Apple's MPP loads every picture
     * of every message, so an open rate that counts it as a reader says most
     * of the list read the email. MUTATION: drop the `$ua === 'Mozilla/5.0'`
     * arm in uaClass() and the MPP fetch reads as a human.
     */
    $outlook = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Microsoft Outlook 16.0.17126';
    $appleMail = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko)';
    $c = fn (string $ua, string $ip, ?int $since) => OpenPixel::classify($ua, OpenPixel::uaClass($ua), OpenPixel::ipClass($ip), $since);

    expect($c('Mozilla/5.0', '104.28.40.1', 3600))->toBe('apple')                    // MPP's bare user agent
        ->and($c($appleMail, '17.58.100.4', 3600))->toBe('apple')                    // Apple's own address
        ->and($c($appleMail, '2a01:b740:a42::1', 3600))->toBe('apple')
        ->and($c($appleMail, '86.98.10.1', 4))->toBe('apple')                        // within seconds of delivery
        ->and($c($appleMail, '86.98.10.1', 3600))->toBe('human')                     // an hour later: a reader
        ->and($c('Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)', '66.249.84.10', 60))->toBe('proxy')
        ->and($c($outlook, '66.102.8.3', 60))->toBe('proxy')                        // Google's address
        ->and($c('Barracuda Sentinel (EE)', '52.1.1.1', 2))->toBe('scanner')
        ->and($c('python-requests/2.31', '52.1.1.1', 600))->toBe('scanner')
        ->and($c('', '52.1.1.1', 600))->toBe('scanner')
        ->and($c($outlook, '86.98.10.1', 4))->toBe('human')
        ->and(OpenPixel::ipClass('not-an-ip'))->toBe('other');

    // Best evidence wins: an MPP prefetch, then the person on Outlook.
    [, $sends] = erCampaign(2);
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get(erPixel($sends[0]));
    expect(DB::table('mkt_sends')->where('id', $sends[0]->id)->value('open_class'))->toBe('apple');
    $this->withHeaders(['User-Agent' => $outlook])->get(erPixel($sends[0]));
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get(erPixel($sends[0]));
    $row = DB::table('mkt_sends')->where('id', $sends[0]->id)->first();
    expect($row->open_class)->toBe('human')->and((int) $row->open_count)->toBe(3)->and($row->open_ua)->toBe('mail');

    // A scanner alone is not an open.
    $this->withHeaders(['User-Agent' => 'Mimecast scanner'])->get(erPixel($sends[1]));
    $r = app(CampaignInsights::class)->campaign((int) $sends[0]->campaign_id);
    expect($r['opens']['unique'])->toBe(1)->and($r['opens']['scanner'])->toBe(1)->and($r['opens']['apple'])->toBe(0);
});

/* ------------------------------------------------------------------- UTMs */

it('adds utm_source, utm_medium and utm_campaign once, to the shop\'s own links only, and never doubles them', function () {
    /*
     * The defect: a link tagged twice (utm_source=email&utm_source=email) or a
     * hand-tagged link overwritten. MUTATION: drop the `! isset($have[$k])`
     * test in CampaignLinks::tag() and the second tag() doubles every key.
     */
    $shop = rtrim(Url::external('/'), '/');
    $t = CampaignLinks::tag($shop . '/shop/', 7, 'Autumn glow — 20% off!');

    expect($t)->toBe($shop . '/shop/?utm_source=email&utm_medium=marketing&utm_campaign=mkt-7-autumn-glow-20-off')
        ->and(CampaignLinks::tag($t, 7, 'Autumn glow — 20% off!'))->toBe($t)
        ->and(CampaignLinks::tag($shop . '/p/?a=1#top', 7, 'X'))->toBe($shop . '/p/?a=1&utm_source=email&utm_medium=marketing&utm_campaign=mkt-7-x#top')
        ->and(CampaignLinks::tag($shop . '/p/?utm_campaign=mine', 7, 'X'))->toBe($shop . '/p/?utm_campaign=mine&utm_source=email&utm_medium=marketing')
        ->and(CampaignLinks::tag('https://www.instagram.com/kbeautybliss/', 7, 'X'))->toBe('https://www.instagram.com/kbeautybliss/')
        ->and(CampaignLinks::tag('mailto:hi@example.com', 7, 'X'))->toBe('mailto:hi@example.com')
        ->and(CampaignLinks::campaignId('mkt-7-autumn-glow'))->toBe(7)
        ->and(CampaignLinks::campaignId('mkt-71'))->toBe(71)
        ->and(CampaignLinks::campaignId('summer-sale'))->toBeNull();
});

it('lands a click on the tagged shop page, records its device, and leaves another site\'s link untouched', function () {
    /*
     * MUTATION: redirect to $link->url again in MarketingEmailController::click()
     * and the landing page carries no utm_campaign — the visit is "Referral".
     */
    [$id, $sends] = erCampaign(1);
    $links = erLinks($id);

    $r = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'])
        ->get("/email/c/{$sends[0]->token}/1");
    $r->assertRedirect(CampaignLinks::tag($links[1]->url, $id, 'Autumn glow'));
    expect($r->headers->get('Location'))->toContain('utm_campaign=mkt-' . $id . '-autumn-glow')
        ->and($r->headers->get('Location'))->not->toContain('example.com')
        ->and(DB::table('mkt_clicks')->value('dev'))->toBe('mobile');

    $this->get("/email/c/{$sends[0]->token}/3")->assertRedirect('https://www.instagram.com/kbeautybliss/');

    // And site analytics files the landing under Email, not Referral.
    expect(Channels::classify('', 'email', 'marketing', ''))->toBe('email');
});

/* -------------------------------------------------------- the numbers */

it('reports delivery, opens, clicks, products and orders exactly on seeded data', function () {
    /*
     * Ten sends with every case in them. MUTATION: count Apple auto-opens in
     * `opened` without the click exception, or drop the `OR b.send_id IS NOT
     * NULL` from totals(), and a figure below moves.
     */
    [$id, $s] = erCampaign(10);
    $links = erLinks($id);
    $at = now()->subHours(2);
    $set = fn (int $i, array $v) => DB::table('mkt_sends')->where('id', $s[$i]->id)->update($v);

    $set(0, ['open_class' => 'human', 'first_open_at' => $at, 'open_count' => 3, 'first_click_at' => $at]);
    $set(1, ['open_class' => 'apple', 'first_open_at' => now()->subHours(3), 'open_count' => 1]);
    $set(2, ['open_class' => 'apple', 'first_open_at' => $at, 'open_count' => 2, 'first_click_at' => $at]);
    $set(3, ['open_class' => 'proxy', 'first_open_at' => $at, 'open_count' => 1]);
    $set(4, ['open_class' => 'scanner', 'first_open_at' => $at, 'open_count' => 1]);
    $set(5, ['unsubscribed_at' => $at]);
    // 6: a bounce report read back after it was accepted (Lane EB).
    DB::table('email_bounces')->insert(['email' => $s[6]->email, 'kind' => 'hard', 'campaign_id' => $id, 'send_id' => $s[6]->id, 'source' => 'imap', 'created_at' => now()]);
    $set(7, ['status' => 'failed', 'error' => 'SOFT 452 4.2.2 mailbox full']);
    DB::table('email_bounces')->insert(['email' => $s[7]->email, 'kind' => 'soft', 'campaign_id' => $id, 'send_id' => $s[7]->id, 'source' => 'smtp', 'created_at' => now()]);
    $set(8, ['status' => 'failed', 'error' => 'HARD 550 5.1.1 user unknown']);
    $set(9, ['status' => 'skipped']);

    DB::table('mkt_clicks')->insert([
        ['send_id' => $s[0]->id, 'link_id' => $links[1]->id, 'clicked_at' => $at, 'dev' => 'mobile'],
        ['send_id' => $s[0]->id, 'link_id' => $links[2]->id, 'clicked_at' => $at, 'dev' => 'mobile'],
        ['send_id' => $s[2]->id, 'link_id' => $links[1]->id, 'clicked_at' => $at, 'dev' => 'desktop'],
    ]);
    F::product('Zero Pore Pad', 79, ['slug' => 'zero-pore-pad']);
    $buyer = F::customer($s[0]->email);
    F::order($buyer, [['Medicube', 15000]], 'processing', 'Dubai', now()->subHour()->format('Y-m-d H:i:s'));

    $r = app(CampaignInsights::class)->campaign($id);

    expect($r['delivery'])->toMatchArray(['sent' => 9, 'bounced' => 3, 'hard' => 2, 'soft' => 1, 'delivered' => 6, 'skipped' => 1, 'unsubscribed' => 1])
        ->and($r['opens'])->toMatchArray(['unique' => 4, 'apple' => 1, 'proxy' => 1, 'scanner' => 1, 'loads' => 8, 'rate' => 66.7])
        ->and($r['clicks'])->toMatchArray(['unique' => 2, 'total' => 3, 'rate' => 33.3, 'cto' => 50.0])
        ->and($r['clicks']['devices'])->toMatchArray(['mobile' => 2, 'desktop' => 1])
        ->and($r['clicks']['products'])->toBe([['name' => 'Zero Pore Pad', 'slug' => 'zero-pore-pad', 'clicks' => 2, 'people' => 2]])
        ->and($r['clicks']['links'][0])->toMatchArray(['n' => 1, 'clicks' => 2, 'people' => 2])
        ->and($r['orders'])->toMatchArray(['orders' => 1, 'revenue_fils' => 15000, 'window_days' => CampaignReport::WINDOW_DAYS]);

    // The timeline puts the 2-hours-ago opens and clicks in hour 1 (the campaign
    // started 3 hours ago). It read hour 0 on SQLite while the bucket was
    // julianday() floats: exactly +1:00 came out as 0.9999… and CAST cut it down.
    expect(array_sum(array_column($r['timeline']['hours'], 'opens')))->toBe(4)
        ->and($r['timeline']['hours'][1])->toMatchArray(['opens' => 3, 'clicks' => 3])
        ->and($r['timeline']['hours'][0])->toMatchArray(['opens' => 1, 'apple' => 1]);

    // The recipient list agrees, filter by filter.
    $ins = app(CampaignInsights::class);
    expect($ins->recipients($id, '', 'opened')['total'])->toBe(4)
        ->and($ins->recipients($id, '', 'clicked')['total'])->toBe(2)
        ->and($ins->recipients($id, '', 'bounced')['total'])->toBe(3)
        ->and($ins->recipients($id, '', 'ordered')['total'])->toBe(1)
        ->and($ins->recipients($id, '', 'unsubscribed')['total'])->toBe(1)
        ->and($ins->recipients($id, $s[3]->email)['rows'][0])->toMatchArray(['opened' => true, 'open_kind' => 'proxy'])
        ->and($ins->recipients($id, '', 'ordered')['rows'][0])->toMatchArray(['email' => $s[0]->email, 'orders' => 1, 'clicks' => 2])
        ->and($ins->recipients($id, '%')['total'])->toBe(0);   // a LIKE wildcard is a character, not "everything"

    // And the list's row says the same as the report.
    $o = $ins->overview([$id])[$id];
    expect($o)->toMatchArray(['delivered' => 6, 'opened' => 4, 'open_rate' => 66.7, 'clickers' => 2, 'click_rate' => 33.3, 'orders' => 1, 'revenue_fils' => 15000]);
});

it('ties a visit from the campaign\'s UTM link back to it: visits, pages, add to cart, checkout and the order', function () {
    /*
     * "came to the website". MUTATION: remove the cmp_cart / cmp_chk block
     * from Rollup::rollDay() and carts and checkouts read 0.
     */
    [$id] = erCampaign(1);
    $cmp = CampaignLinks::utmCampaign($id, 'Autumn glow');
    $m = intdiv(time(), 60);
    $hit = fn (array $v) => DB::table('an_hits')->insert($v + ['m' => $m, 'e' => 0, 'k' => 0, 'path' => '/', 'title' => '', 'ref' => '', 'ch' => 'direct',
        'src' => '', 'med' => '', 'cmp' => '', 'dev' => 'mobile', 'br' => '', 'os' => '', 'cc' => '', 'lang' => '']);

    $hit(['v' => 'visitor-one-0001', 's' => 'session-one-0001', 'e' => 1, 'path' => '/product/zero-pore-pad/', 'ch' => 'email', 'src' => 'email', 'med' => 'marketing', 'cmp' => $cmp]);
    $hit(['v' => 'visitor-one-0001', 's' => 'session-one-0001', 'path' => '/shop/']);
    $hit(['v' => 'visitor-one-0001', 's' => 'session-day-0001', 'k' => 1, 'path' => '']);
    $hit(['v' => 'visitor-one-0001', 's' => 'session-one-0001', 'k' => 2, 'path' => '/checkout/']);
    // Somebody else, from another campaign: not ours.
    $hit(['v' => 'visitor-two-0002', 's' => 'session-two-0002', 'e' => 1, 'ch' => 'email', 'src' => 'email', 'med' => 'marketing', 'cmp' => 'mkt-' . ($id + 1)]);
    $hit(['v' => 'visitor-two-0002', 's' => 'session-twd-0002', 'k' => 1, 'path' => '']);

    Rollup::rollDay(StoreTime::now()->format('Y-m-d'));

    $c = F::customer('tagged@example.com');
    $o = F::order($c, [['Anua', 9900]]);
    DB::table('orders')->where('id', $o->id)->update(['src_channel' => 'email', 'src_campaign' => $cmp]);

    $site = app(CampaignInsights::class)->campaign($id)['site'];

    expect($site)->toMatchArray(['visits' => 1, 'views' => 3, 'visitors' => 1, 'carts' => 1, 'checkouts' => 1, 'orders' => 1, 'revenue_fils' => 9900])
        ->and(app(CampaignInsights::class)->overview([$id])[$id]['visits'])->toBe(1);
});

/* -------------------------------------------------------- speed */

it('costs the same number of queries for 3 recipients as for 40: report, list and recipients page', function () {
    /*
     * The way BrandPageOwnerAsksTest proves it. MUTATION: look up each row's
     * clicks inside describe()'s map() instead of the grouped query and the
     * 40-row page costs 37 more.
     */
    $seed = function (int $n): int {
        [$id, $s] = erCampaign($n, 'Flat ' . $n);
        $links = erLinks($id);

        foreach ($s as $i => $row) {
            DB::table('mkt_sends')->where('id', $row->id)->update(['open_class' => $i % 3 ? 'human' : 'apple', 'first_open_at' => now()->subHour(), 'open_count' => 1,
                'first_click_at' => $i % 2 ? now()->subHour() : null]);

            if ($i % 2) {
                DB::table('mkt_clicks')->insert(['send_id' => $row->id, 'link_id' => $links[1]->id, 'clicked_at' => now()->subHour(), 'dev' => 'mobile']);
                F::order(F::customer($row->email), [['Anua', 1000]]);
            }
        }

        return $id;
    };

    $small = $seed(3);
    $big = $seed(40);
    $this->actingAs(F::admin('owner'), 'admin');

    $count = function (string $url): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count("/admin-api/email-marketing/reports/{$small}");   // warm
    $a = $count("/admin-api/email-marketing/reports/{$small}");
    $b = $count("/admin-api/email-marketing/reports/{$big}");
    $p1 = $count("/admin-api/email-marketing/reports/{$small}/recipients");
    $p2 = $count("/admin-api/email-marketing/reports/{$big}/recipients");

    expect($b)->toBe($a)->and($p2)->toBe($p1)->and($a)->toBeLessThan(30);

    $count('/admin-api/email-marketing/reports');
    $l1 = $count('/admin-api/email-marketing/reports');
    $seed(25);
    // Seeding placed orders; their after-response push look-up rides the next request. Not ours: warm once.
    $count('/admin-api/email-marketing/reports');
    $l2 = $count('/admin-api/email-marketing/reports');
    expect($l2)->toBe($l1);
});

/* -------------------------------------------------------- security */

it('gates the recipients CSV behind marketing.export, and quotes a cell that starts like a formula', function () {
    /*
     * A CSV of addresses is the same act as Lane MK's group export. MUTATION:
     * delete the reports/*\/export line from AdminCapabilities and the route
     * falls to the GET wildcard (marketing.email.view): the manager below,
     * who may read reports but not export, downloads every address.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/email-marketing/reports/{id}/export'))->toBe('marketing.export')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/email-marketing/reports/{id}/recipients'))->toBe('marketing.email.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/email-marketing/open-tracking'))->toBe('marketing.email.manage');

    [$id, $s] = erCampaign(2);
    DB::table('mkt_sends')->where('id', $s[0]->id)->update(['email' => '=cmd@example.com']);

    $noExport = F::admin('manager');
    $noExport->forceFill(['revokes' => json_encode(['marketing.export'])])->save();
    \App\Support\AdminRoles::flush();
    $this->actingAs($noExport, 'admin');
    $this->getJson("/admin-api/email-marketing/reports/{$id}/recipients")->assertOk();
    $this->get("/admin-api/email-marketing/reports/{$id}/export")->assertForbidden();

    $this->actingAs(F::admin('support'), 'admin');
    $this->getJson("/admin-api/email-marketing/reports/{$id}/recipients")->assertForbidden();

    $this->actingAs(F::admin('owner'), 'admin');
    $csv = $this->get("/admin-api/email-marketing/reports/{$id}/export")->assertOk()->streamedContent();
    expect($csv)->toContain("'=cmd@example.com")->and($csv)->toContain($s[1]->email)
        ->and(substr_count($csv, "\n"))->toBe(3);
});

it('never leaks from the public pixel and click endpoints, and the admin ones need a session', function () {
    /*
     * /email/* is opened by people who are not signed in. Nothing it answers
     * may carry an address, a name or a row; the report endpoints answer only
     * a signed-in admin. MUTATION: give MarketingOpenController a JSON body
     * on a bad token and the bodies differ.
     */
    [$id, $s] = erCampaign(1);
    erLinks($id);

    $pixel = $this->get(erPixel($s[0]));
    expect($pixel->getContent())->not->toContain('@')->and(strlen($pixel->getContent()))->toBe(42)
        ->and($pixel->headers->get('Set-Cookie'))->toBeNull()
        ->and(erPixel($s[0]))->not->toContain((string) $s[0]->email)
        ->and(erPixel($s[0]))->not->toContain('autumn');

    $click = $this->get("/email/c/{$s[0]->token}/1");
    expect($click->headers->get('Location'))->not->toContain('@')->and($click->headers->get('Location'))->not->toContain($s[0]->token);

    // A database error inside the pixel still answers the GIF, never a 500.
    DB::statement('ALTER TABLE mkt_sends RENAME TO mkt_sends_gone');
    try {
        $broken = $this->get(erPixel($s[0]));
    } finally {
        DB::statement('ALTER TABLE mkt_sends_gone RENAME TO mkt_sends');
    }
    $broken->assertOk()->assertHeader('Content-Type', 'image/gif');
    expect($broken->getContent())->toBe(MarketingOpenController::gif()->getContent());

    $this->getJson("/admin-api/email-marketing/reports/{$id}/recipients")->assertUnauthorized();
    $guest = $this->get("/admin-api/email-marketing/reports/{$id}/export");
    expect($guest->status())->toBeIn([302, 401, 404])->and((string) $guest->getContent())->not->toContain('@example.com');
    $this->postJson('/admin-api/email-marketing/open-tracking', ['on' => false])->assertUnauthorized();
    expect(OpenPixel::enabled())->toBeTrue();

    // The switch itself, as the owner.
    $this->actingAs(F::admin('owner'), 'admin');
    $this->postJson('/admin-api/email-marketing/open-tracking', ['on' => false])->assertOk()->assertJson(['open_tracking' => false]);
    $this->postJson('/admin-api/email-marketing/open-tracking', ['on' => 'maybe'])->assertStatus(422);
    $this->getJson('/admin-api/email-marketing/reports')->assertOk()->assertJson(['open_tracking' => false]);
});

/* -------------------------------------------------------- the screen */

it('draws the report from its own partial through one hook each in Lane MK\'s screen, measuring nothing and escaping every value', function () {
    /*
     * Zero hooks is the "built, never shown" shape; two draws the list twice.
     * And the console forbids layout-measuring APIs (CLAUDE.md, rule 4).
     * MUTATION: print `c.name` without h.esc() in list() and the escape count
     * check below goes red.
     */
    $mk = file_get_contents(resource_path('views/admin/partials/marketing-emails-screens.blade.php'));
    $er = file_get_contents(resource_path('views/admin/partials/marketing-email-report.blade.php'));

    expect(substr_count($mk, 'window.kbbMktReport.list(S,'))->toBe(1)
        ->and(substr_count($mk, 'window.kbbMktReport.panel(r,'))->toBe(1)
        ->and(substr_count($mk, 'S.reportsOpenTracking = rd.open_tracking'))->toBe(1)
        ->and(substr_count($er, 'window.kbbMktReport = {'))->toBe(1);

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'scrollWidth', 'getComputedStyle', 'ResizeObserver', 'IntersectionObserver', 'setInterval', 'setTimeout', '<script src', 'import('] as $api) {
        expect($er)->not->toContain($api);
    }

    foreach (['c.name', 'c.subject', 'p.email', 'l.label', 'l.url', 'p.name', 'f.email', 'f.error', 'x.utm', 'w.revenue', 'i.revenue'] as $field) {
        expect($er)->toContain('esc(' . $field)->and(preg_match('/[^(]' . preg_quote($field, '/') . '\b(?!\s*[)=!<>?:|&,.])/', str_replace('esc(' . $field, '', $er)))->toBe(0, $field . ' printed without esc()');
    }
});
