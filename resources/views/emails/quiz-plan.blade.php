{{--
    "Your skin plan" in look A — Lane EM, from the owner's approved preview 16.
    Each routine the quiz built is one box of numbered steps, two side by side
    on a laptop and stacked on a phone (the kit's infoPair). The button is
    Lane Q's choice, unchanged: the routine page, else the concern's
    collection, else the shop — and the label moves with the URL.
--}}
@extends('emails.kit.simple')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitTitle = $kitTitle ?? __('email.kit.quiz_title');
    $kitPreheader = __('email.kit.pre_quiz');
    $kitWhy = __('email.kit.why_quiz');
    $kbbConcernUrl = $concernUrl ?? null;
    $kbbCtaUrl = $routineUrl ?? $kbbConcernUrl ?? $shopUrl;
    $kbbCtaLabel = $routineUrl !== null
        ? __('email.quiz_plan.routine_button')
        : ($kbbConcernUrl !== null ? __('email.quiz_plan.concern_button') : __('email.quiz_plan.shop_button'));
    $kitAbout = implode(' · ', array_values(array_filter([
        trim($skinType) !== '' ? __('email.quiz_plan.skin_type') . ': ' . $skinType : '',
        $concerns !== [] ? __('email.quiz_plan.concerns') . ': ' . implode(', ', $concerns) : '',
    ], static fn ($v) => $v !== '')));
    $kitLead = trim($kitAbout . ($kitAbout !== '' ? '. ' : '') . __('email.quiz_plan.lead'));
    $kitBox = static function (array $routine): array {
        $steps = array_values(array_map('strval', (array) ($routine['steps'] ?? [])));
        $html = implode('<br>', array_map(static fn ($s, $i) => e(($i + 1) . '. ' . $s), $steps, array_keys($steps)));

        return [(string) ($routine['name'] ?? ''), new \Illuminate\Support\HtmlString($html)];
    };
    $kitPairs = array_chunk(array_map($kitBox, array_values($routines)), 2);
@endphp

@section('kit_inner')
@include('emails.kit.hero', ['icon' => 'spark', 'tone' => 'pink', 'eyebrow' => __('email.kit.eyebrow_quiz'), 'title' => trim($name) !== '' ? __('email.quiz_plan.greeting_named', ['name' => $name]) : __('email.quiz_plan.greeting'), 'lead' => $kitLead])
@foreach ($kitPairs as $kitPair)
@include('emails.kit.info-pair', ['left' => $kitPair[0], 'right' => $kitPair[1] ?? ['', '']])
@endforeach
@include('emails.kit.button', ['label' => $kbbCtaLabel, 'href' => $kbbCtaUrl])
@include('emails.kit.para', ['html' => __('email.quiz_plan.steps_note'), 'pad' => '14px 32px 28px', 'size' => 13, 'center' => true])
@endsection
