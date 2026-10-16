<?php

namespace KoreUi\Calendar\Layout;

use Collator;
use InvalidArgumentException;
use KoreUi\Calendar\Events\EventInstance;

/**
 * El orden de prioridad de los eventos: quién se coloca primero en el kernel.
 *
 * Mismo formato que `eventOrder` de FullCalendar (`'start,-duration,allDay,title'`
 * por defecto): campos separados por comas, `-` delante para descendente. Un
 * campo que no es de los cuatro conocidos se busca en `meta`.
 *
 * Se compara sobre el rango COMPLETO del evento, no sobre el trozo de la fila: un
 * evento largo conserva su prioridad en todas las semanas que cruza
 * (FullCalendar, "rendering of events across weeks stays consistent").
 *
 * Los nulos van al final, y el texto se compara con `Collator` cuando hay
 * `ext-intl` (`strcmp` ordena «Ñ» y las tildes donde no toca).
 */
final class EventOrder
{
    public const DEFAULT = 'start,-duration,allDay,title';

    /** @var list<array{field: string, dir: int}> */
    private readonly array $fields;

    private readonly ?Collator $collator;

    public function __construct(string $spec = self::DEFAULT, ?string $locale = null)
    {
        $fields = [];

        foreach (array_map('trim', explode(',', $spec)) as $part) {
            $desc = str_starts_with($part, '-');
            $field = ltrim($part, '-+');

            if ($field === '') {
                throw new InvalidArgumentException("Campo vacío en el orden de eventos «{$spec}».");
            }

            $fields[] = ['field' => $field, 'dir' => $desc ? -1 : 1];
        }

        $this->fields = $fields;
        $this->collator = class_exists(Collator::class) ? new Collator($locale ?? (function_exists('app') ? app()->getLocale() : 'en')) : null;
    }

    /**
     * @param  list<EventInstance>  $instances
     * @return list<EventInstance>
     */
    public function sort(array $instances): array
    {
        // usort es estable desde PHP 8: a igualdad, se respeta el orden de entrada.
        usort($instances, fn (EventInstance $a, EventInstance $b) => $this->compare($a, $b));

        return $instances;
    }

    public function compare(EventInstance $a, EventInstance $b): int
    {
        foreach ($this->fields as ['field' => $field, 'dir' => $dir]) {
            $va = $this->value($a, $field);
            $vb = $this->value($b, $field);

            // flexibleCompare de FullCalendar: los nulos al final SIEMPRE. Se
            // resuelven antes de aplicar la dirección, o un `-campo` los subiría.
            if ($va === null || $vb === null) {
                if ($va === null && $vb === null) {
                    continue;
                }

                return $va === null ? 1 : -1;
            }

            $cmp = $this->compareValues($va, $vb);

            if ($cmp !== 0) {
                return $cmp * $dir;
            }
        }

        return 0;
    }

    private function value(EventInstance $i, string $field): mixed
    {
        return match ($field) {
            'start' => $i->range->start->ts,
            'end' => $i->range->end->ts,
            'duration' => $i->range->seconds(),
            // Los eventos con hora van antes que los de día completo a igual inicio.
            'allDay' => (int) $i->allDay,
            'title' => $i->event->title,
            'id' => (string) $i->event->id,
            default => $i->event->meta[$field] ?? null,
        };
    }

    private function compareValues(mixed $a, mixed $b): int
    {
        if (is_string($a) && is_string($b)) {
            return $this->collator?->compare($a, $b) ?: strnatcasecmp($a, $b) <=> 0;
        }

        return $a <=> $b;
    }
}
