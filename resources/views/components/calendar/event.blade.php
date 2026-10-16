{{--
    Un evento. Lo usan todas las vistas; cambia la forma según `display`:
      block     barra (día completo o varios días) — mes y carril de todo el día
      list-item punto + hora + título              — evento con hora de un solo día en el mes
      timed     bloque en la rejilla horaria        — con hora, alto según duración

    Con `url` es un enlace; sin ella, un elemento no enfocable: el calendario de
    la fase 3 es de solo lectura (la interacción llega con la fase 6).
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
@endphp

<{{ $tag }}
    @if ($event->url) href="{{ $event->url }}" @endif
    data-kore-calendar-event="{{ $event->id }}"
    @if (! $isStart) data-continues-before @endif
    @if (! $isEnd) data-continues-after @endif
    @if ($style) style="{{ $style }}" @endif
    {{ $attributes->class([
        'block min-w-0 overflow-hidden text-xs leading-tight',
        'kore-calendar-event rounded-kore-sm px-1.5 py-0.5' => $display !== 'list-item',
        'flex items-center gap-1.5 rounded-kore-sm px-1 py-0.5 text-kore-fg hover:bg-kore-muted' => $display === 'list-item',
        'h-full' => $display === 'timed',
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
</{{ $tag }}>
