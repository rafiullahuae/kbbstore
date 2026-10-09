{{--
    The contact page's inquiry form, social icons and opening hours (Lane CT).

    THE FIELDS ARE THE CHECKOUT'S. The owner: "i don't like the form fields, do
    the same as we have on the checkout page. along with inner place holder etc."
    So each field is the checkout's floating-label markup, written out the way
    its coupon field is: a .form-row, a .woocommerce-input-wrapper.fld.kbb-fl
    with the icon at its start (partials/icon-*, the checkout's own), the
    .input-text control with the checkout's hint as its placeholder, and the
    real <label> AFTER the control, so `:placeholder-shown ~ label` lifts it
    with no script. kbb-checkout.css does not load on content pages, so the few
    rules that draw it are copied, scoped under .ctc-form, in
    contact-hub-head. The label stays a real, visible label: nothing is
    placeholder-only.

    Works with no script at all: a plain POST to /contact-us/send that redirects
    back to the page with a flash (no #fragment: one would make the browser
    skip autofocus). The thank-you, or the errors beside their fields with the
    FIRST field in error carrying `autofocus`, so a screen reader announces the
    field and its message (aria-describedby). Every value is escaped; old()
    refills what was typed.
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
    $ctcRow = static fn (string $field, string $extra = ''): string => 'form-row'.$extra.($errors->has($field) ? ' kbb-invalid' : '');
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
                <p class="{{ $ctcRow('name') }}"><span class="woocommerce-input-wrapper fld kbb-fl ico"><span class="lead" aria-hidden="true">@include('partials.icon-user')</span><input type="text" class="input-text" id="ctc-name" name="name" maxlength="{{ $ctcForm['max']['name'] }}" required aria-required="true" autocomplete="name" placeholder="{{ __('store.checkout.field_full_name_placeholder') }}" value="{{ old('name') }}"{!! $ctcAttrs('name') !!}><label for="ctc-name">{{ __('store.contact.label_name') }}&nbsp;<span class="required" aria-hidden="true">*</span></label></span>
@error('name')
                    <span class="ctc-err" id="ctc-name-err">{{ $message }}</span>
@enderror
                </p>
                <p class="{{ $ctcRow('email') }}"><span class="woocommerce-input-wrapper fld kbb-fl ico"><span class="lead" aria-hidden="true">@include('partials.icon-mail')</span><input type="email" class="input-text" id="ctc-email" name="email" maxlength="{{ $ctcForm['max']['email'] }}" required aria-required="true" autocomplete="email" inputmode="email" dir="ltr" placeholder="{{ __('store.checkout.field_email_placeholder') }}" value="{{ old('email') }}"{!! $ctcAttrs('email') !!}><label for="ctc-email">{{ __('store.contact.label_email') }}&nbsp;<span class="required" aria-hidden="true">*</span></label></span>
@error('email')
                    <span class="ctc-err" id="ctc-email-err">{{ $message }}</span>
@enderror
                </p>
                <p class="{{ $ctcRow('phone') }}"><span class="woocommerce-input-wrapper fld kbb-fl ico"><span class="lead" aria-hidden="true">{!! $hub['waIcon'] !!}</span><input type="tel" class="input-text" id="ctc-phone" name="phone" maxlength="{{ $ctcForm['max']['phone'] }}" autocomplete="tel" inputmode="tel" dir="ltr" placeholder="{{ __('store.checkout.field_phone_placeholder') }}" value="{{ old('phone') }}"{!! $ctcAttrs('phone') !!}><label for="ctc-phone">{{ __('store.contact.label_phone') }}&nbsp;<span class="optional">({{ __('store.contact.optional') }})</span></label></span>
@error('phone')
                    <span class="ctc-err" id="ctc-phone-err">{{ $message }}</span>
@enderror
                </p>
                <p class="{{ $ctcRow('topic') }}"><span class="woocommerce-input-wrapper fld kbb-fl ico"><span class="lead" aria-hidden="true">@include('partials.icon-tag')</span><select class="input-text" id="ctc-topic" name="topic" required aria-required="true"{!! $ctcAttrs('topic') !!}><option value=""></option>
@foreach ($ctcForm['topics'] as [$ctcValue, $ctcLabel])
                    <option value="{{ $ctcValue }}"@selected((string) old('topic') === (string) $ctcValue)>{{ $ctcLabel }}</option>
@endforeach
                </select><label for="ctc-topic">{{ __('store.contact.label_topic') }}&nbsp;<span class="required" aria-hidden="true">*</span></label></span>
@error('topic')
                    <span class="ctc-err" id="ctc-topic-err">{{ $message }}</span>
@enderror
                </p>
                <p class="{{ $ctcRow('message', ' wide') }}"><span class="woocommerce-input-wrapper fld kbb-fl ico"><span class="lead" aria-hidden="true">@include('partials.icon-note')</span><textarea class="input-text" id="ctc-message" name="message" rows="4" maxlength="{{ $ctcForm['max']['message'] }}" required aria-required="true" placeholder="{{ __('store.contact.message_placeholder') }}"{!! $ctcAttrs('message') !!}>{{ old('message') }}</textarea><label for="ctc-message">{{ __('store.contact.label_message') }}&nbsp;<span class="required" aria-hidden="true">*</span></label></span>
@error('message')
                    <span class="ctc-err" id="ctc-message-err">{{ $message }}</span>
@enderror
                </p>
            </div>
            <div class="ctc-act"><button type="submit" class="ctc-send">{{ __('store.contact.submit') }}</button><span class="ctc-priv">{{ __('store.contact.privacy') }}</span></div>
        </form>
    </section>
@endif
@if ($ctcSide)
    <aside class="ctc-panel ctc-side">
@if ($hub['socials'] !== [])
        <div class="ctc-strip"><h2 id="ctc-follow">{{ __('store.contact.follow_title') }}</h2><div class="ctc-soc" aria-labelledby="ctc-follow">@foreach ($hub['socials'] as $ctcSoc)<a href="{{ $ctcSoc['href'] }}" aria-label="{{ $ctcSoc['name'] }}" target="_blank" rel="noopener">{!! $ctcSoc['icon'] !!}</a>@endforeach</div></div>
@endif
@if ($hub['hours'] !== [])
        <div class="ctc-strip"><h2 id="ctc-hours">{{ __('store.contact.hours_title') }}</h2><ul class="ctc-hours" aria-labelledby="ctc-hours">@foreach ($hub['hours'] as $ctcLine)<li>{{ $ctcLine }}</li>@endforeach</ul></div>
@endif
    </aside>
@endif
</div>
@endif
