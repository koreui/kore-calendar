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

    <x-kore::select wire:model="color" :label="$t('form_color')" :options="$koreColors" :placeholder="$t('form_no_color')" clearable />

    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
        <div>
            @if ($mode === 'edit')
                <x-kore::button type="button" variant="ghost" color="destructive" wire:click="delete" :label="$t('form_delete')" />
            @endif
        </div>

        <div class="flex gap-2">
            <x-kore::button type="button" variant="ghost" color="secondary" wire:click="close" :label="$t('form_cancel')" />
            <x-kore::button type="submit" wire:loading.attr="disabled" wire:target="save" :label="$t('form_save')" />
        </div>
    </div>
</form>
