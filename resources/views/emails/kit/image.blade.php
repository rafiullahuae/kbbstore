{{--
    rj-email-kit.cjs heroImage(): a picture across the card (Lane EK, for the
    builder's Hero image block). $src an absolute URL MailKit::image() built,
    $alt plain text, $href null or a URL MailKit::url() checked, $bleed true
    for edge to edge (the approved m1 campaign), false for the padded card
    width; $h the height to declare at 600 wide (every <img> in the kit
    declares both).
--}}
<tr><td style="padding:{{ ($bleed ?? true) ? '0' : '18px 32px 0' }};">@if (($href ?? null) !== null)<a href="{{ $href }}" style="text-decoration:none;">@endif<img src="{{ $src }}" width="600" height="{{ (int) ($h ?? 300) }}" alt="{{ $alt }}" style="display:block;width:100%;max-width:600px;height:auto;border:0;{{ ($bleed ?? true) ? '' : 'border-radius:14px;' }}">@if (($href ?? null) !== null)</a>@endif</td></tr>
