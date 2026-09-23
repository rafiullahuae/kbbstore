{{--
    THE SLIM FOOTER — one bar, drawn at the foot of the cart page and the
    checkout, from App\Services\SlimFooter.

    ── WHY IT IS A PARTIAL AND NOT TWO COPIES ─────────────────────────────────

    Both pages draw the same bar from the same settings. A second copy would be
    a second place for the support number to be wrong, and the wrong one would
    be whichever page nobody looked at.

    ── EVERY WORD IS A SETTING, AND EMPTY MEANS ABSENT ────────────────────────

    Nothing below is a literal a shopper reads, which is what
    StorefrontStringsAreKeyedTest walks this directory for. An empty setting
    draws NO element rather than an empty one — so a shop that wants a brand
    and a phone number and nothing else gets a bar with a brand and a phone
    number in it, not a bar with four empty gaps.

    ── THE ARROW SCROLLS, IT DOES NOT JUMP ────────────────────────────────────

    `href="#"` would put a fragment in the address bar of a page somebody is
    paying from, and back-button its way into a half-finished order. It is a
    button, and it calls scrollTo with `behavior:'smooth'` — which the browser
    itself turns into an instant jump for a shopper who has asked for reduced
    motion, so there is nothing here to guard.
--}}
@php
    $sf = app(\App\Services\SlimFooter::class);
    $sfC = $sf->all();

    // Drawn once each, rather than asked for four times inside the markup.
    $sfPhoneUrl = $sfC['phone'] !== '' ? $sf->url($sfC['phone_url']) : null;
    $sfMailUrl = $sfC['email'] !== '' ? $sf->url('mailto:'.$sfC['email']) : null;
    $sfL1 = $sfC['l1_text'] !== '' ? $sf->url($sfC['l1_url']) : null;
    $sfL2 = $sfC['l2_text'] !== '' ? $sf->url($sfC['l2_url']) : null;
@endphp
<footer class="kbb-slimfoot{{ $sf->bodyClass() }}"{!! $sf->styleAttr() !!}>
    <div class="sf-in">
@if ($sfC['brand'] !== '' || $sfC['byline'] !== '')
        <div class="sf-brand">
@if ($sfC['brand'] !== '')<b>{{ $sfC['brand'] }}</b>@endif
@if ($sfC['byline'] !== '')<i>{{ $sfC['byline'] }}</i>@endif
        </div>
@endif
@if ($sfC['help_title'] !== '' || $sfC['help_sub'] !== '')
        <div class="sf-help">
@if ($sfC['help_title'] !== '')<b>{{ $sfC['help_title'] }}</b>@endif
@if ($sfC['help_sub'] !== '')<span>{{ $sfC['help_sub'] }}</span>@endif
        </div>
@endif
{{-- WHOLE TAGS IN EVERY BRANCH, not one tag with its name interpolated.
     `<{{ $url ? 'a href=...' : 'span' }}>` works and reads terribly, and the
     scanner in StorefrontStringsAreKeyedTest -- which strips Blade expressions
     before looking for English -- saw what was left as a sentence. Written out,
     each branch is a tag somebody can read. --}}
@if ($sfC['phone'] !== '' || $sfC['email'] !== '')
        <div class="sf-con">
@if ($sfC['phone'] !== '' && $sfPhoneUrl !== null)
            <a class="sf-c" href="{{ $sfPhoneUrl }}"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.7 15l-1.2 4.3a.6.6 0 0 0 .7.7L7.2 20.8A10 10 0 1 0 12 2Zm0 1.8a8.2 8.2 0 1 1-4.2 15.2.9.9 0 0 0-.7-.1l-2.7.8.8-2.6a.9.9 0 0 0-.1-.8A8.2 8.2 0 0 1 12 3.8Zm-3.3 4c-.2 0-.5.1-.7.4-.3.3-.9.9-.9 2.1s.9 2.4 1 2.6c.1.2 1.7 2.7 4.2 3.7 2.1.8 2.5.7 3 .6.5 0 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3-.3-.2-1.5-.8-1.8-.9-.2 0-.4-.1-.5.1-.2.3-.6.9-.8 1-.1.2-.3.2-.5.1-.3-.1-1.1-.4-2-1.2-.8-.7-1.3-1.5-1.4-1.7-.2-.3 0-.4.1-.5l.4-.5c.1-.2.2-.3.2-.5s0-.4-.1-.5c0-.2-.5-1.4-.7-1.9-.2-.4-.4-.4-.5-.4h-.4Z"/></svg><span>{{ $sfC['phone'] }}</span></a>
@elseif ($sfC['phone'] !== '')
            <span class="sf-c"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.7 15l-1.2 4.3a.6.6 0 0 0 .7.7L7.2 20.8A10 10 0 1 0 12 2Zm0 1.8a8.2 8.2 0 1 1-4.2 15.2.9.9 0 0 0-.7-.1l-2.7.8.8-2.6a.9.9 0 0 0-.1-.8A8.2 8.2 0 0 1 12 3.8Zm-3.3 4c-.2 0-.5.1-.7.4-.3.3-.9.9-.9 2.1s.9 2.4 1 2.6c.1.2 1.7 2.7 4.2 3.7 2.1.8 2.5.7 3 .6.5 0 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3-.3-.2-1.5-.8-1.8-.9-.2 0-.4-.1-.5.1-.2.3-.6.9-.8 1-.1.2-.3.2-.5.1-.3-.1-1.1-.4-2-1.2-.8-.7-1.3-1.5-1.4-1.7-.2-.3 0-.4.1-.5l.4-.5c.1-.2.2-.3.2-.5s0-.4-.1-.5c0-.2-.5-1.4-.7-1.9-.2-.4-.4-.4-.5-.4h-.4Z"/></svg><span>{{ $sfC['phone'] }}</span></span>
@endif
@if ($sfC['email'] !== '' && $sfMailUrl !== null)
            <a class="sf-c" href="{{ $sfMailUrl }}"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 5.5h18a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-11a1 1 0 0 1 1-1Zm1.6 1.8L12 12.4l7.4-5.1H4.6Z"/></svg><span>{{ $sfC['email'] }}</span></a>
@elseif ($sfC['email'] !== '')
            <span class="sf-c"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 5.5h18a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-11a1 1 0 0 1 1-1Zm1.6 1.8L12 12.4l7.4-5.1H4.6Z"/></svg><span>{{ $sfC['email'] }}</span></span>
@endif
        </div>
@endif
@if ($sfC['l1_text'] !== '' || $sfC['l2_text'] !== '')
        <div class="sf-links">
@if ($sfC['l1_text'] !== '' && $sfL1 !== null)<a href="{{ $sfL1 }}">{{ $sfC['l1_text'] }}</a>@elseif ($sfC['l1_text'] !== '')<span>{{ $sfC['l1_text'] }}</span>@endif
@if ($sfC['l2_text'] !== '' && $sfL2 !== null)<a href="{{ $sfL2 }}">{{ $sfC['l2_text'] }}</a>@elseif ($sfC['l2_text'] !== '')<span>{{ $sfC['l2_text'] }}</span>@endif
        </div>
@endif
@if ($sfC['top_on'])
        <button type="button" class="sf-top" data-sf-top
@if ($sfC['top_label'] !== '') aria-label="{{ $sfC['top_label'] }}"@else aria-hidden="true" tabindex="-1"@endif>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5"/><path d="m5.5 11.5 6.5-6.5 6.5 6.5"/></svg>
        </button>
@endif
    </div>
</footer>
@once
@push('styles')
<style>
/* ── THE SLIM FOOTER ───────────────────────────────────────────────────────
   One bar, three shapes, and a height that is mostly --sf-pady. Every number
   here is a var() with its own default, so a shop that has never opened
   Appearance -> Footer gets exactly what ships and sends no style attribute.

   Scoped under .kbb-slimfoot and nothing else: it is drawn on two pages whose
   stylesheets have nothing in common, so it cannot inherit its way to
   correctness on either. */
.kbb-slimfoot{
  --sf-pady:12px; --sf-padx:20px; --sf-gap:18px; --sf-f:1; --sf-bf:1;
  --sf-ink:#17181C; --sf-ink-2:#5E545A; --sf-line:#EBE3E6; --sf-bg:#FBF5F4;
  background:var(--sf-bg);color:var(--sf-ink-2);
  border-top:1px solid var(--sf-line);
  font-family:inherit;font-size:calc(12px * var(--sf-f));line-height:1.45;
  /* kbb.css carries a BARE ELEMENT rule -- `footer{background:#241C20;
     color:#CDBFC6;padding:52px 0 26px}` -- for the site footer. A class beats
     an element selector on colour, so the two above were already ours; padding
     was not, and 78px of it arrived uninvited. Measured: the bar rendered
     161px tall against the 82px its own contents needed. Reset here, where
     .sf-in owns every pixel of spacing this footer has. */
  padding:0;margin:0;
}
.kbb-slimfoot.sf-noline{border-top:0}
.kbb-slimfoot.sf-t-white{--sf-bg:#FFFFFF}
.kbb-slimfoot.sf-t-ink{--sf-bg:#17181C;--sf-ink:#FFFFFF;--sf-ink-2:#C9C1C5;--sf-line:#2C2A2E}

.kbb-slimfoot .sf-in{
  max-width:1040px;margin:0 auto;
  padding:var(--sf-pady) var(--sf-padx);
  display:flex;align-items:center;flex-wrap:wrap;
  gap:calc(var(--sf-gap) * .55) var(--sf-gap);
  min-width:0;
}
.kbb-slimfoot .sf-in > *{min-width:0}

.kbb-slimfoot b{color:var(--sf-ink);font-weight:700}
.kbb-slimfoot .sf-brand b{
  display:block;font-size:calc(14px * var(--sf-bf));font-weight:800;
  letter-spacing:.02em;text-transform:uppercase;line-height:1.15;
}
.kbb-slimfoot .sf-brand i{display:block;font-style:normal;font-size:calc(10.5px * var(--sf-f));opacity:.85}
.kbb-slimfoot .sf-help{display:flex;align-items:baseline;gap:6px;flex-wrap:wrap}
.kbb-slimfoot .sf-con{display:flex;align-items:center;flex-wrap:wrap;gap:calc(var(--sf-gap) * .5) var(--sf-gap)}
.kbb-slimfoot .sf-c{display:inline-flex;align-items:center;gap:6px;color:inherit;text-decoration:none;min-width:0}
.kbb-slimfoot .sf-c svg{flex:none;width:calc(15px * var(--sf-f));height:calc(15px * var(--sf-f));color:var(--sf-ink)}
.kbb-slimfoot .sf-c span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.kbb-slimfoot a:hover{color:var(--sf-ink)}
.kbb-slimfoot .sf-links{display:flex;flex-wrap:wrap;gap:calc(var(--sf-gap) * .5) var(--sf-gap)}
.kbb-slimfoot .sf-links a,.kbb-slimfoot .sf-links span{color:inherit;text-decoration:underline;text-underline-offset:2px}

/* Pushed to the far end whatever the shape, because "back to top" belongs at
   the end of the line the eye is already travelling along. */
.kbb-slimfoot .sf-top{
  margin-inline-start:auto;flex:none;
  width:calc(30px * var(--sf-f));height:calc(30px * var(--sf-f));
  display:grid;place-items:center;border-radius:50%;
  background:transparent;border:1px solid var(--sf-line);color:var(--sf-ink);
  cursor:pointer;transition:.15s;
}
.kbb-slimfoot .sf-top:hover{background:var(--sf-line)}
.kbb-slimfoot .sf-top svg{width:calc(15px * var(--sf-f));height:calc(15px * var(--sf-f))}

/* ── THE THREE SHAPES ──────────────────────────────────────────────────────
   `bar` is the default and the shortest: everything on one row, wrapping only
   when it has to. The other two group the content instead. */
.kbb-slimfoot.sf-bar .sf-brand b{display:inline}
.kbb-slimfoot.sf-bar .sf-brand i{display:inline;margin-inline-start:6px}
.kbb-slimfoot.sf-bar .sf-help b{margin-inline-end:2px}

.kbb-slimfoot.sf-split .sf-in{align-items:flex-start}
.kbb-slimfoot.sf-split .sf-brand{margin-inline-end:auto}
.kbb-slimfoot.sf-split .sf-help,
.kbb-slimfoot.sf-split .sf-con,
.kbb-slimfoot.sf-split .sf-links{align-self:center}

.kbb-slimfoot.sf-stack .sf-in{flex-direction:column;align-items:flex-start}
.kbb-slimfoot.sf-stack .sf-top{margin-inline-start:0;margin-top:calc(var(--sf-gap) * -.35);align-self:flex-end}
.kbb-slimfoot.sf-stack .sf-help{flex-direction:column;align-items:flex-start;gap:1px}

/* A phone is 390px and the bar has five blocks in it. Wrapping is the point;
   what must not happen is a bar that makes the page scroll sideways. */
@media (max-width:640px){
  .kbb-slimfoot .sf-in{align-items:flex-start}
  .kbb-slimfoot .sf-top{align-self:center}
  .kbb-slimfoot.sf-bar .sf-brand i{display:block;margin-inline-start:0}
}
</style>
@endpush
@push('scripts')
<script>
/* ONE DELEGATED LISTENER, and it is the whole of this footer's JavaScript.
   Bound to document rather than to the button because the cart page replaces
   whole regions of itself on every quantity change, and a handler bound to an
   element would go with the element. */
document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-sf-top]')) return;
    window.scrollTo({ top: 0, behavior: 'smooth' });
});
</script>
@endpush
@endonce
