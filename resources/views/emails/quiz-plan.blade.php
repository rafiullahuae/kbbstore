{{--
    The skin quiz's plan email — Lane FT.

    Plain, and deliberately NOT built on emails/layout.blade.php: see
    App\Mail\QuizPlanEmail's header. This goes to somebody who may never have
    bought anything and who was told "no spam, ever" while typing their address
    in.

    EVERY LINE HERE IS EITHER THE SHOPPER'S OWN ANSWER OR A STEP THE PAGE
    ALREADY SHOWED THEM. No product is named, no price is quoted and no saving
    is claimed — the quiz's own results screen makes none of those claims and an
    email is the last place to start. `$routines` has already been reduced to a
    name and a list of step names by Api\QuizController::routinesFrom(), so
    nothing else can reach this file whatever is posted.

    Nothing is echoed raw. The name and the answers are a stranger's input on a
    public, unauthenticated endpoint, and this message is read in an inbox that
    renders HTML.
--}}
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d1d1f;">
    <p style="font-size:18px;font-weight:600;margin:0 0 14px;">
        @if (trim($name) !== '')
            {{ __('email.quiz_plan.greeting_named', ['name' => $name]) }}
        @else
            {{ __('email.quiz_plan.greeting') }}
        @endif
    </p>

    <p>{{ __('email.quiz_plan.lead') }}</p>

    @if (trim($skinType) !== '' || $concerns !== [])
        <p style="font-size:13px;color:#555;margin:18px 0 0;">
            @if (trim($skinType) !== '')
                <strong>{{ __('email.quiz_plan.skin_type') }}:</strong> {{ $skinType }}<br>
            @endif
            @if ($concerns !== [])
                <strong>{{ __('email.quiz_plan.concerns') }}:</strong> {{ implode(', ', $concerns) }}
            @endif
        </p>
    @endif

    @foreach ($routines as $routine)
        <div style="border:1px solid #eee;border-radius:10px;padding:14px 16px;margin:18px 0;">
            <p style="font-weight:600;margin:0 0 8px;">{{ $routine['name'] }}</p>
            <ol style="margin:0;padding-inline-start:20px;">
                @foreach ($routine['steps'] as $step)
                    <li style="margin:2px 0;">{{ $step }}</li>
                @endforeach
            </ol>
        </div>
    @endforeach

    {{--
        THE SENTENCE THAT KEEPS THIS HONEST. The list above is an order of
        steps, not a basket: nothing has been chosen, reserved or charged. The
        quiz's own results screen says the same thing by showing steps and
        sending the shopper to the shop, and an email that let that go unsaid
        would read as a list of things somebody had picked out for them.
    --}}
    <p style="font-size:13px;color:#555;">{{ __('email.quiz_plan.steps_note') }}</p>

    <p style="margin:24px 0;">
        <a href="{{ $routineUrl ?? $shopUrl }}" style="display:inline-block;padding:12px 22px;background:#1d1d1f;color:#ffffff;text-decoration:none;border-radius:6px;">{{ $routineUrl !== null ? __('email.quiz_plan.routine_button') : __('email.quiz_plan.shop_button') }}</a>
    </p>

    <p style="font-size:13px;color:#555;">{{ __('email.common.paste_link') }}<br>
        <span style="word-break:break-all;">{{ $routineUrl ?? $shopUrl }}</span></p>

    {{--
        WHY THIS MESSAGE ARRIVED, IN WORDS — the rule
        emails/back-in-stock.blade.php states: a message a recipient cannot
        account for is a message they report as spam, and a spam complaint costs
        this shop's whole domain far more than one plan is worth.

        Here it carries a second load. The form promised "no spam, ever" and
        this is where that promise is kept in writing: one message, no list.
    --}}
    <p style="font-size:13px;color:#555;">{{ __('email.quiz_plan.why') }}</p>

    <p style="color:#555;">— {{ $brand['storeName'] ?? config('app.name') }}</p>
</div>
