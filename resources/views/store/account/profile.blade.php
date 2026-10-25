{{--
    MY ACCOUNT -> PROFILE & PASSWORD (Lane ACC). Two cards: her details (first
    name, last name, mobile; the email shown but not editable) and Change
    password (current, new, confirm). On a computer they sit side by side. The
    dashboard's "Change password" row links to #password, which this page
    scrolls to by itself (a plain anchor, no script).

    Every value printed is escaped. The forms post to
    AccountProfileController with the CSRF token; errors come back under the
    field that caused them, and a saved form says so at the top of its card.
    A customer with no password at all (an account made at checkout) is sent
    to the existing "set a password by email" page instead of being asked for
    a current password she never had.
--}}
@extends('layouts.store')
@section('title', __('store.profile.page_title'))

@section('content')
@php use App\Support\AccountIcons; use App\Support\Url; $saved = session('kac_saved'); @endphp
<div class="acw wide kac">
    @include('partials.account.chrome', ['kacTitle' => __('store.profile.heading'), 'kacBack' => '/my-account/', 'kacBackLabel' => __('store.account.to_dashboard'), 'kacActive' => 'profile'])

    <div class="kac-stack two">
        <section class="kac-card" aria-labelledby="kacDetails">
            <h2 id="kacDetails">{!! AccountIcons::svg('user', 18) !!}{{ __('store.profile.details_heading') }}</h2>
            @if ($saved === 'details')<p class="kac-ok" role="status">{!! AccountIcons::svg('check', 18) !!}{{ __('store.profile.saved') }}</p>@endif
            <form class="kac-form" method="post" action="{{ Url::to('/my-account/profile') }}" novalidate>
                @csrf
                <div class="kac-grid2">
                    <label class="kac-f">{{ __('store.profile.first_name') }}
                        <input type="text" name="first_name" value="{{ old('first_name', (string) $customer->first_name ?: strtok($customer->displayName(), ' @')) }}" maxlength="60" autocomplete="given-name" required>
                        @error('first_name')<span class="kac-err">{{ $message }}</span>@enderror
                    </label>
                    <label class="kac-f">{{ __('store.profile.last_name') }}
                        <input type="text" name="last_name" value="{{ old('last_name', (string) $customer->last_name) }}" maxlength="60" autocomplete="family-name">
                        @error('last_name')<span class="kac-err">{{ $message }}</span>@enderror
                    </label>
                </div>
                <label class="kac-f">{{ __('store.profile.phone') }}
                    <input type="tel" name="phone" value="{{ old('phone', (string) $customer->phone) }}" maxlength="24" autocomplete="tel" inputmode="tel" dir="ltr">
                    @error('phone')<span class="kac-err">{{ $message }}</span>@enderror
                </label>
                <label class="kac-f">{{ __('store.profile.email') }}
                    <input type="email" value="{{ $customer->email }}" readonly aria-readonly="true" dir="ltr">
                </label>
                <p class="kac-note">{{ __('store.profile.email_note') }}</p>
                <div><button class="kac-btn p" type="submit">{{ __('store.profile.save') }}</button></div>
            </form>
        </section>

        <section class="kac-card" id="password" aria-labelledby="kacPassword">
            <h2 id="kacPassword">{!! AccountIcons::svg('lock', 18) !!}{{ __('store.profile.password_heading') }}</h2>
            @if ($saved === 'password')<p class="kac-ok" role="status">{!! AccountIcons::svg('check', 18) !!}{{ __('store.profile.password_saved') }}</p>@endif
            @if ($hasPassword)
            <form class="kac-form" method="post" action="{{ Url::to('/my-account/profile/password') }}" novalidate>
                @csrf
                <label class="kac-f">{{ __('store.profile.current_password') }}
                    <input type="password" name="current_password" autocomplete="current-password" maxlength="255" required>
                    @error('current_password')<span class="kac-err">{{ $message }}</span>@enderror
                </label>
                <div class="kac-grid2">
                    <label class="kac-f">{{ __('store.profile.new_password') }}
                        <input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="255" required>
                        @error('password')<span class="kac-err">{{ $message }}</span>@enderror
                    </label>
                    <label class="kac-f">{{ __('store.profile.confirm_password') }}
                        <input type="password" name="password_confirmation" autocomplete="new-password" minlength="8" maxlength="255" required>
                    </label>
                </div>
                <p class="kac-note">{{ __('store.profile.password_hint') }}</p>
                <div><button class="kac-btn p" type="submit">{{ __('store.profile.password_save') }}</button></div>
            </form>
            @else
            <p class="kac-note" style="font-size:13.5px;margin:6px 0 12px">{{ __('store.profile.no_password') }}</p>
            <a class="kac-btn p" href="{{ Url::to('/my-account/forgot/') }}">{{ __('store.profile.no_password_link') }}</a>
            @endif
        </section>
    </div>
</div>
@endsection
