@props(['model' => 'pin', 'submit' => null, 'label' => 'PIN transaksi'])
{{-- Input PIN 6 angka (logika di resources/js/pin-input.js). `model` = properti Livewire, `submit` = aksi saat Enter & lengkap. --}}
<div x-data="pinInput($wire.entangle('{{ $model }}'))" {{ $attributes->merge(['class' => 'space-y-2']) }} data-pin-input>
    <p class="text-sm font-medium" id="{{ $model }}-label">{{ $label }}</p>
    <div class="flex justify-center gap-2" role="group" aria-labelledby="{{ $model }}-label">
        @for ($i = 0; $i < 6; $i++)
            <input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
                   x-ref="d{{ $i }}" :value="digits[{{ $i }}]" aria-label="Digit {{ $i + 1 }}"
                   x-on:input="input({{ $i }}, $event)" x-on:paste="paste($event)" x-on:keydown.backspace="back({{ $i }}, $event)"
                   @if ($submit) x-on:keydown.enter.prevent="complete && $wire.{{ $submit }}()" @endif
                   @if ($i === 0) x-init="$nextTick(() => $el.focus())" @endif
                   class="size-12 rounded-xl border border-stone-300 text-center text-xl font-semibold tabular-nums outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
        @endfor
    </div>
    <p class="text-center text-xs" :class="complete ? 'text-emerald-700' : 'text-stone-500'" x-text="complete ? 'PIN lengkap' : 'Masukkan 6 angka'" aria-live="polite"></p>
    {{ $slot }}
</div>
