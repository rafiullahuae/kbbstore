{{--
    Choose a new password.

    One template for both outcomes. A link that is spent, expired, forged or
    points at an id that does not exist all render the SAME sentence — see
    PasswordResetController::INVALID_MESSAGE. Splitting them into "expired" and
    "unknown account" would read better and would tell an attacker walking the
    ids which ones are real.
--}}
@extends('layouts.store')
@section('title', 'Set a new password')

@section('content')
@php use App\Support\Url; @endphp
@php $ap = app(\App\Services\AccountPanel::class); @endphp

<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid">
        <div class="authcard">
            <h1>Set a new password</h1>

            @if (! $valid)
                <div class="auth-err">{{ $message }}</div>
                <p class="alt"><a href="{{ Url::to('/my-account/forgot') }}">Request a new link</a></p>
            @else
                <p class="lede">Choose something you have not used here before. At least 8 characters.</p>

                @if ($errors->any())<div class="auth-err">{{ $errors->first() }}</div>@endif

                @if ($retiresLegacy)
                    {{--
                        Said out loud, before the fact. This account still signs
                        in with the password it had on the old WordPress site;
                        setting a new one retires that. A customer who was not
                        told would read the old password's next failure as the
                        reset not having worked.
                    --}}
                    <div class="auth-ok">Note: your original K Beauty Bliss password will stop working once you save this.</div>
                @endif

                <form method="post" action="{{ Url::to('/my-account/reset') }}">
                    @csrf
                    <input type="hidden" name="id" value="{{ $id }}">
                    <input type="hidden" name="token" value="{{ $token }}">

                    <div class="fgroup">
                        <x-field name="password" label="New password" type="password"
                                 icon="lock" autocomplete="new-password" minlength="8" reveal />
                        <x-field name="password_confirmation" label="Confirm new password" type="password"
                                 icon="lock" autocomplete="new-password" minlength="8" reveal />
                    </div>

                    <button class="go" type="submit">Save new password</button>
                </form>

                <p class="alt">Signing in everywhere else will be ended, so you will need to sign in again on your other devices.</p>
            @endif
        </div>
    </div>
</div>
@endsection
