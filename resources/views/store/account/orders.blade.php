{{--
    The customer's own orders, newest first, paginated (Lane ACC redesign).

    $orders is a LengthAwarePaginator of the signed-in shopper's orders and
    nothing else -- see AccountController::orders(), which also applies the
    search ($q: order number or a product on the order) and the date ($when,
    one of $choices). Each order is a row with a coloured status strip and its
    photos (partials.account.order-row); two side by side on a computer.

    The search is a plain GET form: it works with no script, the back button
    returns to the same results, and the pager keeps them (withQueryString).
    Changing the date submits the form by itself; the Search button does the
    same for anyone who prefers it. Pagination is drawn by hand, as before:
    $orders->links() would emit Laravel's Tailwind partial into a stylesheet
    with no Tailwind in it.
--}}
@extends('layouts.store')
@php use App\Support\AccountIcons; use App\Support\Url; @endphp
@section('title', __('store.orders.page_title'))

@section('content')
@php
    $groups = ['quick' => [], 'month' => [], 'year' => []];
    foreach ($choices as $key => $choice) {
        $groups[$choice['group']][$key] = $choice['label'];
    }
    $chips = ['' => __('store.orders.when_any')] + $groups['quick'] + array_slice($groups['year'], 0, 3, true);
    $chipUrl = static fn (string $key): string => Url::to('/my-account/orders/') . ($key === '' && $q === '' ? '' : '?' . http_build_query(array_filter(['q' => $q, 'when' => $key], static fn ($v) => $v !== '')));
@endphp
<div class="acw wide kac">
    @include('partials.account.chrome', ['kacTitle' => __('store.account.nav_orders'), 'kacBack' => '/my-account/', 'kacBackLabel' => __('store.account.to_dashboard'), 'kacActive' => 'orders'])

    <form class="kac-filter" method="get" action="{{ Url::to('/my-account/orders/') }}" role="search" aria-label="{{ __('store.orders.filter_label') }}">
        <label class="kac-search">{!! AccountIcons::svg('search', 18) !!}<input type="search" name="q" value="{{ $q }}" maxlength="60" placeholder="{{ __('store.orders.search_placeholder') }}" aria-label="{{ __('store.orders.search_placeholder') }}" autocomplete="off" enterkeyhint="search"></label>
        <select class="kac-when" name="when" aria-label="{{ __('store.orders.when_label') }}" onchange="this.form.submit()">
            <option value=""{{ $when === '' ? ' selected' : '' }}>{{ __('store.orders.when_any') }}</option>
            @foreach ($groups['quick'] as $key => $label)<option value="{{ $key }}"{{ $when === $key ? ' selected' : '' }}>{{ $label }}</option>@endforeach
            @foreach ($groups['month'] as $key => $label)<option value="{{ $key }}"{{ $when === $key ? ' selected' : '' }}>{{ $label }}</option>@endforeach
            @foreach ($groups['year'] as $key => $label)<option value="{{ $key }}"{{ $when === (string) $key ? ' selected' : '' }}>{{ $label }}</option>@endforeach
        </select>
        <button class="kac-go" type="submit">{{ __('store.orders.search_button') }}</button>
    </form>
    <div class="kac-chips">
        @foreach ($chips as $key => $label)
            <a class="kac-chip{{ $when === (string) $key ? ' on' : '' }}" href="{{ $chipUrl((string) $key) }}"@if ($when === (string) $key) aria-current="true"@endif>{{ $label }}</a>
        @endforeach
    </div>

    @if ($orders->isEmpty())
        <div class="kac-card">
            @if ($filtered)
                <p class="kac-empty">{{ __('store.orders.no_match') }} <a href="{{ Url::to('/my-account/orders/') }}">{{ __('store.orders.clear_filters') }}</a></p>
            @else
                <p class="kac-empty">{{ __('store.account.no_orders_yet') }} <a href="{{ Url::to('/shop/') }}">{{ __('store.account.start_shopping') }}</a></p>
            @endif
        </div>
    @else
        <div class="kac-rows">
            @foreach ($orders as $order)
                @include('partials.account.order-row', ['order' => $order])
            @endforeach
        </div>

        @if ($orders->hasPages())
            <nav class="kac-pager" aria-label="{{ __('store.orders.pager_label') }}">
                <a class="kac-step {{ $orders->onFirstPage() ? 'is-off' : '' }}" href="{{ $orders->previousPageUrl() ?: '#' }}"@if ($orders->onFirstPage()) aria-disabled="true" tabindex="-1"@endif>‹ {{ __('store.orders.pager_newer') }}</a>
                <span class="kac-page">{{ __('store.orders.pager_page', ['current' => $orders->currentPage(), 'total' => $orders->lastPage()]) }}</span>
                <a class="kac-step {{ $orders->hasMorePages() ? '' : 'is-off' }}" href="{{ $orders->nextPageUrl() ?: '#' }}"@unless ($orders->hasMorePages()) aria-disabled="true" tabindex="-1"@endunless>{{ __('store.orders.pager_older') }} ›</a>
            </nav>
        @endif
    @endif
</div>
@endsection
