<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\KoreCalendar;

/** Con el formulario y undoable, pero sin implementar ningún hook de persistencia: lo que pasa por defecto. */
class NoHooksCalendar extends KoreCalendar
{
    public function configure(): void
    {
        $this->defaultView('week')->timezone('Europe/Madrid')->firstDay(1)->editable()->eventForm()->undoable();
    }

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return InteractiveCalendar::defaultEvents();
    }
}
