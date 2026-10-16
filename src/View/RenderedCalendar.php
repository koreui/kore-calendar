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
    /** Cuántos títulos nombra el aviso de conflicto de un evento antes de pasar a «y N más». */
    private const CONFLICT_TITLES = 3;

    private ?string $keyboardHelpId = null;

    /** @var array<string, array<int, string>> `format()` ya calculados: patrón → instante → texto. */
    private array $formatted = [];

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

    /**
     * El texto para lectores de pantalla de un evento en conflicto: «En conflicto con «A», «B», «C» y 4 más».
     * `null` si no lo está.
     *
     * Cita como mucho `CONFLICT_TITLES` títulos distintos y cuenta el resto de eventos en «y N más»: el texto
     * de cada evento crecía con todos sus conflictos (en una semana muy cargada, cientos de títulos por evento,
     * miles de bytes de HTML para algo que un lector de pantalla no lee entero). El total se conserva: cada
     * evento en conflicto, o está nombrado, o entra en la cuenta. Dos con el mismo título se nombran una vez.
     */
    public function conflictLabel(EventInstance $instance): ?string
    {
        $others = $this->conflictsOf($instance);

        if ($others === []) {
            return null;
        }

        $named = [];
        $more = 0;

        foreach ($others as $other) {
            $title = $other->event->title;

            if (isset($named[$title])) {
                continue;
            }

            if (count($named) < self::CONFLICT_TITLES) {
                $named[$title] = $this->text('event_quoted', ['title' => $title]);
            } else {
                $more++;
            }
        }

        $with = implode(', ', $named);

        return $this->text('conflict', ['with' => $more > 0 ? $this->text('conflict_more', ['titles' => $with, 'count' => $more]) : $with]);
    }

    /** Una fecha con uno de los formatos del config (`formats.*`), o un patrón isoFormat directo. */
    public function format(Marker $date, string $format): string
    {
        $pattern = $this->settings->formats[$format] ?? $format;

        // Las vistas piden la misma hora una vez por evento (y las del mismo minuto, cientos de veces): se formatea
        // una vez por (patrón, instante). El idioma es el del calendario, fijo en esta instancia.
        return $this->formatted[$pattern][$date->ts] ??= $date->toCarbon()->locale($this->settings->locale)->isoFormat($pattern);
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
