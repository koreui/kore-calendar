<?php

namespace KoreUi\Calendar\Interaction;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\View\CalendarSettings;

/**
 * Lo que devuelve el formulario de evento, vuelto a comprobar en el servidor.
 *
 * El formulario es un overlay aparte y el aviso con sus datos viaja como un evento
 * de Livewire: cualquiera puede lanzar ese evento desde la consola del navegador
 * con lo que quiera dentro. Que el formulario ya validara no sirve de nada aquí
 * (docs/calendar.md §11), así que el calendario no se fía de ninguna clave: tipos,
 * fechas reales, recurso existente, color de la lista y rango válido.
 *
 * Devuelve los datos limpios, listos para los hooks `createEvent()` /
 * `updateEvent()`, o el motivo del rechazo (ya traducido, para el toast).
 */
final class EventFormPayload
{
    /**
     * Los colores que ofrece el formulario: token → clave de traducción de su nombre.
     * Cada clave va escrita entera, no montada con un prefijo: así el test de
     * claves sin usar las ve.
     */
    public const COLORS = [
        'primary' => 'color_primary',
        'success' => 'color_success',
        'warning' => 'color_warning',
        'destructive' => 'color_destructive',
        'info' => 'color_info',
        'chart-1' => 'color_chart_1',
        'chart-2' => 'color_chart_2',
        'chart-3' => 'color_chart_3',
        'chart-4' => 'color_chart_4',
        'chart-5' => 'color_chart_5',
        'chart-6' => 'color_chart_6',
        'chart-7' => 'color_chart_7',
        'chart-8' => 'color_chart_8',
    ];

    /** El título más largo que se acepta: una columna `varchar(255)` es lo habitual. */
    public const TITLE_MAX = 255;

    /**
     * @param  array<mixed>  $raw  Lo que llegó del navegador.
     * @param  ?CalendarEvent  $original  El evento que se edita, ya resuelto entre los de la vista (`null` al crear).
     * @return array{
     *     mode: string, id: string|int|null, title: string, allDay: bool,
     *     start: string, end: ?string, resourceId: string|int|null, resourceIds: list<string|int>,
     *     color: ?string, startAt: CarbonImmutable, endAt: ?CarbonImmutable
     * }|string
     */
    public static function check(array $raw, CalendarSettings $settings, ResourceSet $resources, ?CalendarEvent $original = null): array|string
    {
        $invalid = Messages::get('invalid_change');

        $title = $raw['title'] ?? null;
        $allDay = $raw['allDay'] ?? null;

        if (! is_string($title) || trim($title) === '' || mb_strlen(trim($title)) > self::TITLE_MAX || ! is_bool($allDay)) {
            return $invalid;
        }

        $start = self::marker($raw['start'] ?? null, $allDay);
        $end = ($raw['end'] ?? null) === null || $raw['end'] === '' ? null : self::marker($raw['end'], $allDay);

        // Un fin que no se entiende no se descarta en silencio: el usuario creería
        // que ha guardado una duración que no existe.
        if ($start === null || (($raw['end'] ?? null) !== null && $raw['end'] !== '' && $end === null)) {
            return $invalid;
        }

        // El fin de un día completo es exclusivo, así que el menor posible es el día siguiente.
        // En uno con hora puede coincidir con el inicio: el normalizador le pone la duración por defecto.
        if ($end !== null && $end->ts < $start->ts + ($allDay ? Marker::DAY : 0)) {
            return $invalid;
        }

        $resource = null;
        $resourceId = $raw['resourceId'] ?? null;

        if ($resourceId !== null && $resourceId !== '') {
            if (! is_string($resourceId) && ! is_int($resourceId)) {
                return $invalid;
            }

            // El cliente puede mandar cualquier id: solo vale uno de resources().
            $resource = $resources->find($resourceId);

            if ($resource === null) {
                return Messages::get('resource_not_found');
            }
        }

        $color = $raw['color'] ?? null;
        $color = $color === '' ? null : $color;

        // Un color suyo que no está en la lista (`#ff0000`) se conserva: el formulario
        // lo ofrece como opción para no borrárselo a quien solo cambia el título.
        if ($color !== null && (! is_string($color) || (! isset(self::COLORS[$color]) && $color !== $original?->color))) {
            return $invalid;
        }

        $clock = $settings->clock;
        $startAt = $clock->toInstant($start);
        $endAt = $end === null ? null : $clock->toInstant($end);

        // Mismo rango válido que al mover o seleccionar. Sin fin se mira lo que ocuparía por defecto.
        $until = $end ?? ($allDay ? $start->addDays($settings->defaultAllDayDays) : $start->addSeconds($settings->defaultTimedSeconds));
        $outOfRange = (new MutationValidator($settings))->validateSelection(
            new Selection($startAt, $endAt ?? $startAt, $start, $until, $allDay),
        );

        if ($outOfRange !== null) {
            return $outOfRange;
        }

        return [
            'mode' => $original === null ? 'create' : 'edit',
            'id' => $original?->id,
            'title' => trim($title),
            'allDay' => $allDay,
            'start' => $allDay ? $start->toDateString() : $start->toDateTimeString(),
            'end' => $end === null ? null : ($allDay ? $end->toDateString() : $end->toDateTimeString()),
            'resourceId' => $resource?->id,
            'resourceIds' => self::resourceIds($resource?->id, $original),
            'color' => $color,
            'startAt' => $startAt,
            'endAt' => $endAt,
        ];
    }

    /**
     * El formulario tiene un solo selector de recurso. Un evento con varios no
     * pierde los demás por abrirlo y guardarlo: si el elegido sigue siendo el
     * primero, se conservan todos.
     *
     * @return list<string|int>
     */
    private static function resourceIds(string|int|null $chosen, ?CalendarEvent $original): array
    {
        if ($chosen === null) {
            return [];
        }

        $current = $original?->resourceIds ?? [];

        return count($current) > 1 && (string) $current[0] === (string) $chosen ? $current : [$chosen];
    }

    /**
     * Una hora de pared del formulario, o `null` si no es una fecha real.
     *
     * Estricto con la forma: `Marker::parse()` perdona un 30 de febrero (lo
     * desborda a marzo), y un día completo no lleva hora. Se comprueba que el
     * marker vuelve a escribirse igual que llegó.
     */
    private static function marker(mixed $value, bool $allDay): ?Marker
    {
        $pattern = $allDay ? '/^(\d{4}-\d{2}-\d{2})$/' : '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/';

        if (! is_string($value) || ! preg_match($pattern, $value)) {
            return null;
        }

        $marker = Marker::parse($value);

        if (($allDay ? $marker->toDateString() : $marker->toDateTimeString()) !== $value || ! Bounds::contains($marker->ts)) {
            return null;
        }

        return $marker;
    }
}
