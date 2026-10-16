<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\KoreCalendar;

/** Editable pero sin implementar los hooks de persistencia: lo que pasa por defecto. */
class UnimplementedCalendar extends KoreCalendar
{
    public function configure(): void
    {
        $this->defaultView('week')->timezone('Europe/Madrid')->firstDay(1)->editable()->selectable();
    }

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return InteractiveCalendar::defaultEvents();
    }
}
