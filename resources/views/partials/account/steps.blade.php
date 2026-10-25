{{--
    The four steps of an order (Lane ACC): Placed, Confirmed, Shipped,
    Delivered, from App\Support\OrderProgress::steps(). Done steps are green
    with a tick, the step in progress has a ring (amber when the order waits on
    payment or is on hold), the rest are grey. A date shows only where the
    order recorded one. An ordered list, so a screen reader hears the sequence.
--}}
@php
    $kacStepIcons = ['placed' => 'bag', 'confirmed' => 'pack', 'shipped' => 'truck', 'delivered' => 'home'];
    // Whole keys, so StorefrontStringsAreKeyedTest can see every one is defined.
    $kacStepNames = ['placed' => __('store.account.step_placed'), 'confirmed' => __('store.account.step_confirmed'), 'shipped' => __('store.account.step_shipped'), 'delivered' => __('store.account.step_delivered')];
@endphp
<ol class="kac-steps" aria-label="{{ __('store.account.steps_label') }}">
    @foreach ($steps as $step)
        <li class="{{ $step['state'] }}"@if ($step['state'] === 'now' || $step['state'] === 'hold') aria-current="step"@endif><span class="kac-dot">{!! \App\Support\AccountIcons::svg($step['state'] === 'done' ? 'check' : $kacStepIcons[$step['key']], 14) !!}</span>{{ $kacStepNames[$step['key']] }}@if ($step['date'])<em>{{ $step['date'] }}</em>@endif</li>
    @endforeach
</ol>
