<?php

namespace KoreUi\Calendar\Layout;

use KoreUi\Calendar\Events\EventInstance;

/** El trozo de un evento dentro de una fila de días. Columnas [colStart, colEnd). */
final class DaySegment
{
    public function __construct(
        public readonly EventInstance $instance,
        public readonly int $row,
        public readonly int $colStart,
        public readonly int $colEnd,
        public readonly bool $isStart,
        public readonly bool $isEnd,
    ) {}
}
