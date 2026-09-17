{{--
    The page a confirmation link lands on.

    Public, and rendered identically for every kind of failure: unknown id,
    wrong address digest, bad signature, expired link. The id in the URL is a
    small integer, so a page that distinguished "no such customer" from "bad
    signature" would be a customer-count oracle for anyone willing to count.
--}}
@extends('layouts.store')
@section('title', $ok ? __('store.verify.result_ok_title') : __('store.verify.result_bad_title'))

@section('content')
@php use App\Support\Url; @endphp
@php $ap = app(\App\Services\AccountPanel::class); @endphp

<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid">
        <div class="authcard">
            <h1>{{ $ok ? __('store.verify.result_ok_title') : __('store.verify.result_bad_heading') }}</h1>

            @if ($ok)
                <div class="auth-ok">{{ $message }}</div>
            @else
                <div class="auth-err">{{ $message }}</div>
            @endif

            <p class="alt"><a href="{{ Url::to('/my-account/') }}">{{ __('store.verify.go_to_account') }}</a></p>
        </div>
    </div>
</div>
@endsection
