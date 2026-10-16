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
| `clickEvent($id)` | Pulsar un evento: lo busca entre los de la vista y llama a `onEventClick()` |
| `undoLastChange()` | Deshace el último movimiento o cambio de duración (con `undoable()`) |

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
| `eventForm(bool = true)` | El formulario de kore-ui para crear y editar eventos |
| `undoable(bool = true)` | Un toast con «Deshacer» tras mover o redimensionar |
| `eventClick(bool = true)` | Los eventos se pueden pulsar (`onEventClick()`). Lo activa solo `eventForm()` o sobrescribir el hook; esto es para apagarlo (`false`) |

## Crear y editar eventos

```php
public function configure(): void
{
    $this->selectable()->eventForm();
}

protected function createEvent(array $data)
{
    Meeting::create([
        'title' => $data['title'],
        'starts_at' => $data['startAt'],     // instantes en la zona del calendario
        'ends_at' => $data['endAt'],         // null si el evento no tiene fin
        'all_day' => $data['allDay'],
        'room_id' => $data['resourceId'],
        'color' => $data['color'],
    ]);
}

protected function updateEvent(CalendarEvent $event, array $data)
{
    Meeting::findOrFail($event->id)->update([/* igual que arriba */]);
}

protected function deleteEvent(CalendarEvent $event)
{
    Meeting::whereKey($event->id)->delete();
}
```

El layout necesita `<livewire:kore-overlay-manager />` (el formulario es un overlay) y `<livewire:kore-feedback-manager />` (los toasts).

- **Seleccionar un rango** (hace falta `selectable()`) abre el formulario de un evento nuevo con ese rango y el recurso donde se soltó. **Pulsar un evento** (clic, o Intro con el teclado) abre el de edición. Los eventos con `url` siguen siendo enlaces, y uno con `editable: false` no abre el formulario.
- **El formulario no guarda nada.** Valida lo que se escribe (título obligatorio, fechas reales, fin no anterior al inicio) y devuelve los datos al calendario que lo abrió; el calendario los vuelve a comprobar y llama al hook. Eliminar pide antes confirmación con el diálogo de kore-ui.
- **Los hooks** tienen el contrato de `persistMove`: `null` o `true` aceptan, `false` o un texto rechazan con ese motivo, corren dentro de una transacción si hay base de datos y una excepción se reporta. Por defecto rechazan con un mensaje que dice qué método falta. Éxito: toast de éxito. Rechazo: toast de error con el motivo.
- **`$data`** (ya limpio):

| Clave | |
|---|---|
| `mode`, `id` | `'create'` / `'edit'`; el id del evento (`null` al crear). Eliminar solo recibe el `CalendarEvent` |
| `title`, `allDay`, `color` | `color` es un token de kore-ui (`primary`, `success`, `warning`, `destructive`, `info`, `chart-1`…`chart-8`) o `null`. Un evento con un color suyo (`#ff8800`) lo conserva al guardarse |
| `start`, `end` | Hora de pared en la zona del calendario: `'Y-m-d'` o `'Y-m-d H:i:s'`. `end` es **exclusivo** y `null` si no hay fin |
| `startAt`, `endAt` | Lo mismo como instantes (`CarbonImmutable`), listos para guardar. Resuelven las horas del cambio de horario con las reglas de siempre |
| `resourceId`, `resourceIds` | El recurso elegido y la lista. Un evento con varios recursos conserva todos mientras no se cambie el primero |

- **En el formulario el fin de un día completo es inclusivo** («del 14 al 16» incluye el 16, como se dice en voz alta); en `$data` ya es exclusivo, como en todo el paquete.
- **No te fíes del navegador, y el calendario tampoco.** El aviso del formulario es un evento de Livewire que cualquiera puede lanzar con lo que quiera dentro, así que el calendario ignora el que lleva el id de otro calendario o llega sin `eventForm()`, busca el evento entre los de `events()` (un id ajeno o de otra semana se rechaza), y revisa cada campo: tipos, fechas que existen, recurso de `resources()`, color de la lista y `validRange`.
- **Qué NO comprueba:** los solapes (`eventOverlap(false)`), los permisos ni las reglas de negocio. Van en el hook, que puede rechazar con un texto o lanzar una `AuthorizationException` (`$this->authorize('update', $meeting)`), igual que en `persistMove()`.
- **Personalizar:** `onDateSelect()` y `onEventClick()` se pueden sobrescribir (con `parent::` para conservar lo del formulario). Sobrescribir `onEventClick()` basta para que los eventos sean pulsables sin formulario: se pintan enfocables (`role="button"`) y el hook recibe el `CalendarEvent` ya resuelto.
- **Textos:** todos están en `kore-calendar.translations` (`form_*`, `color_*`, `event_created`…).

## Deshacer

```php
$this->editable()->undoable();
```

Tras mover o redimensionar con éxito sale un toast breve «Deshacer». Pulsarlo repite el cambio al revés con el **mismo hook** (`persistMove` o `persistResize`), así que no hay nada nuevo que implementar. Está apagado por defecto para no llenar la pantalla de toasts.

- **Solo el último cambio, y una sola vez.** El estado anterior vive en una propiedad `#[Locked]` del calendario (`koreLastChange`); el navegador no puede escribirlo.
- **Pasa por la misma validación:** el evento se vuelve a buscar entre los de la vista, se comprueba que siga siendo editable y que el sitio no lo haya ocupado otro evento. Si algo falla sale el motivo y no se puede reintentar.
- **Se restaura lo exacto:** el día completo vuelve a sus días, el evento sin fin a no tener fin, y la hora repetida del cambio de horario a su misma ocurrencia.
- Dispara `EventMoved` / `EventResized` como cualquier otro cambio.
