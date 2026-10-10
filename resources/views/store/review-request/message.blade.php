@extends('layouts.store')
@php $seoCtx = ['noindex' => true, 'url' => \App\Support\Url::to('/review-order/')]; @endphp
@section('title', $title)

@section('content')
{{--
    One message, one card (Lane RV): the "not valid" page — which a forged
    token, a token never issued and an expired one all get, word for word —
    and the honeypot's, the throttle's and the closed form's answers. Nothing
    here says which of those it is, or anything about the order.
--}}
<div class="auth">
    <div class="auth-grid">
        <div class="authcard">
            <h1>{{ $title }}</h1>
            <p class="lede">{{ $lead }}</p>
            <a class="btn btn-primary" href="{{ \App\Support\Url::to('/shop/') }}" data-no-prefetch>{{ __('store.review_request.thanks_shop') }}</a>
        </div>
    </div>
</div>
@endsection
