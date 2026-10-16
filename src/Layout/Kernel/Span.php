<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Un segmento de entrada para el kernel: el tramo de un evento en UNA fila de la
 * vista mes o en UNA columna horaria. Es geometría pura, sin fechas.
 *
 * La lista de spans que recibe un placer va YA ordenada por `eventOrder`, y la
 * posición en la lista es el `orderIndex`. El orden se calcula sobre el rango
 * completo del evento, no sobre el trozo de la fila: un evento largo conserva
 * su prioridad en todas las semanas.
 *
 * `start` es inclusivo y `end` exclusivo (`[start, end)`): dos spans que solo se
 * tocan no colisionan. En la vista mes son columnas enteras; en la rejilla
 * horaria, la unidad que sea (segundos) YA ajustada por la altura mínima.
 */
final class Span
{
    /**
     * @param  string  $key  Id estable del evento o de la instancia. Dentro de un lote tiene que ser único.
     * @param  mixed  $payload  Lo que el consumidor quiera recuperar (el evento, el segmento...). El kernel no lo mira.
     * @param  bool  $isStart  El evento empieza de verdad aquí (no está recortado por la fila o el rango).
     * @param  bool  $isEnd  El evento termina de verdad aquí.
     */
    public function __construct(
        public readonly string $key,
        public readonly int|float $start,
        public readonly int|float $end,
        public readonly mixed $payload = null,
        public readonly bool $isStart = true,
        public readonly bool $isEnd = true,
    ) {}
}
