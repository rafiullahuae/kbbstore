@extends('layouts.store')
@section('title', 'login' === $tab ? 'Sign in' : 'Create account')

@section('content')
@php
    use App\Support\Url;
    $ap = app(\App\Services\AccountPanel::class);
@endphp

<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid @if ($panel['form_aside']) with-aside @endif">
        <div class="authcard">
            @if ($panel['form_mark'])<span class="mark">KB</span>@endif

            <h1>{{ 'login' === $tab ? 'Welcome back' : 'Create your account' }}</h1>
            <p class="lede">{{ 'login' === $tab
                ? 'Sign in to see your orders and wishlist.'
                : 'It takes about a minute.' }}</p>

            @if ($errors->any())<div class="auth-err">{{ $errors->first() }}</div>@endif
            @if (session('status'))<div class="auth-ok">{{ session('status') }}</div>@endif

            <div class="segs">
                <a class="seg {{ 'login' === $tab ? 'on' : '' }}" href="{{ Url::to('/my-account/') }}">Sign in</a>
                <a class="seg {{ 'register' === $tab ? 'on' : '' }}" href="{{ Url::to('/my-account/') }}?tab=register">Create account</a>
            </div>

            @if ('login' === $tab)
                <form method="post" action="{{ Url::to('/my-account/login') }}">
                    @csrf
                    <div class="fgroup">
                        <x-field name="email" label="Email address" type="email"
                                 :value="old('email')" icon="mail" autocomplete="email" />
                        <x-field name="password" label="Password" type="password"
                                 icon="lock" autocomplete="current-password" reveal />
                    </div>

                    <div class="row">
                        <label class="chk"><input type="checkbox" name="remember" value="1"><span></span>Stay signed in</label>
                        <a href="{{ Url::to('/my-account/forgot/') }}">Forgot password?</a>
                    </div>

                    <button class="go" type="submit">Sign in</button>
                </form>
                <p class="alt">New here? <a href="{{ Url::to('/my-account/') }}?tab=register">Create an account</a></p>
            @else
                <form method="post" action="{{ Url::to('/my-account/register') }}">
                    @csrf
                    <div class="fgroup">
                        <x-field name="name" label="Full name" :value="old('name')" icon="user" autocomplete="name" />
                        <x-field name="email" label="Email address" type="email" :value="old('email')" icon="mail" autocomplete="email" />
                        <x-field name="password" label="Password" type="password" icon="lock"
                                 autocomplete="new-password" minlength="8" reveal />
                        <x-field name="password_confirmation" label="Confirm password" type="password"
                                 icon="lock" autocomplete="new-password" minlength="8" />
                    </div>

                    @if ($panel['form_strength'])
                        {{-- The score is filled in as they type; it starts empty
                             rather than claiming a strength for a blank field. --}}
                        <div class="meter" data-score="0">
                            <span class="meter-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                            <span class="meter-label" role="status" aria-live="polite"></span>
                        </div>
                        <p class="hint">Eight characters or more, with a mix of letters and numbers.</p>
                    @endif

                    @if ($hc)
                        <div class="sum">
                            <span class="q">{{ $panel['sum_label'] }} <b>{{ $hc['question'] }}</b>?</span>
                            <input type="hidden" name="hc_token" value="{{ $hc['token'] }}">
                            <div class="fld tiny">
                                <input type="text" name="hc_answer" id="f-hc" placeholder=" "
                                       inputmode="numeric" autocomplete="off" required>
                                <label for="f-hc">Answer</label>
                            </div>
                        </div>
                    @endif

                    <button class="go" type="submit">Create account</button>

                    @if ($panel['terms_show'])
                        <p class="alt">By continuing you agree to our
                            <a href="{{ Url::to('/terms-and-conditions/') }}">terms</a> and
                            <a href="{{ Url::to('/privacy-policy/') }}">privacy policy</a>.</p>
                    @endif
                </form>
                <p class="alt">Already have an account? <a href="{{ Url::to('/my-account/') }}">Sign in</a></p>
            @endif
        </div>

        @if ($panel['form_aside'])
            <aside class="authside">
                <h2>Why an account</h2>
                <ul>
                    <li>Your orders and their status in one place</li>
                    <li>Checkout without retyping your address</li>
                    <li>Your wishlist kept across devices</li>
                    <li>Early access to restocks</li>
                </ul>
                <p class="authside-note">Free delivery on orders over د.إ150.</p>
            </aside>
        @endif
    </div>
</div>
@endsection
