<?php

namespace KoreUi\Calendar\ICal;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

/**
 * Una fecha de iCalendar con la representación en la que viaja. Son las mismas
 * cuatro del modelo de tiempo del paquete (docs/calendar.md §5):
 *
 * - `date`: un día de calendario (`VALUE=DATE`), sin hora ni zona. `dt` es la
 *   medianoche UTC de ese día, solo como contenedor de los campos.
 * - `floating`: hora de pared sin zona (`20261016T090000`). `dt` es UTC con esos mismos
 *   campos, por la misma razón.
 * - `utc`: un instante (`20261016T070000Z`).
 * - `zoned`: hora de pared más zona (`TZID=Europe/Madrid:20261016T090000`). `dt` está
 *   en esa zona.
 *
 * Es lo que decide cómo se escribe una fecha y cómo la interpreta el expansor de
 * recurrencia, así que cualquier conversión entre ellas pasa por aquí o se pierde
 * información en silencio.
 *
 * @internal
 */
final class DateValue
{
    public const DATE = 'date';

    public const FLOATING = 'floating';

    public const UTC = 'utc';

    public const ZONED = 'zoned';

    private function __construct(
        public readonly string $kind,
        public readonly DateTimeImmutable $dt,
        /** El TZID con el que se escribe; solo en `zoned`. */
        public readonly ?string $tzid = null,
        /** El valor tal como estaba en el archivo, para derivar ids estables. */
        public readonly string $raw = '',
    ) {}

    public static function date(int $y, int $m, int $d, string $raw = ''): self
    {
        return new self(self::DATE, self::build($y, $m, $d, 0, 0, 0, new DateTimeZone('UTC')), null, $raw);
    }

    public static function floating(int $y, int $m, int $d, int $h, int $i, int $s, string $raw = ''): self
    {
        return new self(self::FLOATING, self::build($y, $m, $d, $h, $i, $s, new DateTimeZone('UTC')), null, $raw);
    }

    public static function utc(DateTimeImmutable $instant, string $raw = ''): self
    {
        return new self(self::UTC, $instant->setTimezone(new DateTimeZone('UTC')), null, $raw);
    }

    public static function zoned(DateTimeImmutable $local, string $tzid, string $raw = ''): self
    {
        return new self(self::ZONED, $local, $tzid, $raw);
    }

    public static function fromFields(string $kind, int $y, int $m, int $d, int $h, int $i, int $s, ?DateTimeZone $zone, ?string $tzid, string $raw): self
    {
        return match ($kind) {
            self::DATE => self::date($y, $m, $d, $raw),
            self::FLOATING => self::floating($y, $m, $d, $h, $i, $s, $raw),
            self::UTC => new self(self::UTC, self::build($y, $m, $d, $h, $i, $s, new DateTimeZone('UTC')), null, $raw),
            default => new self(self::ZONED, self::resolve($y, $m, $d, $h, $i, $s, $zone), $tzid, $raw),
        };
    }

    /**
     * Una hora de pared en una zona → su instante, con la regla del paquete y de RFC 5545 §3.3.5:
     * si la hora se repite (otoño) es la primera, y si no existe (primavera) se resuelve hacia
     * delante. No se delega en PHP, que para la hora repetida da la primera en una zona y la
     * segunda en otra (ver `WallClock`).
     */
    private static function resolve(int $y, int $m, int $d, int $h, int $i, int $s, DateTimeZone $zone): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface((new WallClock($zone))->toInstant(Marker::of($y, $m, $d, $h, $i, $s)));
    }

    /** ¿Es un instante real (UTC o con zona) y no una hora de pared? */
    public function hasInstant(): bool
    {
        return $this->kind === self::UTC || $this->kind === self::ZONED;
    }

    /** Lo que se le da a `CalendarEvent`: 'Y-m-d', 'Y-m-d H:i:s' sin zona, o el instante con su zona. */
    public function toEventValue(): DateTimeImmutable|string
    {
        return match ($this->kind) {
            self::DATE => $this->dt->format('Y-m-d'),
            self::FLOATING => $this->dt->format('Y-m-d H:i:s'),
            default => $this->dt,
        };
    }

    /** El mismo día, sin hora. */
    public function toDate(): self
    {
        return new self(self::DATE, new DateTimeImmutable($this->dt->format('Y-m-d').' 00:00:00', new DateTimeZone('UTC')), null, $this->raw);
    }

    public function isMidnight(): bool
    {
        return $this->dt->format('His') === '000000';
    }

    /**
     * Sumar una duración respetando lo que significa en cada representación: en una
     * hora de pared los días miden 24 h; con zona, `P1D` es un día de calendario y
     * `PT24H` son 24 h reales, que PHP distingue al sumar.
     */
    public function plus(DateInterval $interval): self
    {
        $dt = $this->dt->add($interval);

        return match ($this->kind) {
            self::DATE => self::date((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'), $this->raw),
            default => new self($this->kind, $dt, $this->tzid, $this->raw),
        };
    }

    /** Segundos de pared (como un `Marker`): para comparar fechas de representaciones distintas. */
    public function wallTs(): int
    {
        return Marker::fromWallFields($this->dt)->ts;
    }

    /** ¿Termina `$this` estrictamente después que `$other`? Con dos instantes se comparan instantes. */
    public function isAfter(self $other): bool
    {
        if ($this->hasInstant() && $other->hasInstant()) {
            return $this->dt->getTimestamp() > $other->dt->getTimestamp();
        }

        return $this->wallTs() > $other->wallTs();
    }

    /** El valor como se escribe en iCalendar, sin parámetros. */
    public function format(): string
    {
        return match ($this->kind) {
            self::DATE => $this->dt->format('Ymd'),
            self::UTC => $this->dt->format('Ymd\THis\Z'),
            default => $this->dt->format('Ymd\THis'),
        };
    }

    /** Los parámetros que acompañan al valor: `;VALUE=DATE` o `;TZID=…`. */
    public function params(): string
    {
        return match ($this->kind) {
            self::DATE => ';VALUE=DATE',
            self::ZONED => ';TZID='.Text::param((string) $this->tzid),
            default => '',
        };
    }

    private static function build(int $y, int $m, int $d, int $h, int $i, int $s, DateTimeZone $zone): DateTimeImmutable
    {
        return (new DateTimeImmutable('2000-01-01 00:00:00', $zone))->setDate($y, $m, $d)->setTime($h, $i, $s);
    }
}
