{{--
    Choose a new password.

    One template for both outcomes. A link that is spent, expired, forged or
    points at an id that does not exist all render the SAME sentence — see
    PasswordResetController::INVALID_MESSAGE. Splitting them into "expired" and
    "unknown account" would read better and would tell an attacker walking the
    ids which ones are real.
--}}
@extends('layouts.store')
@section('title', __('store.account.reset_title'))

@section('content')
@php use App\Support\Url; @endphp
@php $ap = app(\App\Services\AccountPanel::class); @endphp

<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid">
        <div class="authcard">
            <h1>{{ __('store.account.reset_title') }}</h1>

            @if (! $valid)
                <div class="auth-err">{{ $message }}</div>
                <p class="alt"><a href="{{ Url::to('/my-account/forgot') }}">{{ __('store.account.reset_new_link') }}</a></p>
            @else
                <p class="lede">{{ __('store.account.reset_lead') }}</p>

                @if ($errors->any())<div class="auth-err">{{ $errors->first() }}</div>@endif

                @if ($retiresLegacy)
                    {{--
                        Said out loud, before the fact. This account still signs
                        in with the password it had on the old WordPress site;
                        setting a new one retires that. A customer who was not
                        told would read the old password's next failure as the
                        reset not having worked.
                    --}}
                    <div class="auth-ok">{{ __('store.account.reset_legacy_note') }}</div>
                @endif

                <form method="post" action="{{ Url::to('/my-account/reset') }}">
                    @csrf
                    <input type="hidden" name="id" value="{{ $id }}">
                    <input type="hidden" name="token" value="{{ $token }}">

                    <div class="fgroup">
                        <x-field name="password" :label="__('store.account.reset_field_password')" type="password"
                                 icon="lock" autocomplete="new-password" minlength="8" reveal />
                        <x-field name="password_confirmation" :label="__('store.account.reset_field_confirm')" type="password"
                                 icon="lock" autocomplete="new-password" minlength="8" reveal />
                    </div>

                    <button class="go" type="submit">{{ __('store.account.reset_submit') }}</button>
                </form>

                <p class="alt">{{ __('store.account.reset_signed_out_note') }}</p>
            @endif
        </div>
    </div>
</div>
@endsection
