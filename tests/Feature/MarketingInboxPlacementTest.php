<?php

declare(strict_types=1);

use App\Services\Mail\MailConfigurator;
use App\Services\Marketing\CampaignSender;
use App\Services\Marketing\EmailTheme;
use App\Services\Marketing\PersonalLetter;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MarketingEmailsRoutes;
use Tests\Support\MarketingFixtures as F;

/**
 * Inbox placement — Lane EP. The owner, 10 October: "The marketing emails are
 * going to promotion folder, we want to send to inbox to our existing
 * customers."
 *
 * What each case stops, as it looked (or would look) in a customer's Gmail:
 *   · every campaign declaring itself bulk mail with `Precedence: bulk`, a
 *     header no Google guideline asks for;
 *   · the one-click unsubscribe removed along with it — Google REQUIRES it for
 *     marketing mail, and its absence turns "Unsubscribe" into "Report spam";
 *   · a reply to a campaign landing nowhere, or the Reply-To printed twice;
 *   · a "personal letter" that still carries a product grid, prices, buttons,
 *     a pile of links, banners, the invisible pixel or click-redirect links;
 *   · an HTML letter whose plain-text part says something else, or nothing;
 *   · a letter that arrives from "K-Beauty Bliss" instead of from a person.
 */

beforeEach(function () {
    $s = app(SettingsService::class);
    $s->set('mail_address_dubai', 'Office 1, Dubai');
    $s->set('mail_transport', 'gmail');
    $s->set('mail_gmail_username', 'info@kbeautybliss.com');
    app(App\Services\Mail\MailCredentials::class)->put('gmail_password', 'abcdefghijklmnop');
    SettingsService::forgetMemo();
});

/** Sends $template to one customer through the array transport and returns the real message. */
function epRealSend(string $template, array $attrs = [], array $extraBlocks = []): Symfony\Component\Mime\Email
{
    app('mail.manager');
    config(['mail.mailers.' . MailConfigurator::MAILER => ['transport' => 'array']]);
    app('mail.manager')->purge(MailConfigurator::MAILER);

    F::product('Zero Pore Pad', 79, ['brand_id' => F::brand('Medicube')->id, 'total_sales' => 50, 'sale_price' => 5900]);
    F::order(F::customer('aisha@example.com', ['first_name' => 'Aisha']), [['Medicube', 10000]]);
    $id = F::campaign($template, F::group('All ' . uniqid(), []), $attrs);

    if ($extraBlocks !== []) {
        $blocks = json_decode((string) DB::table('mkt_campaigns')->where('id', $id)->value('blocks'), true);
        $footer = array_pop($blocks);
        DB::table('mkt_campaigns')->where('id', $id)->update(['blocks' => json_encode(array_merge($blocks, $extraBlocks, [$footer]))]);
    }

    [$ok, $why] = app(CampaignSender::class)->start($id);
    expect($ok)->toBeTrue($why);

    for ($i = 0; $i < 4; $i++) {
        app(CampaignSender::class)->step($id);
    }

    $messages = Mail::mailer(MailConfigurator::MAILER)->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);

    return $messages[0]->getOriginalMessage();
}

/** The words a reader sees, one space between them. */
function epWords(string $s): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

it('sends no Precedence: bulk header, and keeps one-click unsubscribe', function () {
    /*
     * The defect: CampaignMail::headers() added `Precedence: bulk` to every
     * campaign — the sender labelling its own mail as bulk to every filter.
     * MUTATION: put 'Precedence' => 'bulk' back and this is red. The second
     * half stops the obvious over-correction: Google's sender guidelines
     * require List-Unsubscribe + List-Unsubscribe-Post for marketing mail.
     */
    $h = epRealSend('best-sellers')->getHeaders();

    expect($h->has('Precedence'))->toBeFalse()
        ->and($h->get('List-Unsubscribe')->getBodyAsString())->toMatch('#^<https?://[^>]+/email/u/[0-9a-z]+-[0-9a-f]{32}>#')
        ->and($h->get('List-Unsubscribe-Post')->getBodyAsString())->toBe('List-Unsubscribe=One-Click')
        ->and($h->get('Feedback-ID')->getBodyAsString())->toMatch('/^\d+:mkt:kbb$/');
});

it('sets a Reply-To that reaches the support mailbox, once', function () {
    /*
     * The defect: with Store → Mail's Reply-To box blank (its shipped state) a
     * campaign carried no Reply-To, and a customer's reply — the strongest
     * Primary-tab signal there is — went wherever the From pointed. And the
     * opposite trap: Laravel ADDS a Mailable's Reply-To to the mailer's global
     * one, so setting both printed the address twice.
     * MUTATION: drop the `$envelope['reply']` argument in CampaignSender::
     * sendOne() and the first expectation is red; make PersonalLetter::
     * replyTo() ignore the global box and the second is.
     */
    app(SettingsService::class)->set('mail_support_email', 'info@kbeautybliss.com');
    SettingsService::forgetMemo();

    $reply = epRealSend('best-sellers')->getReplyTo();
    expect(array_map(fn ($a) => $a->getAddress(), $reply))->toBe(['info@kbeautybliss.com']);

    expect(PersonalLetter::replyTo())->toBe('info@kbeautybliss.com');

    app(SettingsService::class)->set('mail_reply_to', 'hello@kbeautybliss.com');
    SettingsService::forgetMemo();

    // The global box wins and the campaign adds nothing of its own.
    expect(PersonalLetter::replyTo())->toBeNull()
        ->and(PersonalLetter::replyLandsAt())->toBe('hello@kbeautybliss.com');
});

it('never names a no-reply address as where replies go', function () {
    // An install with no support box, no typed From and no Workspace: the only
    // address left is the no-reply@ the From falls back to. Nobody reads it.
    $s = app(SettingsService::class);
    $s->set('mail_transport', 'server');
    $s->set('mail_support_email', '');
    SettingsService::forgetMemo();
    config(['app.url' => 'https://kbeautybliss.com']);

    expect(PersonalLetter::replyTo())->toBeNull()->and(PersonalLetter::replyLandsAt())->toBe('');
});

it('sends the personal letter as a letter: from a person, Hi {name}, two plain links, no pixel, no grid, a matching text part', function () {
    /*
     * The defects this stops, each as it would read in Gmail: a "letter" that
     * still has the product grid and its AED prices, a button, a banner, a
     * dozen links through /email/c/, the invisible open pixel, and a From of
     * "K-Beauty Bliss" — the shape of every promotion in the tab.
     * Two extra pictures and a link-heavy paragraph are added to the ready
     * template so the limits are exercised, not just met by the copy.
     * MUTATION: set PersonalLetter::MAX_LINKS to 5, or drop the `$images >=`
     * check in renderLetter(), and the counts below are red.
     */
    $b = static fn (string $t, array $p = []) => App\Services\Marketing\Blocks::make($t, $p);
    $email = epRealSend('new-look-letter', ['theme' => 'letter', 'letter_signer' => 'Rafi'], [
        $b('hero_image', ['art' => 'new-look-c', 'href' => '/super-sale/']),
        $b('image', ['src' => 'https://cdn.example.com/a.jpg', 'alt' => 'A second picture']),
        $b('text', ['body' => 'More: [one](/a/) [two](/b/) [three](/c/)']),
        $b('button', ['label' => 'Shop now →', 'href' => '/shop/']),
        $b('product_grid', ['fill' => 'on_sale', 'count' => 4]),
    ]);

    $html = (string) $email->getHtmlBody();
    $text = (string) $email->getTextBody();
    $body = substr($html, 0, (int) strpos($html, 'border-top:1px solid'));   // the letter above its footer

    preg_match_all('/<a\b[^>]*href="([^"]+)"/i', $body, $links);

    expect($email->getFrom()[0]->getName())->toBe('Rafi from KBB')
        ->and($email->getFrom()[0]->getAddress())->toBe('info@kbeautybliss.com')
        ->and(substr_count(strtolower($html), '<img'))->toBe(1)
        ->and($links[1])->toHaveCount(PersonalLetter::MAX_LINKS)
        ->and($html)->not->toContain('/email/c/')
        ->and($html)->not->toContain('/email/o/')
        ->and($html)->not->toContain('Zero Pore Pad')
        ->and($html)->not->toContain('AED')
        ->and($html)->not->toContain('<style')
        ->and($html)->toContain('Hi Aisha,')
        ->and($html)->toContain('Rafi<br>KBB')
        ->and($html)->toContain('just reply to this email')
        ->and(substr_count($html, '/email/u/'))->toBe(1)
        ->and(strlen($html))->toBeLessThan(8 * 1024);

    // Straight to the shop, tagged so "Came to the website" still counts it.
    foreach ($links[1] as $href) {
        expect(html_entity_decode($href))->toContain('utm_source=email&utm_medium=marketing&utm_campaign=mkt-');
    }

    // The text part is the same letter, not a stub: every paragraph's words,
    // in order, and the footer's unsubscribe link.
    $paragraphs = [];
    preg_match_all('#<td style="[^"]*padding:0 18px 16px;">(.*?)</td>#s', $body, $m);

    foreach ($m[1] as $p) {
        $words = epWords(html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($words !== '') {
            $paragraphs[] = $words;
        }
    }

    $flat = epWords($text);
    $at = 0;

    expect($paragraphs)->not->toBeEmpty()->and($flat)->toStartWith('Hi Aisha,');

    foreach ($paragraphs as $p) {
        // A link's words stand in the text part as "words (address)"; compare words.
        $needle = epWords((string) preg_replace('/\s*\(https?:[^)]+\)/', '', $p));
        $hay = epWords((string) preg_replace('/\s*\(https?:[^)]+\)/', '', $flat));
        $pos = mb_strpos($hay, $needle, $at);
        expect($pos)->not->toBeFalse('text part is missing: ' . $needle);
        $at = (int) $pos;
    }

    expect($text)->toContain('/email/u/')->and($text)->not->toContain('AED');
});

it('carries the open pixel on a letter only when the campaign asks', function () {
    // MUTATION: make PersonalLetter::tracksOpens() return false always and
    // this is red — the owner's "Count opens" box would do nothing.
    $html = (string) epRealSend('new-look-letter', ['theme' => 'letter', 'letter_opens' => true])->getHtmlBody();

    expect($html)->toContain('/email/o/');
});

it('ships the letter template, and the admin stores the letter fields as one clean line', function () {
    MarketingEmailsRoutes::wire(app());
    $this->actingAs(F::admin('manager'), 'admin');

    $t = DB::table('mkt_templates')->where('key', 'new-look-letter')->first();
    expect($t)->not->toBeNull()->and($t->theme)->toBe('letter')->and((bool) $t->preset)->toBeTrue()
        ->and(EmailTheme::THEMES['letter'])->toBe('Personal letter style (best chance for the Primary tab)');

    $c = $this->postJson('/admin-api/email-marketing/campaigns', ['template_id' => $t->id])->assertOk()->json('campaign');
    expect($c['theme'])->toBe('letter')->and($c['letter_opens'])->toBeFalse()
        ->and($c['subject'])->toBe('{first_name|Hello}, we have a new look');

    $this->putJson('/admin-api/email-marketing/campaigns/' . $c['id'], ['letter_signer' => "Rafi\nX"])->assertStatus(422);
    $this->putJson('/admin-api/email-marketing/campaigns/' . $c['id'], ['letter_signer' => '  Rafi <b>  ', 'letter_opens' => true])->assertOk()
        ->assertJsonPath('campaign.letter_signer', 'Rafi b')
        ->assertJsonPath('campaign.letter_opens', true);

    // The subject's name merges per recipient; the review shows the From a customer will see.
    expect(App\Services\Marketing\Blocks::mergeName($c['subject'], 'Aisha'))->toBe('Aisha, we have a new look')
        ->and(App\Services\Marketing\Blocks::mergeName($c['subject'], ''))->toBe('Hello, we have a new look');

    $this->getJson('/admin-api/email-marketing/campaigns/' . $c['id'] . '/review')->assertOk()
        ->assertJsonPath('inbox.from_name', 'Rafi b from KBB')
        ->assertJsonPath('inbox.letter', true)
        ->assertJsonPath('inbox.reply_to', 'info@kbeautybliss.com');
});

it('shows the owner the DKIM, SPF and DMARC steps on the Deliverability tab, and the builder knows the link limit', function () {
    /*
     * The owner has no way to know what to type at his DNS host. The card
     * names the exact Host and Value fields. And the builder's note quotes the
     * letter's link limit from its own constant, which must be the server's.
     */
    $screen = file_get_contents(resource_path('views/admin/partials/email-health-screen.blade.php'));
    $builder = file_get_contents(resource_path('views/admin/partials/marketing-emails-screens.blade.php'));

    expect($screen)->toContain('Inbox placement: Primary tab or Promotions')
        ->toContain("copyRow('google._domainkey')")
        ->toContain('Authenticate email')
        ->toContain('Start authentication')
        ->toContain("copyRow('v=spf1 include:_spf.google.com ~all')")
        ->toContain("copyRow('_dmarc')")
        ->toContain("'v=DMARC1; p=none; rua=mailto:'")
        ->toContain('nothing a sender adds can force Primary')
        ->and(substr_count($screen, 'inboxView(wrap, d && d.domain'))->toBe(1)
        ->and($builder)->toContain('var LETTER_LINKS = ' . PersonalLetter::MAX_LINKS . ';')
        ->and($builder)->toContain('Send me a test');
});
