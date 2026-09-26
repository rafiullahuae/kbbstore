{{--
    The like button — ours, and the only number on a tile this shop did not
    compute from its own catalogue.

    ── WHAT IS NOT HERE ────────────────────────────────────────────────────

    A source's like or comment count. The owner cut that mid-round — "okay, leave
    the counts for now, just get the videos from there" — and the fetchers, the
    credentials and the columns went with it rather than being left inert.
    docs/UGC-ENGAGEMENT.md records what each platform would have cost.

    The rule that drove that design is worth keeping written down, because the day
    somebody builds it they will need it: A NUMBER THIS SHOP CANNOT VERIFY IS NOT
    PRINTED, and a zero standing in for "we could not find out" is the same lie as
    an invented figure, only quieter. The count below is exempt from that for one
    reason only — we count it ourselves, so 0 genuinely means nobody has pressed
    it.

    ── AND IT SHIPS OFF ────────────────────────────────────────────────────

    R3 has no heart on it. Appearance → Video rail → Likes defaults to off,
    Api\UgcController::like() 404s while it is, and this partial is not included at
    all — so the shipped rail is R3 to the pixel and turning this on is the owner's
    deliberate departure from it.
--}}
<div class="ugcr-eng">
  {{-- A public write, and the only one this rail has. One like per browser, rate
       limited per address, and nothing personal is stored — see
       App\Models\UgcVideoLike, whose whole table is a clip id and the SHA-256 of a
       token this shop minted itself. --}}
  <button class="ugcr-like" type="button" data-ugcr-like-slug="{{ $tile['slug'] }}"
          aria-label="{{ __('store.ugc.like') }}">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-9.2A4 4 0 0 1 12 8a4 4 0 0 1 7 2.8C19 15.6 12 20 12 20Z"/></svg>
    <span data-ugcr-like-count><bdi>{{ number_format((int) ($tile['engagement']['own_likes'] ?? 0)) }}</bdi></span>
  </button>
</div>
