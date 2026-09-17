{{--
    One checkout field.

    WHY THIS IS NOT `x-field`.

    The account forms' `x-field` renders the design's `.fld` shape: a floating
    label sitting inside the box, lifted by `:placeholder-shown`. This checkout
    renders WooCommerce's `.form-row` shape: a small static label above the box,
    a `.woocommerce-input-wrapper` around the control, and `.input-text` on the
    control itself. Those are two different looks, and D-35 (storefront design =
    the WordPress theme, ported close to verbatim) is what stands between them —
    swapping one for the other is a restyle, not a refactor.

    More concretely, every rule that 2.60.197 added for phone shoppers is keyed
    to this shape:

        .kbb-checkout .input-text,
        .kbb-checkout .form-row input[type="email"], ...  { font-size:16px }
        .kbb-checkout .input-text, ... { min-height:44px }

    A field rendered as `.fld` matches none of those selectors, so moving the
    checkout onto `x-field` would silently put every field on this page back
    under iOS's 16px zoom threshold and under the 44px touch target. That is
    the defect 2.60.197 was written to fix, on the one form the shop is paid
    through.

    So this component is the *same idea* as `x-field` — one place that decides
    what a field's markup is, so nine hand-written copies cannot drift apart —
    rendering the checkout's own markup rather than the account forms'. The two
    can converge later, on the far side of a D-35 amendment, by this file
    changing rather than by nine call sites changing.

    TWO NOTES ON HOW THIS FILE IS WRITTEN, both paid for while writing it.

    1. PROSE IN A BLADE FILE IS STILL COMPILED. Component tags are compiled
       before comments are stripped, and directives inside a PHP `/* */` block
       are compiled too, because at that point the whole file is still just
       text. Writing the name of a component in angle brackets, or the name of
       a directive with its leading at-sign, anywhere in this file -- including
       in a comment -- makes Blade try to compile it, and the view dies with a
       parse error that points somewhere else entirely. So component and
       directive names appear here in plain words: "an x-field tag", "a foreach
       directive".

    2. THE ATTRIBUTES ARE ASSEMBLED IN THE php BLOCK, not with conditionals
       written between two HTML attributes. A Blade directive wants whitespace
       in front of it and a non-word character behind it, and neither is
       comfortable to guarantee inside a tag; getting it wrong is silent -- the
       directive is emitted as text, or an endif closes the wrong block.
       Building the strings first leaves the tag one interpolation with nothing
       to get wrong.
--}}
@props([
    // The posted name. Also the id and the `{id}_field` row id, unless `id`
    // says otherwise.
    'name',
    'label',
    'type' => 'text',
    'value' => '',
    'placeholder' => '',
    'required' => false,
    // Renders the "(optional)" note the live page shows beside Phone and
    // Delivery notes. Not simply `! $required`: most optional fields say
    // nothing at all.
    'optional' => false,
    'autocomplete' => null,
    'inputmode' => null,
    // WooCommerce's own row classes, kept because they are what shipped.
    // Nothing in this repo's CSS or JS reads `validate-*` or `required_field`,
    // but they are part of the markup the live theme serves.
    'rowClass' => 'form-row-wide',
    'validate' => null,
    'priority' => null,
    'maxlength' => null,
    'minlength' => null,
    'rows' => null,
    'id' => null,
])
@php
    $fieldId = $id ?: $name;

    $rowClasses = trim('form-row '.trim($rowClass.' '.(string) $validate));

    $rowAttrs = ' class="'.e($rowClasses).'" id="'.e($fieldId).'_field"';
    if ($priority !== null) {
        $rowAttrs .= ' data-priority="'.e((string) $priority).'"';
    }

    $labelAttrs = ' for="'.e($fieldId).'"';
    if ($required) {
        $labelAttrs .= ' class="required_field"';
    }

    /*
     * A non-breaking space before the marker, so "(optional)" and the red
     * asterisk cannot wrap onto a line of their own away from the words they
     * belong to. Five of the six rows already did this; "Delivery notes" used a
     * plain space, which is the kind of drift this component exists to end.
     */
    $marker = '';
    if ($required) {
        $marker = '&nbsp;<span class="required" aria-hidden="true">*</span>';
    } elseif ($optional) {
        $marker = '&nbsp;<span class="optional">(optional)</span>';
    }

    // `.input-text` is load-bearing: it is what both phone rules select on.
    $controlAttrs = ' class="input-text" name="'.e($name).'" id="'.e($fieldId).'"';

    if ($type !== 'select' && $placeholder !== '') {
        $controlAttrs .= ' placeholder="'.e($placeholder).'"';
    }

    if ($required) {
        // Both halves. `required` is constraint validation, which is what stops
        // an empty submission; `aria-required` is the announcement, which is
        // what a screen reader repeats. ShopperPathTruthTest pins the first.
        $controlAttrs .= ' required aria-required="true"';
    }

    foreach (['autocomplete' => $autocomplete, 'inputmode' => $inputmode] as $attr => $val) {
        if ($val !== null && $val !== '') {
            $controlAttrs .= ' '.$attr.'="'.e($val).'"';
        }
    }

    foreach (['maxlength' => $maxlength, 'minlength' => $minlength, 'rows' => $rows] as $attr => $val) {
        if ($val !== null) {
            $controlAttrs .= ' '.$attr.'="'.e((string) $val).'"';
        }
    }

    /*
     * The control, built here for the same reason as the attributes above: the
     * three shapes differ in where the value goes (an attribute, the element's
     * own text, or a `selected` option inside it), and a run of conditionals
     * between two tags is the one place Blade is easy to get silently wrong.
     *
     * A select's options are the default slot, because that is where the loop
     * over the country list reads naturally at the call site. The other slot
     * is named "badge": markup that belongs inside the label after the marker,
     * which is how the Country row carries its "Detected" pill.
     */
    $control = match ($type) {
        'select' => '<select'.$controlAttrs.'>'.trim((string) $slot).'</select>',
        'textarea' => '<textarea'.$controlAttrs.'>'.e($value).'</textarea>',
        default => '<input type="'.e($type).'"'.$controlAttrs.' value="'.e($value).'" />',
    };
@endphp
<p{!! $rowAttrs !!}><label{!! $labelAttrs !!}>{{ $label }}{!! $marker !!}{!! $badge ?? '' !!}</label><span class="woocommerce-input-wrapper">{!! $control !!}</span></p>
