{!! $heading !!}

{!! wordwrap($body, 72) !!}

@foreach ($products as $p)
{!! $p['brand'] !== '' ? $p['brand'] . ' — ' : '' !!}{!! $p['name'] !!}{!! $p['variant'] !== '' ? ' (' . $p['variant'] . ')' : '' !!}
@foreach ($p['stars'] as $n => $href)
  {!! str_repeat('*', $n + 1) !!} {!! $href !!}
@endforeach

@endforeach
{!! __('email.review_request.button') !!}: {!! $pageUrl !!}

{!! wordwrap(__('email.review_request.note', ['days' => $linkDays]), 72) !!}

{!! wordwrap(__('email.review_request.closing'), 72) !!}

{!! __('email.review_request.signoff_thanks') !!}
{!! __('email.review_request.signoff_team', ['store' => $brand['storeName'] ?? config('app.name')]) !!}

--
{!! __('email.review_request.why', ['store' => $brand['storeName'] ?? config('app.name')]) !!}
{!! __('email.review_request.unsubscribe') !!}: {!! $stopUrl !!}
