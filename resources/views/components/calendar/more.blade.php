{{--
    "+N más" y su popover con TODOS los eventos de la celda (no solo los ocultos),
    como FullCalendar: el popover se arma con las fuentes que cruzan la columna, una
    vez cada una (#6397).

    No se teletransporta: vive dentro de la celda, así que el Escape lo maneja él
    mismo y cumple el contrato del overlay de kore-ui (preventDefault solo si había
    algo que cerrar).
--}}
@props([
    'calendar',
    'date',
    'count',
    'instances' => [],
])

@php
    $label = $calendar->format($date, 'day_header_long');
    // Determinista entre renders (IdContext de kore-ui): con un id aleatorio el
    // morph reemplazaría el nodo y el aria-controls apuntaría a un id que ya no existe.
    $id = \KoreUi\Core\Support\IdContext::secuencia('kore-calendar-more');
@endphp

<div x-data="{ open: false }" class="relative" x-on:keydown.escape="if (open) { open = false; $event.preventDefault(); $nextTick(() => $refs.trigger.focus()) }">
    <button
        type="button"
        x-ref="trigger"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open"
        aria-haspopup="dialog"
        aria-controls="{{ $id }}"
        aria-label="{{ $calendar->text('more_label', ['count' => $count, 'date' => $label]) }}"
        class="w-full rounded-kore-sm px-1.5 text-left text-xs font-medium text-kore-muted-fg hover:bg-kore-muted hover:text-kore-fg focus-visible:outline-2 focus-visible:outline-kore-ring"
    >{{ $calendar->text('more', ['count' => $count]) }}</button>

    <div
        id="{{ $id }}"
        role="dialog"
        aria-label="{{ $calendar->text('events_of', ['date' => $label]) }}"
        x-show="open"
        x-cloak
        x-kore-trap="open"
        x-on:click.outside="open = false"
        class="absolute start-0 top-0 z-40 w-56 rounded-kore-lg border border-kore-border bg-kore-surface p-2 text-kore-surface-fg shadow-lg"
    >
        <div class="mb-1.5 flex items-center justify-between gap-2">
            <span class="text-sm font-medium">{{ $label }}</span>
            <button type="button" x-on:click="open = false; $nextTick(() => $refs.trigger.focus())" aria-label="{{ $calendar->text('close') }}" class="rounded-kore-sm p-0.5 text-kore-muted-fg hover:bg-kore-muted hover:text-kore-fg">
                <x-lucide-x class="size-4" aria-hidden="true" />
            </button>
        </div>
        <ul class="space-y-1">
            @foreach ($instances as $instance)
                <li>
                    <x-kore::calendar.event :calendar="$calendar" :instance="$instance" :display="$instance->allDay ? 'block' : 'list-item'" />
                </li>
            @endforeach
        </ul>
    </div>
</div>
