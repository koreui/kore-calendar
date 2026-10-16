{{--
    Barra del calendario: anterior / hoy / siguiente, título y selector de vista.

    Dos modos:
      links    enlaces con ?date= y ?view= (componente anónimo, sin estado)
      livewire acciones del componente (KoreCalendar): previous, today, next, changeView

    El título es un h2 que anuncia los cambios con aria-live: al pulsar
    "siguiente", el lector de pantalla dice «Noviembre 2026». FullCalendar no
    anuncia la navegación.

    Ancho: la barra es su propio contenedor (@container) y decide por SU ancho, no por el de
    la pantalla, así que también se adapta dentro de un panel lateral. Por debajo de @2xl
    (42 rem) las vistas pasan de botones a un <select> nativo: con nueve vistas los botones
    ocupaban cuatro líneas en un móvil. El título cabe entero (hace wrap, nunca se trunca) y
    en estrecho va en su propia línea, encima de los controles. El orden visual cambia con
    `order`, pero el del DOM no (barra, título, vista): el título no se enfoca.

    El select hace lo mismo que los botones en cada modo. En livewire llama a changeView, que
    valida la vista como siempre; en links navega a la misma URL que el botón (data-href), con
    el resto de parámetros de la consulta conservados. Los dos grupos están en el DOM, pero el
    que no se ve lleva display: none, así que ningún lector de pantalla lo anuncia dos veces.
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
    $hasViews = count($views) > 1;
@endphp

<div {{ $attributes->class('@container') }}>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <div class="flex items-center gap-1">
            @if ($mode === 'livewire')
                <x-kore::button size="sm" variant="ghost" icon="chevron-left" class="kore-calendar-target" wire:click="previous" :aria-label="$calendar->text('previous')" />
                <x-kore::button size="sm" variant="outline" class="kore-calendar-target" wire:click="today" :label="$calendar->text('today')" />
                <x-kore::button size="sm" variant="ghost" icon="chevron-right" class="kore-calendar-target" wire:click="next" :aria-label="$calendar->text('next')" />
            @else
                <x-kore::button size="sm" variant="ghost" icon="chevron-left" class="kore-calendar-target" :href="$link([$dateParam => $range->previous()->toDateString()])" :aria-label="$calendar->text('previous')" />
                <x-kore::button size="sm" variant="outline" class="kore-calendar-target" :href="$link([$dateParam => $today])" :label="$calendar->text('today')" />
                <x-kore::button size="sm" variant="ghost" icon="chevron-right" class="kore-calendar-target" :href="$link([$dateParam => $range->next()->toDateString()])" :aria-label="$calendar->text('next')" />
            @endif
        </div>

        {{--
            min-w-48: sin un mínimo, en ancho normal el grupo de vistas (de una sola línea) se queda con todo el
            sitio y el título, con basis 0, se reduce a unas letras. Con él la línea se parte y las vistas bajan.
        --}}
        <h2
            @class([
                'break-words text-lg font-semibold',
                'order-first min-w-0 basis-full @2xl:order-none @2xl:min-w-48 @2xl:flex-1 @2xl:basis-0' => $hasViews,
                'min-w-48 flex-1' => ! $hasViews,
            ])
            aria-live="polite"
            aria-atomic="true"
        >{{ $calendar->title() }}</h2>

        @if ($hasViews)
            <div role="group" aria-label="{{ $calendar->text('views') }}" class="hidden flex-wrap gap-1 @2xl:flex">
                @foreach ($views as $view)
                    @php
                        $active = $view === $current;
                    @endphp
                    @if ($mode === 'livewire')
                        <x-kore::button size="sm" :variant="$active ? 'solid' : 'ghost'" class="kore-calendar-target" wire:click="changeView('{{ $view }}')" :label="$calendar->text('view_'.$view)" :aria-pressed="$active ? 'true' : 'false'" />
                    @else
                        <x-kore::button size="sm" :variant="$active ? 'solid' : 'ghost'" class="kore-calendar-target" :href="$link([$viewParam => $view])" :label="$calendar->text('view_'.$view)" :aria-current="$active ? 'page' : null" />
                    @endif
                @endforeach
            </div>

            <div class="min-w-0 flex-1 @2xl:hidden">
                <select
                    aria-label="{{ $calendar->text('views') }}"
                    @if ($mode === 'livewire')
                        wire:change="changeView($event.target.value)"
                    @else
                        x-data
                        x-on:change="location.href = $event.target.selectedOptions[0].dataset.href"
                    @endif
                    class="kore-calendar-select kore-calendar-target block w-full rounded-kore-md border border-kore-input bg-kore-bg px-3 py-1.5 text-kore-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-kore-ring"
                >
                    @foreach ($views as $view)
                        <option value="{{ $view }}" @selected($view === $current) @if ($mode !== 'livewire') data-href="{{ $link([$viewParam => $view]) }}" @endif>{{ $calendar->text('view_'.$view) }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>
</div>
