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

**Series.** Si el modelo guarda eventos que se repiten, di en qué columna va la regla:

```php
public function calendarRruleColumn(): ?string
{
    return 'rrule';   // por defecto null: el modelo no tiene series
}
```

Con ella, una fila con regla entra si `start < $to` **aunque su fin sea anterior al rango**: `starts_at` y `ends_at` son los de la primera ocurrencia, que puede ser de hace un año, y las del rango las genera la expansión (`EventCollector`). Sin la columna, esa serie no se trae y nunca llega a expandirse. Lo mismo vale para `new EloquentSource(..., rruleColumn: 'rrule')`. `toCalendarEvent()` pasa la columna como `rrule:` (y las excepciones como `recurrenceOf:` / `recurrenceId:`): ver [Series recurrentes](#series-recurrentes-editar-por-alcance).

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
| `clickEvent($id)` | Pulsar un evento: lo busca entre los de la vista y llama a `onEventClick()`. `$id` es el `instanceId` (`data-kore-instance`); el id del evento también vale, salvo en una serie |
| `moveEvent($id, …)`, `resizeEvent($id, …)` | Lo que llama el arrastre. Con una ocurrencia no guardan: devuelven `needsScope` y abren el diálogo de alcance |
| `applyRecurrenceScope('this'\|'following'\|'all')` | La respuesta del diálogo: repite la acción pendiente con ese alcance |
| `cancelRecurrenceScope()` | Descarta la acción pendiente |
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
- **`EventMutation`**: `start`, `end` (instantes), `startMarker`, `endMarker` (horas de pared), `allDay`, `resourceIds`, los valores `previous*`, `deltaSeconds`, `isResourceChange()`, `isAllDayChange()`. En una ocurrencia de una serie, además, `scope`, `seriesId`, `recurrenceId` y `occurrenceStart` ([Series recurrentes](#series-recurrentes-editar-por-alcance)).
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
| `mode`, `id` | `'create'` / `'edit'`; el id del evento (`null` al crear) |
| `title`, `allDay`, `color` | `color` es un token de kore-ui (`primary`, `success`, `warning`, `destructive`, `info`, `chart-1`…`chart-8`) o `null`. Un evento con un color suyo (`#ff8800`) lo conserva al guardarse |
| `start`, `end` | Hora de pared en la zona del calendario: `'Y-m-d'` o `'Y-m-d H:i:s'`. `end` es **exclusivo** y `null` si no hay fin |
| `startAt`, `endAt` | Lo mismo como instantes (`CarbonImmutable`), listos para guardar. Resuelven las horas del cambio de horario con las reglas de siempre |
| `resourceId`, `resourceIds` | El recurso elegido y la lista. Un evento con varios recursos conserva todos mientras no se cambie el primero |

- **`deleteEvent(CalendarEvent $event)`** recibe además un segundo argumento, `array $data` (`mode: 'delete'`, `id` y, en una ocurrencia, las claves de la serie). La base no lo declara para no romper a quien ya la sobrescribe con un solo parámetro: declara `deleteEvent(CalendarEvent $event, array $data = [])` para leerlo.
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

## Series recurrentes: editar por alcance

Los eventos con `rrule` se definen en [getting-started.md](getting-started.md#eventos-recurrentes). Aquí, qué pasa al **tocar una ocurrencia**: mover, redimensionar, editar o eliminar una de ellas no se puede guardar sin saber a qué alcanza.

| Alcance | Qué cambia |
|---|---|
| `this` | Solo esa ocurrencia |
| `following` | Esa y todas las siguientes |
| `all` | La serie entera |

```
arrastrar ─▶ moveEvent(instanceId, …) ──▶ valida ──▶ { ok: false, needsScope: true }  + abre el diálogo
                                                          (no se guarda nada; se guarda la acción pendiente)
elegir    ─▶ applyRecurrenceScope('this') ─▶ vuelve a resolver y a validar ─▶ persistMove($event, $mutation con scope)
```

- **El diálogo** (`kore-calendar-recurrence-scope`, un overlay de kore-ui con tres botones y «Cancelar») se abre solo; necesita el `<livewire:kore-overlay-manager />` en el layout, como el formulario. Los textos son `scope_*` en `translations`.
- **No se pregunta por lo que se rechazaría**: el diálogo sale después de validar (editable, rango, solapes), y soltar la ocurrencia donde estaba tampoco pregunta.
- **La acción pendiente** vive en `$koreRecurrencePending`, una propiedad `#[Locked]`: el navegador solo puede elegir el alcance de una acción que el servidor ya aceptó. Guarda los argumentos del gesto, no la mutación, y al elegir se repite **todo el camino de siempre**: la ocurrencia se vuelve a buscar (puede haber cambiado o desaparecido, y entonces se rechaza), el destino se vuelve a ajustar y se valida. Un alcance que no existe, o ninguna acción pendiente, no hacen nada; la pendiente se consume antes de intentarlo.
- **Identificar la ocurrencia.** Todas comparten `event->id`, así que el cliente manda el `instanceId` (`data-kore-instance`) en `moveEvent`, `resizeEvent` y `clickEvent`. El servidor busca primero por `instanceId` y, si no, por el id del evento (lo que mandaban los clientes anteriores); pero un id de serie no identifica una ocurrencia y se rechaza.
- **Con el formulario** (`eventForm()`): guardar o eliminar una ocurrencia abre el diálogo **encima** del formulario, que no se cierra hasta que se elige (cancelar vuelve a él). Eliminar una ocurrencia no pide la confirmación de siempre: elegir el alcance en el diálogo «Eliminar un evento recurrente» ya lo es.
- **Sin deshacer.** `undoable()` no ofrece deshacer el cambio de una ocurrencia: puede ser un EXDATE más una excepción, un corte de la regla o un desplazamiento, y la instantánea no sabe revertir ninguno. Tras elegir el alcance el resultado sale en un toast (el gesto ya terminó y no hay `aria-live` al que anunciarlo).

### Qué reciben los hooks

`persistMove()` / `persistResize()` reciben, como siempre, `$event` y `$mutation`. En una ocurrencia:

- **`$event`** es la ocurrencia, con SUS fechas y sin regla; su `id` es el de la serie (o el de su excepción, si ya lo era: `$event->isOverride()`).
- **`$mutation->scope`**: `this`, `following` o `all` (`null` en un evento normal). **`seriesId`**: la serie, para cargarla. **`recurrenceId`** (`Marker`) y **`occurrenceStart`** (instante): el inicio ORIGINAL de la ocurrencia, aunque se haya movido antes. `$mutation->isOccurrence()` los distingue de un evento normal.
- **Con `all`, la mutación es el desplazamiento de la serie, no la nueva posición de la ocurrencia.** `start` y `end` dicen dónde queda ESA ocurrencia; a la serie hay que sumarle `$mutation->deltaSeconds` a su `start` (`RecurrenceEditor::shift()`). Guardar `$mutation->start` como inicio de la serie la movería a esa fecha y perdería todas las anteriores. Al redimensionar, `RecurrenceEditor::resize()`.
- **`updateEvent($event, $data)`** (el formulario) gana en una ocurrencia las mismas claves en `$data`: `scope`, `seriesId`, `recurrenceId`, `occurrenceStart`, y `deltaSeconds` (cuánto se movió su inicio, para `all`). **`deleteEvent($event, $data)`**, las cuatro primeras.
- La validación es la de la ocurrencia que se tocó (editable, `validRange`, solapes): con `all` no se comprueban las demás.

### `RecurrenceEditor`

Las operaciones que el consumidor necesita para guardar, sin saber RFC 5545. Puro y estático: recibe la serie (`$row->toCalendarEvent()`) y la ocurrencia, que puede ser la `EventMutation`, el `$data` de `updateEvent()` / `deleteEvent()` o una `EventInstance`. **Todo lo que devuelve va en la representación de la serie** (`'Y-m-d'` si es de día completo, `'Y-m-d H:i:s'` si es flotante, un instante en su zona si la tiene), que es en la que se comparan los EXDATE y los `recurrenceId`.

| Método | Devuelve | Para |
|---|---|---|
| `exclude($series, $occ)` | Los `exdates` de la serie + esta ocurrencia | `this` al **eliminar** |
| `override($series, $occ, $change)` | La excepción lista para guardar: `recurrenceOf`, `recurrenceId`, `title`, `start`, `end`, `allDay`, `resourceIds`, `color` | `this` al **mover o editar**. `$change` es la mutación o el `$data` |
| `splitAt($series, $occ)` | `['before' => …, 'after' => …]`: la regla de la serie original cortada **un segundo antes** de la ocurrencia (`UNTIL` en UTC, en lugar de `COUNT`/`UNTIL`) y la de la serie nueva (con `COUNT`, las ocurrencias que quedaban desde esta, ella incluida; con `UNTIL` o sin límite, la misma) | `following` |
| `isFirst($series, $occ)` | `true` si no hay nada antes | `following` sobre la primera es `all` |
| `shift($series, $deltaSeconds)` | `['start' => …, 'end' => …]` desplazados | `all` al mover |
| `resize($series, $mutation)` | `['start' => …, 'end' => …]`: todas pasan a durar lo que la redimensionada | `all` al redimensionar |

- **La unión de `before` y `after` es exactamente la serie original** (hay un test con `COUNT`, con `UNTIL` y cruzando el cambio de hora). Un EXDATE no resta del `COUNT`, igual que en la expansión.
- **`shift` suma en pared**, en la zona de la propia serie: «cada lunes a las 9 de Madrid» movido un día es «cada martes a las 9 de Madrid», también tras el cambio de hora.
- **La serie nueva de `following`** empieza donde queda la ocurrencia (`$mutation->start`, `$data['startAt']`) y se guarda en la zona de la serie (Eloquent lo hace por ti con la zona de la aplicación).

### Ejemplo con Eloquent

El modelo guarda la serie, y cada excepción es otra fila con `recurrence_of` (la serie) y `recurrence_id` (el inicio original):

```php
class Meeting extends Model
{
    use HasCalendarEvents;

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'recurrence_id' => 'datetime', 'exdates' => 'array'];

    public function calendarRruleColumn(): ?string
    {
        return 'rrule';
    }

    public function toCalendarEvent(): CalendarEvent
    {
        return CalendarEvent::make(
            id: $this->id, title: $this->title, start: $this->starts_at, end: $this->ends_at,
            rrule: $this->rrule, exdates: $this->exdates ?? [],
            recurrenceOf: $this->recurrence_of, recurrenceId: $this->recurrence_id,
        );
    }
}
```

`persistMove()` con los tres alcances (`persistResize()` es igual con `RecurrenceEditor::resize()`):

```php
use KoreUi\Calendar\Recurrence\RecurrenceEditor;

protected function persistMove(CalendarEvent $event, EventMutation $mutation)
{
    // Un evento normal: como siempre.
    if (! $mutation->isOccurrence()) {
        Meeting::whereKey($event->id)->update(['starts_at' => $mutation->start, 'ends_at' => $mutation->end]);

        return;
    }

    // La serie, no la ocurrencia: `$event->id` es el suyo, pero la excepción de una ocurrencia modificada tiene otro.
    $row = Meeting::findOrFail($mutation->seriesId);
    $series = $row->toCalendarEvent();

    // Esta y las siguientes sobre la primera ocurrencia es toda la serie.
    $scope = $mutation->scope === 'following' && RecurrenceEditor::isFirst($series, $mutation) ? 'all' : $mutation->scope;

    match ($scope) {
        // Solo esta: una excepción. Si ya lo era, se actualiza su fila en vez de crear otra.
        'this' => $this->saveOverride(RecurrenceEditor::override($series, $mutation, $mutation)),

        // Esta y las siguientes: la serie se corta antes y nace otra desde aquí.
        'following' => $this->split($row, $series, $mutation),

        // Toda la serie: se desplaza lo que se movió esta ocurrencia.
        'all' => $row->update($this->columns(RecurrenceEditor::shift($series, $mutation->deltaSeconds))),
    };
}

private function saveOverride(array $o): void
{
    Meeting::updateOrCreate(
        ['recurrence_of' => $o['recurrenceOf'], 'recurrence_id' => $o['recurrenceId']],
        ['title' => $o['title'], 'starts_at' => $o['start'], 'ends_at' => $o['end'], 'all_day' => $o['allDay'], 'room_id' => $o['resourceIds'][0] ?? null],
    );
}

private function split(Meeting $row, CalendarEvent $series, EventMutation $m): void
{
    $cut = RecurrenceEditor::splitAt($series, $m);

    $row->update(['rrule' => $cut['before']]);

    $next = $row->replicate()->forceFill(['rrule' => $cut['after'], 'exdates' => null, 'starts_at' => $m->start, 'ends_at' => $m->end]);
    $next->save();

    // Las excepciones desde aquí pasan a la serie nueva: apuntaban a una ocurrencia que ahora es suya.
    Meeting::where('recurrence_of', $row->id)->where('recurrence_id', '>=', $m->occurrenceStart)->update(['recurrence_of' => $next->id]);
}

private function columns(array $times): array
{
    return ['starts_at' => $times['start'], 'ends_at' => $times['end']];
}
```

Para eliminar, `deleteEvent(CalendarEvent $event, array $data = [])` sigue el mismo reparto: `this` → `$row->update(['exdates' => RecurrenceEditor::exclude($series, $data)])` (y borrar la fila de la excepción, si la ocurrencia ya lo era); `following` → `$row->update(['rrule' => RecurrenceEditor::splitAt($series, $data)['before']])`; `all` → `$row->delete()`. Para editar desde el formulario, `updateEvent($event, $data)`: `override($series, $data, $data)`, `splitAt($series, $data)` y `shift($series, $data['deltaSeconds'])`.

### Lo que el paquete no hace por ti

- **Las excepciones de una serie desplazada.** Una excepción guarda el inicio ORIGINAL de la ocurrencia que sustituye. Con `all` hay que desplazarla con la serie (el mismo `deltaSeconds`) o borrarla, o quedará apuntando a una ocurrencia que ya no existe.
- **`following` y los días de la semana.** Al mover «esta y las siguientes» a otro día de la semana, una regla con `BYDAY` (`FREQ=WEEKLY;BYDAY=MO,WE`) sigue repitiéndose esos días; el paquete no reescribe la regla. Cambiar la hora, el título o el recurso funciona siempre.
- **Cambiar de día completo a con hora** una ocurrencia con `all`: `deltaSeconds` solo desplaza; la serie conserva su tipo. Mira `$mutation->isAllDayChange()` y rechaza (devuelve un texto) o resuélvelo tú.
- **Una excepción movida fuera del rango cargado.** `EloquentSource` trae las excepciones por su NUEVA fecha: si una se mueve a un mes que no se está viendo, la ocurrencia original reaparece en el mes de origen hasta que se carga la excepción. Si lo necesitas, devuelve también desde `events()` las excepciones cuyo `recurrence_id` caiga en el rango.

