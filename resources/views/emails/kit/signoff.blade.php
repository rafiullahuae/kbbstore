{{-- rj-email-kit.cjs signoff(): the owner's sign-off (EmailBranding::signature()), one escaped line per <br>. --}}
@if (($signoff ?? $k['signature']) !== [])
<tr><td class="px" style="padding:26px 32px 30px;font-family:{!! $k['sans'] !!};"><div class="ink" style="font-family:{!! $k['sans'] !!};font-size:15.5px;line-height:1.55;color:{{ $k['text'] }};font-weight:500;">@foreach ($signoff ?? $k['signature'] as $soLine){{ $soLine }}@if (! $loop->last)<br>@endif
@endforeach</div></td></tr>
@endif
