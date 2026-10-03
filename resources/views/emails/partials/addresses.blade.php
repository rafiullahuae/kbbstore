{{--
    The shop's addresses in the email small print (Lane RK, package E1).
    Rendered by emails/layout.blade.php only when at least one is saved; see the
    note there for why it is rendered into a variable rather than looped in
    place. Every line is operator text and goes through {{ }}; the <br>s are
    this template's own. One line, no surrounding whitespace, so the footer
    sentence it follows keeps its exact shape.
--}}
@foreach ($addresses as $place)<div style="margin-top:{{ $loop->first ? '12px' : '8px' }};"><span style="font-weight:700;color:{{ $c['ink2'] }};">{{ __('email.layout.address_' . $place['place']) }}</span>@foreach ($place['lines'] as $line)<br>{{ $line }}@endforeach</div>@endforeach
