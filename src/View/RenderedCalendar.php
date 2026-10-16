<?php

namespace KoreUi\Calendar\View;

use KoreUi\Calendar\Layout\ListLayout;
use KoreUi\Calendar\Layout\MonthLayout;
use KoreUi\Calendar\Layout\TimeGridLayout;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewRange;

/**
 * Lo que reciben las vistas Blade. Incluye los formateadores, para que ninguna
 * vista tenga que saber de locale ni de formatos del config.
 */
final class RenderedCalendar
{
    public function __construct(
        public readonly CalendarSettings $settings,
        public readonly ViewRange $range,
        public readonly MonthLayout|TimeGridLayout|ListLayout $layout,
        public readonly Marker $now,
        public readonly ?ResourceSet $resources,
    ) {}

    /** Una fecha con uno de los formatos del config (`formats.*`), o un patrón isoFormat directo. */
    public function format(Marker $date, string $format): string
    {
        $pattern = $this->settings->formats[$format] ?? $format;

        return $date->toCarbon()->locale($this->settings->locale)->isoFormat($pattern);
    }

    public function time(Marker $date): string
    {
        return $this->format($date, 'time');
    }

    /** El título de la cabecera: «octubre 2026», «12 – 18 oct 2026», «viernes, 16 de octubre de 2026». */
    public function title(): string
    {
        $current = $this->range->current;
        $last = $current->end->addDays(-1);

        return match ($this->range->unit) {
            'month' => mb_convert_case($this->format($current->start, 'title_month'), MB_CASE_TITLE),
            'day' => $this->format($current->start, 'title_day'),
            // Mismo mes: «12 – 18 oct 2026»; si cruza de mes: «28 sept – 4 oct 2026».
            default => ($current->start->month() === $last->month() ? $this->format($current->start, 'D') : $this->format($current->start, 'title_week_start'))
                .' – '.$this->format($last, 'title_week_end'),
        };
    }

    public function text(string $key, array $replace = []): string
    {
        $text = (string) config("kore-calendar.translations.{$key}", $key);

        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }
}
