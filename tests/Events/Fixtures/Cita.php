<?php

namespace KoreUi\Calendar\Tests\Events\Fixtures;

use Illuminate\Database\Eloquent\Model;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\Concerns\HasCalendarEvents;

/** Modelo con las columnas por defecto del trait: `starts_at` y `ends_at`. */
class Cita extends Model
{
    use HasCalendarEvents;

    protected $table = 'citas';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function toCalendarEvent(): CalendarEvent
    {
        return CalendarEvent::make(
            id: $this->id,
            title: $this->titulo,
            start: $this->starts_at,
            end: $this->ends_at,
            resourceIds: $this->resource_id === null ? [] : [$this->resource_id],
        );
    }
}
