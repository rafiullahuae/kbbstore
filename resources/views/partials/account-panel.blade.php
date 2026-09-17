{{--
    The account panel.

    One panel serves both states. Signed in it lists the account pages; signed
    out it offers sign in and register side by side, with a small sum on the
    register side.

    It opens on hover on a pointer device and on tap on a phone, which is why
    the trigger is a button rather than a link — a link would navigate before
    the panel could open.
--}}
@php
    use App\Support\Url;
    $ap = app(\App\Services\AccountPanel::class);
    $a = $ap->all();
    $hc = $a['sum_show'] ? app(\App\Services\HumanCheck::class)->issue() : null;

    // Shown once per sign-in: the controller flashes this, and it is gone on
    // the next request whether or not anyone saw it.
    $greet = session('kbb.greet');
@endphp

<div class="acct" id="acctPanel" style="{{ $ap->cssVariables() }}" hidden>
    @auth
        @php $animate = $greet && $a['welcome_show']; @endphp

        <div class="acct-head">
            <span class="acct-av">{{ mb_strtoupper(mb_substr(auth()->user()->name ?? 'K', 0, 1)) }}</span>
            <span class="acct-who">
                {{-- The name animates where it will live anyway, then settles into
                     its normal colour, so nothing moves when the greeting ends. --}}
                <b @class(['ap-greet', 'ap-' . $a['welcome_style'] => $animate])>{{ auth()->user()->name ?? __('store.account_panel.default_name') }}</b>

                @if ($animate)
                    <span class="acct-sub">
                        <span class="ap-kick">{{ 'new' === $greet['kind'] ? $a['welcome_new'] : $a['welcome_back'] }}</span>
                        @if ($a['show_email'])<span class="ap-mail">{{ auth()->user()->email ?? '' }}</span>@endif
                    </span>
                @elseif ($a['show_email'])
                    <span>{{ auth()->user()->email ?? '' }}</span>
                @endif
            </span>
        </div>
        <a class="acct-it" href="{{ Url::to('/my-account/') }}">{{ __('store.account_panel.link_account') }}</a>
        @if ($a['link_orders'])<a class="acct-it" href="{{ Url::to('/my-account/orders/') }}">{{ __('store.account_panel.link_orders') }}</a>@endif
        @if ($a['link_wishlist'])<a class="acct-it" href="{{ Url::to('/my-wishlist/') }}">{{ __('store.account_panel.link_wishlist') }}</a>@endif
        @if ($a['link_address'])<a class="acct-it" href="{{ Url::to('/my-account/edit-address/') }}">{{ __('store.account_panel.link_addresses') }}</a>@endif
        @if ($a['link_track'])<a class="acct-it" href="{{ Url::to('/track-my-order/') }}">{{ __('store.account_panel.link_track') }}</a>@endif
        <form method="post" action="{{ Url::to('/my-account/logout') }}">@csrf
            <button class="acct-out" type="submit">{{ __('store.account_panel.sign_out') }}</button>
        </form>
    @else
        @if ($errors->any())
            <div class="acct-err">{{ $errors->first() }}</div>
        @endif

        <div class="acct-tabs">
            <button class="acct-tab on" type="button" data-acct-tab="in">{{ __('store.account_panel.tab_sign_in') }}</button>
            <button class="acct-tab" type="button" data-acct-tab="up">{{ __('store.account_panel.tab_register') }}</button>
        </div>

        <form class="acct-form on" method="post" action="{{ Url::to('/my-account/login') }}" data-acct-pane="in">
            @csrf
            <label>{{ __('store.account_panel.field_email') }}<input type="email" name="email" required autocomplete="email"></label>
            <label>{{ __('store.account_panel.field_password') }}<input type="password" name="password" required autocomplete="current-password"></label>
            <div class="acct-row">
                <label class="acct-check"><input type="checkbox" name="remember" value="1"> {{ __('store.account_panel.stay_signed_in') }}</label>
                <a class="acct-link" href="{{ Url::to('/my-account/forgot/') }}">{{ __('store.account_panel.forgotten') }}</a>
            </div>
            <button class="acct-go" type="submit">{{ __('store.account_panel.sign_in_button') }}</button>
        </form>

        <form class="acct-form" method="post" action="{{ Url::to('/my-account/register') }}" data-acct-pane="up">
            @csrf
            <label>{{ __('store.account_panel.field_name') }}<input type="text" name="name" required autocomplete="name"></label>
            <label>{{ __('store.account_panel.field_email') }}<input type="email" name="email" required autocomplete="email"></label>
            <label>{{ __('store.account_panel.field_password') }}<input type="password" name="password" required autocomplete="new-password" minlength="8"></label>
            {{-- The register endpoint validates password with `confirmed`, so this
                 field is required for the form to succeed at all. --}}
            <label>{{ __('store.account_panel.field_password_confirm') }}<input type="password" name="password_confirmation" required autocomplete="new-password" minlength="8"></label>

            @if ($hc)
                {{-- The answer lives in the session against this token, never in the page. --}}
                <input type="hidden" name="hc_token" value="{{ $hc['token'] }}" data-hc-token>
                <label class="acct-sum">{{ $a['sum_label'] }} <b data-hc-q>{{ $hc['question'] }}</b>?
                    <input type="text" name="hc_answer" inputmode="numeric" autocomplete="off" required>
                </label>
            @endif

            <button class="acct-go" type="submit">{{ __('store.account_panel.register_button') }}</button>
            @if ($a['terms_show'])
            <p class="acct-fine">{!! __('store.account_panel.terms_notice', [
                'terms' => '<a href="' . e(Url::to('/terms-and-conditions/')) . '">' . e(__('store.account_panel.terms_link')) . '</a>',
                'privacy' => '<a href="' . e(Url::to('/privacy-policy/')) . '">' . e(__('store.account_panel.privacy_link')) . '</a>',
            ]) !!}</p>
            @endif
        </form>
    @endauth
</div>
