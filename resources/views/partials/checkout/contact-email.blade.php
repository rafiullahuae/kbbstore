{{-- The Email field (its own partial since 2.60.495, so it can open "Contact" as
     before or "Delivery Information" once Contact is folded into it). Same
     name, id, autocomplete and validation in both places. The grey line under
     it is 13 October layout -> "Grey line under Email", plain text. --}}
{{-- CONTACT IS THE EMAIL ADDRESS (Lane QK6). The owner: "Change Shipping
     address section heading to Shipping Details, remove the phone and Full
     name fields from the contact section and bring these two fields to
     Shipping Details section." Full name and Phone open section 2 now, in that
     order; each field keeps its name, id, autocomplete, inputmode, validation,
     required star, icon and floating label exactly as before, so the server,
     "Remember my details", the account prefill and the pixels read the same
     boxes. Email stands alone here, full width.

     Phone is required on the server as well as here: see place()'s rules. --}}
                        <x-checkout.field name="billing_email" :label="__('store.checkout.field_email')" type="email" required icon="mail"
                            validate="validate-required validate-email" priority="1"
                            :placeholder="__('store.checkout.field_email_placeholder')" inputmode="email"
                            autocomplete="section-billing billing email"
                            :value="old('billing_email', $prefill['email'] ?? '')" />
@if (! empty($hint) && ($kbbEmailHint = $kbbCoPage->emailHint()) !== '')
                        <p class="co-ehint">{{ $kbbEmailHint }}</p>
@endif
