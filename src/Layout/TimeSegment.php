<?php

namespace KoreUi\Calendar\Layout;

use KoreUi\Calendar\Events\EventInstance;

/** El trozo de un evento con hora dentro de una columna. Segundos desde el inicio del eje de esa columna. */
final class TimeSegment
{
    public function __construct(
        public readonly EventInstance $instance,
        public readonly int $column,
        public readonly int $start,
        public readonly int $end,
        public readonly bool $isStart,
        public readonly bool $isEnd,
    ) {}
}
