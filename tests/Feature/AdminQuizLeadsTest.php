<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\QuizSubmission;
use Illuminate\Support\Facades\DB;
use Tests\Support\QuizLeadsAdminRoutes;

/**
 * Store → Quiz Leads.
 *
 * A lead list is a worklist: the owner opens it to decide who to call. It was
 * none of those things.
 *
 * WHAT THE SCREEN COULD NOT DO. No search — with the quiz linked from the
 * storefront, finding the person who just phoned meant reading every row. No
 * status, although `quiz_submissions.status` exists, defaults to 'new' and was
 * already being returned by the endpoint, so there was no way to record that a
 * lead had been called. And `expert_message` — the words the customer actually
 * typed when asking for a consultation — was never sent to the screen at all,
 * so the one row flagged "Expert · Requested" showed the request and hid the
 * request's content.
 *
 * WHAT IT DID THAT IT SHOULD NOT. `concerns` and `recommended` were
 * concatenated into the table's HTML without sesc(), while every other cell on
 * the same row was escaped. POST /api/quiz is public and unauthenticated and
 * validates `concerns` as 'nullable' — no type, no length, no content rule — so
 * the value is whatever an anonymous caller posts. That is a stored
 * cross-site-scripting hole that runs in the owner's authenticated admin
 * session, on a screen whose whole purpose is to be opened and read.
 */

/* ------------------------------------------------------------------ fixtures */

/*
 * Declared here as well as in AdminAnalyticsTest, each guarded, because Pest
 * puts test-file functions in the global namespace. This file called it
 * without defining it and passed only because that file loaded first; alone,
 * all ten of its tests errored on "Call to undefined function anAdminUser()".
 */
if (! function_exists('anAdminUser')) {
    function anAdminUser(): \App\Models\AdminUser
    {
        return \App\Models\AdminUser::create([
            'name' => 'A Owner',
            'email' => 'a-owner-' . uniqid() . '@example.test',
            'password' => 'secret-secret',
            'role' => 'owner',
        ]);
    }
}

/** Wire this lane's route file and sign in — the normal case. */
function asQuizAdmin(): void
{
    QuizLeadsAdminRoutes::wire(app());
    test()->actingAs(anAdminUser(), 'admin');
}

function aLead(array $attributes = []): QuizSubmission
{
    static $n = 0;
    $n++;

    return QuizSubmission::create(array_merge([
        'name' => 'Lead ' . $n,
        'email' => 'lead-' . $n . '@example.test',
        'phone' => '+9715000000' . $n,
        'skin_type' => 'combination',
        'concerns' => 'dryness,dullness',
        'status' => 'new',
    ], $attributes));
}

/* ---------------------------------------------------------------- the guard */

it('refuses an anonymous caller on the quiz leads endpoint', function () {
    expect(test()->getJson('/admin-api/quiz-leads')->getStatusCode())->toBe(401);
});

/* ------------------------------------------------- the stored-XSS hole */

it('escapes a concern before putting it in the admin table', function () {
    $console = file_get_contents(resource_path('views/admin/app.blade.php'));

    // The unescaped concatenation, exactly as it shipped.
    expect(str_contains($console, "'<span class=\"tagchip\" style=\"font-size:10px\">'+x+'</span>'"))
        ->toBeFalse('concerns came straight from a public unauthenticated POST and were not escaped');
});

it('accepts a hostile concern from the public quiz without the admin trusting it', function () {
    $payload = '<img src=x onerror=alert(1)>';

    test()->postJson('/api/quiz', [
        'skin_type' => 'oily',
        'concerns' => [$payload],
        'email' => 'hostile@example.test',
    ])->assertStatus(201);

    asQuizAdmin();

    $leads = test()->getJson('/admin-api/quiz-leads')->json('leads');
    $row = collect($leads)->firstWhere('email', 'hostile@example.test');

    expect($row)->not->toBeNull('the lead must still be captured — this is not about rejecting the input');
    expect($row['concerns'])->toContain($payload);

    // The screen is what must not trust it. Both list cells go through sesc().
    $console = file_get_contents(resource_path('views/admin/app.blade.php'));
    $render = substr($console, (int) strpos($console, 'async function renderQuizLeads'), 6000);

    expect(str_contains($render, 'sesc(x)'))
        ->toBeTrue('each concern chip must be escaped');
    expect(str_contains($render, "l.recommended.join(', ')"))
        ->toBeFalse('the recommended routines were joined into HTML unescaped');
});

/* ------------------------------------------------- what the owner needs */

it('sends the expert request message to the screen', function () {
    asQuizAdmin();

    aLead([
        'email' => 'wants-help@example.test',
        'expert_requested' => true,
        'expert_message' => 'My skin reacts to everything, can someone call me?',
    ]);

    $row = collect(test()->getJson('/admin-api/quiz-leads')->json('leads'))
        ->firstWhere('email', 'wants-help@example.test');

    expect($row['expert'])->toBeTrue();
    expect($row['expert_message'] ?? null)
        ->toBe('My skin reacts to everything, can someone call me?',
            'the screen flagged the request and hid what was asked');
});

it('can be searched by name, email and phone', function () {
    asQuizAdmin();

    aLead(['name' => 'Fatima Al Suwaidi', 'email' => 'fatima@example.test', 'phone' => '+971501234567']);
    aLead(['name' => 'Someone Else', 'email' => 'other@example.test', 'phone' => '+971509999999']);

    $byName = test()->getJson('/admin-api/quiz-leads?q=Suwaidi')->json('leads');
    expect($byName)->toHaveCount(1);
    expect($byName[0]['email'])->toBe('fatima@example.test');

    $byEmail = test()->getJson('/admin-api/quiz-leads?q=fatima@example')->json('leads');
    expect($byEmail)->toHaveCount(1);

    // The owner has the number the customer called from, not their spacing.
    $byPhone = test()->getJson('/admin-api/quiz-leads?q=501234567')->json('leads');
    expect($byPhone)->toHaveCount(1, 'a lead list you cannot search by phone is a list you cannot work');
});

it('records that a lead has been dealt with', function () {
    asQuizAdmin();

    $lead = aLead();

    expect($lead->status)->toBe('new');

    test()->putJson('/admin-api/quiz-leads/' . $lead->id, ['status' => 'contacted'])
        ->assertOk();

    expect($lead->fresh()->status)->toBe('contacted');
});

it('refuses a status the screen does not offer', function () {
    asQuizAdmin();

    $lead = aLead();

    test()->putJson('/admin-api/quiz-leads/' . $lead->id, ['status' => 'whatever'])
        ->assertStatus(422);

    expect($lead->fresh()->status)->toBe('new');
});

/* ------------------------------------------------------- quiz conversion */

it('reports how many leads went on to order, and does not invent the number', function () {
    asQuizAdmin();

    $bought = aLead(['email' => 'buyer@example.test']);
    aLead(['email' => 'browser@example.test']);

    // An order placed AFTER the quiz was filled in, in a real status.
    $order = Order::create([
        'order_number' => 'Q-' . uniqid(),
        'email' => 'buyer@example.test',
        'status' => 'completed',
        'subtotal' => 25000,
        'total' => 25000,
    ]);
    DB::table('orders')->where('id', $order->id)
        ->update(['created_at' => now()->addMinute()]);

    $summary = test()->getJson('/admin-api/quiz-leads')->json('summary');

    expect($summary['total'])->toBe(2);
    expect($summary['converted'])->toBe(1, 'one of the two leads ordered');
    expect($summary['converted_pct'])->toBe(50);
});

it('does not count an order placed before the quiz as a quiz conversion', function () {
    asQuizAdmin();

    $order = Order::create([
        'order_number' => 'Q-' . uniqid(),
        'email' => 'already@example.test',
        'status' => 'completed',
        'subtotal' => 25000,
        'total' => 25000,
    ]);
    DB::table('orders')->where('id', $order->id)
        ->update(['created_at' => now()->subDays(10)]);

    aLead(['email' => 'already@example.test']);

    $summary = test()->getJson('/admin-api/quiz-leads')->json('summary');

    expect($summary['converted'])->toBe(0, 'an order from before the quiz was not caused by the quiz');
});

it('does not count a cancelled order as a quiz conversion', function () {
    asQuizAdmin();

    aLead(['email' => 'cancelled@example.test']);

    $order = Order::create([
        'order_number' => 'Q-' . uniqid(),
        'email' => 'cancelled@example.test',
        'status' => 'cancelled',
        'subtotal' => 25000,
        'total' => 25000,
    ]);
    DB::table('orders')->where('id', $order->id)
        ->update(['created_at' => now()->addMinute()]);

    $summary = test()->getJson('/admin-api/quiz-leads')->json('summary');

    expect($summary['converted'])->toBe(0);
});

it('reports a conversion rate of zero rather than dividing by no leads', function () {
    asQuizAdmin();

    $summary = test()->getJson('/admin-api/quiz-leads')->json('summary');

    expect($summary['total'])->toBe(0);
    expect($summary['converted'])->toBe(0);
    expect($summary['converted_pct'])->toBe(0);
});
