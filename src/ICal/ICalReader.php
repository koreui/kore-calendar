<?php

namespace KoreUi\Calendar\ICal;

use DateInterval;
use InvalidArgumentException;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Recurrence\RecurrenceExpander;
use RRule\RRule;

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
 *
 * Opciones: `strict` (false), `uidDomain` (null), `untitled` («(sin título)»: el título
 * de un evento sin SUMMARY).
 */
final class ICalReader
{
    private const OPTIONS = ['strict', 'uidDomain', 'untitled'];

    /** Componentes que no pueden contener a otro igual: un BEGIN de uno de ellos cierra a la fuerza el anterior. */
    private const BLOCKS = ['VEVENT', 'VTODO', 'VJOURNAL', 'VFREEBUSY', 'VTIMEZONE'];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    private ?string $calendarName = null;

    private bool $recoded = false;

    /**
     * @param  array{strict?: bool, uidDomain?: ?string, untitled?: string}  $options
     * @return list<CalendarEvent>
     *
     * @throws ICalException Si no es un archivo iCalendar, o en el primer error con `strict`.
     */
    public function read(string $ics, array $options = []): array
    {
        $o = $this->options($options);
        $this->errors = $this->warnings = [];
        $this->calendarName = null;
        $this->recoded = false;

        $calendars = $this->parse($ics);

        if ($calendars === []) {
            throw new ICalException('El contenido no es un archivo iCalendar: no se encontró BEGIN:VCALENDAR.');
        }

        $events = [];
        $seen = [];

        foreach ($calendars as $calendar) {
            foreach ($calendar->problems as $problem) {
                // Un archivo truncado se lee hasta donde llega; con `strict` es un error.
                if ($o['strict']) {
                    throw $problem;
                }

                $this->warnings[] = $problem->getMessage();
            }

            if (($version = $calendar->first('VERSION')) !== null && trim($version->value) !== '2.0') {
                $this->warnings[] = "Línea {$version->line}: VERSION «".trim($version->value).'» no es 2.0; se lee como iCalendar 2.0.';
            }

            $this->calendarName ??= ($name = $calendar->first('X-WR-CALNAME')) !== null ? trim(Text::unescape($name->value)) : null;

            $resolver = new TimezoneResolver(fn (string $message) => $this->warnings[] = $message);

            foreach ($calendar->children as $child) {
                if ($child->name !== 'VTIMEZONE') {
                    continue;
                }

                try {
                    $resolver->define(TimezoneDefinition::fromComponent($child));
                } catch (ICalException $e) {
                    // Solo importa si algún evento usa ese TZID, y entonces el error sale con el evento.
                    $this->warnings[] = $e->getMessage();
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

                    $this->errors[] = $e->getMessage();

                    continue;
                }

                if (isset($seen[(string) $event->id])) {
                    $this->warnings[] = "Línea {$child->line}: el UID «{$event->id}» está repetido; los dos eventos se conservan.";
                }

                $seen[(string) $event->id] = true;
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Los VEVENT que se saltaron en la última lectura, cada uno con su línea.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Lo que se leyó con una salvedad: una zona resuelta por su VTIMEZONE, un RDATE ignorado…
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
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
     * @return list<Component> Los VCALENDAR.
     */
    private function parse(string $ics): array
    {
        $ics = preg_replace('/^\xEF\xBB\xBF/', '', $ics);
        $roots = [];
        $stack = [];

        foreach (Text::unfold($ics) as [$no, $text]) {
            if (trim($text) === '') {
                continue;
            }

            // Después de desplegar: un carácter partido entre dos líneas físicas solo es UTF-8
            // válido una vez unidos sus bytes.
            if (! preg_match('//u', $text)) {
                $text = $this->recode($text);
            }

            try {
                $line = ContentLine::parse($text, $no);
            } catch (ICalException $e) {
                if ($stack !== []) {
                    $stack[array_key_last($stack)]->problems[] = $e;
                }

                continue;
            }

            if ($line->name === 'BEGIN') {
                $name = strtoupper(trim($line->value));

                // Un VEVENT sin END seguido de otro BEGIN:VEVENT: el primero está roto, no contiene al segundo.
                while ($stack !== [] && in_array($stack[array_key_last($stack)]->name, self::BLOCKS, true)
                    && (in_array($name, self::BLOCKS, true) || $name === 'VCALENDAR')) {
                    $open = array_pop($stack);
                    array_unshift($open->problems, new ICalException("Línea {$open->line}: el {$open->name} no se cierra (falta END:{$open->name}).", $open->line));
                }

                $component = new Component($name, $no);

                if ($stack === []) {
                    $roots[] = $component;
                } else {
                    $stack[array_key_last($stack)]->children[] = $component;
                }

                $stack[] = $component;

                continue;
            }

            if ($line->name === 'END') {
                $name = strtoupper(trim($line->value));
                $index = null;

                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]->name === $name) {
                        $index = $i;
                        break;
                    }
                }

                if ($index === null) {
                    $this->warnings[] = "Línea {$no}: END:{$name} sin su BEGIN; se ignora.";

                    continue;
                }

                while (count($stack) - 1 > $index) {
                    $open = array_pop($stack);
                    array_unshift($open->problems, new ICalException("Línea {$open->line}: el {$open->name} no se cierra (falta END:{$open->name}).", $open->line));
                }

                array_pop($stack);

                continue;
            }

            if ($stack !== []) {
                $stack[array_key_last($stack)]->lines[] = $line;
            }
        }

        foreach ($stack as $open) {
            array_unshift($open->problems, new ICalException("Línea {$open->line}: el {$open->name} no se cierra (falta END:{$open->name}); el archivo parece truncado.", $open->line));
        }

        return array_values(array_filter($roots, fn (Component $c) => $c->name === 'VCALENDAR'));
    }

    private function recode(string $text): string
    {
        if (! $this->recoded) {
            $this->recoded = true;
            $this->warnings[] = 'El archivo no está en UTF-8; se interpretó como Windows-1252.';
        }

        return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    // ── Eventos ────────────────────────────────────────────────────────────

    /**
     * @param  array{strict: bool, uidDomain: ?string, untitled: string}  $o
     *
     * @throws ICalException
     */
    private function event(Component $v, TimezoneResolver $tz, array $o): CalendarEvent
    {
        if ($v->problems !== []) {
            throw $v->problems[0];
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
                $this->warnings[] = "Línea {$rules[1]->line}: el VEVENT tiene varios RRULE; solo se usa el primero.";
            }

            $rrule = $this->rule($rules[0], $start);
        }

        foreach (['RDATE', 'EXRULE'] as $name) {
            if (($line = $v->first($name)) !== null) {
                $this->warnings[] = "Línea {$line->line}: {$name} no se puede representar y se ignora; la serie puede mostrar ocurrencias de más o de menos.";
            }
        }

        $exdates = [];

        foreach ($v->all('EXDATE') as $line) {
            foreach (explode(',', $line->value) as $piece) {
                if (trim($piece) !== '') {
                    $exdates[] = $this->date(new ContentLine('EXDATE', $line->params, trim($piece), $line->line), $tz)->toEventValue();
                }
            }
        }

        $recurrence = null;

        if (($line = $v->first('RECURRENCE-ID')) !== null) {
            $recurrence = $this->date($line, $tz);

            if (($range = $line->param('RANGE')) !== null) {
                $this->warnings[] = "Línea {$line->line}: RECURRENCE-ID;RANGE={$range} está obsoleto y no se soporta; se trata como una sola ocurrencia.";
            }

            if ($rrule !== null) {
                $this->warnings[] = "Línea {$line->line}: una ocurrencia modificada no puede llevar RRULE; se ignora.";
                $rrule = null;
                $exdates = [];
            }
        }

        if ($exdates !== [] && $rrule === null) {
            $this->warnings[] = 'Línea '.$v->first('EXDATE')->line.': EXDATE sin RRULE no excluye nada; se ignora.';
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
            $this->warnings[] = "Línea {$v->line}: el VEVENT no tiene UID; se le dio el id «{$uid}».";
        }

        $seriesId = $this->id($uid, $o['uidDomain']);
        $id = $recurrence !== null ? $seriesId.'#'.$recurrence->raw : $seriesId;

        $resources = [];
        foreach ($v->all('X-KORE-RESOURCES') as $line) {
            array_push($resources, ...array_filter(Text::splitList($line->value), fn (string $r) => trim($r) !== ''));
        }

        // X-KORE-COLOR es el valor original (un token del tema); COLOR, el de otros clientes.
        $line = $v->first('X-KORE-COLOR') ?? $v->first('COLOR');
        $color = $line !== null ? trim(Text::unescape($line->value)) : '';
        $url = ($line = $v->first('URL')) !== null ? trim($line->value) : '';

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
     * @return array{strict: bool, uidDomain: ?string, untitled: string}
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
        ];
    }
}
