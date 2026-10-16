<?php

namespace KoreUi\Calendar\Events;

use DateTimeInterface;
use InvalidArgumentException;
use TypeError;

/**
 * Un evento tal como lo entrega el consumidor: fechas sin normalizar, sin zona
 * aplicada. Convertirlo a la rejilla (markers, fin por defecto, día completo)
 * es trabajo de `EventNormalizer`; este DTO solo se asegura de que lo que
 * guarda tiene sentido.
 *
 * Estricto a propósito. FullCalendar mete cualquier clave que no conoce en
 * `extendedProps` en silencio, así que una errata como `colour` no da error y el
 * evento sale sin color. Aquí `fromArray()` rechaza lo desconocido y lista las
 * claves válidas. Los datos libres tienen su sitio: `meta`.
 *
 * Los `null` de `editable`, `startEditable`, `durationEditable` y `overlap`
 * significan "que decida la cascada" (evento → fuente → calendario), no `false`.
 */
final readonly class CalendarEvent
{
    public const DISPLAYS = ['auto', 'block', 'list-item', 'background', 'none'];

    /** Claves que acepta `fromArray()`, además del atajo `resourceId`. Un test las compara con el constructor. */
    public const KEYS = [
        'id', 'title', 'start', 'end', 'allDay', 'resourceIds', 'groupId', 'color', 'url',
        'display', 'editable', 'startEditable', 'durationEditable', 'overlap', 'meta',
    ];

    /** Lo mínimo para que el evento exista; el resto tiene un valor por defecto. */
    private const REQUIRED = ['id', 'title', 'start'];

    /**
     * Sin repetidos y reindexada: un id repetido pintaría el evento dos veces en la misma columna.
     *
     * @var list<string|int>
     */
    public array $resourceIds;

    /**
     * @param  list<string|int>  $resourceIds
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string|int $id,
        public string $title,
        public DateTimeInterface|string $start,
        public DateTimeInterface|string|null $end = null,
        public ?bool $allDay = null,
        array $resourceIds = [],
        public ?string $groupId = null,
        public ?string $color = null,
        public ?string $url = null,
        public string $display = 'auto',
        public ?bool $editable = null,
        public ?bool $startEditable = null,
        public ?bool $durationEditable = null,
        public ?bool $overlap = null,
        public array $meta = [],
    ) {
        if (self::isBlank($id)) {
            throw new InvalidArgumentException('CalendarEvent: el id no puede estar vacío.');
        }

        // Un string vacío no es "sin fin": es un dato mal construido (una columna
        // '' en vez de NULL). Se avisa aquí y no al pintar, lejos de la causa.
        if (self::isBlank($start)) {
            throw new InvalidArgumentException("Evento «{$id}»: `start` no puede estar vacío.");
        }

        if ($end !== null && self::isBlank($end)) {
            throw new InvalidArgumentException("Evento «{$id}»: `end` está vacío. Usa null si el evento no tiene fin.");
        }

        if (! in_array($display, self::DISPLAYS, true)) {
            throw new InvalidArgumentException(
                "Evento «{$id}»: display «{$display}» no válido. Valores permitidos: ".implode(', ', self::DISPLAYS).'.'
            );
        }

        // Dos eventos con groupId '' se moverían juntos sin que nadie los agrupara.
        if ($groupId !== null && trim($groupId) === '') {
            throw new InvalidArgumentException("Evento «{$id}»: `groupId` está vacío. Usa null si el evento no tiene grupo.");
        }

        $this->resourceIds = self::cleanResourceIds($id, $resourceIds);
    }

    /**
     * Mismos argumentos que el constructor: existe para leerse bien en el sitio
     * de la llamada (`CalendarEvent::make(id: 1, ...)`).
     *
     * @param  list<string|int>  $resourceIds
     * @param  array<string, mixed>  $meta
     */
    public static function make(
        string|int $id,
        string $title,
        DateTimeInterface|string $start,
        DateTimeInterface|string|null $end = null,
        ?bool $allDay = null,
        array $resourceIds = [],
        ?string $groupId = null,
        ?string $color = null,
        ?string $url = null,
        string $display = 'auto',
        ?bool $editable = null,
        ?bool $startEditable = null,
        ?bool $durationEditable = null,
        ?bool $overlap = null,
        array $meta = [],
    ): self {
        return new self(
            id: $id, title: $title, start: $start, end: $end, allDay: $allDay, resourceIds: $resourceIds,
            groupId: $groupId, color: $color, url: $url, display: $display, editable: $editable,
            startEditable: $startEditable, durationEditable: $durationEditable, overlap: $overlap, meta: $meta,
        );
    }

    /**
     * Desde un array (una fila, un JSON). Estricto: cualquier clave que no sea
     * un argumento del constructor lanza una excepción.
     *
     * `resourceId` (singular) es el atajo de `resourceIds: [x]` para el caso, muy
     * común, de un evento con un solo recurso. Con `null` equivale a ninguno
     * (una columna `resource_id` nullable), y no se admite junto a `resourceIds`:
     * no se puede saber cuál de los dos manda.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $allowed = [...self::KEYS, 'resourceId'];
        $unknown = array_values(array_diff(array_map('strval', array_keys($data)), $allowed));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'CalendarEvent::fromArray(): clave%s desconocida%s %s. Permitidas: %s. Los datos libres van en `meta`.',
                count($unknown) > 1 ? 's' : '',
                count($unknown) > 1 ? 's' : '',
                implode(', ', array_map(fn (string $k) => "«{$k}»", $unknown)),
                implode(', ', $allowed),
            ));
        }

        $missing = array_values(array_filter(self::REQUIRED, fn (string $k) => ! array_key_exists($k, $data)));

        if ($missing !== []) {
            throw new InvalidArgumentException('CalendarEvent::fromArray(): faltan claves obligatorias: '.implode(', ', $missing).'.');
        }

        if (array_key_exists('resourceId', $data)) {
            if (array_key_exists('resourceIds', $data)) {
                throw new InvalidArgumentException('CalendarEvent::fromArray(): usa `resourceId` o `resourceIds`, no los dos a la vez.');
            }

            $data['resourceIds'] = $data['resourceId'] === null ? [] : [$data['resourceId']];
            unset($data['resourceId']);
        }

        try {
            return new self(...$data);
        } catch (TypeError $e) {
            // El mensaje de PHP va en inglés y habla de argumentos posicionales;
            // se conserva como causa, pero se dice en qué evento y desde dónde.
            $id = is_scalar($data['id'] ?? null) ? $data['id'] : '?';

            throw new InvalidArgumentException("CalendarEvent::fromArray(): evento «{$id}» con un valor de tipo no válido ({$e->getMessage()}).", 0, $e);
        }
    }

    private static function isBlank(mixed $value): bool
    {
        return is_string($value) && trim($value) === '';
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<string|int>
     */
    private static function cleanResourceIds(string|int $eventId, array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            if ((! is_string($id) && ! is_int($id)) || self::isBlank($id)) {
                throw new InvalidArgumentException(
                    "Evento «{$eventId}»: `resourceIds` solo admite ids string o int no vacíos, y llegó ".get_debug_type($id).'.'
                );
            }

            // Como clave: 1 y '1' son el mismo recurso, igual que en ResourceSet.
            $clean[$id] ??= $id;
        }

        return array_values($clean);
    }
}
