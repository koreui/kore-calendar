<?php

namespace KoreUi\Calendar\Tests\Events\Fixtures;

use Illuminate\Database\Eloquent\Model;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\Concerns\HasCalendarEvents;

/** Modelo que sobrescribe los nombres de columna: `inicio` y `fin`. Guarda fechas, no instantes. */
class Reserva extends Model
{
    use HasCalendarEvents;

    protected $table = 'reservas';

    public $timestamps = false;

    protected $guarded = [];

    public function calendarStartColumn(): string
    {
        return 'inicio';
    }

    public function calendarEndColumn(): ?string
    {
        return 'fin';
    }

    public function toCalendarEvent(): CalendarEvent
    {
        return CalendarEvent::make(id: $this->id, title: $this->nombre, start: $this->inicio, end: $this->fin, allDay: true);
    }
}
