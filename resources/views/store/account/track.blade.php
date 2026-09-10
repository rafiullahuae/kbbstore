@extends('layouts.store')
@section('title', 'Track my order')

@section('content')
@php use App\Support\Url; @endphp

@php $ap = app(\App\Services\AccountPanel::class); @endphp
<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid">
        <div class="authcard">
            <h1>Track my order</h1>
            <p class="lede">Your order number is in your confirmation email.</p>

            <form method="get" action="{{ Url::to('/track-my-order/') }}">
                <div class="fgroup">
                    <x-field name="order" label="Order number" :value="request('order')" />
                    <x-field name="email" label="Email address" type="email"
                             :value="request('email')" icon="mail" autocomplete="email" />
                </div>
                <button class="go" type="submit">Find my order</button>
            </form>
        </div>
    </div>
</div>
@endsection
