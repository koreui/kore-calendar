<?php

namespace KoreUi\Calendar\Events\Concerns;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\Sources\EloquentSource;

/**
 * Para un modelo Eloquent que ES un evento del calendario.
 *
 * Aporta el scope `overlapping()` (filas que pueden intersecar un rango) y
 * `calendarSource()`, la fuente lista para pasar al calendario. El modelo solo
 * tiene que decir cómo se convierte: `toCalendarEvent()`.
 *
 * Las columnas por defecto son `starts_at` y `ends_at`; se cambian
 * sobrescribiendo `calendarStartColumn()` / `calendarEndColumn()` (esta última
 * puede devolver null si el modelo no tiene fin).
 *
 * Un modelo con series recurrentes dice en qué columna guarda la regla con
 * `calendarRruleColumn()` (por defecto `null`: no tiene). Sin ella, una serie
 * que empezó hace un año y cuya primera ocurrencia ya acabó no se trae, y no
 * llega nunca a expandirse.
 */
trait HasCalendarEvents
{
    abstract public function toCalendarEvent(): CalendarEvent;

    public function calendarStartColumn(): string
    {
        return 'starts_at';
    }

    public function calendarEndColumn(): ?string
    {
        return 'ends_at';
    }

    /** La columna con la regla `rrule` (RFC 5545, sin `DTSTART`), o `null` si el modelo no tiene series. */
    public function calendarRruleColumn(): ?string
    {
        return null;
    }

    /**
     * `Cita::overlapping($desde, $hasta)`. Mismo filtro que usa `calendarSource()`;
     * ver `EloquentSource::constrain()` para las reglas de los bordes.
     */
    public function scopeOverlapping(Builder $query, DateTimeInterface $from, DateTimeInterface $to): void
    {
        EloquentSource::constrain($query, $this->calendarStartColumn(), $this->calendarEndColumn(), $from, $to, $this->calendarRruleColumn());
    }

    /**
     * @param  ?Closure(Builder): mixed  $constrain  Añade condiciones a la consulta (permisos, un calendario
     *                                               concreto). Puede modificarla en sitio o devolver otra.
     */
    public static function calendarSource(?Closure $constrain = null): EloquentSource
    {
        $model = new static;

        return new EloquentSource(
            // Una consulta nueva en cada render: `$constrain` puede depender del usuario.
            query: function () use ($constrain): Builder {
                $query = static::query();
                $result = $constrain === null ? null : $constrain($query);

                return $result instanceof Builder ? $result : $query;
            },
            map: fn (Model $row) => $row->toCalendarEvent(),
            startColumn: $model->calendarStartColumn(),
            endColumn: $model->calendarEndColumn(),
            rruleColumn: $model->calendarRruleColumn(),
        );
    }
}
