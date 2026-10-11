{{-- Lane RD · review-showcase PREVIEW. The one script the carousel letters
     share, printed once. It measures nothing: buttons call scrollIntoView on a
     slide, an IntersectionObserver reports which slides are showing, and the
     spotlight advances when its CSS progress bar's animation ENDS -- so
     pausing on hover / focus / off-screen / a Pause press is
     animation-play-state in CSS, and there is no JavaScript timer of any kind at
     all. Auto-advance stops for good after two rounds or the first press, and
     never starts under prefers-reduced-motion. --}}
<script>
(function(){
var RM=matchMedia('(prefers-reduced-motion: reduce)').matches;
function each(s,f){[].forEach.call(document.querySelectorAll(s),f)}
function out(root){new IntersectionObserver(function(e){root.classList.toggle('is-out',!e[e.length-1].isIntersecting)},{threshold:.2}).observe(root)}
function pp(root,cb){var b=root.querySelector('[data-rs-pp]');if(!b)return;b.addEventListener('click',function(){var p=b.getAttribute('aria-pressed')!=='true';b.setAttribute('aria-pressed',p);b.setAttribute('aria-label',p?b.dataset.play:b.dataset.pause);root.classList.toggle('is-paused',p);cb&&cb(p)})}
each('[data-rs=car]:not([data-rs-on])',function(root){
root.setAttribute('data-rs-on','');
var t=root.querySelector('[data-rs-track]'),sl=[].slice.call(t.children),d=[].slice.call(root.querySelectorAll('[data-rs-dot]')),seen={},first=0;
function vis(){return Object.keys(seen).map(Number).sort(function(a,b){return a-b})}
var io=new IntersectionObserver(function(es){es.forEach(function(e){var i=sl.indexOf(e.target);if(e.isIntersecting)seen[i]=1;else delete seen[i]});var v=vis();if(v.length)first=v[0];d.forEach(function(b,i){b.setAttribute('aria-current',i===first?'true':'false');b.classList.toggle('in',!!seen[i])})},{root:t,threshold:.6});
sl.forEach(function(s){io.observe(s)});
root.addEventListener('click',function(e){var b=e.target.closest('[data-rs-go]');if(!b)return;var g=b.getAttribute('data-rs-go'),n=sl.length,v=vis(),step=Math.max(1,v.length),last=v.length?v[v.length-1]:first,i;
if(g==='next')i=last>=n-1?0:Math.min(n-1,first+step);else if(g==='prev')i=first<=0?n-1:Math.max(0,first-step);else i=+g;
sl[i].scrollIntoView({behavior:RM?'auto':'smooth',block:'nearest',inline:'start'})})});
each('[data-rs=spot]:not([data-rs-on])',function(root){
root.setAttribute('data-rs-on','');
var sl=[].slice.call(root.querySelectorAll('[data-rs-slide]')),d=[].slice.call(root.querySelectorAll('[data-rs-dot]')),n=sl.length,cur=0,rounds=0;
function stop(s){root.classList.toggle('is-stopped',s)}
function show(i,user){cur=(i+n)%n;sl.forEach(function(s,k){var on=k===cur;s.classList.toggle('on',on);s.setAttribute('aria-hidden',on?'false':'true');s.inert=!on});d.forEach(function(b,k){b.setAttribute('aria-current',k===cur?'true':'false')});if(user)stop(true)}
if(RM)stop(true);out(root);
pp(root,function(p){if(!p)stop(false)});
root.addEventListener('animationend',function(e){if(!e.target.hasAttribute('data-rs-bar')||root.classList.contains('is-stopped'))return;if(cur===n-1&&++rounds>=2){stop(true);return}show(cur+1)});
root.addEventListener('click',function(e){var b=e.target.closest('[data-rs-go]');if(!b)return;var g=b.getAttribute('data-rs-go');show(g==='next'?cur+1:g==='prev'?cur-1:+g,true)})});
each('[data-rs=mq]:not([data-rs-on])',function(root){root.setAttribute('data-rs-on','');out(root);pp(root)});
})();
</script>
