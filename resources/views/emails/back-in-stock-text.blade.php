{!! __('email.greeting.hello') !!}

{{ $body }}

{{ $productName }}
{{ $productUrl }}

{!! wordwrap(__('email.back_in_stock.why_text'), 78) !!}

- {{ $brand['storeName'] ?? \App\Support\BrandName::appName() }}

{!! __('email.text.unsubscribe_here_like_this') !!}
{{ $unsubscribeUrl }}
