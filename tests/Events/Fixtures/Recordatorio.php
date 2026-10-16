<?php

namespace KoreUi\Calendar\Tests\Events\Fixtures;

use Illuminate\Database\Eloquent\Model;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\Concerns\HasCalendarEvents;

/** Modelo sin columna de fin: `calendarEndColumn()` devuelve null. */
class Recordatorio extends Model
{
    use HasCalendarEvents;

    protected $table = 'recordatorios';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime'];
    }

    public function calendarEndColumn(): ?string
    {
        return null;
    }

    public function toCalendarEvent(): CalendarEvent
    {
        return CalendarEvent::make(id: $this->id, title: $this->texto, start: $this->starts_at);
    }
}
