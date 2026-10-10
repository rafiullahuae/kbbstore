{{-- name · city, verified, date --}}
<span class="rs-who"><b dir="auto">{{ $c['name'] }}</b>@if ($c['city'] !== '')<span>{{ $c['city'] }}</span>@endif @if ($c['verified'])@include('partials.review-showcase._vf')@endif @if (($date ?? true) && $c['date'] !== '')<time datetime="{{ $c['iso'] }}">{{ $c['date'] }}</time>@endif</span>
