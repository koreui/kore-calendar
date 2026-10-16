<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\KoreCalendar;

/** Una agenda histórica: `validRange` amplía la ventana de ±200 años alrededor de hoy. */
class HistoricCalendar extends KoreCalendar
{
    protected function configure(): void
    {
        $this->views(['month'])->timezone('Europe/Madrid')->validRange('1500-01-01', '2100-12-31');
    }

    protected function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return [];
    }
}
