<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

class TimelineCalendar extends TeamCalendar
{
    public function configure(): void
    {
        $this->views(['month', 'timeline-day', 'timeline-week'])->timezone('Europe/Madrid');
    }
}
