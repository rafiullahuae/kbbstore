{{--
    The order number as Code 128, drawn in CSS. See App\Support\Code128 for why
    it is drawn rather than fetched, generated or set in a barcode font.

    $value  — the string to encode AND the string printed underneath it. One
              variable for both on purpose: a barcode whose human-readable text
              is a different value from the bars is the one failure mode of a
              printed code that nobody notices until a parcel is scanned to the
              wrong order.
    $height — optional CSS height for the bars.

    WHEN IT CANNOT BE ENCODED, THE NUMBER IS STILL PRINTED. Code128::encode()
    returns null for an empty value, an over-long one, or one containing any
    character outside ASCII 32–126 — an Arabic order number, say. The document
    then shows the number as plain text and remains a usable document. It does
    not fail, and it does not print bars that stand for something else.
--}}
@php
    $bcValue = trim((string) $value);
    $bcWidths = \App\Support\Code128::encode($bcValue);
    /* 0.34mm per module: comfortably above the 0.19mm minimum every 1D scanner
       reads, and narrow enough that a 24-character code still fits a 105mm
       label with its quiet zones. */
    $bcModule = 0.34;
@endphp

@if ($bcWidths !== null)
    <div class="bc" @if (! empty($height)) style="height: {{ $height }}" @endif
         role="img" aria-label="{{ __('invoice.document.barcode_label', ['value' => $bcValue]) }}">
        @foreach ($bcWidths as $bcIndex => $bcWidth)
            @php $bcMm = number_format($bcWidth * $bcModule, 3, '.', ''); @endphp
            {{-- The list alternates, starting with a bar. --}}
            @if ($bcIndex % 2 === 0)
                <i class="b" style="border-left-width: {{ $bcMm }}mm"></i>
            @else
                <i class="s" style="width: {{ $bcMm }}mm"></i>
            @endif
        @endforeach
    </div>
@endif

<div class="bc-text">{{ $bcValue }}</div>
