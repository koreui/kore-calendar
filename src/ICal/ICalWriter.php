<?php

namespace KoreUi\Calendar\ICal;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Recurrence\RecurrenceExpander;
use KoreUi\Calendar\Recurrence\RuleScanner;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;
use RRule\RRule;
use Stringable;
use Throwable;

/**
 * Eventos → archivo iCalendar (RFC 5545).
 *
 * Cada fecha viaja en la representación con la que llegó, que son las cuatro del modelo
 * de tiempo del paquete (docs/calendar.md §5) y las cuatro de iCalendar:
 *
 * | CalendarEvent                          | iCalendar                                   |
 * |----------------------------------------|---------------------------------------------|
 * | día completo (`allDay`, `'2026-10-16'`) | `DTSTART;VALUE=DATE:20261016` (fin exclusivo) |
 * | fecha con zona IANA                     | `DTSTART;TZID=Europe/Madrid:20261016T090000` + VTIMEZONE |
 * | instante (UTC, o un offset fijo)        | `DTSTART:20261016T070000Z`                  |
 * | string sin offset (hora de pared)       | `DTSTART:20261016T090000` (flotante)        |
 *
 * **Offsets fijos.** `'…T09:00:00+02:00'` es un instante, no una zona, así que un evento
 * suelto se escribe en UTC: es lo más compatible y no pierde nada. Una SERIE no puede:
 * su regla corre en la hora de pared de esa zona (`BYDAY=MO` a las 01:00+02:00 es un
 * domingo en UTC), así que se escribe con un TZID `UTC+0200` y su propio VTIMEZONE.
 *
 * **Hora repetida del otoño.** Un TZID no distingue las dos veces que existe la 02:30 del
 * último domingo de octubre en Madrid: RFC 5545 §3.3.5 dice que es la primera. Un evento suelto
 * que está en la segunda se escribe como instante UTC, o el TZID lo correría una hora.
 *
 * **Sin fin.** No se escribe DTEND ni DURATION: es lo que RFC 5545 §3.6.1 define para
 * un evento sin fin (un día, si es de día completo; duración cero, si tiene hora) y lo
 * único que se lee de vuelta como «sin fin». La duración por defecto del calendario
 * (`time.default_timed_duration`) es de la aplicación, no del archivo; con la opción
 * `defaultDuration` se escribe un `DURATION` explícito en los eventos con hora, para
 * los clientes que pintan un evento de duración cero como un punto invisible.
 *
 * Las opciones: `name` (X-WR-CALNAME), `uidDomain` (por defecto el host de `app.url`),
 * `dtstamp` (fecha fija para tests y exportaciones reproducibles) y `defaultDuration`.
 */
final class ICalWriter
{
    /** Los nombres de color CSS3 (CSS Color Module Level 4 §6.1): los únicos que admite COLOR. */
    private const CSS_COLOR_NAMES = [
        'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure', 'beige', 'bisque', 'black',
        'blanchedalmond', 'blue', 'blueviolet', 'brown', 'burlywood', 'cadetblue', 'chartreuse',
        'chocolate', 'coral', 'cornflowerblue', 'cornsilk', 'crimson', 'cyan', 'darkblue', 'darkcyan',
        'darkgoldenrod', 'darkgray', 'darkgreen', 'darkgrey', 'darkkhaki', 'darkmagenta', 'darkolivegreen',
        'darkorange', 'darkorchid', 'darkred', 'darksalmon', 'darkseagreen', 'darkslateblue', 'darkslategray',
        'darkslategrey', 'darkturquoise', 'darkviolet', 'deeppink', 'deepskyblue', 'dimgray', 'dimgrey',
        'dodgerblue', 'firebrick', 'floralwhite', 'forestgreen', 'fuchsia', 'gainsboro', 'ghostwhite',
        'gold', 'goldenrod', 'gray', 'green', 'greenyellow', 'grey', 'honeydew', 'hotpink', 'indianred',
        'indigo', 'ivory', 'khaki', 'lavender', 'lavenderblush', 'lawngreen', 'lemonchiffon', 'lightblue',
        'lightcoral', 'lightcyan', 'lightgoldenrodyellow', 'lightgray', 'lightgreen', 'lightgrey',
        'lightpink', 'lightsalmon', 'lightseagreen', 'lightskyblue', 'lightslategray', 'lightslategrey',
        'lightsteelblue', 'lightyellow', 'lime', 'limegreen', 'linen', 'magenta', 'maroon', 'mediumaquamarine',
        'mediumblue', 'mediumorchid', 'mediumpurple', 'mediumseagreen', 'mediumslateblue', 'mediumspringgreen',
        'mediumturquoise', 'mediumvioletred', 'midnightblue', 'mintcream', 'mistyrose', 'moccasin',
        'navajowhite', 'navy', 'oldlace', 'olive', 'olivedrab', 'orange', 'orangered', 'orchid',
        'palegoldenrod', 'palegreen', 'paleturquoise', 'palevioletred', 'papayawhip', 'peachpuff',
        'peru', 'pink', 'plum', 'powderblue', 'purple', 'rebeccapurple', 'red', 'rosybrown', 'royalblue',
        'saddlebrown', 'salmon', 'sandybrown', 'seagreen', 'seashell', 'sienna', 'silver', 'skyblue',
        'slateblue', 'slategray', 'slategrey', 'snow', 'springgreen', 'steelblue', 'tan', 'teal',
        'thistle', 'tomato', 'turquoise', 'violet', 'wheat', 'white', 'whitesmoke', 'yellow', 'yellowgreen',
    ];

    public const PRODID = '-//kore-ui//calendar//ES';

    private const OPTIONS = ['name', 'uidDomain', 'dtstamp', 'defaultDuration'];

    /** El mismo criterio que `EventNormalizer`: un string de fecha es ISO 8601 y nada más. */
    private const ISO = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/iD';

    private const OFFSET = '/(Z|[+-]\d{2}:?\d{2})$/iD';

    private const DATE_ONLY = '/^\d{4}-\d{2}-\d{2}$/D';

    /** Años de VTIMEZONE que se añaden a una serie sin final: lo que un cliente que use ese VTIMEZONE verá bien. */
    private const OPEN_ENDED_YEARS = 10;

    /** Tope para `UNTIL=99991231` y similares: no se escriben miles de años de cambios de hora. */
    private const MAX_TIMEZONE_YEARS = 30;

    /** Nombres de zona que son UTC con otro nombre. */
    private const UTC_NAMES = ['utc', 'z', 'gmt', 'etc/utc', 'etc/gmt', 'etc/zulu', 'zulu', 'etc/universal', 'universal', 'etc/greenwich', 'greenwich'];

    /**
     * @param  iterable<CalendarEvent|array<string, mixed>>  $events  Los arrays pasan por `CalendarEvent::fromArray()`.
     * @param  array{name?: ?string, uidDomain?: ?string, dtstamp?: DateTimeInterface|string|null, defaultDuration?: ?int}  $options
     */
    public function write(iterable $events, array $options = []): string
    {
        $o = $this->options($options);
        $list = $this->materialize($events);
        $zones = new ZoneUsage;

        // Las series, primero: sus excepciones y exclusiones se escriben en SU representación
        // y una excepción puede venir en la lista antes que su serie.
        $series = [];
        foreach ($list as $event) {
            if ($event->isRecurring()) {
                $series[(string) $event->id] = $this->series($event, $zones);
            }
        }

        $body = [];
        foreach ($list as $event) {
            array_push($body, ...$this->vevent($event, $series, $o, $zones));
        }

        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:'.self::PRODID, 'CALSCALE:GREGORIAN'];

        if ($o['name'] !== null) {
            $lines[] = 'X-WR-CALNAME:'.Text::escape($o['name']);
        }

        array_push($lines, ...$zones->lines(), ...$body);
        $lines[] = 'END:VCALENDAR';

        return implode('', array_map(Text::fold(...), $lines));
    }

    /**
     * @param  array<string, array{start: DateValue, rule: string}>  $series
     * @param  array{name: ?string, uidDomain: string, dtstamp: string, defaultDuration: ?int}  $o
     * @return list<string>
     */
    private function vevent(CalendarEvent $e, array $series, array $o, ZoneUsage $zones): array
    {
        $override = $e->isOverride();
        $context = $e->isRecurring() ? $series[(string) $e->id] : null;

        // Una excepción lleva el UID de su serie: RECURRENCE-ID solo significa algo junto a él.
        $lines = ['BEGIN:VEVENT', 'UID:'.Text::escape(($override ? $e->recurrenceOf : $e->id).'@'.$o['uidDomain']), 'DTSTAMP:'.$o['dtstamp']];

        $allDay = self::isAllDay($e);
        $start = $context['start'] ?? $this->value($e->id, 'start', $e->start, $allDay, false, $zones);
        $lines[] = 'DTSTART'.$start->params().':'.$start->format();

        $end = $e->end === null ? null : $this->value($e->id, 'end', $e->end, $allDay, $e->isRecurring(), $zones);

        // Un fin que no es posterior al inicio no es un fin: el normalizador lo trata como si
        // faltara, y iCalendar no admite DTEND ≤ DTSTART.
        if ($end !== null && ! $end->isAfter($start)) {
            $end = null;
        }

        if ($end !== null) {
            $lines[] = 'DTEND'.$end->params().':'.$end->format();
        } elseif ($o['defaultDuration'] !== null && $start->kind !== DateValue::DATE) {
            $lines[] = 'DURATION:'.self::duration($o['defaultDuration']);
        }

        if ($context !== null) {
            $lines[] = 'RRULE:'.$context['rule'];
            array_push($lines, ...$this->exdates($e, $context, $zones));
        }

        if ($override) {
            $lines[] = $this->recurrenceId($e, $series, $zones);
        }

        $lines[] = 'SUMMARY:'.Text::escape($e->title);

        $meta = $e->meta;

        if (($text = self::text($meta['description'] ?? null)) !== null) {
            $lines[] = 'DESCRIPTION:'.Text::escape($text);
        }

        if (($text = self::text($meta['location'] ?? null)) !== null) {
            $lines[] = 'LOCATION:'.Text::escape($text);
        }

        if ($e->url !== null && Text::clean($e->url) !== '') {
            $lines[] = 'URL:'.Text::clean($e->url);
        }

        if (is_string($meta['status'] ?? null) && in_array($status = strtoupper(trim($meta['status'])), ['TENTATIVE', 'CONFIRMED', 'CANCELLED'], true)) {
            $lines[] = 'STATUS:'.$status;
        }

        if (is_array($meta['categories'] ?? null) && ($categories = array_filter(array_map(self::text(...), array_values($meta['categories'])))) !== []) {
            $lines[] = 'CATEGORIES:'.implode(',', array_map(Text::escape(...), $categories));
        }

        // RFC 7986 define COLOR como un nombre de color CSS3 y nada más. El color de un evento
        // suele ser un token del tema (`chart-4`) o un oklch(), que un cliente estricto rechaza:
        // el valor original viaja en X-KORE-COLOR (la vuelta lo recupera) y COLOR solo se
        // escribe cuando es un nombre que Google o Apple entienden.
        if ($e->color !== null && $e->color !== '') {
            $lines[] = 'X-KORE-COLOR:'.Text::escape($e->color);

            if (in_array(strtolower($e->color), self::CSS_COLOR_NAMES, true)) {
                $lines[] = 'COLOR:'.strtolower($e->color);
            }
        }

        if ($e->resourceIds !== []) {
            $lines[] = 'X-KORE-RESOURCES:'.implode(',', array_map(fn ($id) => Text::escape((string) $id), $e->resourceIds));
        }

        if (is_int($meta['sequence'] ?? null) && $meta['sequence'] >= 0) {
            $lines[] = 'SEQUENCE:'.$meta['sequence'];
        }

        if (is_array($meta['x'] ?? null)) {
            foreach ($meta['x'] as $name => $value) {
                $name = strtoupper((string) $name);

                if (preg_match('/^X-[A-Z0-9-]+$/D', $name) && ! in_array($name, ['X-KORE-RESOURCES', 'X-KORE-COLOR'], true) && ($text = self::text($value)) !== null) {
                    $lines[] = $name.':'.Text::escape($text);
                }
            }
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    // ── Fechas ─────────────────────────────────────────────────────────────

    /**
     * @return array{start: DateValue, rule: string}
     */
    private function series(CalendarEvent $e, ZoneUsage $zones): array
    {
        $start = $this->value($e->id, 'start', $e->start, self::isAllDay($e), true, $zones);
        $rule = Text::clean(trim((string) $e->rrule));

        // RFC 5545 §3.3.10 liga el UNTIL a la representación del inicio. Se reescribe solo
        // lo que no la cumple (`UNTIL=20261231`, que la spec del paquete admite): un UNTIL
        // en UTC en una serie con zona se deja como está.
        $rule = $start->hasInstant()
            ? RecurrenceExpander::normalizeUntil($rule, $start->dt->getTimezone())
            : self::localUntil($rule, $start);

        if ($start->kind === DateValue::ZONED) {
            $zones->note((string) $start->tzid, $start->dt->getTimezone(), self::lastYear($rule, $start));
        }

        return ['start' => $start, 'rule' => $rule];
    }

    /**
     * Un día completo o una hora de pared llevan el UNTIL en su misma representación: una
     * fecha, o una hora sin `Z`.
     */
    private static function localUntil(string $rule, DateValue $start): string
    {
        return preg_replace_callback('/UNTIL=(\d{8})(?:T(\d{6}))?Z?/i', function (array $m) use ($start) {
            if ($start->kind === DateValue::DATE) {
                return 'UNTIL='.$m[1];
            }

            return 'UNTIL='.$m[1].'T'.(($m[2] ?? '') !== '' ? $m[2] : '235959');
        }, $rule);
    }

    /**
     * El último año en el que la serie necesita VTIMEZONE: el de su UNTIL, el de su última
     * ocurrencia si tiene COUNT, o unos años más si no acaba nunca.
     */
    private static function lastYear(string $rule, DateValue $start): int
    {
        $first = (int) $start->dt->format('Y');
        $cap = $first + self::MAX_TIMEZONE_YEARS;

        if (preg_match('/UNTIL=(\d{4})/i', $rule, $m)) {
            return min($cap, max($first, (int) $m[1]));
        }

        if (preg_match('/COUNT=(\d+)/i', $rule, $m) && (int) $m[1] <= 1000) {
            try {
                $all = (new RRule($rule, $start->dt))->getOccurrences();

                if ($all !== []) {
                    return min($cap, (int) $all[array_key_last($all)]->format('Y'));
                }
            } catch (Throwable) {
                // Una regla que php-rrule no entiende se trata como si no acabara.
            }
        }

        return $first + self::OPEN_ENDED_YEARS;
    }

    /**
     * Un valor de fecha del evento → su representación iCalendar.
     */
    private function value(string|int $id, string $field, DateTimeInterface|string $value, bool $allDay, bool $series, ZoneUsage $zones): DateValue
    {
        if ($allDay) {
            return $this->day($id, $field, $value);
        }

        if ($value instanceof DateTimeInterface) {
            return $this->instant(DateTimeImmutable::createFromInterface($value), $series, $zones);
        }

        $fields = $this->fields($id, $field, $value);

        if (preg_match(self::OFFSET, trim($value))) {
            return $this->instant(new DateTimeImmutable(trim($value)), $series, $zones);
        }

        return DateValue::floating(...$fields);
    }

    /** La fecha de calendario tal como está escrita, sin pasar por ninguna zona. */
    private function day(string|int $id, string $field, DateTimeInterface|string $value): DateValue
    {
        if ($value instanceof DateTimeInterface) {
            return DateValue::date((int) $value->format('Y'), (int) $value->format('n'), (int) $value->format('j'));
        }

        [$y, $m, $d] = $this->fields($id, $field, $value);

        return DateValue::date($y, $m, $d);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
     */
    private function fields(string|int $id, string $field, string $value): array
    {
        $ok = preg_match(self::ISO, trim($value), $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) ($m[4] ?? 0) < 24
            && (int) ($m[5] ?? 0) < 60
            && (int) ($m[6] ?? 0) < 60;

        if (! $ok) {
            throw new InvalidArgumentException(
                "Evento «{$id}»: `{$field}` «{$value}» no es una fecha válida. Se espera ISO 8601: YYYY-MM-DD, YYYY-MM-DD HH:MM[:SS] o con offset/Z."
            );
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
    }

    private function instant(DateTimeImmutable $dt, bool $series, ZoneUsage $zones): DateValue
    {
        $zone = $dt->getTimezone();
        $name = $zone->getName();

        // `getLocation()` solo existe en las zonas con nombre IANA; un offset (`+02:00`) o una
        // abreviatura (`EST`) no la tienen.
        $named = $zone->getLocation() !== false;

        if ($named ? in_array(strtolower($name), self::UTC_NAMES, true) : $dt->getOffset() === 0) {
            return DateValue::utc($dt);
        }

        if (! $named) {
            if (! $series) {
                return DateValue::utc($dt);
            }

            // Una serie conserva su hora de pared: ver la nota de la clase.
            $offset = $dt->getOffset();
            $name = 'UTC'.TimezoneWriter::offset($offset);
            $zone = new DateTimeZone(sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60)));
            $dt = $dt->setTimezone($zone);
        }

        // Una hora de pared con zona no distingue las dos veces que se repite una hora en otoño:
        // RFC 5545 §3.3.5 dice que es la primera. Si este instante es la segunda, el TZID lo
        // movería una hora; un evento suelto se escribe entonces como instante UTC.
        if (! $series && $named && ! self::reproducible($dt)) {
            return DateValue::utc($dt);
        }

        $zones->note($name, $zone, (int) $dt->format('Y'));

        return DateValue::zoned($dt, $name);
    }

    /** ¿Leyendo esta hora de pared en su zona sale el mismo instante? */
    private static function reproducible(DateTimeImmutable $dt): bool
    {
        return (new WallClock($dt->getTimezone()))->toInstant(Marker::fromWallFields($dt))->getTimestamp() === $dt->getTimestamp();
    }

    // ── Series: exclusiones y excepciones ──────────────────────────────────

    /**
     * Una línea EXDATE por cada representación distinta, con sus fechas separadas por coma.
     *
     * @param  array{start: DateValue, rule: string}  $context
     * @return list<string>
     */
    private function exdates(CalendarEvent $e, array $context, ZoneUsage $zones): array
    {
        $groups = [];

        foreach ($e->exdates as $exdate) {
            foreach ($this->inSeries($e->id, $exdate, $context, $zones, false) as $value) {
                $groups[$value->params()][$value->format()] = true;
            }
        }

        $lines = [];
        foreach ($groups as $params => $values) {
            $lines[] = 'EXDATE'.$params.':'.implode(',', array_keys($values));
        }

        return $lines;
    }

    /**
     * @param  array<string, array{start: DateValue, rule: string}>  $series
     */
    private function recurrenceId(CalendarEvent $e, array $series, ZoneUsage $zones): string
    {
        $context = $series[(string) $e->recurrenceOf] ?? null;

        // Con su serie en la lista, el RECURRENCE-ID va en la representación de su DTSTART, que es
        // lo que exige RFC 5545 §3.8.4.4. Sin ella (la serie vive en otra fuente) no hay a qué
        // ajustarse y viaja como llegó.
        $values = $context !== null
            ? $this->inSeries($e->id, $e->recurrenceId, $context, $zones, true)
            : [$this->value($e->id, 'recurrenceId', $e->recurrenceId, self::isDateOnly($e->recurrenceId), false, $zones)];

        $value = $values[0];

        return 'RECURRENCE-ID'.$value->params().':'.$value->format();
    }

    /**
     * Una fecha de EXDATE o RECURRENCE-ID en la representación de su serie. Es la misma
     * regla con la que el expansor la compara (`RecurrenceExpander::key()`):
     *
     * - serie de día completo: el día;
     * - serie con zona: el instante, en la zona de la serie (un string sin offset es de esa zona);
     * - serie flotante: los campos de pared tal como están escritos.
     *
     * Una fecha sin hora sobre una serie con hora («la ocurrencia de ese día, sea cual sea su
     * hora») no existe en iCalendar, así que se busca la hora real de esa ocurrencia. Si ese día
     * no hay ninguna, un EXDATE se omite (no hay nada que excluir) y un RECURRENCE-ID se queda
     * con el día.
     *
     * @param  array{start: DateValue, rule: string}  $context
     * @return list<DateValue>
     */
    private function inSeries(string|int $id, DateTimeInterface|string $value, array $context, ZoneUsage $zones, bool $keepDay): array
    {
        $start = $context['start'];

        if ($start->kind === DateValue::DATE) {
            return [$this->day($id, 'exdates', $value)];
        }

        if (self::isDateOnly($value)) {
            $day = $this->day($id, 'exdates', $value);
            $found = $this->occurrencesOn(trim($value), $context);

            return match (true) {
                $found === null => [$day],
                $found === [] => $keepDay ? [$day] : [],
                default => $found,
            };
        }

        if ($start->kind === DateValue::FLOATING) {
            // Una hora de pared no tiene zona a la que convertir: los campos son los que son.
            $wall = $value instanceof DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : trim($value);

            return [DateValue::floating(...$this->fields($id, 'exdates', $wall))];
        }

        $zone = $start->dt->getTimezone();

        if ($value instanceof DateTimeInterface) {
            $dt = DateTimeImmutable::createFromInterface($value);
        } else {
            $this->fields($id, 'exdates', $value);
            $dt = preg_match(self::OFFSET, trim($value)) ? new DateTimeImmutable(trim($value)) : new DateTimeImmutable(trim($value), $zone);
        }

        $dt = $dt->setTimezone($zone);

        if ($start->kind === DateValue::UTC) {
            return [DateValue::utc($dt)];
        }

        $zones->note((string) $start->tzid, $zone, (int) $dt->format('Y'));

        return [DateValue::zoned($dt, (string) $start->tzid)];
    }

    /**
     * Las ocurrencias de una serie con hora en un día concreto, de pared (o de la zona de la
     * serie). `null` si la regla no se puede evaluar.
     *
     * @param  array{start: DateValue, rule: string}  $context
     * @return ?list<DateValue>
     */
    private function occurrencesOn(string $day, array $context): ?array
    {
        $start = $context['start'];
        $zone = $start->dt->getTimezone();

        try {
            // Con el tope de iteraciones y el inicio adelantado de `RuleScanner`: un EXDATE de un día
            // sobre `FREQ=MINUTELY` desde 2020 no recorre tres millones de ocurrencias. Y como mucho
            // `max_occurrences` de ese día: lo que sigue a las primeras mil no cabe en un EXDATE razonable.
            $scan = RuleScanner::between(
                $context['rule'],
                $start->dt,
                new DateTimeImmutable($day.' 00:00:00', $zone),
                new DateTimeImmutable($day.' 23:59:59', $zone),
                RuleScanner::limit('max_occurrences', 1000),
                RuleScanner::limit('max_iterations', RuleScanner::MAX_ITERATIONS),
            );
        } catch (Throwable) {
            return null;
        }

        // Cortó antes de llegar a ese día: no se sabe qué hay en él.
        if ($scan->truncated && $scan->occurrences === []) {
            return null;
        }

        $found = $scan->occurrences;

        return array_map(
            fn (DateTimeInterface $d) => match ($start->kind) {
                DateValue::FLOATING => DateValue::floating((int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j'), (int) $d->format('G'), (int) $d->format('i'), (int) $d->format('s')),
                DateValue::UTC => DateValue::utc(DateTimeImmutable::createFromInterface($d)),
                default => DateValue::zoned(DateTimeImmutable::createFromInterface($d)->setTimezone($zone), (string) $start->tzid),
            },
            $found,
        );
    }

    // ── Utilidades ─────────────────────────────────────────────────────────

    /** Lo mismo que decide `EventNormalizer`: `allDay` explícito, o inicio y fin sin hora. */
    private static function isAllDay(CalendarEvent $e): bool
    {
        return $e->allDay ?? (self::isDateOnly($e->start) && ($e->end === null || self::isDateOnly($e->end)));
    }

    private static function isDateOnly(DateTimeInterface|string|null $value): bool
    {
        return is_string($value) && preg_match(self::DATE_ONLY, trim($value)) === 1;
    }

    private static function text(mixed $value): ?string
    {
        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function duration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return 'PT'.($h ? $h.'H' : '').($m ? $m.'M' : '').($s ? $s.'S' : '');
    }

    /**
     * @param  iterable<mixed>  $events
     * @return list<CalendarEvent>
     */
    private function materialize(iterable $events): array
    {
        $list = [];

        foreach ($events as $event) {
            if (is_array($event)) {
                $event = CalendarEvent::fromArray($event);
            }

            if (! $event instanceof CalendarEvent) {
                throw new InvalidArgumentException(sprintf(
                    'ICalWriter::write() espera CalendarEvent (o arrays para CalendarEvent::fromArray()) y recibió %s.',
                    get_debug_type($event),
                ));
            }

            $list[] = $event;
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{name: ?string, uidDomain: string, dtstamp: string, defaultDuration: ?int}
     */
    private function options(array $options): array
    {
        $unknown = array_values(array_diff(array_keys($options), self::OPTIONS));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'ICalWriter: opción desconocida «%s». Permitidas: %s.',
                implode('», «', $unknown),
                implode(', ', self::OPTIONS),
            ));
        }

        $duration = $options['defaultDuration'] ?? null;

        if ($duration !== null && (! is_int($duration) || $duration < 1)) {
            throw new InvalidArgumentException('ICalWriter: `defaultDuration` son segundos (un entero de al menos 1).');
        }

        $domain = Text::clean(trim((string) ($options['uidDomain'] ?? '')));
        $stamp = $options['dtstamp'] ?? null;

        return [
            'name' => isset($options['name']) && $options['name'] !== '' ? (string) $options['name'] : null,
            'uidDomain' => $domain !== '' ? $domain : self::defaultDomain(),
            'dtstamp' => ($stamp === null ? CarbonImmutable::now('UTC') : (new DateTimeImmutable(is_string($stamp) ? $stamp : '@'.$stamp->getTimestamp())))
                ->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            'defaultDuration' => $duration,
        ];
    }

    /**
     * El host de `app.url`: dos aplicaciones que exportan un evento con id 1 al mismo calendario
     * de Google no deben pisarse. Fuera de Laravel, un nombre fijo.
     */
    private static function defaultDomain(): string
    {
        try {
            $host = function_exists('config') ? parse_url((string) config('app.url'), PHP_URL_HOST) : null;
        } catch (Throwable) {
            $host = null;
        }

        return is_string($host) && $host !== '' ? $host : 'kore-calendar';
    }
}
