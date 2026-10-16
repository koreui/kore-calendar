<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

/** Pide la semana con `defaultView()`, para probar que `configure()` gana a `default_view` del config. */
class WeekDefaultCalendar extends PlainCalendar
{
    public function configure(): void
    {
        $this->defaultView('week');
    }
}
