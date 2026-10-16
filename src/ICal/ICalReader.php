<?php

namespace KoreUi\Calendar\ICal;

use DateInterval;
use InvalidArgumentException;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Recurrence\RecurrenceExpander;
use RRule\RRule;
use Throwable;

/**
 * Archivo iCalendar (RFC 5545) → eventos.
 *
 * Lee lo que exportan Google Calendar, Outlook/Exchange y Apple Calendar y lo lleva al
 * modelo del paquete: cada fecha conserva su representación (`VALUE=DATE` → día completo,
 * `TZID=` → fecha con su zona, `Z` → instante UTC, sin nada → hora de pared flotante).
 *
 * - **Zonas.** `TZID=Europe/Madrid` se resuelve con la zona de PHP del mismo nombre. Un
 *   TZID que PHP no conoce («Romance Standard Time», lo que exporta Outlook) se resuelve
 *   con el VTIMEZONE del mismo archivo y se avisa en `warnings()`: ver `TimezoneResolver`.
 * - **Ids.** El id de un evento es su UID. Con la opción `uidDomain` se le quita el
 *   `@dominio`, que es lo que `ICalWriter` le añade: así la vuelta recupera los ids
 *   originales (como strings). Una excepción (RECURRENCE-ID) no tiene UID propio: su id
 *   es `{UID de la serie}#{RECURRENCE-ID}`, estable entre lecturas del mismo archivo.
 * - **Lo que no es de `CalendarEvent`** va a `meta` (`description`, `location`, `status`,
 *   `categories`, `organizer`, `attendees`, `sequence` y `x` con las propiedades
 *   `X-`), nunca se pierde en silencio ni rompe la lectura. Se ignoran VTODO, VJOURNAL,
 *   VFREEBUSY y los VALARM.
 * - **Errores.** Un VEVENT que no se puede leer (sin DTSTART, con una fecha inválida, un
 *   TZID sin definición, una RRULE que php-rrule rechaza) se salta y queda en `errors()`
 *   con su línea; con `strict` se lanza una `ICalException` en el primero. Lo que no es un
 *   archivo iCalendar (sin `BEGIN:VCALENDAR`) lanza siempre. Los avisos (una zona
 *   resuelta por su VTIMEZONE, un RDATE que no se puede representar…) van a `warnings()`
 *   y nunca lanzan.
 * - **Límites.** El archivo lo escribe un tercero. `maxBytes` rechaza el que pesa más;
 *   `maxEvents` deja de leer al llegar al VEVENT número `maxEvents + 1` (queda en `errors()`);
 *   `maxErrors` acota cuántos errores y avisos se guardan: de ahí en adelante solo se
 *   cuentan (`errorCount()`, `warningCount()`), y cada mensaje se trunca a 200 caracteres.
 *   Un archivo hostil no puede gastar más memoria o tiempo que esos topes.
 * - **URL.** Se descarta (con un aviso) una `URL:` que no sea relativa ni `http`, `https`,
 *   `mailto` o `tel`: un `javascript:` en un enlace de la interfaz sería un XSS.
 *
 * Opciones: `strict` (false), `uidDomain` (null), `untitled` («(sin título)»: el título
 * de un evento sin SUMMARY), `maxBytes` (`ical.max_bytes`, 5 MB), `maxEvents`
 * (`ical.max_events`, 10 000), `maxErrors` (`ical.max_errors`, 100) e `idPrefix` (''):
 * un prefijo para el `id` y el `recurrenceOf`, para que el UID de un archivo ajeno no
 * choque con el id de un evento propio al mezclar fuentes (`'ics:'`).
 */
final class ICalReader
{
    private const OPTIONS = ['strict', 'uidDomain', 'untitled', 'maxBytes', 'maxEvents', 'maxErrors', 'idPrefix'];

    private const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    private const DEFAULT_MAX_EVENTS = 10_000;

    private const DEFAULT_MAX_ERRORS = 100;

    /** Caracteres de un mensaje de error o aviso: lo demás lo pone el archivo, que no es de fiar. */
    private const MESSAGE_LENGTH = 200;

    /**
     * Lo que ocupa como mínimo, en bytes, una propiedad o un componente de los que se guardan. Acota
     * cuántos se guardan según `maxBytes` (con 5 MB, 218 000): cada uno es un objeto de unos 250 bytes,
     * y un archivo de líneas de tres bytes sería, sin tope, diez veces su tamaño en memoria.
     */
    private const MIN_ITEM_BYTES = 24;

    /**
     * Niveles de componentes anidados. Un archivo real llega a 3 (VCALENDAR > VEVENT > VALARM);
     * uno de 450 000 BEGIN seguidos tumba a PHP al liberarlo, que lo hace recursivamente.
     */
    private const MAX_DEPTH = 16;

    /** ATTENDEE que se leen de un VEVENT: cada uno es un array en `meta`. */
    private const MAX_ATTENDEES = 1_000;

    /** EXDATE que se leen de un VEVENT: cada uno es un objeto, y una línea de 5 MB son 300 000. */
    private const MAX_EXDATES = 5_000;

    /** Esquemas de una `URL:` que se aceptan, además de una ruta relativa. */
    private const URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Componentes que no pueden contener a otro igual: un BEGIN de uno de ellos cierra a la fuerza el anterior. */
    private const BLOCKS = ['VEVENT', 'VTODO', 'VJOURNAL', 'VFREEBUSY', 'VTIMEZONE'];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    private int $errorCount = 0;

    private int $warningCount = 0;

    private int $maxErrors = self::DEFAULT_MAX_ERRORS;

    private ?string $calendarName = null;

    private bool $recoded = false;

    /** @var ?array{line: int, message: string} Por qué se dejó de leer antes del final (un tope), si pasó. */
    private ?array $stopped = null;

    /**
     * @param  array{strict?: bool, uidDomain?: ?string, untitled?: string, maxBytes?: int, maxEvents?: int, maxErrors?: int, idPrefix?: string}  $options
     * @return list<CalendarEvent>
     *
     * @throws ICalException Si no es un archivo iCalendar, pesa más de `maxBytes`, o en el primer error con `strict`.
     */
    public function read(string $ics, array $options = []): array
    {
        $o = $this->options($options);
        $this->errors = $this->warnings = [];
        $this->errorCount = $this->warningCount = 0;
        $this->maxErrors = $o['maxErrors'];
        $this->calendarName = null;
        $this->recoded = false;
        $this->stopped = null;

        if (strlen($ics) > $o['maxBytes']) {
            throw new ICalException(sprintf(
                'El archivo pesa %s y el máximo que se lee es %s (opción maxBytes de ICalReader, o `ical.max_bytes` en el config).',
                self::size(strlen($ics)),
                self::size($o['maxBytes']),
            ));
        }

        $calendars = $this->parse($ics, $o);

        if ($calendars === []) {
            throw new ICalException('El contenido no es un archivo iCalendar: no se encontró BEGIN:VCALENDAR.');
        }

        $events = [];
        $seen = [];

        foreach ($calendars as $calendar) {
            foreach ($calendar->problems as $problem) {
                // Un archivo truncado se lee hasta donde llega; con `strict` es un error.
                if ($o['strict']) {
                    throw new ICalException($problem['message'], $problem['line']);
                }

                $this->warn($problem['message']);
            }

            // Los que no cupieron en el componente: solo se cuentan.
            $this->warningCount += $calendar->dropped;

            if (($version = $calendar->first('VERSION')) !== null && trim($version->value) !== '2.0') {
                $this->warn("Línea {$version->line}: VERSION «".trim($version->value).'» no es 2.0; se lee como iCalendar 2.0.');
            }

            $this->calendarName ??= ($name = $calendar->first('X-WR-CALNAME')) !== null ? trim(Text::unescape($name->value)) : null;

            $resolver = new TimezoneResolver(fn (string $message) => $this->warn($message));

            foreach ($calendar->children as $child) {
                if ($child->name !== 'VTIMEZONE') {
                    continue;
                }

                try {
                    $resolver->define(TimezoneDefinition::fromComponent($child));
                } catch (ICalException $e) {
                    // Solo importa si algún evento usa ese TZID, y entonces el error sale con el evento.
                    $this->warn($e->getMessage());
                }
            }

            foreach ($calendar->children as $child) {
                if ($child->name !== 'VEVENT') {
                    continue;
                }

                try {
                    $event = $this->event($child, $resolver, $o);
                } catch (ICalException $e) {
                    if ($o['strict']) {
                        throw $e;
                    }

                    $this->fail($e->getMessage());

                    continue;
                }

                if (isset($seen[(string) $event->id])) {
                    $this->warn("Línea {$child->line}: el UID «{$event->id}» está repetido; los dos eventos se conservan.");
                }

                $seen[(string) $event->id] = true;
                $events[] = $event;
            }
        }

        // Se dejó de leer a medias: lo que queda sin leer es un error, no un aviso (faltan eventos).
        if ($this->stopped !== null) {
            if ($o['strict']) {
                throw new ICalException($this->stopped['message'], $this->stopped['line']);
            }

            $this->fail($this->stopped['message']);
        }

        return $events;
    }

    /**
     * Los VEVENT que se saltaron en la última lectura, cada uno con su línea. Como mucho
     * `maxErrors`: los demás están en `errorCount()`.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Lo que se leyó con una salvedad: una zona resuelta por su VTIMEZONE, un RDATE ignorado…
     * Como mucho `maxErrors`: los demás están en `warningCount()`.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** Cuántos errores hubo en la última lectura, también los que pasaron de `maxErrors` y no se guardaron. */
    public function errorCount(): int
    {
        return $this->errorCount;
    }

    /** Cuántos avisos hubo en la última lectura, también los que pasaron de `maxErrors` y no se guardaron. */
    public function warningCount(): int
    {
        return $this->warningCount;
    }

    /**
     * El tamaño máximo de archivo que se lee con estas opciones. `ICalSource` lo pregunta para
     * no cargar en memoria un archivo de gigas antes de que `read()` lo rechace.
     *
     * @param  array<string, mixed>  $options
     */
    public function maxBytes(array $options = []): int
    {
        return $this->options($options)['maxBytes'];
    }

    /** El `X-WR-CALNAME` del último archivo leído. */
    public function calendarName(): ?string
    {
        return $this->calendarName;
    }

    // ── Estructura ─────────────────────────────────────────────────────────

    /**
     * El archivo como árbol de componentes. Un BEGIN sin su END no tumba el resto: el
     * componente queda marcado como roto y se sigue leyendo.
     *
     * Una pasada y lineal: para saber si un END tiene su BEGIN se lleva la cuenta de los
     * componentes abiertos por nombre, en vez de buscarlo recorriendo la pila (35 000 pares
     * desparejados eran 12 s).
     *
     * @param  array{maxEvents: int, maxBytes: int, maxErrors: int}  $o
     * @return list<Component> Los VCALENDAR.
     */
    private function parse(string $ics, array $o): array
    {
        if (str_starts_with($ics, "\xEF\xBB\xBF")) {
            $ics = substr($ics, 3);
        }

        $roots = [];
        $stack = [];
        $open = [];
        $events = 0;
        $items = 0;
        $maxItems = intdiv($o['maxBytes'], self::MIN_ITEM_BYTES);

        foreach (Text::lines($ics) as [$no, $text]) {
            if (trim($text) === '') {
                continue;
            }

            // Después de desplegar: un carácter partido entre dos líneas físicas solo es UTF-8
            // válido una vez unidos sus bytes.
            if (! preg_match('//u', $text)) {
                $text = $this->recode($text);
            }

            $line = ContentLine::tryParse($text, $no, $error);

            if ($line === null) {
                if ($stack !== []) {
                    // De un VEVENT solo hace falta la primera línea mala; de un VCALENDAR, cada una es un aviso.
                    $top = $stack[array_key_last($stack)];
                    $top->problem($error, $no, $top->name === 'VCALENDAR' ? $o['maxErrors'] : 1);
                }

                continue;
            }

            if ($line->name === 'END') {
                $name = strtoupper(trim($line->value));

                if (($open[$name] ?? 0) === 0) {
                    $this->warn("Línea {$no}: END:{$name} sin su BEGIN; se ignora.");

                    continue;
                }

                // Lo que quedó abierto entre el BEGIN de este END y él mismo está roto.
                while ($stack[array_key_last($stack)]->name !== $name) {
                    $broken = array_pop($stack);
                    $open[$broken->name]--;
                    $broken->unclosed("Línea {$broken->line}: el {$broken->name} no se cierra (falta END:{$broken->name}).", $broken->line);
                }

                array_pop($stack);
                $open[$name]--;

                continue;
            }

            // Cada componente y cada propiedad que se guarda es un objeto en memoria: acotados por el tamaño permitido.
            if (++$items > $maxItems) {
                $this->stopped = ['line' => $no, 'message' => "Línea {$no}: el archivo tiene demasiadas líneas para su tamaño máximo; se dejó de leer aquí."];

                break;
            }

            if ($line->name === 'BEGIN') {
                $name = strtoupper(trim($line->value));

                // Un VEVENT sin END seguido de otro BEGIN:VEVENT: el primero está roto, no contiene al segundo.
                while ($stack !== [] && in_array($stack[array_key_last($stack)]->name, self::BLOCKS, true)
                    && (in_array($name, self::BLOCKS, true) || $name === 'VCALENDAR')) {
                    $broken = array_pop($stack);
                    $open[$broken->name]--;
                    $broken->unclosed("Línea {$broken->line}: el {$broken->name} no se cierra (falta END:{$broken->name}).", $broken->line);
                }

                if ($name === 'VEVENT' && ++$events > $o['maxEvents']) {
                    $this->stopped = ['line' => $no, 'message' => "Línea {$no}: el archivo tiene más de {$o['maxEvents']} VEVENT (opción maxEvents); se dejó de leer aquí."];

                    break;
                }

                if (count($stack) >= self::MAX_DEPTH) {
                    $this->stopped = ['line' => $no, 'message' => 'Línea '.$no.': los componentes están anidados a más de '.self::MAX_DEPTH.' niveles; se dejó de leer aquí.'];

                    break;
                }

                $component = new Component($name, $no);

                if ($stack === []) {
                    $roots[] = $component;
                } else {
                    $stack[array_key_last($stack)]->children[] = $component;
                }

                $stack[] = $component;
                $open[$name] = ($open[$name] ?? 0) + 1;

                continue;
            }

            if ($stack !== []) {
                $stack[array_key_last($stack)]->lines[] = $line;
            }
        }

        // Si se dejó de leer por un tope, lo que sigue abierto no es un archivo truncado.
        if ($this->stopped === null) {
            foreach ($stack as $unclosed) {
                $unclosed->unclosed("Línea {$unclosed->line}: el {$unclosed->name} no se cierra (falta END:{$unclosed->name}); el archivo parece truncado.", $unclosed->line);
            }
        }

        return array_values(array_filter($roots, fn (Component $c) => $c->name === 'VCALENDAR'));
    }

    /** Un aviso: se guardan como mucho `maxErrors`, truncados; los demás solo se cuentan. */
    private function warn(string $message): void
    {
        $this->warningCount++;

        if (count($this->warnings) < $this->maxErrors) {
            $this->warnings[] = self::truncate($message);
        }
    }

    /** Un error (un VEVENT que se salta): lo mismo que `warn()`. */
    private function fail(string $message): void
    {
        $this->errorCount++;

        if (count($this->errors) < $this->maxErrors) {
            $this->errors[] = self::truncate($message);
        }
    }

    private static function truncate(string $message): string
    {
        return strlen($message) > self::MESSAGE_LENGTH && mb_strlen($message) > self::MESSAGE_LENGTH
            ? mb_substr($message, 0, self::MESSAGE_LENGTH - 1).'…'
            : $message;
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1_048_576 ? round($bytes / 1_048_576, 1).' MB' : round($bytes / 1024, 1).' KB';
    }

    private function recode(string $text): string
    {
        if (! $this->recoded) {
            $this->recoded = true;
            $this->warn('El archivo no está en UTF-8; se interpretó como Windows-1252.');
        }

        return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    // ── Eventos ────────────────────────────────────────────────────────────

    /**
     * @param  array{strict: bool, uidDomain: ?string, untitled: string, idPrefix: string}  $o
     *
     * @throws ICalException
     */
    private function event(Component $v, TimezoneResolver $tz, array $o): CalendarEvent
    {
        if ($v->problems !== []) {
            throw new ICalException($v->problems[0]['message'], $v->problems[0]['line']);
        }

        $startLine = $v->first('DTSTART')
            ?? throw new ICalException("Línea {$v->line}: el VEVENT no tiene DTSTART.", $v->line);

        $start = $this->date($startLine, $tz);
        $end = null;

        if (($line = $v->first('DTEND')) !== null) {
            $end = $this->date($line, $tz);

            // Un día completo con un fin con hora: se queda con el día.
            if ($start->kind === DateValue::DATE && $end->kind !== DateValue::DATE) {
                $end = $end->toDate();
            }
        } elseif (($line = $v->first('DURATION')) !== null) {
            $end = $start->plus($this->duration($line));
        }

        // Outlook marca los eventos de día completo con una hora a las 00:00 y esta propiedad.
        $flag = $v->first('X-MICROSOFT-CDO-ALLDAYEVENT');

        if ($flag !== null && strtoupper(trim($flag->value)) === 'TRUE'
            && in_array($start->kind, [DateValue::FLOATING, DateValue::ZONED], true) && $start->isMidnight()
            && ($end === null || ($end->kind === $start->kind && $end->isMidnight()))) {
            $start = $start->toDate();
            $end = $end?->toDate();
        }

        $rrule = null;
        $rules = $v->all('RRULE');

        if ($rules !== []) {
            if (count($rules) > 1) {
                $this->warn("Línea {$rules[1]->line}: el VEVENT tiene varios RRULE; solo se usa el primero.");
            }

            $rrule = $this->rule($rules[0], $start);
        }

        foreach (['RDATE', 'EXRULE'] as $name) {
            if (($line = $v->first($name)) !== null) {
                $this->warn("Línea {$line->line}: {$name} no se puede representar y se ignora; la serie puede mostrar ocurrencias de más o de menos.");
            }
        }

        $exdates = [];

        foreach ($v->all('EXDATE') as $line) {
            foreach (explode(',', $line->value) as $piece) {
                if (trim($piece) === '') {
                    continue;
                }

                // Cada EXDATE es un objeto, y el expansor los compara en cada render.
                if (count($exdates) >= self::MAX_EXDATES) {
                    $this->warn("Línea {$line->line}: el VEVENT tiene más de ".self::MAX_EXDATES.' EXDATE; se ignoran los demás.');
                    break 2;
                }

                $exdates[] = $this->date(new ContentLine('EXDATE', $line->params, trim($piece), $line->line), $tz)->toEventValue();
            }
        }

        $recurrence = null;

        if (($line = $v->first('RECURRENCE-ID')) !== null) {
            $recurrence = $this->date($line, $tz);

            if (($range = $line->param('RANGE')) !== null) {
                $this->warn("Línea {$line->line}: RECURRENCE-ID;RANGE={$range} está obsoleto y no se soporta; se trata como una sola ocurrencia.");
            }

            if ($rrule !== null) {
                $this->warn("Línea {$line->line}: una ocurrencia modificada no puede llevar RRULE; se ignora.");
                $rrule = null;
                $exdates = [];
            }
        }

        if ($exdates !== [] && $rrule === null) {
            $this->warn('Línea '.$v->first('EXDATE')->line.': EXDATE sin RRULE no excluye nada; se ignora.');
            $exdates = [];
        }

        $uid = trim(Text::unescape($v->first('UID')?->value ?? ''));
        $summary = $v->first('SUMMARY');
        $title = $summary !== null ? Text::unescape($summary->value) : $o['untitled'];

        if ($uid === '') {
            if ($o['strict']) {
                throw new ICalException("Línea {$v->line}: el VEVENT no tiene UID (RFC 5545 lo exige).", $v->line);
            }

            // Sin UID no hay id estable entre lecturas: se deriva del contenido.
            $uid = 'ical-'.substr(sha1($title.'|'.$startLine->value.'|'.($rrule ?? '')), 0, 12);
            $this->warn("Línea {$v->line}: el VEVENT no tiene UID; se le dio el id «{$uid}».");
        }

        // El prefijo va delante del id y del recurrenceOf: sin él, un UID `1` de un archivo ajeno
        // y el evento `1` de la base de datos son el mismo para el colector.
        $seriesId = $o['idPrefix'].$this->id($uid, $o['uidDomain']);
        $id = $recurrence !== null ? $seriesId.'#'.$recurrence->raw : $seriesId;

        $resources = [];
        foreach ($v->all('X-KORE-RESOURCES') as $line) {
            array_push($resources, ...array_filter(Text::splitList($line->value), fn (string $r) => trim($r) !== ''));
        }

        // X-KORE-COLOR es el valor original (un token del tema); COLOR, el de otros clientes.
        $line = $v->first('X-KORE-COLOR') ?? $v->first('COLOR');
        $color = $line !== null ? trim(Text::unescape($line->value)) : '';
        $url = ($line = $v->first('URL')) !== null ? trim($line->value) : '';

        if ($url !== '' && ! self::safeUrl($url)) {
            $this->warn("Línea {$line->line}: la URL «".self::preview($url).'» no es relativa ni http, https, mailto o tel; se descarta.');
            $url = '';
        }

        try {
            return CalendarEvent::fromArray([
                'id' => $id,
                'title' => $title,
                'start' => $start->toEventValue(),
                'end' => $end?->toEventValue(),
                'allDay' => $start->kind === DateValue::DATE ? true : null,
                'resourceIds' => $resources,
                'color' => $color !== '' ? $color : null,
                'url' => $url !== '' ? $url : null,
                'meta' => $this->meta($v),
                'rrule' => $rrule,
                'exdates' => $exdates,
                'recurrenceOf' => $recurrence !== null ? $seriesId : null,
                'recurrenceId' => $recurrence?->toEventValue(),
            ]);
        } catch (InvalidArgumentException $e) {
            throw new ICalException("Línea {$v->line}: evento «{$id}»: {$e->getMessage()}", $v->line, $e);
        }
    }

    /**
     * Una URL que un enlace de la interfaz puede llevar: relativa o con un esquema de la lista.
     * Todo lo demás (`javascript:`, `data:`, `vbscript:`…) se descarta. Se mira como lo haría
     * un navegador, que ignora tabuladores y saltos dentro de la URL (`java\tscript:`), y se
     * es estricto con lo ambiguo: si antes de la primera `/`, `?` o `#` hay un `:`, es un esquema.
     */
    private static function safeUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }

        $head = substr($url, 0, strcspn($url, '/?#'));

        if (! str_contains($head, ':')) {
            return true;
        }

        return in_array(strtolower(substr($head, 0, strpos($head, ':'))), self::URL_SCHEMES, true);
    }

    private static function preview(string $text): string
    {
        return mb_strlen($text) > 60 ? mb_substr($text, 0, 57).'…' : $text;
    }

    private function id(string $uid, ?string $domain): string
    {
        if ($domain !== null && $domain !== '') {
            $suffix = '@'.$domain;

            if (strlen($uid) > strlen($suffix) && str_ends_with($uid, $suffix)) {
                return substr($uid, 0, -strlen($suffix));
            }
        }

        return $uid;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(Component $v): array
    {
        $meta = [];

        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $key => $name) {
            if (($line = $v->first($name)) !== null && trim($line->value) !== '') {
                $meta[$key] = Text::unescape($line->value);
            }
        }

        if (($line = $v->first('STATUS')) !== null && trim($line->value) !== '') {
            $meta['status'] = strtoupper(trim($line->value));
        }

        $categories = [];
        foreach ($v->all('CATEGORIES') as $line) {
            array_push($categories, ...array_filter(Text::splitList($line->value), fn (string $c) => trim($c) !== ''));
        }

        if ($categories !== []) {
            $meta['categories'] = $categories;
        }

        if (($line = $v->first('ORGANIZER')) !== null) {
            $meta['organizer'] = self::person($line);
        }

        if (($attendees = $v->all('ATTENDEE')) !== []) {
            if (count($attendees) > self::MAX_ATTENDEES) {
                $this->warn("Línea {$attendees[self::MAX_ATTENDEES]->line}: el VEVENT tiene más de ".self::MAX_ATTENDEES.' ATTENDEE; se ignoran los demás.');
                $attendees = array_slice($attendees, 0, self::MAX_ATTENDEES);
            }

            $meta['attendees'] = array_map(self::person(...), $attendees);
        }

        if (($line = $v->first('SEQUENCE')) !== null && preg_match('/^\d+$/', trim($line->value))) {
            $meta['sequence'] = (int) $line->value;
        }

        $x = [];
        foreach ($v->lines as $line) {
            if (str_starts_with($line->name, 'X-') && ! in_array($line->name, ['X-KORE-RESOURCES', 'X-KORE-COLOR'], true)) {
                $x[$line->name] = Text::unescape($line->value);
            }
        }

        if ($x !== []) {
            $meta['x'] = $x;
        }

        return $meta;
    }

    /**
     * @return array{email: string, name?: string}
     */
    private static function person(ContentLine $line): array
    {
        $person = ['email' => trim(preg_replace('/^mailto:/i', '', trim($line->value)))];

        if (($name = $line->param('CN')) !== null && $name !== '') {
            $person['name'] = $name;
        }

        return $person;
    }

    // ── Fechas, duraciones y reglas ────────────────────────────────────────

    /**
     * @throws ICalException
     */
    private function date(ContentLine $line, TimezoneResolver $tz): DateValue
    {
        $value = trim($line->value);

        if (! preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})?(Z)?)?$/i', $value, $m)) {
            throw new ICalException(
                "Línea {$line->line}: {$line->name} «{$value}» no es una fecha iCalendar válida (se espera 20261016, 20261016T090000 o 20261016T090000Z).",
                $line->line,
            );
        }

        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $hasTime = ($m[4] ?? '') !== '';
        [$h, $i, $s] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];

        // Un segundo intercalar (:60) es válido en RFC 5545 y no existe para PHP.
        $s = min($s, 59);

        if (! checkdate($mo, $d, $y) || $h > 23 || $i > 59) {
            throw new ICalException("Línea {$line->line}: {$line->name} «{$value}» no es una fecha que exista.", $line->line);
        }

        if (! $hasTime) {
            return DateValue::date($y, $mo, $d, $value);
        }

        if (($m[7] ?? '') !== '') {
            return DateValue::fromFields(DateValue::UTC, $y, $mo, $d, $h, $i, $s, null, null, $value);
        }

        $tzid = $line->param('TZID');

        if ($tzid === null || trim($tzid) === '') {
            return DateValue::floating($y, $mo, $d, $h, $i, $s, $value);
        }

        $zone = $tz->resolve($tzid, DateValue::floating($y, $mo, $d, $h, $i, $s)->dt, $line->line);

        return DateValue::fromFields(DateValue::ZONED, $y, $mo, $d, $h, $i, $s, $zone, $zone->getName(), $value);
    }

    /**
     * @throws ICalException
     */
    private function duration(ContentLine $line): DateInterval
    {
        $value = trim($line->value);

        if (! preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', $value, $m) || $value === 'P' || ! preg_match('/\d/', $value)) {
            throw new ICalException("Línea {$line->line}: DURATION «{$value}» no es una duración válida (se espera P1D, PT1H30M, P1W…).", $line->line);
        }

        $days = (int) ($m[2] ?? 0) * 7 + (int) ($m[3] ?? 0);
        $interval = new DateInterval(sprintf('P%dDT%dH%dM%dS', $days, (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)));
        $interval->invert = ($m[1] ?? '') === '-' ? 1 : 0;

        return $interval;
    }

    /**
     * La regla tal cual, tras comprobar que php-rrule la entiende: una RRULE rota
     * lanzaría al dibujar el calendario, lejos del archivo que la trajo.
     *
     * @throws ICalException
     */
    private function rule(ContentLine $line, DateValue $start): string
    {
        $rule = trim(preg_replace('/^RRULE:/i', '', trim($line->value)));

        try {
            new RRule(
                RecurrenceExpander::normalizeUntil($rule, $start->hasInstant() ? $start->dt->getTimezone() : null),
                $start->dt,
            );
        } catch (InvalidArgumentException $e) {
            throw new ICalException("Línea {$line->line}: RRULE «{$rule}» no válida ({$e->getMessage()}).", $line->line, $e);
        }

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{strict: bool, uidDomain: ?string, untitled: string, maxBytes: int, maxEvents: int, maxErrors: int, idPrefix: string}
     */
    private function options(array $options): array
    {
        $unknown = array_values(array_diff(array_keys($options), self::OPTIONS));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'ICalReader: opción desconocida «%s». Permitidas: %s.',
                implode('», «', $unknown),
                implode(', ', self::OPTIONS),
            ));
        }

        return [
            'strict' => (bool) ($options['strict'] ?? false),
            'uidDomain' => isset($options['uidDomain']) ? (string) $options['uidDomain'] : null,
            'untitled' => (string) ($options['untitled'] ?? '(sin título)'),
            'maxBytes' => self::limit($options, 'maxBytes', 'max_bytes', self::DEFAULT_MAX_BYTES, 1),
            'maxEvents' => self::limit($options, 'maxEvents', 'max_events', self::DEFAULT_MAX_EVENTS, 1),
            'maxErrors' => self::limit($options, 'maxErrors', 'max_errors', self::DEFAULT_MAX_ERRORS, 0),
            'idPrefix' => (string) ($options['idPrefix'] ?? ''),
        ];
    }

    /**
     * Un tope: la opción, o `ical.{clave}` del config, o el valor de serie. Un entero de al menos `$min`.
     *
     * @param  array<string, mixed>  $options
     */
    private static function limit(array $options, string $option, string $key, int $default, int $min): int
    {
        if (isset($options[$option])) {
            if (! is_int($options[$option]) || $options[$option] < $min) {
                throw new InvalidArgumentException("ICalReader: `{$option}` es un entero de al menos {$min}.");
            }

            return $options[$option];
        }

        try {
            $value = function_exists('config') ? config('kore-calendar.ical.'.$key) : null;
        } catch (Throwable) {
            $value = null;
        }

        return is_numeric($value) && (int) $value >= $min ? (int) $value : $default;
    }
}
