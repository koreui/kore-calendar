<?php

namespace KoreUi\Calendar\Interaction;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\View\CalendarSettings;

/**
 * Cómo estaba un evento justo antes de moverlo o redimensionarlo, para poder
 * volver a dejarlo así.
 *
 * Vive en una propiedad pública `#[Locked]` del calendario, que Livewire
 * serializa entre peticiones: de ahí que todo sea escalares (enteros, bool,
 * listas), nunca un `Marker` ni un `Carbon`.
 *
 * Guarda los DOS instantes y los markers del estado anterior. Con los markers
 * solos, una hora del pliegue de otoño (dos instantes, un marker) se
 * restauraría a la otra; con los instantes solos, el evento de día completo
 * perdería su fin exclusivo. `endInstant` es `null` cuando el evento no tenía
 * fin: un deshacer no puede inventarle uno, o la columna `ends_at` pasaría de
 * NULL a un valor.
 *
 * No reconstruye el movimiento con `MutationBuilder`: mover un evento de día
 * completo a una hora y deshacerlo daría la duración por defecto de un evento
 * con hora, no los días que tenía.
 */
final readonly class UndoSnapshot
{
    /**
     * @param  'move'|'resize'  $type  El hook que lo guardó y que tiene que deshacerlo.
     * @param  list<string|int>  $resourceIds
     */
    public function __construct(
        public string $type,
        public string|int $id,
        public int $start,
        public int $end,
        public bool $allDay,
        public array $resourceIds,
        public int $startInstant,
        public ?int $endInstant,
    ) {}

    /** El estado ANTERIOR a la mutación. */
    public static function before(string $type, EventMutation $mutation): self
    {
        return new self(
            $type,
            $mutation->event->id,
            $mutation->previousStartMarker->ts,
            $mutation->previousEndMarker->ts,
            $mutation->previousAllDay,
            $mutation->previousResourceIds,
            $mutation->previousStart->getTimestamp(),
            $mutation->previousEnd?->getTimestamp(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * Defensivo aunque la propiedad esté bloqueada: un estado que no se entiende
     * es «nada que deshacer», no una excepción en mitad de un toast.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)
            || ! in_array($data['type'] ?? null, ['move', 'resize'], true)
            || ! (is_string($data['id'] ?? null) || is_int($data['id'] ?? null))
            || ! is_int($data['start'] ?? null) || ! is_int($data['end'] ?? null)
            || ! is_bool($data['allDay'] ?? null) || ! is_array($data['resourceIds'] ?? null)
            || ! is_int($data['startInstant'] ?? null)
            || ! (($data['endInstant'] ?? null) === null || is_int($data['endInstant']))
        ) {
            return null;
        }

        return new self(
            $data['type'], $data['id'], $data['start'], $data['end'], $data['allDay'],
            array_values($data['resourceIds']), $data['startInstant'], $data['endInstant'] ?? null,
        );
    }

    /**
     * La mutación que devuelve el evento a como estaba. `$current` es el evento tal
     * como está AHORA (resuelto entre los de la vista), no como se guardó: sus
     * valores `previous*` son los de después del cambio.
     */
    public function inverse(EventInstance $current, CalendarSettings $settings): EventMutation
    {
        $clock = $settings->clock;
        $range = $current->range;

        $startMarker = Marker::fromTimestamp($this->start);
        $endMarker = Marker::fromTimestamp($this->end);
        $end = $this->endInstant === null ? null : CarbonImmutable::createFromTimestamp($this->endInstant, $clock->zone);

        return new EventMutation(
            event: $current->event,
            start: CarbonImmutable::createFromTimestamp($this->startInstant, $clock->zone),
            end: $end,
            allDay: $this->allDay,
            resourceIds: $this->resourceIds,
            previousStart: $current->exactStart ?? $clock->toInstant($range->start),
            previousEnd: $current->hasEnd ? ($current->exactEnd ?? $clock->toInstant($range->end)) : null,
            previousAllDay: $current->allDay,
            previousResourceIds: $current->event->resourceIds,
            startMarker: $startMarker,
            endMarker: $endMarker,
            // Cuánto se mueve el borde que cambia: el inicio si cambia, y si no el fin.
            deltaSeconds: $startMarker->ts !== $range->start->ts ? $startMarker->ts - $range->start->ts : $endMarker->ts - $range->end->ts,
            endInstant: $end ?? $clock->toInstant($endMarker),
            previousStartMarker: $range->start,
            previousEndMarker: $range->end,
        );
    }
}
