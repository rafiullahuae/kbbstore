{{--
    The delivery road map itself (Quiqup P3), from App\Support\DeliveryTimeline.
    Also what the live refresh renders and swaps in, so the page and the
    refresh cannot draw it two ways. Every word is escaped ({{ }}); the only
    raw output is AccountIcons' constant SVG markup (with width/height).
--}}
<ol class="kdt-ol" aria-label="{{ __('store.delivery.timeline_label') }}">
@foreach ($tl['steps'] as $kdtStep)
<li class="kdt-s {{ $kdtStep['state'] }}"@if (str_contains($kdtStep['state'], 'now')) aria-current="step"@endif><span class="kdt-dot">{!! \App\Support\AccountIcons::svg($kdtStep['state'] === 'done' ? 'check' : $kdtStep['icon'], 14) !!}</span><span class="kdt-t"><b>{{ $kdtStep['label'] }}</b>@if ($kdtStep['at'])<em>{{ $kdtStep['at'] }}</em>@endif @if (str_contains($kdtStep['state'], 'now') && $tl['window_local'])<small>{{ $tl['window_local'] }}</small>@endif</span></li>
@endforeach
</ol>
@if ($tl['track_url'])
<a class="kdt-live" href="{{ $tl['track_url'] }}" target="_blank" rel="noopener noreferrer">{!! \App\Support\AccountIcons::svg('pin', 16) !!}{{ __('store.delivery.live_view') }}</a>
@endif
