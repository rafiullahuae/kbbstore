{{--
    "Your account is ready — choose a password." (Lane PQ)

    Where an account invite from Store → Customers → Send account invite lands.
    Built on store/account/reset.blade.php, same card, same fields, same rule
    (at least 8 characters, typed twice).

    One template for both outcomes. A link that is unknown, expired, used, or
    replaced by a newer invite all render the SAME sentence — see
    AccountInviteController. The address is shown only on a live link, to the
    person holding the link that was mailed to it.
--}}
@extends('layouts.store')
@section('title', __('store.account.welcome_title'))

@section('content')
@php use App\Support\Url; @endphp
@php $ap = app(\App\Services\AccountPanel::class); @endphp

<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid">
        <div class="authcard">
            <h1>{{ __('store.account.welcome_title') }}</h1>

            @if (! $valid)
                <div class="auth-err">{{ $message }}</div>
                <p class="alt"><a href="{{ Url::to('/my-account/forgot') }}">{{ __('store.account.reset_new_link') }}</a></p>
            @else
                <p class="lede">{{ __('store.account.welcome_lead', ['shop' => $shop]) }}</p>
                <p class="lede" style="word-break:break-all">{{ __('store.account.welcome_for', ['email' => $email]) }}</p>

                @if ($errors->any())<div class="auth-err">{{ $errors->first() }}</div>@endif

                <form method="post" action="{{ Url::to('/my-account/welcome') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    {{-- For the browser's password manager, so the new password is saved against this address. Never submitted as anything the server reads. --}}
                    <input type="email" name="username" value="{{ $email }}" autocomplete="username" readonly hidden>

                    <div class="fgroup">
                        <x-field name="password" :label="__('store.account.reset_field_password')" type="password"
                                 icon="lock" autocomplete="new-password" minlength="8" reveal />
                        <x-field name="password_confirmation" :label="__('store.account.reset_field_confirm')" type="password"
                                 icon="lock" autocomplete="new-password" minlength="8" reveal />
                    </div>

                    <button class="go" type="submit">{{ __('store.account.welcome_submit') }}</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
