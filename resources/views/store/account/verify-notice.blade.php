{{--
    "Confirm your email address" — shown to a signed-in customer.

    Deliberately NOT a wall: nothing on this storefront is gated on a confirmed
    address today. Verification is here so that it can be, and so the column
    that has sat unwritten since the baseline schema starts carrying a fact.
--}}
@extends('layouts.store')
@section('title', __('store.verify.notice_title'))

@section('content')
@php use App\Support\Url; @endphp
@php $ap = app(\App\Services\AccountPanel::class); @endphp

<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid">
        <div class="authcard">
            <h1>{{ __('store.verify.notice_title') }}</h1>

            @if ($errors->any())<div class="auth-err">{{ $errors->first() }}</div>@endif
            @if (session('status'))<div class="auth-ok">{{ session('status') }}</div>@endif

            @if ($customer && $customer->hasVerifiedEmail())
                <p class="lede">{{ __('store.verify.already_done') }}</p>
            @else
                <p class="lede">{!! __('store.verify.will_send', ['email' => '<strong>' . e($customer?->email) . '</strong>']) !!}</p>

                <form method="post" action="{{ Url::to('/my-account/verify/resend') }}">
                    @csrf
                    <button class="go" type="submit">{{ __('store.account.forgot_submit') }}</button>
                </form>
            @endif

            <p class="alt"><a href="{{ Url::to('/my-account/') }}">{{ __('store.verify.back_to_account') }}</a></p>
        </div>
    </div>
</div>
@endsection
