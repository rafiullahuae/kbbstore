{{--
    rj-email-kit.cjs header('A'): the logo slot on a soft pink band, the
    shop's three links, and the 3px accent rule under it.

    The uploaded logo when there is one whose size can be read (MailKit::for
    declines a logo it cannot give a height); the header's own two-part
    wordmark otherwise — which every client shows with images blocked.
    @include('emails.kit.header', ['nav' => false]) drops the links, as the
    kit does for the account-security emails.

    Directives sit hard against markup on purpose: a space between two inline
    links is a visible gap the approved header does not have.
--}}
<tr><td align="center" bgcolor="#FFF0F4" class="soft" style="background:#FFF0F4;padding:26px 24px 20px;border-radius:18px 18px 0 0;font-family:{!! $k['sans'] !!};">@if ($k['logo'] !== null)<img src="{{ $k['logo'][0] }}" width="{{ $k['logo'][1] }}" height="{{ $k['logo'][2] }}" alt="{{ $k['storeName'] }}" style="display:block;width:170px;max-width:170px;height:auto;margin:0 auto;">@else<div class="wm-ink" style="font-family:{!! $k['sans'] !!};font-size:26px;font-weight:800;letter-spacing:-.02em;color:#2A2228;">{{ $k['wordmark'][0] }}<span style="color:#C13E63;">{{ $k['wordmark'][1] }}</span></div>@endif{{-- --}}@if ($nav ?? true)<div style="margin-top:12px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;">
@foreach ($k['nav'] as [$navLabel, $navUrl])@if (! $loop->first)<span style="color:{!! $k['accent'] !!};">&nbsp;&nbsp;&bull;&nbsp;&nbsp;</span>@endif<a class="ink2" href="{{ $navUrl }}" style="color:#5E545A;font-weight:600;">{{ $navLabel }}</a>@endforeach</div>@endif</td></tr>
<tr><td bgcolor="{!! $k['accent'] !!}" style="background:{!! $k['accent'] !!};height:3px;line-height:3px;font-size:0;">&nbsp;</td></tr>
