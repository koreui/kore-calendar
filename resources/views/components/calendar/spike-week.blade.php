{{--
    PRUEBA TÉCNICA (fase 1). Se borra cuando la fase 3 traiga la vista semana de verdad.

    Valida tres cosas antes de construir el motor:
      1. Posiciones calculadas en el servidor (en %) + previsualización en Alpine.
      2. Un único viaje a Livewire al soltar, con aceptación o rechazo del servidor.
      3. Que un morph a mitad del gesto (un poll, otra acción) no rompa el arrastre.

    Contrato de cada evento: ['id', 'title', 'column' (0..N-1), 'start' y 'duration'
    en minutos desde el inicio del eje, 'locked' (bool, opcional)].
--}}
@props([
    'events' => [],
    'columns' => [],          // etiquetas de las columnas, ya formateadas
    'axisStart' => 480,       // minutos desde medianoche (08:00)
    'axisEnd' => 1080,        // 18:00
    'slotMinutes' => 30,
    'snapMinutes' => 15,
    'method' => 'moveEvent',
])

@php
    $axisMinutes = max(1, $axisEnd - $axisStart);
    $columnCount = max(1, count($columns));
    $slots = (int) ceil($axisMinutes / $slotMinutes);
    $hora = fn (int $min) => sprintf('%02d:%02d', intdiv($axisStart + $min, 60), ($axisStart + $min) % 60);
    $pct = fn (float $v) => round($v * 100, 4);
@endphp

@assets
    <script src="{{ \KoreUi\Calendar\KoreCalendarServiceProvider::scriptUrl() }}" defer></script>
@endassets

<div
    x-data="KoreCalendarDrag({{ Js::from([
        'method' => $method,
        'columns' => $columnCount,
        'axisMinutes' => $axisMinutes,
        'snapMinutes' => $snapMinutes,
    ]) }})"
    x-on:pointermove.window="moveDrag($event)"
    x-on:pointerup.window="endDrag()"
    x-on:pointercancel.window="cancelDrag()"
    x-on:keydown.escape.window="cancelDrag()"
    {{ $attributes->class('overflow-hidden rounded-kore-lg border border-kore-border bg-kore-bg text-kore-fg') }}
>
    {{-- Cabecera --}}
    <div class="grid border-b border-kore-border" style="grid-template-columns: 4rem repeat({{ $columnCount }}, minmax(0, 1fr))">
        <div></div>
        @foreach ($columns as $label)
            <div class="border-l border-kore-border px-2 py-2 text-center text-sm font-medium">{{ $label }}</div>
        @endforeach
    </div>

    <div class="flex">
        {{-- Eje de horas --}}
        <div class="w-16 shrink-0">
            @for ($i = 0; $i < $slots; $i++)
                <div class="h-10 pr-2 text-right text-xs text-kore-muted-fg">{{ $i % 2 === 0 ? $hora($i * $slotMinutes) : '' }}</div>
            @endfor
        </div>

        {{-- Rejilla: la referencia de medidas del gesto --}}
        <div x-ref="grid" class="relative grid flex-1" style="grid-template-columns: repeat({{ $columnCount }}, minmax(0, 1fr))">
            @for ($c = 0; $c < $columnCount; $c++)
                <div class="relative border-l border-kore-border">
                    @for ($i = 0; $i < $slots; $i++)
                        <div class="h-10 border-b border-kore-border/60"></div>
                    @endfor

                    @foreach (collect($events)->where('column', $c) as $event)
                        @php
                            $locked = (bool) ($event['locked'] ?? false);
                            $top = $pct($event['start'] / $axisMinutes);
                            $height = $pct($event['duration'] / $axisMinutes);
                        @endphp
                        <div
                            wire:key="spike-event-{{ $event['id'] }}"
                            role="button"
                            tabindex="0"
                            aria-label="{{ $event['title'] }}, {{ $hora($event['start']) }}–{{ $hora($event['start'] + $event['duration']) }}"
                            @unless ($locked)
                                x-on:pointerdown="startDrag($event, {{ Js::from($event['id']) }}, {{ $c }}, {{ (int) $event['start'] }}, {{ (int) $event['duration'] }})"
                                x-bind:class="isDragging({{ Js::from($event['id']) }}) && 'opacity-40'"
                            @endunless
                            @class([
                                'absolute inset-x-1 select-none overflow-hidden rounded-kore-md border-l-2 px-2 py-1 text-xs touch-none',
                                'cursor-grab border-kore-primary bg-kore-primary/15 text-kore-primary-text' => ! $locked,
                                'cursor-not-allowed border-kore-border bg-kore-muted text-kore-muted-fg' => $locked,
                            ])
                            style="top: {{ $top }}%; height: {{ $height }}%"
                        >
                            <div class="font-medium">{{ $event['title'] }}</div>
                            <div>{{ $hora($event['start']) }}</div>
                        </div>
                    @endforeach
                </div>
            @endfor

            {{-- Sombra del gesto: solo cliente, nunca la toca el servidor --}}
            <div
                aria-hidden="true"
                class="pointer-events-none absolute px-1"
                x-bind:style="shadowStyle"
                style="display: none"
            >
                <div
                    class="h-full rounded-kore-md border-2 border-dashed border-kore-primary bg-kore-primary/10"
                    x-bind:class="pending && 'animate-pulse'"
                ></div>
            </div>
        </div>
    </div>

    <div class="sr-only" aria-live="polite" x-text="announcement"></div>
</div>
