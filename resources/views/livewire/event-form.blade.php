{{--
    Formulario de un evento (crear y editar), dentro del overlay de kore-ui.

    Solo componentes de kore-ui. Los textos salen de Messages (lang/ y el override `translations`), en el idioma del calendario que lo abrió.

    - `novalidate`: los campos llevan `required` por el asterisco de la etiqueta, pero
      el aviso lo da el servidor, en el idioma del calendario. Sin esto el navegador
      bloquea el envío con su propio globo, en el suyo, y nunca llega a Livewire.
    - `$wire.allDay` y no `$allDay`: es el estado del navegador, que cambia al pulsar
      el interruptor. Con `$allDay` los selectores de hora no se esconderían hasta
      una ida y vuelta al servidor, y el formulario solo guarda con `save()`.
--}}
@php
    $t = fn (string $key, array $replace = []) => \KoreUi\Calendar\Interaction\Messages::get($key, $replace, $calendarLocale);
@endphp

<form wire:submit="save" novalidate class="flex flex-col gap-4 p-6 text-start">
    <h2 class="text-lg font-semibold text-kore-fg">{{ $t($mode === 'edit' ? 'form_edit' : 'form_create') }}</h2>

    <x-kore::input wire:model="title" :label="$t('form_title')" required autofocus autocomplete="off" />

    <x-kore::toggle wire:model="allDay" :label="$t('all_day')" />

    {{-- Inicio y fin: la fecha siempre; la hora solo si el evento no es de día completo. --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div x-bind:class="$wire.allDay ? 'sm:col-span-2' : ''">
            <x-kore::datepicker
                wire:model="startDate"
                :label="$t('form_start_date')"
                :placeholder="$t('form_pick_date')"
                :locale="$calendarLocale"
                :start-of-week="$weekStart"
                required
            />
        </div>
        <div x-show="! $wire.allDay">
            <x-kore::time-picker wire:model="startTime" :label="$t('form_start_time')" :placeholder="$t('form_pick_time')" :minute-step="5" />
        </div>

        <div x-bind:class="$wire.allDay ? 'sm:col-span-2' : ''">
            <x-kore::datepicker
                wire:model="endDate"
                :label="$t('form_end_date')"
                :placeholder="$t('form_pick_date')"
                :locale="$calendarLocale"
                :start-of-week="$weekStart"
                clearable
            />
        </div>
        <div x-show="! $wire.allDay">
            <x-kore::time-picker wire:model="endTime" :label="$t('form_end_time')" :placeholder="$t('form_pick_time')" :minute-step="5" clearable />
        </div>
    </div>

    @if ($resources !== [])
        <x-kore::select
            wire:model="resourceId"
            :label="$t('form_resource')"
            :options="$resources"
            :placeholder="$t('form_no_resource')"
            :searchable="count($resources) > 8"
            clearable
        />
    @endif

    {{--
        El color se elige viéndolo. Antes era un select con «Paleta 1… Paleta 8»: había que abrirlo
        y probar para saber cuál era cuál. Son radios de verdad —un grupo, una parada del tabulador,
        flechas para moverse— con el nombre de cada color para el lector de pantalla.
    --}}
    <fieldset class="flex flex-col gap-1.5">
        <legend class="text-sm font-medium text-kore-fg">{{ $t('form_color') }}</legend>

        <div class="flex flex-wrap items-center gap-2">
            <label class="relative inline-flex cursor-pointer" title="{{ $t('form_no_color') }}">
                <input type="radio" class="peer sr-only" wire:model="color" value="" />
                <span class="flex size-7 items-center justify-center rounded-full border border-kore-input bg-kore-surface text-kore-muted-fg ring-offset-2 ring-offset-kore-surface kore-transition peer-checked:ring-2 peer-checked:ring-kore-fg peer-focus-visible:ring-2 peer-focus-visible:ring-kore-ring" aria-hidden="true">
                    <x-lucide-ban class="size-3.5" />
                </span>
                <span class="sr-only">{{ $t('form_no_color') }}</span>
            </label>

            @foreach ($koreColors as $option)
                <label class="relative inline-flex cursor-pointer" title="{{ $option['label'] }}">
                    <input type="radio" class="peer sr-only" wire:model="color" value="{{ $option['value'] }}" />
                    <span class="block size-7 rounded-full border border-kore-border ring-offset-2 ring-offset-kore-surface kore-transition peer-checked:ring-2 peer-checked:ring-kore-fg peer-focus-visible:ring-2 peer-focus-visible:ring-kore-ring" style="background: {{ \KoreUi\Calendar\View\EventColor::css($option['value']) }}" aria-hidden="true"></span>
                    <span class="sr-only">{{ $option['label'] }}</span>
                </label>
            @endforeach
        </div>

        @error('color')
            <p class="text-sm text-kore-destructive-text" role="alert">{{ $message }}</p>
        @enderror
    </fieldset>

    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-kore-border pt-4">
        <div>
            @if ($mode === 'edit')
                <x-kore::button type="button" variant="ghost" color="destructive" wire:click="delete" :label="$t('form_delete')" />
            @endif
        </div>

        <div class="flex gap-2">
            <x-kore::button type="button" variant="outline" color="secondary" wire:click="close" :label="$t('form_cancel')" />
            <x-kore::button type="submit" wire:loading.attr="disabled" wire:target="save" :label="$t('form_save')" />
        </div>
    </div>
</form>
