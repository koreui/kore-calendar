# iCalendar (.ics)

Exportar los eventos a un archivo `.ics` e importar el de Google Calendar, Outlook o Apple Calendar. RFC 5545 en PHP puro, sin dependencias nuevas. Las clases viven en `KoreUi\Calendar\ICal`:

| Clase | Para qué |
|---|---|
| `ICalResponse::download()` | Servir un `.ics` desde un controlador |
| `ICalWriter` | `CalendarEvent` → texto iCalendar |
| `ICalReader` | Texto iCalendar → `CalendarEvent` |
| `ICalSource` | Un `.ics` como fuente de eventos del calendario |

## Exportar

```php
use KoreUi\Calendar\ICal\ICalResponse;

class TeamCalendarExportController
{
    public function __invoke()
    {
        $meetings = Meeting::query()->where('team_id', auth()->user()->team_id)->get();

        return ICalResponse::download(
            $meetings->map->toCalendarEvent(),
            'equipo.ics',
            ['name' => 'Equipo de producto', 'uidDomain' => 'miempresa.es'],
        );
    }
}
```

`download()` devuelve una `Response` con `Content-Type: text/calendar; charset=utf-8`, `X-Content-Type-Options: nosniff` y `Content-Disposition: attachment`. El nombre se limpia (sin rutas ni saltos de línea, siempre `.ics`, y con una versión ASCII para los navegadores antiguos si lleva tildes), así que puede venir de la petición.

Para obtener el texto sin respuesta HTTP (guardarlo, adjuntarlo a un correo):

```php
$ics = (new ICalWriter)->write($events, ['name' => 'Equipo']);
```

| Opción | Por defecto | |
|---|---|---|
| `name` | sin nombre | `X-WR-CALNAME`: el nombre con el que se suscribe el calendario |
| `uidDomain` | host de `app.url` | El UID de un evento es `{id}@{dominio}`, igual en cada exportación: así Google y Outlook reconocen el evento y lo actualizan en vez de duplicarlo. Dos aplicaciones que exporten un evento con id 1 al mismo calendario no se pisan si sus dominios son distintos |
| `dtstamp` | ahora, en UTC | Fecha fija para tests o exportaciones reproducibles (`DateTimeInterface` o string) |
| `defaultDuration` | ninguna | Segundos. Escribe un `DURATION` en los eventos **con hora y sin fin** (ver «Sin fin») |

Una opción que no existe lanza una excepción con la lista de las válidas. El escritor acepta `CalendarEvent` o arrays con la forma de `CalendarEvent::fromArray()`, y cualquier `iterable` (también un generador).

## Importar

```php
use KoreUi\Calendar\ICal\ICalException;
use KoreUi\Calendar\ICal\ICalReader;

public function import(Request $request)
{
    // `max:` (en KB) solo adelanta el rechazo al usuario. El tope que protege al lector es el suyo
    // (`maxBytes`, 5 MB), y es el único que vale para un `.ics` que no viene de un formulario.
    $request->validate(['ics' => ['required', 'file', 'max:2048']]);

    $reader = new ICalReader;

    try {
        $events = $reader->read(file_get_contents($request->file('ics')->getRealPath()), ['uidDomain' => 'miempresa.es']);
    } catch (ICalException $e) {
        return back()->withErrors(['ics' => $e->getMessage()]);   // «El contenido no es un archivo iCalendar…», «El archivo pesa 6.1 MB y el máximo…»
    }

    foreach ($events as $event) {
        Meeting::updateOrCreate(['uid' => $event->id], [/* … lo que guardes de $event … */]);
    }

    return back()->with([
        'skipped' => $reader->errors(),     // VEVENT que no se pudieron leer, con su línea
        'warnings' => $reader->warnings(),  // lo que se leyó con una salvedad
    ]);
}
```

`read()` devuelve una lista de `CalendarEvent`; no guarda nada: eso es de tu aplicación.

| Opción | Por defecto | |
|---|---|---|
| `strict` | `false` | Con `true`, el primer VEVENT roto lanza una `ICalException` (con `icsLine`) en vez de saltarse |
| `uidDomain` | `null` | Quita el `@dominio` del UID: es lo que `ICalWriter` añade, y así la vuelta recupera los ids originales |
| `untitled` | `(sin título)` | Título de un VEVENT sin `SUMMARY` |
| `idPrefix` | `''` | Se antepone al `id` y al `recurrenceOf` (`'ics:'`). **Recomendada al mezclar un `.ics` con otras fuentes**: el id de un evento es el `UID` del archivo, y un `UID:1` ajeno y el evento `1` de tu base de datos serían el mismo para el calendario (una ocurrencia modificada de uno sustituiría a la del otro). No la uses si luego vas a exportar lo leído con `ICalWriter` y esperas recuperar los UID originales |
| `maxBytes` | 5 MB | Un archivo más grande lanza `ICalException` sin analizarse (ver «Archivos de terceros») |
| `maxEvents` | 10 000 | A partir del VEVENT siguiente se deja de leer, y queda un error en `errors()` |
| `maxErrors` | 100 | Cuántos errores y avisos se guardan; los demás solo se cuentan |

Los tres últimos tienen su valor por defecto en el config (`ical.max_bytes`, `ical.max_events`, `ical.max_errors`).

### Errores y avisos

- **Un VEVENT roto se salta** y queda en `errors()` con el número de línea del archivo, que cuenta las líneas físicas (también las plegadas): `Línea 12: DTSTART «20261345T100000» no es una fecha que exista.` Los demás se leen. Un VEVENT es «roto» si no tiene DTSTART, tiene una fecha inválida, un TZID que no se puede resolver, una `RRULE` que php-rrule rechaza, una línea que no es `NOMBRE:valor` o no cierra con `END:VEVENT`.
- **Lo que no es un archivo iCalendar** (sin `BEGIN:VCALENDAR`) lanza `ICalException` siempre, también sin `strict`.
- **Los avisos nunca lanzan**: una zona resuelta por su VTIMEZONE, un `RDATE` o `EXRULE` que no se pueden representar, varias `RRULE`, un VEVENT sin UID (recibe un id derivado de su contenido, estable entre lecturas), un UID repetido, un archivo en Windows-1252 en vez de UTF-8, un archivo truncado, una `URL` descartada (ver «Archivos de terceros»).
- **Como mucho `maxErrors`** errores y `maxErrors` avisos se guardan en `errors()` y `warnings()`, cada mensaje truncado a 200 caracteres; `errorCount()` y `warningCount()` dicen cuántos hubo en total. Un archivo con 300 000 líneas malas no llena la memoria con sus mensajes.
- Los **VTODO, VJOURNAL, VFREEBUSY y VALARM** se ignoran sin avisar. Lo que hay dentro de un VALARM no se cuela en el evento.

Cada llamada a `read()` empieza de cero: `errors()`, `warnings()` y sus contadores son los de la última.

### Qué se lee de un VEVENT

| iCalendar | `CalendarEvent` |
|---|---|
| `UID` | `id` (con `uidDomain`, sin el `@dominio`). Siempre un string |
| `SUMMARY` | `title` |
| `DTSTART`, `DTEND` / `DURATION` | `start`, `end` (ver «Cómo viaja cada fecha») |
| `RRULE`, `EXDATE` | `rrule`, `exdates` |
| `RECURRENCE-ID` | `recurrenceOf` (el UID de la serie) y `recurrenceId`. El `id` de la excepción es `{UID}#{RECURRENCE-ID}`: iCalendar no le da UID propio, y así es estable entre lecturas del mismo archivo |
| `URL`, `X-KORE-COLOR` (o `COLOR`) | `url`, `color` |
| `X-KORE-RESOURCES` | `resourceIds` (strings) |
| `DESCRIPTION`, `LOCATION`, `STATUS`, `CATEGORIES`, `ORGANIZER`, `ATTENDEE`, `SEQUENCE` | `meta['description']`, `['location']`, `['status']`, `['categories']`, `['organizer']`, `['attendees']` (`['email' => …, 'name' => …]`), `['sequence']` |
| Cualquier `X-…` | `meta['x']['X-NOMBRE']` |
| `X-WR-CALNAME` | `$reader->calendarName()` |

Lo demás (`CLASS`, `PRIORITY`, `GEO`, `ATTACH`…) no se guarda.

## ICalSource

Dos constructores, a propósito: `ICalSource::fromString($ics)` para un contenido (un archivo subido, una respuesta HTTP) y `ICalSource::fromFile($ruta)` para un archivo en disco. `fromString` nunca interpreta el texto como ruta: un constructor que lo adivinara convertiría un dato del usuario en un lector de archivos del servidor.

Un `.ics` (su contenido o la ruta) como fuente del calendario, junto a `EloquentSource` y compañía. Los dos constructores aceptan como segundo argumento las opciones de `ICalReader` (`strict`, `uidDomain`, `untitled`, `idPrefix`, `maxBytes`, `maxEvents`, `maxErrors`):

```php
use KoreUi\Calendar\ICal\ICalSource;

// En un componente KoreCalendar
protected function events(CarbonImmutable $from, CarbonImmutable $to): iterable
{
    return [
        ...Meeting::overlapping($from, $to)->get()->map->toCalendarEvent(),
        ...ICalSource::fromFile(storage_path('app/festivos.ics'), ['idPrefix' => 'ics:'])->events($from, $to),
    ];
}
```

Con `fromFile`, un archivo mayor que `maxBytes` no se carga entero en memoria: se leen `maxBytes + 1` bytes y el lector lo rechaza.

```blade
<x-kore::calendar :events="[\KoreUi\Calendar\ICal\ICalSource::fromFile(storage_path('app/festivos.ics'))]" />
```

La fuente lee y analiza el archivo **una vez por instancia**, la primera vez que se le piden eventos; no cachea entre peticiones. Para un `.ics` grande que se consulta en cada render, guarda los eventos ya leídos (cache de Laravel) y pásalos como `ArraySource`.

Devuelve lo que puede tocar el rango y deja el resto al colector:

- **Las series, siempre**, salvo las que empiezan después del rango. La expansión la hace `EventCollector`, que es quien sabe en qué zona se dibuja.
- **Las ocurrencias modificadas, siempre.** Una excepción movida fuera del rango tiene que llegar igualmente: es lo que le dice a la serie que no genere esa ocurrencia.
- **El resto, si cruza el rango**, con un día de margen por cada lado. Un evento sin fin cuenta si empieza como mucho un día antes (el criterio de `EloquentSource`).

`errors()` y `warnings()` de la fuente son los del lector. Un archivo que no es iCalendar lanza al pedir los eventos, no al construirla; una ruta que no se puede leer, al construirla.

## Cómo viaja cada fecha

Las cuatro representaciones del modelo de tiempo del paquete son las cuatro de iCalendar, y cada fecha sale y vuelve en la suya:

| `CalendarEvent` | iCalendar | Al leer |
|---|---|---|
| Día completo (`allDay`, o `'2026-10-16'` sin hora). Fin exclusivo | `DTSTART;VALUE=DATE:20261016` | String `'2026-10-16'`, `allDay: true` |
| Fecha con zona IANA (`Europe/Madrid`) | `DTSTART;TZID=Europe/Madrid:20261016T090000` y un VTIMEZONE | `DateTimeImmutable` en esa zona |
| Instante UTC | `DTSTART:20261016T070000Z` | `DateTimeImmutable` en UTC |
| String sin offset (hora de pared) | `DTSTART:20261016T090000` | String `'2026-10-16 09:00:00'` |

- **Offsets fijos.** `'2026-10-16T09:00:00+02:00'` es un instante, no una zona: un evento suelto se escribe en UTC. Una **serie** con offset fijo no puede (su regla corre en la hora de pared de esa zona: `BYDAY=MO` a las 01:00+02:00 es domingo en UTC), así que se escribe con un TZID `UTC+0200` y su propio VTIMEZONE.
- **Hora repetida del otoño.** Un TZID no distingue las dos veces que existe la 02:30 del 25-oct en Madrid; RFC 5545 dice que es la primera. Un evento suelto que está en la segunda se escribe como instante UTC, y al leer, una hora repetida es siempre la primera (y una que no existe, la siguiente). Es la misma regla de `WallClock`.
- **Sin fin.** No se escribe `DTEND` ni `DURATION`: es lo que RFC 5545 define como evento sin fin (un día, si es de día completo; duración cero, si tiene hora) y lo único que se lee de vuelta como «sin fin». La duración por defecto del calendario es de tu aplicación, no del archivo. Algunos clientes pintan un evento de duración cero como un punto: con `defaultDuration` se escribe un `DURATION:PT1H` explícito en los eventos con hora sin fin. Un fin que no es posterior al inicio no es un fin y no se escribe.
- **`UNTIL`.** RFC 5545 liga el `UNTIL` a la representación del inicio: una fecha en un día completo, una hora local sin `Z` en una flotante, UTC en una con zona o UTC. El paquete admite `UNTIL=20261231` («hasta ese día»), y al escribir se reescribe a la forma que el cliente espera (`UNTIL=20261231T225959Z` en una serie de Madrid en invierno). Un `UNTIL` que ya es correcto no se toca. Por eso el texto de la `rrule` puede cambiar en la vuelta; las ocurrencias, no.
- **`EXDATE` y `RECURRENCE-ID`** van en la representación de su serie. Una fecha sin hora sobre una serie con hora («la ocurrencia de ese día, sea a la hora que sea») no existe en iCalendar, así que se busca la hora real de esa ocurrencia. Si ese día no hay ninguna, el `EXDATE` se omite. Se escriben como mucho `recurrence.max_occurrences` horas por día, y la búsqueda respeta `recurrence.max_iterations`: sobre `FREQ=MINUTELY` desde 2020 no recorre tres millones de ocurrencias.
- **`COLOR`.** RFC 7986 solo admite nombres de color CSS3, y el color de un evento suele ser un token del tema (`chart-3`) o un `oklch()`. El valor original viaja en `X-KORE-COLOR` (es lo que lo recupera en la vuelta) y `COLOR` solo se escribe cuando es un nombre CSS3 (`tomato`), para no mandar a Google o Apple un valor que no es válido. Al leer, `X-KORE-COLOR` gana a `COLOR`.

### VTIMEZONE

Por cada TZID usado se escribe un VTIMEZONE generado desde la base de datos de zonas de PHP: un `STANDARD`/`DAYLIGHT` por cada cambio de hora de los años del archivo, con `TZOFFSETFROM`, `TZOFFSETTO` y su `DTSTART`. Son fechas explícitas, no reglas anuales: una regla derivada de las transiciones vale para Europa y Estados Unidos y falla en zonas con calendarios irregulares. Cubre desde el primer año del archivo hasta el último; una serie con `UNTIL` o `COUNT` llega hasta su final, y una sin final, diez años más. Fuera de ese rango, el cliente usa su propia base de datos por el nombre del TZID, que es lo que hacen Google y Apple de todos modos.

### Zonas de Outlook

Outlook y Exchange no escriben `TZID=Europe/Madrid` sino un nombre de Windows (`TZID=Romance Standard Time`), que no es de ninguna base de datos de zonas. Lo único que dice qué significa es el VTIMEZONE del mismo archivo. En orden:

1. Un nombre que PHP conoce, con el prefijo global de RFC 5545 (`/mozilla.org/20050126_1/Europe/Madrid`) o como desplazamiento (`UTC+0200`, `GMT+01:00`).
2. Un TZID con VTIMEZONE: se busca una zona de PHP con los mismos cambios de hora en los años del evento, y se **avisa** (una vez por TZID): `el TZID «Romance Standard Time» no es una zona IANA; se usó la definición de su VTIMEZONE, que coincide con «Europe/Madrid»`. La zona elegida tiene las mismas reglas, no es necesariamente la ciudad del usuario (Madrid, París y Berlín cambian a la vez); desempatan `X-LIC-LOCATION`, la zona de la aplicación y una lista de zonas habituales. Lo que importa es que una serie semanal de Outlook no se corra una hora en invierno.
3. Si el VTIMEZONE no tiene cambios de hora, un desplazamiento fijo. Si los tiene y ninguna zona de PHP coincide, el desplazamiento que el VTIMEZONE da en la fecha de ese evento: es correcto ese día, pero una serie ya no sigue el cambio de hora. El aviso lo dice.

Un TZID desconocido y sin VTIMEZONE en el archivo no se puede resolver: es un error de ese VEVENT. Lo es también un desplazamiento que no existe (`UTC+99:99`, `GMT-25:00`: se admite hasta ±14:00), un nombre con caracteres nulos o de más de 100 caracteres; ninguno aborta la lectura del resto.

**Un VTIMEZONE solo admite `FREQ=YEARLY`.** Una zona cambia de hora una vez al año; Outlook y Google escriben `RRULE:FREQ=YEARLY;…`. Un `STANDARD` o `DAYLIGHT` con otra frecuencia (`FREQ=SECONDLY` desde 1601 son 13 000 millones de ocurrencias) hace que se ignore el VTIMEZONE entero, con un aviso, y sus TZID se resuelven como un TZID desconocido. Lo que sí vale se calcula en una ventana de 50 años anteriores a la fecha consultada, con tope de ocurrencias, y un VTIMEZONE con más de 10 000 `RDATE` se ignora.

## Ida y vuelta

`read(write($events))` devuelve eventos equivalentes, y al expandirlos con `EventCollector` salen las mismas ocurrencias en cualquier zona de vista. Está probado para día completo, con zona, UTC, flotante, series con `EXDATE`, ocurrencias modificadas (con zona, de día completo y flotantes) y series que cruzan el cambio de hora; y escribir lo leído da el mismo archivo.

**Se conserva:** título, fechas (cada una en su representación y, con zona, su zona), fin o su ausencia, `rrule`, `exdates`, ocurrencias modificadas, `resourceIds`, `color`, `url`, y de `meta`: `description`, `location`, `status` (`TENTATIVE`, `CONFIRMED`, `CANCELLED`), `categories` (lista de strings), `sequence` (entero) y `x` (propiedades `X-…` con valor de texto).

**Cambia, sin que cambie el evento:**

- Un `id` numérico vuelve como string (`7` → `'7'`); con `uidDomain` en la lectura se recupera sin el `@dominio`.
- La excepción de una serie vuelve con el id `{serie}#{RECURRENCE-ID}`, no con el que tenía.
- Un día completo inferido (`'2026-10-16'` sin `allDay`) vuelve con `allDay: true`.
- Un instante con offset (`+02:00`) vuelve como UTC; el mismo instante.
- El texto de un `UNTIL` que no era RFC (ver arriba).

**No se conserva:** `groupId`, `display`, `editable`, `startEditable`, `durationEditable`, `overlap`, las claves de `meta` que no sean las de arriba, y al escribir tampoco `organizer` ni `attendees`. Son de la interfaz o de tu aplicación, no del archivo.

## Archivos de terceros

Un `.ics` lo escribe quien quiera: el de un enlace de suscripción, el que sube un usuario. El lector no confía en él.

- **Tamaño.** `maxBytes` (5 MB) rechaza el archivo antes de analizarlo. El tiempo de análisis es proporcional al tamaño del archivo (sin casos cuadráticos: un archivo hostil de 5 MB tarda menos de un segundo), y la memoria está acotada: se guardan como mucho `maxBytes / 24` componentes y propiedades (218 000 con 5 MB), un máximo de 16 niveles de componentes anidados, 5 000 `EXDATE` y 1 000 `ATTENDEE` por evento, y se deja de leer al pasar `maxEvents` VEVENT. Lo que falta por esto queda en `errors()` (los VEVENT sin leer) o en `warnings()` (los `EXDATE` y `ATTENDEE` de más).
- **Errores.** `maxErrors` y mensajes de 200 caracteres como mucho (ver «Errores y avisos»). Las líneas malas se guardan como datos (línea y mensaje), no como excepciones con su traza.
- **`URL`.** Se descarta, con un aviso, una `URL:` que no sea relativa ni `http`, `https`, `mailto` o `tel`: `javascript:alert(1)` en el enlace de un evento sería un XSS. Se mira como un navegador (ignora tabuladores dentro de la URL: `java⇥script:`) y lo ambiguo se rechaza.
- **Reglas.** Una `RRULE` hostil no tumba el calendario al dibujarlo: `DTSTART:20200101` con `FREQ=MINUTELY` no recorre tres millones de ocurrencias, y los topes de `recurrence.*` (ver [Configuración](configuration.md)) cortan lo demás.
- **Ids.** Al mezclar el archivo con eventos propios, `idPrefix` (ver «Importar»).

## Límites

- **`RDATE` y `EXRULE`** no tienen equivalente en `CalendarEvent`: se ignoran con un aviso, y la serie puede mostrar ocurrencias de más o de menos.
- **`RECURRENCE-ID;RANGE=THISANDFUTURE`** está obsoleto en RFC 5545: se trata como una sola ocurrencia, con un aviso. Si necesitas «esta y las siguientes», `RecurrenceEditor::splitAt()` corta la serie en dos.
- **Una `RRULE` que php-rrule no entiende** hace fallar ese VEVENT al leerlo, en vez de lanzar más tarde al dibujar el calendario.
- **El archivo tiene topes** (`maxBytes`, `maxEvents`, `maxErrors`, ver «Archivos de terceros»). Subirlos es posible; la memoria y el tiempo que gasta un archivo en el peor caso crecen con ellos.
- **iCalendar 1.0 (vCalendar, `.vcs`)** no se soporta: se lee como 2.0 con un aviso.

## Ver también

- [Calendar](getting-started.md): eventos, series recurrentes y fuentes de eventos.
- [KoreCalendar](kore-calendar.md): `RecurrenceEditor` y los hooks que reciben las series.
- [Configuración](configuration.md): `recurrence.max_occurrences` y el resto de claves (`recurrence.*` y `ical.*` para los topes de este documento).
