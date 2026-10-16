{{--
    Vista mes. Recibe un RenderedCalendar cuyo layout es un MonthLayout.

    Semántica de FullCalendar 7.1.2: role=table (no grid, para que JAWS no salga
    del modo lectura) + navegación con flechas propia (KoreCalendarGrid). Cada
    evento vive en la lista de la celda donde empieza y se extiende con CSS por
    las columnas que ocupa: el lector de pantalla lo lee en su día, y la rejilla
    sigue siendo filas de celdas.
--}}
@props(['calendar'])

@php
    $layout = $calendar->layout;
    $columns = $layout->columns;
    $weekNumbers = $calendar->settings->weekNumbers !== null;
    $template = ($weekNumbers ? '2.5rem ' : '').'repeat('.$columns.', minmax(0, 1fr))';

    // Roving tabindex: una sola celda entra en el orden de tabulación, hoy si se ve.
    $flat = collect($layout->rows)->flatMap(fn ($w) => $w['days'])->values();
    $focusIndex = max(0, (int) $flat->search(fn ($d) => $d['isToday']));
@endphp

@assets
    <script src="{{ \KoreUi\Calendar\KoreCalendarServiceProvider::scriptUrl() }}" defer></script>
@endassets

<div
    role="table"
    aria-label="{{ $calendar->title() }}"
    x-data="KoreCalendarGrid({ columns: {{ $columns }} })"
    x-on:keydown="onKeydown($event)"
    {{ $attributes->class('kore-calendar-month overflow-hidden rounded-kore-lg border border-kore-border bg-kore-bg text-kore-fg') }}
>
    <div role="rowgroup">
        <div role="row" class="grid border-b border-kore-border" style="grid-template-columns: {{ $template }}">
            @if ($weekNumbers)
                <div role="columnheader" class="px-1 py-2 text-center text-xs text-kore-muted-fg">{{ $calendar->text('week') }}</div>
            @endif
            @foreach (array_slice($layout->rows[0]['days'] ?? [], 0, $columns) as $cell)
                <div role="columnheader" class="px-2 py-2 text-center text-xs font-medium uppercase tracking-wide text-kore-muted-fg">
                    {{ $calendar->format($cell['date'], 'weekday') }}
                </div>
            @endforeach
        </div>
    </div>

    <div role="rowgroup">
        @foreach ($layout->rows as $r => $row)
            <div role="row" wire:key="kore-calendar-week-{{ $row['days'][0]['date']->ts }}" class="grid min-h-24 border-b border-kore-border last:border-b-0" style="grid-template-columns: {{ $template }}">
                @if ($weekNumbers)
                    <div role="rowheader" class="px-1 pt-1.5 text-center text-xs tabular-nums text-kore-muted-fg">
                        <span class="sr-only">{{ $calendar->text('week_number', ['number' => $row['weekNumber']]) }}</span>
                        <span aria-hidden="true">{{ $row['weekNumber'] }}</span>
                    </div>
                @endif

                @foreach ($row['days'] as $c => $cell)
                    @php
                        $starting = array_values(array_filter($row['slices'], fn ($s) => $s['colStart'] === $c));
                    @endphp
                    <div
                        role="cell"
                        @unless ($cell['isDisabled']) data-kore-drop="day" data-date="{{ $cell['date']->ts }}" @endunless
                        @if ($cell['isToday']) aria-current="date" @endif
                        @class([
                            'relative flex min-w-0 flex-col gap-0.5 border-kore-border pb-1',
                            'border-l' => $c > 0 || $weekNumbers,
                            'kore-calendar-day-today' => $cell['isToday'],
                            'bg-kore-muted/40' => $cell['isDisabled'],
                        ])
                    >
                        <div
                            data-kore-calendar-cell="{{ $r * $columns + $c }}"
                            tabindex="{{ $r * $columns + $c === $focusIndex ? '0' : '-1' }}"
                            class="flex justify-end px-1.5 pt-1 text-xs focus-visible:outline-2 focus-visible:outline-kore-ring"
                        >
                            <span class="sr-only">{{ $calendar->format($cell['date'], 'day_header_long') }}</span>
                            <span
                                aria-hidden="true"
                                @class([
                                    'grid size-6 place-items-center rounded-full tabular-nums',
                                    'bg-kore-primary font-semibold text-kore-primary-fg' => $cell['isToday'],
                                    'text-kore-muted-fg' => ! $cell['isToday'] && $cell['isOther'],
                                ])
                            >{{ $calendar->format($cell['date'], 'day_number') }}</span>
                        </div>

                        @unless ($cell['isDisabled'])
                            <ul class="kore-calendar-month-events" style="--kc-rows: {{ $row['rowCount'] }}">
                                @foreach ($starting as $slice)
                                    <li
                                        wire:key="kore-calendar-slice-{{ $slice['key'] }}"
                                        class="kore-calendar-month-slot z-10"
                                        style="--kc-row: {{ $slice['row'] }}; --kc-span: {{ $slice['colEnd'] - $slice['colStart'] }}"
                                    >
                                        <x-kore::calendar.event
                                            :calendar="$calendar"
                                            :instance="$slice['instance']"
                                            :display="$slice['display']"
                                            :is-start="$slice['isStart']"
                                            :is-end="$slice['isEnd']"
                                            class="h-full"
                                        />
                                    </li>
                                @endforeach
                            </ul>

                            @if ($cell['hiddenCount'] > 0)
                                <x-kore::calendar.more :calendar="$calendar" :date="$cell['date']" :count="$cell['hiddenCount']" :instances="$cell['popover']" class="px-0.5" />
                            @endif
                        @endunless
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
