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

## Edición: mover, redimensionar y seleccionar

```php
public function configure(): void
{
    $this->editable()->selectable()->snap('15m');
}

protected function persistMove(CalendarEvent $event, EventMutation $mutation)
{
    Meeting::whereKey($event->id)->update([
        'starts_at' => $mutation->start,          // instante en la zona de la vista
        'ends_at' => $mutation->end,
        'room_id' => $mutation->resourceIds[0] ?? null,
    ]);
}

protected function persistResize(CalendarEvent $event, EventMutation $mutation)
{
    Meeting::whereKey($event->id)->update(['ends_at' => $mutation->end]);
}

protected function onDateSelect(Selection $selection): void
{
    $this->dispatch('kore:open', name: 'meeting-form', arguments: [
        'start' => $selection->start->toIso8601String(),
        'end' => $selection->end->toIso8601String(),
    ]);
}
```

- **Lo que manda el navegador no es fiable.** El evento se busca entre los que devuelve tu `events()` para la vista actual (respeta tus scopes y evita mover un evento ajeno), el destino se vuelve a ajustar al `snap` y se valida: editable, `validRange`, solapes (`eventOverlap(false)`, o `overlap: false` en un evento; los del mismo `groupId` no chocan entre sí) y que el recurso destino exista.
- **El hook decide al final.** `persistMove` / `persistResize` devuelven `null` o `true` para aceptar, `false` o un texto para rechazar (el texto es el motivo que verá el usuario). Corren dentro de una transacción si hay base de datos. Una `ValidationException` muestra su mensaje; cualquier otra excepción se reporta y se rechaza con un mensaje genérico.
- **Al aceptar** se disparan los eventos de Laravel `EventMoved` / `EventResized` (con `calendarClass`, `event` y `mutation`) y el cambio se anuncia al lector de pantalla. **Al rechazar**, toast de error con el motivo y el evento no se mueve.
- **`EventMutation`**: `start`, `end` (instantes), `startMarker`, `endMarker` (horas de pared), `allDay`, `resourceIds`, los valores `previous*`, `deltaSeconds`, `isResourceChange()`, `isAllDayChange()`.
- **Día completo ↔ con hora:** arrastrar de una columna horaria al carril de todo el día (o al revés) cambia el tipo; sin `allDayMaintainDuration()` la duración pasa a la por defecto del nuevo tipo, como en FullCalendar.
- **Por evento:** `editable`, `startEditable`, `durationEditable` y `overlap` en el `CalendarEvent` ganan a la configuración.

**Teclado:** con un evento enfocado, Espacio lo agarra; ←/→ lo mueven un día, ↑/↓ un slot (una semana en el mes), Mayúsculas + flechas cambian la duración, Intro suelta y Escape cancela. Cada paso se anuncia. **Táctil:** pulsación larga para empezar a arrastrar; un deslizamiento normal sigue siendo scroll.

| Método de `configure()` | |
|---|---|
| `editable(bool = true)` | Mover y redimensionar |
| `selectable(bool = true)` | Seleccionar rangos vacíos (`onDateSelect`) |
| `snap('15m')` | Rejilla del gesto (por defecto, la del slot) |
| `eventOverlap(bool)` | Si se permiten solapes |
| `validRange(?string $start, ?string $end)` | Fechas fuera de las que no se puede soltar |
| `allDayMaintainDuration(bool = true)` | Conservar la duración al cambiar de tipo |
| `resourceEditable(bool)` | Si se puede cambiar un evento de recurso |
