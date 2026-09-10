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
                <b @class(['ap-greet', 'ap-' . $a['welcome_style'] => $animate])>{{ auth()->user()->name ?? 'My account' }}</b>

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
        <a class="acct-it" href="{{ Url::to('/my-account/') }}">My account</a>
        @if ($a['link_orders'])<a class="acct-it" href="{{ Url::to('/my-account/orders/') }}">Orders</a>@endif
        @if ($a['link_wishlist'])<a class="acct-it" href="{{ Url::to('/my-wishlist/') }}">Wishlist</a>@endif
        @if ($a['link_address'])<a class="acct-it" href="{{ Url::to('/my-account/edit-address/') }}">Addresses</a>@endif
        @if ($a['link_track'])<a class="acct-it" href="{{ Url::to('/track-my-order/') }}">Track my order</a>@endif
        <form method="post" action="{{ Url::to('/my-account/logout') }}">@csrf
            <button class="acct-out" type="submit">Sign out</button>
        </form>
    @else
        @if ($errors->any())
            <div class="acct-err">{{ $errors->first() }}</div>
        @endif

        <div class="acct-tabs">
            <button class="acct-tab on" type="button" data-acct-tab="in">Sign in</button>
            <button class="acct-tab" type="button" data-acct-tab="up">Create account</button>
        </div>

        <form class="acct-form on" method="post" action="{{ Url::to('/my-account/login') }}" data-acct-pane="in">
            @csrf
            <label>Email<input type="email" name="email" required autocomplete="email"></label>
            <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
            <div class="acct-row">
                <label class="acct-check"><input type="checkbox" name="remember" value="1"> Stay signed in</label>
                <a class="acct-link" href="{{ Url::to('/my-account/forgot/') }}">Forgotten?</a>
            </div>
            <button class="acct-go" type="submit">Sign in</button>
        </form>

        <form class="acct-form" method="post" action="{{ Url::to('/my-account/register') }}" data-acct-pane="up">
            @csrf
            <label>Name<input type="text" name="name" required autocomplete="name"></label>
            <label>Email<input type="email" name="email" required autocomplete="email"></label>
            <label>Password<input type="password" name="password" required autocomplete="new-password" minlength="8"></label>
            {{-- The register endpoint validates password with `confirmed`, so this
                 field is required for the form to succeed at all. --}}
            <label>Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password" minlength="8"></label>

            @if ($hc)
                {{-- The answer lives in the session against this token, never in the page. --}}
                <input type="hidden" name="hc_token" value="{{ $hc['token'] }}" data-hc-token>
                <label class="acct-sum">{{ $a['sum_label'] }} <b data-hc-q>{{ $hc['question'] }}</b>?
                    <input type="text" name="hc_answer" inputmode="numeric" autocomplete="off" required>
                </label>
            @endif

            <button class="acct-go" type="submit">Create account</button>
            @if ($a['terms_show'])
            <p class="acct-fine">By creating an account you agree to our
                <a href="{{ Url::to('/terms-and-conditions/') }}">terms</a> and
                <a href="{{ Url::to('/privacy-policy/') }}">privacy policy</a>.</p>
            @endif
        </form>
    @endauth
</div>
