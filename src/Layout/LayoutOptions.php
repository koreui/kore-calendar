<?php

namespace KoreUi\Calendar\Layout;

use KoreUi\Calendar\Time\Marker;

/**
 * Las opciones del motor de layout. Valores por defecto = los de FullCalendar,
 * con las alturas en píxeles traducidas a minutos: el servidor no conoce el alto
 * de nada y el kernel solo compara eventos entre sí (escalar todas las
 * coordenadas no cambia niveles ni colisiones, slat-estimate.ts).
 */
final class LayoutOptions
{
    public function __construct(
        public readonly EventOrder $order = new EventOrder,
        public readonly bool $orderStrict = false,
        /** Límite de eventos por celda en la vista mes (`dayMaxEvents`). */
        public readonly ?int $maxEvents = null,
        /** Límite de filas por celda, contando la del "+N más" (`dayMaxEventRows`). */
        public readonly ?int $maxEventRows = null,
        public readonly bool $slicing = true,
        public readonly int $nextDayThreshold = 0,
        public readonly int $slotMin = 0,
        public readonly int $slotMax = Marker::DAY,
        /** Duración mínima para colisionar y para el alto (`eventMinHeight`, 15 px ≈ 15 min con slots de 30 min). */
        public readonly int $eventMinSeconds = 15 * 60,
        /** Por debajo, el evento es "corto": una línea con hora y título (`eventShortHeight`). */
        public readonly int $eventShortSeconds = 30 * 60,
        public readonly ?int $maxStack = null,
        public readonly bool $slotEventOverlap = true,
        /** Límite de filas del carril de todo el día en la rejilla horaria. */
        public readonly ?int $allDayMaxEventRows = 5,
    ) {}
}
