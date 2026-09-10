@extends('layouts.store')
@section('title', 'Addresses')

@section('content')
@php use App\Support\Url; @endphp
<div class="acw wide">
  <div class="acw-in">
    <h1>Addresses</h1><p class="acw-sub">Where we deliver.</p>
    <p class="acw-empty">No addresses saved yet. One will be kept from your next order.</p>
  </div>
</div>
@endsection
