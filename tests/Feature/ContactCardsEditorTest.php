<?php

declare(strict_types=1);

/*
 * The contact page's cards, editable on the page's own editor. (Lane CT2)
 *
 * The owner, 9 October, on a screenshot of the live /contact-us/ with only the
 * WhatsApp and Email cards: "i have added the instagram link, but not showing
 * the third block. please add manually and give such blocks edits option
 * directly in contact us page edits."
 *
 * Pages → User pages → Contact Us → Edit → Contact cards. One stored list
 * (`contact_page.cards`) that Store → Inquiries → Contact page's switches also
 * write. Each case names the defect it would have caught on the shop and the
 * change that turns it red (MUTATION).
 */

use App\Models\AdminUser;
use App\Models\Page;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\ContactPage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ContactInquiryRoutes;
use Tests\Support\PageEditorRoutes;

function ccForget(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
}

function ccSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    ccForget();
}

function ccAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'CC '.$role, 'email' => 'cc-'.$role.'-'.uniqid().'@example.test',
        'password' => 'password-long-enough', 'role' => $role,
    ]);
}

function ccPage(string $slug = 'contact-us'): Page
{
    return Page::query()->where('slug', $slug)->firstOrFail();
}

function ccHtml(): string
{
    ccForget();

    return (string) test()->get('/contact-us/')->assertOk()->getContent();
}

/** The cards section only, so the footer's own Instagram link cannot answer for it. */
function ccCards(): string
{
    preg_match('#<section class="ctc ctc-top".*?</section>#s', ccHtml(), $m);

    return $m[0] ?? '';
}

/** @return list<string> the drawn cards' keys, in page order */
function ccOrder(): array
{
    preg_match_all('/class="ctc-card" data-ct="([a-z0-9]+)"/', ccCards(), $m);

    return $m[1];
}

/** The editor's projection of the contact page, as the screen receives it. */
function ccLoad(?AdminUser $as = null): array
{
    test()->actingAs($as ?? ccAdmin(), 'admin');

    return test()->getJson('/admin-api/page-editor-load/'.ccPage()->id)->assertOk()->json('page');
}

/** Save the contact page through the editor, with its cards as given. */
function ccSave(array $cards, ?AdminUser $as = null, ?Page $page = null): \Illuminate\Testing\TestResponse
{
    $page ??= ccPage();
    test()->actingAs($as ?? ccAdmin(), 'admin');

    $res = test()->postJson('/admin-api/page-editor-save/'.$page->id, [
        'title' => html_entity_decode((string) $page->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'content' => (string) $page->content,
        'status' => (string) $page->status,
        'seo' => ['title' => '', 'desc' => '', 'og_image' => '', 'canonical' => '', 'noindex' => false],
        'translations' => [],
        'contact_cards' => $cards,
    ]);
    ccForget();

    return $res;
}

/** The editor's cards, as the screen would post them back untouched. */
function ccPosted(array $editorCards): array
{
    return array_map(static fn (array $c): array => array_intersect_key($c, array_flip(['k', 'on', 'icon', 'title', 'note', 'value', 'action', 'link'])), $editorCards);
}

beforeEach(function () {
    PageEditorRoutes::wire($this->app);
    ContactInquiryRoutes::wire($this->app);
    Cache::flush();
    ccSet('support_email', 'info@kbeautybliss.com');
    ccSet('support_phone', '+971 58 505 2611');
    ccSet('brand_whatsapp', '+971585052611');
    $this->withoutDefer();
});

/* ═════════════════════ the Instagram card, by default ═════════════════════ */

it('draws the Instagram card by default, falling back to the shop’s known profile', function () {
    /*
     * The live defect: `social_instagram` saved blank (the SEO tab opens that
     * box empty for a key with no row and Save writes ''), so the card had no
     * profile and was not drawn. MUTATION: return '' instead of
     * DEFAULT_INSTAGRAM at the end of ContactPage::instagramProfile() -> red.
     */
    expect(ccOrder())->toBe(['wa', 'ig', 'email'])
        ->and(ccCards())->toContain('href="https://ig.me/m/kbeauty.bliss"');

    ccSet('social_instagram', '');
    expect(ccOrder())->toBe(['wa', 'ig', 'email'])
        ->and(ccCards())->toContain('href="https://ig.me/m/kbeauty.bliss"')->toContain('@kbeauty.bliss');
});

it('uses Store → Mail’s Instagram handle, and a handle typed without https://, before the default', function () {
    /*
     * The two other ways he may have "added the instagram link". MUTATION:
     * drop the mail_support_instagram read in instagramProfile() -> the first
     * expectation reads @kbeauty.bliss, red; drop the instagramHandle() arm for
     * social_instagram -> the second, red.
     */
    ccSet('social_instagram', '');
    ccSet('mail_support_instagram', '@kbb.mail');
    expect(ccCards())->toContain('href="https://ig.me/m/kbb.mail"')->toContain('@kbb.mail');

    ccSet('social_instagram', 'instagram.com/kbb.seo');
    expect(ccCards())->toContain('href="https://ig.me/m/kbb.seo"');
});

/* ═════════════════════════════ the editor ═════════════════════════════ */

it('shows the Contact cards card on the contact page only, pre-filled with what the shop prints', function () {
    // MUTATION: drop the slug check in projection() -> the FAQ page carries cards, red.
    $page = ccLoad();
    $cards = $page['contact_cards']['cards'];

    expect(array_column($cards, 'k'))->toBe(['wa', 'ig', 'email', 'phone'])
        ->and(array_column($cards, 'on'))->toBe([true, true, true, false])
        ->and($cards[1])->toMatchArray(['title' => 'Instagram', 'value' => '@kbeauty.bliss', 'link' => 'https://ig.me/m/kbeauty.bliss'])
        ->and($cards[0])->toMatchArray(['value' => '+971585052611', 'link' => 'https://wa.me/971585052611', 'action' => 'Chat on WhatsApp'])
        ->and($page['contact_cards']['max'])->toBe(6);

    test()->actingAs(ccAdmin(), 'admin');
    expect(test()->getJson('/admin-api/page-editor-load/'.ccPage('faqs')->id)->json('page.contact_cards'))->toBeNull();
});

it('stores nothing for a box left as it was, so the card keeps following the settings', function () {
    /*
     * The defect this prevents: a Save that freezes today's number into the
     * page, so a later change on the business screen never reaches the card.
     * MUTATION: drop the "equal to the default -> ''" loop in checkCards() ->
     * the stored value is the number, red.
     */
    ccSave(ccPosted(ccLoad()['contact_cards']['cards']))->assertOk();

    $stored = app(SettingsService::class)->get(ContactPage::KEY);
    foreach ($stored['cards'] as $card) {
        expect([$card['title'], $card['note'], $card['value'], $card['action'], $card['link']])->toBe(['', '', '', '', '']);
    }

    ccSet('brand_whatsapp', '+971500000001');
    expect(ccCards())->toContain('href="https://wa.me/971500000001"');
});

it('overrides one card’s words and link for this page, and a new value carries its own link', function () {
    /*
     * MUTATION: ignore $card['link'] in resolve() -> the override link is not
     * drawn, red; drop the derivedLink() arm -> the new email prints with the
     * old mailto:, red.
     */
    $cards = ccPosted(ccLoad()['contact_cards']['cards']);
    $cards[1] = array_replace($cards[1], ['title' => 'DM us', 'note' => 'We answer fast', 'value' => '@kbb.dm', 'action' => 'Open Instagram', 'link' => 'https://www.instagram.com/kbb.dm/']);
    $cards[2] = array_replace($cards[2], ['value' => 'hello@kbeautybliss.com']);
    ccSave($cards)->assertOk();

    $html = ccCards();
    expect($html)->toContain('<h3>DM us</h3>')->toContain('We answer fast')->toContain('@kbb.dm')
        ->toContain('href="https://www.instagram.com/kbb.dm/"')->toContain('Open Instagram')
        ->toContain('href="mailto:hello@kbeautybliss.com"')->toContain('hello@kbeautybliss.com')
        ->and($html)->not->toContain('mailto:info@kbeautybliss.com');

    // The editor opens on the override, and the link the new value implies.
    $back = ccLoad()['contact_cards']['cards'];
    expect($back[1]['title'])->toBe('DM us')->and($back[2]['link'])->toBe('mailto:hello@kbeautybliss.com');
});

it('hides a card from the page editor, and shows it again', function () {
    // MUTATION: drop the `! $card['on']` check in resolve() -> red.
    $cards = ccPosted(ccLoad()['contact_cards']['cards']);
    $cards[0]['on'] = false;
    ccSave($cards)->assertOk();
    expect(ccOrder())->toBe(['ig', 'email']);

    $cards[0]['on'] = true;
    ccSave($cards)->assertOk();
    expect(ccOrder())->toBe(['wa', 'ig', 'email']);
});

it('draws the cards in the order the editor saved', function () {
    // MUTATION: iterate self::BUILTIN instead of cardsFrom() in view() -> red.
    $cards = ccPosted(ccLoad()['contact_cards']['cards']);
    ccSave([$cards[2], $cards[1], $cards[3], $cards[0]])->assertOk();

    expect(ccOrder())->toBe(['email', 'ig', 'wa'])
        ->and(array_column(ccLoad()['contact_cards']['cards'], 'k'))->toBe(['email', 'ig', 'phone', 'wa']);
});

it('adds a custom card with an icon from the fixed set', function () {
    /*
     * MUTATION: print $card['icon'] instead of self::icon($card['icon']) ->
     * the word "location" where the map pin should be, red.
     */
    $cards = ccPosted(ccLoad()['contact_cards']['cards']);
    $cards[] = ['k' => 'c1', 'on' => true, 'icon' => 'location', 'title' => 'Visit us', 'note' => 'Dubai showroom', 'value' => 'Al Quoz 1', 'action' => 'Get directions', 'link' => 'https://maps.google.com/?q=kbb'];
    ccSave($cards)->assertOk();

    $html = ccCards();
    expect(ccOrder())->toBe(['wa', 'ig', 'email', 'c1'])
        ->and($html)->toContain('<h3>Visit us</h3>')->toContain('Al Quoz 1')->toContain('Get directions')
        ->toContain('href="https://maps.google.com/?q=kbb" target="_blank" rel="noopener"')
        ->toContain('<circle cx="12" cy="9.5" r="2.5"/>');

    // An icon outside the set, a seventh card, a third custom card: refused, nothing stored.
    $bad = $cards;
    $bad[4]['icon'] = '<svg onload=alert(1)>';
    ccSave($bad)->assertStatus(422);
    ccSave(array_merge($cards, [['k' => 'c2'] + $cards[4], ['k' => 'c3'] + $cards[4]]))->assertStatus(422);
    expect(count(ContactPage::cards()))->toBe(5);
});

it('refuses an unsafe link on any card, and stores nothing', function (string $link) {
    /*
     * MUTATION: let linkOk() accept whatever SafeUrl::href() accepts ->
     * `mailto:not-an-address` is stored, red (measured); make it return true
     * -> every case is red.
     */
    $cards = ccPosted(ccLoad()['contact_cards']['cards']);
    $before = app(SettingsService::class)->get(ContactPage::KEY);

    $one = $cards;
    $one[1]['link'] = $link;
    ccSave($one)->assertStatus(422)->assertJsonPath('ok', false);

    $two = $cards;
    $two[] = ['k' => 'c1', 'on' => true, 'icon' => 'link', 'title' => 'X', 'note' => '', 'value' => '', 'action' => 'Go', 'link' => $link];
    ccSave($two)->assertStatus(422);

    expect(app(SettingsService::class)->get(ContactPage::KEY))->toBe($before)
        ->and(ccHtml())->not->toContain($link);
})->with([
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html,<script>alert(1)</script>',
    'vbscript' => 'vbscript:msgbox(1)',
    'other host' => '//evil.test/x',
    'entity-smuggled' => 'jav&#x09;ascript:alert(1)',
    'mailto junk' => 'mailto:not-an-address',
]);

it('drops a hand-written unsafe link on the way out, too', function () {
    // MUTATION: drop the linkOk() check in cleanStored() -> the row's link is drawn, red.
    ccSet(ContactPage::KEY, ['cards' => [
        ['k' => 'ig', 'on' => true, 'link' => 'javascript:alert(1)'],
        ['k' => 'c1', 'on' => true, 'icon' => 'link', 'title' => 'Bad', 'action' => 'Go', 'link' => 'javascript:alert(2)'],
    ]]);

    $html = ccHtml();
    expect($html)->not->toContain('javascript:alert')
        ->and(ccOrder())->toBe(['ig', 'wa', 'email'])
        ->and(ccCards())->toContain('href="https://ig.me/m/kbeauty.bliss"');
});

it('prints every typed word escaped', function () {
    // MUTATION: {!! $ctcCard['title'] !!} in contact-hub-cards.blade.php -> red.
    $cards = ccPosted(ccLoad()['contact_cards']['cards']);
    $cards[1]['title'] = '<img src=x onerror=alert(1)>';
    $cards[] = ['k' => 'c1', 'on' => true, 'icon' => 'chat', 'title' => '"><script>alert(2)</script>', 'note' => '', 'value' => '<b>v</b>', 'action' => 'Go & see', 'link' => 'https://example.com/?a=1&b=2'];
    ccSave($cards)->assertOk();

    $html = ccCards();
    expect($html)->not->toContain('<img src=x')->not->toContain('<script>alert(2)')->not->toContain('<b>v</b>')
        ->toContain('&lt;img src=x onerror=alert(1)&gt;')->toContain('&lt;b&gt;v&lt;/b&gt;')
        ->toContain('Go &amp; see')->toContain('href="https://example.com/?a=1&amp;b=2"');
});

/* ══════════════════════ one list, two screens ══════════════════════ */

it('makes Store → Inquiries’ switches and the page editor’s Show boxes the same stored value', function () {
    /*
     * The defect: two sources, so a card switched on in one screen stays off
     * because the other says so. MUTATION: store `$clean` as it was in
     * ContactPage::save() (top-level switches, no card list) -> the editor's
     * Show box disagrees, red; or drop the "keep every word" loop -> the
     * override below is lost, red.
     */
    $owner = ccAdmin();
    $cards = ccPosted(ccLoad($owner)['contact_cards']['cards']);
    $cards[1]['title'] = 'DM us';
    ccSave($cards, $owner)->assertOk();

    // Inquiries switches Instagram off and the phone on…
    $this->actingAs($owner, 'admin')->postJson('/admin-api/inquiries/settings', ['config' => array_replace(ContactPage::defaults(), ['ig' => false, 'phone' => true])])->assertOk();
    ccForget();
    $editor = ccLoad($owner)['contact_cards']['cards'];
    expect(array_column($editor, 'on', 'k'))->toBe(['wa' => true, 'ig' => false, 'email' => true, 'phone' => true])
        ->and($editor[1]['title'])->toBe('DM us')
        ->and(ccOrder())->toBe(['wa', 'email', 'phone']);

    // …and the editor switches it back, which Inquiries then reads.
    $cards = ccPosted($editor);
    $cards[1]['on'] = true;
    ccSave($cards, $owner)->assertOk();
    $inq = $this->actingAs($owner, 'admin')->getJson('/admin-api/inquiries/settings')->assertOk()->json();
    expect($inq['config']['ig'])->toBeTrue()->and($inq['values']['ig'])->toBe('@kbeauty.bliss');

    // One row, no second copy of the switches.
    $row = app(SettingsService::class)->get(ContactPage::KEY);
    expect($row)->toHaveKey('cards')->not->toHaveKey('ig')->not->toHaveKey('wa');
});

it('moves the old switches into the card list, Instagram on, once', function () {
    /*
     * The migration. MUTATION: copy `ig` as stored instead of switching it on
     * -> the owner's card stays hidden, red; drop the `isset($row['cards'])`
     * guard -> the second run overwrites the edited title, red.
     */
    $migration = require base_path('database/migrations/2027_10_15_180000_move_contact_card_switches_into_cards.php');

    ccSet(ContactPage::KEY, ['wa' => true, 'ig' => false, 'email' => false, 'phone' => false, 'socials' => true, 'hours' => true, 'form' => true, 'recipient' => '', 'topics' => ['Other']]);
    $migration->up();
    ccForget();

    $row = app(SettingsService::class)->get(ContactPage::KEY);
    expect(array_column($row['cards'], 'on', 'k'))->toBe(['wa' => true, 'ig' => true, 'email' => false, 'phone' => false])
        ->and($row)->not->toHaveKey('ig')->and($row['topics'])->toBe(['Other'])
        ->and(ccOrder())->toBe(['wa', 'ig']);

    $row['cards'][0]['title'] = 'Kept';
    ccSet(ContactPage::KEY, $row);
    $migration->up();
    ccForget();
    expect(app(SettingsService::class)->get(ContactPage::KEY)['cards'][0]['title'])->toBe('Kept');

    $migration->down();
    ccForget();
    expect(app(SettingsService::class)->get(ContactPage::KEY))->toMatchArray(['wa' => true, 'ig' => true, 'email' => false, 'phone' => false]);
});

/* ═════════════════════════ capability, cost ═════════════════════════ */

it('refuses the cards to a role without pages.manage, fails closed, and only on the contact page', function () {
    /*
     * MUTATION: map POST admin-api/page-editor-save/* to a capability support
     * holds -> red. The cards ride the page editor's own routes, so they have
     * no door of their own to forget.
     */
    $before = app(SettingsService::class)->get(ContactPage::KEY);
    $cards = ccPosted(ccLoad()['contact_cards']['cards']);
    $cards[0]['on'] = false;

    ccSave($cards, ccAdmin('support'))->assertForbidden();
    test()->actingAs(ccAdmin('support'), 'admin')->getJson('/admin-api/page-editor-load/'.ccPage()->id)->assertForbidden();

    \Illuminate\Support\Facades\Auth::guard('admin')->logout();
    test()->postJson('/admin-api/page-editor-save/'.ccPage()->id, ['contact_cards' => $cards])->assertUnauthorized();

    ccSave($cards, ccAdmin('owner'), ccPage('faqs'))->assertStatus(422);

    expect(app(SettingsService::class)->get(ContactPage::KEY))->toBe($before)
        ->and(ccOrder())->toBe(['wa', 'ig', 'email']);

    ccSave($cards, ccAdmin('editor'))->assertOk();
    expect(ccOrder())->toBe(['ig', 'email']);
});

it('costs /contact-us/ no extra query, with six cards edited as much as with none', function () {
    /*
     * Flat: the list rides the request's settings memo. MUTATION: read
     * `mail_support_instagram` through MailSettings::all() or a
     * Setting::query() in instagramProfile() -> more queries, red.
     */
    $count = static function (string $path): int {
        ccForget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get($path)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count('/contact-us/');
    $plain = $count('/contact-us/');
    expect($plain)->toBe($count('/privacy-policy/'));

    ccSet('social_instagram', '');
    ccSet('mail_support_instagram', '@kbb.mail');
    $cards = ccPosted(ccLoad()['contact_cards']['cards']);
    $cards[3]['on'] = true;
    $cards[0]['title'] = 'Chat';
    $cards[] = ['k' => 'c1', 'on' => true, 'icon' => 'clock', 'title' => 'Hours', 'note' => '', 'value' => '10–22', 'action' => 'See', 'link' => '/contact-us/'];
    $cards[] = ['k' => 'c2', 'on' => true, 'icon' => 'link', 'title' => 'Track', 'note' => '', 'value' => '', 'action' => 'Track', 'link' => '/track-my-order/'];
    ccSave($cards)->assertOk();
    \Illuminate\Support\Facades\Auth::guard('admin')->logout();

    expect(count(ccOrder()))->toBe(6)
        ->and($count('/contact-us/'))->toBe($plain);
});

it('lays the phone grid out by count: the first card across only when the count is odd', function () {
    /*
     * The owner's approved phone layout was keyed on [data-ct=wa]; once the
     * order is his that is wrong (WhatsApp moved third would still span the
     * row and leave a hole). MUTATION: restore `.ctc-card[data-ct=wa]
     * {grid-column:1/-1}` -> red.
     */
    $html = ccHtml();
    preg_match('#@media \(max-width:599px\)\{.*?\n\}#s', $html, $phone);

    expect($phone[0] ?? '')->toContain('.ctc-card:first-child:nth-last-child(odd){grid-column:1/-1}')
        ->not->toContain('[data-ct=wa]{grid-column')
        ->and($html)->toContain('.ctc-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))');
});

it('isolates the @handle on the Arabic page, and leaves English alone', function () {
    /*
     * Measured on the RTL preview: "@kbeauty.bliss" printed as
     * "kbeauty.bliss@" on /ar/contact-us/, the leading @ taking the page's
     * direction. MUTATION: wrap only 'wa' in Bidi::number() in resolve() -> red.
     */
    \Tests\Support\ArabicShop::on();
    app(SettingsService::class)->set(\App\Support\Locale::SETTING_RTL, '1');
    ccForget();

    $ar = (string) test()->get('/ar/contact-us/')->assertOk()->getContent();
    expect($ar)->toContain('<p class="ctc-val">'.\App\Support\Bidi::LRI.'@kbeauty.bliss'.\App\Support\Bidi::PDI.'</p>')
        ->and(ccCards())->toContain('<p class="ctc-val">@kbeauty.bliss</p>');
});
