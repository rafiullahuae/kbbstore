{{--
    The overlay card itself — one markup, both legs. (Lane PLC)

    Drawn into a <template> on the checkout, where a press clones it, and drawn
    straight into the document on the order-received page, where the server has
    already decided what it says.

    ═══════════════════════════════════════════════════════════════════════════
    EVERY GLYPH ON IT IS A CONSTANT IN THIS FILE
    ═══════════════════════════════════════════════════════════════════════════

    The star burst and the tick are inline SVG written here, with path data
    computed once and pasted. Nothing about this drawing is reachable from a
    setting, a model or a response, which is what CLAUDE.md rule 5 asks of
    anything printed unescaped. The only values this partial interpolates are
    $title and $note, and both go through {{ }}.

    THE BADGE IS THE ONE THE OWNER ATTACHED: a soft star-burst seal — a paler
    halo of the same shape behind a mint one — with a solid green disc and a
    white tick inside it.

    ═══════════════════════════════════════════════════════════════════════════
    ACCESSIBILITY
    ═══════════════════════════════════════════════════════════════════════════

    role="dialog" + aria-modal, named by a translated label. The drawing is
    aria-hidden — it says nothing a screen reader can use and "image" would be a
    lie. The sentence is carried BOTH in the visible paragraph and in a visually
    hidden role="status" live region, which is the element that gets announced:
    changing the text of the element a dialog is named by is not reliably
    re-announced, and this is the moment a screen-reader user most needs telling.

    `assertive`, not `polite`: an order is being placed, and an announcement
    that queues behind something else and lands after the navigation is no
    announcement at all.

    ═══════════════════════════════════════════════════════════════════════════
    $modal, AND WHY THE RETURN LEG PASSES false
    ═══════════════════════════════════════════════════════════════════════════

    On the checkout this card IS modal: the order is in flight, the page behind
    it must not be touched, and everything else on <body> is made `inert` while
    it is up. role="dialog" + aria-modal="true" is then the truth.

    On the order-received page it is not. It is a transient state card over a
    receipt the shopper came back to READ, it takes itself down in CSS after a
    second or two, and nothing behind it is inert. aria-modal there would hide
    the entire order — the line items, the address, the total — from a screen
    reader for as long as it happened to be on screen, which is the opposite of
    what it is for. So the wrapper is a plain element and the sentence is
    carried by the live region alone, which is announced either way.

    Expects: $label, $title, $note (string, may be ''), $classes (string),
    $hold (string CSS time, or ''), $modal (bool).
--}}
<div class="kbb-placing{{ $classes === '' ? '' : ' '.$classes }}"
     @if ($modal) role="dialog" aria-modal="true" aria-label="{{ $label }}" tabindex="-1" @endif
     @if ($hold !== '') style="--kbbp-hold:{{ $hold }}" @endif>
  <div class="kbb-placing-card">
    <div class="kbb-placing-mark">
      <svg class="kbb-placing-ring" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
        <circle class="kbb-placing-track" cx="32" cy="32" r="27" pathLength="100"></circle>
        <circle class="kbb-placing-fill" cx="32" cy="32" r="27" pathLength="100"></circle>
      </svg>
      <svg class="kbb-placing-tick" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
        <path class="kbb-placing-halo" d="M32.0 1.0 L38.6 7.4 L47.5 5.2 L50.0 14.0 L58.9 16.5 L56.6 25.4 L63.0 32.0 L56.6 38.6 L58.9 47.5 L50.0 50.0 L47.5 58.9 L38.6 56.6 L32.0 63.0 L25.4 56.6 L16.5 58.9 L14.0 50.0 L5.2 47.5 L7.4 38.6 L1.0 32.0 L7.4 25.4 L5.2 16.5 L14.0 14.0 L16.5 5.2 L25.4 7.4 Z"></path>
        <path class="kbb-placing-burst" d="M32.0 3.0 L38.0 9.6 L46.5 6.9 L48.4 15.6 L57.1 17.5 L54.4 26.0 L61.0 32.0 L54.4 38.0 L57.1 46.5 L48.4 48.4 L46.5 57.1 L38.0 54.4 L32.0 61.0 L26.0 54.4 L17.5 57.1 L15.6 48.4 L6.9 46.5 L9.6 38.0 L3.0 32.0 L9.6 26.0 L6.9 17.5 L15.6 15.6 L17.5 6.9 L26.0 9.6 Z"></path>
        <circle class="kbb-placing-disc" cx="32" cy="32" r="20.5"></circle>
        <path class="kbb-placing-check" d="M22.6 32.6 L29.2 39.4 L41.6 25.6" pathLength="100"></path>
      </svg>
      <div class="kbb-placing-ping"></div>
    </div>
    <p class="kbb-placing-title">{{ $title }}</p>
    <p class="kbb-placing-note">{{ $note }}</p>
    <p class="kbb-placing-sr" role="status" aria-live="assertive">{{ trim($title.' '.$note) }}</p>
  </div>
</div>
