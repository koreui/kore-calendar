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

    /** Los esquemas que puede tener la `url` de un evento (acaba en un `href`). Las relativas también valen. */
    public const URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Claves que acepta `fromArray()`, además del atajo `resourceId`. Un test las compara con el constructor. */
    public const KEYS = [
        'id', 'title', 'start', 'end', 'allDay', 'resourceIds', 'groupId', 'color', 'url',
        'display', 'editable', 'startEditable', 'durationEditable', 'overlap', 'meta',
        'rrule', 'exdates', 'recurrenceOf', 'recurrenceId',
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
        public ?string $rrule = null,
        public array $exdates = [],
        public string|int|null $recurrenceOf = null,
        public DateTimeInterface|string|null $recurrenceId = null,
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

        if ($url !== null) {
            self::checkUrl($id, $url);
        }

        $this->resourceIds = self::cleanResourceIds($id, $resourceIds);

        self::checkRecurrence($id, $rrule, $exdates, $recurrenceOf, $recurrenceId);
    }

    /**
     * Una ocurrencia de esta serie: el mismo evento con otras fechas y sin regla.
     * La genera RecurrenceExpander; conserva id, título, recursos, color…
     */
    public function occurrence(DateTimeInterface|string $start, DateTimeInterface|string|null $end): self
    {
        return new self(
            id: $this->id, title: $this->title, start: $start, end: $end, allDay: $this->allDay,
            resourceIds: $this->resourceIds, groupId: $this->groupId, color: $this->color, url: $this->url,
            display: $this->display, editable: $this->editable, startEditable: $this->startEditable,
            durationEditable: $this->durationEditable, overlap: $this->overlap, meta: $this->meta,
        );
    }

    /** Una serie (tiene `rrule`) o una ocurrencia modificada (tiene `recurrenceOf`). */
    public function isRecurring(): bool
    {
        return $this->rrule !== null;
    }

    public function isOverride(): bool
    {
        return $this->recurrenceOf !== null;
    }

    /**
     * La `url` va a un `<a href>`: un `javascript:` o un `data:` ejecutaría código en el
     * origen de la aplicación al pulsarlo, y los datos de un evento pueden venir de un
     * `.ics` ajeno o de un campo que el usuario escribió. Solo se aceptan las URL
     * relativas (`/reunion/5`, `?id=5`, `#x`, `reunion/5`) y las de `URL_SCHEMES`.
     *
     * El esquema se busca como lo hace el navegador al resolver un `href`: sin mirar
     * mayúsculas ni los espacios y caracteres de control del principio, y sin los
     * tabuladores y saltos de línea de en medio (`java\tscript:` ES `javascript:`). Para no
     * depender de cuáles quita cada navegador, aquí se descartan todos los caracteres de
     * control y los espacios de antes del esquema.
     *
     * `//otro.example` (relativa al protocolo) se permite: lleva a otro sitio, pero no más
     * que `https://otro.example`, que es válida, y no puede ejecutar nada.
     */
    private static function checkUrl(string|int $id, string $url): void
    {
        $probe = ltrim(preg_replace('/[\x00-\x1F\x7F]+/', '', $url) ?? '');

        if (! preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $m)) {
            return;
        }

        if (! in_array(strtolower($m[1]), self::URL_SCHEMES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Evento «%s»: la `url` usa el esquema «%s:», que no se admite (acaba en un enlace). Solo URL relativas o con esquema %s.',
                $id,
                strtolower($m[1]),
                implode(', ', self::URL_SCHEMES),
            ));
        }
    }

    /**
     * La regla es RFC 5545 sin DTSTART (el inicio es `start`: tener los dos
     * permitiría que no coincidieran). Una ocurrencia modificada necesita las dos
     * claves que la identifican, y no puede ser a su vez una serie.
     */
    private static function checkRecurrence(string|int $id, ?string $rrule, array $exdates, string|int|null $of, mixed $recurrenceId): void
    {
        if ($rrule !== null) {
            if (trim($rrule) === '') {
                throw new InvalidArgumentException("Evento «{$id}»: `rrule` está vacío. Usa null si el evento no se repite.");
            }

            if (preg_match('/(^|[\s;])(DTSTART|RRULE:)/i', $rrule)) {
                throw new InvalidArgumentException("Evento «{$id}»: `rrule` lleva solo la regla (FREQ=…;BYDAY=…), sin DTSTART ni el prefijo RRULE:. El inicio de la serie es `start`.");
            }
        }

        if ($exdates !== [] && $rrule === null) {
            throw new InvalidArgumentException("Evento «{$id}»: `exdates` solo tiene sentido con `rrule`.");
        }

        if (($of === null) !== ($recurrenceId === null)) {
            throw new InvalidArgumentException("Evento «{$id}»: una ocurrencia modificada necesita `recurrenceOf` (la serie) y `recurrenceId` (qué ocurrencia sustituye), las dos.");
        }

        if ($of !== null && $rrule !== null) {
            throw new InvalidArgumentException("Evento «{$id}»: una ocurrencia modificada no puede ser a su vez una serie (`rrule`).");
        }
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
        ?string $rrule = null,
        array $exdates = [],
        string|int|null $recurrenceOf = null,
        DateTimeInterface|string|null $recurrenceId = null,
    ): self {
        return new self(
            id: $id, title: $title, start: $start, end: $end, allDay: $allDay, resourceIds: $resourceIds,
            groupId: $groupId, color: $color, url: $url, display: $display, editable: $editable,
            startEditable: $startEditable, durationEditable: $durationEditable, overlap: $overlap, meta: $meta,
            rrule: $rrule, exdates: $exdates, recurrenceOf: $recurrenceOf, recurrenceId: $recurrenceId,
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
