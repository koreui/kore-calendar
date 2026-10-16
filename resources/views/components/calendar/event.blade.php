{{--
    Un evento. Lo usan todas las vistas; cambia la forma según `display`:
      block     barra (día completo o varios días) — mes y carril de todo el día
      list-item punto + hora + título              — evento con hora de un solo día en el mes
      timed     bloque en la rejilla horaria        — con hora, alto según duración

    Con `url` es un enlace. Si el calendario es editable y el evento se puede
    mover, es enfocable y lleva sus datos en data-* para KoreCalendarInteraction
    (Espacio para agarrarlo con el teclado). Si se puede redimensionar, lleva un asa.

    `data-kore-event` es el id del evento, que comparten todas las ocurrencias de una
    serie; `data-kore-instance`, el que distingue cada una y el que se manda al servidor.

    Si el calendario tiene clic activo (eventForm() o un onEventClick() propio), un
    evento sin `url` también es enfocable aunque no se pueda mover: lleva
    data-clickable y Intro (o un clic) lo abre.
--}}
@props([
    'calendar',
    'instance',
    'display' => 'block',
    'isStart' => true,
    'isEnd' => true,
    'short' => false,
])

@php
    $event = $instance->event;
    $color = $event->color ?? collect($event->resourceIds)
        ->map(fn ($id) => $calendar->resources?->find($id)?->color)
        ->filter()
        ->first();
    $style = \KoreUi\Calendar\View\EventColor::style($color);
    $showTime = ! $instance->allDay && $isStart;
    $tag = $event->url ? 'a' : 'div';
    $movable = \KoreUi\Calendar\Interaction\Editability::canMove($event, $calendar->settings);
    // Un enlace ya hace lo suyo al pulsarlo: el clic del calendario es solo para los demás.
    $clickable = $calendar->settings->eventClick && ! $event->url;
    // El asa va en el borde real del evento: un trozo que sigue en la semana
    // siguiente no se redimensiona desde aquí.
    $resizable = $display !== 'list-item' && $isEnd && \KoreUi\Calendar\Interaction\Editability::canResize($event, $calendar->settings);
@endphp

<{{ $tag }}
    @if ($event->url) href="{{ $event->url }}" @endif
    data-kore-calendar-event="{{ $event->id }}"
    data-kore-event="{{ $event->id }}"
    data-kore-instance="{{ $instance->instanceId }}"
    data-title="{{ $event->title }}"
    data-start="{{ $instance->range->start->ts }}"
    data-end="{{ $instance->range->end->ts }}"
    data-all-day="{{ $instance->allDay ? '1' : '0' }}"
    @if ($movable) data-movable aria-describedby="kore-calendar-kbd-help" @endif
    @if ($clickable) data-clickable @endif
    @if (($movable || $clickable) && ! $event->url) tabindex="0" role="button" @endif
    @if ($resizable) data-resizable @endif
    @if (! $isStart) data-continues-before @endif
    @if (! $isEnd) data-continues-after @endif
    @if ($style) style="{{ $style }}" @endif
    {{ $attributes->class([
        'block min-w-0 overflow-hidden text-xs leading-tight',
        'kore-calendar-event rounded-kore-sm px-1.5 py-0.5' => $display !== 'list-item',
        'flex items-center gap-1.5 rounded-kore-sm px-1 py-0.5 text-kore-fg hover:bg-kore-muted' => $display === 'list-item',
        'h-full' => $display === 'timed',
        'relative' => $resizable,
        'cursor-grab touch-manipulation select-none' => $movable,
        'cursor-pointer' => $clickable && ! $movable,
    ]) }}
>
    @if ($display === 'list-item')
        <span class="kore-calendar-dot size-2 shrink-0 rounded-full" aria-hidden="true"></span>
        <span class="shrink-0 tabular-nums text-kore-muted-fg">{{ $calendar->time($instance->range->start) }}</span>
        <span class="truncate font-medium">{{ $event->title }}</span>
    @elseif ($display === 'timed')
        <span @class(['block font-medium', 'truncate' => $short])>
            @if ($short && $showTime)<span class="tabular-nums font-normal">{{ $calendar->time($instance->range->start) }}</span> @endif{{ $event->title }}
        </span>
        @unless ($short)
            <span class="block tabular-nums opacity-80">{{ $calendar->time($instance->range->start) }} – {{ $calendar->time($instance->range->end) }}</span>
        @endunless
    @else
        <span class="block truncate">
            @if ($showTime)<span class="tabular-nums">{{ $calendar->time($instance->range->start) }}</span> @endif<span class="font-medium">{{ $event->title }}</span>
        </span>
    @endif

    @if ($resizable)
        <span
            data-kore-resize="end"
            aria-hidden="true"
            @class([
                'kore-calendar-resize absolute',
                'inset-x-0 bottom-0 h-1.5 cursor-ns-resize' => $display === 'timed',
                'inset-y-0 end-0 w-1.5 cursor-ew-resize' => $display !== 'timed',
            ])
        ></span>
    @endif
</{{ $tag }}>
