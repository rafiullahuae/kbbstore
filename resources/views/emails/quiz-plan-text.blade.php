{{--
    The plain-text twin. Same facts, same order, no markup — the text part is
    what a mail client with images and HTML switched off shows, and what several
    spam filters score instead of the HTML.

    {{ }} rather than {!! !!} for everything that came from the shopper: this is
    still their input and this file is still rendered by Blade. The escaping
    turns & into &amp; in a plain-text part, which is a cosmetic cost the other
    text twins in this directory already pay for the same reason.
--}}
@if (trim($name) !== ''){!! __('email.quiz_plan.greeting_named', ['name' => $name]) !!}@else{!! __('email.quiz_plan.greeting') !!}@endif


{!! wordwrap(__('email.quiz_plan.lead'), 78) !!}
@if (trim($skinType) !== '')

{!! __('email.quiz_plan.skin_type') !!}: {{ $skinType }}
@endif
@if ($concerns !== [])
{!! __('email.quiz_plan.concerns') !!}: {{ implode(', ', $concerns) }}
@endif
@foreach ($routines as $routine)

{{ $routine['name'] ?? '' }}
@foreach ($routine['steps'] ?? [] as $i => $step)
{{ $i + 1 }}. {{ $step }}
@endforeach
@endforeach

{!! wordwrap(__('email.quiz_plan.steps_note_text'), 78) !!}

@php
    /*
        THE ONE BUTTON THIS MESSAGE CARRIES, chosen three ways — Lane Q.

        routine -> collection -> shop, and the label moves with the URL. The
        routine page needs `build_my_routine`, which ships OFF; /concern/{slug}/
        needs only that the owner has tagged enough products for that concern on
        Catalog -> Build my routine; /shop/ always exists. Before this the
        message fell straight from the first to the third, so a shopper who had
        just named a concern was sent to the whole catalogue.

        $concernUrl is read defensively because this view is also rendered
        outside the mailable that supplies it.
    */
    $kbbConcernUrl = $concernUrl ?? null;
    $kbbCtaUrl = $routineUrl ?? $kbbConcernUrl ?? $shopUrl;
    $kbbCtaLabel = $routineUrl !== null
        ? __('email.quiz_plan.routine_button')
        : ($kbbConcernUrl !== null ? __('email.quiz_plan.concern_button') : __('email.quiz_plan.shop_button'));
@endphp
{!! $kbbCtaLabel !!}

{{ $kbbCtaUrl }}

{!! wordwrap(__('email.quiz_plan.why_text'), 78) !!}

- {{ $brand['storeName'] ?? config('app.name') }}
