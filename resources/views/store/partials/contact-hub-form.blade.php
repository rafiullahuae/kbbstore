{{--
    The contact page's inquiry form, social icons and opening hours (Lane CT).

    Works with no script at all: a plain POST to /contact-us/send that redirects
    back to the page with a flash (no #fragment: one would make the browser
    skip autofocus). The outcome is drawn from that flash:
    the thank-you, or the errors beside their fields with the FIRST field in
    error carrying `autofocus` — the browser moves focus there on load, so a
    screen reader announces the field and its message (aria-describedby) with
    no JavaScript. Every value is escaped; old() refills what was typed.
--}}
@php
    $ctcForm = $hub['form'];
    $ctcSide = $hub['socials'] !== [] || $hub['hours'] !== [];
    $ctcSent = (bool) session('ctc_sent');
    $ctcFlash = (string) session('ctc_error', '');
    $ctcFirst = null;
    foreach (['name', 'email', 'phone', 'topic', 'message'] as $ctcName) {
        if ($errors->has($ctcName)) { $ctcFirst = $ctcName; break; }
    }
    $ctcAttrs = static function (string $field) use ($errors, $ctcFirst): string {
        if (! $errors->has($field)) {
            return '';
        }

        return ' aria-invalid="true" aria-describedby="ctc-'.$field.'-err"'.($ctcFirst === $field ? ' autofocus' : '');
    };
@endphp
@if ($ctcForm !== null || $ctcSide)
<div class="ctc ctc-low{{ $ctcForm !== null ? ' has-form' : '' }}{{ $ctcSide ? ' has-side' : '' }}">
@if ($ctcForm !== null)
    <section class="ctc-panel ctc-formwrap" id="ctc-form" aria-labelledby="ctc-form-h">
        <h2 class="ctc-h" id="ctc-form-h">{{ __('store.contact.form_title') }}</h2>
        <p class="ctc-sub">{{ __('store.contact.form_intro') }}</p>
@if ($ctcSent)
        <div class="ctc-status ok" role="status" tabindex="-1" autofocus><strong>{{ __('store.contact.sent_title') }}</strong>{{ __('store.contact.sent') }}</div>
@elseif ($ctcFlash !== '')
        <div class="ctc-status bad" role="alert" tabindex="-1" autofocus>{{ $ctcFlash }}</div>
@elseif ($ctcFirst !== null)
        <div class="ctc-status bad" role="alert">{{ __('store.contact.err_summary') }}</div>
@endif
        <form class="ctc-form" method="post" action="{{ $ctcForm['action'] }}" novalidate>
            @csrf
            <input type="hidden" name="ts" value="{{ $ctcForm['stamp'] }}">
            <div class="ctc-hp" aria-hidden="true"><label for="ctc-website">{{ __('store.contact.hp_label') }}</label><input type="text" id="ctc-website" name="website" tabindex="-1" autocomplete="off"></div>
            <div class="ctc-grid">
                <div class="ctc-f{{ $errors->has('name') ? ' is-bad' : '' }}">
                    <label for="ctc-name">{{ __('store.contact.label_name') }}</label>
                    <input type="text" id="ctc-name" name="name" maxlength="{{ $ctcForm['max']['name'] }}" required autocomplete="name" value="{{ old('name') }}"{!! $ctcAttrs('name') !!}>
@error('name')
                    <p class="ctc-err" id="ctc-name-err">{{ $message }}</p>
@enderror
                </div>
                <div class="ctc-f{{ $errors->has('email') ? ' is-bad' : '' }}">
                    <label for="ctc-email">{{ __('store.contact.label_email') }}</label>
                    <input type="email" id="ctc-email" name="email" maxlength="{{ $ctcForm['max']['email'] }}" required autocomplete="email" inputmode="email" dir="ltr" value="{{ old('email') }}"{!! $ctcAttrs('email') !!}>
@error('email')
                    <p class="ctc-err" id="ctc-email-err">{{ $message }}</p>
@enderror
                </div>
                <div class="ctc-f{{ $errors->has('phone') ? ' is-bad' : '' }}">
                    <label for="ctc-phone">{{ __('store.contact.label_phone') }} <small>({{ __('store.contact.optional') }})</small></label>
                    <input type="tel" id="ctc-phone" name="phone" maxlength="{{ $ctcForm['max']['phone'] }}" autocomplete="tel" inputmode="tel" dir="ltr" value="{{ old('phone') }}"{!! $ctcAttrs('phone') !!}>
@error('phone')
                    <p class="ctc-err" id="ctc-phone-err">{{ $message }}</p>
@enderror
                </div>
                <div class="ctc-f{{ $errors->has('topic') ? ' is-bad' : '' }}">
                    <label for="ctc-topic">{{ __('store.contact.label_topic') }}</label>
                    <select id="ctc-topic" name="topic" required{!! $ctcAttrs('topic') !!}>
                        <option value="">{{ __('store.contact.topic_choose') }}</option>
@foreach ($ctcForm['topics'] as [$ctcValue, $ctcLabel])
                        <option value="{{ $ctcValue }}"@selected((string) old('topic') === (string) $ctcValue)>{{ $ctcLabel }}</option>
@endforeach
                    </select>
@error('topic')
                    <p class="ctc-err" id="ctc-topic-err">{{ $message }}</p>
@enderror
                </div>
                <div class="ctc-f wide{{ $errors->has('message') ? ' is-bad' : '' }}">
                    <label for="ctc-message">{{ __('store.contact.label_message') }}</label>
                    <textarea id="ctc-message" name="message" rows="6" maxlength="{{ $ctcForm['max']['message'] }}" required{!! $ctcAttrs('message') !!}>{{ old('message') }}</textarea>
@error('message')
                    <p class="ctc-err" id="ctc-message-err">{{ $message }}</p>
@enderror
                </div>
            </div>
            <button type="submit" class="ctc-send">{{ __('store.contact.submit') }}</button>
            <p class="ctc-priv">{{ __('store.contact.privacy') }}</p>
        </form>
    </section>
@endif
@if ($ctcSide)
    <aside class="ctc-side">
@if ($hub['socials'] !== [])
        <section class="ctc-panel" aria-labelledby="ctc-follow">
            <h2 id="ctc-follow">{{ __('store.contact.follow_title') }}</h2>
            <p>{{ __('store.contact.follow_note') }}</p>
            <div class="ctc-soc">@foreach ($hub['socials'] as $ctcSoc)<a href="{{ $ctcSoc['href'] }}" aria-label="{{ $ctcSoc['name'] }}" target="_blank" rel="noopener">{!! $ctcSoc['icon'] !!}</a>@endforeach</div>
        </section>
@endif
@if ($hub['hours'] !== [])
        <section class="ctc-panel" aria-labelledby="ctc-hours">
            <h2 id="ctc-hours">{{ __('store.contact.hours_title') }}</h2>
            <ul class="ctc-hours">@foreach ($hub['hours'] as $ctcLine)<li>{{ $ctcLine }}</li>@endforeach</ul>
        </section>
@endif
    </aside>
@endif
</div>
@endif
