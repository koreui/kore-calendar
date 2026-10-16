<?php

namespace KoreUi\Calendar\Resources;

use Countable;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * El conjunto de recursos de un calendario, validado y ordenado una sola vez.
 *
 * Inmutable: se construye con todos los recursos y se consulta. Si la jerarquía
 * no es un árbol (ids repetidos, un padre que no existe, un ciclo) lanza al
 * construirse; el layout no tiene que defenderse de ello luego, y un ciclo no
 * puede colgar un bucle `while` en medio de un render.
 *
 * Los ids se comparan como claves de array: `1` y `'1'` son el mismo recurso.
 *
 * Orden de los hermanos (y de `all()`): `order` ascendente con los `null` al
 * final, luego el título (comparación natural sin mayúsculas: «Sala 2» antes que
 * «Sala 10»), luego el id. Total y determinista, para que el orden no dependa de
 * cómo llegaron los datos de la base de datos.
 */
final class ResourceSet implements Countable
{
    /** @var array<string|int, CalendarResource> En el orden de `all()`. */
    private readonly array $byId;

    /** @var list<CalendarResource> */
    private readonly array $roots;

    /** @var array<string|int, list<CalendarResource>> */
    private readonly array $children;

    /**
     * @param  iterable<CalendarResource|array<string, mixed>>  $resources
     */
    public function __construct(iterable $resources)
    {
        $items = [];

        foreach ($resources as $position => $resource) {
            $resource = self::toResource($position, $resource);

            if (isset($items[$resource->id])) {
                throw new InvalidArgumentException("ResourceSet: el id de recurso «{$resource->id}» está repetido.");
            }

            $items[$resource->id] = $resource;
        }

        foreach ($items as $resource) {
            if ($resource->parentId !== null && ! isset($items[$resource->parentId])) {
                throw new InvalidArgumentException(
                    "ResourceSet: el recurso «{$resource->id}» apunta a un padre que no existe («{$resource->parentId}»)."
                );
            }
        }

        self::assertNoCycles($items);

        uasort($items, self::compare(...));

        $roots = [];
        $children = [];

        // Ya ordenado: los hermanos heredan el orden global sin ordenar otra vez.
        foreach ($items as $resource) {
            if ($resource->parentId === null) {
                $roots[] = $resource;
            } else {
                $children[$resource->parentId][] = $resource;
            }
        }

        $this->byId = $items;
        $this->roots = $roots;
        $this->children = $children;
    }

    /** @return list<CalendarResource> Todos, en orden global (no en orden de árbol: eso es `flatten()`). */
    public function all(): array
    {
        return array_values($this->byId);
    }

    public function find(string|int $id): ?CalendarResource
    {
        return $this->byId[$id] ?? null;
    }

    /** @return list<CalendarResource> */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * Los hijos directos. Un id que no existe da una lista vacía, igual que una hoja:
     * para distinguirlos está `find()`.
     *
     * @return list<CalendarResource>
     */
    public function children(string|int $id): array
    {
        return $this->children[$id] ?? [];
    }

    /**
     * El árbol en profundidad: cada padre antes que sus hijos, y los hermanos
     * ordenados. Es el orden de las filas de un timeline anidado.
     *
     * @return list<array{resource: CalendarResource, depth: int}>
     */
    public function flatten(): array
    {
        $flat = [];
        $this->walk($this->roots, 0, $flat);

        return $flat;
    }

    /**
     * Agrupados por su campo `group`, en el orden de `all()`; los grupos salen en
     * el orden en que aparece su primer recurso. Los que no tienen grupo, bajo la
     * clave `''`.
     *
     * PHP convierte una clave como `'10'` en el entero 10: un grupo con nombre
     * numérico se compara con `(string) $clave`.
     *
     * @return array<string, list<CalendarResource>>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->byId as $resource) {
            $groups[$resource->group ?? ''][] = $resource;
        }

        return $groups;
    }

    public function isEmpty(): bool
    {
        return $this->byId === [];
    }

    public function count(): int
    {
        return count($this->byId);
    }

    /**
     * @param  list<CalendarResource>  $nodes
     * @param  list<array{resource: CalendarResource, depth: int}>  $flat
     */
    private function walk(array $nodes, int $depth, array &$flat): void
    {
        foreach ($nodes as $node) {
            $flat[] = ['resource' => $node, 'depth' => $depth];
            $this->walk($this->children($node->id), $depth + 1, $flat);
        }
    }

    private static function toResource(int|string $position, mixed $resource): CalendarResource
    {
        if ($resource instanceof CalendarResource) {
            return $resource;
        }

        if (! is_array($resource)) {
            throw new UnexpectedValueException(sprintf(
                'ResourceSet espera CalendarResource o array y en la posición %s recibió %s.',
                $position,
                get_debug_type($resource),
            ));
        }

        try {
            return CalendarResource::fromArray($resource);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("ResourceSet: recurso en la posición {$position} no válido. ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Sube por los padres desde cada recurso. Si vuelve a un id que ya está en el
     * camino de esta subida, hay un ciclo. Lo ya recorrido sin problema no se
     * repite, así que el coste total es lineal.
     *
     * @param  array<string|int, CalendarResource>  $items
     */
    private static function assertNoCycles(array $items): void
    {
        $verified = [];

        foreach ($items as $first) {
            $path = [];
            $current = $first;

            while (! isset($verified[$current->id])) {
                if (isset($path[$current->id])) {
                    $cycle = array_slice(array_keys($path), array_search($current->id, array_keys($path), false));
                    $cycle[] = $current->id;

                    throw new InvalidArgumentException(
                        'ResourceSet: ciclo en la jerarquía de recursos (cada uno apunta a su padre): '.implode(' → ', $cycle).'.'
                    );
                }

                $path[$current->id] = true;

                if ($current->parentId === null) {
                    break;
                }

                $current = $items[$current->parentId];
            }

            foreach ($path as $id => $_) {
                $verified[$id] = true;
            }
        }
    }

    /**
     * Títulos con las reglas del idioma: comparando bytes, «Sala Ámbar» va detrás
     * de «Sala Zeta». Con ext-intl, Collator con orden numérico («Sala 2» antes que
     * «Sala 10»); sin ella, comparación natural sin mayúsculas.
     */
    private static function compareTitles(string $a, string $b): int
    {
        static $collator = false;

        if ($collator === false) {
            $collator = null;

            if (class_exists(\Collator::class)) {
                $collator = new \Collator(function_exists('app') ? app()->getLocale() : 'en');
                $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
                // Sin distinguir mayúsculas (como strnatcasecmp), pero sí tildes.
                $collator->setStrength(\Collator::SECONDARY);
            }
        }

        return $collator ? $collator->compare($a, $b) : strnatcasecmp($a, $b);
    }

    private static function compare(CalendarResource $a, CalendarResource $b): int
    {
        $byOrder = match (true) {
            $a->order === null && $b->order === null => 0,
            $a->order === null => 1,
            $b->order === null => -1,
            // Dos enteros se comparan como enteros; con un string de por medio, de
            // forma natural, para que '2' vaya antes que '10'.
            is_int($a->order) && is_int($b->order) => $a->order <=> $b->order,
            default => strnatcasecmp((string) $a->order, (string) $b->order),
        };

        if ($byOrder !== 0) {
            return $byOrder;
        }

        $byTitle = self::compareTitles($a->title, $b->title);

        if ($byTitle !== 0) {
            return $byTitle;
        }

        return is_int($a->id) && is_int($b->id)
            ? $a->id <=> $b->id
            : strnatcmp((string) $a->id, (string) $b->id);
    }
}
