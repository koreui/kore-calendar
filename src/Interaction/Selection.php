<?php

namespace KoreUi\Calendar\Interaction;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Time\Marker;

/**
 * Un rango de fechas que el usuario seleccionó arrastrando sobre la rejilla,
 * ya ajustado al snap y validado por el servidor.
 *
 * `start`/`end` son instantes en la zona de la vista (el fin es exclusivo); los
 * markers son las mismas horas de pared que dibujó la rejilla. Con `allDay`,
 * `start` es las 00:00 del primer día y `end` las 00:00 del día siguiente al
 * último seleccionado. `resourceId` es la columna o fila donde se soltó, o
 * `null` en las vistas sin recursos.
 */
final readonly class Selection
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public Marker $startMarker,
        public Marker $endMarker,
        public bool $allDay,
        public string|int|null $resourceId = null,
    ) {}
}
