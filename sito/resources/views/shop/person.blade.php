{{-- Un nominativo del carrello: nome ed email come sul documento (non si cambiano dopo il pagamento). --}}
@php
    $prefill ??= null;
    $base = "lines[$typeId][attendees][$i]";
    $key = "lines.$typeId.attendees.$i";
    $id = 'p-'.$typeId.'-'.$i;
@endphp
<div class="persona" role="group" aria-labelledby="{{ $id }}">
    <p id="{{ $id }}" class="etichetta">{{ $label }}</p>
    <div class="campo">
        <label for="{{ $id }}-name">{{ __('Full name') }}</label>
        <input class="input" id="{{ $id }}-name" name="{{ $base }}[name]" value="{{ old("$key.name", $prefill?->name) }}" autocomplete="{{ $prefill ? 'name' : 'off' }}" maxlength="200">
    </div>
    <div class="campo">
        <label for="{{ $id }}-email">{{ __('Email') }}</label>
        <input class="input" id="{{ $id }}-email" type="email" name="{{ $base }}[email]" value="{{ old("$key.email", $prefill?->email) }}" autocomplete="{{ $prefill ? 'email' : 'off' }}" maxlength="254" inputmode="email">
    </div>
</div>
