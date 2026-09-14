{{--
    "Confirm your email address" — shown to a signed-in customer.

    Deliberately NOT a wall: nothing on this storefront is gated on a confirmed
    address today. Verification is here so that it can be, and so the column
    that has sat unwritten since the baseline schema starts carrying a fact.
--}}
@extends('layouts.store')
@section('title', 'Confirm your email')

@section('content')
@php use App\Support\Url; @endphp
@php $ap = app(\App\Services\AccountPanel::class); @endphp

<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid">
        <div class="authcard">
            <h1>Confirm your email</h1>

            @if ($errors->any())<div class="auth-err">{{ $errors->first() }}</div>@endif
            @if (session('status'))<div class="auth-ok">{{ session('status') }}</div>@endif

            @if ($customer && $customer->hasVerifiedEmail())
                <p class="lede">Your address is confirmed. There is nothing to do here.</p>
            @else
                <p class="lede">We will send a link to
                    <strong>{{ $customer?->email }}</strong>. Open it and the address is confirmed.</p>

                <form method="post" action="{{ Url::to('/my-account/verify/resend') }}">
                    @csrf
                    <button class="go" type="submit">Send the link</button>
                </form>
            @endif

            <p class="alt"><a href="{{ Url::to('/my-account/') }}">Back to your account</a></p>
        </div>
    </div>
</div>
@endsection
