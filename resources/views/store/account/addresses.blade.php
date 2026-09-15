@extends('layouts.store')
@section('title', 'Addresses')

@section('content')
@php use App\Support\Url; @endphp
<div class="acw wide">
  <div class="acw-in">
    <h1>Addresses</h1>
    <p class="acw-sub">Where we deliver. Your default is used first at checkout.</p>

    @if (session('kbb_status'))
      <p class="ab-note">{{ session('kbb_status') }}</p>
    @endif

    @if ($errors->any())
      <ul class="ab-errs">
        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
      </ul>
    @endif

    @if ($addresses->isEmpty())
      <p class="acw-empty">No addresses saved yet. Add one below and it will be offered at checkout.</p>
    @else
      <div class="ab-grid">
        @foreach ($addresses as $a)
          <div class="ab-card{{ $a->is_default ? ' is-def' : '' }}">
            <div class="ab-head">
              <span class="ab-type">{{ ucfirst($a->type) }}</span>
              @if ($a->is_default)<span class="ab-badge">Default</span>@endif
            </div>
            <div class="ab-body">
              <strong>{{ trim($a->first_name . ' ' . $a->last_name) }}</strong>
              @if ($a->company)<span>{{ $a->company }}</span>@endif
              <span>{{ $a->line1 }}</span>
              @if ($a->line2)<span>{{ $a->line2 }}</span>@endif
              <span>{{ trim($a->city . ($a->state ? ', ' . $a->state : '')) }} {{ $a->postcode }}</span>
              <span>{{ $countries[$a->country] ?? $a->country }}</span>
              @if ($a->phone)<span>{{ $a->phone }}</span>@endif
            </div>
            <div class="ab-acts">
              <a class="ab-link" href="{{ route('account.addresses.edit', $a->id) }}">Edit</a>
              @unless ($a->is_default)
                <form method="post" action="{{ route('account.addresses.default', $a->id) }}">@csrf
                  <button type="submit" class="ab-link">Make default</button>
                </form>
              @endunless
              <form method="post" action="{{ route('account.addresses.delete', $a->id) }}"
                    onsubmit="return confirm('Delete this address?')">@csrf
                <button type="submit" class="ab-link ab-del">Delete</button>
              </form>
            </div>
          </div>
        @endforeach
      </div>
    @endif

    <h2 class="ab-h2">{{ $editing ? 'Edit address' : 'Add an address' }}</h2>

    <form class="ab-form" method="post"
          action="{{ $editing ? route('account.addresses.update', $editing->id) : route('account.addresses.store') }}">
      @csrf

      <label class="ab-f">
        <span>Type</span>
        <select name="type">
          @foreach ($types as $t)
            <option value="{{ $t }}" @selected(old('type', $editing->type ?? 'shipping') === $t)>{{ ucfirst($t) }}</option>
          @endforeach
        </select>
      </label>

      <label class="ab-f"><span>First name *</span>
        <input type="text" name="first_name" value="{{ old('first_name', $editing->first_name ?? '') }}" required></label>
      <label class="ab-f"><span>Last name</span>
        <input type="text" name="last_name" value="{{ old('last_name', $editing->last_name ?? '') }}"></label>
      <label class="ab-f ab-wide"><span>Company</span>
        <input type="text" name="company" value="{{ old('company', $editing->company ?? '') }}"></label>
      <label class="ab-f ab-wide"><span>Address line 1 *</span>
        <input type="text" name="line1" value="{{ old('line1', $editing->line1 ?? '') }}" required></label>
      <label class="ab-f ab-wide"><span>Address line 2</span>
        <input type="text" name="line2" value="{{ old('line2', $editing->line2 ?? '') }}"></label>
      <label class="ab-f"><span>City *</span>
        <input type="text" name="city" value="{{ old('city', $editing->city ?? '') }}" required></label>
      <label class="ab-f"><span>Emirate / State</span>
        <input type="text" name="state" value="{{ old('state', $editing->state ?? '') }}"></label>
      <label class="ab-f"><span>Postcode</span>
        <input type="text" name="postcode" value="{{ old('postcode', $editing->postcode ?? '') }}"></label>
      <label class="ab-f"><span>Country *</span>
        <select name="country">
          @foreach ($countries as $code => $label)
            <option value="{{ $code }}" @selected(old('country', $editing->country ?? 'AE') === $code)>{{ $label }}</option>
          @endforeach
        </select>
      </label>
      <label class="ab-f"><span>Phone</span>
        <input type="tel" name="phone" value="{{ old('phone', $editing->phone ?? '') }}"></label>

      <label class="ab-chk">
        <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $editing->is_default ?? false))>
        <span>Use as my default for this type</span>
      </label>

      <div class="ab-submit">
        <button type="submit" class="ab-btn">{{ $editing ? 'Save changes' : 'Add address' }}</button>
        @if ($editing)<a class="ab-link" href="{{ route('account.addresses') }}">Cancel</a>@endif
      </div>
    </form>
  </div>
</div>

@push('scripts')
<style>
.ab-note{background:#f2fbf4;border:1px solid #cfe9d6;color:#2f6b41;padding:9px 12px;border-radius:8px;font-size:13px;margin:0 0 14px}
.ab-errs{background:#fff3f5;border:1px solid #f0ccd6;color:#a33a55;padding:9px 12px 9px 28px;border-radius:8px;font-size:13px;margin:0 0 14px}
.ab-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));margin:0 0 26px}
.ab-card{border:1px solid #ece6e9;border-radius:10px;padding:13px 14px;background:#fff}
.ab-card.is-def{border-color:#e6b9c8}
.ab-head{display:flex;align-items:center;gap:8px;margin-bottom:7px}
.ab-type{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#8a7f85}
.ab-badge{font-size:10px;background:#fff0f4;color:#b4517a;border-radius:99px;padding:2px 8px}
.ab-body{display:flex;flex-direction:column;gap:2px;font-size:13px;color:#5e545a;line-height:1.45}
.ab-acts{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:11px;padding-top:10px;border-top:1px solid #f3eef0}
.ab-acts form{margin:0}
.ab-link{background:none;border:0;padding:0;font-size:12px;color:#b4517a;cursor:pointer;text-decoration:underline}
.ab-del{color:#a33a55}
.ab-h2{font-size:16px;margin:4px 0 12px}
.ab-form{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
.ab-f{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#6d6369}
.ab-f.ab-wide{grid-column:1/-1}
.ab-f input,.ab-f select{border:1px solid #e4dcdf;border-radius:8px;padding:9px 10px;font-size:14px;font-family:inherit;background:#fff}
.ab-chk{grid-column:1/-1;display:flex;align-items:center;gap:8px;font-size:13px;color:#5e545a}
.ab-submit{grid-column:1/-1;display:flex;align-items:center;gap:14px}
.ab-btn{background:#b4517a;color:#fff;border:0;border-radius:8px;padding:11px 22px;font-size:14px;cursor:pointer}
</style>
@endpush
@endsection
