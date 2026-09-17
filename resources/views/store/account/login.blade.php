@extends('layouts.store')
@section('title', 'login' === $tab ? __('store.account.sign_in_title') : __('store.account.register_title'))

@section('content')
@php
    use App\Support\Url;
    $ap = app(\App\Services\AccountPanel::class);

    /*
     * THE THRESHOLD IS THE OWNER'S, NOT THIS TEMPLATE'S — Lane FB.
     *
     * This page used to state "Free delivery on orders over د.إ150." as a
     * literal, and 150 was not a number this shop has ever run: the
     * free-shipping method carries 19900, and the announcement bar, the home
     * ticker, the trust row and window.KBB.freeShip all say 199. The sign-in
     * page contradicted the rest of the shop, on the screen that asks for a
     * password.
     *
     * RESOLVED HERE RATHER THAN READ OFF $kbbFreeShipThreshold, which is the
     * variable every other surface uses. StoreComposer is bound to
     * 'layouts.store' (ViewServiceProvider), and Blade renders a child's
     * sections BEFORE the layout it @extends, so that variable is genuinely not
     * in scope in this file — taking it would have been an undefined-variable
     * error under a name that looks correct. thresholdHere() is what the
     * composer itself calls, and it memoises per request on the Request, so
     * asking it directly costs nothing.
     */
    $kbbSignInFreeShip = app(\App\Services\ShippingService::class)->thresholdHere();
@endphp

<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid @if ($panel['form_aside']) with-aside @endif">
        <div class="authcard">
            @if ($panel['form_mark'])<span class="mark">KB</span>@endif

            <h1>{{ 'login' === $tab ? __('store.account.sign_in_heading') : __('store.account.register_heading') }}</h1>
            <p class="lede">{{ 'login' === $tab
                ? __('store.account.sign_in_lead')
                : __('store.account.register_lead') }}</p>

            @if ($errors->any())<div class="auth-err">{{ $errors->first() }}</div>@endif
            @if (session('status'))<div class="auth-ok">{{ session('status') }}</div>@endif

            <div class="segs">
                <a class="seg {{ 'login' === $tab ? 'on' : '' }}" href="{{ Url::to('/my-account/') }}">{{ __('store.account_panel.tab_sign_in') }}</a>
                <a class="seg {{ 'register' === $tab ? 'on' : '' }}" href="{{ Url::to('/my-account/') }}?tab=register">{{ __('store.account_panel.tab_register') }}</a>
            </div>

            @if ('login' === $tab)
                <form method="post" action="{{ Url::to('/my-account/login') }}">
                    @csrf
                    <div class="fgroup">
                        <x-field name="email" :label="__('store.checkout.field_email')" type="email"
                                 :value="old('email')" icon="mail" autocomplete="email" />
                        <x-field name="password" :label="__('store.account_panel.field_password')" type="password"
                                 icon="lock" autocomplete="current-password" reveal />
                    </div>

                    <div class="row">
                        <label class="chk"><input type="checkbox" name="remember" value="1"><span></span>{{ __('store.account_panel.stay_signed_in') }}</label>
                        <a href="{{ Url::to('/my-account/forgot/') }}">{{ __('store.account.forgot_link') }}</a>
                    </div>

                    <button class="go" type="submit">{{ __('store.account_panel.sign_in_button') }}</button>
                </form>
                <p class="alt">{!! __('store.account.new_here', ['link' => '<a href="' . e(Url::to('/my-account/') . '?tab=register') . '">' . e(__('store.account.new_here_link')) . '</a>']) !!}</p>
            @else
                <form method="post" action="{{ Url::to('/my-account/register') }}">
                    @csrf
                    <div class="fgroup">
                        <x-field name="name" :label="__('store.checkout.field_full_name')" :value="old('name')" icon="user" autocomplete="name" />
                        <x-field name="email" :label="__('store.checkout.field_email')" type="email" :value="old('email')" icon="mail" autocomplete="email" />
                        <x-field name="password" :label="__('store.account_panel.field_password')" type="password" icon="lock"
                                 autocomplete="new-password" minlength="8" reveal />
                        <x-field name="password_confirmation" :label="__('store.account_panel.field_password_confirm')" type="password"
                                 icon="lock" autocomplete="new-password" minlength="8" />
                    </div>

                    @if ($panel['form_strength'])
                        {{-- The score is filled in as they type; it starts empty
                             rather than claiming a strength for a blank field. --}}
                        <div class="meter" data-score="0">
                            <span class="meter-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                            <span class="meter-label" role="status" aria-live="polite"></span>
                        </div>
                        <p class="hint">{{ __('store.account.password_hint') }}</p>
                    @endif

                    @if ($hc)
                        <div class="sum">
                            <span class="q">{{ $panel['sum_label'] }} <b>{{ $hc['question'] }}</b>?</span>
                            <input type="hidden" name="hc_token" value="{{ $hc['token'] }}">
                            <div class="fld tiny">
                                <input type="text" name="hc_answer" id="f-hc" placeholder=" "
                                       inputmode="numeric" autocomplete="off" required>
                                <label for="f-hc">{{ __('store.reviews.field_captcha_placeholder') }}</label>
                            </div>
                        </div>
                    @endif

                    <button class="go" type="submit">{{ __('store.account_panel.register_button') }}</button>

                    @if ($panel['terms_show'])
                        <p class="alt">{!! __('store.account.terms_notice', [
                            'terms' => '<a href="' . e(Url::to('/terms-and-conditions/')) . '">' . e(__('store.account_panel.terms_link')) . '</a>',
                            'privacy' => '<a href="' . e(Url::to('/privacy-policy/')) . '">' . e(__('store.account_panel.privacy_link')) . '</a>',
                        ]) !!}</p>
                    @endif
                </form>
                <p class="alt">{!! __('store.account.have_account', ['link' => '<a href="' . e(Url::to('/my-account/')) . '">' . e(__('store.account_panel.tab_sign_in')) . '</a>']) !!}</p>
            @endif
        </div>

        @if ($panel['form_aside'])
            <aside class="authside">
                <h2>{{ __('store.account.aside_heading') }}</h2>
                <ul>
                    <li>{{ __('store.account.aside_point_orders') }}</li>
                    <li>{{ __('store.account.aside_point_checkout') }}</li>
                    <li>{{ __('store.account.aside_point_wishlist') }}</li>
                    <li>{{ __('store.account.aside_point_restocks') }}</li>
                </ul>
                {{-- null when this shopper's zone has no free-delivery method at
                     all. Unguarded, the sentence renders as "Free delivery on
                     orders over ." — a promise with its number missing, which
                     still reads as a promise. Same guard as
                     partials/announcement.blade.php. --}}
                @if ($kbbSignInFreeShip !== null)
                    <p class="authside-note">{!! __('store.account.aside_free_delivery', ['amount' => \App\Support\Money::format($kbbSignInFreeShip, 0)]) !!}</p>
                @endif
            </aside>
        @endif
    </div>
</div>
@endsection
