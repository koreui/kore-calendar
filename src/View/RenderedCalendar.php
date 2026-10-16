<?php

namespace KoreUi\Calendar\View;

use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Interaction\Messages;
use KoreUi\Calendar\Layout\ListLayout;
use KoreUi\Calendar\Layout\MonthLayout;
use KoreUi\Calendar\Layout\TimeGridLayout;
use KoreUi\Calendar\Layout\TimelineLayout;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewRange;
use KoreUi\Core\Support\IdContext;

/**
 * Lo que reciben las vistas Blade. Incluye los formateadores, para que ninguna
 * vista tenga que saber de locale ni de formatos del config.
 */
final class RenderedCalendar
{
    private ?string $keyboardHelpId = null;

    public function __construct(
        public readonly CalendarSettings $settings,
        public readonly ViewRange $range,
        public readonly MonthLayout|TimeGridLayout|ListLayout|TimelineLayout $layout,
        public readonly Marker $now,
        public readonly ?ResourceSet $resources,
        /** @var array<string, list<EventInstance>> `instanceId` → los eventos con los que choca (vacío sin `highlightConflicts()`). */
        public readonly array $conflicts = [],
        /** El id del componente de Livewire que lo pinta (`KoreCalendar`). `null` en el calendario sin estado. */
        public readonly ?string $id = null,
    ) {}

    /**
     * El id del texto de ayuda del teclado, único por calendario.
     *
     * Los eventos movibles lo citan con `aria-describedby` y la vista de Livewire lo
     * pinta. Con un id fijo, dos calendarios en la página lo repetían y el segundo
     * apuntaba al texto del primero. Con Livewire lleva el id del componente: distinto
     * por calendario y el mismo en cada render del mismo (un contador no valdría: el
     * morph vería un id nuevo en cada petición). Sin él (el texto solo existe en la vista
     * de Livewire, pero las vistas se pueden pintar sueltas) se pide a `IdContext` de
     * kore-ui, el generador de ids de la librería. Se calcula una vez por render para que
     * el texto y los eventos coincidan.
     */
    public function keyboardHelpId(): string
    {
        return $this->keyboardHelpId ??= $this->id !== null
            ? 'kore-calendar-kbd-help-'.$this->id
            : IdContext::secuencia('kore-calendar-kbd-help');
    }

    /**
     * Los eventos con los que choca este (para marcarlo y decir con quién).
     *
     * @return list<EventInstance>
     */
    public function conflictsOf(EventInstance $instance): array
    {
        return $this->conflicts[$instance->instanceId] ?? [];
    }

    /** El texto para lectores de pantalla de un evento en conflicto: «En conflicto con «A», «B»». `null` si no lo está. */
    public function conflictLabel(EventInstance $instance): ?string
    {
        $others = $this->conflictsOf($instance);

        if ($others === []) {
            return null;
        }

        $titles = array_unique(array_map(fn (EventInstance $other) => $this->text('event_quoted', ['title' => $other->event->title]), $others));

        return $this->text('conflict', ['with' => implode(', ', $titles)]);
    }

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

    /** Un texto de interfaz en el idioma de este calendario (`Messages` lo resuelve, con los overrides del config). */
    public function text(string $key, array $replace = []): string
    {
        return Messages::get($key, $replace, $this->settings->locale);
    }
}
