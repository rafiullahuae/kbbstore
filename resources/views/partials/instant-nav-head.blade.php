{{-- SP:BEGIN  Instant page changes (Lane SP). Appearance -> Site layout ->
     Page speed. App\Support\InstantNav carries the why, the analytics
     decision (prefetch, never prerender) and the allowlist. Nothing at all
     when both switches are off. The rules are built from constants and the
     base path and printed through the escaping echo; the script reads them
     back from its own data attribute. NO NEWLINE OUTSIDE THE TAGS: the
     include line supplies the one newline, so the page gains exactly these
     lines (StorefrontEnglishUnchangedTest cuts them out by that shape). --}}@if (\App\Support\InstantNav::fade())<style>@view-transition{navigation:auto}::view-transition-group(root){animation-duration:.15s}body>header{view-transition-name:kbb-hd}::view-transition-group(kbb-hd){animation-duration:0s}@media (prefers-reduced-motion:reduce){@view-transition{navigation:none}}</style>
@endif
@if (\App\Support\InstantNav::viewedLater() > 0)<script data-u="{{ \App\Support\Url::raw('/api/viewed') }}" data-t="{{ csrf_token() }}" data-i="{{ \App\Support\InstantNav::viewedLater() }}">(function(s){var b=new URLSearchParams();b.append('_token',s.dataset.t);b.append('id',s.dataset.i);fetch(s.dataset.u,{method:'POST',body:b,credentials:'same-origin',keepalive:true}).catch(function(){})})(document.currentScript)</script>
@endif
@if (\App\Support\InstantNav::on())<script data-r="{{ json_encode(\App\Support\InstantNav::rules(\App\Support\InstantNav::prefixes()), JSON_UNESCAPED_SLASHES) }}">(function(d,w){var s=d.currentScript,c=navigator.connection,e;if(!(w.HTMLScriptElement&&HTMLScriptElement.supports&&HTMLScriptElement.supports('speculationrules'))||c&&(c.saveData||/2g/.test(c.effectiveType||'')))return;e=d.createElement('script');e.type='speculationrules';e.textContent=s.dataset.r;d.head.appendChild(e)})(document,window)</script>
@endif{{-- SP:END --}}