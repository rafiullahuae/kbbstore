@extends('layouts.store')
@section('title', __('store.account.forgot_title'))

@section('content')
@php use App\Support\Url; @endphp

@php $ap = app(\App\Services\AccountPanel::class); @endphp
<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid">
        <div class="authcard">
            <h1>{{ __('store.account.forgot_heading') }}</h1>
            <p class="lede">{{ __('store.account.forgot_lead') }}</p>

            @if ($errors->any())<div class="auth-err">{{ $errors->first() }}</div>@endif
            @if (session('status'))<div class="auth-ok">{{ session('status') }}</div>@endif

            <form method="post" action="{{ Url::to('/my-account/forgot') }}">
                @csrf
                <div class="fgroup">
                    <x-field name="email" :label="__('store.checkout.field_email')" type="email" icon="mail" autocomplete="email" />
                </div>
                <button class="go" type="submit">{{ __('store.account.forgot_submit') }}</button>
            </form>

            <p class="alt"><a href="{{ Url::to('/my-account/') }}">{{ __('store.account.back_to_sign_in') }}</a></p>
        </div>
    </div>
</div>
@endsection
