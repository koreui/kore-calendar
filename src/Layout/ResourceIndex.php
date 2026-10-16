<?php

namespace KoreUi\Calendar\Layout;

use KoreUi\Calendar\Events\EventInstance;

/**
 * Las instancias de cada recurso, en una sola pasada.
 *
 * Las vistas por recurso (timeline, rejilla horaria por recurso) necesitan "los eventos
 * de este recurso" para cada fila o columna. Filtrar la lista entera por cada una son
 * filas × eventos comprobaciones; con este índice es una búsqueda.
 *
 * Un evento con varios recursos aparece en la lista de cada uno (sus ids no se repiten:
 * `CalendarEvent` los depura). Dentro de cada lista las instancias conservan el orden de
 * la lista original, así que el resultado es el mismo que el de filtrar.
 *
 * @internal
 */
final class ResourceIndex
{
    private function __construct() {}

    /**
     * @param  list<EventInstance>  $instances
     * @return array<string, list<EventInstance>> Por id de recurso (como texto: PHP convierte las claves numéricas a int, y se busca igual).
     */
    public static function of(array $instances): array
    {
        $index = [];

        foreach ($instances as $instance) {
            foreach ($instance->event->resourceIds as $id) {
                $index[(string) $id][] = $instance;
            }
        }

        return $index;
    }
}
