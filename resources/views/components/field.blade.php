{{--
    One field, used by every account form.

    The label sits inside and lifts once there is something to lift for. That
    relies on the placeholder being a single space: `:placeholder-shown` is what
    tells CSS the field is empty, with no script involved.
--}}
@props([
    'name',
    'label',
    'type' => 'text',
    'value' => '',
    'icon' => null,
    'required' => true,
    'autocomplete' => null,
    'minlength' => null,
    'reveal' => false,
])

<div @class(['fld', 'ico' => $icon])>
    @if ($icon)
        <span class="lead">@include('partials.icon-' . $icon)</span>
    @endif

    <input type="{{ $type }}" name="{{ $name }}" id="f-{{ $name }}"
           value="{{ $value }}" placeholder=" "
           @if ($required) required @endif
           @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
           @if ($minlength) minlength="{{ $minlength }}" @endif>

    <label for="f-{{ $name }}">{{ $label }}</label>

    @if ($reveal)
        {{-- A button rather than a checkbox: the CSS-only trick relies on a
             property Firefox does not support, so it would quietly do nothing
             for some customers. --}}
        <button type="button" class="trail" data-reveal="f-{{ $name }}"
                aria-label="Show password">@include('partials.icon-eye')</button>
    @endif
</div>
