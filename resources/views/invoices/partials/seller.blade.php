{{-- Who is issuing the document. Filled from settings; see InvoiceDocument::seller(). --}}
<div class="biz" dir="auto">{{ $doc['seller']['name'] }}</div>
<div class="biz-lines">
    @foreach ($doc['seller']['addressLines'] as $line)
        <div dir="auto">{{ $line }}</div>
    @endforeach
    @if ($doc['seller']['phone'] !== '')
        <div dir="auto">{{ $doc['seller']['phone'] }}</div>
    @endif
    @if ($doc['seller']['email'] !== '')
        <div dir="auto">{{ $doc['seller']['email'] }}</div>
    @endif
    @if ($doc['seller']['website'] !== '')
        <div dir="auto">{{ $doc['seller']['website'] }}</div>
    @endif
    @if ($doc['seller']['trn'] !== '')
        {{-- Printed only when it has really been entered: an invoice showing an
             invented tax registration number is worse than one showing none. --}}
        <div dir="auto">TRN {{ $doc['seller']['trn'] }}</div>
    @endif
</div>
