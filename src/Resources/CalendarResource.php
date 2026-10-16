<?php

namespace KoreUi\Calendar\Resources;

use InvalidArgumentException;
use TypeError;

/**
 * Algo a lo que se asigna un evento: una sala, una persona, una máquina. En la
 * rejilla es una columna (vistas `resource-*`) o una fila (timeline).
 *
 * `parentId` anida (edificio → planta → sala) y `group` agrupa filas o columnas
 * por un campo libre; son independientes. `color` es el de sus eventos cuando el
 * evento no trae el suyo. `order` fija el orden entre hermanos (los `null` van al
 * final, ordenados por título).
 *
 * Estricto como `CalendarEvent`: `fromArray()` rechaza claves desconocidas, que
 * en FullCalendar acaban en `extendedProps` sin avisar. Los datos libres van en
 * `meta`.
 */
final readonly class CalendarResource
{
    public const KEYS = ['id', 'title', 'parentId', 'group', 'color', 'order', 'businessHours', 'meta'];

    private const REQUIRED = ['id', 'title'];

    /**
     * @param  ?array<string, mixed>  $businessHours  Horario propio del recurso; `null` = el del calendario.
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string|int $id,
        public string $title,
        public string|int|null $parentId = null,
        public ?string $group = null,
        public ?string $color = null,
        public int|string|null $order = null,
        public ?array $businessHours = null,
        public array $meta = [],
    ) {
        if (self::isBlank($id)) {
            throw new InvalidArgumentException('CalendarResource: el id no puede estar vacío.');
        }

        if (self::isBlank($parentId)) {
            throw new InvalidArgumentException("Recurso «{$id}»: `parentId` está vacío. Usa null si es un recurso raíz.");
        }

        // Se dice aquí, con un mensaje propio, el ciclo más corto de todos; los
        // demás los detecta ResourceSet, que ve el conjunto.
        if ($parentId !== null && (string) $parentId === (string) $id) {
            throw new InvalidArgumentException("Recurso «{$id}»: no puede ser su propio padre.");
        }

        // Con '' el recurso caería en el mismo cajón que los que no tienen grupo.
        if ($group !== null && trim($group) === '') {
            throw new InvalidArgumentException("Recurso «{$id}»: `group` está vacío. Usa null si no pertenece a ningún grupo.");
        }
    }

    /**
     * @param  ?array<string, mixed>  $businessHours
     * @param  array<string, mixed>  $meta
     */
    public static function make(
        string|int $id,
        string $title,
        string|int|null $parentId = null,
        ?string $group = null,
        ?string $color = null,
        int|string|null $order = null,
        ?array $businessHours = null,
        array $meta = [],
    ): self {
        return new self(
            id: $id, title: $title, parentId: $parentId, group: $group, color: $color,
            order: $order, businessHours: $businessHours, meta: $meta,
        );
    }

    /**
     * Desde un array (una fila, un JSON). Estricto: una clave que no sea un
     * argumento del constructor lanza una excepción.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($data)), self::KEYS));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'CalendarResource::fromArray(): clave%s desconocida%s %s. Permitidas: %s. Los datos libres van en `meta`.',
                count($unknown) > 1 ? 's' : '',
                count($unknown) > 1 ? 's' : '',
                implode(', ', array_map(fn (string $k) => "«{$k}»", $unknown)),
                implode(', ', self::KEYS),
            ));
        }

        $missing = array_values(array_filter(self::REQUIRED, fn (string $k) => ! array_key_exists($k, $data)));

        if ($missing !== []) {
            throw new InvalidArgumentException('CalendarResource::fromArray(): faltan claves obligatorias: '.implode(', ', $missing).'.');
        }

        try {
            return new self(...$data);
        } catch (TypeError $e) {
            $id = is_scalar($data['id'] ?? null) ? $data['id'] : '?';

            throw new InvalidArgumentException("CalendarResource::fromArray(): recurso «{$id}» con un valor de tipo no válido ({$e->getMessage()}).", 0, $e);
        }
    }

    private static function isBlank(mixed $value): bool
    {
        return is_string($value) && trim($value) === '';
    }
}
