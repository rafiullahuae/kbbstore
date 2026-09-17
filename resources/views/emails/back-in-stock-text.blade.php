Hello,

{{ $body }}

{{ $productName }}
{{ $productUrl }}

You asked to be told when this product came back in stock. This is that one
message - we will not email you about it again unless you ask us to.

- {{ $brand['storeName'] ?? config('app.name') }}

Never want email like this? Unsubscribe here:
{{ $unsubscribeUrl }}
