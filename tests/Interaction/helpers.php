<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Interaction\MutationBuilder;
use KoreUi\Calendar\Interaction\MutationValidator;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;
use KoreUi\Calendar\View\CalendarSettings;

/*
| Ayudas de los tests de interacción. Prefijo `ix` para no chocar con las
| globales de otros archivos (`ly` en Layout). Todo va en Europe/Madrid salvo
| que se diga: es la zona de los casos de horario de verano de la spec.
*/

function ixClock(string $zone = 'Europe/Madrid'): WallClock
{
    return new WallClock($zone);
}

function ixMarker(string $wall): Marker
{
    return Marker::parse($wall);
}

/**
 * Una instancia normalizada. `$start`/`$end` con offset ('2026-10-25T00:30:00+02:00') llevan
 * instantes exactos; sin offset son hora de pared flotante.
 *
 * @param  array<string, mixed>  $extra  Resto de argumentos de CalendarEvent::make (resourceIds, overlap…).
 */
function ixInstance(string|int $id, string $start, ?string $end = null, ?bool $allDay = null, array $extra = [], int $defaultTimed = 3600, int $defaultDays = 1): EventInstance
{
    return (new EventNormalizer(ixClock(), $defaultTimed, $defaultDays))->normalize(
        CalendarEvent::make(...[...['id' => $id, 'title' => "Evento {$id}", 'start' => $start, 'end' => $end, 'allDay' => $allDay], ...$extra])
    );
}

/** @param  array<string, mixed>  $o  Argumentos con nombre de MutationBuilder. */
function ixBuilder(array $o = []): MutationBuilder
{
    return new MutationBuilder(...[...['clock' => ixClock(), 'snapSeconds' => 1800], ...$o]);
}

/** @param  array<string, mixed>  $overrides  Claves del config con puntos. */
function ixSettings(array $overrides = []): CalendarSettings
{
    return CalendarSettings::make([...[
        'timezone' => 'Europe/Madrid',
        'first_day' => 1,
        'view' => 'week',
        'date' => '2026-10-14',
        'interaction.editable' => true,
    ], ...$overrides]);
}

/** El instante como ISO 8601 con offset: '2026-10-25T02:30:00+02:00'. */
function ixIso(?\DateTimeInterface $date): ?string
{
    return $date?->format('c');
}

function ixBuilderFor(CalendarSettings $settings): MutationBuilder
{
    return MutationBuilder::fromSettings($settings);
}

/**
 * Construye y valida el movimiento del evento `$id` a `$destino` entre las instancias de `$vista`.
 *
 * @param  list<EventInstance>  $vista
 * @param  array<string, mixed>  $settings  Claves del config con puntos.
 * @param  list<string|int>|null  $resources  Recursos resultantes (null = sin cambio).
 */
function ixValidateMove(array $vista, string|int $id, string $destino, array $settings = [], bool $allDay = false, ?array $resources = null): ?string
{
    $s = ixSettings($settings);
    $original = collect($vista)->first(fn (EventInstance $i) => $i->event->id === $id);

    return (new MutationValidator($s))->validateMove(ixBuilderFor($s)->move($original, ixMarker($destino), $allDay, $resources), $vista);
}
