{{--
    Timeline: el tiempo en horizontal, un recurso por fila. Recibe un
    RenderedCalendar cuyo layout es un TimelineLayout.

    La columna de recursos es fija a la izquierda y la cabecera arriba; el resto
    hace scroll en los dos ejes. Los eventos llegan en porcentaje del eje
    (--kc-left, --kc-width) y en su subfila (--kc-level).

    Con muchos recursos solo se monta una ventana de filas; los espaciadores de
    arriba y abajo ocupan el alto estimado del resto, y KoreCalendarTimeline pide
    al servidor la ventana nueva al hacer scroll (solo dentro de KoreCalendar).
--}}
@props(['calendar', 'mode' => 'links'])

@php
    $layout = $calendar->layout;
    $slotCount = count($layout->slots);
    $daySlots = $layout->slotSeconds >= 86400;
    $virtual = $layout->totalRows > count($layout->rows);

    // Dónde se abre el eje (scrollTime): "ahora" si cae dentro, si no la hora
    // configurada. Solo en vistas por horas; un timeline de días empieza al principio.
    $scrollTo = $daySlots ? 0 : ($layout->nowLeft ?? round($calendar->settings->timelineScrollTime / max(1, $layout->axisSeconds) * 100, 4));
    // Las filas miden todo el contenido (w-max): un sticky no sale de su caja, y con
    // filas del ancho visible la columna de recursos se iba con el scroll horizontal.
    $template = 'var(--kore-calendar-resource-width) minmax(calc('.$slotCount.' * var(--kore-calendar-timeline-slot-min)), 1fr)';
@endphp

@assets
    <script src="{{ \KoreUi\Calendar\KoreCalendarServiceProvider::scriptUrl() }}" defer></script>
@endassets

<div
    role="table"
    aria-label="{{ $calendar->title() }}"
    x-data="KoreCalendarTimeline({ live: {{ $virtual && $mode === 'livewire' ? 'true' : 'false' }}, scrollTo: {{ $scrollTo }} })"
    x-on:scroll.throttle.150ms="onScroll()"
    style="--kc-slots: {{ $slotCount }}"
    {{ $attributes->class('kore-calendar-timeline max-h-[44rem] overflow-auto rounded-kore-lg border border-kore-border bg-kore-bg text-kore-fg') }}
>
    <div role="rowgroup" class="sticky top-0 z-30 bg-kore-bg">
        <div role="row" class="grid w-max min-w-full border-b border-kore-border" style="grid-template-columns: {{ $template }}">
            <div role="columnheader" class="sticky start-0 z-10 border-e border-kore-border bg-kore-bg px-3 py-2 text-xs font-medium text-kore-muted-fg">{{ $calendar->text('resources') }}</div>
            <div class="grid" style="grid-template-columns: repeat({{ $slotCount }}, minmax(0, 1fr))">
                @foreach ($layout->slots as $slot)
                    <div
                        role="columnheader"
                        @class([
                            'truncate border-s border-kore-border px-1.5 py-2 text-xs tabular-nums text-kore-muted-fg',
                            'font-medium text-kore-fg' => $slot['isMajor'] && ! $daySlots,
                            'kore-calendar-day-today' => $daySlots && $slot['label']->startOfDay()->equals($calendar->now->startOfDay()),
                        ])
                    >
                        @if ($daySlots)
                            {{ $calendar->format($slot['label'], 'day_header') }}
                        @else
                            {{ $calendar->time($slot['label']) }}
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- La ventana viaja en data-*, no en x-data: el morph reescribe atributos pero no reevalúa x-data. --}}
    <div
        role="rowgroup"
        class="relative"
        x-data="{ collapsed: {} }"
        data-kore-timeline-window
        data-row-start="{{ $layout->rowsBefore }}"
        data-row-count="{{ count($layout->rows) }}"
        data-total="{{ $layout->totalRows }}"
    >
        @if ($layout->rowsBefore > 0)
            <div aria-hidden="true" data-kore-timeline-spacer style="height: calc({{ $layout->rowsBefore }} * var(--kore-calendar-timeline-row-min))"></div>
        @endif

        @foreach ($layout->rows as $row)
            @if ($row['type'] === 'group')
                <div role="row" wire:key="kore-timeline-{{ $row['key'] }}" class="grid w-max min-w-full border-b border-kore-border bg-kore-muted/40" style="grid-template-columns: {{ $template }}">
                    <div role="rowheader" class="sticky start-0 z-10 bg-kore-muted px-2 py-1.5">
                        <button
                            type="button"
                            x-on:click="collapsed[{{ Js::from($row['key']) }}] = ! collapsed[{{ Js::from($row['key']) }}]"
                            x-bind:aria-expanded="! collapsed[{{ Js::from($row['key']) }}]"
                            aria-label="{{ $calendar->text('toggle_group', ['group' => $row['label']]) }}"
                            class="flex items-center gap-1.5 rounded-kore-sm px-1 text-xs font-semibold hover:bg-kore-muted focus-visible:outline-2 focus-visible:outline-kore-ring"
                        >
                            <x-lucide-chevron-down class="size-3.5 transition-transform" x-bind:class="collapsed[{{ Js::from($row['key']) }}] && '-rotate-90'" aria-hidden="true" />
                            {{ $row['label'] }}
                            <span class="font-normal text-kore-muted-fg">{{ $row['count'] }}</span>
                        </button>
                    </div>
                    <div role="cell" aria-hidden="true"></div>
                </div>
            @else
                <div
                    role="row"
                    wire:key="kore-timeline-{{ $row['key'] }}"
                    @if ($row['group'] !== null) x-show="! collapsed[{{ Js::from('g:'.$row['group']) }}]" @endif
                    class="grid w-max min-w-full border-b border-kore-border last:border-b-0"
                    style="grid-template-columns: {{ $template }}"
                >
                    <div role="rowheader" class="sticky start-0 z-10 flex items-center border-e border-kore-border bg-kore-bg py-1.5 pe-2 text-sm" style="padding-inline-start: calc(0.75rem + {{ $row['depth'] }} * 1rem)">
                        @if ($row['resource']?->color)
                            <span class="kore-calendar-dot me-2 size-2 shrink-0 rounded-full" style="{{ \KoreUi\Calendar\View\EventColor::style($row['resource']->color) }}" aria-hidden="true"></span>
                        @endif
                        <span class="truncate">{{ $row['resource']?->title ?? $calendar->title() }}</span>
                    </div>

                    <div role="cell" class="kore-calendar-timeline-lane relative" style="--kc-levels: {{ $row['levels'] }}">
                        <ul class="absolute inset-0">
                            @foreach ($row['events'] as $item)
                                <li
                                    wire:key="kore-timeline-{{ $row['key'] }}-{{ $item['key'] }}"
                                    class="kore-calendar-timeline-slot"
                                    style="--kc-left: {{ $item['left'] }}; --kc-width: {{ $item['width'] }}; --kc-level: {{ $item['level'] }}"
                                >
                                    <x-kore::calendar.event :calendar="$calendar" :instance="$item['instance']" :is-start="$item['isStart']" :is-end="$item['isEnd']" class="h-full" />
                                </li>
                            @endforeach
                        </ul>

                        @if ($calendar->settings->nowIndicator && $layout->nowLeft !== null)
                            <div class="kore-calendar-now-vertical" style="--kc-left: {{ $layout->nowLeft }}" aria-hidden="true"></div>
                        @endif
                    </div>
                </div>
            @endif
        @endforeach

        @if ($layout->rowsAfter > 0)
            <div aria-hidden="true" data-kore-timeline-spacer style="height: calc({{ $layout->rowsAfter }} * var(--kore-calendar-timeline-row-min))"></div>
        @endif
    </div>
</div>
