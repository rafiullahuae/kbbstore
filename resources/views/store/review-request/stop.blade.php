@extends('layouts.store')
@php $seoCtx = ['noindex' => true, 'url' => \App\Support\Url::to('/review-order/')]; @endphp
@section('title', __('store.review_request.stop_title'))

@section('content')
{{--
    "Unsubscribe from review request emails" (Lane RV). GET renders and
    changes nothing — mail scanners open every link in a message — and the
    button POSTs to /review-order/stop/ with the token from the session. The
    mail client's RFC 8058 one-click POST goes to the token's own address.
    The same page for every token, valid or not, and the address is never
    printed: the holder of this link is not necessarily its owner.
--}}
<div class="auth">
    <div class="auth-grid">
        <div class="authcard">
            @if ($done)
                <h1>{{ __('store.review_request.stop_done_title') }}</h1>
                <p class="lede">{{ __('store.review_request.stop_done_lead') }}</p>
            @else
                <h1>{{ __('store.review_request.stop_title') }}</h1>
                <p class="lede">{{ __('store.review_request.stop_lead') }}</p>
                <form method="post" action="{{ \App\Support\Url::to('/review-order/stop/') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">{{ __('store.review_request.stop_button') }}</button>
                </form>
            @endif
            <p class="muted" style="margin-top:16px;font-size:13px;">{{ __('store.review_request.stop_note') }}</p>
        </div>
    </div>
</div>
@endsection
