<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\KoreCalendar;

/** 150 recursos: más que `timeline.virtualize_after` (100), así que el timeline monta una ventana. */
class ManyResourcesCalendar extends KoreCalendar
{
    public function configure(): void
    {
        $this->views(['month', 'timeline-day'])->timezone('UTC');
    }

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return [];
    }

    public function resources(): iterable
    {
        return array_map(fn ($i) => ['id' => "r{$i}", 'title' => sprintf('Recurso %03d', $i)], range(1, 150));
    }
}
