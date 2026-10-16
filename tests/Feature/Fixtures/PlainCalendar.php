<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\KoreCalendar;

class PlainCalendar extends KoreCalendar
{
    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return [];
    }
}
