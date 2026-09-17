<?php

declare(strict_types=1);

/**
 * Newsletter double opt-in, end to end — Lane EE.
 *
 * The property every test here exists to defend is one sentence: NOTHING IS ON
 * THE MARKETING LIST UNTIL A LINK SENT TO THAT ADDRESS HAS BEEN CLICKED.
 *
 * Mail::fake() throughout. Nothing here sends a real message, and the
 * assertions are on the mailable, its recipient, its subject and its RENDERED
 * BODY — not on a spy that records a call. A stub that cannot fail would pass
 * against a confirmation email with a broken link in it, which is the one
 * defect that would make this whole feature useless while looking finished.
 */

use App\Mail\NewsletterConfirmation;
use App\Services\NewsletterList;
use App\Support\CustomerLinkSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\NewsletterRoutes;

beforeEach(function () {
    NewsletterRoutes::wire();
    Mail::fake();
});

/**
 * The confirmation URL as it was actually rendered into the email body.
 *
 * `$to` selects which message when more than one has been sent. It is not
 * optional politeness: without it this helper returns the FIRST mail in the
 * fake's collection, and a test that signs two addresses up and then confirms
 * "the" link silently confirms the wrong one. That is precisely how the export
 * test first passed against a CSV containing the address it was asserting was
 * absent.
 */
function confirmUrlFromMail(?string $to = null): string
{
    $mailed = Mail::sent(NewsletterConfirmation::class);

    expect($mailed)->not->toBeEmpty('no confirmation email was sent at all');

    $chosen = $to === null
        ? $mailed->first()
        : $mailed->first(fn ($mail) => $mail->hasTo($to));

    expect($chosen)->not->toBeNull('no confirmation email was sent to ' . $to);

    $body = (string) $chosen->render();

    // The link as the shopper's mail client sees it, pulled out of the rendered
    // HTML rather than rebuilt from the signer. Rebuilding it would test that
    // the signer agrees with itself and would pass against a view that printed
    // the wrong variable.
    expect(preg_match('#https?://[^"\s]*?/newsletter/confirm/\d+/\?expires=\d+&(?:amp;)?signature=[0-9a-f]{64}#', $body, $m))
        ->toBe(1, 'the rendered email carried no usable confirmation link');

    return html_entity_decode($m[0]);
}

/** As above, and `$to` matters here for the same reason. */
function unsubscribeUrlFromMail(?string $to = null): string
{
    $mailed = Mail::sent(NewsletterConfirmation::class);

    $chosen = $to === null
        ? $mailed->first()
        : $mailed->first(fn ($mail) => $mail->hasTo($to));

    expect($chosen)->not->toBeNull('no confirmation email was sent to ' . $to);

    $body = (string) $chosen->render();

    expect(preg_match('#https?://[^"\s]*?/newsletter/unsubscribe/\d+/\?expires=\d+&(?:amp;)?signature=[0-9a-f]{64}#', $body, $m))
        ->toBe(1, 'the rendered email carried no usable unsubscribe link');

    return html_entity_decode($m[0]);
}

/** Turn an absolute link from an email into the path+query a test can GET. */
function pathOf(string $url): string
{
    $parts = parse_url($url);

    return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

/** Post the hidden fields the landing page renders, as the button would. */
function pressButton(string $action, string $url): Illuminate\Testing\TestResponse
{
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);

    preg_match('#/(\d+)/?$#', (string) ($parts['path'] ?? ''), $m);

    return test()->post('/newsletter/' . $action, [
        'id' => $m[1] ?? '0',
        'expires' => $query['expires'] ?? '',
        'signature' => $query['signature'] ?? '',
    ]);
}

/* ------------------------------------------------------------------ the trip */

it('does not put a new address on the list until the link is clicked', function () {
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])
        ->assertOk();

    $row = DB::table('subscribers')->where('email', 'shopper@example.com')->first();

    expect($row)->not->toBeNull();
    expect($row->status)->toBe(NewsletterList::PENDING);
    expect($row->confirmed_at)->toBeNull();

    // The whole property, asserted through the one query anything may mail from.
    expect(NewsletterList::marketable()->count())->toBe(0);

    // And then the round trip completes.
    $url = confirmUrlFromMail();

    $this->get(pathOf($url))->assertOk();

    // The GET alone must change NOTHING. A mail scanner fetching the link is
    // not the recipient agreeing to anything.
    expect(DB::table('subscribers')->where('email', 'shopper@example.com')->value('status'))
        ->toBe(NewsletterList::PENDING);

    pressButton('confirm', $url)->assertOk();

    $row = DB::table('subscribers')->where('email', 'shopper@example.com')->first();

    expect($row->status)->toBe(NewsletterList::SUBSCRIBED);
    expect($row->confirmed_at)->not->toBeNull();
    expect(NewsletterList::marketable()->count())->toBe(1);
});

it('sends the confirmation to the address that signed up, and only that one', function () {
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();

    Mail::assertSent(NewsletterConfirmation::class, function ($mail) {
        return $mail->hasTo('shopper@example.com');
    });

    Mail::assertSent(NewsletterConfirmation::class, 1);
});

it('subjects the confirmation plainly and puts no token in the subject', function () {
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();

    $mail = Mail::sent(NewsletterConfirmation::class)->first();
    $subject = (string) $mail->envelope()->subject;

    expect($subject)->toContain('confirm');

    // A subject is quoted in notification previews, in shared screenshots and
    // in every mail server's log along the way. A signature in one would be a
    // token disclosed to every hop.
    expect($subject)->not->toMatch('#[0-9a-f]{64}#');
    expect($subject)->not->toContain('shopper@example.com');
});

it('carries a working unsubscribe link in the confirmation itself', function () {
    // The case this is for: somebody else typed your address in. "Do nothing"
    // works, but the recipient must also be able to settle it.
    $this->postJson('/api/subscribe', ['email' => 'victim@example.com'])->assertOk();

    $url = unsubscribeUrlFromMail();

    $this->get(pathOf($url))->assertOk();

    // Again: the GET changes nothing.
    expect(DB::table('subscribers')->where('email', 'victim@example.com')->value('status'))
        ->toBe(NewsletterList::PENDING);

    pressButton('unsubscribe', $url)->assertOk();

    expect(DB::table('subscribers')->where('email', 'victim@example.com')->value('status'))
        ->toBe(NewsletterList::UNSUBSCRIBED);
    expect(NewsletterList::marketable()->count())->toBe(0);
});

it('puts a List-Unsubscribe header on the confirmation', function () {
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();

    $headers = Mail::sent(NewsletterConfirmation::class)->first()->headers()->text;

    expect($headers)->toHaveKey('List-Unsubscribe');
    expect($headers['List-Unsubscribe'])->toContain('/newsletter/unsubscribe/');

    // No List-Unsubscribe-Post. Promising RFC 8058 one-click and then rejecting
    // the provider's CSRF-less POST shows the recipient a button that does
    // nothing — see the mailable's header.
    expect($headers)->not->toHaveKey('List-Unsubscribe-Post');
});

/* ------------------------------------------------------- unsubscribe is real */

it('takes a confirmed subscriber off the list', function () {
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();

    $confirm = confirmUrlFromMail();
    pressButton('confirm', $confirm)->assertOk();
    expect(NewsletterList::marketable()->count())->toBe(1);

    pressButton('unsubscribe', unsubscribeUrlFromMail())->assertOk();

    $row = DB::table('subscribers')->where('email', 'shopper@example.com')->first();

    expect($row->status)->toBe(NewsletterList::UNSUBSCRIBED);
    // Cleared, not left behind: marketable() tests both columns, and a stale
    // confirmation date on an unsubscribed row is how a later change to that
    // query silently starts mailing them again.
    expect($row->confirmed_at)->toBeNull();
    expect(NewsletterList::marketable()->count())->toBe(0);

    // The row survives, so a later import cannot quietly re-add them.
    expect(DB::table('subscribers')->where('email', 'shopper@example.com')->exists())->toBeTrue();
});

it('makes somebody who unsubscribed prove the mailbox again before returning', function () {
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();
    pressButton('confirm', confirmUrlFromMail())->assertOk();
    pressButton('unsubscribe', unsubscribeUrlFromMail())->assertOk();

    // They come back.
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();

    // Pending, NOT straight back onto the list.
    expect(DB::table('subscribers')->where('email', 'shopper@example.com')->value('status'))
        ->toBe(NewsletterList::PENDING);
    expect(NewsletterList::marketable()->count())->toBe(0);
});

/* -------------------------------------------------------------- forged links */

it('refuses a forged signature, and says the same thing as an expired one', function () {
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();

    $id = (int) DB::table('subscribers')->where('email', 'shopper@example.com')->value('id');

    $forged = pressButton('confirm', '/newsletter/confirm/' . $id . '/?expires=' . (time() + 600) . '&signature=' . str_repeat('a', 64));

    $expired = test()->post('/newsletter/confirm', [
        'id' => $id,
        'expires' => time() - 10,
        'signature' => CustomerLinkSigner::sign(
            NewsletterList::PURPOSE_CONFIRM,
            ['id' => (string) $id, 'hash' => hash('sha256', 'shopper@example.com')],
            time() - 10,
        ),
    ]);

    // Neither worked...
    expect(DB::table('subscribers')->where('id', $id)->value('status'))->toBe(NewsletterList::PENDING);

    // ...and neither is distinguishable from the other.
    $forged->assertOk();
    $expired->assertOk();
    expect($forged->getContent())->toBe($expired->getContent());
});

it('answers an id that was never issued exactly as it answers a forged one', function () {
    // The oracle CLAUDE.md records for Api\QuizController::expertRequest, kept.
    // Subscriber ids are sequential small integers; a response that differed
    // here would let anyone count this shop's list.
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();

    $real = (int) DB::table('subscribers')->where('email', 'shopper@example.com')->value('id');

    $forgedOnReal = pressButton('confirm', '/newsletter/confirm/' . $real . '/?expires=' . (time() + 600) . '&signature=' . str_repeat('b', 64));
    $neverIssued = pressButton('confirm', '/newsletter/confirm/' . ($real + 9999) . '/?expires=' . (time() + 600) . '&signature=' . str_repeat('b', 64));

    expect($forgedOnReal->getContent())->toBe($neverIssued->getContent());
});

it('will not let a confirm link be replayed as an unsubscribe', function () {
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();
    $confirm = confirmUrlFromMail();
    pressButton('confirm', $confirm)->assertOk();

    // Separate purposes in the MAC are what stop this. Without them one link
    // would do both jobs and an unsubscribe link found in a forwarded email
    // would re-subscribe the person who forwarded it.
    pressButton('unsubscribe', $confirm)->assertOk();

    expect(DB::table('subscribers')->where('email', 'shopper@example.com')->value('status'))
        ->toBe(NewsletterList::SUBSCRIBED);
});

it('will not let a link be replayed after the address changes hands', function () {
    $this->postJson('/api/subscribe', ['email' => 'first@example.com'])->assertOk();
    $url = confirmUrlFromMail();

    $id = (int) DB::table('subscribers')->where('email', 'first@example.com')->value('id');

    // The row is re-pointed at a different address — the digest in the claims
    // is what makes the old link stop working.
    DB::table('subscribers')->where('id', $id)->update(['email' => 'second@example.com']);

    pressButton('confirm', $url)->assertOk();

    expect(DB::table('subscribers')->where('id', $id)->value('status'))->toBe(NewsletterList::PENDING);
});

/* ------------------------------------------------------------ the same answer */

it('answers a new address, a pending one and a confirmed one identically', function () {
    // routes/web.php records this endpoint as a membership oracle. Double
    // opt-in makes it fixable rather than merely throttleable, because all
    // three outcomes now have one truthful answer.
    $new = $this->postJson('/api/subscribe', ['email' => 'brand-new@example.com']);

    $this->postJson('/api/subscribe', ['email' => 'pending@example.com']);
    $pending = $this->postJson('/api/subscribe', ['email' => 'pending@example.com']);

    $this->postJson('/api/subscribe', ['email' => 'done@example.com']);
    pressButton('confirm', confirmUrlFromMail());
    $confirmed = $this->postJson('/api/subscribe', ['email' => 'done@example.com']);

    expect($new->json('message'))->toBe($pending->json('message'));
    expect($new->json('message'))->toBe($confirmed->json('message'));
    expect($new->json('ok'))->toBe($confirmed->json('ok'));
});

it('sends nothing at all to an address already confirmed', function () {
    $this->postJson('/api/subscribe', ['email' => 'done@example.com'])->assertOk();
    pressButton('confirm', confirmUrlFromMail())->assertOk();

    Mail::fake();

    $this->postJson('/api/subscribe', ['email' => 'done@example.com'])->assertOk();

    // Re-mailing a confirmed subscriber every time a stranger types their
    // address into a public form is an inbox-bombing vector with this shop's
    // domain in the From line.
    Mail::assertNothingSent();
});

it('will not send a second confirmation inside the cooldown', function () {
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();

    Mail::fake();

    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();

    Mail::assertNothingSent();
});

it('sends a fresh confirmation once the cooldown has passed', function () {
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();

    DB::table('subscribers')->where('email', 'shopper@example.com')->update([
        'updated_at' => now()->subSeconds(NewsletterList::RESEND_COOLDOWN + 60),
    ]);

    Mail::fake();

    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();

    // A pending signup that was never confirmed must not be a dead end forever:
    // the first mail may simply have been lost.
    Mail::assertSent(NewsletterConfirmation::class, 1);
});

/* ------------------------------------------------------------------- details */

it('is idempotent on a second press of the same confirm link', function () {
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();
    $url = confirmUrlFromMail();

    pressButton('confirm', $url)->assertOk();
    $first = DB::table('subscribers')->where('email', 'shopper@example.com')->value('confirmed_at');

    pressButton('confirm', $url)->assertOk();
    $second = DB::table('subscribers')->where('email', 'shopper@example.com')->value('confirmed_at');

    // Still confirmed, and the record of WHEN consent was given has not moved.
    expect($second)->toBe($first);
    expect(NewsletterList::marketable()->count())->toBe(1);
});

it('never prints the address on the confirm or unsubscribe pages', function () {
    $this->postJson('/api/subscribe', ['email' => 'private@example.com'])->assertOk();

    $confirm = confirmUrlFromMail();
    $unsub = unsubscribeUrlFromMail();

    // A link is a bearer credential: anyone holding it can open these pages,
    // including anyone who found it in a forwarded email or a proxy log.
    // Echoing the address turns a link that grants one harmless action into a
    // way to read the address it belongs to.
    foreach ([$this->get(pathOf($confirm)), $this->get(pathOf($unsub))] as $page) {
        expect($page->getContent())->not->toContain('private@example.com');
    }

    expect(pressButton('confirm', $confirm)->getContent())->not->toContain('private@example.com');
    expect(pressButton('unsubscribe', $unsub)->getContent())->not->toContain('private@example.com');
});

it('keeps the address out of the link itself', function () {
    $this->postJson('/api/subscribe', ['email' => 'private@example.com'])->assertOk();

    $url = confirmUrlFromMail();

    // A query string ends up in Referer headers, in proxy and CDN access logs,
    // and in whatever an inbox provider does when it prefetches links. The
    // claims carry a digest instead — see NewsletterList::claims().
    expect($url)->not->toContain('private@example.com');
    expect($url)->not->toContain(rawurlencode('private@example.com'));
});

it('lowercases the address so one person is one row', function () {
    $this->postJson('/api/subscribe', ['email' => 'Shopper@Example.COM'])->assertOk();
    $this->postJson('/api/subscribe', ['email' => 'shopper@example.com'])->assertOk();

    expect(DB::table('subscribers')->count())->toBe(1);
});

/* ------------------------------------------------ the owner's copy of the list */

/**
 * An owner, for the two admin-side assertions below.
 *
 * Declared here rather than reused from another test file because Pest loads
 * each file into its own scope but shares the process, so a duplicate function
 * name would fatal.
 */
function newsletterAdmin(): App\Models\AdminUser
{
    return App\Models\AdminUser::create([
        'name' => 'EE Owner',
        'email' => 'ee-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

it('keeps an unconfirmed address out of the CSV the owner downloads', function () {
    /*
     * THE LEAK THIS CLOSES. The export is downloaded in order to be pasted into
     * a mail-merge or an email platform, so an unconfirmed address in it is an
     * unconfirmed address that gets marketed to — through a route that touches
     * none of the guards in NewsletterList. Keeping pending rows out of the
     * application's own sending path and leaving them in the file the owner
     * actually mails from would be a rule enforced everywhere except where it
     * matters.
     */
    $this->postJson('/api/subscribe', ['email' => 'never-clicked@example.com'])->assertOk();
    $this->postJson('/api/subscribe', ['email' => 'did-click@example.com'])->assertOk();

    pressButton('confirm', confirmUrlFromMail('did-click@example.com'))->assertOk();

    $csv = $this->actingAs(newsletterAdmin(), 'admin')
        ->get('/admin-api/newsletter/export')
        ->streamedContent();

    expect($csv)->toContain('did-click@example.com');
    expect($csv)->not->toContain('never-clicked@example.com');
});

it('keeps somebody who unsubscribed out of the CSV as well', function () {
    $this->postJson('/api/subscribe', ['email' => 'gone@example.com'])->assertOk();
    pressButton('confirm', confirmUrlFromMail())->assertOk();
    pressButton('unsubscribe', unsubscribeUrlFromMail())->assertOk();

    $csv = $this->actingAs(newsletterAdmin(), 'admin')
        ->get('/admin-api/newsletter/export')
        ->streamedContent();

    // The row survives in the table so a later import cannot re-add them, and
    // is absent from the file the owner mails from. Both halves are required.
    expect($csv)->not->toContain('gone@example.com');
    expect(Illuminate\Support\Facades\DB::table('subscribers')->where('email', 'gone@example.com')->exists())->toBeTrue();
});

it('reports confirmed and pending separately on the newsletter screen', function () {
    /*
     * A single headline number would tell the owner he has a list of two when
     * one of them never clicked anything and will never receive a campaign.
     * `confirmed` is counted through the same builder the export filters on, so
     * the figure on the screen and the rows in the file cannot drift apart.
     */
    $this->postJson('/api/subscribe', ['email' => 'never-clicked@example.com'])->assertOk();
    $this->postJson('/api/subscribe', ['email' => 'did-click@example.com'])->assertOk();

    pressButton('confirm', confirmUrlFromMail('did-click@example.com'))->assertOk();

    $stats = $this->actingAs(newsletterAdmin(), 'admin')
        ->getJson('/admin-api/newsletter')
        ->assertOk()
        ->json('stats');

    expect($stats['total'])->toBe(2);
    expect($stats['confirmed'])->toBe(1);
    expect($stats['pending'])->toBe(1);
});
