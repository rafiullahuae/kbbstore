{{-- "Create an account", the returning-customer note and the WhatsApp opt-in:
     the end of "Contact" as before, or the end of "Delivery Information" (under
     Country, as asked) once Contact is folded into it. Own partial since
     2.60.495; every name and id is as it was, and partials/checkout/sign-in
     still removes #create_account_field when a shopper signs in. --}}
{{-- THE THREE COMPOUND ROWS ON THIS PAGE STAY HAND-WRITTEN, deliberately.

     This one, the gift row below and the WhatsApp opt-in are not fields with a
     label above them: each is a tick that reveals or prices a second control,
     with its own wrapper classes, its own hidden state and -- here -- the only
     per-field error message on the checkout. Pushing them through
     x-checkout.field would mean teaching that component three shapes it has one
     caller each for, which is how a shared component becomes harder to read
     than the nine copies it replaced. The nine plain rows are the ones that
     were drifting, and they are the ones it renders. --}}
@guest('customer')
                            <p class="form-row form-row-wide kbb-acct" id="create_account_field">
                                <label for="create_account" class="kbb-acct-opt">
                                    <input type="checkbox" name="create_account" id="create_account" value="1" @checked(old('create_account'))>
                                    <span>{{ __('store.checkout.create_account') }}</span>
                                </label>
@if ($kbbCoPage->floatLabels())
                                <span class="woocommerce-input-wrapper kbb-acct-pw fld kbb-fl ico" id="account_password_wrap" hidden><span class="lead" aria-hidden="true">@include('partials.icon-lock')</span><input type="password" class="input-text" name="account_password" id="account_password"
                                           placeholder=" " autocomplete="new-password" minlength="8"><label for="account_password">{{ __('store.checkout.password_placeholder') }}</label></span>
@else
                                <span class="woocommerce-input-wrapper kbb-acct-pw" id="account_password_wrap" hidden>
                                    <input type="password" class="input-text" name="account_password" id="account_password"
                                           placeholder="{{ __('store.checkout.password_placeholder') }}" autocomplete="new-password" minlength="8">
                                </span>
@endif
                                @error('account_password')<span class="kbb-acct-err">{{ $message }}</span>@enderror
                            </p>
@endguest



                        <div class="co-note ok" id="kbbReturning" style="display:none"></div>
@if ($kbbCoPage->get('optin_on'))
                            {{-- UNTICKED unless the owner says otherwise. It shipped
                                 pre-ticked, so every order carried a consent nobody
                                 actively gave. old() still wins on a rejected submission,
                                 so a shopper who ticked it does not lose the tick. --}}
                            <div class="kbb-wa"><p class="form-row form-row-wide" id="billing_kbb_whatsapp_field" data-priority="25"><span class="woocommerce-input-wrapper"><label class="checkbox " ><input type="checkbox" name="billing_kbb_whatsapp" id="billing_kbb_whatsapp" value="1" class="input-checkbox " @checked(old('billing_kbb_whatsapp', $kbbCoPage->get('optin_checked'))) /> {{ __('store.checkout.whatsapp_optin') }}&nbsp;<span class="optional">{{ __('store.checkout.optional_note') }}</span></label></span></p></div>
@endif
