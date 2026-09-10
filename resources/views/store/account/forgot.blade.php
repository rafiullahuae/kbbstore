@extends('layouts.store')
@section('title', 'Reset password')

@section('content')
@php use App\Support\Url; @endphp

@php $ap = app(\App\Services\AccountPanel::class); @endphp
<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid">
        <div class="authcard">
            <h1>Reset your password</h1>
            <p class="lede">Enter your email and we will send you a link to set a new one.</p>

            @if ($errors->any())<div class="auth-err">{{ $errors->first() }}</div>@endif
            @if (session('status'))<div class="auth-ok">{{ session('status') }}</div>@endif

            <form method="post" action="{{ Url::to('/my-account/forgot') }}">
                @csrf
                <div class="fgroup">
                    <x-field name="email" label="Email address" type="email" icon="mail" autocomplete="email" />
                </div>
                <button class="go" type="submit">Send the link</button>
            </form>

            <p class="alt"><a href="{{ Url::to('/my-account/') }}">Back to sign in</a></p>
        </div>
    </div>
</div>
@endsection
