# KoreCalendar (clase base)

Componente Livewire base para un calendario con estado. Se extiende como `KoreDataTable`: implementas `events()` y, si quieres, `resources()` y `configure()`. La navegación (vista y fecha) vive en la URL.

## Uso

```php
use Carbon\CarbonImmutable;
use KoreUi\Calendar\KoreCalendar;

class TeamCalendar extends KoreCalendar
{
    public function configure(): void
    {
        $this->views(['month', 'week', 'list', 'resource-week'])
            ->timezone(auth()->user()->timezone)
            ->slotMinTime('08:00')
            ->slotMaxTime('20:00')
            ->maxEvents(3);
    }

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return Meeting::overlapping($from, $to)->get()->map->toCalendarEvent();
    }

    public function resources(): iterable
    {
        return Room::orderBy('name')->get()->map(fn ($r) => ['id' => $r->id, 'title' => $r->name]);
    }
}
```

```blade
<livewire:team-calendar />
```

`events()` recibe el rango **dibujado** (con los días de relleno del mes) en instantes de la zona de la vista. Puede devolver arrays con la forma de un evento, `CalendarEvent` o lo que produzca el trait `HasCalendarEvents`.

## Eventos desde Eloquent

```php
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\Concerns\HasCalendarEvents;

class Meeting extends Model
{
    use HasCalendarEvents;

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function toCalendarEvent(): CalendarEvent
    {
        return CalendarEvent::make(id: $this->id, title: $this->title, start: $this->starts_at, end: $this->ends_at, resourceIds: [$this->room_id]);
    }
}
```

`Meeting::overlapping($from, $to)` filtra en SQL las filas que pueden caer en el rango (también las que no tienen fin). Las columnas se cambian con `calendarStartColumn()` / `calendarEndColumn()`.

## Configuración (`configure()`)

| Método | Equivale a |
|--------|-----------|
| `views(array)` | Vistas del selector |
| `defaultView(string)` | Vista inicial |
| `timezone(string)`, `locale(string)`, `firstDay(int)`, `hiddenDays(array)` | |
| `weekNumbers('iso'\|'locale'\|false)` | |
| `slotDuration('15m')`, `slotMinTime('08:00')`, `slotMaxTime('20:00')` | Rejilla horaria |
| `maxEvents(?int)` | Límite por celda en el mes |
| `nowIndicator(bool)` | |
| `datesAboveResources(bool)` | Cabecera fecha → recursos en vez de recurso → fechas |
| `eventOrder(string, bool $strict)` | Orden de prioridad (`'start,-duration,allDay,title'`) |
| `set(string $key, mixed $value)` | Cualquier clave del config, con puntos |

`configure()` corre en cada petición (en `booted()`), así que sus valores sobreviven a las acciones sin viajar al navegador.

## Acciones

| Método | Qué hace |
|--------|----------|
| `previous()`, `next()` | Periodo anterior o siguiente |
| `today()` | Vuelve a hoy (limpia `?date=`) |
| `changeView(string)` | Cambia de vista; solo a las que ofrece el calendario |
| `goToDate('YYYY-MM-DD')` | Va a una fecha; solo fechas reales |

Atajos de teclado dentro del calendario: **RePág / AvPág** periodo anterior y siguiente, **T** hoy.
