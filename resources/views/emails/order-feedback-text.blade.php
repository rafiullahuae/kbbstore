{!! $heading !!}

{!! wordwrap($body, 72) !!}

{!! mb_strtoupper(__('email.feedback.items_heading')) !!}
@foreach ($products as $p)
- {!! $p['brand'] !== '' ? $p['brand'] . ' — ' : '' !!}{!! $p['name'] !!}
  {!! __('email.feedback.button') !!}: {!! $p['url'] !!}
@endforeach

{!! wordwrap(__('email.feedback.closing'), 72) !!}

@include('emails.partials.support-text')
