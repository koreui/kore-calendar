<?php

namespace KoreUi\Calendar\Time;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Stringable;

/**
 * Una hora de pared, sin zona: "el 16 de octubre a las 10:00" en el calendario.
 *
 * Es el "marker" de FullCalendar (headless-calendar/src/marker.ts) llevado a PHP:
 * los segundos que habrían pasado desde 1970-01-01 00:00 si el mundo fuera UTC.
 * Toda la aritmética de la rejilla (sumar días, inicio de día, de semana, de
 * mes, cortar un evento por días) se hace aquí, donde un día mide SIEMPRE
 * 86 400 segundos. Los cambios de horario no existen en este espacio, así que no
 * pueden descolocar una fila ni dejar un día sin medianoche (America/Santiago).
 *
 * Solo `WallClock` convierte entre esto e instantes reales, y solo en los bordes.
 *
 * Inmutable. Comparar con `->ts`; `equals()` existe para leerse mejor.
 */
final class Marker implements Stringable
{
    public const DAY = 86_400;

    private static ?DateTimeZone $utc = null;

    private function __construct(public readonly int $ts) {}

    public static function fromTimestamp(int $ts): self
    {
        return new self($ts);
    }

    public static function of(int $year, int $month, int $day, int $hour = 0, int $minute = 0, int $second = 0): self
    {
        // `gmmktime` lee los años 0-100 como de dos cifras (1 → 2001, 70 → 1970): `0001-01-01`, el
        // primer día que el paquete admite (`Bounds::MIN`), saldría como 2001. Para esos años se
        // construye con `DateTimeImmutable`, que los toma tal cual. Normaliza igual que `gmmktime`.
        if ($year >= 0 && $year <= 100) {
            return new self((new \DateTimeImmutable('@0'))->setDate($year, $month, $day)->setTime($hour, $minute, $second)->getTimestamp());
        }

        return new self(gmmktime($hour, $minute, $second, $month, $day, $year));
    }

    /**
     * 'YYYY-MM-DD', 'YYYY-MM-DD HH:MM[:SS]' o con 'T'. Sin zona ni offset: una
     * hora de pared no tiene. Para algo con offset, `WallClock::toMarker()`.
     */
    public static function parse(string $value): self
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/', trim($value), $m)) {
            throw new InvalidArgumentException("Hora de pared no válida: «{$value}». Se espera YYYY-MM-DD[ HH:MM[:SS]] sin zona.");
        }

        return self::of((int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));
    }

    /** Los campos de pared de cualquier fecha, ignorando su zona. */
    public static function fromWallFields(\DateTimeInterface $date): self
    {
        return self::parse($date->format('Y-m-d H:i:s'));
    }

    // ── Aritmética (siempre en días de 24 h) ─────────────────────────────

    public function addSeconds(int $seconds): self
    {
        return new self($this->ts + $seconds);
    }

    public function addMinutes(int $minutes): self
    {
        return new self($this->ts + $minutes * 60);
    }

    public function addDays(int $days): self
    {
        return new self($this->ts + $days * self::DAY);
    }

    /**
     * Meses de calendario, recortando al último día si el destino es más corto:
     * 31-ene + 1 mes = 28/29-feb, nunca 3-mar (el fallo de navegación que
     * FullCalendar arregló en 1.3.1 y otra vez en 4.0.2, #4595).
     */
    public function addMonths(int $months): self
    {
        [$y, $m, $d] = [$this->year(), $this->month(), $this->day()];
        $index = $y * 12 + ($m - 1) + $months;
        $ny = intdiv($index, 12);
        $nm = $index % 12 + 1;
        $nd = min($d, (int) gmdate('t', gmmktime(0, 0, 0, $nm, 1, $ny)));

        return self::of($ny, $nm, $nd, $this->hour(), $this->minute(), $this->second());
    }

    public function addYears(int $years): self
    {
        return $this->addMonths($years * 12);
    }

    public function startOfDay(): self
    {
        return new self($this->ts - $this->mod());
    }

    /** `$firstDay`: 0 = domingo … 6 = sábado. */
    public function startOfWeek(int $firstDay): self
    {
        $back = ($this->dayOfWeek() - $firstDay + 7) % 7;

        return $this->startOfDay()->addDays(-$back);
    }

    public function startOfMonth(): self
    {
        return self::of($this->year(), $this->month(), 1);
    }

    public function startOfYear(): self
    {
        return self::of($this->year(), 1, 1);
    }

    /** Días completos entre los inicios de día de ambos (puede ser negativo). */
    public function diffInDays(self $other): int
    {
        return intdiv($other->startOfDay()->ts - $this->startOfDay()->ts, self::DAY);
    }

    // ── Lectura ──────────────────────────────────────────────────────────

    public function year(): int
    {
        return (int) gmdate('Y', $this->ts);
    }

    public function month(): int
    {
        return (int) gmdate('n', $this->ts);
    }

    public function day(): int
    {
        return (int) gmdate('j', $this->ts);
    }

    public function hour(): int
    {
        return intdiv($this->mod(), 3600);
    }

    public function minute(): int
    {
        return intdiv($this->mod() % 3600, 60);
    }

    public function second(): int
    {
        return $this->mod() % 60;
    }

    /** 0 = domingo … 6 = sábado. */
    public function dayOfWeek(): int
    {
        return (int) gmdate('w', $this->ts);
    }

    /** Segundos desde el inicio del día. */
    public function secondsOfDay(): int
    {
        return $this->mod();
    }

    public function isStartOfDay(): bool
    {
        return $this->mod() === 0;
    }

    public function equals(self $other): bool
    {
        return $this->ts === $other->ts;
    }

    public function isBefore(self $other): bool
    {
        return $this->ts < $other->ts;
    }

    public function isAfter(self $other): bool
    {
        return $this->ts > $other->ts;
    }

    public static function min(self $a, self $b): self
    {
        return $a->ts <= $b->ts ? $a : $b;
    }

    public static function max(self $a, self $b): self
    {
        return $a->ts >= $b->ts ? $a : $b;
    }

    public function toDateString(): string
    {
        return gmdate('Y-m-d', $this->ts);
    }

    public function toDateTimeString(): string
    {
        return gmdate('Y-m-d H:i:s', $this->ts);
    }

    /**
     * Para formatear con Carbon (`isoFormat`, locale): una fecha UTC con
     * los mismos campos de pared. No es un instante: no se compara con otros.
     */
    public function toCarbon(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($this->ts);
    }

    public function toDateTime(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$this->ts))->setTimezone(self::$utc ??= new DateTimeZone('UTC'));
    }

    public function __toString(): string
    {
        return $this->toDateTimeString();
    }

    /** Segundos dentro del día, también para fechas anteriores a 1970. */
    private function mod(): int
    {
        return (($this->ts % self::DAY) + self::DAY) % self::DAY;
    }
}
