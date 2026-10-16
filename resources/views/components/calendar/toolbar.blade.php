{{--
    Barra del calendario: anterior / hoy / siguiente, título y selector de vista.

    Dos modos:
      links    enlaces con ?date= y ?view= (componente anónimo, sin estado)
      livewire acciones del componente (KoreCalendar): previous, today, next, changeView

    El título es un h2 que anuncia los cambios con aria-live: al pulsar
    "siguiente", el lector de pantalla dice «Noviembre 2026». FullCalendar no
    anuncia la navegación.
--}}
@props([
    'calendar',
    'views' => [],
    'mode' => 'links',
    'dateParam' => 'date',
    'viewParam' => 'view',
])

@php
    $range = $calendar->range;
    $current = $calendar->settings->view;
    $link = fn (array $query) => request()->fullUrlWithQuery($query);
    $today = $calendar->now->toDateString();
@endphp

<div {{ $attributes->class('flex flex-wrap items-center gap-x-4 gap-y-2') }}>
    <div class="flex items-center gap-1">
        @if ($mode === 'livewire')
            <x-kore::button size="sm" variant="ghost" icon="chevron-left" wire:click="previous" :aria-label="$calendar->text('previous')" />
            <x-kore::button size="sm" variant="outline" wire:click="today" :label="$calendar->text('today')" />
            <x-kore::button size="sm" variant="ghost" icon="chevron-right" wire:click="next" :aria-label="$calendar->text('next')" />
        @else
            <x-kore::button size="sm" variant="ghost" icon="chevron-left" :href="$link([$dateParam => $range->previous()->toDateString()])" :aria-label="$calendar->text('previous')" />
            <x-kore::button size="sm" variant="outline" :href="$link([$dateParam => $today])" :label="$calendar->text('today')" />
            <x-kore::button size="sm" variant="ghost" icon="chevron-right" :href="$link([$dateParam => $range->next()->toDateString()])" :aria-label="$calendar->text('next')" />
        @endif
    </div>

    <h2 class="min-w-0 flex-1 truncate text-lg font-semibold" aria-live="polite" aria-atomic="true">{{ $calendar->title() }}</h2>

    @if (count($views) > 1)
        <div role="group" aria-label="{{ $calendar->text('views') }}" class="flex flex-wrap gap-1">
            @foreach ($views as $view)
                @php
                    $active = $view === $current;
                @endphp
                @if ($mode === 'livewire')
                    <x-kore::button size="sm" :variant="$active ? 'solid' : 'ghost'" wire:click="changeView('{{ $view }}')" :label="$calendar->text('view_'.$view)" :aria-pressed="$active ? 'true' : 'false'" />
                @else
                    <x-kore::button size="sm" :variant="$active ? 'solid' : 'ghost'" :href="$link([$viewParam => $view])" :label="$calendar->text('view_'.$view)" :aria-current="$active ? 'page' : null" />
                @endif
            @endforeach
        </div>
    @endif
</div>
