{{--
    Semana, día y sus variantes por recurso. Recibe un RenderedCalendar cuyo layout
    es un TimeGridLayout.

    Arriba la cabecera (con recursos, dos niveles), luego el carril de todo el día
    y después el eje de horas con una columna por día o por (día, recurso). Las
    posiciones llegan en porcentaje del eje (--kc-top, --kc-height, --kc-left,
    --kc-right): el navegador escala sin JavaScript.

    Las líneas de las ranuras no son un nodo por ranura y columna: cada columna lleva un solo
    div (.kore-calendar-slots) que las dibuja con degradados, con la hora en punto continua y las
    demás ranuras discontinuas y tenues. Los gestos no dependen de ellas: el destino es la celda
    (data-kore-drop) y la ranura se calcula por coordenadas. El número de ranuras y cada cuántas cae
    una hora (--kc-slots, --kc-hour) viajan en el style de la tabla.

    Horario laboral: lo que no lo es se pinta como bloques decorativos en % del eje
    (aria-hidden: la información accesible es la validación del servidor), y cada
    destino de arrastre lleva sus tramos laborables en data-business para que la
    previsualización del cliente se marque si cae fuera.
--}}
@props(['calendar'])

@php
    $layout = $calendar->layout;
    $columns = $layout->columns;
    $count = count($columns);
    $template = '4rem repeat('.$count.', minmax(0, 1fr))';
    $hasResources = collect($columns)->contains(fn ($c) => $c['resource'] !== null);
    $datesAbove = $calendar->settings->datesAboveResources;
    $focusIndex = max(0, (int) collect($columns)->search(fn ($c) => $c['isToday']));

    // Cabecera de dos niveles: grupos de columnas contiguas con la misma fecha
    // (fecha arriba) o el mismo recurso (recurso arriba).
    $groups = [];
    if ($hasResources) {
        foreach ($columns as $i => $c) {
            $key = $datesAbove ? 'd'.$c['date']->ts : 'r'.$c['resource']->id;
            if ($groups !== [] && $groups[count($groups) - 1]['key'] === $key) {
                $groups[count($groups) - 1]['span']++;
            } else {
                $groups[] = ['key' => $key, 'span' => 1, 'column' => $c];
            }
        }
    }
@endphp

@assets
    <script src="{{ \KoreUi\Calendar\KoreCalendarServiceProvider::scriptUrl() }}" defer></script>
@endassets

<div
    role="table"
    aria-label="{{ $calendar->title() }}"
    x-data="KoreCalendarGrid({ columns: {{ $count }} })"
    x-on:keydown="onKeydown($event)"
    style="--kc-min: calc(4rem + {{ $count }} * var(--kore-calendar-column-min)); --kc-slots: {{ count($layout->slots) }}; --kc-hour: {{ $layout->hourEvery() }}"
    {{ $attributes->class('kore-calendar-time-grid max-h-[44rem] overflow-auto rounded-kore-lg border border-kore-border bg-kore-bg text-kore-fg') }}
>
    {{-- Cabecera y todo el día: fijas arriba al hacer scroll por las horas --}}
    <div class="sticky top-0 z-30 bg-kore-bg">
    <div role="rowgroup" class="border-b border-kore-border">
        @if ($hasResources)
            <div role="row" class="grid min-w-[var(--kc-min)]" style="grid-template-columns: {{ $template }}">
                <div role="columnheader" aria-hidden="true"></div>
                @foreach ($groups as $g)
                    <div role="columnheader" class="truncate border-l border-kore-border px-2 py-1.5 text-center text-sm font-medium" style="grid-column: span {{ $g['span'] }}">
                        {{ $datesAbove ? $calendar->format($g['column']['date'], 'day_header') : $g['column']['resource']->title }}
                    </div>
                @endforeach
            </div>
        @endif
        <div role="row" class="grid min-w-[var(--kc-min)]" style="grid-template-columns: {{ $template }}">
            <div role="columnheader" aria-hidden="true"></div>
            @foreach ($columns as $i => $c)
                @php
                    $label = $hasResources && ! $datesAbove ? $calendar->format($c['date'], 'day_header') : ($c['resource']?->title ?? $calendar->format($c['date'], 'day_header'));
                @endphp
                <div
                    role="columnheader"
                    data-kore-calendar-cell="{{ $i }}"
                    tabindex="{{ $i === $focusIndex ? '0' : '-1' }}"
                    @if ($c['isToday']) aria-current="date" @endif
                    @class([
                        'kore-calendar-hit truncate border-l border-kore-border px-2 py-1.5 text-center text-xs focus-visible:outline-2 focus-visible:outline-kore-ring',
                        'font-semibold text-kore-primary-text' => $c['isToday'],
                        'text-kore-muted-fg' => ! $c['isToday'],
                    ])
                >
                    <span class="sr-only">{{ $calendar->format($c['date'], 'day_header_long') }}@if ($c['resource']), {{ $c['resource']->title }}@endif</span>
                    <span aria-hidden="true">{{ $label }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Todo el día --}}
    <div role="rowgroup" class="border-b border-kore-border">
        <div role="row" class="grid min-w-[var(--kc-min)]" style="grid-template-columns: {{ $template }}">
            <div role="rowheader" class="whitespace-nowrap px-1 py-1 text-right text-[10px] leading-tight text-kore-muted-fg">{{ $calendar->text('all_day') }}</div>
            @foreach ($columns as $i => $c)
                @php
                    $starting = array_values(array_filter($layout->allDay['slices'], fn ($s) => $s['colStart'] === $i));
                    $cell = $layout->allDay['cells'][$i];
                    $dayBusiness = $layout->hours[$i]['day'] ?? null;
                @endphp
                <div
                    role="cell"
                    data-kore-drop="allday"
                    data-date="{{ $c['date']->ts }}"
                    @if ($dayBusiness !== null) data-business="{{ $dayBusiness }}" @endif
                    @if ($c['resource']) data-resource="{{ $c['resource']->id }}" @endif
                    @class(['relative min-w-0 border-l border-kore-border py-0.5', 'kore-calendar-day-today' => $c['isToday']])
                >
                    <ul class="kore-calendar-month-events" style="--kc-rows: {{ $layout->allDay['rowCount'] }}">
                        @foreach ($starting as $slice)
                            <li wire:key="kore-calendar-allday-{{ $slice['key'] }}" class="kore-calendar-month-slot z-10" style="--kc-row: {{ $slice['row'] }}; --kc-span: {{ $slice['colEnd'] - $slice['colStart'] }}">
                                <x-kore::calendar.event :calendar="$calendar" :instance="$slice['instance']" :is-start="$slice['isStart']" :is-end="$slice['isEnd']" class="h-full" />
                            </li>
                        @endforeach
                    </ul>
                    @if ($cell['hiddenCount'] > 0)
                        <x-kore::calendar.more :calendar="$calendar" :date="$c['date']" :count="$cell['hiddenCount']" :instances="$cell['popover']" />
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    </div>

    {{-- Eje de horas --}}
    <div role="rowgroup">
        <div role="row" class="grid min-w-[var(--kc-min)]" style="grid-template-columns: {{ $template }}">
            <div role="rowheader" aria-hidden="true" class="relative">
                @foreach ($layout->slots as $slot)
                    <div class="h-[var(--kore-calendar-slot-height)] pr-2 text-right text-[11px] tabular-nums leading-none text-kore-muted-fg">
                        @if ($slot['start'] % 3600 === 0)<span class="relative -top-1.5">{{ $calendar->time($slot['label']) }}</span>@endif
                    </div>
                @endforeach
            </div>

            @foreach ($layout->lanes as $i => $lane)
                @php
                    $hours = $layout->hours[$i] ?? ['off' => [], 'axis' => null];
                @endphp
                <div
                    role="cell"
                    data-kore-drop="time"
                    data-origin="{{ $columns[$i]['date']->ts + $calendar->settings->layoutOptions->slotMin }}"
                    data-axis="{{ $layout->axisSeconds }}"
                    @if ($hours['axis'] !== null) data-business="{{ $hours['axis'] }}" @endif
                    @if ($columns[$i]['resource']) data-resource="{{ $columns[$i]['resource']->id }}" @endif
                    @class(['relative border-l border-kore-border', 'kore-calendar-day-today' => $columns[$i]['isToday']])
                >
                    <div aria-hidden="true" class="kore-calendar-slots"></div>

                    @foreach ($hours['off'] as $off)
                        <div aria-hidden="true" class="kore-calendar-non-business kore-calendar-non-business-v" style="--kc-top: {{ $off['top'] }}; --kc-height: {{ $off['height'] }}"></div>
                    @endforeach

                    <ul class="absolute inset-0 mx-0.5">
                        @foreach ($lane['events'] as $item)
                            <li
                                wire:key="kore-calendar-timed-{{ $item['key'] }}"
                                class="kore-calendar-timed"
                                @if ($item['marginEnd']) data-margin-end @endif
                                style="--kc-top: {{ $item['top'] }}; --kc-height: {{ $item['height'] }}; --kc-left: {{ $item['left'] }}; --kc-right: {{ $item['right'] }}; --kc-z: {{ $item['zIndex'] }}"
                            >
                                <x-kore::calendar.event
                                    :calendar="$calendar"
                                    :instance="$item['instance']"
                                    display="timed"
                                    :short="$item['isShort']"
                                    :is-start="$item['segment']->isStart"
                                    :is-end="$item['segment']->isEnd"
                                />
                            </li>
                        @endforeach

                        @foreach ($lane['hidden'] as $group)
                            <li wire:key="kore-calendar-hidden-{{ $group['key'] }}" class="kore-calendar-timed" style="--kc-top: {{ $group['top'] }}; --kc-height: {{ $group['height'] }}; --kc-left: 70; --kc-right: 0; --kc-z: 20">
                                <x-kore::calendar.more :calendar="$calendar" :date="$columns[$i]['date']" :count="count($group['instances'])" :instances="$group['instances']" />
                            </li>
                        @endforeach
                    </ul>

                    @if ($calendar->settings->nowIndicator && $layout->nowColumn === $i)
                        <div class="kore-calendar-now" style="--kc-top: {{ $layout->nowTop }}" aria-hidden="true"></div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
